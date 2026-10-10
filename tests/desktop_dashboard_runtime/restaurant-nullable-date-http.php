<?php
/** Narrow original HTTP fixture; the 106 column/FK shapes and all actors are synthetic. */
// Usage: php restaurant-nullable-date-http.php generated-application maria-port artifact-directory reproduce|fixed
use Illuminate\Support\Facades\DB;

[$script, $application, $port, $artifact, $mode] = $argv;
$application = realpath($application);
$source = dirname(__DIR__, 2);
if (!in_array($mode, ['reproduce', 'fixed'], true) || !$application || !is_dir($artifact)) throw new RuntimeException('Invalid isolated fixture arguments.');
$database = 'restaurant_dates_'.bin2hex(random_bytes(8));
$completed = false; $web = null; $pdo = null; $checks = 0;
$receipt = ['format'=>1, 'kind'=>'original-restaurant-nullable-date-http', 'mode'=>$mode, 'desktopLocal'=>false,
    'originalPublicEntrypoint'=>true, 'syntheticActors'=>[30,31,32], 'privateDatabase'=>true, 'providersReplaced'=>false,
    'productionCredentialsCopied'=>false, 'fullDashboard'=>false, 'applicationValidated'=>false, 'attempts'=>[], 'completed'=>false];
register_shutdown_function(function() use (&$completed, &$web, &$pdo, &$receipt, &$checks, $database, $artifact) {
    if (is_resource($web)) { proc_terminate($web); proc_close($web); }
    if ($pdo) { $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'); $receipt['databaseDropped']=true; }
    $receipt['completed']=$completed; $receipt['checks']=$checks;
    file_put_contents($artifact.'/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
    if (!$completed) { fwrite(STDERR, 'Restaurant date HTTP fixture did not complete.'.PHP_EOL); exit(1); }
});
function verifyDateProof(bool $condition, string $message): void {
    global $checks; if (!$condition) throw new RuntimeException($message); $checks++; echo 'PASS '.$message.PHP_EOL;
}
set_exception_handler(function(Throwable $error) use (&$receipt) {
    $receipt['failure']=['class'=>get_class($error), 'message'=>$error->getMessage()];
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL); exit(1);
});
$manifest=json_decode(file_get_contents(dirname($application).'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['sourceHashes'] as $file=>$hash) verifyDateSource($application.'/'.$file, $hash);
function verifyDateSource(string $file, string $hash): void { if (hash_file('sha256', $file)!==$hash) throw new RuntimeException('Runtime source bytes differ: '.$file); }
$receipt['sourceRevision']=$manifest['sourceRevision']; $receipt['sourceFilesVerified']=count($manifest['sourceHashes']);
$receipt['sourceManifestSha256']=hash_file('sha256', dirname($application).'/manifest.json');
$receipt['fixtureSha256']=hash_file('sha256', __FILE__);
$receipt['schemaHelperSha256']=hash_file('sha256', __DIR__.'/full-schema.php');
$receipt['schemaReportSha256']=hash_file('sha256', __DIR__.'/fixtures/deployed-schema-20261008.json');
$receipt['dependencyLockSha256']=hash_file('sha256', $application.'/composer.lock');
verifyDateProof(!file_exists($application.'/.env'), 'fresh generated application contains no environment file');
foreach (array_keys(getenv()) as $key) if (preg_match('/^(?:APP_|DB_|SESSION_|CACHE_|DESKTOP_)/', $key)) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
foreach (['app/public','framework/cache/data','framework/sessions','framework/views','logs'] as $directory) mkdir($application.'/storage/'.$directory, 0700, true);
$reservation=stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
verifyDateProof((bool)$reservation, 'private HTTP port reservation succeeds');
$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1); fclose($reservation);
$origin='http://127.0.0.1:'.$httpPort;
foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_URL'=>$origin,'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'SESSION_DRIVER'=>'file','CACHE_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'log','DESKTOP_DASHBOARD_LOCAL'=>'false','DESKTOP_DASHBOARD_ENABLED'=>'false'] as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value; }
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn(); $receipt['phpVersion']=PHP_VERSION;
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
require $application.'/vendor/autoload.php';
$app=require $application.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Restore an explicit nonzero failure boundary after Laravel installs its exception handler.
set_exception_handler(function(Throwable $error) use (&$receipt) { $receipt['failure']=['class'=>get_class($error),'message'=>$error->getMessage()]; fwrite(STDERR, $error.PHP_EOL); exit(1); });
$receipt['laravel']=Illuminate\Foundation\Application::VERSION;
verifyDateProof($receipt['laravel']==='8.83.29', 'actual original Laravel8 dependency runtime is used');
require __DIR__.'/full-schema.php';
$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'), true, 512, JSON_THROW_ON_ERROR);
DesktopFullSchemaFixture::create($report['tables']);
$receipt['schema']='106 synthetic column/FK table shapes; five permission tables use the original migration; defaults/indexes otherwise synthetic';
$receipt['schemaTables']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='.$pdo->quote($database))->fetchColumn();
verifyDateProof($receipt['schemaTables']===106 && (int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1, 'all106 inspected table shapes exist with foreign keys enabled');
foreach (['resturants','resturant_products','reviews'] as $table) verifyDateProof($report['tables'][$table]['columns']['created_at']==='timestamp nullable', $table.' legitimately permits a NULL creation timestamp');
$stamp='2000-01-01 00:00:00';
foreach ([30=>['Fixture Restaurant Reader','admin'],31=>['Fixture Restaurant Vendor','vendor'],32=>['Fixture Review Customer','user']] as $id=>[$name,$accountType]) DB::table('users')->insert(['id'=>$id,'added_by'=>null,'name'=>$name,'email'=>'restaurant-'.$id.'@test.invalid','mobile'=>'12000000'.$id,'password'=>password_hash('Fixture123',PASSWORD_BCRYPT),'account_type'=>$accountType,'status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0,'created_at'=>$stamp]);
$actor=App\Models\User::withoutGlobalScopes()->findOrFail(30);
DB::table('roles')->insert(['id'=>11,'name'=>'Fixture Restaurant Reader','guard_name'=>'admin','created_at'=>$stamp,'updated_at'=>$stamp]);
$actor->assignRole(Spatie\Permission\Models\Role::findById(11,'admin'));
$permission=Spatie\Permission\Models\Permission::create(['name'=>'resturant-list','guard_name'=>'admin']); $actor->givePermissionTo($permission);
verifyDateProof(!$actor->hasRole('Super Admin') && $actor->getAllPermissions()->pluck('name')->all()===['resturant-list'], 'actor has only the original restaurant read permission');
foreach ((new ReflectionClass(App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($property->getName()==='site_name'?'Restaurant Date Fixture':($property->getType()?->getName()==='bool'?true:'0'))]);
DB::table('categories')->insert(['id'=>1,'added_by'=>30,'name_ar'=>'Fixture Category','name_en'=>'Fixture Category','status'=>'show','order'=>1,'created_at'=>$stamp]);
DB::table('products')->insert(['id'=>1,'added_by'=>30,'category_id'=>1,'name_ar'=>'Fixture Product','name_en'=>'Fixture Product','status'=>'show','created_at'=>$stamp]);
foreach ([100=>'Nullable Restaurant',101=>'Dated Restaurant'] as $id=>$name) {
    DB::table('resturants')->insert(['id'=>$id,'added_by'=>30,'user_id'=>31,'name'=>$name,'status'=>'opened','address'=>'Private Fixture Address','created_at'=>$stamp]);
    DB::table('resturant_products')->insert(['id'=>$id,'added_by'=>30,'resturant_id'=>$id,'product_id'=>1,'category_id'=>1,'product_name'=>$id===100?'Nullable Product':'Dated Product','product_price'=>'100.00','status'=>'show','highest_rated'=>'no','price'=>json_encode(array_fill_keys(['extra_combo','extra_large','extra_medium','extra_clean','extra_clear','extra_vacuim'],0)),'created_at'=>$stamp]);
    DB::table('orders')->insert(['id'=>$id,'user_id'=>32,'resturant_id'=>$id,'type'=>'current','status'=>'pending','payment_type'=>'cash','created_at'=>$stamp]);
    DB::table('reviews')->insert(['id'=>$id,'resturant_id'=>$id,'user_id'=>32,'order_id'=>$id,'rate'=>'5','created_at'=>$stamp]);
}
$web=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/public/index.php'], [['pipe','r'],['file',$artifact.'/http-out.log','a'],['file',$artifact.'/http-error.log','a']], $pipes, $application, getenv());
verifyDateProof(is_resource($web), 'untouched original public/index.php server starts'); fclose($pipes[0]);
$ready=false; for ($attempt=0;$attempt<120;$attempt++) { if (!proc_get_status($web)['running']) break; $socket=@stream_socket_client('tcp://127.0.0.1:'.$httpPort,$errno,$error,.1); if ($socket) { fclose($socket);$ready=true;break; } usleep(50000); }
verifyDateProof($ready, 'fresh original HTTP server becomes ready');
$cookies=[];
$http=function(string $path, ?array $form=null) use ($origin, &$cookies, &$receipt) {
    $headers=['Accept: text/html']; if ($cookies) $headers[]='Cookie: '.implode('; ',array_map(fn($key,$value)=>$key.'='.$value,array_keys($cookies),$cookies));
    if ($form!==null) $headers[]='Content-Type: application/x-www-form-urlencoded';
    $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$form===null?'':http_build_query($form),'ignore_errors'=>true,'timeout'=>20,'follow_location'=>0]]);
    $body=@file_get_contents($origin.$path,false,$context); $responseHeaders=$http_response_header??[]; preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'',$status);
    foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match)) $cookies[$match[1]]=$match[2];
    $receipt['attempts'][]=['request'=>($form===null?'GET ':'POST ').$path,'status'=>(int)($status[1]??0),'bodySha256'=>hash('sha256',$body===false?'':$body)];
    return [(int)($status[1]??0),$body===false?'':$body,$responseHeaders];
};
foreach (['/admin/resturants','/admin/resturants/100'] as $path) verifyDateProof($http($path)[0]===302, 'original authentication denies anonymous '.$path);
[$status,$body]=$http('/admin/login'); preg_match('/name="_token" value="([^"]+)"/',$body,$token);
verifyDateProof($status===200 && !empty($token[1]), 'original login provides real CSRF and cookies');
[$status,,$headers]=$http('/admin/signin',['_token'=>$token[1],'email'=>'restaurant-30@test.invalid','password'=>'Fixture123']);
verifyDateProof($status===302 && in_array('Location: '.$origin.'/admin/dashboard',$headers,true), 'synthetic actor signs in through the original admin controller');
function dateCells(string $html, string $query): array {
    $document=new DOMDocument(); @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html); $xpath=new DOMXPath($document);
    return array_map(fn($node)=>trim($node->textContent),iterator_to_array($xpath->query($query)));
}
$indexQuery=fn($name)=>"//tbody/tr[td[contains(.,'".$name."')]]/td[last()-1]";
$showQuery="//div[contains(@class,'show-data')]//div[contains(@class,'form-group')][label[normalize-space(.)='".__('main.created_at')."']]/span | //*[@id='pills-resturant_products']//tbody/tr/td[last()-1] | //*[@id='pills-resturant_reviews']//tbody/tr/td[last()-1]";
$expected=App\Models\Resturant::withoutGlobalScopes()->findOrFail(101)->created_at->diffForHumans();
[$status,$body]=$http('/admin/resturants'); verifyDateProof($status===200 && dateCells($body,$indexQuery('Dated Restaurant'))===[$expected], 'nonnull original index date keeps its human-readable display');
[$status,$body]=$http('/admin/resturants/101'); verifyDateProof($status===200 && dateCells($body,$showQuery)===[$expected,$expected,$expected], 'nonnull original restaurant/product/review dates render unchanged');
foreach (['resturants','resturant_products','reviews'] as $table) DB::table($table)->where('id',100)->update(['created_at'=>null]);
if ($mode==='reproduce') {
    $fail=function(string $path,string $view,string $case) use ($http,$application,&$receipt) {
        $log=$application.'/storage/logs/laravel.log'; clearstatcache(true,$log); $offset=is_file($log)?filesize($log):0;
        [$status]=$http($path); $newLog=substr(file_get_contents($log),$offset);
        verifyDateProof($status===500 && str_contains($newLog,'Call to a member function diffForHumans() on null') && str_contains($newLog,'resources/views/admin/resturants/'.$view.'.blade.php'), 'baseline NULL '.$case.' causes the concrete original HTTP500 date dereference');
        $receipt['reproducedFailures'][]=['case'=>$case,'path'=>$path,'status'=>$status,'error'=>'Call to a member function diffForHumans() on null','originalView'=>'resources/views/admin/resturants/'.$view.'.blade.php','newLogSha256'=>hash('sha256',$newLog)];
    };
    $fail('/admin/resturants','index','restaurant index'); $fail('/admin/resturants/100','show','restaurant detail');
    DB::table('resturants')->where('id',100)->update(['created_at'=>$stamp]); $fail('/admin/resturants/100','show','product detail');
    DB::table('resturant_products')->where('id',100)->update(['created_at'=>$stamp]); $fail('/admin/resturants/100','show','review detail');
} else {
    [$status,$body]=$http('/admin/resturants');
    verifyDateProof($status===200 && dateCells($body,$indexQuery('Nullable Restaurant'))===['—'] && dateCells($body,$indexQuery('Dated Restaurant'))===[$expected], 'mixed NULL and nonnull restaurant index dates render without inventing a date');
    [$status,$body]=$http('/admin/resturants/100'); verifyDateProof($status===200 && dateCells($body,$showQuery)===['—','—','—'], 'NULL restaurant/product/review detail dates use the familiar no-value glyph');
    $receipt['renderedDates']=['nonnull'=>$expected,'nullIndex'=>'—','nullDetail'=>['—','—','—']];
}
$actor->revokePermissionTo('resturant-list'); app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
verifyDateProof(!$actor->fresh()->hasPermissionTo('resturant-list','admin'), 'original restaurant read grant is actually revoked');
foreach (['/admin/resturants','/admin/resturants/101'] as $path) verifyDateProof($http($path)[0]===403, 'original permission middleware denies revoked '.$path);
$actor->givePermissionTo('resturant-list'); app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
foreach (['/admin/resturants?resturant_id=101','/admin/resturants/101'] as $path) verifyDateProof($http($path)[0]===200, 'restoring the original permission restores '.$path);
verifyDateProof(DB::table('resturants')->where('id',100)->value('created_at')===($mode==='reproduce'?$stamp:null) && DB::table('reviews')->where('id',100)->value('created_at')===null, 'rendering retains the persisted timestamp values');
$completed=true;
echo 'RESTAURANT_NULLABLE_DATE_HTTP_COMPLETE '.json_encode(['mode'=>$mode,'sourceRevision'=>$receipt['sourceRevision'],'checks'=>$checks,'desktopLocal'=>false,'fullDashboard'=>false]).PHP_EOL;
