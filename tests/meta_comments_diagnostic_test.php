<?php

/** Run the exact read-only diagnostic against Laravel 8 Http::fake in an isolated app. */
$vendor = getenv('WA_INBOX_TEST_VENDOR');
if (is_dir($vendor)) $vendor .= '/autoload.php';
if (!is_file($vendor)) throw new RuntimeException('Set WA_INBOX_TEST_VENDOR to Laravel 8 + Guzzle vendor/autoload.php.');
require $vendor;
$httpVendor = getenv('WA_HTTP_TEST_VENDOR');
if (is_dir($httpVendor)) $httpVendor .= '/autoload.php';
if ($httpVendor) {
    if (!is_file($httpVendor)) throw new RuntimeException('WA_HTTP_TEST_VENDOR must be a Guzzle-capable vendor/autoload.php.');
    require $httpVendor;
}
if (!class_exists(\GuzzleHttp\Client::class)) throw new RuntimeException('This diagnostic regression test requires real Guzzle dependencies.');
$fixture = sys_get_temp_dir() . '/meta-comments-diagnostic-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700); mkdir($fixture . '/vendor', 0700); mkdir($fixture . '/bootstrap', 0700);
file_put_contents($fixture . '/vendor/autoload.php', '<?php require ' . var_export($vendor, true) . ';'
    . ($httpVendor ? ' require ' . var_export($httpVendor, true) . ';' : ''));
file_put_contents($fixture . '/bootstrap/app.php', <<<'PHP'
<?php
$app = new \Illuminate\Container\Container;
\Illuminate\Container\Container::setInstance($app);
$app->instance('config', new \Illuminate\Config\Repository([
    'desktop_dashboard' => ['local' => false],
    'meta_messaging' => ['api_version' => 'v25.0', 'app_secret' => 'fixture-private-secret'],
]));
if (!function_exists('config')) {
    function config($key, $default = null) { return \Illuminate\Container\Container::getInstance()->make('config')->get($key, $default); }
}
$app->instance(\Illuminate\Contracts\Console\Kernel::class, new class { public function bootstrap() {} });
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
\Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
// Only the account source is stubbed; the diagnostic's real PendingRequest chain executes.
eval('namespace App\\Services\\Dashboard; class MetaInboxAccess { public static function accounts(): array {
    return [["channel"=>"messenger","account_id"=>"101","access_token"=>"fixture-facebook-private-token"],
        ["channel"=>"instagram","account_id"=>"202","login"=>"facebook","access_token"=>"fixture-instagram-private-token"]];
}}');
$calls = [];
register_shutdown_function(function () use (&$calls) {
    file_put_contents(getenv('META_COMMENTS_DIAGNOSTIC_TRACE'), json_encode($calls, JSON_THROW_ON_ERROR));
});
\Illuminate\Support\Facades\Http::fake(function ($request, $options) use (&$calls) {
    $parts = parse_url($request->url()); $params = []; parse_str($parts['query'] ?? '', $params);
    $path = $parts['path']; $debug = $path === '/v25.0/debug_token';
    $token = $debug ? '2177982783065133|fixture-private-secret'
        : ($path === '/v25.0/101/published_posts' ? 'fixture-facebook-private-token' : 'fixture-instagram-private-token');
    $calls[] = ['method' => $request->method(), 'host' => $parts['host'], 'path' => $path,
        'fixed_options' => ($options['allow_redirects'] ?? null) === false && ($options['verify'] ?? null) === true
            && ($options['connect_timeout'] ?? null) === 3 && ($options['timeout'] ?? null) === 8,
        'auth_valid' => $request->hasHeader('Authorization', 'Bearer ' . $token),
        'proof_valid' => ($params['appsecret_proof'] ?? null) === hash_hmac('sha256', $token, 'fixture-private-secret'),
        'bounded_read' => $debug || (($params['fields'] ?? null) === 'id' && ($params['limit'] ?? null) === '1'),
        'input_token_valid' => !$debug || in_array($params['input_token'] ?? null,
            ['fixture-facebook-private-token', 'fixture-instagram-private-token'], true)];
    $scenario = getenv('META_COMMENTS_DIAGNOSTIC_SCENARIO');
    if ($scenario === 'transport') throw new \GuzzleHttp\Exception\ConnectException(
        'private-body fixture-facebook-private-token', new \GuzzleHttp\Psr7\Request('GET', $request->url()), null, ['errno' => 28]);
    if ($debug) {
        if ($scenario === 'debug-error') return \Illuminate\Support\Facades\Http::response([
            'error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'private-debug-body fixture-private-secret']], 400);
        $scopes = $scenario === 'permission'
            ? ['pages_read_engagement', 'pages_read_user_content', 'instagram_basic']
            : ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement', 'instagram_basic', 'instagram_manage_comments'];
        return \Illuminate\Support\Facades\Http::response(['data' => ['is_valid' => true, 'app_id' => '2177982783065133',
            'scopes' => $scopes, 'user_id' => 'private-user-id', 'raw_private' => 'fixture-private-secret']], 200);
    }
    if ($scenario === 'permission') return \Illuminate\Support\Facades\Http::response([
        'error' => ['code' => $path === '/v25.0/101/published_posts' ? 200 : 10, 'error_subcode' => 2018065,
            'message' => 'private-graph-body fixture-facebook-private-token']], 403);
    return \Illuminate\Support\Facades\Http::response(['data' => [['id' => 'private-post-id',
        'message' => 'private-comment-text fixture-instagram-private-token']]], 200);
});
return $app;
PHP
);
$checks = 0;
function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); }
function diagnosticRun(string $scenario): array {
    global $fixture;
    $trace = $fixture . '/trace-' . $scenario . '.json';
    // Reproduce this process's dynamic extensions without relying on a machine-wide PHP install.
    $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
    foreach (get_loaded_extensions() as $extension) if (is_file(ini_get('extension_dir') . '/' . strtolower($extension) . '.so')) {
        $command[] = '-d'; $command[] = 'extension=' . strtolower($extension);
    }
    $command[] = __DIR__ . '/../deployment/meta_comments_diagnostic.php'; $command[] = $fixture;
    $environment = getenv(); $environment['META_COMMENTS_DIAGNOSTIC_SCENARIO'] = $scenario;
    $environment['META_COMMENTS_DIAGNOSTIC_TRACE'] = $trace;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Diagnostic subprocess unavailable.');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    check($exit === 0 && $stderr === '', 'diagnostic completed cleanly with real Laravel 8 client');
    check(preg_match('/\AMETA_COMMENTS_ACCESS=([^\n]+)\n\z/', $stdout, $match) === 1, 'one safe JSON output line');
    $result = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
    $calls = json_decode(file_get_contents($trace), true, 32, JSON_THROW_ON_ERROR);
    check(count($calls) === 4, 'both post-list and debug-token paths reached for both accounts');
    foreach ($calls as $call) check($call['method'] === 'GET' && $call['host'] === 'graph.facebook.com'
        && $call['fixed_options'] && $call['auth_valid'] && $call['proof_valid'] && $call['bounded_read']
        && $call['input_token_valid'], 'GET only with fixed host, valid bearer/proof, compatible timeout options and bounded reads');
    foreach (['fixture-facebook-private-token', 'fixture-instagram-private-token', 'fixture-private-secret',
        'private-comment-text', 'private-graph-body', 'private-debug-body', 'private-post-id', 'private-user-id', 'access_token'] as $private) {
        check(strpos($stdout, $private) === false, 'output redacts ' . $private);
    }
    return $result;
}
try {
    $ready = diagnosticRun('ready');
    check($ready['check_completed'] && count($ready['accounts']) === 2, 'configured account diagnostics complete');
    foreach ($ready['accounts'] as $account) check($account['posts_readable'] && $account['token_verified']
        && $account['read_status'] === 'READ_READY' && $account['permission_check'] === 'checked'
        && $account['missing_permissions'] === [] && $account['read_http_status'] === 200
        && $account['debug_http_status'] === 200 && $account['read_error_code'] === null, 'ready account capability result');
    $denied = diagnosticRun('permission');
    check($denied['accounts'][0]['missing_permissions'] === ['pages_manage_engagement']
        && $denied['accounts'][1]['missing_permissions'] === ['instagram_manage_comments'], 'missing scopes remain native and account-specific');
    foreach ($denied['accounts'] as $account) check(!$account['posts_readable'] && $account['token_verified']
        && $account['read_status'] === 'PERMISSION_REQUIRED' && $account['read_http_status'] === 403
        && in_array($account['read_error_code'], [10, 200], true) && $account['read_error_subcode'] === 2018065, 'permission rejection exposes only actionable numeric status');
    $debugError = diagnosticRun('debug-error');
    foreach ($debugError['accounts'] as $account) check($account['posts_readable'] && !$account['token_verified']
        && $account['permission_check'] === 'unavailable' && $account['debug_http_status'] === 400
        && $account['debug_error_code'] === 190 && $account['debug_error_subcode'] === 463, 'debug rejection retains safe numeric token diagnostics');
    $transport = diagnosticRun('transport');
    foreach ($transport['accounts'] as $account) check(!$account['posts_readable'] && !$account['token_verified']
        && $account['read_status'] === 'READ_UNAVAILABLE' && $account['read_transport'] === 'TIMEOUT'
        && $account['debug_transport'] === 'TIMEOUT', 'transport exceptions normalized without raw exception text');
    echo "meta-comments-diagnostic: $checks checks passed\n";
} finally {
    foreach (glob($fixture . '/trace-*.json') as $file) unlink($file);
    unlink($fixture . '/vendor/autoload.php'); unlink($fixture . '/bootstrap/app.php');
    rmdir($fixture . '/vendor'); rmdir($fixture . '/bootstrap'); rmdir($fixture);
}
