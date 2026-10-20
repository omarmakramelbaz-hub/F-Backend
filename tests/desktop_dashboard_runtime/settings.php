<?php
// Actual original application + disposable InnoDB: no production environment or business rows.
use Illuminate\Support\Facades\{DB,Schema,Crypt};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||!is_file($application.'/desktop/bootstrap.php')||$port<1024||$port>65535)throw new RuntimeException('Usage: settings.php application local-mariadb-port');
$profile=sys_get_temp_dir().'/desktop-settings-'.bin2hex(random_bytes(6));
foreach(['framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_settings_'.bin2hex(random_bytes(6));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($pdo,$database){$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');$device=(string)Str::uuid();
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_STORAGE'=>$profile,
    'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:33408','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','SETTINGS_CACHE_ENABLED'=>'true','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value)putenv($key.'='.$value);
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
Schema::create('users',function(Blueprint $t){$t->engine='InnoDB';$t->id();$t->string('name');$t->string('account_type');$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->engine='InnoDB';$t->id();$t->unsignedBigInteger('user_id');$t->string('name');$t->unsignedBigInteger('parent_id')->nullable();});
foreach(['2022_08_05_174522_create_permission_tables.php'=>'CreatePermissionTables','2022_08_09_145144_create_settings_table.php'=>'CreateSettingsTable',
    '2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal','2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState'] as $file=>$class){require $application.'/database/migrations/'.$file;(new $class)->up();}
DB::table('users')->insert(['id'=>10,'name'=>'Settings actor','account_type'=>'admin']);DB::table('resturants')->insert(['id'=>100,'user_id'=>10,'name'=>'Settings branch']);
$permission=\Spatie\Permission\Models\Permission::create(['name'=>'setting-list','guard_name'=>'admin']);
$actor=\App\Models\User::withoutGlobalScopes()->findOrFail(10);$actor->givePermissionTo($permission);
$actor->givePermissionTo(\Spatie\Permission\Models\Permission::create(['name'=>'order-list','guard_name'=>'admin']));
$now=now('UTC');$snapshot=(string)Str::uuid();
DB::table('desktop_dashboard_devices')->insert(['id'=>$device,'actor_id'=>10,'name'=>'Settings device','enrollment_hash'=>str_repeat('a',64),'token_hash'=>str_repeat('b',64),'branches'=>'["f:100"]','enabled'=>true,'enrolled_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$device,'actor_id'=>10,'snapshot_id'=>$snapshot,'schema_hash'=>str_repeat('c',64),'branches'=>'["f:100"]','coverage'=>'{}','state'=>'ready','created_at'=>$now,'updated_at'=>$now]);
$fields=[];foreach((new ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property){
    $name=$property->getName();$fields[$name]=$property->getType()->getName()==='bool'?true:'Original '.$name;
}
foreach(['min_order_price','service_fees','km_price','vendor_tax','tax','app_balance','shipping_km_price','shipping_min_price','shipping_cancelled_block_no','default_0_1','default_1_2','default_2_3','min_wallet'] as $key)$fields[$key]='1';
$fields['app_balance']='0';$fields['advertise_resturant_id']='100';$fields['email']='settings@example.test';$fields['phone']='01012345678';
foreach(['googleplay_link','vendor_googleplay_link','delegate_googleplay_link','applestore_link','vendor_applestore_link','delegate_applestore_link'] as $key)$fields[$key]='https://example.test/'.$key;
foreach($fields as $key=>$value)DB::table('settings')->insert(['group'=>'general','name'=>$key,'payload'=>json_encode($value),'locked'=>false,'created_at'=>$now,'updated_at'=>$now]);
$checks=0;$check=function($condition,$message)use(&$checks){if(!$condition)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
$deny=function(callable $fn,int $status,string $message)use($check){try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$check($error->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected rejection: '.$message);};
$settings=app(\App\Services\Dashboard\DesktopDashboardSettings::class);$journal=app(\App\Services\Dashboard\DesktopDashboardJournal::class);
$check(realpath((new ReflectionClass($settings))->getFileName())===realpath($application.'/app/Services/Dashboard/DesktopDashboardSettings.php'),'the settings adapter is bound to the generated original source');
$check(realpath((new ReflectionClass(\App\Http\Controllers\Dashboard\SettingsController::class))->getFileName())===realpath($application.'/app/Http/Controllers/Dashboard/SettingsController.php'),'the original settings controller is loaded from the same generated application');
$values=array_intersect_key($fields,array_flip(['site_name','email','phone','policy_en','policy_ar','about_en','about_ar','service_fees','terms_en','terms_ar',
    'contact_text_en','contact_text_ar','address','km_price','tax','vendor_tax','googleplay_link','applestore_link','vendor_googleplay_link','vendor_applestore_link',
    'delegate_googleplay_link','delegate_applestore_link','slider_title_en','slider_title_ar','slider_text_en','slider_text_ar','shipping_km_price','shipping_min_price',
    'shipping_cancelled_block_no','default_0_1','default_1_2','default_2_3','app_banner_background_color','min_order_price']));
$values['site_name']='New original name';$values['app_balance']='0';$command=(string)Str::uuid();
$request=\Illuminate\Http\Request::create(url('/admin/settings/update'),'PUT',$values+['_desktop_command'=>$command]);
$route=clone app('router')->getRoutes()->getByName('updateSetting');$route->bind($request);$request->setRouteResolver(fn()=>$route);
$session=new \Illuminate\Session\Store('settings-source-fixture',new \Illuminate\Session\ArraySessionHandler(60));$session->start();$request->setLaravelSession($session);
$before=DB::table('settings')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
$payload=DB::transaction(fn()=>$settings->payload('updateSetting',$request,$command,$actor,DB::table('desktop_dashboard_local_state')->first()));
$check(!isset($payload['values']['app_balance'])&&!isset($payload['facts']['settings_before']['app_balance']),'the read-only masked app balance is absent from the immutable settings command and before-facts');
$result=$settings->guard(fn()=>DB::transaction(fn()=>$settings->execute('updateSetting',$payload,$actor)));
$check($result['after']['site_name']['value']==='New original name'&&DB::table('settings')->count()===count($before),'actual original settings controller updates one existing settings group without duplicate rows');
$check(DB::table('settings')->where('name','app_balance')->value('payload')==='"0"','the original local masked balance remains zero without entering the command');
$after=DB::table('settings')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
foreach($before as $i=>$row)DB::table('settings')->where('id',$row['id'])->update($row);
DB::table('settings')->where('name','app_balance')->update(['payload'=>'"98765.43"']);
config(['desktop_dashboard.local'=>false]);
$server=$settings->guard(fn()=>DB::transaction(function()use($settings,$payload,$actor){$settings->authorize('updateSetting',$payload,$actor,DB::table('desktop_dashboard_devices')->first());return $settings->execute('updateSetting',$payload,$actor);}));
$settings->verifyAfter($server,$result,$payload,$payload);
$check(DB::table('settings')->where('name','app_balance')->value('payload')==='"98765.43"','original central replay preserves its fresh locked nonzero app balance despite the old local read-only zero');
$invalidAfter=$result;unset($invalidAfter['after']['site_name']['updated_at']);$deny(fn()=>$settings->verifyAfter($server,$invalidAfter,$payload,$payload),409,'a fabricated settings result cannot omit its complete timestamp facts');
$check($server['after']['site_name']['value']==='New original name'&&DB::table('settings')->count()===count($before),'original replay retains the complete original settings group and row membership');
$check(!str_contains(json_encode($server),'98765.43'),'the central saved settings result does not expose the protected app balance');
$deny(fn()=>$settings->guard(fn()=>DB::transaction(fn()=>$settings->execute('updateSetting',$payload,$actor))),409,'changed public server settings cannot be overwritten by replaying old before-facts');
$advertisingCommand=(string)Str::uuid();$advertisingRequest=\Illuminate\Http\Request::create(url('/admin/advertising/update'),'PUT',['advertise_resturant_id'=>'100','_desktop_command'=>$advertisingCommand]);
$advertisingRoute=clone app('router')->getRoutes()->getByName('updateAdvertising');$advertisingRoute->bind($advertisingRequest);$advertisingRequest->setRouteResolver(fn()=>$advertisingRoute);$advertisingRequest->setLaravelSession($session);
$advertisingPayload=DB::transaction(fn()=>$settings->payload('updateAdvertising',$advertisingRequest,$advertisingCommand,$actor,DB::table('desktop_dashboard_devices')->first()));
$advertising=$settings->guard(fn()=>DB::transaction(fn()=>$settings->execute('updateAdvertising',$advertisingPayload,$actor)));
$check($advertising['after']['advertise_resturant_id']['value']===100&&DB::table('settings')->where('name','app_balance')->value('payload')==='"98765.43"','the original nonsecret advertising selection preserves its scoped restaurant and central app balance');
$mapped=$settings->inputs($advertisingPayload,fn($entity,$id)=>['$desktop_ref'=>['entity'=>$entity,'command_id'=>(string)Str::uuid(),'local_id'=>$id]]);
$settings->validateReferences($mapped);
$check($mapped['values']['advertise_resturant_id']['$desktop_ref']['entity']==='menu_restaurant'
    &&$mapped['facts']['settings_before']['advertise_resturant_id']['value']['$desktop_ref']['entity']==='menu_restaurant','both requested and current advertising restaurant identities use named typed references');
$mapped['values']['advertise_resturant_id']['$desktop_ref']['entity']='wallet_account';$deny(fn()=>$settings->validateReferences($mapped),422,'an advertising restaurant cannot be supplied through a foreign entity kind');
$events=[];app('events')->listen(\Spatie\LaravelSettings\Events\SettingsSaved::class,function()use(&$events){$events[]=DB::transactionLevel();});
$advertisingPayload=DB::transaction(fn()=>$settings->payload('updateAdvertising',$advertisingRequest,(string)Str::uuid(),$actor,DB::table('desktop_dashboard_devices')->first()));
$beforeRollback=DB::table('settings')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
try{$settings->guard(fn()=>DB::transaction(function()use($settings,$advertisingPayload,$actor){$settings->execute('updateAdvertising',$advertisingPayload,$actor);throw new RuntimeException('synthetic later journal failure');}));throw new RuntimeException('A settings rollback was not triggered.');}
catch(RuntimeException $error){if($error->getMessage()!=='synthetic later journal failure')throw $error;}
$check($beforeRollback===DB::table('settings')->orderBy('id')->get()->map(fn($row)=>(array)$row)->all()&&$events===[],'a later root transaction failure rolls back every settings row and publishes no SettingsSaved event');
$cache=app(\Spatie\LaravelSettings\Support\SettingsCacheFactory::class)->build(\App\Models\GeneralSettings::repository());
$hasCache=fn()=>method_exists($cache,'has')?$cache->has(\App\Models\GeneralSettings::class):$cache->get(\App\Models\GeneralSettings::class)!==null;
$check($cache->isEnabled()&&!$hasCache(),'rollback cannot leave an uncommitted GeneralSettings object in the enabled shared cache');
$settings->guard(fn()=>DB::transaction(fn()=>$settings->execute('updateAdvertising',$advertisingPayload,$actor)));
$check($events===[0]&&!$hasCache(),'successful settings events run only after the root commit and final reads reload authoritative settings');
DB::table('model_has_permissions')->where('model_id',10)->delete();$actor->unsetRelation('permissions');
$deny(fn()=>DB::transaction(fn()=>$settings->authorize('updateSetting',$payload,$actor,DB::table('desktop_dashboard_devices')->first())),403,'revoked current permission is checked independently of warmed middleware before saved replies');
$check(!\App\Services\Dashboard\DesktopDashboardSettings::handles('updateEnv')&&!\App\Services\Dashboard\DesktopDashboardSettings::handles('updatePaymentActivation'),'provider credentials and payment availability remain central-only');
$template=file_get_contents($application.'/resources/views/admin/settings.blade.php');
$start=strpos($template,'<label for="app_balance">');$end=strpos($template,'<div class="form-group col-sm-6"></div>',$start);$widget=substr($template,$start,$end-$start);
$template=file_get_contents($application.'/resources/views/admin/env-setting.blade.php');$start=strpos($template,"@if(config('desktop_dashboard.local'))");$end=strpos($template,"\n       </div>",$start);$provider=substr($template,$start,$end-$start);
$render=function(string $source){$settings=app(\App\Models\GeneralSettings::class)->refresh();$compiled=app('blade.compiler')->compileString($source);ob_start();eval('?>'.$compiled);return ob_get_clean();};
config(['desktop_dashboard.local'=>true]);$localWidget=$render($widget);$localProvider=$render($provider);
$check(!str_contains($localWidget,'name="app_balance"')&&!str_contains($localWidget,'98765.43')&&str_contains($localWidget,'لا يتغير عند حفظ'),'the actual original local settings widget explains the financial display boundary instead of presenting masked zero as a balance');
$check(!str_contains($localProvider,'name="PAYMOB_API_KEY"')&&!str_contains($localProvider,'name="payment_card_activate"')&&str_contains($localProvider,'المتصلة فقط'),'the actual original local payment view hides credential and activation forms with connected-only guidance');
config(['desktop_dashboard.local'=>false]);$onlineWidget=$render($widget);$onlineProvider=$render($provider);
$check(str_contains($onlineWidget,'name="app_balance"')&&str_contains($onlineWidget,'98765.43')&&str_contains($onlineProvider,'name="PAYMOB_API_KEY"')&&str_contains($onlineProvider,'name="payment_card_activate"'),'the original connected settings and payment controls retain their existing values and forms');
require __DIR__.'/settings-sync.php';
require __DIR__.'/settings-images.php';
require __DIR__.'/settings-legacy-images.php';
echo 'DESKTOP_SETTINGS_COMPLETE '.$checks.PHP_EOL;
