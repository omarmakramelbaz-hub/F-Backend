<?php
/** Resolve the real Kernel schedule AND provider hooks without running commands,
 * loading application configuration, or connecting to any database. */
require $argv[1] ?? __DIR__.'/vendor/autoload.php';

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

$root = dirname(__DIR__, 2);
require $root.'/app/Console/Kernel.php';
require $root.'/app/Providers/RouteServiceProvider.php';

$app = new Application($root);
$app->instance('config', new Repository([
    'app' => ['timezone' => 'UTC'],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
]));
Facade::setFacadeApplication($app);
(new CacheServiceProvider($app))->register();
// Kernel registers the schedule when the application finishes booting.
$kernel = new App\Console\Kernel($app, $app['events']);
// Execute the application's actual boot method, including afterResolving hooks.
// HTTP route loading is deliberately not registered in this isolated test.
(new App\Providers\RouteServiceProvider($app))->boot();
$app->boot();

$schedule = $app->make(Schedule::class);
$dispatch = array_values(array_filter($schedule->events(),
    static fn ($event) => str_contains($event->command ?? '', 'go-services:dispatch')));
if (count($dispatch) !== 1) {
    throw new RuntimeException('Expected one GO dispatch event; found '.count($dispatch).'.');
}
if ($dispatch[0]->expression !== '* * * * *' || !$dispatch[0]->withoutOverlapping) {
    throw new RuntimeException('GO dispatch must run every minute with overlap protection.');
}
if ($app->make(Schedule::class) !== $schedule) {
    throw new RuntimeException('The scheduler must remain a shared singleton.');
}
echo "PASS real Laravel Kernel/provider schedule: one GO event, every minute, overlap protected. No commands executed.\n";

require $root.'/deployment/go_service_routes.php';
$router = new \Illuminate\Routing\Router($app['events'], $app);
$app->instance('router', $router);
Facade::clearResolvedInstance('router');
// Reproduce the production failure: a legacy route references a missing class.
$router->get('/dashboard/blogs', 'App\\Http\\Controllers\\Dashboard\\BlogController@index');
$router->prefix('api')->group($root.'/routes/go_services.php');
if (goServiceRouteIssues($router) !== []) {
    throw new RuntimeException('Valid GO routes must pass despite an unrelated missing controller.');
}
$router->get('/api/go-services/capabilities', static fn () => null);
if (!in_array('GET /api/go-services/capabilities targets the wrong action.', goServiceRouteIssues($router), true)) {
    throw new RuntimeException('The release check accepted an incorrectly routed GO endpoint.');
}
$router->post('/api/go-services/jobs', [\App\Http\Controllers\Api\V1\GoServiceMarketplaceController::class, 'store']);
if (!in_array('POST /api/go-services/jobs is missing auth:api.', goServiceRouteIssues($router), true)) {
    throw new RuntimeException('The release check accepted a GO write route without authentication.');
}
$router->setRoutes(new \Illuminate\Routing\RouteCollection());
if (count(goServiceRouteIssues($router)) !== 14) {
    throw new RuntimeException('The release check must reject missing GO routes.');
}
echo "PASS GO route matching with missing legacy controller; wrong targets, absent auth and missing routes rejected. No controllers executed.\n";
