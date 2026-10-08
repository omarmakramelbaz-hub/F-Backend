<?php
// PHP's built-in server is bound to loopback. Every request also needs the app-only header.
$origin = getenv('DESKTOP_DASHBOARD_ORIGIN');
$token = getenv('DESKTOP_DASHBOARD_TOKEN');
$expectedHost = parse_url($origin ?: '', PHP_URL_HOST).':'.parse_url($origin ?: '', PHP_URL_PORT);
if (getenv('DESKTOP_DASHBOARD_LOCAL') !== 'true' || !$token
    || !hash_equals($token, $_SERVER['HTTP_X_FASAKHANSTA_DESKTOP'] ?? '')
    || ($_SERVER['HTTP_HOST'] ?? '') !== $expectedHost
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin)) {
    http_response_code(403); exit;
}
header('X-Content-Type-Options: nosniff');
$url = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pathname = rawurldecode($url ?: '/');
if (str_contains($pathname, "\0") || str_contains($pathname, '\\') || preg_match('#(?:^|/)\.\.?(/|$)#D', $pathname)) { http_response_code(404); exit; }
if(preg_match('/\.(?:php[0-9]?|phtml|phar)(?:\/|$)/i',$pathname)){http_response_code(404);exit;}
if ($pathname === '/_desktop/health') {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo '{"runtime":"fasakhansta-dashboard","format":1}'; exit;
}
if($pathname==='/_desktop/control'){require __DIR__.'/control.php';exit;}
if(str_starts_with($pathname,'/_desktop/')){http_response_code(404);exit;}
$public = realpath(dirname(__DIR__).'/public');
$root = $public; $relative = $pathname;
if (str_starts_with($pathname, '/storage/')) {
    $root = realpath(getenv('DESKTOP_DASHBOARD_STORAGE').'/app/public');
    $relative = substr($pathname, strlen('/storage'));
}
$file = $root ? realpath($root.$relative) : false;
$extensions = ['js'=>'application/javascript','css'=>'text/css','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','eot'=>'application/vnd.ms-fontobject','mp3'=>'audio/mpeg','wav'=>'audio/wav','json'=>'application/json'];
$extension = strtolower(pathinfo($file ?: '', PATHINFO_EXTENSION));
// CKEditor's bundled dialogs use static HTML; do not extend this allowance to uploaded files.
if ($extension === 'html' && str_starts_with($pathname, '/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/')) {
    $extensions['html'] = 'text/html; charset=utf-8';
}
if ($file && is_file($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && isset($extensions[$extension])) {
    header('Content-Type: '.$extensions[$extension]);
    readfile($file); exit;
}
define('LARAVEL_START', microtime(true));
$app = require __DIR__.'/bootstrap.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
