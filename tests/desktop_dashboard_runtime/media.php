<?php
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardBootstrap,DesktopDashboardDevices};
// Original full-schema fixture: scoped uploads, actual image bytes, binary API and revocation.
$imageBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=');
$imageFiles=['resturants/1/logo.png','resturants/2/foreign.png','products/3/رنجة.png','products/3/conversions/thumb.png','users/4/avatar.png','users/5/foreign.png','branding/logo.png'];
foreach($imageFiles as $file){$path=$profile.'/app/public/'.$file;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$imageBytes);}
$expensePdfBytes="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$pdfSource=$profile.'/private/expense-source.pdf';file_put_contents($pdfSource,$expensePdfBytes);
$expenseValues=['branch'=>'f:100','idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'occurred_on'=>now('Africa/Cairo')->toDateString(),
    'category'=>'purchases','description'=>'مرفق اختبار النسخة المحلية','amount'=>'10.00','payment_method'=>'cash'];
$expenseResult=app(\App\Services\Dashboard\BranchExpenses::class)->save($expenseValues,$actor,new \Illuminate\Http\UploadedFile($pdfSource,'فاتورة.pdf','application/pdf',null,true));
$expenseAttachmentId=$expenseResult['expense']['id'];$expenseAttachmentPath=DB::table('branch_expenses')->where('id',$expenseAttachmentId)->value('attachment_path');
$foreignValues=$expenseValues;$foreignValues['branch']='f:101';$foreignValues['idempotency_key']=(string)\Illuminate\Support\Str::uuid();
app(\App\Services\Dashboard\BranchExpenses::class)->save($foreignValues,User::withoutGlobalScopes()->findOrFail(11),new \Illuminate\Http\UploadedFile($pdfSource,'فرع آخر.pdf','application/pdf',null,true));
foreach([
    [1,'Resturant',100,'resturants','logo','logo.png'],[2,'Resturant',101,'resturants','logo','foreign.png'],
    [3,'Product',1,'products','product_image','رنجة.png'],[4,'User',10,'users','photo_profile','avatar.png'],[5,'User',11,'users','photo_profile','foreign.png'],
] as [$mediaId,$model,$modelId,$disk,$collection,$name]){
    DB::table('media')->insert(['id'=>$mediaId,'model_type'=>'App\\Models\\'.$model,'model_id'=>$modelId,'collection_name'=>$collection,'name'=>$name,
        'file_name'=>$name,'mime_type'=>'image/png','disk'=>$disk,'conversions_disk'=>$disk,'size'=>strlen($imageBytes),
        'manipulations'=>'[]','custom_properties'=>'{}','generated_conversions'=>$mediaId===3?' {"thumb":true}':'{}','responsive_images'=>'{}']);
}
DB::table('settings')->where('group','general')->where('name','logo')->update(['payload'=>json_encode('branding/logo.png')]);
$mediaSnapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
$paths=array_column(array_filter($mediaSnapshot['media'],fn($file)=>!isset($file['area'])),'path');sort($paths);
verify($paths===['branding/logo.png','products/3/conversions/thumb.png','products/3/رنجة.png','resturants/1/logo.png','users/4/avatar.png'],
    'scoped media includes original account/branch/product images, derivatives and branding without another branch uploads');
verify($mediaSnapshot['coverage']['media']===true&&$mediaSnapshot['coverage']['full_dashboard']===false,
    'verified scoped images never claim that all dashboard write modules are complete');
$privateFiles=array_values(array_filter($mediaSnapshot['media'],fn($file)=>($file['area']??'public')==='private'));
verify(count($privateFiles)===1&&$privateFiles[0]['path']===$expenseAttachmentPath&&$privateFiles[0]['mime']==='application/pdf'
    &&!is_file($profile.'/app/public/'.$expenseAttachmentPath),'the original private expense PDF is scoped to this branch and never copied into public storage');
$privateTicket=$privateFiles[0]['ticket'];
$ticket=collect($mediaSnapshot['media'])->firstWhere('path','branding/logo.png')['ticket'];$devices=app(DesktopDashboardDevices::class);$device=$devices->device($link['token']);
$download=app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$ticket);
verify($download['bytes']===$imageBytes&&$download['mime']==='image/png','the signed media capability returns the exact scoped image bytes');
function rejectMedia(callable $work,int $status,string $message){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$status,$message);return;}throw new RuntimeException('Media request was not rejected: '.$message);}
$privateDownload=app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$privateTicket);
verify($privateDownload['bytes']===$expensePdfBytes&&$privateDownload['mime']==='application/pdf','a scoped private capability returns the exact original expense PDF');
$privatePayload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($privateTicket),true);$privatePayload['area']='public';
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,\Illuminate\Support\Facades\Crypt::encryptString(json_encode($privatePayload))),403,
    'a private expense capability cannot be reclassified as a public image');
$privatePath=$profile.'/app/'.$expenseAttachmentPath;file_put_contents($privatePath,$expensePdfBytes.'changed');
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$privateTicket),409,'a changed private PDF cannot be mixed into the previous snapshot');
verify(app(DesktopDashboardBootstrap::class)->export($device)['coverage']['media']===false,'a private PDF whose hash disagrees with its expense row blocks file completeness');
file_put_contents($privatePath,$expensePdfBytes);
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$ticket.'x'),422,'a modified media capability cannot authorize a file');
$payload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($ticket),true);$payload['expires_at']=time()-1;
$expired=\Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload));
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$expired),403,'an expired capability requires a fresh snapshot');
$other=$devices->enroll(['device_id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>'foreign media fixture','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(11));
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($devices->device($other['token']),$ticket),403,'another enrolled branch cannot reuse an image capability');
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($devices->device($other['token']),$privateTicket),403,'another enrolled branch cannot reuse a private expense capability');
DB::table('branch_expenses')->where('id',$expenseAttachmentId)->update(['branch'=>'f:101']);
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$privateTicket),403,'an expense removed from the enrolled branch cannot use its old private capability');
DB::table('branch_expenses')->where('id',$expenseAttachmentId)->update(['branch'=>'f:100']);
file_put_contents($profile.'/app/public/branding/logo.png',$imageBytes.'changed');
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$ticket),409,'an image changed after export cannot be mixed into the previous coherent snapshot');
file_put_contents($profile.'/app/public/branding/logo.png',$imageBytes);
$apiReservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$apiPort=(int)substr(strrchr(stream_socket_get_name($apiReservation,false),':'),1);fclose($apiReservation);
$apiEnv=getenv();$apiEnv['DESKTOP_TEST_APPLICATION']=$application;$apiEnv['DESKTOP_DASHBOARD_ENABLED']='true';
$api=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$apiPort,__DIR__.'/server-router.php'],[['pipe','r'],['file',$profile.'/media-api.log','a'],['file',$profile.'/media-api.log','a']],$apiPipes,$application,$apiEnv);
$apiUrl='http://127.0.0.1:'.$apiPort.'/api/desktop-dashboard/media?ticket='.rawurlencode($ticket);
$imageApi=function($token,$capability=null)use($apiUrl,$apiPort){
    $context=stream_context_create(['http'=>['header'=>"Accept: application/json\r\nAuthorization: Bearer ".$token,'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);
    $url=$capability===null?$apiUrl:'http://127.0.0.1:'.$apiPort.'/api/desktop-dashboard/media?ticket='.rawurlencode($capability);
    $body=@file_get_contents($url,false,$context);$headers=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$headers[0]??'',$status);
    return [(int)($status[1]??0),$body,$headers];
};
try{
    for($n=0;$n<100;$n++){[$status,$body,$headers]=$imageApi($link['token']);if($status)break;usleep(50000);}
    if($status!==200)fwrite(STDERR,file_get_contents($profile.'/media-api.log'));
    verify($status===200&&$body===$imageBytes&&in_array('Content-Type: image/png',$headers,true),'the actual Laravel media API serves verified binary image bytes with the enrolled device credential');
    verify($imageApi('')[0]===401,'the actual binary media API rejects a missing device credential');
    [$pdfStatus,$pdfBody,$pdfHeaders]=$imageApi($link['token'],$privateTicket);
    verify($pdfStatus===200&&$pdfBody===$expensePdfBytes&&in_array('Content-Type: application/pdf',$pdfHeaders,true),
        'the actual Laravel media API downloads the scoped private PDF through the native credential');
    verify($imageApi('',$privateTicket)[0]===401,'a private PDF download also requires the native device credential');
    $serverCookies=[];
    $serverHttp=function($path,$values=null,$bearer=null,$csrf=null,$json=false)use($apiPort,&$serverCookies){
        $headers=['Accept: application/json'];
        if($serverCookies)$headers[]='Cookie: '.implode('; ',$serverCookies);
        if($bearer!==null)$headers[]='Authorization: Bearer '.$bearer;
        if($csrf!==null)$headers[]='X-CSRF-TOKEN: '.$csrf;
        if($values!==null)$headers[]='Content-Type: '.($json?'application/json':'application/x-www-form-urlencoded');
        $context=stream_context_create(['http'=>['method'=>$values===null?'GET':'POST','header'=>implode("\r\n",$headers),
            'content'=>$values===null?'':($json?json_encode($values):http_build_query($values)),'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);
        $body=@file_get_contents('http://127.0.0.1:'.$apiPort.$path,false,$context);$reply=$http_response_header??[];
        foreach($reply as $line)if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$line,$cookie))$serverCookies[$cookie[1]]=$cookie[1].'='.$cookie[2];
        preg_match('/^HTTP\/\S+ (\d+)/',$reply[0]??'',$status);return [(int)($status[1]??0),$body];
    };
    verify($serverHttp('/admin/desktop-dashboard/session',null,$link['token'])[0]===302,'a device token alone cannot claim a signed-in original dashboard session');
    [$loginStatus,$login]=$serverHttp('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$login,$loginCsrf);
    verify($loginStatus===200&&isset($loginCsrf[1]),'the original server login exposes its own session CSRF token');
    verify($serverHttp('/admin/signin',['_token'=>$loginCsrf[1],'email'=>'branch@test.invalid','password'=>'Fixture123'])[0]===302,'the original server session authenticates the enrolled account');
    [$sessionStatus,$session]=$serverHttp('/admin/desktop-dashboard/session',null,$link['token']);
    verify($sessionStatus===200&&json_decode($session,true)===['device_id'=>$device->id,'actor_id'=>10],
        'server return verifies the current browser account and device without exposing its bearer credential');
    verify($serverHttp('/admin/desktop-dashboard/session',null,$other['token'])[0]===403,'another device account cannot authorize switching the signed-in browser');
    verify($serverHttp('/admin/desktop-dashboard/session')[0]===401,'the signed-in browser alone cannot authorize a desktop server return');
    [$pageStatus,$page]=$serverHttp('/admin/takeaway');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$pageCsrf);
    verify($pageStatus===200&&isset($pageCsrf[1]),'native preparation uses the current original dashboard CSRF token');
    $enrollment=['device_id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>'native enrollment HTTP fixture','nonce'=>bin2hex(random_bytes(32))];
    verify($serverHttp('/admin/desktop-dashboard/enroll',$enrollment,null,'invalid-CSRF',true)[0]===419
        &&!DB::table('desktop_dashboard_devices')->where('id',$enrollment['device_id'])->exists(),'invalid CSRF cannot enroll a native device');
    [$enrollStatus,$enrollBody]=$serverHttp('/admin/desktop-dashboard/enroll',$enrollment,null,$pageCsrf[1],true);
    [$retryStatus,$retryBody]=$serverHttp('/admin/desktop-dashboard/enroll',$enrollment,null,$pageCsrf[1],true);
    verify($enrollStatus===200&&$retryStatus===200&&$enrollBody===$retryBody&&json_decode($enrollBody,true)['actor_id']===10,
        'the actual original browser session enrolls idempotently using a persisted native nonce');
    DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['enabled'=>false]);
    verify($imageApi($link['token'])[0]===401,'revoking a device immediately blocks an already issued image capability');
    verify($imageApi($link['token'],$privateTicket)[0]===401,'revoking a device immediately blocks its cached private PDF capability');
    DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['enabled'=>true]);
    DB::table('users')->where('id',10)->update(['status'=>'disabled']);
    verify($imageApi($link['token'])[0]===403,'disabling the enrolled account immediately blocks its cached media capability');
    DB::table('users')->where('id',10)->update(['status'=>'accepted']);
    DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
    $removedBranch=$imageApi($link['token']);
    verify($removedBranch[0]===404&&$removedBranch[1]!==$imageBytes,'removing the account branch blocks media using the original hidden-branch response (HTTP '.$removedBranch[0].')');
    DB::table('resturants')->where('id',100)->update(['user_id'=>10]);
}finally{fclose($apiPipes[0]);proc_terminate($api);proc_close($api);}
unlink($profile.'/app/public/branding/logo.png');
$missing=app(DesktopDashboardBootstrap::class)->export($devices->device($link['token']));
verify(!$missing['coverage']['media']&&count($missing['media_issues'])>0,'a missing referenced image leaves preparation incomplete instead of accepting a partial media dataset');
file_put_contents($profile.'/app/public/branding/logo.png',$imageBytes);
unlink($privatePath);
verify(app(DesktopDashboardBootstrap::class)->export($devices->device($link['token']))['coverage']['media']===false,
    'a missing referenced private expense attachment blocks file completeness');
file_put_contents($privatePath,$expensePdfBytes);
foreach(['../private.png','/absolute.png','folder/NUL.png','folder/image.php','folder/image.png.','folder/x%2fimage.png'] as $path)
    rejectMedia(fn()=>\App\Services\Dashboard\DesktopDashboardMedia::receipt([['path'=>$path,'sha256'=>str_repeat('a',64),'bytes'=>1,'mime'=>'image/png']]),422,'unsafe media path is rejected: '.$path);
foreach(['public/invoice.pdf','branch-expenses/../invoice.pdf','branch-expenses/invoice.html'] as $path)
    rejectMedia(fn()=>\App\Services\Dashboard\DesktopDashboardMedia::receipt([['area'=>'private','path'=>$path,'sha256'=>str_repeat('a',64),'bytes'=>1,'mime'=>'application/pdf']]),422,
        'unsafe or unsupported private attachment path is rejected: '.$path);
