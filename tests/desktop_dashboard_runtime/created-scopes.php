<?php
// Real Laravel against disposable databases with all 106 inspected production table shapes.
use Illuminate\Support\Facades\{DB,Schema};
use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardBootstrap,DesktopDashboardImport};

$application=realpath($argv[1]);$port=(int)$argv[2];$profile=sys_get_temp_dir().'/desktop-legacy-'.bin2hex(random_bytes(8));
foreach(['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_legacy_test_'.bin2hex(random_bytes(8));$stage='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach([$database,$stage] as $name)$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($pdo,$database,$stage){foreach([$database,$stage] as $name)$pdo->exec('DROP DATABASE IF EXISTS `'.$name.'`');});
$fixtureCompleted=false;register_shutdown_function(function()use(&$fixtureCompleted){if(!$fixtureCompleted){fwrite(STDERR,'Original legacy fixture did not complete.'.PHP_EOL);exit(1);}});
$key='base64:'.base64_encode(random_bytes(32));
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:43144',
    'APP_KEY'=>$key,'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $name=>$value){putenv($name.'='.$value);$_ENV[$name]=$value;$_SERVER[$name]=$value;}
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$count=0;function verify($condition,$message){global $count;if(!$condition)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
require __DIR__.'/full-schema.php';
$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);
DesktopFullSchemaFixture::create($report['tables']);
require $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';(new CreateDesktopDashboardJournal)->up();
require $application.'/database/migrations/2026_10_20_000002_create_desktop_dashboard_created_scopes.php';(new CreateDesktopDashboardCreatedScopes)->up();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('users')->insert([
    ['id'=>1,'name'=>'الأونر','email'=>'owner@test.invalid','mobile'=>'1000000000','password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'admin','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0],
    ['id'=>10,'name'=>'مدير فرع','email'=>'branch@test.invalid','mobile'=>'1012345678','password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'vendor','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0],
    ['id'=>11,'name'=>'فرع آخر','email'=>'other@test.invalid','mobile'=>'1099999999','password'=>password_hash('Other123',PASSWORD_BCRYPT),'account_type'=>'vendor','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0],
    ['id'=>20,'name'=>'عميل الفرع','email'=>'customer@test.invalid','mobile'=>'1112345678','password'=>password_hash('Customer123',PASSWORD_BCRYPT),'account_type'=>'user','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>100],
    ['id'=>21,'name'=>'عميل غير مرتبط','email'=>'foreign@test.invalid','mobile'=>'1199999999','password'=>password_hash('Foreign123',PASSWORD_BCRYPT),'account_type'=>'user','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>100],
]);
DB::table('users')->update(['added_by'=>1,'created_at'=>'2020-01-01 00:00:00']);
DB::table('users')->where('id',10)->update(['partner_auth_email'=>'hidden-account@test.invalid']);
foreach([85001,85002,85003] as $contactId)DB::table('contacts')->insert(['id'=>$contactId,'user_id'=>20,'name'=>'رسالة اختبار المزامنة '.$contactId,'email'=>'contact@test.invalid','message'=>'محتوى رسالة التواصل '.$contactId]);
DB::table('resturants')->insert([['id'=>100,'added_by'=>1,'user_id'=>10,'name'=>'الفرع الأول','status'=>'opened','address'=>'المنصورة'],['id'=>101,'added_by'=>1,'user_id'=>11,'name'=>'الفرع الثاني','status'=>'opened','address'=>'المحلة']]);
DB::table('categories')->insert(['id'=>1,'added_by'=>1,'name_ar'=>'رنجة','name_en'=>'Herring','status'=>'show','order'=>1]);
DB::table('products')->insert(['id'=>1,'added_by'=>1,'category_id'=>1,'name_ar'=>'رنجة سمينة','name_en'=>'Herring','status'=>'show']);
foreach(['categories','products'] as $table)DB::table($table)->update(['created_at'=>now(),'updated_at'=>now()]);
DB::table('resturant_products')->insert(['id'=>1,'added_by'=>1,'resturant_id'=>100,'product_id'=>1,'category_id'=>1,'product_name'=>'رنجة سمينة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
DB::table('orders')->insert([['id'=>1,'user_id'=>20,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'cash'],['id'=>2,'user_id'=>21,'resturant_id'=>101,'type'=>'current','status'=>'pending','payment_type'=>'cash']]);
DB::table('carts')->insert(['id'=>1,'order_id'=>1,'user_id'=>20,'resturant_id'=>100,'resturant_product_id'=>1,'is_order'=>true,'price'=>'100.00','qty'=>1]);
DB::table('wallets')->insert(['id'=>1,'from_user'=>20,'to_user'=>10,'type'=>'transfer','amount'=>10,'payment'=>'wallet','status'=>'completed','order_id'=>1]);
DB::table('go_store_orders')->insert(['id'=>1,'store_id'=>999,'customer_id'=>21,'snapshot'=>'{}','checkout_secret'=>'never-export-this-secret']);
DB::table('order_board_clocks')->insert([['source'=>'legacy','order_id'=>1,'accepted_at'=>now()->addDay()],['source'=>'store','order_id'=>1,'accepted_at'=>now()->addDay()]]);
DB::table('social_accounts')->insert(['user_id'=>20,'provider'=>'test','provider_user_id'=>'private-oauth-id']);
DB::table('user_tokens')->insert(['user_id'=>20,'token'=>'private-session-token']);
foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
    $value=$property->getName()==='site_name'?'فسخانستا':($property->getName()==='app_balance'?'999999':($property->getType()?->getName()==='bool'?true:'0'));
    DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($value,JSON_UNESCAPED_UNICODE)]);
}
DB::table('settings')->insert(['group'=>'private','name'=>'service_account','locked'=>true,'payload'=>'{"private_key":"never-export-settings-secret"}']);
DB::statement('SET FOREIGN_KEY_CHECKS=1');config(['desktop_dashboard.enabled'=>true]);
$id=(string)\Illuminate\Support\Str::uuid();
$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
$origin='http://127.0.0.1:'.$httpPort;$browserToken=bin2hex(random_bytes(32));
$env=getenv();$env['DB_DATABASE']=$stage;$env['APP_URL']=$origin;$env['DESKTOP_DASHBOARD_DEVICE_ID']=$id;
$env['DESKTOP_DASHBOARD_ORIGIN']=$origin;$env['DESKTOP_DASHBOARD_TOKEN']=$browserToken;$env['DESKTOP_DASHBOARD_CONTROL_TOKEN']=bin2hex(random_bytes(32));
config(['database.connections.mysql.database'=>$database]);DB::purge();
$owner=User::withoutGlobalScopes()->findOrFail(1);
$role=\Spatie\Permission\Models\Role::create(['name'=>'Super Admin','guard_name'=>'admin']);
foreach(['category-list','category-create','category-edit','category-delete','product-list','product-create','product-edit','product-delete','areas-list','areas-create','areas-edit','areas-delete','question_answer-list','question_answer-create','question_answer-edit','question_answer-delete','contact-list','contact-delete','feature-list','feature-create','feature-edit','feature-delete','contract-list','contract-edit','contract-delete','role-list','role-create','role-edit','role-delete'] as $permission)$role->givePermissionTo(\Spatie\Permission\Models\Permission::create(['name'=>$permission,'guard_name'=>'admin']));
DB::table('contracts')->insert([['id'=>87001,'added_by'=>1,'type'=>'vendor','template'=>'قالب مورّد مشترك'],['id'=>87002,'added_by'=>1,'type'=>'delegate','template'=>'قالب مندوب مشترك']]);
$contractManager=User::withoutGlobalScopes()->create(['id'=>30,'added_by'=>1,'name'=>'مدير القوالب','email'=>'template-manager@test.invalid','mobile'=>'1200000030','password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'admin','status'=>'accepted','app_scope'=>'fasakhansta']);
$contractRole=\Spatie\Permission\Models\Role::create(['name'=>'Template Manager','guard_name'=>'admin']);$contractRole->givePermissionTo('contract-edit');$contractManager->assignRole($contractRole);
$browserBulkRoleIds=[];$snapshotBulkRoleIds=[];
foreach(['Browser Bulk Role A','Browser Bulk Role B','Snapshot Bulk Role A','Snapshot Bulk Role B'] as $index=>$name){
    $bulkFixtureRole=\Spatie\Permission\Models\Role::create(['name'=>$name,'guard_name'=>'admin']);
    $bulkFixtureRole->givePermissionTo($index%2===0?'product-list':'category-list');
    if($index%2===0)User::withoutGlobalScopes()->findOrFail(21)->assignRole($bulkFixtureRole);
    if($index<2)$browserBulkRoleIds[]=(int)$bulkFixtureRole->id;else $snapshotBulkRoleIds[]=(int)$bulkFixtureRole->id;
}
$contractQueries=app(\App\Services\Dashboard\DesktopDashboardData::class)->queries((object)[],$contractManager,['f:100']);
verify(!app(\App\Services\Dashboard\DesktopDashboardData::class)->allAdministration($contractManager)&&$contractQueries['contracts']->count()===2,'a central contract editor receives its original shared templates without requiring global Super Admin authority');
$contractRole->revokePermissionTo('contract-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$contractManager=User::withoutGlobalScopes()->findOrFail(30);$contractQueries=app(\App\Services\Dashboard\DesktopDashboardData::class)->queries((object)[],$contractManager,['f:100']);
verify($contractQueries['contracts']->count()===0,'revoking the original contract read/edit authority removes templates from another initial preparation');
$branchQueries=app(\App\Services\Dashboard\DesktopDashboardData::class)->queries((object)[],User::withoutGlobalScopes()->findOrFail(10),['f:100']);
verify($branchQueries['contracts']->count()===0,'shared contract templates remain outside an ordinary branch account snapshot');
$owner->assignRole($role);app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
foreach([60,61] as $storeOwner){
    DB::table('users')->insert(['id'=>$storeOwner,'added_by'=>1,'name'=>'مالك متجر اختبار '.$storeOwner,'email'=>'store'.$storeOwner.'@test.invalid','mobile'=>'12000000'.$storeOwner,
        'password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'vendor','status'=>'accepted','app_scope'=>'go_partner','balance'=>0]);
    DB::table('go_stores')->insert(['user_id'=>$storeOwner,'name'=>'متجر اختبار '.$storeOwner,'kind'=>'grocery','address'=>'عنوان الاختبار','revision'=>1]);
    DB::table('go_store_products')->insert(['id'=>$storeOwner===60?1:2,'user_id'=>$storeOwner,'name'=>'صنف متجر '.$storeOwner,'price_cents'=>10000,'available'=>true,'revision'=>1,'options'=>'[]']);
}
DB::table('resturant_products')->insert(['id'=>2,'added_by'=>1,'resturant_id'=>101,'product_id'=>1,'category_id'=>1,'product_name'=>'صنف الفرع الآخر','product_price'=>'100.00','status'=>'show','price'=>'{}']);
$browserNotificationId=(string)\Illuminate\Support\Str::uuid();DB::table('notifications')->insert(['id'=>$browserNotificationId,'type'=>'FixtureNotification',
    'notifiable_type'=>User::class,'notifiable_id'=>1,'data'=>json_encode(['title'=>'إشعار اختبار المتصفح','text'=>'نص سجل الإشعارات']),
    'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$ownerDevice=(string)\Illuminate\Support\Str::uuid();$ownerLink=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$ownerDevice,'name'=>'owner fixture','nonce'=>bin2hex(random_bytes(32))],$owner);
$ownerSnapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($ownerLink['token']));
verify(count($ownerSnapshot['tables']['contacts']['rows'])===3,'the primary administrator preparation imports its original global contact messages');
$ownerStage='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$ownerStage.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$ownerStage.'`'));
$localProfileKey='base64:'.base64_encode(random_bytes(32));config(['app.key'=>$localProfileKey]);app()->forgetInstance('encrypter');\Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.device_id'=>$ownerDevice]);DB::purge();$initialImported=app(DesktopDashboardImport::class)->import($ownerSnapshot);
$env['DB_DATABASE']=$ownerStage;$env['DESKTOP_DASHBOARD_DEVICE_ID']=$ownerDevice;$cookies=[];
config(['app.key'=>$key]);app()->forgetInstance('encrypter');\Illuminate\Support\Facades\Crypt::clearResolvedInstance('encrypter');
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();$remoteDevice=app(DesktopDashboardDevices::class)->device($ownerLink['token']);
require __DIR__.'/created-scopes-component.php';
$fixtureCompleted=true;
echo 'CREATED_CATALOG_SCOPE_CHECKS='.$count.PHP_EOL;
