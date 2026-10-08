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
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $name=>$value)putenv($name.'='.$value);
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
$count=0;function verify($condition,$message){global $count;if(!$condition)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
require __DIR__.'/full-schema.php';
$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'),true,512,JSON_THROW_ON_ERROR);
DesktopFullSchemaFixture::create($report['tables']);
require $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';(new CreateDesktopDashboardJournal)->up();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('users')->insert([
    ['id'=>1,'name'=>'الأونر','email'=>'owner@test.invalid','mobile'=>'1000000000','password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'admin','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0],
    ['id'=>10,'name'=>'مدير فرع','email'=>'branch@test.invalid','mobile'=>'1012345678','password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>'vendor','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0],
    ['id'=>11,'name'=>'فرع آخر','email'=>'other@test.invalid','mobile'=>'1099999999','password'=>password_hash('Other123',PASSWORD_BCRYPT),'account_type'=>'vendor','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0],
    ['id'=>20,'name'=>'عميل الفرع','email'=>'customer@test.invalid','mobile'=>'1112345678','password'=>password_hash('Customer123',PASSWORD_BCRYPT),'account_type'=>'user','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>100],
    ['id'=>21,'name'=>'عميل غير مرتبط','email'=>'foreign@test.invalid','mobile'=>'1199999999','password'=>password_hash('Foreign123',PASSWORD_BCRYPT),'account_type'=>'user','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>100],
]);
DB::table('users')->update(['added_by'=>1]);
DB::table('users')->where('id',10)->update(['partner_auth_email'=>'hidden-account@test.invalid']);
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
$actor=User::withoutGlobalScopes()->findOrFail(10);$id=(string)\Illuminate\Support\Str::uuid();
$link=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$id,'name'=>'اختبار مخطط السيرفر','nonce'=>bin2hex(random_bytes(32))],$actor);
require __DIR__.'/media.php';
$snapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($link['token']));
verify(count($snapshot['tables'])===106,'all 106 inspected schemas are available for original dashboard queries');
$userIds=array_column($snapshot['tables']['users']['rows'],'id');sort($userIds);
verify($userIds===[1,10,20],'foreign-key closure adds the branch customer and creator without unrelated customer accounts');
verify(count($snapshot['tables']['orders']['rows'])===1&&count($snapshot['tables']['carts']['rows'])===1,'legacy app orders and cart rows stay inside the account branch');
verify(count($snapshot['tables']['order_board_clocks']['rows'])===1 && $snapshot['tables']['order_board_clocks']['rows'][0]['source']==='legacy','colliding application order IDs cannot leak another source clock');
verify($snapshot['tables']['social_accounts']['rows']===[]&&$snapshot['tables']['user_tokens']['rows']===[],'OAuth and application session rows are never exported');
verify(!str_contains(DesktopDashboardBootstrap::json($snapshot),'hidden-account@test.invalid'),'hidden partner login aliases are excluded even for the enrolled account');
verify(!str_contains(DesktopDashboardBootstrap::json($snapshot),'never-export')&&count($snapshot['tables']['settings']['rows'])===count((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC)),'private settings and payment checkout secrets are excluded');
verify(collect($snapshot['tables']['settings']['rows'])->firstWhere('name','app_balance')['payload']==='"0"','a branch dataset excludes the platform owner balance');
config(['database.connections.mysql.database'=>$stage,'desktop_dashboard.device_id'=>$id]);DB::purge();
$imported=app(DesktopDashboardImport::class)->import($snapshot);
verify(app(DesktopDashboardImport::class)->verify($imported)['verified'],'an independent staging verification hashes the actual scoped local images');
$shortened=$imported;$shortened['media']=[];
rejectMedia(fn()=>app(DesktopDashboardImport::class)->verify($shortened),409,'a modified receipt cannot drop required images from the persisted snapshot manifest');
verify($imported['tables']===106&&DB::table('users')->count()===3,'the full inspected dataset imports with legacy cyclic foreign keys intact');
verify(app(\App\Models\GeneralSettings::class)->site_name==='فسخانستا','the original settings class resolves from imported safe settings');
$reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation,false),':'),1);fclose($reservation);
$origin='http://127.0.0.1:'.$httpPort;$browserToken=bin2hex(random_bytes(32));
$env=getenv();$env['DB_DATABASE']=$stage;$env['APP_URL']=$origin;$env['DESKTOP_DASHBOARD_DEVICE_ID']=$id;
$env['DESKTOP_DASHBOARD_ORIGIN']=$origin;$env['DESKTOP_DASHBOARD_TOKEN']=$browserToken;$env['DESKTOP_DASHBOARD_CONTROL_TOKEN']=bin2hex(random_bytes(32));
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
$cookies=[];
$http=function(string $path,?array $form=null,array $extraHeaders=[],?string $method=null)use($origin,$browserToken,&$cookies){
    $headers=['X-Fasakhansta-Desktop: '.$browserToken,...$extraHeaders];
    if($cookies)$headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $context=stream_context_create(['http'=>['method'=>$method??($form===null?'GET':'POST'),'header'=>implode("\r\n",$headers),'content'=>$form===null?'':http_build_query($form),'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
    $body=@file_get_contents($origin.$path,false,$context);$responseHeaders=$http_response_header??[];
    preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'',$status);
    foreach($responseHeaders as $header)if(preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match))$cookies[$match[1]]=$match[2];
    return [(int)($status[1]??0),$body,$responseHeaders];
};
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/login');
    verify($status===200&&str_contains($page,'dashboard-login-form')&&str_contains($page,'فسخانستا'),'the original branded login Blade page renders over the private local HTTP gateway');
    verify($http('/admin/branch-expenses/'.$expenseAttachmentId.'/attachment')[0]===302,
        'the private expense download retains the original signed-in dashboard requirement');
    preg_match('/name="_token" value="([^"]+)"/',$page,$token);verify(!empty($token[1]),'the original offline login retains Laravel CSRF protection');
    [$status,$page,$headers]=$http('/admin/signin',['_token'=>$token[1],'email'=>'branch@test.invalid','password'=>'Fixture123']);
    verify($status===302&&count(array_filter($headers,fn($header)=>str_contains($header,'/admin/applies-orders')))===1,'the imported enrolled account signs in through the unchanged original login controller');
    foreach(['/admin/dashboard','/admin/takeaway','/admin/phone-orders','/admin/dining','/admin/branch-stock','/admin/employees','/admin/branch-expenses'] as $path){
        [$status,$page]=$http($path);
        if($status!==200)fwrite(STDERR,file_get_contents($profile.'/logs/laravel.log')?:file_get_contents($profile.'/web.log'));
        verify($status===200&&str_contains($page,'dashboard-brand.css')&&str_contains($page,'dashboard-spa.js'),'original local dashboard page renders: '.$path);
    }
    verify($http('/dashboard/branding/dashboard-brand.css')[0]===200&&$http('/dashboard/js/dashboard-spa.js')[0]===200,'the original dashboard style and navigation scripts are served locally');
    [$imageStatus,$localImage]=$http('/storage/products/3/'.rawurlencode('رنجة.png'));
    verify($imageStatus===200&&$localImage===$imageBytes,'the protected original local HTTP gateway serves the downloaded Arabic product image');
    [$attachmentStatus,$attachmentBody,$attachmentHeaders]=$http('/admin/branch-expenses/'.$expenseAttachmentId.'/attachment');
    verify($attachmentStatus===200&&$attachmentBody===$expensePdfBytes&&in_array('Content-Type: application/pdf',$attachmentHeaders,true),
        'the original signed-in expense controller reads the prepared PDF from its private local disk');
    verify($http('/storage/'.$expenseAttachmentPath)[0]===404,
        'the original public storage URL cannot expose the private expense PDF');
    verify(str_ends_with(\App\Models\Resturant::findOrFail(100)->getFirstMediaUrl('logo'),'/storage/resturants/1/logo.png'),
        'the original media library retains its model rows and generates the correct nested local disk URL');
    [$status,$page]=$http('/admin/takeaway');preg_match('/name="csrf-token" content="([^"]+)"/',$page,$csrf);
    [$status,$quote]=$http('/admin/takeaway/quote',['_token'=>$csrf[1],'branch'=>'f:100','items'=>[['product_id'=>1,'quantity_mode'=>'weight','quantity'=>'0.250']],'discount'=>'0.00','payment_method'=>'cash'],['Accept: application/json']);
    verify($status===200&&isset(json_decode($quote,true)['quote_hash']),'original POST price calculation works locally without creating an outbox entry');
    verify($http('/admin/categorys',['_token'=>$csrf[1],'_desktop_command'=>(string)\Illuminate\Support\Str::uuid(),'added_by'=>10,'name_ar'=>'قسم ممنوع','name_en'=>'Forbidden','status'=>'show'])[0]===403&&DB::table('desktop_dashboard_commands')->count()===0,'a branch account cannot acquire global catalog administration offline');
    $control=DB::table('resturants')->where('id',100)->value('control');
    verify($http('/admin/resturantControl')[0]===501&&DB::table('resturants')->where('id',100)->value('control')===$control&&DB::table('desktop_dashboard_commands')->count()===0,'an original legacy GET mutation is blocked by a real read-only transaction');
    require __DIR__.'/expense-local-http.php';
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}

// Prepare an owner account from the same complete schema, then reconcile original catalog forms.
config(['database.connections.mysql.database'=>$database]);DB::purge();
$owner=User::withoutGlobalScopes()->findOrFail(1);
$role=\Spatie\Permission\Models\Role::create(['name'=>'Super Admin','guard_name'=>'admin']);
foreach(['category-list','category-create','category-edit','category-delete','product-list','product-create','product-edit','product-delete','areas-list','areas-create','areas-edit','areas-delete','question_answer-list','question_answer-create','question_answer-edit','question_answer-delete'] as $permission)$role->givePermissionTo(\Spatie\Permission\Models\Permission::create(['name'=>$permission,'guard_name'=>'admin']));
$owner->assignRole($role);app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$ownerDevice=(string)\Illuminate\Support\Str::uuid();$ownerLink=app(DesktopDashboardDevices::class)->enroll(['device_id'=>$ownerDevice,'name'=>'owner fixture','nonce'=>bin2hex(random_bytes(32))],$owner);
$ownerSnapshot=app(DesktopDashboardBootstrap::class)->export(app(DesktopDashboardDevices::class)->device($ownerLink['token']));
$ownerStage='fasakhansta_dashboard_stage_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$ownerStage.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(fn()=>$pdo->exec('DROP DATABASE IF EXISTS `'.$ownerStage.'`'));
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.device_id'=>$ownerDevice]);DB::purge();app(DesktopDashboardImport::class)->import($ownerSnapshot);
$env['DB_DATABASE']=$ownerStage;$env['DESKTOP_DASHBOARD_DEVICE_ID']=$ownerDevice;$cookies=[];
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'],[['pipe','r'],['file',$profile.'/web.log','a'],['file',$profile.'/web.log','a']],$pipes,$application,$env);
try{
    for($n=0;$n<100;$n++){[$status]=$http('/_desktop/health');if($status===200)break;usleep(50000);}
    [$status,$page]=$http('/admin/login');preg_match('/name="_token" value="([^"]+)"/',$page,$csrf);
    $http('/admin/signin',['_token'=>$csrf[1],'email'=>'owner@test.invalid','password'=>'Fixture123']);
    foreach(['/admin/categorys','/admin/products'] as $path){[$status,$page]=$http($path);verify($status===200&&str_contains($page,'desktop-dashboard.js'),'the original owner catalog page retains its interface: '.$path);}
    if(getenv('DESKTOP_TEST_BROWSER_MODULE')){
        $browser=proc_open(['node',__DIR__.'/browser.cjs'],[['pipe','r'],['pipe','w'],['pipe','w']],$browserPipes,__DIR__);
        fwrite($browserPipes[0],json_encode(['origin'=>$origin,'token'=>$browserToken]));fclose($browserPipes[0]);
        echo stream_get_contents($browserPipes[1]);fwrite(STDERR,stream_get_contents($browserPipes[2]));fclose($browserPipes[1]);fclose($browserPipes[2]);
        verify(proc_close($browser)===0,'the real offline browser preserves original catalog forms and stable UUIDs');
    }
    $categoryCommand=(string)\Illuminate\Support\Str::uuid();$category=['_token'=>$csrf[1],'_desktop_command'=>$categoryCommand,'added_by'=>1,'name_ar'=>'قسم من الجهاز','name_en'=>'Local category','status'=>'show'];
    [$status]=$http('/admin/categorys',$category);
    if($status!==302)fwrite(STDERR,file_get_contents($profile.'/logs/laravel.log'));
    $categoryId=(int)DB::table('categories')->where('name_ar',$category['name_ar'])->value('id');
    verify($status===302&&$categoryId>1&&DB::table('desktop_dashboard_commands')->count()===1,'the original category controller and form redirect commit with their encrypted local command');
    verify($http('/admin/categorys',$category)[0]===302&&DB::table('categories')->where('name_ar',$category['name_ar'])->count()===1,'retrying a lost original form response does not create a second category');
    $productCommand=(string)\Illuminate\Support\Str::uuid();$product=['_token'=>$csrf[1],'_desktop_command'=>$productCommand,'added_by'=>1,'category_id'=>$categoryId,'name_ar'=>'صنف من الجهاز','status'=>'show','product_features'=>['kilo','half']];
    [$status]=$http('/admin/products',$product);$productId=(int)DB::table('products')->where('name_ar',$product['name_ar'])->value('id');
    verify($status===302&&$productId>1&&DB::table('product_features')->where('product_id',$productId)->count()===2,'the original product repository saves its category and portion features locally');
    $category['_desktop_command']=(string)\Illuminate\Support\Str::uuid();$category['_method']='PUT';$category['name_ar']='قسم معدل من الجهاز';
    verify($http('/admin/categorys/'.$categoryId,$category)[0]===302&&DB::table('categories')->where('id',$categoryId)->value('name_ar')===$category['name_ar'],'the original category update is journaled without changing its form behavior');
    verify($http('/admin/categorys/'.$categoryId,$category)[0]===302&&DB::table('desktop_dashboard_commands')->count()===3,'an update retry retains the original before-state and operation UUID');
    $product['_desktop_command']=(string)\Illuminate\Support\Str::uuid();$product['_method']='PUT';$product['product_id']=$productId;$product['name_ar']='صنف معدل من الجهاز';$product['product_features']=['quarter','combo'];
    verify($http('/admin/products/'.$productId,$product)[0]===302&&DB::table('products')->where('id',$productId)->value('name_ar')===$product['name_ar'],'the original product update and replacement portion features commit locally');
    $invalid=$category;$invalid['_desktop_command']=(string)\Illuminate\Support\Str::uuid();unset($invalid['_method']);$invalid['name_ar']='';
    verify($http('/admin/categorys',$invalid)[0]===302&&DB::table('desktop_dashboard_commands')->count()===4,'original form validation errors retain their redirect and do not queue a success');
    $category['_desktop_command']=(string)\Illuminate\Support\Str::uuid();$category['name_ar']='قسم تعارض من الجهاز';
    verify($http('/admin/categorys/'.$categoryId,$category)[0]===302&&DB::table('desktop_dashboard_commands')->count()===5,'a later offline update retains its expected original catalog state');
}finally{fclose($pipes[0]);proc_terminate($web);proc_close($web);}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
DB::table('categories')->insert(['id'=>$categoryId,'added_by'=>1,'name_ar'=>'قسم سيرفر مستقل','name_en'=>'Server collision','status'=>'show']);
DB::table('products')->insert(['id'=>$productId,'added_by'=>1,'category_id'=>1,'name_ar'=>'صنف سيرفر مستقل','name_en'=>'Server collision','status'=>'show']);
$remoteDevice=app(DesktopDashboardDevices::class)->device($ownerLink['token']);
for($n=0;$n<4;$n++){
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();$command=app(\App\Services\Dashboard\DesktopDashboardJournal::class)->pending($ownerDevice)[0];
    config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
    if($n===3){
        $currentRole=\Spatie\Permission\Models\Role::findOrFail($role->id);$currentRole->revokePermissionTo('product-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try{app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);throw new RuntimeException('Revoked catalog permission was accepted.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===403&&DB::table('products')->where('name_ar',$product['name_ar'])->count()===0,'revoked original product permission rejects replay before changing server rows');}
        $currentRole->givePermissionTo('product-edit');app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    $receipt=app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    $again=app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($remoteDevice,$command);
    verify($receipt===$again,'original server controller reconciles once with fresh permissions: '.$command['route_name']);
    verify(auth('admin')->getUser()===null,'original controller replay restores the outer API authentication context');
    config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();app(\App\Services\Dashboard\DesktopDashboardJournal::class)->acknowledge($ownerDevice,$command['command_id'],$receipt);
}
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
$serverCategory=(int)DB::table('categories')->where('name_ar','قسم معدل من الجهاز')->value('id');$serverProduct=DB::table('products')->where('name_ar',$product['name_ar'])->first();
verify($serverCategory!==$categoryId&&$serverProduct->id!==$productId&&(int)$serverProduct->category_id===$serverCategory,'original catalog reconciliation maps colliding product, route and category IDs');
verify(DB::table('product_features')->where('product_id',$serverProduct->id)->orderBy('id')->pluck('name')->all()===['quarter','combo'],'the server executes the original portion feature replacement');
DB::table('categories')->where('id',$serverCategory)->update(['name_ar'=>'تعديل مستقل على السيرفر']);
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();$conflicting=app(\App\Services\Dashboard\DesktopDashboardJournal::class)->pending($ownerDevice)[0];
config(['database.connections.mysql.database'=>$database,'desktop_dashboard.local'=>false]);DB::purge();
try{app(\App\Services\Dashboard\DesktopDashboardReconciliation::class)->ingest($remoteDevice,$conflicting);throw new RuntimeException('Changed server catalog was overwritten.');}
catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){verify($error->getStatusCode()===409&&DB::table('categories')->where('id',$serverCategory)->value('name_ar')==='تعديل مستقل على السيرفر','a changed server category remains intact and reconciliation reports a retained conflict');}
config(['database.connections.mysql.database'=>$ownerStage,'desktop_dashboard.local'=>true]);DB::purge();
verify(app(\App\Services\Dashboard\DesktopDashboardJournal::class)->pending($ownerDevice)[0]['command_id']===$conflicting['command_id'],'a rejected catalog update remains durably queued on the local device');
require __DIR__.'/catalog-delete.php';
require __DIR__.'/areas.php';
require __DIR__.'/faq.php';
require __DIR__.'/remote-attempts.php';
echo $count.' legacy schema checks passed'.PHP_EOL;
$fixtureCompleted=true;
