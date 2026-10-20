<?php
namespace App\Services\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};

/** Operation-owned append-only settings images; old bytes remain available after rollback/replay. */
final class DesktopDashboardSettingsImages
{
    private const MIMES=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    private int $depth=0;
    private array $written=[];
    private bool $rollbackKnown=false;
    private ?array $expected=null;
    public function guard(callable $work)
    {
        if($this->depth){$this->depth++;try{return $work();}finally{$this->depth--;}}
        $app=app();$old=$app->bound(self::class)?$app->make(self::class):null;$app->instance(self::class,$this);$this->depth=1;$this->written=[];$this->rollbackKnown=false;
        try{return $work();}
        catch(\Throwable $e){if($this->rollbackKnown)foreach(array_reverse($this->written) as $path){if(is_file($path)&&!is_link($path))unlink($path);}throw $e;}
        finally{$this->depth=0;$this->expected=null;$this->written=[];$app->forgetInstance(self::class);if($old)$app->instance(self::class,$old);}
    }
    /** Must be called inside the root callback catch, before any COMMIT attempt. */
    public function rolledBack(): void {$this->rollbackKnown=true;}
    private function image(string $bytes): array
    {
        abort_unless(strlen($bytes)>0&&strlen($bytes)<=5*1024*1024,422);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$dimensions=@getimagesizefromstring($bytes);
        abort_unless(isset(self::MIMES[$mime])&&is_array($dimensions)&&$dimensions[0]>0&&$dimensions[1]>0&&$dimensions[0]<=4096&&$dimensions[1]<=4096&&($dimensions['mime']??null)===$mime,422,'صورة الإعدادات غير صالحة أو أكبر من الحد المسموح.');
        return ['mime'=>$mime,'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];
    }
    private function originalName($name): string
    {abort_unless(is_string($name)&&$name!==''&&strlen($name)<=255&&!preg_match('/[\\\\\/\x00-\x1f\x7f]/',$name),422);return $name;}
    public function files(string $route,Request $request): array
    {
        $slots=$route==='updateSetting'?['logo','favicon']:['advertise_image'];abort_if(array_diff(array_keys($request->allFiles()),$slots),422);$files=[];$total=0;
        foreach($slots as $slot){$file=$request->file($slot);if(!$file)continue;abort_unless($file instanceof \Illuminate\Http\UploadedFile&&$file->isValid(),422);
            $bytes=file_get_contents($file->getRealPath(),false,null,0,5*1024*1024+1);abort_unless(is_string($bytes),422);$files[$slot]=['name'=>$this->originalName($file->getClientOriginalName())]+$this->image($bytes)+['base64'=>base64_encode($bytes)];$total+=strlen($bytes);abort_if($total>5*1024*1024,413,'مجموع صور الإعدادات يتجاوز 5 ميجابايت.');}
        return $files;
    }
    public function decoded(string $route,array $files): array
    {
        $slots=$route==='updateSetting'?['logo','favicon']:['advertise_image'];abort_if(array_diff(array_keys($files),$slots),422);$decoded=[];$total=0;
        foreach($files as $slot=>$file){abort_unless(is_array($file)&&array_keys($file)===['name','mime','sha256','bytes','base64']&&is_string($file['base64'])&&strlen($file['base64'])<=6990508,422);
            $this->originalName($file['name']);$bytes=base64_decode($file['base64'],true);abort_unless(is_string($bytes)&&base64_encode($bytes)===$file['base64']&&array_intersect_key($file,array_flip(['mime','sha256','bytes']))===$this->image($bytes),422);$total+=strlen($bytes);abort_if($total>5*1024*1024,413);$decoded[$slot]=[$file,$bytes];}
        return $decoded;
    }
    private function full(string $relative): string
    {
        abort_unless(DesktopDashboardMedia::safePath($relative)&&str_starts_with($relative,'uploadedImages/')&&!str_contains(substr($relative,strlen('uploadedImages/')),'/')
            &&config('filesystems.default')==='local'&&config('filesystems.disks.local.driver')==='local',409,'مسار صورة الإعدادات غير مدعوم.');
        $root=storage_path('app');abort_unless(!is_link($root)&&!is_link(storage_path('app/public')),409);if(!is_dir(storage_path('app/public')))abort_unless(mkdir(storage_path('app/public'),0755,true)||is_dir(storage_path('app/public')),503);
        $configured=realpath((string)config('filesystems.disks.local.root'));abort_unless($configured===realpath($root),409);
        $directory=storage_path('app/public/uploadedImages');abort_if(is_link($directory),409);if(!is_dir($directory))abort_unless(mkdir($directory,0755,true)||is_dir($directory),503);
        abort_unless(realpath(dirname($directory))===realpath(storage_path('app/public')),409);
        $full=$directory.'/'.substr($relative,strlen('uploadedImages/'));abort_if(is_link($full),409);return $full;
    }
    public function proof(string $path): array
    {
        $relative=DesktopDashboardMedia::settingsPath($path);abort_unless($relative!==null,409,'مسار صورة الإعدادات غير مدعوم.');$full=$this->full($relative);abort_unless(is_file($full)&&filesize($full)>0&&filesize($full)<=5*1024*1024,409,'صورة الإعدادات القديمة غير متاحة للتحقق.');
        $bytes=file_get_contents($full);abort_unless(is_string($bytes),409);try{$proof=$this->image($bytes);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){abort(409,'صورة الإعدادات القديمة تحتاج مراجعة.');}
        abort_unless((DesktopDashboardMedia::MIMES[strtolower(pathinfo($relative,PATHINFO_EXTENSION))]??null)===$proof['mime'],409);
        return ['path'=>$path]+$proof;
    }
    public function before(array $state,array $files): array
    {
        $proofs=[];foreach($files as $slot=>$file){$path=$state[$slot]['value']??null;abort_unless(is_string($path),409);$proofs[$slot]=in_array($path,['','0'],true)?['path'=>$path,'empty'=>true]:$this->proof($path);}return $proofs;
    }
    public function verifyBefore(string $route,array $facts,array $files): void
    {
        $this->decoded($route,$files);abort_unless(array_keys($facts)===array_keys($files),409);
        foreach($facts as $slot=>$proof){abort_unless(is_array($proof)&&is_string($proof['path']??null),409);$actual=in_array($proof['path'],['','0'],true)?['path'=>$proof['path'],'empty'=>true]:$this->proof($proof['path']);abort_unless($actual===$proof,409,'صورة الإعدادات تغيّرت؛ العملية المحلية محفوظة للمراجعة.');}
    }
    private function destination(string $command,string $slot,array $file): string
    {Validator::make(['command'=>$command],['command'=>'required|uuid'])->validate();return 'uploadedImages/desktop-settings-'.strtolower($command).'-'.$slot.'-'.substr($file['sha256'],0,20).'.'.self::MIMES[$file['mime']];}
    public function bind(string $route,Request $request,string $command,array $files,callable $work)
    {
        $decoded=$this->decoded($route,$files);if(!$decoded)return $work();abort_unless($this->depth>0&&DB::transactionLevel()>0&&$this->expected===null,409);
        $old=[];$temps=[];$converted=(function(){return $this->convertedFiles;})->call($request);$this->expected=[];
        try{foreach($decoded as $slot=>[$file,$bytes]){$temp=tempnam(sys_get_temp_dir(),'desktop-settings-image-');abort_unless($temp!==false,503);$temps[]=$temp;abort_unless(file_put_contents($temp,$bytes)===strlen($bytes),503);$old[$slot]=$request->files->get($slot);$request->files->set($slot,new DesktopDashboardSettingsUpload($temp,$file['name'],$file['mime'],$slot,$command));$this->expected[$slot]=['path'=>$this->destination($command,$slot,$file),'file'=>$file,'stored'=>false];}
            (function(){$this->convertedFiles=null;})->call($request);$result=$work();foreach($this->expected as $entry)abort_unless($entry['stored'],409);return $result;
        }finally{foreach($old as $slot=>$file){if($file)$request->files->set($slot,$file);else $request->files->remove($slot);}(function($v){$this->convertedFiles=$v;})->call($request,$converted);foreach($temps as $temp)if(is_file($temp))unlink($temp);$this->expected=null;}
    }
    public function store(DesktopDashboardSettingsUpload $upload,$path,$name,$options): string
    {
        $slot=$upload->desktopSlot;$entry=$this->expected[$slot]??null;
        abort_unless($this->depth>0&&DB::transactionLevel()>0&&$entry&&!$entry['stored']&&$path==='/public/uploadedImages'&&$options===[]
            &&is_string($name)&&preg_match('/^settings-[0-9]+\\./D',$name),409);
        $full=$this->full($entry['path']);abort_if(file_exists($full),409,'صورة العملية موجودة مسبقًا؛ راجع نتيجتها المحفوظة.');
        $bytes=file_get_contents($upload->getRealPath());abort_unless(is_string($bytes)&&$this->image($bytes)===array_intersect_key($entry['file'],array_flip(['mime','sha256','bytes'])),409);
        $output=null;
        try{$output=fopen($full,'xb');abort_unless(is_resource($output),503);$this->written[]=$full;$offset=0;while($offset<strlen($bytes)){$n=fwrite($output,substr($bytes,$offset));abort_unless(is_int($n)&&$n>0,503);$offset+=$n;}abort_unless(fflush($output)&&fsync($output),503);fclose($output);$output=null;@chmod($full,0644);$this->expected[$slot]['stored']=true;abort_unless($this->proof($entry['path'])===['path'=>$entry['path']]+$this->image($bytes),503);return 'public/'.$entry['path'];}
        finally{if(is_resource($output))fclose($output);}
    }
    public function result(string $route,array $state,array $files,string $command): array
    {
        $after=[];foreach($this->decoded($route,$files) as $slot=>[$file,$bytes]){$path=$this->destination($command,$slot,$file);abort_unless(($state[$slot]['value']??null)===$path,409);$after[$slot]=$this->proof($path);abort_unless(array_intersect_key($after[$slot],array_flip(['mime','sha256','bytes']))===$this->image($bytes),409);}return $after;
    }
    public function verifyResult(string $route,array $result,array $files,string $command): void
    {abort_unless(is_array($result['after']??null)&&is_array($result['after_images']??null),503);abort_unless($result['after_images']===$this->result($route,$result['after'],$files,$command),503);}
}
