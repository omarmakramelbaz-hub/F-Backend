<?php
// Pure public-envelope grammar: no application bootstrap, SQL, network or user data.
namespace Illuminate\Support\Facades {
    class DB {public static function connection(){return new class {function getDriverName(){return 'mysql';}function getConfig($key){return '127.0.0.1';}function getDatabaseName(){return 'fasakhansta_dashboard_stage_aaaaaaaaaaaaaaaa';}};}}
    // Only the Crypt API is synthetic. Authenticated malformed bytes are refused.
    class Crypt {
        public static function encryptString($plain){return base64_encode($plain).'.'.hash_hmac('sha256',$plain,'synthetic-catalog-key');}
        public static function decryptString($value){$parts=explode('.',$value);$plain=base64_decode($parts[0]??'',true);if(count($parts)!==2||!is_string($plain)||!hash_equals(hash_hmac('sha256',$plain,'synthetic-catalog-key'),$parts[1]))throw new \RuntimeException('Synthetic cipher refused');return $plain;}
    }
}
namespace {
function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException($message?:'HTTP '.$status);}
function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
function app($name=null){if($name===null)return new class {function environment($name){return false;}};if($name===\App\Services\Dashboard\DesktopDashboardWorkspaceCommandAuthorities::class)return new $name;throw new RuntimeException('Unexpected dependency '.$name);}
function config($key){return in_array($key,['desktop_dashboard.local','desktop_dashboard.workspace_enabled'],true)?true:null;}
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceCommandAuthorities.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardBootstrap.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardCreatedScopes.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceCreatedScopes.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceLocalSchema.php';
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
function catalogWireFixture(): array
{
    $one=item();$one['creation']['after']['owner']['balance']=1.0;$one['descriptor']['result_sha256']=digest($one['creation']);
    // Private storage sorts its own object keys. publicItem deliberately emits
    // descriptor then creation again; the wire digest uses that exact order.
    $stored=json_decode(canonical($one),true,64,JSON_THROW_ON_ERROR);
    $items=[['descriptor'=>$stored['descriptor'],'creation'=>$stored['creation']]];
    return ['format'=>2,'kind'=>'desktop-workspace-catalog-wire-fixture','scope'=>'synthetic-no-authentication',
        'created_scopes_json'=>\App\Services\Dashboard\DesktopDashboardBootstrap::json($items)]+(new Catalog)->publicItemsDigest($items);
}
$cases['private-capture-server-coverage-and-exact-wire-fraction-use-one-digest']=function()use($catalog,$manifest){
    putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');$fixture=catalogWireFixture();
    $items=json_decode($fixture['created_scopes_json'],true,64,JSON_THROW_ON_ERROR);$snapshot=snapshot($items);
    $catalog->validateSnapshotCatalog($snapshot,$manifest);
    check(str_contains($fixture['created_scopes_json'],'"balance":1.0'),'PHP wire fraction was normalized away.');
    check($fixture['sha256']===hash('sha256',$fixture['created_scopes_json'])
        &&$fixture['sha256']===$snapshot['coverage']['catalog_grants_sha256'],'Exact public wire order differs from server coverage hashing.');
    $sourceHash=str_repeat('e',64);$schema=str_repeat('f',64);
    $value=$items[0]+['provenance'=>['kind'=>'bootstrap','snapshot_id'=>$snapshot['snapshot_id'],'source_hash'=>$sourceHash,'schema_hash'=>$schema]];
    $row=array_intersect_key($value['descriptor'],array_flip(['grant_id','device_id','enrollment_id','command_id','actor_id','route_name','capability','entity','entity_id','origin_entity_id','branch']));
    $row['descriptor_hash']=digest($value);$row['evidence_cipher']=\Illuminate\Support\Facades\Crypt::encryptString(canonical($value));$lookups=[];
    $lookup=function($table,$where)use(&$lookups,$snapshot,$sourceHash,$schema){
        $lookups[]=$table;check($where===['device_id'=>$snapshot['device_id']],'Capture dependency escaped its authenticated root.');
        return match($table){'desktop_dashboard_source_manifest'=>(object)['snapshot_id'=>$snapshot['snapshot_id'],'sha256'=>$sourceHash],
            'desktop_dashboard_local_state'=>(object)['snapshot_id'=>$snapshot['snapshot_id'],'schema_hash'=>$schema],default=>throw new RuntimeException('Unexpected source lookup')};
    };
    $captured=$catalog->capturePublicItems([$row],$manifest['workspace_id'],$manifest['root_fingerprint'],$lookup);
    check($catalog->publicItemsDigest($captured)===['count'=>1,'sha256'=>$fixture['sha256']], 'Private capture and server raw wire disagree for nonempty catalog.');
    check($lookups===['desktop_dashboard_source_manifest','desktop_dashboard_local_state'],'Retired creator required a current user or grant.');
    $bad=$row;$bad['descriptor_hash']=str_repeat('0',64);refused(fn()=>$catalog->capturePublicItems([$bad],$manifest['workspace_id'],$manifest['root_fingerprint'],$lookup));
    $bad=$row;$bad['evidence_cipher'].='bad';refused(fn()=>$catalog->capturePublicItems([$bad],$manifest['workspace_id'],$manifest['root_fingerprint'],$lookup));
    refused(fn()=>$catalog->capturePublicItems([$row],$manifest['workspace_id'],$manifest['root_fingerprint'],
        fn($table,$where)=>(object)['snapshot_id'=>'99999999-9999-4999-8999-999999999999','sha256'=>$sourceHash,'schema_hash'=>$schema]));
    $path=__DIR__.'/fixtures/workspace-catalog-wire-v2.json';
    if(is_file($path))check(json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR)===$fixture,'Shared native/PHP wire fixture differs from the live PHP producer.');
};
foreach($cases as$name=>$work){$work();echo 'PASS '.$name.PHP_EOL;}
echo 'WORKSPACE_CREATED_SCOPES_CASES '.count($cases).PHP_EOL;
}
