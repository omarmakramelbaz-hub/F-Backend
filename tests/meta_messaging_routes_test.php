<?php
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload .= '/autoload.php';
    require $autoload;
}
namespace App\Http\Controllers {
    class Controller extends \Illuminate\Routing\Controller {}
    class LegacyBrokenController extends Controller {
        public static $resolved = 0;
        public function __construct() { self::$resolved++; throw new \RuntimeException('legacy fixture exception contains private-token'); }
        public function index() {}
    }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Routing\Router;
    use Illuminate\Support\Facades\Facade;
    use App\Http\Controllers\LegacyBrokenController;
    require __DIR__.'/../deployment/meta_messaging_route_check.php';
    foreach (['Api/V1/MetaMessagingWebhookController', 'Dashboard/MetaInboxController', 'Dashboard/MetaOrderController',
              'Dashboard/MetaInboxReadController', 'Dashboard/MetaReplyController'] as $controller) {
        require __DIR__.'/../app/Http/Controllers/'.$controller.'.php';
    }
    $checks = 0;
    function routeCheck($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function fixtureRouter($omit = null, $shadow = false) {
        $container = new Container;
        Container::setInstance($container);
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        LegacyBrokenController::$resolved = 0;
        $router->get('legacy/broken', [LegacyBrokenController::class, 'index'])->name('legacy.broken');
        if ($shadow) $router->get('admin/{tail}', function () {})->where('tail', '.*')->name('legacy.catchall');
        $router->group(['prefix' => 'api', 'middleware' => 'api'], function () { require __DIR__.'/../routes/meta_messaging.php'; });
        $router->group(['middleware' => 'web'], function () use ($omit) {
            foreach (['meta_inbox', 'meta_orders', 'meta_reads', 'meta_replies'] as $file) {
                if ($file !== $omit) require __DIR__.'/../routes/'.$file.'.php';
            }
        });
        $router->getRoutes()->refreshNameLookups();
        return $router;
    }
    function rejectedRoutes($router, $reason, $route = null) {
        try { MetaMessagingRouteCheck::verify($router); routeCheck(false, 'Expected '.$reason); }
        catch (MetaMessagingRouteCheckFailure $error) {
            routeCheck($error->reason === $reason, 'Failure reason '.$reason);
            if ($route !== null) routeCheck($error->route === $route, 'Failure route');
        }
    }
    $router = fixtureRouter();
    routeCheck(MetaMessagingRouteCheck::verify($router) === ['ok' => true, 'verified_routes' => 20], 'All actual Meta route files verified');
    routeCheck(LegacyBrokenController::$resolved === 0, 'Unrelated controller never resolved');
    try { $router->getRoutes()->getByName('legacy.broken')->gatherMiddleware(); routeCheck(false, 'Legacy fixture must fail'); }
    catch (\RuntimeException $error) { routeCheck(LegacyBrokenController::$resolved === 1, 'Reproduce broad controller enumeration failure'); }
    rejectedRoutes(fixtureRouter('meta_reads'), 'MISSING_ROUTE', 'meta-inbox.unread');
    $router = fixtureRouter();
    // Change the action's declared middleware; an inherited session group alone cannot grant access.
    $route = $router->getRoutes()->getByName('meta-inbox.conversations');
    $action = $route->getAction(); $action['middleware'] = ['web', 'lang']; $route->setAction($action);
    rejectedRoutes($router, 'ADMIN_MIDDLEWARE', 'meta-inbox.conversations');
    $router = fixtureRouter();
    $router->getRoutes()->getByName('meta-replies.send')->withoutMiddleware('IsAdmin');
    rejectedRoutes($router, 'ADMIN_MIDDLEWARE_EXCLUDED', 'meta-replies.send');
    $router = fixtureRouter();
    $router->getRoutes()->getByName('meta-orders.dispatch')->withoutMiddleware('App\\Http\\Middleware\\VerifyCsrfToken');
    rejectedRoutes($router, 'ADMIN_MIDDLEWARE_EXCLUDED', 'meta-orders.dispatch');
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-messaging.webhook.receive');
    $action = $route->getAction(); $action['excluded_middleware'] = []; $route->setAction($action);
    rejectedRoutes($router, 'CALLBACK_SCHEMA_EXCLUSION', 'meta-messaging.webhook.receive');
    $router = fixtureRouter();
    $router->getRoutes()->getByName('meta-messaging.webhook.verify')->middleware('IsAdmin');
    rejectedRoutes($router, 'CALLBACK_MIDDLEWARE', 'meta-messaging.webhook.verify');
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-orders.dispatch');
    $action = $route->getAction(); $action['uses'] = 'App\\Http\\Controllers\\Dashboard\\MetaReplyController@send'; $route->setAction($action);
    rejectedRoutes($router, 'CONTROLLER_CONTRACT', 'meta-orders.dispatch');
    rejectedRoutes(fixtureRouter(null, true), 'ROUTE_SHADOWED', 'meta-inbox.index');
    $router = fixtureRouter();
    $router->setCompiledRoutes($router->getRoutes()->compile());
    routeCheck(MetaMessagingRouteCheck::verify($router)['verified_routes'] === 20, 'Cached route collection supported');
    $error = new \RuntimeException('Class "App\\Missing\\Controller" not found; private-token=do-not-print');
    $failure = MetaMessagingRouteCheck::failure($error, 'bootstrap');
    routeCheck(($failure['missing_class'] ?? null) === 'App\\Missing\\Controller', 'Missing dependency identifier retained');
    routeCheck(strpos(json_encode($failure), 'private-token') === false, 'No raw exception text exposed');
    echo 'meta-routes: '.$checks." checks passed\n";
}
