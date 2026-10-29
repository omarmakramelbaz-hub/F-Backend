<?php
// The desktop router must stop opcache from re-checking every included file on each request.
// Runs the real desktop/router.php under PHP's built-in server with the desktop's opcache settings.
declare(strict_types=1);

function fail(string $message): void { fwrite(STDERR, "FAIL $message\n"); exit(1); }

$probe = [PHP_BINARY, '-d', 'zend_extension=opcache', '-r', 'echo extension_loaded("Zend OPcache") ? "yes" : "no";'];
$loaded = trim((string) shell_exec(implode(' ', array_map('escapeshellarg', $probe)) . ' 2>/dev/null'));
if ($loaded !== 'yes') {
    $loaded = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo extension_loaded("Zend OPcache") ? "yes" : "no";')));
    if ($loaded !== 'yes') { echo "DESKTOP_ROUTER_OPCACHE_SKIPPED opcache is unavailable\n"; exit(0); }
    $extension = [];
} else {
    $extension = ['-d', 'zend_extension=opcache'];
}

function serve(array $extension, string $router, bool $withSetting): array
{
    $root = sys_get_temp_dir() . '/go-erp-router-' . bin2hex(random_bytes(6));
    mkdir($root . '/desktop', 0700, true); mkdir($root . '/public', 0700);
    $source = file_get_contents($router);
    if (!$withSetting) {
        $source = str_replace("ini_set('opcache.revalidate_freq', '60');", '', $source, $count);
        if ($count !== 1) fail('the router setting must appear exactly once');
    }
    file_put_contents($root . '/desktop/router.php', $source);
    // The router's control endpoint loads this fixture, which includes one more file like the app does.
    file_put_contents($root . '/desktop/control.php', '<?php include __DIR__."/probe.php"; header("Content-Type: application/json");'
        . 'echo json_encode(["freq"=>ini_get("opcache.revalidate_freq"),"value"=>probe_value()]);');
    $write = function (string $value) use ($root) {
        file_put_contents($root . '/desktop/probe.php', '<?php function probe_value(){ return "' . $value . '"; }');
        // Old enough for opcache's file update protection, but different from the cached timestamp.
        touch($root . '/desktop/probe.php', time() - ($value === 'v1' ? 3600 : 3000));
    };
    $write('v1');
    touch($root . '/desktop/router.php', time() - 3600); touch($root . '/desktop/control.php', time() - 3600);
    $socket = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) explode(':', stream_socket_get_name($socket, false))[1]; fclose($socket);
    $token = bin2hex(random_bytes(16)); $origin = 'http://127.0.0.1:' . $port;
    $env = ['DESKTOP_DASHBOARD_LOCAL' => 'true', 'DESKTOP_DASHBOARD_TOKEN' => $token, 'DESKTOP_DASHBOARD_ORIGIN' => $origin,
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $command = array_merge([PHP_BINARY], $extension, ['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0', '-d', 'opcache.file_update_protection=0',
        '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/desktop/router.php']);
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    $get = function () use ($origin, $token, $port) {
        $context = stream_context_create(['http' => ['header' => "X-Fasakhansta-Desktop: $token\r\nHost: 127.0.0.1:$port\r\n", 'ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents($origin . '/_desktop/control', false, $context);
        return $body === false ? null : json_decode($body, true);
    };
    $first = null;
    for ($i = 0; $i < 50 && $first === null; $i++) { usleep(100000); $first = $get(); }
    $second = $get();
    $write('v2');
    $third = $get();
    proc_terminate($server); proc_close($server);
    array_map('unlink', glob($root . '/desktop/*')); rmdir($root . '/desktop'); rmdir($root . '/public'); rmdir($root);
    return [$first, $second, $third];
}

$router = dirname(__DIR__) . '/desktop/router.php';
[$first, , $third] = serve($extension, $router, true);
if (($first['freq'] ?? null) !== '60') fail('requests after the router run with opcache.revalidate_freq=60');
if (($third['value'] ?? null) !== 'v1') fail('an unchanged-generation file is served from opcache without a per-request timestamp check');
[$before, , $after] = serve($extension, $router, false);
if (($before['freq'] ?? null) !== '0' || ($after['value'] ?? null) !== 'v2') fail('the control run must show per-request revalidation without the setting');
echo "DESKTOP_ROUTER_OPCACHE_TESTS=2 PASS\n";
