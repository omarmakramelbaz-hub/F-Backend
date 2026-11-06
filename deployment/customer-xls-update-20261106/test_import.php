<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/tests/owner_mobile_runtime/vendor/autoload.php';
define('LEGACY_CUSTOMER_IMPORT_LIBRARY',true);
define('CUSTOMER_XLS_UNPACK_LIBRARY',true);
require __DIR__.'/import_customers.php';
require __DIR__.'/unpack_customers.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\{DB,Schema};

function expect_xls(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
putenv('LEGACY_CUSTOMER_IMPORT_TESTING=1');
$app=new Container;Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('config',new Illuminate\Config\Repository);
$app->instance('date',new Illuminate\Support\DateFactory);
$capsule=new Capsule($app);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$capsule->setAsGlobal();$app->instance('db',$capsule->getDatabaseManager());
$app->bind('db.schema',fn()=>$capsule->getConnection()->getSchemaBuilder());
$app->instance(Illuminate\Contracts\Console\Kernel::class,new class{public function bootstrap(): void {}});
$GLOBALS['xls_test_app']=$app;
$temp=sys_get_temp_dir().'/xls-import-test-'.bin2hex(random_bytes(6));mkdir($temp,0700);
$root=$temp.'/app';mkdir($root);mkdir($root.'/bootstrap');mkdir($root.'/vendor');
file_put_contents($root.'/artisan','fixture');
file_put_contents($root.'/vendor/autoload.php','<?php require '.var_export(dirname(__DIR__,2).'/tests/owner_mobile_runtime/vendor/autoload.php',true).';');
file_put_contents($root.'/bootstrap/app.php','<?php return $GLOBALS["xls_test_app"];');
Schema::create('crm_customers',function(Blueprint $t){
    $t->bigIncrements('id');$t->string('phone',20)->unique();$t->string('phone_key',20);
    foreach(['name','address','area','delivery_notes','service_consent_evidence','marketing_consent_evidence'] as $c)$t->text($c)->nullable();
    foreach(['latitude','longitude'] as $c)$t->decimal($c,12,7)->nullable();
    foreach(['service_consent','marketing_consent'] as $c)$t->boolean($c)->default(false);
    foreach(['service_consented_by','marketing_consented_by'] as $c)$t->unsignedBigInteger($c)->nullable();
    foreach(['service_consented_at','marketing_consented_at','opted_out_at','first_seen_at','last_seen_at','created_at','updated_at'] as $c)$t->timestamp($c)->nullable();
});
Schema::create('crm_customer_sources',function(Blueprint $t){
    $t->bigIncrements('id');$t->unsignedBigInteger('customer_id');$t->string('branch',30);$t->string('channel',20);
    foreach(['first_seen_at','last_seen_at','created_at','updated_at'] as $c)$t->timestamp($c)->nullable();
    $t->unique(['customer_id','branch','channel']);
});

$headers=['code','name','phone1','phone2','phone3','phone4','building','street','floor','flat','notes','area'];
function packed_xls(array $rows,array $phones): array {
    global $headers;
    return ['format'=>1,'source'=>['backup_sha256'=>str_repeat('a',64),'database'=>'fixture','backup_finished_at'=>'unknown'],
        'headers'=>$headers,'rows'=>$rows,'phones'=>$phones];
}
function row_xls(string $name,string $phone,string $address='',string $notes=''): array {
    return ['7',$name,$phone,'','','',$address,'','','',$notes,''];
}
function run_xls(string $data,bool $apply): array {
    global $root,$temp;
    $path=$temp.'/data-'.hash('sha256',$data).'.json';file_put_contents($path,$data);
    $args=['import','--root',$root,'--data',$path,'--expected-data-sha256',hash('sha256',$data)];
    if($apply)$args=array_merge($args,['--apply','--backup-dir',$temp.'/backups']);else $args[]='--dry-run';
    ob_start();$code=lci_main($args);$output=ob_get_clean();
    return [$code,json_decode(trim($output),true)];
}
$id=DB::table('crm_customers')->insertGetId(['phone'=>'01000000001','phone_key'=>'01000000001','name'=>'قديم','address'=>'عنوان قديم',
    'service_consent'=>true,'marketing_consent'=>true,'service_consent_evidence'=>'retained','marketing_consent_evidence'=>'retained']);
DB::table('crm_customers')->insert(['phone'=>'+201000000002','phone_key'=>'01000000002','name'=>'بيانات محفوظة','address'=>'عنوان محفوظ']);
$packed=packed_xls([row_xls('اسم محدث','01000000001','عنوان محدث',"\u{00a0}ملاحظة\u{00a0}"),
    row_xls('','01000000002'),row_xls('عميل جديد','01000000003')],
    [['+201000000001',[]],['+201000000002',[]],['+201000000003',[]]]);
$doc=xls_expand($packed);$data=lci_json($doc);
expect_xls($doc['profiles'][0]['delivery_notes']==='ملاحظة','Unicode whitespace must match extraction');
expect_xls(count(array_unique(array_column($doc['customers'],'source_id')))===3,'Repeated source codes must remain distinct rows');
[$code,$check]=run_xls($data,false);
expect_xls($code===0&&$check['counts']['crm_insert']===1&&$check['counts']['crm_update']===1,'Dry run must match normalized phones and project updates');
expect_xls(!Schema::hasTable(LCI_TABLES[0])&&DB::table('crm_customers')->count()===2,'Dry run must not write schema or customers');
[$code,$result]=run_xls($data,true);
expect_xls($code===0&&$result['status']==='applied','First application must succeed');
expect_xls($result['written']['crm_created']===1&&$result['written']['crm_updated']===1,'Actual counts must match');
$saved=DB::table('crm_customers')->where('id',$id)->first();
expect_xls($saved->name==='اسم محدث'&&$saved->address==='عنوان محدث'&&$saved->phone==='01000000001','Updates must keep the existing CRM identity');
expect_xls((bool)$saved->service_consent&&(bool)$saved->marketing_consent&&$saved->service_consent_evidence==='retained','Existing messaging consent must be preserved');
expect_xls(DB::table('crm_customers')->where('phone','+201000000002')->value('name')==='بيانات محفوظة','Blank XLS fields must not erase saved data');
$new=DB::table('crm_customers')->where('phone','+201000000003')->first();
expect_xls(!(bool)$new->service_consent&&!(bool)$new->marketing_consent,'New contacts must not receive fabricated consent');
expect_xls(is_file($result['backup_directory'].'/before.json'),'A private before-snapshot is required');
expect_xls(DB::table(LCI_TABLES[1])->count()===3&&DB::table(LCI_TABLES[2])->count()===3,'Every raw row and phone profile must be retained');
[$code,$repeat]=run_xls($data,true);
expect_xls($code===0&&$repeat['status']==='already_imported'&&DB::table('crm_customers')->count()===3,'Replays must not duplicate customers');

$conflict=lci_json(xls_expand(packed_xls([row_xls('ألف','01000000004'),row_xls('باء','01000000004')],[['+201000000004',[]],['+201000000004',[]]])));
[$code,$result]=run_xls($conflict,true);
expect_xls($code===0&&$result['counts']['conflicting_phones']===1,'Conflicting source names must be reported');
expect_xls(DB::table('crm_customers')->where('phone','+201000000004')->value('name')===null,'Conflicting names must not overwrite an identity');

$before=[DB::table('crm_customers')->count(),DB::table(LCI_TABLES[0])->count(),DB::table(LCI_TABLES[1])->count()];
DB::unprepared("CREATE TRIGGER fail_import BEFORE INSERT ON legacy_customer_import_profiles WHEN NEW.phone='+201000000005' BEGIN SELECT RAISE(ABORT,'fixture'); END");
$failing=lci_json(xls_expand(packed_xls([row_xls('معاملة تفشل','01000000005')],[['+201000000005',[]]])));
[$code]=run_xls($failing,true);
expect_xls($code===1&&$before===[DB::table('crm_customers')->count(),DB::table(LCI_TABLES[0])->count(),DB::table(LCI_TABLES[1])->count()],'A failure must roll back the complete data transaction');
DB::unprepared('DROP TRIGGER fail_import');
DB::table('crm_customers')->insert(['phone'=>'+201000000001','phone_key'=>'01000000001']);
[$code]=run_xls($data,false);
expect_xls($code===1,'Existing duplicate canonical identities must block changes');
echo "CUSTOMER_XLS_TRANSACTION_TESTS_PASSED\n";
