<?php
// Pure public-envelope grammar: no application bootstrap, SQL, network or user data.
function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException($message?:'HTTP '.$status);}
function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
function app($name){if($name===\App\Services\Dashboard\DesktopDashboardWorkspaceCommandAuthorities::class)return new $name;throw new RuntimeException('Unexpected dependency '.$name);}
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceCommandAuthorities.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardBootstrap.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardCreatedScopes.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceCreatedScopes.php';
use App\Services\Dashboard\DesktopDashboardWorkspaceCreatedScopes as Catalog;

function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function refused(callable $work){try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Malformed catalog evidence was accepted.');}
function canonical($value){$sort=function($v)use(&$sort){if(!is_array($v))return$v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($sort,$v);};return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
function digest($value){return hash('sha256',canonical($value));}
function item(int $id=1000,string $command='33333333-3333-4333-8333-333333333333'):array
{
    $workspace='11111111-1111-4111-8111-111111111111';$root=str_repeat('a',64);$stamp='2026-10-27 09:00:00';
    $creation=['format'=>1,'device_id'=>$workspace,'actor_id'=>1,'route_name'=>'go-stores.store','command_id'=>$command,
        'creator_created_at'=>'2020-01-01 00:00:00','created_scope'=>['kind'=>'gs','entity'=>'menu_store_owner','id'=>$id,'command_id'=>$command],
        'references'=>['menu_store_owner'=>$id,'wallet_account'=>$id,'dashboard_user'=>$id],
        'after'=>['owner'=>['id'=>$id,'added_by'=>1,'name'=>'Fixture owner','mobile'=>'1100000000','email'=>'fixture@example.test',
            'account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted','balance'=>'0.00','delegate_fees'=>null,
            'created_at'=>$stamp,'updated_at'=>$stamp,'email_verified_at'=>null,'pending_vendor_id'=>null,'owner_resturant_id'=>null],
            'profile'=>['user_id'=>$id,'name'=>'Fixture store','kind'=>'supermarket','address'=>'Fixture address','revision'=>1,'created_at'=>$stamp,'updated_at'=>$stamp]]];
    $descriptor=['format'=>2,'device_id'=>$workspace,'enrollment_id'=>$root,'command_id'=>$command,'actor_id'=>1,'route_name'=>'go-stores.store',
        'capability'=>'catalog','entity'=>'menu_store_owner','entity_id'=>$id,'origin_entity_id'=>$id,'branch'=>'gs:'.$id,
        'owner_created_at'=>$stamp,'creator_created_at'=>'2020-01-01 00:00:00','profile_created_at'=>$stamp,'result_sha256'=>digest($creation),
        'workspace_authority'=>['format'=>2,'workspace_id'=>$workspace,'root_fingerprint'=>$root,'actor_id'=>1,'manifest_revision'=>1,
            'grant'=>str_repeat('b',64),'command_sha256'=>str_repeat('c',64)]];
    $descriptor['grant_id']=digest(['workspace-catalog-created-scope:2',$workspace,$root,$command,1,'menu_store_owner',$id]);
    return ['descriptor'=>$descriptor,'creation'=>$creation];
}
function snapshot(array $items):array
{
    return ['format'=>2,'device_id'=>'11111111-1111-4111-8111-111111111111','snapshot_id'=>'22222222-2222-4222-8222-222222222222',
        'branches'=>['f:100'],'created_scopes'=>$items,'coverage'=>(new Catalog)->coverage(['f:100'],$items),
        'enrollment'=>['base_branches'=>['f:100']]];
}
$manifest=['workspace_id'=>'11111111-1111-4111-8111-111111111111','root_fingerprint'=>str_repeat('a',64)];$catalog=new Catalog;
$cases=[];
$cases['valid-empty-and-original-creator-envelope']=function()use($catalog,$manifest){
    $catalog->validateSnapshotCatalog(snapshot([]),$manifest);$value=snapshot([item()]);$catalog->validateSnapshotCatalog($value,$manifest);
    check($value['branches']===['f:100'],'Catalog evidence widened financial BASE.');
    check($value['coverage']['catalog_structural_branches']===['f:100','gs:1000'],'Catalog structural coverage is incomplete.');
};
$cases['wrong-root-actor-command-and-origin-proof-rejected']=function()use($catalog,$manifest){
    foreach(['device_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','enrollment_id'=>str_repeat('f',64),'actor_id'=>30,
        'command_id'=>'not-a-uuid','branch'=>'gs:9999','grant_id'=>str_repeat('0',64),'result_sha256'=>str_repeat('0',64)]as$key=>$bad){
        $items=[item()];$items[0]['descriptor'][$key]=$bad;refused(fn()=>$catalog->validateSnapshotCatalog(snapshot($items),$manifest));
    }
    $items=[item()];$items[0]['descriptor']['workspace_authority']['actor_id']=30;
    refused(fn()=>$catalog->validateSnapshotCatalog(snapshot($items),$manifest));
};
$cases['exact-original-creation-and-private-field-redaction-required']=function()use($catalog,$manifest){
    foreach(['password'=>'secret','remember_token'=>'secret']as$key=>$value){$items=[item()];$items[0]['creation']['after']['owner'][$key]=$value;
        $items[0]['descriptor']['result_sha256']=digest($items[0]['creation']);refused(fn()=>$catalog->validateSnapshotCatalog(snapshot($items),$manifest));}
    $items=[item()];$items[0]['creation']['actor_id']=30;$items[0]['descriptor']['result_sha256']=digest($items[0]['creation']);
    refused(fn()=>$catalog->validateSnapshotCatalog(snapshot($items),$manifest));
    $items=[item()];$items[0]['creation']['after']['owner']['added_by']=30;$items[0]['descriptor']['result_sha256']=digest($items[0]['creation']);
    refused(fn()=>$catalog->validateSnapshotCatalog(snapshot($items),$manifest));
};
$cases['coverage-hashes-and-base-are-not-caller-overrides']=function()use($catalog,$manifest){
    $value=snapshot([item()]);foreach(['catalog_grants_sha256','base_branches_sha256','catalog_branches_sha256']as$key){$bad=$value;$bad['coverage'][$key]=str_repeat('0',64);refused(fn()=>$catalog->validateSnapshotCatalog($bad,$manifest));}
    $bad=$value;$bad['coverage']['catalog_structural_branches'][]='gs:9999';refused(fn()=>$catalog->validateSnapshotCatalog($bad,$manifest));
    $bad=$value;$bad['enrollment']['base_branches'][]='gs:1000';refused(fn()=>$catalog->validateSnapshotCatalog($bad,$manifest));
};
$cases['duplicate-out-of-order-and-oversized-rosters-rejected']=function()use($catalog,$manifest){
    $items=[item(),item(1001,'44444444-4444-4444-8444-444444444444')];
    usort($items,fn($a,$b)=>strcmp($a['descriptor']['grant_id'],$b['descriptor']['grant_id']));$catalog->validateSnapshotCatalog(snapshot($items),$manifest);
    refused(fn()=>$catalog->validateSnapshotCatalog(snapshot(array_reverse($items)),$manifest));
    refused(fn()=>$catalog->validateSnapshotCatalog(snapshot([$items[0],$items[0]]),$manifest));
    refused(fn()=>$catalog->validateSnapshotCatalog(snapshot(array_fill(0,1001,$items[0])),$manifest));
};
foreach($cases as$name=>$work){$work();echo 'PASS '.$name.PHP_EOL;}
echo 'WORKSPACE_CREATED_SCOPES_CASES '.count($cases).PHP_EOL;
