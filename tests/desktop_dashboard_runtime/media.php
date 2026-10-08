<?php
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardBootstrap,DesktopDashboardDevices};
// Original full-schema fixture: scoped uploads, actual image bytes, binary API and revocation.
$imageBytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB5kAAAAASUVORK5CYII=');
$imageFiles=['resturants/1/logo.png','resturants/2/foreign.png','products/3/رنجة.png','products/3/conversions/thumb.png','users/4/avatar.png','users/5/foreign.png','branding/logo.png'];
foreach($imageFiles as $file){$path=$profile.'/app/public/'.$file;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$imageBytes);}
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
$paths=array_column($mediaSnapshot['media'],'path');sort($paths);
verify($paths===['branding/logo.png','products/3/conversions/thumb.png','products/3/رنجة.png','resturants/1/logo.png','users/4/avatar.png'],
    'scoped media includes original account/branch/product images, derivatives and branding without another branch uploads');
verify($mediaSnapshot['coverage']['media']===true&&$mediaSnapshot['coverage']['full_dashboard']===false,
    'verified scoped images never claim that all dashboard write modules are complete');
$ticket=$mediaSnapshot['media'][0]['ticket'];$devices=app(DesktopDashboardDevices::class);$device=$devices->device($link['token']);
$download=app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$ticket);
verify($download['bytes']===$imageBytes&&$download['mime']==='image/png','the signed media capability returns the exact scoped image bytes');
function rejectMedia(callable $work,int $status,string $message){try{$work();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===$status,$message);return;}throw new RuntimeException('Media request was not rejected: '.$message);}
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$ticket.'x'),422,'a modified media capability cannot authorize a file');
$payload=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($ticket),true);$payload['expires_at']=time()-1;
$expired=\Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload));
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$expired),403,'an expired capability requires a fresh snapshot');
$other=$devices->enroll(['device_id'=>(string)\Illuminate\Support\Str::uuid(),'name'=>'foreign media fixture','nonce'=>bin2hex(random_bytes(32))],User::withoutGlobalScopes()->findOrFail(11));
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($devices->device($other['token']),$ticket),403,'another enrolled branch cannot reuse an image capability');
file_put_contents($profile.'/app/public/branding/logo.png',$imageBytes.'changed');
rejectMedia(fn()=>app(\App\Services\Dashboard\DesktopDashboardMedia::class)->download($device,$ticket),409,'an image changed after export cannot be mixed into the previous coherent snapshot');
file_put_contents($profile.'/app/public/branding/logo.png',$imageBytes);
$apiReservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$apiPort=(int)substr(strrchr(stream_socket_get_name($apiReservation,false),':'),1);fclose($apiReservation);
$apiEnv=getenv();$apiEnv['DESKTOP_TEST_APPLICATION']=$application;$apiEnv['DESKTOP_DASHBOARD_ENABLED']='true';
$api=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$apiPort,__DIR__.'/server-router.php'],[['pipe','r'],['file',$profile.'/media-api.log','a'],['file',$profile.'/media-api.log','a']],$apiPipes,$application,$apiEnv);
$apiUrl='http://127.0.0.1:'.$apiPort.'/api/desktop-dashboard/media?ticket='.rawurlencode($ticket);
$imageApi=function($token)use($apiUrl){
    $context=stream_context_create(['http'=>['header'=>"Accept: application/json\r\nAuthorization: Bearer ".$token,'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);
    $body=@file_get_contents($apiUrl,false,$context);$headers=$http_response_header??[];preg_match('/^HTTP\/\S+ (\d+)/',$headers[0]??'',$status);
    return [(int)($status[1]??0),$body,$headers];
};
try{
    for($n=0;$n<100;$n++){[$status,$body,$headers]=$imageApi($link['token']);if($status)break;usleep(50000);}
    if($status!==200)fwrite(STDERR,file_get_contents($profile.'/media-api.log'));
    verify($status===200&&$body===$imageBytes&&in_array('Content-Type: image/png',$headers,true),'the actual Laravel media API serves verified binary image bytes with the enrolled device credential');
    verify($imageApi('')[0]===401,'the actual binary media API rejects a missing device credential');
    DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['enabled'=>false]);
    verify($imageApi($link['token'])[0]===401,'revoking a device immediately blocks an already issued image capability');
    DB::table('desktop_dashboard_devices')->where('id',$device->id)->update(['enabled'=>true]);
    DB::table('users')->where('id',10)->update(['status'=>'disabled']);
    verify($imageApi($link['token'])[0]===403,'disabling the enrolled account immediately blocks its cached media capability');
    DB::table('users')->where('id',10)->update(['status'=>'accepted']);
    DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
    verify($imageApi($link['token'])[0]===403,'removing the account branch immediately blocks its cached media capability');
    DB::table('resturants')->where('id',100)->update(['user_id'=>10]);
}finally{fclose($apiPipes[0]);proc_terminate($api);proc_close($api);}
unlink($profile.'/app/public/branding/logo.png');
$missing=app(DesktopDashboardBootstrap::class)->export($devices->device($link['token']));
verify(!$missing['coverage']['media']&&count($missing['media_issues'])>0,'a missing referenced image leaves preparation incomplete instead of accepting a partial media dataset');
file_put_contents($profile.'/app/public/branding/logo.png',$imageBytes);
foreach(['../private.png','/absolute.png','folder/NUL.png','folder/image.php','folder/image.png.','folder/x%2fimage.png'] as $path)
    rejectMedia(fn()=>\App\Services\Dashboard\DesktopDashboardMedia::receipt([['path'=>$path,'sha256'=>str_repeat('a',64),'bytes'=>1,'mime'=>'image/png']]),422,'unsafe media path is rejected: '.$path);
