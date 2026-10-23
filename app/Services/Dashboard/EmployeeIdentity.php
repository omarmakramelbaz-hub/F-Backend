<?php
namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Schema,Storage,Validator};

/**
 * National ID photos (front and back) on the private disk.
 * Only the owner/admin uploads; anyone who can open the branch's employees can view.
 */
class EmployeeIdentity
{
    public const SIDES=['front','back'];
    public const MIMES=['image/jpeg'=>'jpg','image/png'=>'png'];
    // Inside the private folder that every device version already syncs, so ID photos reach
    // devices without a desktop program change and in any update order.
    public const PREFIX='branch-expenses/employee-identity/';
    private const PRIVATE_FIELDS=['id_front_path','id_front_hash','id_front_mime','id_back_path','id_back_hash','id_back_mime','regulation_accepted_by'];

    public function __construct(private BranchOperations $ops,private BranchPayroll $payroll) {}

    public static function ready(): bool
    {
        return Schema::hasColumns('branch_employees',['id_front_path','id_back_path','regulation_version','regulation_accepted_at']);
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

    public function upload(array $values,$file,$actor): array
    {
        abort_unless(self::ready(),503,'يجب تثبيت تحديث بطاقة الموظف أولًا.');
        abort_unless($this->payroll->canManageEmployees($actor),403,'إضافة صور البطاقة متاحة للأونر والأدمن فقط.');
        $v=Validator::make($values+['photo'=>$file],$this->rules()+['photo'=>'required|file|mimes:jpg,jpeg,png|max:5120'])->validate();
        abort_unless($file instanceof UploadedFile&&$file->isValid(),422,'صورة البطاقة غير صالحة.');
        $this->ops->branches($v['branch'],$actor);
        $mime=$file->getMimeType();$extension=self::MIMES[$mime]??null;
        abort_unless($extension,422,'صورة البطاقة لازم تكون JPG أو PNG.');
        $digest=hash_file('sha256',$file->getRealPath());
        abort_unless(is_string($digest),503,'تعذّر قراءة صورة البطاقة.');
        $side=$v['side'];$relative=self::PREFIX.(int)$v['employee_id'].'/'.$side.'-'.$digest.'.'.$extension;
        $disk=Storage::disk('local');
        $old=DB::transaction(function()use($v,$side,$file,$digest,$relative,$mime,$disk){
            $row=DB::table('branch_employees')->where('branch',$v['branch'])->where('id',(int)$v['employee_id'])->lockForUpdate()->first();
            abort_unless($row,404);
            if(!is_file($disk->path($relative))){
                abort_unless($disk->putFileAs(dirname($relative),$file,basename($relative))!==false,503,'تعذّر حفظ صورة البطاقة.');
                @chmod($disk->path($relative),0600);
            }
            abort_unless(hash_equals($digest,(string)hash_file('sha256',$disk->path($relative))),503,'صورة البطاقة المحفوظة غير مكتملة.');
            $previous=$row->{'id_'.$side.'_path'};
            // Photos are separate from the employee's editable data: no revision change,
            // so an open edit form or a device's pending employee edit stays valid.
            DB::table('branch_employees')->where('id',$row->id)->update(['id_'.$side.'_path'=>$relative,'id_'.$side.'_hash'=>$digest,'id_'.$side.'_mime'=>$mime]);
            return $previous;
        });
        if(self::validPath($old)&&$old!==$relative&&!DB::table('branch_employees')->where('id_front_path',$old)->orWhere('id_back_path',$old)->exists())
            $disk->delete($old);
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
        $file=Storage::disk('local')->path($path);
        abort_unless(is_file($file),404,'صورة البطاقة غير متاحة على هذا الجهاز بعد.');
        abort_unless(is_string($hash)&&hash_equals($hash,(string)hash_file('sha256',$file)),409,'صورة البطاقة المحفوظة لا تطابق سجلها.');
        return ['path'=>$file,'mime'=>$mime];
    }
}
