<?php
namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Schema,Validator};

/**
 * National ID photos (front and back) on the private disk.
 * Only the owner/admin uploads; anyone who can open the branch's employees can view.
 */
class EmployeeIdentity
{
    public const SIDES=['front','back'];
    public const MIMES=['image/jpeg'=>'jpg','image/png'=>'png'];
    public const MAX_BYTES=5*1024*1024;
    // Uses the existing private-media transport prefix. The desktop application
    // and runtime still need the reviewed source version before these routes exist.
    public const PREFIX='branch-expenses/employee-identity/';
    public const COLUMNS=['id_front_path','id_front_hash','id_front_mime','id_back_path','id_back_hash','id_back_mime',
        'regulation_version','regulation_accepted_at','regulation_accepted_by'];
    private const PRIVATE_FIELDS=['id_front_path','id_front_hash','id_front_mime','id_back_path','id_back_hash','id_back_mime','regulation_accepted_by'];

    public function __construct(private BranchOperations $ops,private BranchPayroll $payroll) {}

    public static function ready(): bool
    {
        return Schema::hasColumns('branch_employees',self::COLUMNS);
    }

    /** A listing row without storage paths, with photo and regulation status instead. */
    public static function present(array $employee): array
    {
        $identity=[];foreach(self::SIDES as $side)$identity[$side]=!empty($employee['id_'.$side.'_path']);
        $employee['identity']=$identity;
        $employee['regulation']=EmployeeRegulation::status($employee);
        return array_diff_key($employee,array_flip(self::PRIVATE_FIELDS));
    }

    public static function validPath($path): bool
    {
        return is_string($path)&&str_starts_with($path,self::PREFIX)&&!str_contains($path,'..')&&!str_contains($path,'\\')
            &&preg_match('#^branch-expenses/employee-identity/[1-9][0-9]{0,18}/(?:front|back)-[a-f0-9]{64}\.(?:jpg|png)$#D',$path)===1;
    }

    private function rules(): array
    {
        return ['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'employee_id'=>'required|integer|min:1','side'=>'required|in:front,back'];
    }

    private static function inside(string $file,string $root): bool
    {
        $file=str_replace('\\','/',$file);$root=rtrim(str_replace('\\','/',$root),'/').'/';
        if(DIRECTORY_SEPARATOR==='\\'){$file=strtolower($file);$root=strtolower($root);}
        return str_starts_with($file,$root);
    }

    /** A private local path, never a public disk or a symlink out of private storage. */
    private function file(string $relative,bool $create=false): string
    {
        abort_unless(self::validPath($relative),409,'مسار صورة البطاقة غير صالح.');
        $app=realpath(storage_path('app'));$disk=realpath((string)config('filesystems.disks.local.root'));
        $public=realpath(storage_path('app/public'));
        abort_unless(config('filesystems.disks.local.driver')==='local'&&$app&&$disk===$app,409,'تخزين صور البطاقة الخاص غير متاح.');
        $directory=$app;
        foreach(explode('/',dirname($relative)) as $part){
            $next=$directory.DIRECTORY_SEPARATOR.$part;
            if($create&&!file_exists($next)&&!is_link($next))@mkdir($next,0700);
            $resolved=realpath($next);
            abort_unless(!is_link($next)&&$resolved&&is_dir($resolved)&&self::inside($resolved,$directory)
                &&(!$public||($resolved!==$public&&!self::inside($resolved,$public))),409,'تخزين صور البطاقة الخاص غير متاح.');
            $directory=$resolved;
        }
        $file=$directory.DIRECTORY_SEPARATOR.basename($relative);
        abort_if(is_link($file),409,'تخزين صور البطاقة الخاص غير متاح.');
        if(file_exists($file)){
            $resolved=realpath($file);
            abort_unless($resolved&&is_file($resolved)&&self::inside($resolved,$directory),409,'تخزين صور البطاقة الخاص غير متاح.');
        }
        return $file;
    }

    public function upload(array $values,$file,$actor): array
    {
        abort_unless(self::ready(),503,'يجب تثبيت تحديث بطاقة الموظف أولًا.');
        abort_unless($this->payroll->canManageEmployees($actor),403,'إضافة صور البطاقة متاحة للأونر والأدمن فقط.');
        $v=Validator::make($values+['photo'=>$file],$this->rules()+['photo'=>'required|file|mimes:jpg,jpeg,png|max:5120'])->validate();
        abort_unless($file instanceof UploadedFile&&$file->isValid(),422,'صورة البطاقة غير صالحة.');
        $this->ops->branches($v['branch'],$actor);
        $mime=$file->getMimeType();$extension=self::MIMES[$mime]??null;
        abort_unless($extension,422,'صورة البطاقة لازم تكون JPG أو PNG.');
        $digest=@hash_file('sha256',$file->getRealPath());
        abort_unless(is_string($digest),503,'تعذّر قراءة صورة البطاقة.');
        $side=$v['side'];$relative=self::PREFIX.(int)$v['employee_id'].'/'.$side.'-'.$digest.'.'.$extension;
        DB::transaction(function()use($v,$side,$file,$digest,$relative,$mime){
            $row=DB::table('branch_employees')->where('branch',$v['branch'])->where('id',(int)$v['employee_id'])->lockForUpdate()->first();
            abort_unless($row,404);
            $target=$this->file($relative,true);
            if(!is_file($target)){
                // Exclusive creation keeps content-addressed files immutable. Only this
                // employee's locked upload can publish the new reference below.
                $out=@fopen($target,'x+b');
                abort_unless(is_resource($out),503,'تعذّر حفظ صورة البطاقة.');
                $in=false;$complete=false;
                try{
                    abort_unless(@chmod($target,0600),503,'تعذّر تأمين صورة البطاقة.');$in=@fopen($file->getRealPath(),'rb');
                    abort_unless(is_resource($in),503,'تعذّر قراءة صورة البطاقة.');
                    $bytes=stream_copy_to_stream($in,$out,self::MAX_BYTES+1);
                    abort_unless(is_int($bytes)&&$bytes>0&&$bytes<=self::MAX_BYTES&&fflush($out),503,'تعذّر حفظ صورة البطاقة.');
                    rewind($out);$context=hash_init('sha256');hash_update_stream($context,$out);
                    abort_unless(hash_equals($digest,hash_final($context)),503,'صورة البطاقة المحفوظة غير مكتملة.');
                    $complete=true;
                }finally{
                    if(is_resource($in))fclose($in);fclose($out);
                    if(!$complete)@unlink($target);
                }
            }
            abort_unless(hash_equals($digest,(string)@hash_file('sha256',$target)),503,'صورة البطاقة المحفوظة غير مكتملة.');
            // Photos are separate from the employee's editable data: no revision change,
            // so an open edit form or a device's pending employee edit stays valid.
            DB::table('branch_employees')->where('id',$row->id)->update(['id_'.$side.'_path'=>$relative,'id_'.$side.'_hash'=>$digest,'id_'.$side.'_mime'=>$mime]);
        });
        // Retain replaced immutable files. Deleting after releasing the row lock can
        // race a later A→B→A upload and remove the photo that is current again.
        return ['success'=>true,'identity'=>self::present((array)DB::table('branch_employees')->where('id',(int)$v['employee_id'])->first())['identity']];
    }

    /** The stored photo for one side, for anyone allowed to open this branch's employees. */
    public function image(array $values,$actor): array
    {
        abort_unless(self::ready(),404);
        $v=Validator::make($values,$this->rules())->validate();
        $this->ops->branches($v['branch'],$actor);
        $row=DB::table('branch_employees')->where('branch',$v['branch'])->where('id',(int)$v['employee_id'])->first();
        abort_unless($row,404);
        $path=$row->{'id_'.$v['side'].'_path'};$mime=$row->{'id_'.$v['side'].'_mime'};$hash=$row->{'id_'.$v['side'].'_hash'};
        abort_unless(self::validPath($path)&&isset(self::MIMES[$mime]),404,'صورة البطاقة لم تُرفع بعد.');
        $file=$this->file($path);
        abort_unless(is_file($file),404,'صورة البطاقة غير متاحة على هذا الجهاز بعد.');
        // Serve the exact verified bytes, not a pathname reopened later by the response.
        $in=@fopen($file,'rb');$stream=fopen('php://temp/maxmemory:1048576','w+b');$complete=false;
        try{
            abort_unless(is_resource($in)&&is_resource($stream),409,'صورة البطاقة غير متاحة.');
            $bytes=stream_copy_to_stream($in,$stream,self::MAX_BYTES+1);
            abort_unless(is_int($bytes)&&$bytes>0&&$bytes<=self::MAX_BYTES,409,'صورة البطاقة غير متاحة.');
            rewind($stream);$context=hash_init('sha256');hash_update_stream($context,$stream);
            abort_unless(is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash)===1
                &&hash_equals($hash,hash_final($context)),409,'صورة البطاقة المحفوظة لا تطابق سجلها.');
            rewind($stream);$complete=true;
            return ['stream'=>$stream,'bytes'=>$bytes,'mime'=>$mime];
        }finally{
            if(is_resource($in))fclose($in);
            if(!$complete&&is_resource($stream))fclose($stream);
        }
    }
}
