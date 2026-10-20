<?php
use Illuminate\Support\Facades\DB;
$v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$application=realpath($argv[1]);
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$v['profile'],'DESKTOP_DASHBOARD_DEVICE_ID'=>$v['device'],'APP_KEY'=>$v['key'],'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$v['port'],'DB_DATABASE'=>$v['database'],'DB_USERNAME'=>'root','DB_PASSWORD'=>''] as $name=>$value){putenv($name.'='.$value);$_ENV[$name]=$value;$_SERVER[$name]=$value;}
$app=require $application.'/desktop/bootstrap.php';
DB::transaction(function()use($v){$state=DB::table('desktop_dashboard_local_state')->where('device_id',$v['device'])->lockForUpdate()->first();$actor=\App\Models\User::withoutGlobalScopes()->findOrFail($state->actor_id);app(\App\Services\Dashboard\DesktopDashboardCreatedScopes::class)->assertStructural($state,$actor);app(\App\Services\Dashboard\DesktopDashboardCreatedScopes::class)->catalog($state,$v['branch'],$actor);echo json_encode(['catalog'=>true]);});
