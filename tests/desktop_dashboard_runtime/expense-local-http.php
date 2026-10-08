<?php
// The original offline expense page/controller, session, actual upload parser and encrypted journal.
require __DIR__.'/multipart.php';
$localExpenseForm=['_token'=>$csrf[1],'branch'=>'f:100','idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),
    'occurred_on'=>now('Africa/Cairo')->toDateString(),'category'=>'purchases','description'=>'مرفق من شاشة الفرع المحلية',
    'amount'=>'6.00','payment_method'=>'cash'];
DB::statement("CREATE TRIGGER reject_local_upload_journal BEFORE INSERT ON desktop_dashboard_commands FOR EACH ROW BEGIN IF NEW.route_name='branch-expenses.save' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated local HTTP upload journal failure'; END IF; END");
try{
    verify($multipart($localExpenseForm,['X-Fasakhansta-Desktop: '.$browserToken],$expensePdfBytes)[0]===500
        &&!DB::table('branch_expenses')->where('description',$localExpenseForm['description'])->exists(),
        'the actual offline multipart controller rolls its expense back when journal insertion fails');
}finally{DB::statement('DROP TRIGGER reject_local_upload_journal');}
[$localUploadStatus,$localUploadBody]=$multipart($localExpenseForm,['X-Fasakhansta-Desktop: '.$browserToken],$expensePdfBytes);
$localUpload=json_decode($localUploadBody,true);$localUploadId=$localUpload['expense']['id']??null;
verify($localUploadStatus===200&&$localUploadId&&DB::table('desktop_dashboard_commands')->where('command_id',$localExpenseForm['idempotency_key'])->count()===1,
    'the original offline expense upload commits one parsed multipart file and encrypted operation');
verify($multipart($localExpenseForm,['X-Fasakhansta-Desktop: '.$browserToken],$expensePdfBytes)[1]===$localUploadBody
    &&DB::table('branch_expenses')->where('description',$localExpenseForm['description'])->count()===1,
    'the actual offline expense form reuses its saved response after a lost reply');
$uploadCipher=DB::table('desktop_dashboard_commands')->where('command_id',$localExpenseForm['idempotency_key'])->value('command_cipher');
$uploadPayload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($uploadCipher),true);
verify(!isset($uploadPayload['values']['attachment'])&&$uploadPayload['files']['attachment']['base64']===base64_encode($expensePdfBytes)
    &&$uploadPayload['files']['attachment']['name']==='فاتورة المورد.pdf',
    'the actual local middleware journals complete original bytes without temporary UploadedFile objects in its values');
[$localAttachmentStatus,$localAttachmentBody]=$http('/admin/branch-expenses/'.$localUploadId.'/attachment');
verify($localAttachmentStatus===200&&$localAttachmentBody===$expensePdfBytes,
    'the original offline expense download retrieves the newly uploaded private file');
$localUploadPath=DB::table('branch_expenses')->where('id',$localUploadId)->value('attachment_path');
verify($http('/storage/'.$localUploadPath)[0]===404,'the actual offline upload does not create a public copy of its private attachment');
$wrongBranch=$localExpenseForm;$wrongBranch['branch']='f:101';$wrongBranch['idempotency_key']=(string)\Illuminate\Support\Str::uuid();
verify($multipart($wrongBranch,['X-Fasakhansta-Desktop: '.$browserToken],$expensePdfBytes)[0]===404
    &&!DB::table('desktop_dashboard_commands')->where('command_id',$wrongBranch['idempotency_key'])->exists(),
    'the actual offline multipart expense cannot bypass the enrolled original branch scope');
