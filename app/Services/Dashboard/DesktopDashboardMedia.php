<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{Cache,Crypt,DB};

/** Explicit scoped images and private expense attachments. Never enumerate shared storage. */
class DesktopDashboardMedia
{
    public const MIMES=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','ico'=>'image/x-icon'];
    public const PRIVATE_MIMES=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','pdf'=>'application/pdf'];
    public const MAX_FILE=16*1024*1024;
    public const MAX_TOTAL=2*1024*1024*1024;
    public const MAX_FILES=20000;
    private const PROOF_CACHE_SECONDS=30;
    private const MODELS=['User'=>'users','Admin'=>'users','Resturant'=>'resturants','ResturantProduct'=>'resturant_products',
        'Product'=>'products','Category'=>'categories','Advertising'=>'advertisings','Banner'=>'banners','Slidear'=>'slidears',
        'Feature'=>'features','PendingVendor'=>'pending_vendors','Area'=>'areas','Review'=>'reviews'];
    private const SETTINGS=['logo','favicon','advertise_image'];
    private const USER_PARENTS=['orders'=>['user_id'],'go_store_orders'=>['customer_id'],'products'=>['added_by'],
        'categories'=>['added_by'],'resturants'=>['added_by']];
    public function __construct(private DesktopDashboardDevices $devices,private DesktopDashboardData $data) {}

    public static function safePath($path,string $area='public'): bool
    {
        if(!is_string($path)||$path===''||strlen($path)>500||str_starts_with($path,'/')
            ||preg_match('/[\\\\\x00-\x1f\x7f:*?"<>|%]/u',$path)||str_ends_with($path,'/'))return false;
        if(!in_array($area,['public','private'],true)||($area==='private'&&!str_starts_with($path,'branch-expenses/')))return false;
        foreach(explode('/',$path) as $part)if(strlen($part)>255||$part===''||$part==='.'||$part==='..'||str_ends_with($part,'.')||str_ends_with($part,' ')
            ||preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$part))return false;
        return isset(($area==='private'?self::PRIVATE_MIMES:self::MIMES)[strtolower(pathinfo($path,PATHINFO_EXTENSION))]);
    }
    /** Canonical public paths plus the exact historical settings helper's folder joins. */
    public static function settingsPath($path): ?string
    {
        if(self::safePath($path))return $path;
        // The unchanged legacy helper joined its trailing folder slash with storeAs' slash.
        if(is_string($path)&&preg_match('#^/uploadedImages//(settings-[0-9]{1,19}\.(?i:png|jpg|jpeg|gif|webp|ico))$#D',$path,$match))return 'uploadedImages/'.$match[1];
        if(!is_string($path)||!str_starts_with($path,'/uploadedImages/'))return null;
        $relative=substr($path,1);
        if(str_contains(substr($relative,strlen('uploadedImages/')),'/'))return null;
        return self::safePath($relative)?$relative:null;
    }
    private static function inside(string $file,string $root): bool
    {
        $file=str_replace('\\','/',$file);$root=rtrim(str_replace('\\','/',$root),'/').'/';
        if(DIRECTORY_SEPARATOR==='\\'){$file=strtolower($file);$root=strtolower($root);}
        return str_starts_with($file,$root);
    }
    private function disk(string $name): array
    {
        abort_unless(config('filesystems.disks.'.$name.'.driver')==='local',409);
        $base=realpath(storage_path('app/public'));$root=realpath((string)config('filesystems.disks.'.$name.'.root'));
        abort_unless($base&&$root&&($base===$root||self::inside($root,$base)),409);
        $prefix=trim(str_replace('\\','/',substr($root,strlen($base))),'/');
        return [$root,$prefix];
    }
    private static function file(string $path,string $area='public'): string
    {
        abort_unless(self::safePath($path,$area),422);
        if($area==='private'){
            $app=realpath(storage_path('app'));$disk=realpath((string)config('filesystems.disks.local.root'));
            $base=realpath(storage_path('app/branch-expenses'));$public=realpath(storage_path('app/public'));
            abort_unless(config('filesystems.disks.local.driver')==='local'&&$app&&$disk===$app&&$base&&self::inside($base,$app)
                &&(!$public||($base!==$public&&!self::inside($base,$public))),409);
            $file=realpath($base.'/'.substr($path,strlen('branch-expenses/')));
        }else{$base=realpath(storage_path('app/public'));$file=realpath(storage_path('app/public').'/'.$path);}
        abort_unless($base&&$file&&is_file($file)&&self::inside($file,$base),409);
        return $file;
    }
    private function branches(object $device,User $actor): array
    {
        $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);
        foreach($branches as $branch)$this->devices->branch($device,$branch,$actor);
        abort_unless(count($branches),403);return $branches;
    }
    /** Project only the original scoped queries; FK-expanded dataset rows never grant media access. */
    private function ownerMembership(array $rows,array $queries): array
    {
        $columns=[];$users=false;$pending=false;
        foreach($rows as $row){
            $model=str_starts_with((string)($row['model_type']??''),'App\\Models\\')?substr($row['model_type'],11):'';
            $table=self::MODELS[$model]??null;if(!$table||!isset($queries[$table]))continue;
            $columns[$table]['id']=true;$users=$users||in_array($model,['User','Admin'],true);$pending=$pending||$model==='PendingVendor';
        }
        if($users)foreach(self::USER_PARENTS as $table=>$names)if(isset($queries[$table]))foreach($names as $name)
            if(\Illuminate\Support\Facades\Schema::hasColumn($table,$name))$columns[$table][$name]=true;
        if($pending&&isset($queries['users'])&&\Illuminate\Support\Facades\Schema::hasColumn('users','pending_vendor_id'))$columns['users']['pending_vendor_id']=true;
        $membership=[];$count=0;
        foreach($columns as $table=>$names){
            foreach((clone $queries[$table])->select(array_keys($names))->cursor() as $record){
                abort_if(++$count>500000,413);
                foreach($names as $name=>$_){$value=$record->$name??null;if($value!==null)$membership[$table][$name][(string)$value]=true;}
            }
        }
        return $membership;
    }
    private function owner(array $row,array $queries,?array $membership=null): bool
    {
        $model=str_starts_with((string)$row['model_type'],'App\\Models\\')?substr($row['model_type'],11):'';
        $table=self::MODELS[$model]??null;
        if(!$table||!isset($queries[$table]))return false;
        if($membership!==null){
            $id=in_array($model,['User','Admin'],true)?(string)(int)$row['model_id']:(string)$row['model_id'];
            if(isset($membership[$table]['id'][$id]))return true;
            if(in_array($model,['User','Admin'],true))foreach(self::USER_PARENTS as $parent=>$columns)foreach($columns as $column)
                if(isset($membership[$parent][$column][$id]))return true;
            return $model==='PendingVendor'&&isset($membership['users']['pending_vendor_id'][$id]);
        }
        if(in_array($model,['User','Admin'],true)){
            $id=(int)$row['model_id'];
            if((clone $queries['users'])->where('id',$id)->exists())return true;
            // Foreign-key parents displayed by the original branch pages: their public profiles only.
            foreach(self::USER_PARENTS as $parent=>$columns){
                if(!isset($queries[$parent]))continue;
                foreach($columns as $column)if(\Illuminate\Support\Facades\Schema::hasColumn($parent,$column)
                    &&(clone $queries[$parent])->where($column,$id)->exists())return true;
            }
            return false;
        }
        if($model==='PendingVendor')return (clone $queries[$table])->where('id',$row['model_id'])->exists()||(isset($queries['users'])
            &&\Illuminate\Support\Facades\Schema::hasColumn('users','pending_vendor_id')
            &&(clone $queries['users'])->where('pending_vendor_id',$row['model_id'])->exists());
        return (clone $queries[$table])->where('id',$row['model_id'])->exists();
    }
    private static function fileIdentity(array $stat): array
    {
        $identity=[];
        foreach(['dev','ino','mode','size','mtime','ctime'] as $key){abort_unless(isset($stat[$key])&&is_int($stat[$key]),409);$identity[$key]=$stat[$key];}
        abort_unless(($identity['mode']&0170000)===0100000&&$identity['size']>0&&$identity['size']<=self::MAX_FILE,409);
        return $identity;
    }
    /** Called only after the manifest's original row/scope authorization. Cache no scope, ticket or account data. */
    private function fileProof(string $file,string $mime,string $extension): array
    {
        clearstatcache(true,$file);$named=@lstat($file);abort_unless(is_array($named),409);$before=self::fileIdentity($named);
        $stream=@fopen($file,'rb');abort_unless(is_resource($stream),409);
        try{
            $opened=fstat($stream);abort_unless(is_array($opened)&&self::fileIdentity($opened)===$before,409);
            $key='desktop-media-proof:1:'.hash('sha256',$file."\0".json_encode($before,JSON_THROW_ON_ERROR));
            try{$cached=Cache::get($key);}catch(\Throwable $error){$cached=null;}
            $valid=is_array($cached)&&array_keys($cached)===['format','identity','mime','sha256','expires_at']
                &&$cached['format']===1&&$cached['identity']===$before&&$cached['mime']===$mime
                &&is_string($cached['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$cached['sha256'])
                &&is_int($cached['expires_at'])&&$cached['expires_at']>time()&&$cached['expires_at']<=time()+self::PROOF_CACHE_SECONDS;
            if($valid)$sha=$cached['sha256'];
            else{
                // MIME and checksum refer to this descriptor; paths may be replaced during export.
                $head=fread($stream,65536);abort_unless(is_string($head)&&$head!=='',409);
                $detected=(new \finfo(FILEINFO_MIME_TYPE))->buffer($head);
                abort_unless($detected===$mime||($extension==='ico'&&in_array($detected,['image/vnd.microsoft.icon','image/x-icon'],true)),409);
                abort_unless(rewind($stream),409);$hash=hash_init('sha256');$read=hash_update_stream($hash,$stream,self::MAX_FILE+1);
                abort_unless($read===$before['size'],409);$sha=hash_final($hash);
            }
            $after=fstat($stream);clearstatcache(true,$file);$current=@lstat($file);
            abort_unless(is_array($after)&&is_array($current)&&self::fileIdentity($after)===$before&&self::fileIdentity($current)===$before,409);
            if(!$valid){
                try{Cache::put($key,['format'=>1,'identity'=>$before,'mime'=>$mime,'sha256'=>$sha,'expires_at'=>time()+self::PROOF_CACHE_SECONDS],self::PROOF_CACHE_SECONDS);}
                catch(\Throwable $error){/* Cache outages must not change the verified media contract. */}
            }
            return ['bytes'=>$before['size'],'sha256'=>$sha];
        }finally{fclose($stream);}
    }
    public function manifest(array $dataset,object $device,User $actor,string $snapshot): array
    {
        $queries=$this->data->queries($device,$actor,$this->branches($device,$actor));
        $owners=$this->ownerMembership($dataset['media']??[],$queries);
        $files=[];$issues=[];$total=0;
        $add=function(string $path,array $proof,string $area='public',array $expected=[])use(&$files,&$issues,&$total,$device,$actor,$snapshot){
            $key=$area."\0".$path;
            try{
                $file=self::file($path,$area);$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
                $mime=($area==='private'?self::PRIVATE_MIMES:self::MIMES)[$extension];$proofFile=$this->fileProof($file,$mime,$extension);
                $bytes=$proofFile['bytes'];$sha=$proofFile['sha256'];
                if($area==='private')abort_unless(($expected['sha256']??null)===$sha&&($expected['mime']??null)===$mime,409);
                if(isset($files[$key]))return;
                abort_if(count($files)>=self::MAX_FILES||$total+$bytes>self::MAX_TOTAL,413);
                $total+=$bytes;$namespace=$area==='private'?['area'=>'private']:[];
                $ticket=Crypt::encryptString(DesktopDashboardBootstrap::json(['format'=>1,'device_id'=>$device->id,'actor_id'=>(int)$actor->id,
                    'enrollment_binding'=>$this->devices->enrollmentFingerprint($device),
                    'snapshot_id'=>$snapshot,'path'=>$path,'sha256'=>$sha,'bytes'=>$bytes,'mime'=>$mime,'proof'=>$proof,'expires_at'=>time()+3600]+$namespace));
                $files[$key]=['path'=>$path,'sha256'=>$sha,'bytes'=>$bytes,'mime'=>$mime,'ticket'=>$ticket]+$namespace;
            }catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$issues[]=['path'=>$path,
                'reason'=>$error->getStatusCode()===413?'media-capacity-exceeded':'unavailable-or-unsupported']+($area==='private'?['area'=>'private']:[]);}
        };
        foreach($dataset['media']??[] as $row){
            try{
                abort_unless($this->owner($row,$queries,$owners)&&config('media-library.path_generator')===\Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class
                    &&ctype_digit((string)$row['id'])&&(int)$row['id']>0&&is_string($row['file_name'])&&!str_contains($row['file_name'],'/'),409);
                [$root,$prefix]=$this->disk($row['disk']);$relative=$row['id'].'/'.$row['file_name'];
                $proof=['kind'=>'media','id'=>(int)$row['id'],'disk'=>$row['disk'],'relative'=>$relative];
                $add(($prefix?$prefix.'/':'').$relative,$proof);
                // Only this authorized media ID's derivative folders, never adjacent media or branches.
                foreach(['conversions','responsive-images'] as $folder){
                    $disk=$row['conversions_disk']?:$row['disk'];[$derivedRoot,$derivedPrefix]=$this->disk($disk);
                    $directory=$derivedRoot.'/'.$row['id'].'/'.$folder;
                    if(!is_dir($directory))continue;
                    abort_unless(($resolved=realpath($directory))&&self::inside($resolved,$derivedRoot),409);
                    foreach(new \DirectoryIterator($directory) as $entry){
                        if($entry->isDot())continue;
                        abort_unless($entry->isFile()&&!$entry->isLink(),409);
                        $relative=$row['id'].'/'.$folder.'/'.$entry->getFilename();
                        $add(($derivedPrefix?$derivedPrefix.'/':'').$relative,['kind'=>'media','id'=>(int)$row['id'],'disk'=>$disk,'relative'=>$relative]);
                    }
                }
            }catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$issues[]=['media_id'=>(int)$row['id'],'reason'=>'unavailable-or-unsupported'];}
        }
        foreach($dataset['go_store_products']??[] as $row){
            if(!empty($row['image_path'])){
                abort_unless(isset($queries['go_store_products'])&&(clone $queries['go_store_products'])->where('id',$row['id'])->where('image_path',$row['image_path'])->exists(),403);
                $add($row['image_path'],['kind'=>'store-product','id'=>(int)$row['id']]);
            }
        }
        foreach($dataset['branch_expenses']??[] as $row){
            if(empty($row['attachment_path']))continue;
            abort_unless(isset($queries['branch_expenses'])&&(clone $queries['branch_expenses'])->where('id',$row['id'])->exists(),403);
            $add($row['attachment_path'],['kind'=>'expense','id'=>(int)$row['id']],'private',
                ['sha256'=>$row['attachment_hash']??null,'mime'=>$row['attachment_mime']??null]);
        }
        foreach($dataset['branch_employees']??[] as $row){
            if(!isset($queries['branch_employees']))break;
            foreach(EmployeeIdentity::SIDES as $side){
                $path=$row['id_'.$side.'_path']??null;if(empty($path))continue;
                if(!EmployeeIdentity::validPath($path)){$issues[]=['path'=>$path,'area'=>'private','reason'=>'unavailable-or-unsupported'];continue;}
                abort_unless((clone $queries['branch_employees'])->where('id',$row['id'])->exists(),403);
                $add($path,['kind'=>'employee-id','id'=>(int)$row['id'],'side'=>$side],'private',
                    ['sha256'=>$row['id_'.$side.'_hash']??null,'mime'=>$row['id_'.$side.'_mime']??null]);
            }
        }
        foreach($dataset['settings']??[] as $row){
            if(($row['group']??'')!=='general'||!in_array($row['name'],self::SETTINGS,true))continue;
            try{$value=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $error){$value=null;}
            if($value===null||$value===''||$value==='0')continue;
            if(!is_string($value)){$issues[]=['setting'=>$row['name'],'reason'=>'unsupported'];continue;}
            $path=self::settingsPath($value);
            if($path===null){$issues[]=['setting'=>$row['name'],'reason'=>'unsupported'];continue;}
            $add($path,['kind'=>'setting','name'=>$row['name'],'source_path'=>$value]);
        }
        ksort($files);
        return ['files'=>array_values($files),'complete'=>$issues===[],'issues'=>$issues,'bytes'=>$total];
    }
    public function download(object $device,string $ticket): array
    {
        return DB::transaction(function()use($device,$ticket){
            $device=$this->devices->lockAuthenticated($device);
            return $this->downloadLocked($device,$ticket);
        });
    }
    private function downloadLocked(object $device,string $ticket): array
    {
        abort_if(strlen($ticket)>8192,422);
        try{$value=json_decode(Crypt::decryptString($ticket),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){abort(422);}
        $actor=$this->devices->actor($device);$queries=$this->data->queries($device,$actor,$this->branches($device,$actor));
        abort_unless(($value['format']??null)===1&&($value['device_id']??null)===$device->id
            &&is_string($value['enrollment_binding']??null)&&hash_equals($this->devices->enrollmentFingerprint($device),$value['enrollment_binding'])
            &&($value['actor_id']??null)===(int)$actor->id&&($value['expires_at']??0)>=time(),403);
        $proof=$value['proof']??[];$path=$value['path']??'';$area=$value['area']??'public';
        abort_unless($area===(in_array($proof['kind']??'',['expense','employee-id'],true)?'private':'public'),403);
        switch($proof['kind']??''){
            case 'media':
                $row=(array)DB::table('media')->where('id',$proof['id'])->first();abort_unless($row&&$this->owner($row,$queries),403);
                $disk=$proof['disk'];abort_unless(in_array($disk,[$row['disk'],$row['conversions_disk']?:$row['disk']],true),403);
                [$root,$prefix]=$this->disk($disk);$relative=$proof['relative'];
                abort_unless($relative===$row['id'].'/'.$row['file_name']
                    ||str_starts_with($relative,$row['id'].'/conversions/')||str_starts_with($relative,$row['id'].'/responsive-images/'),403);
                abort_unless($path===($prefix?$prefix.'/':'').$relative,403);break;
            case 'store-product':
                $row=isset($queries['go_store_products'])?(clone $queries['go_store_products'])->where('id',$proof['id'])->first():null;
                abort_unless($row&&$row->image_path===$path,403);break;
            case 'setting':
                abort_unless(in_array($proof['name']??'',self::SETTINGS,true),403);
                $row=DB::table('settings')->where('group','general')->where('name',$proof['name'])->first();
                $source=$row?json_decode($row->payload,true):null;
                abort_unless($row&&is_string($source)&&($proof['source_path']??null)===$source&&self::settingsPath($source)===$path,403);break;
            case 'expense':
                $row=isset($queries['branch_expenses'])?(clone $queries['branch_expenses'])->where('id',$proof['id'])->first():null;
                abort_unless($row&&$row->attachment_path===$path&&$row->attachment_hash===$value['sha256']&&$row->attachment_mime===$value['mime'],403);break;
            case 'employee-id':
                $side=$proof['side']??'';abort_unless(in_array($side,EmployeeIdentity::SIDES,true)&&EmployeeIdentity::validPath($path),403);
                $row=isset($queries['branch_employees'])?(clone $queries['branch_employees'])->where('id',$proof['id'])->first():null;
                abort_unless($row&&$row->{'id_'.$side.'_path'}===$path&&$row->{'id_'.$side.'_hash'}===$value['sha256']&&$row->{'id_'.$side.'_mime'}===$value['mime'],403);break;
            default:abort(403);
        }
        // Hash the exact bytes returned; changed/deleted files force a fresh coherent bootstrap.
        $file=self::file($path,$area);$bytes=file_get_contents($file,false,null,0,self::MAX_FILE+1);
        abort_unless(is_string($bytes)&&strlen($bytes)===$value['bytes']&&hash_equals($value['sha256'],hash('sha256',$bytes)),409);
        return ['bytes'=>$bytes,'mime'=>$value['mime'],'sha256'=>$value['sha256']];
    }
    public static function receipt(array $manifest): array
    {
        abort_if(count($manifest)>self::MAX_FILES,413);$files=[];$total=0;$seen=[];
        foreach($manifest as $part){
            abort_unless(is_array($part),422);$area=array_key_exists('area',$part)?$part['area']:'public';
            abort_unless(is_string($area)&&self::safePath($part['path']??null,$area)&&preg_match('/^[a-f0-9]{64}$/D',$part['sha256']??'')
                &&is_int($part['bytes']??null)&&$part['bytes']>0&&$part['bytes']<=self::MAX_FILE
                &&($part['mime']??null)===($area==='private'?self::PRIVATE_MIMES:self::MIMES)[strtolower(pathinfo($part['path'],PATHINFO_EXTENSION))],422);
            $fold=$area."\0".mb_strtolower($part['path']);abort_if(isset($seen[$fold]),422);$seen[$fold]=true;
            $total+=$part['bytes'];abort_if($total>self::MAX_TOTAL,413);
            $files[]=['path'=>$part['path'],'sha256'=>$part['sha256'],'bytes'=>$part['bytes'],'mime'=>$part['mime']]+($area==='private'?['area'=>'private']:[]);
        }
        return $files;
    }
    public static function verifyFiles(array $files): void
    {
        $files=self::receipt($files);
        foreach($files as $part){
            $file=self::file($part['path'],$part['area']??'public');abort_unless(filesize($file)===$part['bytes']
                &&hash_equals($part['sha256'],hash_file('sha256',$file)),409,'مرفقات قاعدة التجهيز لم تكتمل.');
        }
    }
}
