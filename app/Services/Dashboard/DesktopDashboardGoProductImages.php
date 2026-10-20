<?php
namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage,Validator};

/** GO public product images are append-only; rollback removes only bytes created by this command. */
class DesktopDashboardGoProductImages
{
    private const MIMES=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    private int $depth=0;
    private array $written=[];
    private bool $rolledBack=false;
    public function guard(callable $work)
    {
        if($this->depth){$this->depth++;try{return $work();}finally{$this->depth--;}}
        $app=app();$old=$app->bound(self::class)?$app->make(self::class):null;$app->instance(self::class,$this);$this->depth=1;$this->written=[];$this->rolledBack=false;
        try{return $work();}
        catch(\Throwable $error){if($this->rolledBack)foreach(array_reverse($this->written) as $path)if(is_file($path)&&!is_link($path))unlink($path);throw $error;}
        finally{$this->depth=0;$this->written=[];$app->forgetInstance(self::class);if($old)$app->instance(self::class,$old);}
    }
    private function image(string $bytes): array
    {
        abort_unless(strlen($bytes)>0&&strlen($bytes)<=5*1024*1024,422);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$size=@getimagesizefromstring($bytes);
        abort_unless(isset(self::MIMES[$mime])&&is_array($size)&&$size[0]>0&&$size[1]>0&&$size[0]<=4096&&$size[1]<=4096&&($size['mime']??null)===$mime,422,'صورة المنتج غير صالحة.');
        return ['mime'=>$mime,'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];
    }
    /** Called inside the DB callback, before its root commit is attempted. */
    public function rollbackKnown(): void {$this->rolledBack=true;}
    public function transaction(callable $work)
    {return $this->guard(fn()=>DB::transaction(function()use($work){try{return $work();}catch(\Throwable $e){$this->rollbackKnown();throw $e;}}));}
    public function files(Request $request): array
    {
        abort_if(array_diff(array_keys($request->allFiles()),['image']),501);if(!$request->file('image'))return [];$file=$request->file('image');
        abort_unless($file instanceof \Illuminate\Http\UploadedFile&&$file->isValid(),422);$bytes=file_get_contents($file->getRealPath());abort_unless(is_string($bytes),422);
        $name=$file->getClientOriginalName();abort_unless(is_string($name)&&strlen($name)<=255&&!preg_match('/[\\\\\/\x00-\x1f\x7f]/',$name),422);
        return ['image'=>['name'=>$name]+$this->image($bytes)+['base64'=>base64_encode($bytes)]];
    }
    public function decoded(array $files): ?array
    {
        if($files===[])return null;abort_unless(array_keys($files)===['image']&&is_array($files['image'])&&array_keys($files['image'])===['name','mime','sha256','bytes','base64'],422);
        $value=$files['image'];abort_unless(is_string($value['name'])&&strlen($value['name'])<=255&&!preg_match('/[\\\\\/\x00-\x1f\x7f]/',$value['name'])&&is_string($value['base64'])&&strlen($value['base64'])<=6990508,422);
        $bytes=base64_decode($value['base64'],true);abort_unless(is_string($bytes)&&base64_encode($bytes)===$value['base64']&&array_intersect_key($value,array_flip(['mime','sha256','bytes']))===$this->image($bytes),422);
        return [$value,$bytes];
    }
    private function path(string $path,int $owner): string
    {
        abort_unless($owner>0&&preg_match('#^go-stores/'.preg_quote((string)$owner,'#').'/[a-zA-Z0-9_.-]{1,220}$#D',$path)
            &&!str_contains($path,'..')&&(config('filesystems.disks.public.driver')??null)==='local',409,'مسار صورة المتجر غير مدعوم.');
        $root=Storage::disk('public')->path('');abort_unless(is_dir($root)&&!is_link(rtrim($root,'/\\')),409);$base=realpath($root);abort_unless(is_string($base),409);
        $cursor=$base;foreach(explode('/',$path) as $part){$cursor.=DIRECTORY_SEPARATOR.$part;abort_if(is_link($cursor),409,'روابط الصور الرمزية غير مدعومة.');}
        return $cursor;
    }
    public function proof(string $path,int $owner): array
    {
        $full=$this->path($path,$owner);abort_unless(is_file($full)&&filesize($full)<=5*1024*1024,409,'صورة المنتج القديمة غير متاحة للتحقق.');
        $bytes=file_get_contents($full);abort_unless(is_string($bytes),409);return ['path'=>$path]+$this->image($bytes);
    }
    public function bind(Request $request,int $owner,string $command,array $files,callable $work)
    {
        $decoded=$this->decoded($files);if(!$decoded)return $work();abort_unless($this->depth>0&&DB::transactionLevel()>0,409);
        Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();[$value,$bytes]=$decoded;
        $temp=tempnam(sys_get_temp_dir(),'desktop-go-image-');abort_unless($temp!==false,503);$old=$request->files->get('image');$converted=(function(){return $this->convertedFiles;})->call($request);
        $name='desktop-'.strtolower($command).'-'.substr($value['sha256'],0,16).'.'.self::MIMES[$value['mime']];
        try{abort_unless(file_put_contents($temp,$bytes)===strlen($bytes),503);$request->files->set('image',new DesktopDashboardGoProductUpload($temp,$value['name'],$value['mime'],$name));(function(){$this->convertedFiles=null;})->call($request);return $work();}
        finally{if($old)$request->files->set('image',$old);else $request->files->remove('image');(function($value){$this->convertedFiles=$value;})->call($request,$converted);if(is_file($temp))unlink($temp);}
    }
    public function store(DesktopDashboardGoProductUpload $file,string $path,$options,callable $work)
    {
        abort_unless($this->depth>0&&DB::transactionLevel()>0&&$options==='public'&&preg_match('#^go-stores/([1-9][0-9]{0,18})$#D',$path,$match),409);
        $relative=$path.'/'.$file->hashName();$full=$this->path($relative,(int)$match[1]);abort_if(file_exists($full),409,'مسار صورة العملية موجود مسبقًا؛ يلزم مراجعة النتيجة المحفوظة.');
        // Record ownership before Laravel writes, so a partial disk failure is removed too.
        $this->written[]=$full;$stored=$work();abort_unless($stored===$relative&&is_file($full),503);return $stored;
    }
}
