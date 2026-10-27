<?php
namespace Spatie\LaravelSettings {abstract class Settings {}}
namespace {
function abort_unless($value,$status,$message=''){if(!$value)throw new \RuntimeException('HTTP_'.$status);}
require __DIR__.'/../app/Models/GeneralSettings.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceModuleCoverage.php';
use App\Services\Dashboard\DesktopDashboardWorkspaceModuleCoverage as Coverage;
use App\Services\Dashboard\DesktopDashboardWorkspaceAuthority as Authority;
function check($value,$message){if(!$value)throw new \RuntimeException($message);}
function refused(callable$work){try{$work();}catch(\RuntimeException$error){check(str_starts_with($error->getMessage(),'HTTP_'),'Unexpected exception');return;}throw new \RuntimeException('Expected coverage rejection');}
function part(array$rows,string$ddl='CREATE TABLE `fixture` (`id` bigint)'):array{return ['rows'=>$rows,'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),'ddl'=>$ddl];}
$password=password_hash('public-module-fixture',PASSWORD_BCRYPT,['cost'=>4]);
$manifest=['workspace_id'=>'11111111-1111-4111-8111-111111111111','revision'=>3,'members'=>[
    ['actor_id'=>1,'role'=>'admin','permissions'=>['super_admin'=>true],'password_binding'=>hash('sha256',$password)]]];
$tables=[];foreach(array_unique([...Coverage::requiredTables('accounts'),...Coverage::requiredTables('settings')])as$name)$tables[$name]=part([]);
$tables['users']=part([['id'=>1,'account_type'=>'admin','owner_resturant_id'=>null,'status'=>'accepted','password'=>$password],
    ['id'=>50,'account_type'=>'vendor','app_scope'=>'go_partner','status'=>'disabled','password'=>'!desktop-disabled']]);
$tables['resturants']=part([['id'=>100],['id'=>101]]);$tables['go_stores']=part([['user_id'=>50]]);$tables['pending_vendors']=part([]);
$tables['settings']=part(array_map(fn($name)=>['group'=>'general','name'=>$name],Coverage::settingsNames()));
$tables['orders']=part([],'CREATE TABLE `orders` (`id` bigint,`user_address_id` bigint, FOREIGN KEY (`user_address_id`) REFERENCES `user_address` (`id`))');
$structural=Coverage::structural($tables);check($structural===['f:100','f:101','gs:50'],'Dormant structural accounts must not disappear.');
$modules=Coverage::issue($manifest,1,$tables,$structural,['orders']);
check(array_keys($modules)===['accounts','account-addresses','settings'],'Complete module claims must be independent.');
$snapshot=['workspace_manifest'=>$manifest,'tables'=>$tables,'coverage'=>['full_dashboard'=>false,'modules'=>$modules]];
Coverage::validateSnapshot($snapshot);echo "PASS workspace-modules complete-certified-datasets-keep-full-dashboard-false\n";
$partial=$tables;$partial['settings']=part([['group'=>'general','name'=>'app_balance']]);
$claims=Coverage::issue($manifest,1,$partial,Coverage::structural($partial),['orders']);
check(isset($claims['accounts'])&&!isset($claims['settings']),'Incomplete settings may not disable complete account datasets.');
$partial=$tables;unset($partial['model_has_roles']);$claims=Coverage::issue($manifest,1,$partial,$structural,['orders']);
check($claims===[],'Role membership completeness is required for both modules.');echo "PASS workspace-modules incomplete-datasets-never-become-complete-flags\n";
foreach(['account_type'=>'vendor','owner_resturant_id'=>100,'status'=>'disabled','password'=>'different-verifier']as$key=>$value){
    $bad=$tables;$bad['users']['rows'][0][$key]=$value;check(Coverage::issue($manifest,1,$bad,Coverage::structural($bad),['orders'])===[],'A real active global administrator is required.');
}
echo "PASS workspace-modules witness-role-status-password-and-owner-binding\n";
foreach(['module','workspace_id','manifest_revision','witness_actor_id','structural_sha256']as$key){
    $bad=$snapshot;$bad['coverage']['modules']['accounts'][$key]=$key==='manifest_revision'?2:($key==='witness_actor_id'?50:'wrong');refused(fn()=>Coverage::validateSnapshot($bad));
}
$bad=$snapshot;$bad['coverage']['modules']['accounts']['tables']['users']['sha256']=str_repeat('0',64);refused(fn()=>Coverage::validateSnapshot($bad));
$bad=$snapshot;$bad['coverage']['modules']['accounts']['tables']['users']['rows']++;refused(fn()=>Coverage::validateSnapshot($bad));
echo "PASS workspace-modules header-table-count-and-raw-hash-bindings\n";
$bad=$snapshot;unset($bad['coverage']['modules']['accounts']['tables']['go_stores']);refused(fn()=>Coverage::validateSnapshot($bad));
$bad=$snapshot;$bad['coverage']['modules']['accounts']['structural_branches']=['f:100','f:101'];
$bad['coverage']['modules']['accounts']['structural_sha256']=hash('sha256',Authority::canonical(['f:100','f:101']));refused(fn()=>Coverage::validateSnapshot($bad));
echo "PASS workspace-modules optional-structural-tables-and-dormant-go-cohort-required\n";
$claims=Coverage::issue($manifest,1,$tables,$structural,['orders','unknown_address_child']);
check(isset($claims['accounts'])&&!isset($claims['account-addresses']),'Unknown inbound address children may not block safe NAME/status administration.');
$bad=$snapshot;$bad['coverage']['modules']['account-addresses']['address_children']=[];refused(fn()=>Coverage::validateSnapshot($bad));
echo "PASS workspace-modules address-coverage-requires-every-source-reference\n";
$large=$tables;$large['resturants']=part([['id'=>'9007199254740993']]);$branches=Coverage::structural($large);
check($branches===['f:9007199254740993','gs:50'],'Structural SQL identities must remain exact strings above JS safe integers.');
$claims=Coverage::issue($manifest,1,$large,$branches,['orders']);Coverage::validateSnapshot(['workspace_manifest'=>$manifest,'tables'=>$large,'coverage'=>['modules'=>$claims]]);
echo "PASS workspace-modules structural-sql-identity-precision\n";
echo "Workspace module coverage: 7 pure cases passed; no SQL completeness was simulated.\n";
}
