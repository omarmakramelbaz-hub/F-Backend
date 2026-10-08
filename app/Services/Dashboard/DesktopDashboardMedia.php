<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{Crypt,DB};

/** Explicit scoped image references. Never enumerate the shared public storage tree. */
class DesktopDashboardMedia
{
    public const MIMES=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','ico'=>'image/x-icon'];
    public const MAX_FILE=16*1024*1024;
    public const MAX_TOTAL=1024*1024*1024;
    public const MAX_FILES=20000;
    private const MODELS=['User'=>'users','Admin'=>'users','Resturant'=>'resturants','ResturantProduct'=>'resturant_products',
        'Product'=>'products','Category'=>'categories','Advertising'=>'advertisings','Banner'=>'banners','Slidear'=>'slidears',
        'Feature'=>'features','PendingVendor'=>'pending_vendors','Area'=>'areas','Review'=>'reviews'];
    private const SETTINGS=['logo','favicon','advertise_image'];
    public function __construct(private DesktopDashboardDevices $devices,private DesktopDashboardData $data) {}

    public static function safePath($path): bool
    {
        if(!is_string($path)||$path===''||strlen($path)>500||str_starts_with($path,'/')
            ||preg_match('/[\\\\\x00-\x1f\x7f:*?"<>|%]/u',$path)||str_ends_with($path,'/'))return false;
        foreach(explode('/',$path) as $part)if(strlen($part)>255||$part===''||$part==='.'||$part==='..'||str_ends_with($part,'.')||str_ends_with($part,' ')
            ||preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i',$part))return false;
        return isset(self::MIMES[strtolower(pathinfo($path,PATHINFO_EXTENSION))]);
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
    private function file(string $path): string
    {
        abort_unless(self::safePath($path),422);
        $base=realpath(storage_path('app/public'));$file=realpath(storage_path('app/public').'/'.$path);
        abort_unless($base&&$file&&is_file($file)&&self::inside($file,$base),409);
        return $file;
    }
    private function branches(object $device,User $actor): array
    {
        $branches=json_decode($device->branches,true,512,JSON_THROW_ON_ERROR);
        foreach($branches as $branch)$this->devices->branch($device,$branch,$actor);
        abort_unless(count($branches),403);return $branches;
    }
    private function owner(array $row,array $queries): bool
    {
        $model=str_starts_with((string)$row['model_type'],'App\\Models\\')?substr($row['model_type'],11):'';
        $table=self::MODELS[$model]??null;
        if(!$table||!isset($queries[$table]))return false;
        if(in_array($model,['User','Admin'],true)){
            $id=(int)$row['model_id'];
            if((clone $queries['users'])->where('id',$id)->exists())return true;
            // Foreign-key parents displayed by the original branch pages: their public profiles only.
            foreach(['orders'=>['user_id'],'go_store_orders'=>['customer_id'],'products'=>['added_by'],
                'categories'=>['added_by'],'resturants'=>['added_by']] as $parent=>$columns){
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
    public function manifest(array $dataset,object $device,User $actor,string $snapshot): array
    {
        $queries=$this->data->queries($device,$actor,$this->branches($device,$actor));
        $files=[];$issues=[];$total=0;
        $add=function(string $path,array $proof)use(&$files,&$issues,&$total,$device,$actor,$snapshot){
            if(isset($files[$path]))return;
            try{
                $file=$this->file($path);$bytes=filesize($file);$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
                $mime=self::MIMES[$extension];$detected=(new \finfo(FILEINFO_MIME_TYPE))->file($file);
                abort_unless($bytes>0&&$bytes<=self::MAX_FILE&&($detected===$mime
                    ||($extension==='ico'&&in_array($detected,['image/vnd.microsoft.icon','image/x-icon'],true))),409);
                abort_if(count($files)>=self::MAX_FILES||$total+$bytes>self::MAX_TOTAL,413);
                $sha=hash_file('sha256',$file);abort_unless(is_string($sha),409);$total+=$bytes;
                $ticket=Crypt::encryptString(DesktopDashboardBootstrap::json(['format'=>1,'device_id'=>$device->id,'actor_id'=>(int)$actor->id,
                    'snapshot_id'=>$snapshot,'path'=>$path,'sha256'=>$sha,'bytes'=>$bytes,'mime'=>$mime,'proof'=>$proof,'expires_at'=>time()+3600]));
                $files[$path]=['path'=>$path,'sha256'=>$sha,'bytes'=>$bytes,'mime'=>$mime,'ticket'=>$ticket];
            }catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$issues[]=['path'=>$path,'reason'=>'unavailable-or-unsupported'];}
        };
        foreach($dataset['media']??[] as $row){
            try{
                abort_unless($this->owner($row,$queries)&&config('media-library.path_generator')===\Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class
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
            if(!empty($row['image_path']))$add($row['image_path'],['kind'=>'store-product','id'=>(int)$row['id']]);
        }
        foreach($dataset['settings']??[] as $row){
            if(($row['group']??'')!=='general'||!in_array($row['name'],self::SETTINGS,true))continue;
            try{$value=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $error){$value=null;}
            if($value===null||$value===''||$value==='0')continue;
            if(!is_string($value)){$issues[]=['setting'=>$row['name'],'reason'=>'unsupported'];continue;}
            $add($value,['kind'=>'setting','name'=>$row['name']]);
        }
        ksort($files);
        return ['files'=>array_values($files),'complete'=>$issues===[],'issues'=>$issues,'bytes'=>$total];
    }
    public function download(object $device,string $ticket): array
    {
        abort_if(strlen($ticket)>8192,422);
        try{$value=json_decode(Crypt::decryptString($ticket),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){abort(422);}
        $actor=$this->devices->actor($device);$queries=$this->data->queries($device,$actor,$this->branches($device,$actor));
        abort_unless(($value['format']??null)===1&&($value['device_id']??null)===$device->id
            &&($value['actor_id']??null)===(int)$actor->id&&($value['expires_at']??0)>=time(),403);
        $proof=$value['proof']??[];$path=$value['path']??'';
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
                abort_unless($row&&json_decode($row->payload,true)===$path,403);break;
            default:abort(403);
        }
        // Hash the exact bytes returned; changed/deleted files force a fresh coherent bootstrap.
        $file=$this->file($path);$bytes=file_get_contents($file,false,null,0,self::MAX_FILE+1);
        abort_unless(is_string($bytes)&&strlen($bytes)===$value['bytes']&&hash_equals($value['sha256'],hash('sha256',$bytes)),409);
        return ['bytes'=>$bytes,'mime'=>$value['mime'],'sha256'=>$value['sha256']];
    }
    public static function receipt(array $manifest): array
    {
        abort_if(count($manifest)>self::MAX_FILES,413);$files=[];$total=0;$seen=[];
        foreach($manifest as $part){
            abort_unless(is_array($part)&&self::safePath($part['path']??null)&&preg_match('/^[a-f0-9]{64}$/D',$part['sha256']??'')
                &&is_int($part['bytes']??null)&&$part['bytes']>0&&$part['bytes']<=self::MAX_FILE
                &&($part['mime']??null)===self::MIMES[strtolower(pathinfo($part['path'],PATHINFO_EXTENSION))],422);
            $fold=mb_strtolower($part['path']);abort_if(isset($seen[$fold]),422);$seen[$fold]=true;
            $total+=$part['bytes'];abort_if($total>self::MAX_TOTAL,413);
            $files[]=['path'=>$part['path'],'sha256'=>$part['sha256'],'bytes'=>$part['bytes'],'mime'=>$part['mime']];
        }
        return $files;
    }
    public static function verifyFiles(array $files): void
    {
        $files=self::receipt($files);$root=realpath(storage_path('app/public'));abort_unless($root,409);
        foreach($files as $part){
            $file=realpath($root.'/'.$part['path']);abort_unless($file&&self::inside($file,$root)&&is_file($file)
                &&filesize($file)===$part['bytes']&&hash_equals($part['sha256'],hash_file('sha256',$file)),409,'صور قاعدة التجهيز لم تكتمل.');
        }
    }
}
