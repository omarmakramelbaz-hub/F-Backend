<?php
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use App\Services\Dashboard\{DesktopDashboardJournal,DesktopDashboardReconciliation,DesktopDashboardReferences};

// Original feature text/status FormRequest and repository; image transfers remain unregistered.
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$siteFeatureCommands=[];$siteFeatureIds=[];
$featureUpload=function(string $path,array $values,array $extra=[])use($origin,$browserToken,&$cookies,$imageBytes){
    $boundary='desktop-feature-'.Str::uuid();$body='';
    foreach($values as $name=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$name."\"\r\n\r\n".$value."\r\n";
    $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"image\"; filename=\"feature.png\"\r\nContent-Type: image/png\r\n\r\n".$imageBytes."\r\n--".$boundary."--\r\n";
    $headers=['X-Fasakhansta-Desktop: '.$browserToken,'Content-Type: multipart/form-data; boundary='.$boundary,...$extra];
    if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
    @file_get_contents($origin.$path,false,$context);preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$status);return (int)($status[1]??0);
};
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/features/create');preg_match('/name="_token" value="([^"]+)"/',$page,$siteFeatureCsrf);
    verify($status===200&&isset($siteFeatureCsrf[1])&&str_contains($page,'name="text_ar"'),'the original site feature page renders its Arabic and English form fields');
    $siteFeature=['_token'=>$siteFeatureCsrf[1],'_desktop_command'=>(string)Str::uuid(),'added_by'=>1,'status'=>'show','title_ar'=>'ميزة محلي أصلي','title_en'=>'Original local question','text_ar'=>'<p>وصف عربية أصلية</p>','text_en'=>'<p>Original answer</p>'];
    verify($http('/admin/features',$siteFeature)[0]===302,'the original site feature FormRequest autoloads and its repository commits one local command');$siteFeatureCommands[]=$siteFeature['_desktop_command'];
    $siteFeatureFirst=(int)DB::table('features')->where('title_ar',$siteFeature['title_ar'])->value('id');$siteFeatureIds[]=$siteFeatureFirst;
    verify($siteFeatureFirst>0&&$http('/admin/features',$siteFeature)[0]===302&&DB::table('features')->where('title_ar',$siteFeature['title_ar'])->count()===1,'a lost original site feature creation reply does not duplicate its HTML answers');
    $siteFeature['_desktop_command']=(string)Str::uuid();$siteFeature['_method']='PUT';$siteFeature['text_ar']='<p>وصف عربية معدلة</p>';$siteFeature['status']='hide';
    verify($http('/admin/features/'.$siteFeatureFirst,$siteFeature)[0]===302&&DB::table('features')->where('id',$siteFeatureFirst)->value('text_ar')===$siteFeature['text_ar']
        &&DB::table('features')->where('id',$siteFeatureFirst)->value('status')==='hide','the original site feature update retains its before-state, HTML description and visibility');$siteFeatureCommands[]=$siteFeature['_desktop_command'];
    verify($http('/admin/features/'.$siteFeatureFirst,$siteFeature)[0]===302,'the original site feature update returns the same local response after a lost reply');
    $invalid=$siteFeature;unset($invalid['_method']);$invalid['_desktop_command']=(string)Str::uuid();$invalid['title_en']='';$before=DB::table('desktop_dashboard_commands')->count();
    verify($http('/admin/features',$invalid)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'the original site feature English validation redirects without a committed journal entry');
    $invalid['title_en']='Valid feature title';$invalid['status']='invalid';
    verify($http('/admin/features',$invalid)[0]===302&&DB::table('desktop_dashboard_commands')->count()===$before,'the original feature visibility validation cannot create a committed command');
    $upload=$siteFeature;unset($upload['_method']);$upload['_desktop_command']=(string)Str::uuid();
    verify($featureUpload('/admin/features',$upload)===501&&DB::table('desktop_dashboard_commands')->count()===$before
        &&DB::table('features')->where('title_ar',$siteFeature['title_ar'])->count()===1,'a real multipart feature image cannot acquire a successful local receipt or duplicate its text row');
    unset($siteFeature['_method']);$siteFeature['_desktop_command']=(string)Str::uuid();$siteFeature['title_ar']='ميزة محلي للحذف';
    verify($http('/admin/features',$siteFeature)[0]===302,'the original site feature form creates its single-delete fixture');$siteFeatureCommands[]=$siteFeature['_desktop_command'];
    $siteFeatureSecond=(int)DB::table('features')->where('title_ar',$siteFeature['title_ar'])->value('id');$siteFeatureIds[]=$siteFeatureSecond;
    $remove=['_token'=>$siteFeatureCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid()];
    verify($http('/admin/features/'.$siteFeatureSecond,$remove)[0]===302&&!DB::table('features')->where('id',$siteFeatureSecond)->exists()
        &&$http('/admin/features/'.$siteFeatureSecond,$remove)[0]===302,'the original site feature deletion retries before binding its removed model');$siteFeatureCommands[]=$remove['_desktop_command'];
    $siteFeature['_desktop_command']=(string)Str::uuid();$siteFeature['title_ar']='ميزة محلي للحذف الجماعي';
    verify($http('/admin/features',$siteFeature)[0]===302,'the original site feature form creates a second bulk selection');$siteFeatureCommands[]=$siteFeature['_desktop_command'];
    $siteFeatureThird=(int)DB::table('features')->where('title_ar',$siteFeature['title_ar'])->value('id');$siteFeatureIds[]=$siteFeatureThird;
    $bulk=['_token'=>$siteFeatureCsrf[1],'_method'=>'DELETE','_desktop_command'=>(string)Str::uuid(),'ids'=>$siteFeatureThird.','.$siteFeatureFirst];
    verify($http('/admin/featuresDeleteAll',$bulk,['Accept: application/json'])[0]===200&&DB::table('features')->whereIn('id',[$siteFeatureFirst,$siteFeatureThird])->count()===0,'the existing site feature bulk URL invokes its actual permission-protected controller method');
    $bulk['ids']=$siteFeatureFirst.','.$siteFeatureThird;
    verify($http('/admin/featuresDeleteAll',$bulk,['Accept: application/json'])[0]===200,'a reordered site feature bulk retry returns its receipt after both models disappear');$siteFeatureCommands[]=$bulk['_desktop_command'];
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
foreach($siteFeatureIds as $id)DB::table('features')->insert(['id'=>$id,'added_by'=>1,'status'=>'show','title_ar'=>'ميزة سيرفر مستقل '.$id,'title_en'=>'Server question','text_ar'=>'وصف السيرفر','text_en'=>'Server answer']);
foreach($siteFeatureCommands as $id){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$id)->first();$saved=json_decode(Crypt::decryptString($row->local_result_cipher),true);
    $command=['command_id'=>$id,'actor_id'=>1,'route_name'=>$row->route_name,'payload'=>json_decode(Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    $resolved=app(DesktopDashboardReferences::class)->resolve($remoteDevice->id,$command['payload']);
    if($row->route_name==='features.update')verify($resolved['parameters']['feature']!==$siteFeatureFirst,'site feature update parameters map to the created server record despite a colliding local ID');
    if($row->route_name==='features.destroy-all'){
        $selected=$resolved['values']['ids'];$first=$selected[0];$original=DB::table('features')->where('id',$first)->value('text_ar');
        DB::table('features')->where('id',$first)->update(['text_ar'=>'تعديل وصف مستقل على السيرفر']);
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Changed site feature batch was deleted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('features')->whereIn('id',$selected)->count()===count($selected),'one changed answer preserves every site feature row selected for pending bulk deletion');}
        DB::table('features')->where('id',$first)->update(['text_ar'=>$original]);
    }
    if(in_array($row->route_name,['features.destroy','features.destroy-all'],true)){
        $selected=$row->route_name==='features.destroy-all'?$resolved['values']['ids']:[$resolved['parameters']['feature']];
        $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('feature-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked site feature deletion was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('features')->whereIn('id',$selected)->count()===count($selected),'the original site feature deletion permission protects both single and bulk selections during replay');}
        $currentRole->givePermissionTo('feature-delete');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $receipt=app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===app(DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command),'the original site feature controller reconciles exactly once: '.$row->route_name);
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(DesktopDashboardJournal::class)->acknowledge($ownerDevice,$id,$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
verify(DB::table('features')->where('title_ar','like','ميزة محلي%')->count()===0&&DB::table('features')->whereIn('id',$siteFeatureIds)->count()===count($siteFeatureIds),'mapped original site feature deletions leave every unrelated colliding server record intact');
