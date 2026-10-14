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
              'Dashboard/MetaInboxReadController', 'Dashboard/MetaReplyController', 'Dashboard/MetaMessageActionsController',
              'Dashboard/MetaConnectionController'] as $controller) {
        require __DIR__.'/../app/Http/Controllers/'.$controller.'.php';
    }
    require __DIR__.'/../app/Http/Middleware/MetaOAuthCallbackPrivacy.php';
    $checks = 0;
    function routeCheck($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function fixtureRouter($omit = null, $shadow = false, $legacy = false) {
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
        $router->group(['middleware' => 'web'], function () use ($omit, $legacy) {
            foreach (['meta_inbox', 'meta_orders', 'meta_reads', 'meta_replies'] as $file) {
                if ($file === $omit) continue;
                if ($file === 'meta_inbox' && $legacy) {
                    // Rebuild only the established 25-route contract for a pre-OAuth installation.
                    foreach (MetaMessagingRouteCheck::CONTRACTS as $name => [$method, $uri, $controller, $action]) {
                        if (!in_array($name, ['meta-inbox.index', 'meta-inbox.conversations', 'meta-inbox.messages', 'meta-inbox.media', 'meta-inbox.hide'], true)) continue;
                        $route = \Illuminate\Support\Facades\Route::{strtolower($method)}($uri,
                            ['App\\Http\\Controllers\\'.$controller, $action])->middleware(['lang', 'IsAdmin'])->name($name);
                        foreach (['conversation', 'message', 'index'] as $parameter) {
                            if (strpos($uri, '{'.$parameter.'}') !== false) $route->whereNumber($parameter);
                        }
                    }
                } else require __DIR__.'/../routes/'.$file.'.php';
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
    routeCheck(MetaMessagingRouteCheck::verify($router) === ['ok' => true, 'verified_routes' => 29], 'All actual Meta route files and OAuth routes verified');
    routeCheck(MetaMessagingRouteCheck::verify(fixtureRouter(null, false, true)) === ['ok' => true, 'verified_routes' => 25], 'Pre-OAuth installed route set remains valid');
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
    foreach (['meta-inbox.hide', 'meta-replies.attachment', 'meta-replies.forward'] as $name) {
        $router = fixtureRouter();
        $router->getRoutes()->getByName($name)->withoutMiddleware('App\\Http\\Middleware\\VerifyCsrfToken');
        rejectedRoutes($router, 'ADMIN_MIDDLEWARE_EXCLUDED', $name);
    }
    foreach (['meta-connection.start', 'meta-connection.confirm'] as $name) {
        $router = fixtureRouter();
        $router->getRoutes()->getByName($name)->withoutMiddleware('App\\Http\\Middleware\\VerifyCsrfToken');
        rejectedRoutes($router, 'ADMIN_MIDDLEWARE_EXCLUDED', $name);
    }
    $router = fixtureRouter();
    $router->getRoutes()->getByName('meta-connection.callback')->withoutMiddleware('App\\Http\\Middleware\\MetaOAuthCallbackPrivacy');
    rejectedRoutes($router, 'OAUTH_PRIVACY_EXCLUDED', 'meta-connection.callback');
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-connection.callback');
    $action = $route->getAction(); $action['middleware'] = ['web', 'lang', 'IsAdmin', 'App\\Http\\Middleware\\MetaOAuthCallbackPrivacy']; $route->setAction($action);
    rejectedRoutes($router, 'OAUTH_PRIVACY_MIDDLEWARE', 'meta-connection.callback');
    $router = fixtureRouter(null, false, true);
    $router->get('admin/meta/connection/callback', [\App\Http\Controllers\Dashboard\MetaConnectionController::class, 'callback'])
        ->middleware(['web', 'App\\Http\\Middleware\\MetaOAuthCallbackPrivacy', 'lang', 'IsAdmin'])->name('meta-connection.callback');
    $router->getRoutes()->refreshNameLookups();
    rejectedRoutes($router, 'MISSING_ROUTE', 'meta-connection.index');
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-inbox.media');
    $action = $route->getAction(); $action['middleware'] = ['web', 'lang']; $route->setAction($action);
    rejectedRoutes($router, 'ADMIN_MIDDLEWARE', 'meta-inbox.media');
    $router = fixtureRouter();
    unset($router->getRoutes()->getByName('meta-inbox.media')->wheres['index']);
    rejectedRoutes($router, 'ROUTE_PARAMETER_CONSTRAINT', 'meta-inbox.media');
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-messaging.webhook.receive');
    $action = $route->getAction(); $action['excluded_middleware'] = []; $route->setAction($action);
    rejectedRoutes($router, 'CALLBACK_SCHEMA_EXCLUSION', 'meta-messaging.webhook.receive');
    $router = fixtureRouter();
    $router->getRoutes()->getByName('meta-messaging.webhook.verify')->middleware('IsAdmin');
    rejectedRoutes($router, 'CALLBACK_MIDDLEWARE', 'meta-messaging.webhook.verify');
    $router = fixtureRouter();
    $router->getRoutes()->getByName('meta-replies.temporary-media')->middleware('web');
    rejectedRoutes($router, 'CALLBACK_MIDDLEWARE', 'meta-replies.temporary-media');
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-replies.temporary-media');
    $action = $route->getAction(); $action['excluded_middleware'] = []; $route->setAction($action);
    rejectedRoutes($router, 'CALLBACK_SCHEMA_EXCLUSION', 'meta-replies.temporary-media');
    $router = fixtureRouter();
    unset($router->getRoutes()->getByName('meta-replies.temporary-media')->wheres['ticket']);
    rejectedRoutes($router, 'ROUTE_PARAMETER_CONSTRAINT', 'meta-replies.temporary-media');
    $router = fixtureRouter();
    $methods = new \ReflectionProperty(\Illuminate\Routing\Route::class, 'methods');
    $methods->setAccessible(true);
    $methods->setValue($router->getRoutes()->getByName('meta-replies.forward'), ['GET', 'POST']);
    rejectedRoutes($router, 'ROUTE_METHODS', 'meta-replies.forward');
    $router = fixtureRouter();
    $router->match(['POST', 'GET'], 'admin/meta/conversations/{conversation}/forward',
        [\App\Http\Controllers\Dashboard\MetaReplyController::class, 'forward'])->middleware(['web', 'lang', 'IsAdmin'])
        ->name('meta-replies.forward');
    $router->getRoutes()->refreshNameLookups();
    rejectedRoutes($router, 'DUPLICATE_META_ROUTE', 'meta-replies.forward');
    $router = fixtureRouter();
    $router->get('admin/meta/unreviewed-write', function () {})->name('unreviewed.endpoint');
    $router->getRoutes()->refreshNameLookups();
    rejectedRoutes($router, 'UNEXPECTED_META_ROUTE', 'UNREGISTERED_META_ROUTE');
    $router = fixtureRouter();
    foreach ([
        '/admin/meta/conversations/1/messages/customer/media/0',
        '/admin/meta/conversations/1/messages/1/media/private-token',
        '/admin/meta/conversations/customer/messages/1/hide',
        '/api/meta/messaging/media/private-token',
    ] as $path) {
        try { $router->getRoutes()->match(\Illuminate\Http\Request::create($path, strpos($path, '/hide') !== false ? 'POST' : 'GET'));
            routeCheck(false, 'Non-numeric private media/action parameters rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $error) {
            routeCheck(true, 'Non-numeric private media/action parameters rejected');
        }
    }
    foreach (['/hide', '/attachments', '/forward'] as $suffix) {
        $path = $suffix === '/hide' ? '/admin/meta/conversations/1/messages/1/hide' : '/admin/meta/conversations/1'.$suffix;
        try { $router->getRoutes()->match(\Illuminate\Http\Request::create($path, 'GET')); routeCheck(false, 'Write action requires POST'); }
        catch (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $error) { routeCheck(true, 'Write action requires POST'); }
    }
    foreach (['start', 'confirm'] as $suffix) {
        try { $router->getRoutes()->match(\Illuminate\Http\Request::create('/admin/meta/connection/'.$suffix, 'GET'));
            routeCheck(false, 'OAuth connection write requires POST'); }
        catch (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $error) { routeCheck(true, 'OAuth connection write requires POST'); }
    }
    $router = fixtureRouter();
    $route = $router->getRoutes()->getByName('meta-orders.dispatch');
    $action = $route->getAction(); $action['uses'] = 'App\\Http\\Controllers\\Dashboard\\MetaReplyController@send'; $route->setAction($action);
    rejectedRoutes($router, 'CONTROLLER_CONTRACT', 'meta-orders.dispatch');
    rejectedRoutes(fixtureRouter(null, true), 'ROUTE_SHADOWED', 'meta-inbox.index');
    $router = fixtureRouter();
    $router->setCompiledRoutes($router->getRoutes()->compile());
    routeCheck(MetaMessagingRouteCheck::verify($router)['verified_routes'] === 29, 'Cached route collection supported');
    $error = new \RuntimeException('Class "App\\Missing\\Controller" not found; private-token=do-not-print');
    $failure = MetaMessagingRouteCheck::failure($error, 'bootstrap');
    routeCheck(($failure['missing_class'] ?? null) === 'App\\Missing\\Controller', 'Missing dependency identifier retained');
    routeCheck(strpos(json_encode($failure), 'private-token') === false, 'No raw exception text exposed');
    echo 'meta-routes: '.$checks." checks passed\n";
}
