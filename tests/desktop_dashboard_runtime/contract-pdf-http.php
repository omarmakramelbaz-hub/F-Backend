<?php
/** Real application HTTP fixture; all actors, contract text and database rows are synthetic.
 * Usage: php contract-pdf-http.php application maria-port artifacts render|windows [source-revision]
 * `render` runs on Linux too. `windows` additionally requires the bundled native PDF executable.
 */
use Illuminate\Support\Facades\DB;

[$script, $application, $port, $artifact, $mode] = array_pad($argv, 5, null);
$application = $application ? realpath($application) : false;
$artifact = $artifact ? realpath($artifact) : false;
if (!$application || !$artifact || !ctype_digit((string) $port) || !in_array($mode, ['render','windows'], true)) {
    throw new RuntimeException('Usage: contract-pdf-http.php application maria-port existing-artifacts render|windows [source-revision]');
}
if ($mode === 'windows' && PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Native PDF smoke requires actual Windows.');
$source = dirname(__DIR__, 2);
$database = 'contract_pdf_'.bin2hex(random_bytes(8));
$profile = $artifact.'/profile';
$completed = false; $web = null; $pdo = null; $canary = null; $checks = 0;
$receipt = ['format'=>1,'kind'=>'original-contract-pdf-http','mode'=>$mode,'desktopLocal'=>true,
    'originalPublicEntrypoint'=>true,'privateDatabase'=>true,'providersReplaced'=>false,
    'productionCredentialsCopied'=>false,'fullDashboard'=>false,'completed'=>false,
    'scopeQualification'=>'Existing AdminScope filters JSON/API/chat requests. Ordinary HTML retains shared-template visibility, as contracts.show does.',
    'attempts'=>[]];
register_shutdown_function(function () use (&$completed, &$web, &$pdo, &$canary, &$receipt, &$checks, $database, $artifact) {
    if (is_resource($web)) {
        proc_terminate($web); $stopped=false;
        for ($attempt=0;$attempt<100;$attempt++) { if (!proc_get_status($web)['running']) { $stopped=true; break; } usleep(50000); }
        proc_close($web); $receipt['httpServerStopped']=$stopped;
        if (!$stopped) { $completed=false; $receipt['cleanupFailure']='Private HTTP child did not stop.'; }
    }
    if (is_resource($canary)) fclose($canary);
    if ($pdo) { $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`'); $receipt['databaseDropped']=true; }
    $receipt['completed']=$completed; $receipt['checks']=$checks;
    file_put_contents($artifact.'/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL);
    if (!$completed) { fwrite(STDERR, 'Contract PDF HTTP fixture did not complete.'.PHP_EOL); exit(1); }
});
function verifyContractPdf(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++; echo 'PASS '.$message.PHP_EOL;
}
$failure = function (Throwable $error) use (&$receipt) {
    $receipt['failure']=['class'=>get_class($error),'message'=>$error->getMessage()];
    fwrite(STDERR, $error.PHP_EOL); exit(1);
};
set_exception_handler($failure);
function contractPdfCommand(array $command, string $directory): string {
    $process=proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, $directory, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start proof command.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('Proof command failed: '.$err);
    return $out;
}
$manifestPath=dirname($application).'/manifest.json';
$manifest=json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$revision=$argv[5]??$manifest['sourceRevision'];
verifyContractPdf(preg_match('/^[a-f0-9]{40}$/D', $revision)===1 && $revision===$manifest['sourceRevision'], 'runtime and requested source revisions match');
verifyContractPdf(($manifest['fullDashboard']??null)===false, 'runtime remains a preview with fullDashboard false');
foreach ($manifest['sourceHashes'] as $file=>$hash) {
    if (!is_file($application.'/'.$file) || hash_file('sha256', $application.'/'.$file)!==$hash) throw new RuntimeException('Runtime source bytes differ: '.$file);
}
foreach (['app/Http/Controllers/Dashboard/ContractController.php','app/Models/Contract.php','app/Scopes/AdminScope.php','resources/views/admin/contracts/pdf.blade.php','routes/admin.php','desktop/router.php'] as $path) {
    verifyContractPdf(isset($manifest['sourceHashes'][$path]), 'runtime manifest binds '.$path);
}
$receipt['sourceRevision']=$revision;
$receipt['sourceFilesVerified']=count($manifest['sourceHashes']);
$receipt['sourceManifestSha256']=hash_file('sha256', $manifestPath);
$helperPaths=['tests/desktop_dashboard_runtime/contract-pdf-http.php','tests/desktop_dashboard_runtime/verify-contract-pdf.py','tests/desktop_dashboard_runtime/requirements-pdf-smoke.txt','desktop-pos/src/wkhtmltox-windows.json','tests/desktop_dashboard_runtime/full-schema.php','tests/desktop_dashboard_runtime/fixtures/deployed-schema-20261008.json'];
foreach ($helperPaths as $path) {
    $hash=hash_file('sha256', $source.'/'.$path);
    $receipt['fixtureHashes'][$path]=$hash;
    if (getenv('CI')==='true') verifyContractPdf(hash('sha256', contractPdfCommand(['git','show',$revision.':'.$path], $source))===$hash, 'CI helper bytes belong to the exact runtime revision: '.$path);
}
$receipt['helperBinding']=getenv('CI')==='true'?'exact-git-revision':'local-working-tree';
verifyContractPdf(!file_exists($application.'/.env'), 'generated application has no environment file');
foreach (array_keys(getenv()) as $key) if (preg_match('/^(?:APP_|DB_|SESSION_|CACHE_|DESKTOP_)/', $key)) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
foreach (['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $directory) mkdir($profile.'/'.$directory, 0700, true);
$reservation=stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
verifyContractPdf(is_resource($reservation), 'private HTTP port reservation succeeds');
$httpPort=(int)substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1); fclose($reservation);
$origin='http://127.0.0.1:'.$httpPort; $browserToken=bin2hex(random_bytes(32));
foreach (['APP_ENV'=>'desktop','APP_DEBUG'=>'false','APP_URL'=>$origin,'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$database,'DB_USERNAME'=>'root','DB_PASSWORD'=>'',
    'SESSION_DRIVER'=>'file','CACHE_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'log','DESKTOP_DASHBOARD_LOCAL'=>'true',
    'DESKTOP_DASHBOARD_ENABLED'=>'false','DESKTOP_DASHBOARD_STORAGE'=>$profile,'DESKTOP_DASHBOARD_ORIGIN'=>$origin,'DESKTOP_DASHBOARD_TOKEN'=>$browserToken,
    'APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value; }
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$receipt['mariaVersion']=$pdo->query('SELECT VERSION()')->fetchColumn(); $receipt['phpVersion']=PHP_VERSION;
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$app=require $application.'/desktop/bootstrap.php';
set_exception_handler($failure);
$receipt['laravel']=Illuminate\Foundation\Application::VERSION;
verifyContractPdf($receipt['laravel']==='8.83.29' && config('desktop_dashboard.local')===true, 'actual original Laravel8 desktop runtime is used');
require __DIR__.'/full-schema.php';
$report=json_decode(file_get_contents(__DIR__.'/fixtures/deployed-schema-20261008.json'), true, 512, JSON_THROW_ON_ERROR);
DesktopFullSchemaFixture::create($report['tables']);
verifyContractPdf((int)$pdo->query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='.$pdo->quote($database))->fetchColumn()===106, 'all106 synthetic inspected table shapes exist');
require $application.'/database/migrations/2026_10_08_130000_create_desktop_dashboard_journal.php';
(new CreateDesktopDashboardJournal)->up();
verifyContractPdf((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn()===1, 'foreign keys remain enabled');
$stamp='2000-01-01 00:00:00';
foreach ([30,31] as $id) DB::table('users')->insert(['id'=>$id,'added_by'=>null,'name'=>'Fixture Contract Reader '.$id,'email'=>'contract-'.$id.'@test.invalid','mobile'=>'12000000'.$id,'password'=>password_hash('Fixture123', PASSWORD_BCRYPT),'account_type'=>'vendor','status'=>'accepted','app_scope'=>'fasakhansta','balance'=>0,'created_at'=>$stamp]);
$actor=App\Models\User::withoutGlobalScopes()->findOrFail(30);
$role=Spatie\Permission\Models\Role::create(['name'=>'Fixture Contract Reader','guard_name'=>'admin']); $actor->assignRole($role);
$permission=Spatie\Permission\Models\Permission::create(['name'=>'contract-list','guard_name'=>'admin']); $actor->givePermissionTo($permission);
verifyContractPdf(!$actor->hasRole('Super Admin') && $actor->getAllPermissions()->pluck('name')->all()===['contract-list'], 'reader has only the original contract-list permission');
foreach ((new ReflectionClass(App\Models\GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) DB::table('settings')->insert(['group'=>'general','name'=>$property->getName(),'locked'=>false,'payload'=>json_encode($property->getName()==='site_name'?'Contract PDF Fixture':($property->getType()?->getName()==='bool'?true:'0'))]);
$canary=stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
verifyContractPdf(is_resource($canary), 'independent external-asset canary binds to loopback'); stream_set_blocking($canary, false);
$canaryUrl='http://'.stream_socket_get_name($canary, false).'/contract-asset';
$sentinel=$profile.'/private/never-execute.txt'; file_put_contents($sentinel, 'unchanged');
$localUrl='file:///'.str_replace('\\','/',ltrim($sentinel, '/'));
$template='<p>CONTRACT_PDF_OWNED_SENTINEL_30</p><p>عقد تجريبي &amp; نص محفوظ</p><div>[contractDate] | [contractDay] | [vendorName] | [vendorMobile]</div>'
    .'<p>First line<br>Second line &lt;literal&gt; {{ 7 * 7 }} @php echo "NEVER_EVALUATED"; @endphp</p>'
    .'<img src="'.$canaryUrl.'"><iframe src="'.$localUrl.'"></iframe><link rel="stylesheet" href="'.$canaryUrl.'">'
    .'<script>fetch("'.$canaryUrl.'?script");</script><style>@import url("'.$canaryUrl.'?css");</style>';
$expected="CONTRACT_PDF_OWNED_SENTINEL_30\nعقد تجريبي & نص محفوظ\n[contractDate] | [contractDay] | [vendorName] | [vendorMobile]\nFirst line\nSecond line <literal> {{ 7 * 7 }} @php echo \"NEVER_EVALUATED\"; @endphp\n"
    .'fetch("'.$canaryUrl.'?script");@import url("'.$canaryUrl.'?css");';
DB::table('contracts')->insert([
    ['id'=>100,'added_by'=>30,'type'=>'vendor','template'=>$template,'created_at'=>null,'updated_at'=>null],
    ['id'=>101,'added_by'=>31,'type'=>'delegate','template'=>'FOREIGN_CONTRACT_SENTINEL_31','created_at'=>null,'updated_at'=>null],
]);
$phpCommand=[PHP_BINARY];
if (php_ini_loaded_file()) array_push($phpCommand, '-c', php_ini_loaded_file());
$web=proc_open([...$phpCommand,'-d','memory_limit=256M','-S','127.0.0.1:'.$httpPort,'-t',$application.'/public',$application.'/desktop/router.php'], [['pipe','r'],['file',$artifact.'/http-out.log','a'],['file',$artifact.'/http-error.log','a']], $pipes, $application, getenv(), ['bypass_shell'=>true]);
verifyContractPdf(is_resource($web), 'original private desktop HTTP gateway starts'); fclose($pipes[0]);
$ready=false; for ($attempt=0;$attempt<120;$attempt++) { if (!proc_get_status($web)['running']) break; $socket=@stream_socket_client('tcp://127.0.0.1:'.$httpPort, $errno, $error, .1); if ($socket) { fclose($socket); $ready=true; break; } usleep(50000); }
verifyContractPdf($ready, 'private original HTTP server becomes ready');
$cookies=[];
$http=function (string $path, ?array $form=null, string $accept='text/html') use ($origin, $browserToken, &$cookies, &$receipt) {
    $headers=['X-Fasakhansta-Desktop: '.$browserToken,'Accept: '.$accept];
    if ($cookies) $headers[]='Cookie: '.implode('; ', array_map(fn($key,$value)=>$key.'='.$value, array_keys($cookies), $cookies));
    if ($form!==null) $headers[]='Content-Type: application/x-www-form-urlencoded';
    $context=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$form===null?'':http_build_query($form),'ignore_errors'=>true,'timeout'=>45,'follow_location'=>0]]);
    $start=microtime(true); $body=@file_get_contents($origin.$path, false, $context); $duration=microtime(true)-$start;
    $responseHeaders=$http_response_header??[]; preg_match('/^HTTP\/\S+ (\d+)/', $responseHeaders[0]??'', $status);
    foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]]=$match[2];
    $receipt['attempts'][]=['request'=>($form===null?'GET ':'POST ').$path,'accept'=>$accept,'status'=>(int)($status[1]??0),'bytes'=>strlen($body===false?'':$body),'seconds'=>round($duration,3),'bodySha256'=>hash('sha256',$body===false?'':$body)];
    return [(int)($status[1]??0),$body===false?'':$body,$responseHeaders,$duration];
};
foreach (['/admin/pdfview-contract?id=100','/admin/pdfview-contract?id=100&download=1'] as $path) verifyContractPdf($http($path)[0]===302, 'original authentication rejects anonymous '.$path);
[$status,$body]=$http('/admin/login'); preg_match('/name="_token" value="([^"]+)"/', $body, $token);
verifyContractPdf($status===200 && !empty($token[1]), 'original login supplies genuine CSRF and cookies');
verifyContractPdf($http('/admin/signin', ['_token'=>$token[1],'email'=>'contract-30@test.invalid','password'=>'Fixture123'])[0]===302, 'synthetic reader signs in through the original controller');
$databaseHashes=function (): array {
    $result=[];
    foreach (DB::select('SHOW TABLES') as $row) {
        $table=array_values((array)$row)[0]; $rows=array_map(fn($value)=>json_encode((array)$value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), DB::table($table)->get()->all()); sort($rows, SORT_STRING);
        $result[$table]=hash('sha256', implode("\n",$rows));
    }
    ksort($result); return $result;
};
$before=$databaseHashes();
function contractPdfText(string $html): string {
    $document=new DOMDocument(); @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $nodes=(new DOMXPath($document))->query('//div[@class="contract-text"]');
    if ($nodes->length!==1) throw new RuntimeException('Exactly one escaped contract-text element is required.');
    return $nodes->item(0)->textContent;
}
[$status,$body]=$http('/admin/pdfview-contract?id=100');
verifyContractPdf($status===200 && contractPdfText($body)===$expected, 'real Blade renders the exact saved template as escaped plain text with preserved line breaks and placeholders');
$document=new DOMDocument(); @$document->loadHTML($body);
verifyContractPdf((new DOMXPath($document))->query('//script | //img | //iframe | //link | //*[@src] | //*[@href]')->length===0, 'rendered document contains no executable or fetchable asset elements');
verifyContractPdf(!preg_match('/(?:url\s*\(|@import)/i', implode('',array_map(fn($node)=>$node->textContent, iterator_to_array($document->getElementsByTagName('style'))))), 'inline CSS contains no external or local resource references');
foreach (['[contractDate]','[contractDay]','[vendorName]','[vendorMobile]','{{ 7 * 7 }}'] as $placeholder) verifyContractPdf(str_contains(contractPdfText($body),$placeholder), 'literal template token remains unchanged: '.$placeholder);
$receipt['renderedTextSha256']=hash('sha256', contractPdfText($body));
$receipt['renderedHtmlSha256']=hash('sha256', $body);
file_put_contents($artifact.'/contract_100.html', $body);
$receipt['templateSha256']=hash('sha256', $template);
verifyContractPdf($http('/admin/pdfview-contract?id=100', null, 'application/json')[0]===200, 'existing JSON scope permits the reader-owned contract');
verifyContractPdf($http('/admin/pdfview-contract?id=101', null, 'application/json')[0]===404, 'existing AdminScope returns404 for a foreign template on JSON reads');
verifyContractPdf($http('/admin/pdfview-contract?id=101&download=1', null, 'application/json')[0]===404, 'foreign scoped PDF is rejected before native execution');
verifyContractPdf($http('/admin/pdfview-contract?id=101')[0]===200, 'ordinary HTML retains the existing shared-template visibility of contracts.show');
foreach (['/admin/pdfview-contract?id=999999','/admin/pdfview-contract?id=999999&download=1','/admin/pdfview-contract','/admin/pdfview-contract?id=100invalid','/admin/pdfview-contract?id[]=100','/admin/pdfview-contract?id=-1'] as $path) verifyContractPdf($http($path)[0]===404, 'missing or malformed contract ID returns404: '.$path);
$actor->revokePermissionTo('contract-list'); app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
foreach (['/admin/pdfview-contract?id=100','/admin/pdfview-contract?id=100&download=1'] as $path) verifyContractPdf($http($path)[0]===403, 'original contract-list middleware denies the signed-in reader after revocation: '.$path);
$actor->givePermissionTo('contract-list'); app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
verifyContractPdf($http('/admin/pdfview-contract?id=100')[0]===200, 'restoring the original read permission restores rendering');
$wrapper=app('snappy.pdf.wrapper');
verifyContractPdf(get_class($wrapper)===Barryvdh\Snappy\PdfWrapper::class && get_class($wrapper->snappy())===Barryvdh\Snappy\IlluminateSnappyPdf::class && $wrapper->snappy() instanceof Knp\Snappy\Pdf, 'actual Barryvdh wrapper and Knp Snappy generator are installed without a fake facade');
$receipt['pdfClasses']=[get_class($wrapper),get_class($wrapper->snappy())];
if ($mode==='windows') {
    $runtime=$manifest['pdfRuntime']??null;
    verifyContractPdf(is_array($runtime) && $runtime['name']==='wkhtmltox' && $runtime['version']==='0.12.6-1' && $runtime['platform']==='win32-x64', 'manifest identifies the pinned Windows wkhtmltox runtime');
    verifyContractPdf(hash_file('sha256', $source.'/desktop-pos/src/wkhtmltox-windows.json')===($runtime['policySha256']??null), 'native manifest binds the exact revision PDF runtime policy');
    $exe=realpath(dirname($application).'/wkhtmltox/bin/wkhtmltopdf.exe');
    verifyContractPdf($exe!==false && strcasecmp((string)realpath(config('snappy.pdf.binary')), $exe)===0, 'unmodified application configuration selects the sibling bundled Windows executable');
    verifyContractPdf(hash_file('sha256',$exe)===($runtime['files']['wkhtmltox/bin/wkhtmltopdf.exe']??null), 'native PDF executable matches its bundled manifest hash');
    $version=trim(contractPdfCommand([$exe,'--version'], $application));
    verifyContractPdf(preg_match('/^wkhtmltopdf 0\.12\.6(?:\s|$)/', $version)===1, 'actual bundled executable reports wkhtmltopdf0.12.6');
    [$status,$pdf,$headers,$duration]=$http('/admin/pdfview-contract?id=100&download=1');
    verifyContractPdf($status===200 && in_array('Content-Type: application/pdf',$headers,true) && in_array('Content-Disposition: attachment; filename="contract_100.pdf"',$headers,true), 'actual original authenticated route downloads a PDF with the saved contract ID');
    verifyContractPdf(str_starts_with($pdf,'%PDF-') && preg_match('/%%EOF\s*$/D',$pdf)===1 && str_contains($pdf,'startxref'), 'native PDF has a genuine signature, cross-reference trailer and end marker');
    $pages=preg_match_all('/\/Type\s*\/Page\b/',$pdf);
    verifyContractPdf($pages>=1 && $pages<=3 && strlen($pdf)>1000 && strlen($pdf)<524288 && $duration<30, 'native contract PDF stays within page, byte and execution-time bounds');
    verifyContractPdf(!preg_match('/\/(?:JavaScript|JS|OpenAction|AA|Launch|URI|EmbeddedFile|Filespec)\b/',$pdf), 'native PDF contains no script, launch, attachment or external-link actions');
    file_put_contents($artifact.'/contract_100.pdf',$pdf);
    $receipt['nativePdf']=['binarySha256'=>hash_file('sha256',$exe),'version'=>$version,'bytes'=>strlen($pdf),'pages'=>$pages,'seconds'=>round($duration,3),'sha256'=>hash('sha256',$pdf),'originalHttpDownload'=>true];
} else $receipt['nativePdf']=['executed'=>false,'reason'=>'render mode; native Windows execution is a separate required gate'];
verifyContractPdf(@stream_socket_accept($canary,0)===false, 'contract rendering and native generation make no external-asset canary requests');
verifyContractPdf(file_get_contents($sentinel)==='unchanged', 'template rendering never executes code or modifies the private sentinel');
$after=$databaseHashes();
verifyContractPdf($after===$before && DB::table('desktop_dashboard_commands')->count()===0, 'all business/auth rows and the empty desktop journal remain byte-equivalent after reads and restored permission checks');
$receipt['databaseBeforeSha256']=hash('sha256', json_encode($before));
$receipt['databaseAfterSha256']=hash('sha256', json_encode($after));
$receipt['databaseTablesUnchanged']=count($before); $receipt['journalCommands']=0; $receipt['externalAssetRequests']=0;
$completed=true;
echo 'CONTRACT_PDF_HTTP_COMPLETE '.json_encode(['mode'=>$mode,'sourceRevision'=>$revision,'checks'=>$checks,'nativePdf'=>$mode==='windows','fullDashboard'=>false]).PHP_EOL;
