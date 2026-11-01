<?php
declare(strict_types=1);
// Required by a root-generated public index.php with its fixed private runtime path.
ini_set('display_errors','0');
try{
    if(!isset($goOwnerRuntime)||!is_string($goOwnerRuntime))throw new RuntimeException();
    require '/home/fasakha/public_html/vendor/autoload.php';
    $facts=\App\Services\OwnerDashboards\Bootstrap::read($goOwnerRuntime);
    $host=strtolower(explode(':',(string)($_SERVER['HTTP_HOST']??''))[0]);
    if(!hash_equals($facts['host'],$host))throw new RuntimeException();
    $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
    if(!is_string($path)||!str_starts_with($path,$facts['prefix'].'/'))throw new RuntimeException();
    // Explicit base path also supports cPanel's legacy root -> public rewrite.
    $_SERVER['SCRIPT_NAME']=$facts['prefix'].'/index.php';
    $_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
    define('LARAVEL_START',microtime(true));
    $app=\App\Services\OwnerDashboards\Bootstrap::application($facts);
    $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
    $request=\Illuminate\Http\Request::capture();
    $response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);
}catch(Throwable $error){
    http_response_code(503);header('Cache-Control: private, no-store');header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>لوحة التحكم</title><p>اللوحة غير متاحة حاليًا. تواصل مع الأونر الرئيسي.</p></html>';
}
