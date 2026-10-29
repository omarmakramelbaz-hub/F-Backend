<?php
// php -S router: every request boots the dashboard app against the test database.
require __DIR__.'/env.php';
$app = require GOERP_ROOT.'/bootstrap/app.php';
$app->booted(function ($app) { goerp_settings($app); });
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Illuminate\Http\Request::capture());
$response->send();
$kernel->terminate($request, $response);
