<?php
namespace App\Services\Dashboard;

use App\Models\Resturant;
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Validator};
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\{FileNamer\DefaultFileNamer,PathGenerator\DefaultPathGenerator};

/** Scoped Spatie filesystem: preserve old restaurant bytes, create only operation-bound replacements. */
class DesktopDashboardRestaurantImages extends DesktopDashboardProductImages
{
    private const COLUMNS=['id','model_type','model_id','uuid','collection_name','name','file_name','mime_type','disk','conversions_disk','size','manipulations','custom_properties','generated_conversions','responsive_images','order_column','created_at','updated_at'];
    private ?int $restaurant=null;
    private array $protected=[],$uploads=[],$written=[],$done=[];
    private ?string $command=null;
    private ?array $replay=null;
    private bool $active=false,$knownRollback=false;
    public function guard(callable $work)
    {
        if($this->active)return $work();$app=app();$old=$app->bound(self::class)?$app->make(self::class):null;$app->instance(self::class,$this);
        $this->active=true;$this->restaurant=null;$this->protected=[];$this->written=[];$this->uploads=[];$this->done=[];$this->command=null;$this->replay=null;$this->knownRollback=false;
        try{return parent::guard($work);}
        catch(\Throwable $e){if($this->knownRollback)foreach(array_reverse($this->written) as $path)if(is_file($path)&&!is_link($path))unlink($path);throw $e;}
        finally{$this->active=false;$this->restaurant=null;$this->protected=[];$this->written=[];$this->uploads=[];$this->done=[];$this->command=null;$this->replay=null;$app->forgetInstance(self::class);if($old)$app->instance(self::class,$old);}
    }
    public function rollbackKnown(): void {$this->knownRollback=true;}
    public function transaction(callable $work) {return $this->guard(fn()=>DB::transaction(function()use($work){try{return $work();}catch(\Throwable $e){$this->rollbackKnown();throw $e;}}));}
    public function allowRestaurant(int $id,string $command,?array $replay=null): void
    {abort_unless($this->active&&DB::transactionLevel()>0&&$id>0,409);Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();$this->restaurant=$id;$this->command=$command;$this->replay=$replay;}
    public function handlesMedia(Media $media): bool {return $media->model_type===Resturant::class;}
    private function root(): string
    {
        abort_unless(config('filesystems.disks.resturants.driver')==='local'&&config('media-library.path_generator')===DefaultPathGenerator::class
            &&config('media-library.file_namer')===DefaultFileNamer::class&&config('media-library.media_model')===Media::class
            &&config('media-library.custom_path_generators',[])===[]&&config('media-library.moves_media_on_update')===false&&in_array(config('media-library.prefix'),[null,''],true),409);
        $root=storage_path('app/public/resturants');$base=storage_path('app/public');abort_unless(!is_link($root)&&!is_link($base),409);
        if(!is_dir($root))abort_unless(mkdir($root,0755,true)||is_dir($root),503);
        abort_unless(rtrim(str_replace('\\','/',(string)config('filesystems.disks.resturants.root')),'/')===rtrim(str_replace('\\','/',$root),'/'),409);
        return realpath($root);
    }
    private function file(string $relative): array
    {
        abort_unless(DesktopDashboardMedia::safePath('resturants/'.$relative),409);$root=$this->root();$path=$root;
        foreach(explode('/',$relative) as $part){$path.=DIRECTORY_SEPARATOR.$part;abort_if(is_link($path),409);}
        abort_unless(is_file($path)&&filesize($path)>0&&filesize($path)<=DesktopDashboardMedia::MAX_FILE,409);
        $mime=DesktopDashboardMedia::MIMES[strtolower(pathinfo($relative,PATHINFO_EXTENSION))]??null;$actual=(new \finfo(FILEINFO_MIME_TYPE))->file($path);abort_unless($mime&&$actual===$mime,409);
        return ['disk'=>'resturants','relative'=>$relative,'path'=>'resturants/'.$relative,'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'mime'=>$mime];
    }
    private function row(array $row): void
    {
        abort_unless(count($row)===count(self::COLUMNS)&&array_diff(array_keys($row),self::COLUMNS)===[]&&$row['model_type']===Resturant::class&&(int)$row['id']>0&&(int)$row['model_id']>0
            &&$row['disk']==='resturants'&&in_array($row['conversions_disk'],[null,'','resturants'],true)&&is_string($row['file_name'])
            &&!str_contains($row['file_name'],'/')&&DesktopDashboardMedia::safePath('resturants/'.$row['id'].'/'.$row['file_name']),409);
        foreach($row as $value)abort_unless($value===null||is_scalar($value),409);
    }
    public function facts(int $restaurant): array
    {
        $images=[];$rows=DB::table('media')->where('model_type',Resturant::class)->where('model_id',$restaurant)->orderBy('id')->lockForUpdate()->get();abort_unless(count($rows)<=50,409);
        foreach($rows as $item){$row=(array)$item;$this->row($row);$files=[$this->file($row['id'].'/'.$row['file_name'])];
            foreach(['conversions','responsive-images'] as $sub){$dir=$this->root().'/'.$row['id'].'/'.$sub;if(!file_exists($dir))continue;abort_unless(is_dir($dir)&&!is_link($dir),409);
                foreach(new \DirectoryIterator($dir) as $entry){if($entry->isDot())continue;abort_unless($entry->isFile()&&!$entry->isLink(),409);$files[]=$this->file($row['id'].'/'.$sub.'/'.$entry->getFilename());}}
            usort($files,fn($a,$b)=>strcmp($a['relative'],$b['relative']));$images[]=['row'=>$row,'files'=>$files];}
        $this->validateFacts($images);return $images;
    }
    public function validateFacts(array $images): void
    {
        abort_unless(array_is_list($images)&&count($images)<=50,409);$ids=[];$total=0;
        foreach($images as $image){abort_unless(is_array($image)&&array_keys($image)===['row','files']&&is_array($image['row'])&&is_array($image['files'])&&array_is_list($image['files']),409);$row=$image['row'];$this->row($row);abort_if(isset($ids[$row['id']]),409);$ids[$row['id']]=true;$primary=0;$seen=[];
            foreach($image['files'] as $file){abort_unless(is_array($file)&&array_keys($file)===['disk','relative','path','bytes','sha256','mime']&&$file['disk']==='resturants'&&is_string($file['relative'])&&is_string($file['path'])&&$file['path']==='resturants/'.$file['relative']
                    &&DesktopDashboardMedia::safePath($file['path'])&&is_int($file['bytes'])&&$file['bytes']>0&&$file['bytes']<=DesktopDashboardMedia::MAX_FILE&&is_string($file['relative'])&&is_string($file['path'])&&is_string($file['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$file['sha256']),409);
                $relative=$file['relative'];$main=$relative===$row['id'].'/'.$row['file_name'];abort_unless($main||preg_match('#^'.preg_quote((string)$row['id'],'#').'/(?:conversions|responsive-images)/[^/]+$#D',$relative),409);
                abort_if(isset($seen[strtolower($relative)]),409);$seen[strtolower($relative)]=true;$primary+=(int)$main;if($main)abort_unless($file['bytes']===(int)$row['size']&&$file['mime']===$row['mime_type'],409);abort_if(++$total>200,409);}
            abort_unless($primary===1,409);}
    }
    public function verifyFacts(array $images,int $status=409): void
    {$this->validateFacts($images);foreach($images as $image)foreach($image['files'] as $file)abort_unless($this->file($file['relative'])===$file,$status);}
    public function protectOld(array $images): void
    {
        abort_unless($this->active&&$this->restaurant&&DB::transactionLevel()>0,409);$this->verifyFacts($images);
        foreach($images as $image){$row=$image['row'];abort_unless((int)$row['model_id']===$this->restaurant,403);$this->protected[(int)$row['id']]=$row;}
    }
    public function preserves(Media $media): bool
    {$row=$this->protected[(int)$media->id]??null;if(!$row)return false;abort_unless($this->handlesMedia($media)&&(int)$media->model_id===$this->restaurant&&$media->disk==='resturants'
        &&$media->file_name===$row['file_name']&&in_array($media->collection_name,['logo','bg_image'],true),409);return true;}
    public function files(Request $request): array
    {
        $files=$request->allFiles();abort_if(array_diff(array_keys($files),['logo','bg_image']),501);$result=[];
        foreach(['logo','bg_image'] as $collection)if(isset($files[$collection])){$f=$files[$collection];abort_unless($f instanceof UploadedFile&&$f->isValid()&&$f->getSize()>0&&$f->getSize()<=self::MAX_FILE,422);
            $bytes=file_get_contents($f->getRealPath());$result[$collection]=['name'=>$f->getClientOriginalName(),'mime'=>$f->getMimeType(),'sha256'=>hash('sha256',$bytes),'base64'=>base64_encode($bytes)];}
        $this->decoded($result);return $result;
    }
    public function decoded(array $files): array
    {
        abort_if(array_diff(array_keys($files),['logo','bg_image']),422);$decoded=[];
        foreach($files as $collection=>$a){abort_unless(is_array($a)&&array_keys($a)===['name','mime','sha256','base64'],422);Validator::make($a,['name'=>'required|string|max:255','mime'=>'required|string','sha256'=>'required|regex:/^[a-f0-9]{64}$/D','base64'=>'required|string|max:6990508'])->validate();
            $bytes=base64_decode($a['base64'],true);$shape=is_string($bytes)?@getimagesizefromstring($bytes):false;$mime=is_string($bytes)?(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes):null;
            abort_unless(is_string($bytes)&&strlen($bytes)>0&&strlen($bytes)<=self::MAX_FILE&&hash_equals($a['sha256'],hash('sha256',$bytes))&&$mime===$a['mime']
                &&(DesktopDashboardMedia::MIMES[strtolower(pathinfo($a['name'],PATHINFO_EXTENSION))]??null)===$mime&&in_array($mime,['image/png','image/jpeg','image/webp'],true)&&($shape['mime']??null)===$mime&&$shape[0]>0&&$shape[1]>0&&$shape[0]<=8192&&$shape[1]<=8192&&$shape[0]*$shape[1]<=32*1024*1024
                &&!str_contains($a['name'],'/')&&!str_contains($a['name'],'\\')&&DesktopDashboardMedia::safePath('resturants/1/'.$a['name']),422);
            $decoded[$collection]=[$a,$bytes];}
        return $decoded;
    }
    public function withUploads(array $files,callable $work)
    {
        $decoded=$this->decoded($files);$paths=[];$uploads=[];$this->uploads=$decoded;$this->done=[];
        try{foreach($decoded as $collection=>[$a,$bytes]){$path=tempnam(sys_get_temp_dir(),'desktop-restaurant-');abort_unless($path!==false,503);$paths[]=$path;abort_unless(file_put_contents($path,$bytes)===strlen($bytes),503);$uploads[$collection]=new UploadedFile($path,$a['name'],$a['mime'],null,true);}
            $result=$work($uploads);abort_unless(count($this->done)===count($decoded),409,'حفظ صور الفرع لم يكتمل؛ العملية لم تُعتمد.');return $result;
        }finally{$this->uploads=[];foreach($paths as $path)if(is_file($path))unlink($path);}
    }
    public function copy(string $source,Media $media,?string $type,?string $target): void
    {
        $collection=$media->collection_name;abort_unless($this->active&&$this->restaurant&&$this->command&&DB::transactionLevel()>0&&$this->handlesMedia($media)
            &&(int)$media->model_id===$this->restaurant&&isset($this->uploads[$collection])&&!isset($this->done[$collection])&&$type===null&&$media->disk==='resturants'&&$media->wasRecentlyCreated&&get_class($media)===Media::class,409);
        [$a,$bytes]=$this->uploads[$collection];$name=str_replace(['#','/','\\',' '],'-',$a['name']);abort_unless(($target??$name)===$name&&$media->file_name===$name&&$media->mime_type===$a['mime']&&(int)$media->size===strlen($bytes)&&hash_equals($a['sha256'],hash_file('sha256',$source)),409);
        $values=['uuid'=>(string)\Ramsey\Uuid\Uuid::uuid5($this->command,'restaurant:'.$collection)];
        if(isset($this->replay[$collection])){$time=$this->replay[$collection];abort_unless(is_array($time)&&array_keys($time)===['created_at','updated_at','order_column']&&is_int($time['order_column'])&&$time['order_column']>0,409);foreach(['created_at','updated_at'] as $field)abort_unless(is_string($time[$field])&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$time[$field]),409);abort_unless($time['created_at']===$time['updated_at'],409);$values+=$time;}
        DB::table('media')->where('id',$media->id)->update($values);foreach($values as $key=>$value)$media->setAttribute($key,$value);$media->syncOriginalAttributes(array_keys($values));
        $dir=$this->root().'/'.$media->id;abort_if(is_link($dir),409);if(!is_dir($dir))abort_unless(mkdir($dir,0755)||is_dir($dir),503);$path=$dir.'/'.$name;abort_if(is_link($path),409);
        if(file_exists($path)){abort_unless(is_file($path)&&hash_equals($a['sha256'],hash_file('sha256',$path)),409);}
        else{$this->written[]=$path;$out=fopen($path,'xb');abort_unless(is_resource($out),503);try{$offset=0;while($offset<strlen($bytes)){$written=fwrite($out,substr($bytes,$offset));abort_unless(is_int($written)&&$written>0,503);$offset+=$written;}abort_unless(fflush($out)&&fsync($out),503);}finally{fclose($out);}}
        abort_unless(hash_equals($a['sha256'],hash_file('sha256',$path)),503);$this->done[$collection]=true;
    }
    public function verifyResult(array $result,array $files): void
    {$after=$result['after']??($result['result']['after']??null);abort_unless(is_array($after)&&is_array($after['images']??null),409);$this->verifyFacts($after['images']);$this->verifyUploads($after['images'],$files);}
    public function verifyUploads(array $images,array $files): void
    {
        $decoded=$this->decoded($files);foreach($decoded as $collection=>[$upload,$bytes]){$found=array_values(array_filter($images,fn($i)=>$i['row']['collection_name']===$collection));abort_unless(count($found)===1,409);
            $image=$found[0];$row=$image['row'];$name=str_replace(['#','/','\\',' '],'-',$upload['name']);abort_unless($row['file_name']===$name&&$row['mime_type']===$upload['mime']&&(int)$row['size']===strlen($bytes)&&count($image['files'])===1
                &&$image['files'][0]['sha256']===$upload['sha256'],409);}
    }
}
