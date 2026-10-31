<?php
// php -S router: the dashboard's own files (scripts, styles, images) are served as they are, like on the real server;
// every other request boots the dashboard app against the test database.
$file = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($file) && $file !== '/' && strpos($file, '..') === false && is_file(dirname(__DIR__, 3).'/public'.$file)) return false;
require __DIR__.'/env.php';
$app = require GOERP_ROOT.'/bootstrap/app.php';
$app->booted(function ($app) { goerp_settings($app); });
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Illuminate\Http\Request::capture());
$response->send();
$kernel->terminate($request, $response);
