<?php
// Pure synthetic protocol-2 proofs: no application, SQL, network or user data.
function abort_unless($condition,$status,$message=''){if(!$condition)throw new RuntimeException($message?:('HTTP '.$status));}
function abort_if($condition,$status,$message=''){abort_unless(!$condition,$status,$message);}
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceDeltaCodec.php';
require __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceIssuer.php';
use App\Services\Dashboard\DesktopDashboardDeltaCodec as Legacy;
use App\Services\Dashboard\DesktopDashboardWorkspaceDeltaCodec as Codec;

function check($condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function refused(callable $work): void {try{$work();}catch(Throwable $error){return;}throw new RuntimeException('Invalid workspace delta input was accepted.');}
function canonical($value): string {
    if(is_array($value)){if($value!==[]&&array_keys($value)!==range(0,count($value)-1))ksort($value,SORT_STRING);foreach($value as &$item)$item=json_decode(canonical($item),true,512,JSON_THROW_ON_ERROR);unset($item);}
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
}
function signFixture(array $manifest): array {$manifest['roster_sha256']=hash('sha256',canonical($manifest['members']));return $manifest;}
function verifier(string $name): string {return password_hash('workspace-fixture-only-'.$name,PASSWORD_BCRYPT,['cost'=>4]);}
$hashA=verifier('A');$hashB=verifier('B');$hashC=verifier('C');
function fixtureManifest(int $revision=1): array {
    global $hashA,$hashB;
    $member=static fn(int $id,string $role,string $alias,string $branch,string $password)=>[
        'actor_id'=>$id,'role'=>$role,'enabled'=>true,'aliases'=>[$alias],'branches'=>[$branch],
        'permissions'=>['super_admin'=>$id===101,'names'=>['order-list'],'roles'=>[]],
        'password_binding'=>hash('sha256',$password),'grant'=>hash('sha256','fixture-grant-'.$revision.'-'.$id)];
    return signFixture(['format'=>2,'kind'=>'desktop-dashboard-workspace-members',
        'workspace_id'=>'11111111-1111-4111-8111-111111111111','revision'=>$revision,
        'root_fingerprint'=>str_repeat('a',64),'members'=>[
            $member(101,'admin','owner@example.test','f:100',$hashA),
            $member(202,'vendor','branch@example.test','f:100',$hashB)],
        'roster_sha256'=>'','signature'=>str_repeat('e',64)]);
}
function usersDdl(): string {return "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  `name` varchar(100) NOT NULL,\n  `password` varchar(255) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";}
function productDdl(int $counter=10): string {return "CREATE TABLE `products` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(100) NOT NULL,\n  `amount` decimal(12,3) DEFAULT NULL,\n  `rating` double DEFAULT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=$counter DEFAULT CHARSET=utf8mb4";}
function userRows(): array {global $hashA,$hashB,$hashC;return [
    ['id'=>101,'name'=>'Owner','password'=>$hashA],['id'=>202,'name'=>'Branch','password'=>$hashB],
    ['id'=>303,'name'=>'Nonmember','password'=>$hashC]];}
function productRows(): array {return [['id'=>1,'name'=>'أول','amount'=>'10.500','rating'=>0.1],['id'=>2,'name'=>'Second','amount'=>null,'rating'=>null]];}
function sameRows(Codec $codec,string $table,array $left,array $right,string $ddl,?array $projection=null): void {
    check($codec->index($table,$left,$ddl,$projection)===$codec->index($table,$right,$ddl,$projection),'Reconstructed workspace row multiset differs.');
}

$cases=[];
$cases['members-keep-original-verifiers-nonmembers-never-travel']=static function(): void {
    global $hashA,$hashB,$hashC;$codec=new Codec(fixtureManifest());$raw=userRows();$original=$raw;
    $before=$codec->index('users',[],usersDdl());$delta=$codec->delta('users',$raw,usersDdl(),$before);
    $passwords=array_column($delta['add'],'password','id');
    check($passwords[101]===$hashA&&$passwords[202]===$hashB,'A valid second member verifier was disabled.');
    check($passwords[303]===Codec::DISABLED_PASSWORD&&!str_contains(json_encode($delta),$hashC),'Nonmember credentials crossed the transport boundary.');
    check($raw===$original,'Hashing/redaction changed the caller\'s retained rows.');
    $applied=$codec->apply('users',[],usersDdl(),$delta);sameRows($codec,'users',$raw,$applied,usersDdl());
    check($codec->context()===array_intersect_key(fixtureManifest(),array_flip(['workspace_id','revision','root_fingerprint','roster_sha256'])),
        'Public codec context contains a member grant or verifier.');
};
$cases['second-member-password-change-is-real-delta']=static function(): void {
    global $hashA;$oldManifest=fixtureManifest();$old=new Codec($oldManifest);$rows=userRows();$changed=$rows;
    $changed[1]['password']=verifier('B-new');$nextManifest=fixtureManifest(2);
    $nextManifest['members'][1]['password_binding']=hash('sha256',$changed[1]['password']);$nextManifest=signFixture($nextManifest);$next=new Codec($nextManifest);
    check(Legacy::index('users',$rows,101,usersDdl())===Legacy::index('users',$changed,101,usersDdl()),
        'The fixture did not exercise v1\'s noncustodian-password normalization.');
    $before=$old->index('users',$rows,usersDdl());$after=$next->index('users',$changed,usersDdl());
    check($before['rowset_sha256']!==$after['rowset_sha256'],'The second member password change was normalized away.');
    refused(fn()=>$old->index('users',$changed,usersDdl()));
    refused(fn()=>$next->delta('users',$changed,usersDdl(),$before));
    $delta=$next->delta('users',$changed,usersDdl(),$before,null,$old);
    check(($delta['replace']??false)===true&&array_sum($delta['remove'])===3,'An approved forward roster transition did not replace its complete table.');
    $passwords=array_column($delta['add'],'password','id');
    check($passwords[101]===$hashA&&$passwords[202]===$changed[1]['password'],'The forward transition lost an unchanged or changed member verifier.');
    $result=$next->applyReplacement('users',$delta['add'],usersDdl(),$delta,$before,null,$old);
    sameRows($next,'users',$changed,$result,usersDdl());
    refused(fn()=>$next->applyReplacement('users',$delta['add'],usersDdl(),$delta,$before));
};
$cases['retired-member-password-is-disabled']=static function(): void {
    global $hashB;$previous=new Codec(fixtureManifest());$rows=userRows();$before=$previous->index('users',$rows,usersDdl());
    $manifest=fixtureManifest(2);array_pop($manifest['members']);$manifest=signFixture($manifest);$next=new Codec($manifest);
    $delta=$next->delta('users',$rows,usersDdl(),$before,null,$previous);
    check(array_column($delta['add'],'password','id')[202]===Codec::DISABLED_PASSWORD&&!str_contains(json_encode($delta),$hashB),
        'A removed account retained a usable verifier in the new snapshot.');
    $next->applyReplacement('users',$delta['add'],usersDdl(),$delta,$before,null,$previous);
};
$cases['member-verifier-must-match-signed-binding-and-supported-hash']=static function(): void {
    $codec=new Codec(fixtureManifest());
    foreach(['plaintext-password',Codec::DISABLED_PASSWORD,verifier('not-in-manifest')] as $invalid){$rows=userRows();$rows[1]['password']=$invalid;refused(fn()=>$codec->index('users',$rows,usersDdl()));}
    $manifest=fixtureManifest();$rows=userRows();$rows[1]['password']='plaintext-password';
    $manifest['members'][1]['password_binding']=hash('sha256',$rows[1]['password']);$manifest=signFixture($manifest);
    refused(fn()=>(new Codec($manifest))->index('users',$rows,usersDdl()));
    $rows=userRows();$rows[1]['password']=null;refused(fn()=>$codec->index('users',$rows,usersDdl()));
    refused(fn()=>$codec->index('users',userRows(),usersDdl(),['id','name']));
};
$cases['argon-verifier-is-supported-when-runtime-supports-it']=static function(): void {
    if(!defined('PASSWORD_ARGON2ID')||!in_array('argon2id',password_algos(),true))return;
    $manifest=fixtureManifest();$rows=userRows();$rows[1]['password']=password_hash('fixture-argon',PASSWORD_ARGON2ID,['memory_cost'=>1024,'time_cost'=>1,'threads'=>1]);
    $manifest['members'][1]['password_binding']=hash('sha256',$rows[1]['password']);$codec=new Codec(signFixture($manifest));
    $delta=$codec->delta('users',$rows,usersDdl(),$codec->index('users',[],usersDdl()));
    check(array_column($delta['add'],'password','id')[202]===$rows[1]['password'],'A valid Argon2 member verifier was changed.');
};
$cases['nonmember-private-password-randomness-is-not-data-change']=static function(): void {
    $codec=new Codec(fixtureManifest());$one=userRows();$two=$one;$two[2]['password']='unrelated-nonmember-credential';
    check($codec->index('users',$one,usersDdl())===$codec->index('users',$two,usersDdl()),'An unapproved account\'s secret changes entered the data index.');
    $delta=$codec->delta('users',$two,usersDdl(),$codec->index('users',$one,usersDdl()));
    check($delta['add']===[]&&$delta['remove']===[],'A nonmember credential change crossed the network.');
};
$cases['original-financial-sql-canonicalization-is-preserved']=static function(): void {
    $codec=new Codec(fixtureManifest());$one=[['id'=>12,'name'=>'Same','amount'=>'010.500','rating'=>0.5]];
    $two=[['rating'=>'0.5','amount'=>10.5,'name'=>'Same','id'=>'00012']];
    check($codec->index('products',$one,productDdl())===$codec->index('products',$two,productDdl(99999)),
        'Protocol 2 changed original SQL decimal/float/integer or table-counter equivalence.');
    $expected=Legacy::index('products',$one,1,productDdl());$actual=$codec->index('products',$one,productDdl());
    check($actual['hashes']===$expected['hashes']&&$actual['rowset_sha256']===Legacy::tableHash($expected['hashes']),
        'Protocol 2 duplicated or changed original SQL canonical row semantics.');
    $serialize=ini_get('serialize_precision');$precision=ini_get('precision');
    try{ini_set('serialize_precision','3');ini_set('precision','3');$a=$codec->index('products',$one,productDdl());ini_set('serialize_precision','17');ini_set('precision','17');check($a===$codec->index('products',$one,productDdl()),'Protocol 2 depends on PHP display precision.');}
    finally{ini_set('serialize_precision',(string)$serialize);ini_set('precision',(string)$precision);}
};
$cases['ordinary-shared-business-delta-reconstructs-exact-values']=static function(): void {
    $codec=new Codec(fixtureManifest());$one=productRows();$two=[['id'=>1,'name'=>'Updated by B','amount'=>'12.500','rating'=>0.3],['id'=>3,'name'=>'Created by A','amount'=>null,'rating'=>null]];
    $delta=$codec->delta('products',$two,productDdl(),$codec->index('products',$one,productDdl()));
    check(array_sum($delta['remove'])===2&&count($delta['add'])===2,'The shared delta lost an added, removed or changed row.');
    sameRows($codec,'products',$two,$codec->apply('products',$one,productDdl(),$delta),productDdl());
};
$cases['duplicate-row-multiplicities-and-empty-tables-survive']=static function(): void {
    $codec=new Codec(fixtureManifest());$ddl="CREATE TABLE `product_features` (\n  `product_id` bigint unsigned NOT NULL,\n  `feature_id` bigint unsigned NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $a=['product_id'=>12,'feature_id'=>7];$b=['product_id'=>12,'feature_id'=>9];$one=[$a,$a,$b];$two=[$a,$b,$b];
    $delta=$codec->delta('product_features',$two,$ddl,$codec->index('product_features',$one,$ddl));
    check(array_sum($delta['remove'])===1&&count($delta['add'])===1,'Duplicate pivot rows were collapsed.');
    sameRows($codec,'product_features',$two,$codec->apply('product_features',$one,$ddl,$delta),$ddl);
    foreach(['users'=>usersDdl(),'products'=>productDdl()] as $table=>$schema){$empty=$codec->index($table,[],$schema);$delta=$codec->delta($table,[],$schema,$empty);check($codec->apply($table,[],$schema,$delta)===[],'Empty workspace table did not remain empty.');}
    refused(fn()=>$codec->index('users',[],productDdl()));
    refused(fn()=>$codec->index('products',[],usersDdl()));
};
$cases['indexes-are-bound-to-workspace-root-revision-and-roster']=static function(): void {
    $manifest=fixtureManifest();$codec=new Codec($manifest);$index=$codec->index('products',productRows(),productDdl());
    foreach(['workspace_id'=>'22222222-2222-4222-8222-222222222222','root_fingerprint'=>str_repeat('b',64),'revision'=>2] as $key=>$value){
        $other=$manifest;$other[$key]=$value;$otherCodec=new Codec($other);$foreign=$otherCodec->index('products',productRows(),productDdl());
        check($foreign['hashes']===$index['hashes']&&$foreign['table_sha256']!==$index['table_sha256'],'Workspace metadata was not included in the v2 table hash.');
        refused(fn()=>$otherCodec->validateIndex('products',$index));refused(fn()=>$codec->validateIndex('products',$foreign));
    }
    $other=$manifest;$other['members'][1]['grant']=str_repeat('f',64);$other=signFixture($other);$otherCodec=new Codec($other);
    check($otherCodec->index('products',productRows(),productDdl())['table_sha256']!==$index['table_sha256'],'An opaque member grant was not bound through the roster digest.');
    refused(fn()=>$otherCodec->validateIndex('products',$index));
    refused(fn()=>$codec->validateIndex('users',$index));
};
$cases['only-explicit-forward-same-root-transitions-are-allowed']=static function(): void {
    $old=new Codec(fixtureManifest());$next=new Codec(fixtureManifest(2));$rows=productRows();$before=$old->index('products',$rows,productDdl());
    refused(fn()=>$next->delta('products',$rows,productDdl(),$before));
    $delta=$next->delta('products',$rows,productDdl(),$before,null,$old);
    check(($delta['replace']??false)===true&&count($delta['add'])===count($rows)&&array_sum($delta['remove'])===count($rows),'A forward membership transition mixed row hash scopes.');
    $next->applyReplacement('products',$delta['add'],productDdl(),$delta,$before,null,$old);
    refused(fn()=>$old->delta('products',$rows,productDdl(),$next->index('products',$rows,productDdl()),null,$next));
    refused(fn()=>$old->delta('products',$rows,productDdl(),$before,null,$old));
    foreach(['workspace_id'=>'22222222-2222-4222-8222-222222222222','root_fingerprint'=>str_repeat('b',64)] as $key=>$value){$m=fixtureManifest(2);$m[$key]=$value;$foreign=new Codec($m);refused(fn()=>$foreign->delta('products',$rows,productDdl(),$before,null,$old));}
};
$cases['schema-replacement-requires-verified-complete-removal']=static function(): void {
    $codec=new Codec(fixtureManifest());$rows=productRows();$before=$codec->index('products',$rows,productDdl());
    $ddl=str_replace('  PRIMARY KEY',"  `unit` varchar(20) DEFAULT NULL,\n  PRIMARY KEY",productDdl());$target=array_map(fn($r)=>$r+['unit'=>'kg'],$rows);
    $delta=$codec->delta('products',$target,$ddl,$before);check(($delta['replace']??false)===true,'Changed DDL was not replaced.');
    $result=$codec->applyReplacement('products',$delta['add'],$ddl,$delta,$before);sameRows($codec,'products',$target,$result,$ddl);
    $bad=$delta;$bad['remove']=[];refused(fn()=>$codec->applyReplacement('products',$bad['add'],$ddl,$bad,$before));
    refused(fn()=>$codec->apply('products',$rows,productDdl(),$delta));
    $same=$codec->delta('products',$rows,productDdl(),$before);$same['replace']=true;refused(fn()=>$codec->applyReplacement('products',$same['add'],productDdl(),$same,$before));
};
$cases['forward-replacement-strips-excluded-private-projection-fields']=static function(): void {
    $old=new Codec(fixtureManifest());$next=new Codec(fixtureManifest(2));
    $ddl="CREATE TABLE `pending_vendors` (\n  `id` bigint unsigned NOT NULL,\n  `profession_key` varchar(50) NOT NULL,\n  `type` varchar(50) NOT NULL,\n  `status` varchar(50) NOT NULL,\n  `source_app` varchar(50) NOT NULL,\n  `full_name` varchar(100) NOT NULL,\n  `mobile` varchar(50) NOT NULL,\n  `other_field` varchar(100) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $coverage=['pending_vendor_eligibility'=>['kind'=>'go-store-owner-only','source_fields'=>['id','profession_key','type','status','source_app'],'redacted_required_fields'=>['full_name','mobile']]];
    $projection=Legacy::projection('pending_vendors',$coverage);
    $beforeRows=[['id'=>1,'profession_key'=>'fixture','type'=>'fixture','status'=>'accepted','source_app'=>'fixture','full_name'=>'PRIVATE previous name','mobile'=>'PRIVATE previous number','other_field'=>'PRIVATE previous field']];
    $target=[['id'=>1,'profession_key'=>'fixture','type'=>'fixture','status'=>'accepted','source_app'=>'fixture','full_name'=>'','mobile'=>'']];
    $before=$old->index('pending_vendors',$beforeRows,$ddl);
    $delta=$next->delta('pending_vendors',$target,$ddl,$before,$projection,$old);
    $delta['add'][0]['other_field']='PRIVATE forged field outside projection';
    $result=$next->applyReplacement('pending_vendors',$delta['add'],$ddl,$delta,$before,$projection,$old);
    check($result===$target&&!str_contains(json_encode($result),'PRIVATE'),'A field excluded from the authenticated projection survived workspace replacement.');
};
$cases['malformed-or-cross-scope-deltas-cannot-be-applied']=static function(): void {
    $codec=new Codec(fixtureManifest());$one=productRows();$two=$one;$two[0]['amount']='11.500';$delta=$codec->delta('products',$two,productDdl(),$codec->index('products',$one,productDdl()));
    foreach(['kind'=>'dashboard-data-delta','format'=>1,'revision'=>2,'workspace_id'=>'22222222-2222-4222-8222-222222222222',
        'root_fingerprint'=>str_repeat('b',64),'before_sha256'=>str_repeat('b',64),'count'=>99,'rowset_sha256'=>str_repeat('b',64),'unknown'=>true] as $key=>$value){$bad=$delta;$bad[$key]=$value;refused(fn()=>$codec->apply('products',$one,productDdl(),$bad));}
    $bad=$delta;$bad['remove'][str_repeat('0',64)]=1;refused(fn()=>$codec->apply('products',$one,productDdl(),$bad));
    $bad=$codec->index('products',$one,productDdl());$bad['hashes'][str_repeat('0',64)]=1;refused(fn()=>$codec->validateIndex('products',$bad));
    $bad=$codec->index('products',$one,productDdl());$bad['count']=true;refused(fn()=>$codec->validateIndex('products',$bad));
};
$cases['incoming-nonmember-password-payload-is-refused-not-silently-stored']=static function(): void {
    $codec=new Codec(fixtureManifest());$delta=$codec->delta('users',userRows(),usersDdl(),$codec->index('users',[],usersDdl()));
    foreach($delta['add'] as &$row)if((int)$row['id']===303)$row['password']='forged-hidden-nonmember-password';unset($row);
    refused(fn()=>$codec->apply('users',[],usersDdl(),$delta));
};
$cases['manifest-schema-membership-and-roster-integrity-are-checked']=static function(): void {
    $base=fixtureManifest();
    foreach(['format'=>1,'kind'=>'initial-dashboard-data','revision'=>0,'signature'=>'invalid','roster_sha256'=>str_repeat('b',64),'members'=>[],'unknown'=>true] as $key=>$value){$bad=$base;$bad[$key]=$value;refused(fn()=>new Codec($bad));}
    foreach(['enabled'=>false,'role'=>'consumer','password_binding'=>'invalid','actor_id'=>0,'aliases'=>['z','a']] as $key=>$value){$bad=$base;$bad['members'][1][$key]=$value;refused(fn()=>new Codec(signFixture($bad)));}
    $bad=$base;$bad['members']=array_reverse($bad['members']);refused(fn()=>new Codec(signFixture($bad)));
    $bad=$base;$bad['members'][]=$bad['members'][1];refused(fn()=>new Codec(signFixture($bad)));
    $bad=$base;$bad['revision']=1.0;refused(fn()=>new Codec($bad));
    $bad=$base;$bad['members'][1]['branches']=['f:9999999999999999'];refused(fn()=>new Codec(signFixture($bad)));
};
$cases['unicode-field-budgets-match-native-manifest-validation']=static function(): void {
    $manifest=fixtureManifest();$manifest['members'][1]['aliases']=[str_repeat('ب',200)];
    $manifest['members'][1]['permissions']['roles']=[str_repeat('م',200)];$manifest=signFixture($manifest);
    $codec=new Codec($manifest);$codec->index('products',productRows(),productDdl());
    $manifest['members'][1]['aliases']=[str_repeat('😀',160)];new Codec(signFixture($manifest));
    $manifest['members'][1]['aliases']=[str_repeat('😀',161)];refused(fn()=>new Codec(signFixture($manifest)));
};
$cases['unicode-line-separator-canonicalization-matches-javascript']=static function(): void {
    require_once __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
    $manifest=fixtureManifest();$manifest['members'][1]['aliases']=["a\u{2028}b\u{2029}c"];
    $manifest=signFixture($manifest);$codec=new Codec($manifest);
    check($codec->context()['roster_sha256']===hash('sha256',\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::canonical($manifest['members'])),
        'PHP codec and native JSON line-terminator encodings differ.');
};
$cases['mixed-member-and-nonmember-round-trips-preserve-multisets']=static function(): void {
    global $hashC;$codec=new Codec(fixtureManifest());mt_srand(20261027);
    for($round=0;$round<50;$round++){
        $before=userRows();$after=$before;
        for($id=1000;$id<1012;$id++)$before[]=['id'=>$id,'name'=>'Customer '.$id,'password'=>'private-before-'.$id];
        $after=[];
        foreach($before as $row){
            if((int)$row['id']>500&&mt_rand(0,2)===0)continue;
            if(mt_rand(0,2)===0)$row['name'].=' changed '.$round;
            if((int)$row['id']>500)$row['password']='private-after-'.$round.'-'.$row['id'];
            if(mt_rand(0,1)===0)$row['id']=str_pad((string)$row['id'],8,'0',STR_PAD_LEFT);
            $after[]=$row;
        }
        $after[]=['id'=>2000+$round,'name'=>'New customer','password'=>'private-added-'.$round];shuffle($before);shuffle($after);
        $delta=$codec->delta('users',$after,usersDdl(),$codec->index('users',$before,usersDdl()));
        $result=$codec->apply('users',$before,usersDdl(),$delta);sameRows($codec,'users',$after,$result,usersDdl());
        $wire=json_encode($delta);check(!str_contains($wire,'private-before-')&&!str_contains($wire,'private-after-')
            &&!str_contains($wire,'private-added-')&&!str_contains($wire,$hashC),'A mixed round trip transported an unapproved credential.');
    }
};
$cases['shared-canonical-fixture-agrees-with-server-authority']=static function(): void {
    require_once __DIR__.'/../app/Services/Dashboard/DesktopDashboardWorkspaceAuthority.php';
    $fixture=json_decode(file_get_contents(__DIR__.'/fixtures/desktop-workspace-delta-codec-v2.json'),true,512,JSON_THROW_ON_ERROR);
    check($fixture['format']===1&&$fixture['scope']==='synthetic-no-production-signature','A synthetic fixture was mislabeled as authenticated production authority.');
    $manifest=$fixture['manifest'];
    check(hash('sha256',\App\Services\Dashboard\DesktopDashboardWorkspaceAuthority::canonical($manifest['members']))===$manifest['roster_sha256'],
        'Workspace codec and server authority canonicalization disagree.');
    $codec=new Codec($manifest);
    check($codec->index('users',$fixture['users'],$fixture['users_ddl'])===$fixture['users_index'],
        'The cross-language workspace fixture table binding changed.');
    $native=json_decode(file_get_contents(__DIR__.'/../desktop-pos/test/fixtures/workspace-members-v2.json'),true,512,JSON_THROW_ON_ERROR);
    $nativeCodec=new Codec($native['manifest']);
    check($nativeCodec->context()['roster_sha256']===$native['members_sha256'],
        'The actual native manifest fixture differs from the workspace codec contract.');
};
$cases['original-codec-remains-unchanged-and-rejects-v2-envelopes']=static function(): void {
    $codec=new Codec(fixtureManifest());$index=$codec->index('products',productRows(),productDdl());
    refused(fn()=>Legacy::validateIndex($index));
    $old=Legacy::index('products',productRows(),1,productDdl());refused(fn()=>$codec->validateIndex('products',$old));
    $path=__DIR__.'/../app/Services/Dashboard/DesktopDashboardDeltaCodec.php';
    check(hash_file('sha256',$path)==='db87917ac1861c245027c4adc735889e99ad8edec1c9c3415ab1343de36c222b',
        'The original codec changed instead of remaining an independent dependency.');
};

$checks=0;
foreach($cases as $name=>$case){$case();$checks++;echo 'PASS workspace-delta '.$name.PHP_EOL;}
echo 'Workspace delta codec: '.$checks.' synthetic cases passed; no database, network or production authentication used.'.PHP_EOL;
