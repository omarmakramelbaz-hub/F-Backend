<?php
namespace App\Services\Dashboard;

use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Storage,Validator};

/** Private expense bytes are durable before an enclosing dashboard transaction commits. */
class DesktopDashboardExpenseAttachments
{
    public function files(Request $request,string $route): array
    {
        $files=$request->allFiles();
        if(!$files)return [];
        abort_unless($route==='branch-expenses.save'&&array_keys($files)===['attachment'],501,'مرفقات هذا القسم لم تُجهّز للمزامنة بعد.');
        $file=$files['attachment'];
        abort_unless($file instanceof UploadedFile&&$file->isValid(),422,'المرفق غير صالح.');
        Validator::make(['attachment'=>$file],['attachment'=>'required|file|mimes:jpg,jpeg,png,pdf|max:5120'])->validate();
        $bytes=file_get_contents($file->getRealPath());
        abort_unless($bytes!==false&&strlen($bytes)===$file->getSize(),503,'تعذّر قراءة المرفق كاملًا.');
        return ['attachment'=>['name'=>$file->getClientOriginalName(),'mime'=>$file->getMimeType(),
            'sha256'=>hash('sha256',$bytes),'base64'=>base64_encode($bytes)]];
    }

    public function decoded(array $files): array
    {
        abort_unless(array_keys($files)===['attachment'],422,'المرفق غير صالح.');
        $a=$files['attachment'];
        Validator::make($a,['name'=>'required|string|max:1000','mime'=>'required|string|max:100',
            'sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],'base64'=>'required|string|max:6990508'])->validate();
        abort_if(array_diff(array_keys($a),['name','mime','sha256','base64']),422);
        $bytes=base64_decode($a['base64'],true);
        abort_unless($bytes!==false&&strlen($bytes)>0&&strlen($bytes)<=5*1024*1024
            &&hash_equals($a['sha256'],hash('sha256',$bytes)),422,'المرفق غير مكتمل.');
        return [$a,$bytes];
    }

    public function store(UploadedFile $file,array $values,int $actor): string
    {
        $digest=hash_file('sha256',$file->getRealPath());
        $extension=match($file->getMimeType()){'application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg',default=>null};
        abort_unless($extension&&is_string($digest),422,'نوع المرفق غير صالح.');
        $identity=hash('sha256',json_encode([$actor,$values['branch'],$values['idempotency_key'],$digest],JSON_THROW_ON_ERROR));
        $relative='branch-expenses/desktop/'.$identity.'.'.$extension;
        $disk=Storage::disk('local');$path=$disk->path($relative);
        if(is_file($path)){
            abort_unless(hash_equals($digest,hash_file('sha256',$path)),503,'الملف المحفوظ مختلف؛ بيانات العملية محفوظة.');
            return $relative;
        }
        abort_unless($disk->makeDirectory('branch-expenses/desktop'),503,'تعذّر تجهيز مجلد المرفق.');
        $temporary=$path.'.'.bin2hex(random_bytes(16)).'.part';
        $input=null;$output=null;
        try{
            $input=fopen($file->getRealPath(),'rb');$output=fopen($temporary,'xb');
            abort_unless(is_resource($input)&&is_resource($output),503,'تعذّر حفظ المرفق.');
            $copied=stream_copy_to_stream($input,$output);
            abort_unless($copied===$file->getSize()&&fflush($output)&&fsync($output),503,'تعذّر تثبيت المرفق كاملًا.');
            fclose($input);$input=null;fclose($output);$output=null;
            abort_unless(hash_equals($digest,hash_file('sha256',$temporary)),503,'المرفق المحفوظ غير مكتمل.');
            // The account/device write lock serializes retries. A concurrently existing
            // destination is acceptable only if it contains the identical immutable bytes.
            if(!@rename($temporary,$path))abort_unless(is_file($path)&&hash_equals($digest,hash_file('sha256',$path)),503,'تعذّر تثبيت المرفق.');
            @chmod($path,0600);
            abort_unless(is_file($path)&&hash_equals($digest,hash_file('sha256',$path)),503,'تعذّر التحقق من المرفق المحفوظ.');
            return $relative;
        }finally{
            if(is_resource($input))fclose($input);
            if(is_resource($output))fclose($output);
            if(is_file($temporary))unlink($temporary);
        }
    }

    public function verifyResult(array $result,array $files): void
    {
        if(!$files)return;
        [$attachment,$bytes]=$this->decoded($files);
        $expense=$result['expense']??($result['result']['expense']??null);
        abort_unless(is_array($expense)&&is_numeric($expense['id']??null),503,'نتيجة المرفق غير مكتملة.');
        $row=DB::table('branch_expenses')->where('id',(int)$expense['id'])->first();
        abort_unless($row&&is_string($row->attachment_path)&&str_starts_with($row->attachment_path,'branch-expenses/')
            &&!str_contains($row->attachment_path,'..')&&hash_equals($row->attachment_hash??'',$attachment['sha256'])
            &&($row->attachment_mime??null)===$attachment['mime'],503,'مرجع المرفق المحفوظ غير مكتمل.');
        $path=Storage::disk('local')->path($row->attachment_path);
        abort_unless(is_file($path)&&filesize($path)===strlen($bytes)&&hash_equals($attachment['sha256'],hash_file('sha256',$path)),
            503,'المرفق المحفوظ غير متاح كاملًا؛ سجل العملية محفوظ للاسترجاع.');
    }
}
