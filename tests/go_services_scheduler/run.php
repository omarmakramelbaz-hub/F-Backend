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
