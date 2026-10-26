<?php
// Actual original application + disposable InnoDB: no production environment or business rows.
use Illuminate\Support\Facades\{DB,Schema,Crypt};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use App\Services\Dashboard\DesktopDashboardOutboxReview;

$application=realpath($argv[1]??'');$port=(int)($argv[2]??0);
if(!$application||!is_file($application.'/desktop/bootstrap.php')||$port<1024||$port>65535)throw new RuntimeException('Usage: outbox-review.php application local-mariadb-port');
$profile=sys_get_temp_dir().'/desktop-outbox-review-'.bin2hex(random_bytes(6));
foreach(['framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache'] as $dir)mkdir($profile.'/'.$dir,0700,true);
$database='desktop_review_'.bin2hex(random_bytes(6));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(function()use($pdo,$database){$pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');});
$loader=require $application.'/vendor/autoload.php';$loader->setPsr4('App\\',$application.'/app');$device=(string)Str::uuid();
foreach(['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_ENABLED'=>'true','DESKTOP_DASHBOARD_DEVICE_ID'=>$device,'DESKTOP_DASHBOARD_STORAGE'=>$profile,
    'APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:33408','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file','APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler(function(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);exit(1);});
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');$t->unsignedBigInteger('parent_id')->nullable();});
foreach(['2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal',
    '2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState'] as $file=>$class){require $application.'/database/migrations/'.$file;(new $class)->up();}
DB::table('users')->insert([['id'=>10,'name'=>'Review actor','account_type'=>'vendor'],['id'=>11,'name'=>'Other actor','account_type'=>'vendor']]);
DB::table('resturants')->insert(['id'=>100,'user_id'=>10,'name'=>'Review branch']);
$snapshot=(string)Str::uuid();$now=now('UTC');
DB::table('desktop_dashboard_devices')->insert(['id'=>$device,'actor_id'=>10,'name'=>'Review device','enrollment_hash'=>str_repeat('a',64),'token_hash'=>str_repeat('b',64),'branches'=>'["f:100"]','enabled'=>true,'enrolled_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
DB::table('desktop_dashboard_local_state')->insert(['device_id'=>$device,'actor_id'=>10,'snapshot_id'=>$snapshot,'schema_hash'=>str_repeat('c',64),'branches'=>'["f:100"]','coverage'=>'{}','state'=>'ready','created_at'=>$now,'updated_at'=>$now]);
$one=(string)Str::uuid();$two=(string)Str::uuid();
foreach([$one,$two] as $id)DB::table('desktop_dashboard_commands')->insert(['device_id'=>$device,'command_id'=>$id,'actor_id'=>10,'route_name'=>'takeaway.checkout',
    'request_hash'=>str_repeat($id===$one?'d':'e',64),'command_cipher'=>Crypt::encryptString('{"immutable":"business-envelope"}'),
    'local_result_cipher'=>Crypt::encryptString('{"immutable":"local-result"}'),'dependencies'=>$id===$one?'[]':json_encode([$one]),
    'status'=>'conflict','attempts'=>2,'last_error'=>'Current original server state differs','created_at'=>$now,'updated_at'=>$now]);
$checks=0;
$check=function($value,$message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;};
$deny=function(callable $fn,int $status,string $message)use($check){try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $error){$check($error->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected rejection: '.$message);};
$review=app(DesktopDashboardOutboxReview::class);$check(realpath((new ReflectionClass($review))->getFileName())===realpath($application.'/app/Services/Dashboard/DesktopDashboardOutboxReview.php'),'the reviewed PHP implementation is loaded from this generated application');
$before=(array)DB::table('desktop_dashboard_commands')->where('command_id',$one)->first();
$value=$review->inspect($device);$token=$value['first']['review_token'];
$check($value['first']['command_id']===$one&&$value['counts']['conflicts']===2,'review identifies the first blocked command and counts all retained conflicts');
$check(!str_contains(json_encode($value),'business-envelope')&&!str_contains(json_encode($value),'local_result_cipher')&&!str_contains(json_encode($value),'token_hash'),'review returns metadata without decrypted envelopes, results or enrollment credentials');
$deny(fn()=>$review->retry($device,$two,$token),409,'a later dependent conflict cannot jump the first command');
DB::table('desktop_dashboard_commands')->where('command_id',$one)->update(['attempts'=>3]);
$deny(fn()=>$review->retry($device,$one,$token),409,'a changed transmission attempt invalidates an old review');
DB::table('desktop_dashboard_commands')->where('command_id',$one)->update(['attempts'=>2]);
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'held']);
$check($review->inspect($device)['held']===true,'a refresh fence permits only read-only review');
$deny(fn()=>$review->retry($device,$one,$token),409,'a refresh fence blocks retry');
DB::table('desktop_dashboard_local_state')->where('device_id',$device)->update(['state'=>'ready']);
DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
$deny(fn()=>$review->retry($device,$one,$token),404,'revoked enrolled branch authority blocks retry');
DB::table('resturants')->where('id',100)->update(['user_id'=>10]);
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['enabled'=>false]);
$deny(fn()=>$review->retry($device,$one,$token),403,'disabled device is checked before retry');
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['enabled'=>true,'actor_id'=>11]);
$deny(fn()=>$review->inspect($device),403,'mismatched device and local account cannot inspect a retained command');
DB::table('desktop_dashboard_devices')->where('id',$device)->update(['actor_id'=>10]);
DB::table('users')->where('id',10)->update(['status'=>'disabled']);
$deny(fn()=>$review->retry($device,$one,$token),403,'current disabled actor cannot request retry');
DB::table('users')->where('id',10)->update(['status'=>'accepted']);
$result=$review->retry($device,$one,$token);$after=(array)DB::table('desktop_dashboard_commands')->where('command_id',$one)->first();
$immutable=$before;unset($immutable['status'],$immutable['updated_at']);$actual=$after;unset($actual['status'],$actual['updated_at']);
$check($result['queued']&&$after['status']==='pending'&&$immutable===$actual,'retry changes only sendable status and update time; exact UUID, encrypted envelope/result, dependencies, error and occurrence survive');
$check(DB::table('desktop_dashboard_commands')->where('command_id',$two)->value('status')==='conflict','the later dependent conflict remains untouched');
$deny(fn()=>$review->retry($device,$one,$token),409,'a lost successful retry response cannot replay a stale request');
DB::table('desktop_dashboard_commands')->where('command_id',$one)->update(['status'=>'acknowledged']);
$check($review->inspect($device)['first']['command_id']===$two,'review advances only after a real acknowledged status exists');
config(['desktop_dashboard.local'=>false]);$deny(fn()=>$review->inspect($device),403,'native review cannot run against a central application');
echo 'DESKTOP_OUTBOX_REVIEW_COMPLETE '.$checks.PHP_EOL;
