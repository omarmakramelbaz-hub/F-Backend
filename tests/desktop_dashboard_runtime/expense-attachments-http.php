<?php
// Actual multipart original controller + terminal remote outcomes on the existing private HTTP fixture.
$multipart=function(array $form,array $headers,string $bytes,string $filename='فاتورة المورد.pdf')use($origin,&$cookies){
    $boundary='desktop-fixture-'.bin2hex(random_bytes(12));$body='';
    foreach($form as $key=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";
    $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"".$filename."\"\r\nContent-Type: application/pdf\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
    $headers[]='Content-Type: multipart/form-data; boundary='.$boundary;$headers[]='Accept: application/json';
    if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
    $reply=@file_get_contents($origin.'/admin/branch-expenses/save',false,$context);$replyHeaders=$http_response_header??[];
    preg_match('/^HTTP\/\S+ (\d+)/',$replyHeaders[0]??'',$status);
    foreach($replyHeaders as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];
    return [(int)($status[1]??0),$reply];
};
$pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$expenseForm=$base+['idempotency_key'=>(string)Str::uuid(),'occurred_on'=>now('Africa/Cairo')->toDateString(),
    'category'=>'purchases','description'=>'مصروف مرفق نتيجة السيرفر','amount'=>'13.00','payment_method'=>'cash','approve'=>1];
$uploadAttempt=$attempt('/admin/branch-expenses/save');[$uploadReserveStatus,$uploadProof]=$decide($uploadAttempt);
verify($uploadReserveStatus===200&&$uploadProof['status']==='ready','native devices can reserve the original multipart expense action');
$beforeUploadCash=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');
DB::statement("CREATE TRIGGER reject_upload_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.status='committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated upload outcome persistence failure'; END IF; END");
try{
    [$uploadStatus]=$multipart($expenseForm,$proof($uploadProof),$pdf);
    verify($uploadStatus===500&&!DB::table('branch_expenses')->where('description',$expenseForm['description'])->exists()
        &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$beforeUploadCash,
        'failure to persist the multipart terminal outcome rolls back both original expense and cash');
}finally{DB::statement('DROP TRIGGER reject_upload_outcome');}
[$uploadStatus,$uploadBody]=$multipart($expenseForm,$proof($uploadProof),$pdf);
$savedUpload=json_decode($uploadBody,true);$uploadRow=DB::table('branch_expenses')->where('description',$expenseForm['description'])->first();
verify($uploadStatus===200&&$uploadRow&&\Illuminate\Support\Facades\Storage::disk('local')->get($uploadRow->attachment_path)===$pdf
    &&$decide($uploadAttempt,'settle')[1]['status']==='committed','the same multipart attempt can retry after rollback and settle its complete private PDF');
verify($multipart($expenseForm,$proof($uploadProof),$pdf)[1]===$uploadBody
    &&(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')===$beforeUploadCash-1300,
    'a lost multipart response replays the exact receipt without a second drawer deduction');
$newUploadAttempt=$attempt('/admin/branch-expenses/save');[, $newUploadProof]=$decide($newUploadAttempt);
verify($multipart($expenseForm,$proof($newUploadProof),$pdf)[1]===$uploadBody
    &&DB::table('branch_expenses')->where('description',$expenseForm['description'])->count()===1,
    'a different transmission of the same upload operation cannot create a duplicate expense');
verify($multipart($expenseForm,$proof($uploadProof),$pdf.'changed')[0]===409,
    'a committed multipart capability rejects changed attachment bytes');
verify($multipart($expenseForm,$proof($uploadProof),$pdf,'اسم مختلف.pdf')[0]===409,
    'a committed multipart capability also rejects a changed original filename');
$uploadPath=\Illuminate\Support\Facades\Storage::disk('local')->path($uploadRow->attachment_path);unlink($uploadPath);
verify($multipart($expenseForm,$proof($uploadProof),$pdf)[0]===503,'a stored multipart reply cannot report an unavailable private file as a complete success');
file_put_contents($uploadPath,$pdf);
$cancelUpload=$attempt('/admin/branch-expenses/save');[, $cancelUploadProof]=$decide($cancelUpload);$decide($cancelUpload,'settle');
$lateExpense=$expenseForm;$lateExpense['idempotency_key']=(string)Str::uuid();$lateExpense['description']='رفع وصل بعد الإلغاء';
verify($multipart($lateExpense,$proof($cancelUploadProof),$pdf)[0]===409&&!DB::table('branch_expenses')->where('description',$lateExpense['description'])->exists(),
    'a delayed multipart request cannot write after the native reservation is cancelled');
verify($http('/storage/'.$uploadRow->attachment_path)[0]===404,'an expense uploaded through the reserved action remains unavailable at a public URL');
