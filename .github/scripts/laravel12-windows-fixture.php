<?php
// Synthetic private fixture only; exercise the original public entrypoint and admin guard.
[$script, $application, $source, $port, $artifact] = $argv;
$application = realpath($application);
$source = realpath($source);
$database = 'l12_windows_fixture_'.bin2hex(random_bytes(8));
$completed = false;
$web = null;
$pdo = null;
$result = ['format'=>1, 'kind'=>'isolated-laravel12-original-http-fixture', 'platform'=>['php'=>PHP_VERSION, 'osFamily'=>PHP_OS_FAMILY, 'intSize'=>PHP_INT_SIZE], 'desktopLocal'=>false, 'syntheticActor'=>30, 'databasePrivate'=>true, 'attempts'=>[], 'completed'=>false];
register_shutdown_function(function() use (&$completed, &$web, &$pdo, &$result, $database, $artifact) {
    if (is_resource($web)) { proc_terminate($web); proc_close($web); }
    if ($pdo) $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    $result['completed'] = $completed;
    $result['databaseDropped'] = (bool)$pdo;
    file_put_contents($artifact.'/fixture-receipt.json', json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    if (!$completed) { fwrite(STDERR, 'Original Laravel12 fixture did not complete.'.PHP_EOL); exit(1); }
});
function requireProof(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
foreach (['APP_CONFIG_CACHE','APP_PACKAGES_CACHE','APP_SERVICES_CACHE','APP_ROUTES_CACHE'] as $key) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
foreach (['APP_ENV'=>'testing', 'APP_DEBUG'=>'false', 'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)), 'DB_CONNECTION'=>'mysql', 'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>(string)$port, 'DB_DATABASE'=>$database, 'DB_USERNAME'=>'root', 'DB_PASSWORD'=>'', 'SESSION_DRIVER'=>'file', 'CACHE_DRIVER'=>'array', 'CACHE_STORE'=>'array', 'QUEUE_CONNECTION'=>'sync', 'MAIL_MAILER'=>'log', 'DESKTOP_DASHBOARD_LOCAL'=>'false', 'DESKTOP_DASHBOARD_ENABLED'=>'false'] as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value; }
$pdo = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$result['mariaVersion'] = $pdo->query('SELECT VERSION()')->fetchColumn();
requireProof(str_starts_with($result['mariaVersion'], '11.4.13-MariaDB'), 'Unexpected actual MariaDB version.');
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
require $application.'/vendor/autoload.php';
$app = require $application.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error) use (&$result) { $result['failure']=['class'=>get_class($error), 'message'=>$error->getMessage()]; fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL); exit(1); });
$result['laravel'] = Illuminate\Foundation\Application::VERSION;
requireProof($result['laravel']==='12.69.3', 'Unexpected actual Laravel version.');
require $source.'/tests/desktop_dashboard_runtime/full-schema.php';
$report = json_decode(file_get_contents($source.'/tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json'), true, 512, JSON_THROW_ON_ERROR);
DesktopFullSchemaFixture::create($report['tables']);
$result['fullSchemaTables']=(int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='.$pdo->quote($database))->fetchColumn();
requireProof($result['fullSchemaTables']===106 && count($report['tables'])===106, 'Original full-schema helper did not create all106 table shapes.');
requireProof((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1, 'Foreign keys must remain enabled.');
Illuminate\Support\Facades\DB::table('users')->insert(['id'=>30, 'added_by'=>null, 'name'=>'Isolated Runtime Admin', 'email'=>'runtime-admin@test.invalid', 'mobile'=>'1200000030', 'password'=>password_hash('Fixture123', PASSWORD_BCRYPT, ['cost'=>10]), 'account_type'=>'admin', 'status'=>'accepted', 'app_scope'=>'fasakhansta', 'balance'=>0]);
$actor=App\Models\User::withoutGlobalScopes()->findOrFail(30);
$permission=Spatie\Permission\Models\Permission::create(['name'=>'role-list', 'guard_name'=>'admin']);
$actor->givePermissionTo($permission);
foreach ((new ReflectionClass(App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
    $value=$property->getName()==='site_name'?'Isolated Laravel12':($property->getType()?->getName()==='bool'?true:'0');
    Illuminate\Support\Facades\DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($value)]);
}
$reservation=stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
requireProof((bool)$reservation, 'Cannot reserve loopback HTTP port.');
$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
fclose($reservation);
$origin='http://127.0.0.1:'.$httpPort;
putenv('APP_URL='.$origin);
$env=getenv();
$env['APP_URL']=$origin;
$web=proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$httpPort, '-t', $application.'/public', $application.'/public/index.php'], [['pipe','r'], ['file',$artifact.'/php-http-out.log','a'], ['file',$artifact.'/php-http-error.log','a']], $pipes, $application, $env, ['bypass_shell'=>true]);
requireProof(is_resource($web), 'Original PHP HTTP server did not start.');
fclose($pipes[0]);
$cookies=[];
$http=function(string $path, ?array $form=null) use ($origin, &$cookies, &$result) {
    $headers=['Accept: text/html'];
    if ($cookies) $headers[]='Cookie: '.implode('; ',array_map(fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    if ($form!==null) $headers[]='Content-Type: application/x-www-form-urlencoded';
    $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST', 'header'=>implode("\r\n",$headers), 'content'=>$form===null?'':http_build_query($form), 'ignore_errors'=>true, 'timeout'=>20, 'follow_location'=>0]]);
    $body=@file_get_contents($origin.$path,false,$context);
    $responseHeaders=$http_response_header??[];
    preg_match('/^HTTP\/\S+ (\d+)/',$responseHeaders[0]??'', $status);
    foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i',$header,$match)) $cookies[$match[1]]=$match[2];
    $result['attempts'][]=['request'=>($form===null?'GET ':'POST ').$path,'status'=>(int)($status[1]??0), 'bodySha256'=>hash('sha256',$body===false?'':$body)];
    return [(int)($status[1]??0), $body===false?'':$body, $responseHeaders];
};
$ready=false;
for ($attempt=0;$attempt<120;$attempt++) {
    requireProof(proc_get_status($web)['running'], 'Original PHP HTTP server stopped.');
    $connection=@stream_socket_client('tcp://127.0.0.1:'.$httpPort,$errno,$error,.1);
    if ($connection) { fclose($connection);$ready=true;break; }
    usleep(100000);
}
requireProof($ready, 'Original PHP HTTP server readiness timed out.');
[$status,$body]=$http('/admin/login');
preg_match('/name="_token" value="([^"]+)"/', $body, $token);
requireProof($status===200 && !empty($token[1]) && str_contains($body,'dashboard-login-email') && str_contains($body,'dashboard-login-password'), 'Original login form/CSRF marker missing.');
[$status,$body,$headers]=$http('/admin/signin',['_token'=>$token[1], 'email'=>'runtime-admin@test.invalid', 'password'=>'Fixture123']);
requireProof($status===302 && in_array('Location: '.$origin.'/admin/dashboard',$headers,true), 'Original admin signin did not authenticate synthetic actor30.');
$roleMarker='عرض كل الأذونات';
[$status,$body]=$http('/admin/roles');
requireProof($status===200 && str_contains($body,$roleMarker) && str_contains($body,'Isolated Laravel12') && !str_contains($body,'dashboard-login-form'), 'Original granted roles page did not render.');
$actor->revokePermissionTo('role-list');
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
requireProof(!$actor->fresh()->hasPermissionTo('role-list','admin'), 'Actual permission revocation failed.');
[$status,$body]=$http('/admin/roles');
requireProof($status===403, 'Original roles middleware did not deny revoked permission.');
$actor->givePermissionTo('role-list');
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
requireProof($actor->fresh()->hasPermissionTo('role-list','admin'), 'Actual permission restoration failed.');
[$status,$body]=$http('/admin/roles');
requireProof($status===200 && str_contains($body,$roleMarker) && !str_contains($body,'dashboard-login-form'), 'Original restored roles page did not render.');
requireProof(Illuminate\Support\Facades\DB::table('users')->count()===1, 'Fixture created unexpected users.');
requireProof(Illuminate\Support\Facades\DB::table('roles')->count()===0, 'Fixture roles listing must remain empty.');
$result['proof']=['loginGET'=>200,'signinPOST'=>302,'rolesGranted'=>200,'rolesRevoked'=>403,'rolesRestored'=>200,'originalBladeMarkers'=>true,'csrfAndCookies'=>true,'redirectFollowing'=>false,'foreignKeysEnabled'=>true,'emptyRolesListing'=>true];
$completed=true;
echo 'LARAVEL12_ORIGINAL_HTTP_COMPLETE '.json_encode($result['proof'], JSON_THROW_ON_ERROR).PHP_EOL;
