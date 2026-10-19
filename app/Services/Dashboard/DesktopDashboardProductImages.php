<?php
namespace App\Services\Dashboard;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Validator};
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/** Bounded image envelopes and append-only physical bytes for reviewed product commands. */
class DesktopDashboardProductImages
{
    public const MAX_FILE=5*1024*1024;
    public const MAX_MEDIA=50;
    public const MAX_FILES=200;
    private const MODEL='App\\Models\\ResturantProduct';
    private const MIMES=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'];
    private const ROW=['id','model_type','model_id','uuid','collection_name','name','file_name','mime_type','disk','conversions_disk',
        'size','manipulations','custom_properties','generated_conversions','responsive_images','order_column','created_at','updated_at'];
    private int $depth=0;
    private array $protected=[];
    private array $allowed=[];
    private ?string $command=null;
    private ?array $replayMediaTimes=null;
    private ?array $upload=null;
    private bool $copied=false;
    private ?array $copies=null;
    private string $slot='product_image:0';
    private ?int $copyDestination=null;
    private ?string $copyName=null;

    /** Root callers wrap their entire DB transaction, including after-facts and outcome writes. */
    public function guard(callable $work)
    {
        if($this->depth){$this->depth++;try{return $work();}finally{$this->depth--;}}
        $app=app();$classes=[self::class,Filesystem::class];$saved=[];
        foreach($classes as $class){
            $bindings=$app->getBindings();
            $saved[$class]=['binding'=>$bindings[$class]??null,'shared'=>$app->isShared($class),
                'instance'=>$app->isShared($class)?$app->make($class):null];
        }
        $original=$app->make(Filesystem::class);
        // A custom filesystem has unreviewed copy/deletion behavior.
        abort_unless(get_class($original)===Filesystem::class,409,'طريقة حفظ صور المنتجات غير مدعومة في هذه المعاينة.');
        $this->depth=1;$this->protected=[];$this->allowed=[];
        $this->command=null;$this->replayMediaTimes=null;$this->upload=null;$this->copied=false;$this->copies=null;$this->slot='product_image:0';$this->copyDestination=null;$this->copyName=null;
        try{
            $app->instance(self::class,$this);
            $app->instance(Filesystem::class,new DesktopDashboardProductFilesystem($app->make(Factory::class),$this));
            return $work();
        }finally{
            $this->depth=0;$this->protected=[];$this->allowed=[];
            $this->command=null;$this->replayMediaTimes=null;$this->upload=null;$this->copied=false;$this->copies=null;$this->slot='product_image:0';$this->copyDestination=null;$this->copyName=null;
            foreach(array_reverse($classes) as $class){
                $app->forgetInstance($class);$previous=$saved[$class];
                if($previous['binding'])$app->bind($class,$previous['binding']['concrete'],$previous['binding']['shared']);
                else unset($app[$class]);
                if($previous['shared'])$app->instance($class,$previous['instance']);
            }
        }
    }

    /** Called by the adapter only AFTER its current actor, branch and resource authorization. */
    public function allowProduct(int $resturant,?int $product=null): void
    {
        abort_unless($this->depth>0&&DB::transactionLevel()>0&&$resturant>0&&($product===null||$product>0),409);
        $this->allowed[$resturant][$product===null?'new':(string)$product]=true;
    }

    /** Trusted adapter validates replay clocks and the new source-generated order before calling. */
    public function configureUpload(string $command,?array $replayMediaTimes=null): void
    {
        abort_unless($this->depth>0&&DB::transactionLevel()>0&&$this->allowed&&$this->upload===null&&$this->copies===null,409);
        Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();
        $this->replayTimes($replayMediaTimes);
        $this->command=strtolower($command);$this->replayMediaTimes=$replayMediaTimes;$this->copied=false;$this->slot='product_image:0';
    }

    private function replayTimes(?array $replayMediaTimes): void
    {
        if($replayMediaTimes!==null){
            abort_unless(array_keys($replayMediaTimes)===['created_at','updated_at','order_column']
                &&$replayMediaTimes['created_at']===$replayMediaTimes['updated_at']
                &&is_int($replayMediaTimes['order_column'])&&$replayMediaTimes['order_column']>0,422);
            foreach(['created_at','updated_at'] as $field){
                $time=$replayMediaTimes[$field];
                abort_unless(is_string($time)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D',$time),422);
                $parsed=\DateTimeImmutable::createFromFormat(str_contains($time,'.')?'!Y-m-d H:i:s.u':'!Y-m-d H:i:s',$time);
                $errors=\DateTimeImmutable::getLastErrors();
                abort_unless($parsed&&(!$errors||(!$errors['warning_count']&&!$errors['error_count'])),422);
            }
        }
    }

    /** Ordered, trusted copy slots bind each new media row to the actual selected source bytes. */
    public function configureCopies(string $command,array $plan): void
    {
        abort_unless($this->depth>0&&DB::transactionLevel()>0&&$this->allowed&&$this->command===null
            &&$this->upload===null&&$this->copies===null&&array_is_list($plan)&&count($plan)<=100,409);
        Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();
        $copies=[];$previous=-1;$total=0;
        foreach($plan as $entry){
            abort_unless(is_array($entry)&&array_keys($entry)===['selection','source_product','image','replay']
                &&is_int($entry['selection'])&&$entry['selection']>$previous&&$entry['selection']<100
                &&is_int($entry['source_product'])&&$entry['source_product']>0&&is_array($entry['image'])
                &&($entry['replay']===null||is_array($entry['replay'])),409);
            $previous=$entry['selection'];$image=$entry['image'];$this->validateFacts([$image]);$row=$image['row'];
            abort_unless((int)$row['model_id']===$entry['source_product']&&$row['collection_name']==='product_image'
                &&is_string($row['name'])&&mb_strlen($row['name'])<=255
                &&$this->fileName($row['file_name'])===$row['file_name']
                &&(self::MIMES[strtolower(pathinfo($row['file_name'],PATHINFO_EXTENSION))]??null)===$row['mime_type'],409,
                'اسم الصورة أو نوعها يحتاج مراجعة قبل نسخ المنيو.');
            $generated=json_decode((string)$row['generated_conversions'],true);
            abort_unless(json_last_error()===JSON_ERROR_NONE&&is_array($generated)&&!($generated['thumb']??false),409,
                'نسخ صورة مصغرة مولدة يحتاج مراجعة قبل المزامنة.');
            $primary=array_values(array_filter($image['files'],fn($file)=>$file['relative']===$row['id'].'/'.$row['file_name']))[0];
            abort_unless($primary['bytes']<=self::MAX_FILE,413);$total+=$primary['bytes'];
            abort_if($total>50*1024*1024,413,'صور المنيو تتجاوز حد المعاينة؛ انسخ مجموعة أصغر.');
            $this->replayTimes($entry['replay']);$copies[$entry['selection']]=$entry;
        }
        $this->command=strtolower($command);$this->copies=$copies;
    }

    /** Ordinary online copy uses FileAdder directly; the desktop guard verifies the exact planned source. */
    public function withMenuCopy(int $selection,\App\Models\ResturantProduct $source,\App\Models\ResturantProduct $destination,callable $work)
    {
        if(!$this->depth)return $work();
        $entry=$this->copies[$selection]??null;
        abort_unless(DB::transactionLevel()>0&&$entry&&$this->upload===null&&$this->command!==null
            &&$source->exists&&$destination->exists&&$destination->wasRecentlyCreated
            &&(int)$source->getKey()===$entry['source_product']&&(int)$source->getKey()!==(int)$destination->getKey(),409);
        $sourceRow=DB::table('resturant_products')->where('id',$source->getKey())->lockForUpdate()->first();
        $destinationRow=DB::table('resturant_products')->where('id',$destination->getKey())->lockForUpdate()->first();
        abort_unless($sourceRow&&$destinationRow
            &&($this->allowed[(int)$sourceRow->resturant_id][(string)$sourceRow->id]??false)
            &&($this->allowed[(int)$destinationRow->resturant_id]['new']??false),403);
        abort_if(DB::table('media')->where('model_type',self::MODEL)->where('model_id',$destination->getKey())->exists(),409);
        $image=$entry['image'];$row=$image['row'];$this->verifyFacts([$image]);
        $selected=$source->getFirstMedia('product_image');
        $actual=DB::table('media')->where('id',$row['id'])->lockForUpdate()->first();
        abort_unless($selected&&get_class($selected)===Media::class&&(int)$selected->getKey()===(int)$row['id']
            &&$actual&&app(DesktopDashboardJournal::class)->fingerprint((array)$actual)===app(DesktopDashboardJournal::class)->fingerprint($row),409);
        $path=$source->getFirstMediaPath('product_image','thumb');$canonical=$this->root().'/'.$row['id'].'/'.$row['file_name'];
        abort_unless(is_string($path)&&realpath($path)===realpath($canonical)&&!is_link($path),409);
        $bytes=file_get_contents($path,false,null,0,self::MAX_FILE+1);
        $primary=array_values(array_filter($image['files'],fn($file)=>$file['relative']===$row['id'].'/'.$row['file_name']))[0];
        abort_unless(is_string($bytes)&&strlen($bytes)===$primary['bytes']&&hash_equals($primary['sha256'],hash('sha256',$bytes)),503);
        $this->image($row['file_name'],$row['mime_type'],$bytes);
        $this->upload=['name'=>$row['file_name'],'mime'=>$row['mime_type'],'sha256'=>$primary['sha256']];
        $this->replayMediaTimes=$entry['replay'];$this->slot='menu_copy:'.$selection.':product_image:0';$this->copied=false;
        $this->copyDestination=(int)$destination->getKey();$this->copyName=$row['name'];
        try{
            $result=$work();abort_unless($this->copied,409,'الصورة المختارة لم تُنسخ كاملة.');
            unset($this->copies[$selection]);return $result;
        }finally{$this->upload=null;$this->replayMediaTimes=null;$this->slot='product_image:0';$this->copied=false;$this->copyDestination=null;$this->copyName=null;}
    }

    public function assertCopiesComplete(): void
    {
        abort_unless($this->depth>0&&$this->copies!==null&&$this->copies===[],409,'صور المنيو لم تُنسخ كاملة.');
    }

    public function files(Request $request): array
    {
        $files=$request->allFiles();if(!$files)return [];
        abort_unless(array_keys($files)===['product_image']&&$files['product_image'] instanceof UploadedFile
            &&$files['product_image']->isValid(),422,'صورة المنتج غير صالحة.');
        $file=$files['product_image'];
        abort_unless($file->getSize()>0&&$file->getSize()<=self::MAX_FILE,422,'الحد الأقصى لصورة المنتج في المعاينة ٥ ميجابايت.');
        $bytes=file_get_contents($file->getRealPath(),false,null,0,self::MAX_FILE+1);
        abort_unless(is_string($bytes)&&strlen($bytes)===$file->getSize(),503,'تعذر قراءة صورة المنتج كاملة.');
        $value=['name'=>$file->getClientOriginalName(),'mime'=>$file->getMimeType(),'sha256'=>hash('sha256',$bytes),'base64'=>base64_encode($bytes)];
        $this->decoded(['product_image'=>$value]);
        return ['product_image'=>$value];
    }

    public function decoded(array $files): array
    {
        if(!$files)return [];
        abort_unless(array_keys($files)===['product_image']&&is_array($files['product_image']),422,'صورة المنتج غير صالحة.');
        $a=$files['product_image'];
        Validator::make($a,['name'=>'required|string|max:255','mime'=>'required|string|max:100',
            'sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],'base64'=>'required|string|max:6990508'])->validate();
        abort_unless(count($a)===4&&!array_diff(array_keys($a),['name','mime','sha256','base64']),422);
        $bytes=base64_decode($a['base64'],true);
        abort_unless(is_string($bytes)&&strlen($bytes)>0&&strlen($bytes)<=self::MAX_FILE
            &&hash_equals($a['sha256'],hash('sha256',$bytes)),422,'صورة المنتج غير مكتملة.');
        $this->image($a['name'],$a['mime'],$bytes);
        return [$a,$bytes];
    }

    private function image(string $name,string $mime,string $bytes): void
    {
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        abort_unless(DesktopDashboardMedia::safePath('products/1/'.$name)&&!str_contains($name,'/')
            &&(self::MIMES[$extension]??null)===$mime,422,'صور المعاينة تقبل PNG أو JPEG أو WebP فقط، وبحد أقصى ٥ ميجابايت.');
        $detected=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$shape=@getimagesizefromstring($bytes);
        abort_unless($detected===$mime&&is_array($shape)&&($shape['mime']??null)===$mime
            &&$shape[0]>0&&$shape[1]>0&&$shape[0]<=8192&&$shape[1]<=8192&&$shape[0]*$shape[1]<=32*1024*1024,
            422,'محتوى صورة المنتج أو أبعادها غير مدعوم في المعاينة.');
    }

    /** FileAdder may unlink its upload. Each attempt receives a disposable copy of immutable bytes. */
    public function withUploads(array $files,callable $work)
    {
        abort_unless($this->copies===null,409);
        if(!$files)return $work([]);
        [$a,$bytes]=$this->decoded($files);$root=storage_path('private/desktop-product-uploads');
        $this->directory($root,storage_path('private'));
        $temporary=$root.'/'.bin2hex(random_bytes(16)).'.upload';$output=null;
        abort_unless($this->upload===null,409);$this->upload=$a;
        try{
            $output=fopen($temporary,'xb');abort_unless(is_resource($output),503,'تعذر تجهيز صورة المنتج.');
            $this->write($output,$bytes);fclose($output);$output=null;@chmod($temporary,0600);
            abort_unless(hash_equals($a['sha256'],hash_file('sha256',$temporary)),503,'صورة المنتج المؤقتة غير مكتملة.');
            return $work(['product_image'=>new UploadedFile($temporary,$a['name'],$a['mime'],null,true)]);
        }finally{$this->upload=null;if(is_resource($output))fclose($output);if(is_file($temporary))unlink($temporary);}
    }

    public function facts(int $product): array
    {
        abort_unless($product>0,422);$images=[];$total=0;
        $rows=DB::table('media')->where('model_type',self::MODEL)->where('model_id',$product)->orderBy('id')->lockForUpdate()->get();
        abort_if(count($rows)>self::MAX_MEDIA,413,'صور المنتج تحتاج مراجعة قبل المزامنة.');
        foreach($rows as $record){
            $row=(array)$record;$files=[];
            $this->row($row);$root=$this->root();$relative=$row['id'].'/'.$row['file_name'];
            $files[]=$this->file($relative,$root);
            abort_if(++$total>self::MAX_FILES,413);
            abort_unless($files[0]['bytes']===(int)$row['size']&&$files[0]['mime']===$row['mime_type'],409,'بيانات صورة المنتج مختلفة عن الملف المحفوظ.');
            foreach(['conversions','responsive-images'] as $folder){
                $directory=$root.'/'.$row['id'].'/'.$folder;if(!file_exists($directory))continue;
                $this->insideDirectory($directory,$root);
                foreach(new \DirectoryIterator($directory) as $entry){
                    if($entry->isDot())continue;
                    abort_unless($entry->isFile()&&!$entry->isLink(),409,'مسار صورة المنتج غير مدعوم.');
                    $files[]=$this->file($row['id'].'/'.$folder.'/'.$entry->getFilename(),$root);
                    abort_if(++$total>self::MAX_FILES,413);
                }
            }
            usort($files,fn($a,$b)=>strcmp($a['relative'],$b['relative']));$images[]=['row'=>$row,'files'=>$files];
        }
        $this->validateFacts($images);return $images;
    }

    private function row(array $row): void
    {
        abort_unless(count($row)===count(self::ROW)&&!array_diff(array_keys($row),self::ROW)
            &&$row['model_type']===self::MODEL&&ctype_digit((string)$row['id'])&&(int)$row['id']>0
            &&ctype_digit((string)$row['model_id'])&&(int)$row['model_id']>0
            &&$row['disk']==='products'&&in_array($row['conversions_disk'],[null,'','products'],true)
            &&is_string($row['file_name'])&&DesktopDashboardMedia::safePath('products/'.$row['id'].'/'.$row['file_name'])
            &&!str_contains($row['file_name'],'/')&&is_string($row['collection_name'])
            &&isset(DesktopDashboardMedia::MIMES[strtolower(pathinfo($row['file_name'],PATHINFO_EXTENSION))])
            &&ctype_digit((string)$row['size'])&&(int)$row['size']>0&&(int)$row['size']<=DesktopDashboardMedia::MAX_FILE,
            409,'مرجع صورة المنتج غير مدعوم في المعاينة.');
    }

    public function validateFacts(array $images): void
    {
        abort_unless(array_is_list($images)&&count($images)<=self::MAX_MEDIA,422);$seen=[];$total=0;
        foreach($images as $image){
            abort_unless(is_array($image)&&count($image)===2&&isset($image['row'],$image['files'])
                &&is_array($image['row'])&&is_array($image['files'])&&array_is_list($image['files'])&&count($image['files'])>0,422);
            $row=$image['row'];$this->row($row);abort_if(isset($seen[(string)$row['id']]),422);$seen[(string)$row['id']]=true;
            $paths=[];$primary=0;
            foreach($image['files'] as $file){
                abort_unless(is_array($file)&&count($file)===6&&!array_diff(array_keys($file),['disk','relative','path','bytes','sha256','mime'])
                    &&$file['disk']==='products'&&is_string($file['relative'])&&$file['path']==='products/'.$file['relative']
                    &&DesktopDashboardMedia::safePath($file['path'])&&is_int($file['bytes'])&&$file['bytes']>0&&$file['bytes']<=DesktopDashboardMedia::MAX_FILE
                    &&is_string($file['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$file['sha256'])
                    &&($file['mime']??null)===DesktopDashboardMedia::MIMES[strtolower(pathinfo($file['path'],PATHINFO_EXTENSION))],422);
                $relative=$file['relative'];$isPrimary=$relative===$row['id'].'/'.$row['file_name'];
                abort_unless($isPrimary||preg_match('#^'.preg_quote((string)$row['id'],'#').'/(?:conversions|responsive-images)/[^/]+$#D',$relative),422);
                $key=mb_strtolower($relative);abort_if(isset($paths[$key]),422);$paths[$key]=true;$primary+=(int)$isPrimary;
                if($isPrimary)abort_unless($file['bytes']===(int)$row['size']&&$file['mime']===$row['mime_type'],422);
                abort_if(++$total>self::MAX_FILES,413);
            }
            abort_unless($primary===1,422);
        }
    }

    /** Only freshly authorized, physically checked rows are eligible for deletion suppression. */
    public function protectOld(array $images): void
    {
        abort_unless($this->depth>0&&DB::transactionLevel()>0,409);$this->verifyFacts($images);
        foreach($images as $image){
            $row=$image['row'];if($row['collection_name']!=='product_image')continue;
            $product=DB::table('resturant_products')->where('id',$row['model_id'])->first();
            abort_unless($product&&($this->allowed[(int)$product->resturant_id][(string)$product->id]??false),403);
            $actual=DB::table('media')->where('id',$row['id'])->lockForUpdate()->first();
            abort_unless($actual&&app(DesktopDashboardJournal::class)->fingerprint((array)$actual)
                ===app(DesktopDashboardJournal::class)->fingerprint($row),409,'صورة المنتج تغيرت قبل تنفيذ العملية.');
            $this->protected[(string)$row['id']]=$row;
        }
    }

    public function handlesMedia(Media $media): bool {return $media->model_type===self::MODEL;}

    public function preserves(Media $media): bool
    {
        $row=$this->protected[(string)$media->getKey()]??null;
        if(!$row)return false;
        abort_unless($media->model_type===self::MODEL&&$media->collection_name==='product_image'&&$media->disk==='products'
            &&(int)$media->model_id===(int)$row['model_id']&&$media->file_name===$row['file_name'],409);
        return true;
    }

    /** The original FileAdder still creates the media row and emits its original package events. */
    public function copy(string $source,Media $media,?string $type,?string $target): void
    {
        abort_unless($this->depth>0&&DB::transactionLevel()>0&&$type===null&&$media->model_type===self::MODEL
            &&$media->collection_name==='product_image'&&$media->disk==='products'
            &&get_class($media)===Media::class&&$media->wasRecentlyCreated&&$this->command!==null&&$this->upload!==null&&!$this->copied
            &&in_array($media->conversions_disk,[null,'','products'],true)&&ctype_digit((string)$media->getKey())&&(int)$media->getKey()>0,409);
        $product=DB::table('resturant_products')->where('id',$media->model_id)->first();
        abort_unless($product&&(($this->allowed[(int)$product->resturant_id][(string)$product->id]??false)
            ||($this->allowed[(int)$product->resturant_id]['new']??false)),403);
        if($this->copies!==null)abort_unless((int)$media->model_id===$this->copyDestination&&$media->name===$this->copyName,409);
        $name=$target?:pathinfo($source,PATHINFO_BASENAME);abort_unless($name===$media->file_name&&$name===$this->fileName($this->upload['name']),409);
        $bytes=file_get_contents($source,false,null,0,self::MAX_FILE+1);
        abort_unless(is_string($bytes)&&strlen($bytes)>0&&strlen($bytes)<=self::MAX_FILE&&(int)$media->size===strlen($bytes),503);
        $this->image($name,(string)$media->mime_type,$bytes);$digest=hash('sha256',$bytes);
        abort_unless($media->mime_type===$this->upload['mime']&&hash_equals($this->upload['sha256'],$digest),409);
        $this->identity($media);$this->copied=true;
        $root=$this->root();$directory=$root.'/'.$media->getKey();$this->directory($directory,$root);
        $path=$directory.'/'.$name;
        if(file_exists($path)||is_link($path)){
            abort_unless(!is_link($path)&&is_file($path)&&filesize($path)===strlen($bytes)&&hash_equals($digest,hash_file('sha256',$path)),503,'مسار الصورة يحتوي على ملف مختلف؛ بيانات العملية محفوظة.');
            return;
        }
        $temporary=$directory.'/'.bin2hex(random_bytes(16)).'.part';$output=null;
        try{
            $output=fopen($temporary,'xb');abort_unless(is_resource($output),503);$this->write($output,$bytes);
            fclose($output);$output=null;@chmod($temporary,0644);
            abort_unless(hash_equals($digest,hash_file('sha256',$temporary)),503);
            abort_unless(!file_exists($path)&&!is_link($path)&&rename($temporary,$path),503,'تعذر تثبيت صورة المنتج كاملة.');
            abort_unless(is_file($path)&&!is_link($path)&&filesize($path)===strlen($bytes)&&hash_equals($digest,hash_file('sha256',$path)),503);
        }finally{if(is_resource($output))fclose($output);if(is_file($temporary))unlink($temporary);}
    }

    public function verifyFacts(array $images,int $status=409): void
    {
        $this->validateFacts($images);$root=$this->root();
        foreach($images as $image)foreach($image['files'] as $file){
            try{$actual=$this->file($file['relative'],$root);}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){abort($status,'صورة المنتج المحفوظة غير متاحة كاملة.');}
            abort_unless($actual===$file,$status,'صورة المنتج المحفوظة مختلفة عن نتيجة العملية.');
        }
    }

    public function verifyResult(array $result,array $files): void
    {
        $saved=$result['after']??($result['result']['after']??null);
        abort_unless(is_array($saved)&&isset($saved['images'])&&is_array($saved['images']),503,'نتيجة صور المنتج غير مكتملة.');
        $this->verifyFacts($saved['images'],503);
        if(!$files)return;
        [$a,$bytes]=$this->decoded($files);$matches=0;
        foreach($saved['images'] as $image){
            $row=$image['row'];if($row['collection_name']!=='product_image'||$row['file_name']!==$this->fileName($a['name']))continue;
            foreach($image['files'] as $file)if($file['relative']===$row['id'].'/'.$row['file_name']
                &&$file['mime']===$a['mime']&&$file['bytes']===strlen($bytes)&&hash_equals($a['sha256'],$file['sha256']))$matches++;
        }
        abort_unless($matches===1,503,'الصورة المرفوعة لا تطابق نتيجة العملية المحفوظة.');
    }

    private function root(): string
    {
        abort_unless(config('filesystems.disks.products.driver')==='local'
            &&config('media-library.path_generator')===DefaultPathGenerator::class
            &&config('media-library.file_namer')===DefaultFileNamer::class
            &&config('media-library.media_model')===Media::class
            &&config('media-library.custom_path_generators',[])===[]
            &&config('media-library.moves_media_on_update')===false
            &&in_array(config('media-library.prefix'),[null,''],true),409,'مسار صور المنتجات غير مدعوم في المعاينة.');
        $base=storage_path('app/public');$root=storage_path('app/public/products');
        $this->directory($root,$base);
        abort_unless($this->normalize((string)config('filesystems.disks.products.root'))===$this->normalize($root),409);
        return realpath($root);
    }

    private function normalize(string $path): string {return rtrim(str_replace('\\','/',$path),'/');}
    private function insideDirectory(string $directory,string $base): void
    {
        $resolved=realpath($directory);$root=realpath($base);
        abort_unless($resolved&&$root&&is_dir($resolved)&&!is_link($directory)
            &&($this->normalize($resolved)===$this->normalize($root)||str_starts_with($this->normalize($resolved),$this->normalize($root).'/')),409);
    }
    private function directory(string $directory,string $base): void
    {
        // Only fixed private upload/public products roots and numeric media directories.
        abort_unless(!is_link($base)&&!is_link($directory),409);
        $mode=str_starts_with($this->normalize($directory),$this->normalize(storage_path('app/public')).'/')?0755:0700;
        if(!is_dir($base))abort_unless(mkdir($base,$mode,true)||is_dir($base),503);
        if(!is_dir($directory))abort_unless(mkdir($directory,$mode,true)||is_dir($directory),503);
        $this->insideDirectory($directory,$base);
    }
    private function file(string $relative,string $root): array
    {
        abort_unless(DesktopDashboardMedia::safePath('products/'.$relative),409);
        $path=$root.'/'.$relative;foreach(explode('/',$relative) as $part){$root.='/'.$part;abort_if(is_link($root),409);}
        $resolved=realpath($path);abort_unless($resolved&&is_file($resolved),409);
        $bytes=filesize($resolved);$mime=DesktopDashboardMedia::MIMES[strtolower(pathinfo($relative,PATHINFO_EXTENSION))];
        $detected=(new \finfo(FILEINFO_MIME_TYPE))->file($resolved);
        abort_unless($bytes>0&&$bytes<=DesktopDashboardMedia::MAX_FILE&&($detected===$mime
            ||($mime==='image/x-icon'&&$detected==='image/vnd.microsoft.icon')),409);
        $sha=hash_file('sha256',$resolved);abort_unless(is_string($sha),409);
        return ['disk'=>'products','relative'=>$relative,'path'=>'products/'.$relative,'bytes'=>$bytes,'sha256'=>$sha,'mime'=>$mime];
    }
    private function fileName(string $name): string {return str_replace(['#','/','\\',' '],'-',$name);}

    /** FileAdder has inserted this new authorized row; do not install process-global listeners. */
    private function identity(Media $media): void
    {
        $namespace=hex2bin(str_replace('-','',$this->command));
        abort_unless(is_string($namespace)&&strlen($namespace)===16,422);
        $bytes=substr(sha1($namespace.$this->slot,true),0,16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x50);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);$uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
        $values=['uuid'=>$uuid];if($this->replayMediaTimes!==null)$values+=$this->replayMediaTimes;
        $row=DB::table('media')->where('id',$media->getKey())->lockForUpdate()->first();
        abort_unless($row&&$row->model_type===self::MODEL&&(int)$row->model_id===(int)$media->model_id
            &&$row->collection_name==='product_image'&&$row->disk==='products'&&$row->file_name===$media->file_name,409);
        DB::table('media')->where('id',$media->getKey())->update($values);
        foreach($values as $key=>$value)$media->setAttribute($key,$value);
        $media->syncOriginalAttributes(array_keys($values));
    }
    private function write($output,string $bytes): void
    {
        $offset=0;while($offset<strlen($bytes)){$written=fwrite($output,substr($bytes,$offset));abort_unless(is_int($written)&&$written>0,503);$offset+=$written;}
        abort_unless(fflush($output)&&fsync($output),503,'تعذر تثبيت صورة المنتج كاملة.');
    }
}
