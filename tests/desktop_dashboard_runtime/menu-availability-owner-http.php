<?php
// A vendor-enrolled branch remains eligible when its current original menu
// actor becomes resturant_owner without a parent. POS has a different policy.
$menuOwnerDevice=$snapshot['device_id'];$menuOwnerCookies=$cookies;
$menuOwnerSwitch=function(bool $local)use($stage,$database,$menuOwnerDevice,$ownerDevice){
    config(['database.connections.mysql.database'=>$local?$stage:$database,'desktop_dashboard.local'=>$local,'desktop_dashboard.device_id'=>$local?$menuOwnerDevice:$ownerDevice]);DB::purge();
};
$menuOwnerSwitch(true);$menuOwnerLocalType=DB::table('users')->where('id',10)->value('account_type');
DB::table('users')->where('id',10)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>null]);
$menuOwnerEnv=$env;$menuOwnerEnv['DB_DATABASE']=$stage;$menuOwnerEnv['DESKTOP_DASHBOARD_DEVICE_ID']=$menuOwnerDevice;$cookies=[];
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$menuOwnerEnv);
try{
    for($n=0;$n<100;$n++){if($http('/_desktop/health')[0]===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$page,$menuOwnerCsrf);
    verify($http('/admin/signin',['_token'=>$menuOwnerCsrf[1],'email'=>'branch@test.invalid','password'=>'Fixture123'])[0]===302,'the imported original restaurant owner without a parent signs in to its vendor-enrolled branch');
    [$status,$page]=$http('/admin/applies-orders?branch=f:100');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$menuOwnerCsrf);
    $menuOwnerUuid=(string)\Illuminate\Support\Str::uuid();$menuOwnerPath='/admin/order-board/menu/f/100/products/1/availability';
    $menuOwnerForm=['_token'=>$menuOwnerCsrf[1],'idempotency_key'=>$menuOwnerUuid,'available'=>false,'expected_available'=>true,'expected_revision'=>null];
    [$status,$body]=$http($menuOwnerPath,$menuOwnerForm,['Accept: application/json']);
    verify($status===200&&json_decode($body,true)['item']['available']===false&&$http($menuOwnerPath,$menuOwnerForm,['Accept: application/json'])[1]===$body,
        'the real local original availability endpoint and saved reply preserve restaurant-owner user_id fallback');
    $row=DB::table('desktop_dashboard_commands')->where('command_id',$menuOwnerUuid)->first();$saved=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->local_result_cipher),true);
    $menuOwnerCommand=['command_id'=>$menuOwnerUuid,'actor_id'=>10,'route_name'=>$row->route_name,'payload'=>json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->command_cipher),true),
        'local_result'=>$saved['result'],'local_references'=>$saved['references'],'dependencies'=>json_decode($row->dependencies,true),'occurred_at'=>\Carbon\Carbon::parse($row->created_at,'UTC')->toIso8601String()];
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);$cookies=$menuOwnerCookies;}
$menuOwnerSwitch(false);$menuOwnerServerType=DB::table('users')->where('id',10)->value('account_type');$menuOwnerServerStatus=DB::table('resturant_products')->where('id',1)->value('status');
DB::table('users')->where('id',10)->update(['account_type'=>'resturant_owner','owner_resturant_id'=>null]);DB::table('resturant_products')->where('id',1)->update(['status'=>'show']);
$menuOwnerEnv['DB_DATABASE']=$database;$menuOwnerEnv['DESKTOP_TEST_APPLICATION']=$application;$menuOwnerEnv['DESKTOP_DASHBOARD_ENABLED']='true';
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,__DIR__.'/server-router.php'],[['pipe','r'],['file',$profile.'/remote-attempts.log','a'],['file',$profile.'/remote-attempts.log','a']],$pipes,$application,$menuOwnerEnv);
$menuOwnerApi=function()use($origin,$link,$menuOwnerCommand){
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",['Content-Type: application/json','Accept: application/json','Authorization: Bearer '.$link['token']]),
        'content'=>json_encode($menuOwnerCommand,JSON_UNESCAPED_UNICODE),'ignore_errors'=>true,'timeout'=>15]]);
    $body=@file_get_contents($origin.'/api/desktop-dashboard/commands',false,$context);preg_match('/^HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$status);return [(int)($status[1]??0),json_decode($body,true)];
};
try{
    for($n=0;$n<100;$n++){if($http('/admin/login')[0])break;usleep(50000);}
    [$status,$receipt]=$menuOwnerApi();
    verify($status===200&&($receipt['committed']??false)&&$receipt['result']['item']['available']===false&&$menuOwnerApi()===[200,$receipt],
        'the real server reconciliation HTTP endpoint preserves current original owner fallback and deduplicates its exact receipt');
    verify(DB::table('desktop_dashboard_commands')->where('command_id',$menuOwnerUuid)->count()===1&&DB::table('resturant_products')->where('id',1)->value('status')==='hide',
        'the owner fallback writes one actual original product and one durable server receipt');
}finally{
    fclose($pipes[0]);proc_terminate($web);proc_close($web);DB::table('users')->where('id',10)->update(['account_type'=>$menuOwnerServerType]);
    DB::table('resturant_products')->where('id',1)->update(['status'=>$menuOwnerServerStatus]);$menuOwnerSwitch(true);DB::table('users')->where('id',10)->update(['account_type'=>$menuOwnerLocalType]);
    config(['desktop_dashboard.device_id'=>$ownerDevice]);$menuSwitch(false);$cookies=$menuOwnerCookies;
    // This fixture switches between an early branch snapshot (without the later
    // permission catalog) and the source while sharing disposable cache storage.
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}
