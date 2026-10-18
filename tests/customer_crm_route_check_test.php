<?php
// Real Laravel 8 router/route files/controllers, isolated container, no .env/DB/network.
require getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require_once $file;
});

use App\Support\CustomerCrmRouteCheck;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;

$checks = 0;
function routeCheck($condition, string $label): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); echo 'PASS '.$label.PHP_EOL; }
function fixtureRouter(bool $broken = true): Router
{
    $container = new Container; Container::setInstance($container);
    $router = new Router(new Dispatcher($container), $container);
    $container->instance('router', $router); $container->instance(Router::class, $router);
    Facade::setFacadeApplication($container); Facade::clearResolvedInstances();
    $router->middlewareGroup('web', ['Illuminate\\Session\\Middleware\\StartSession', 'App\\Http\\Middleware\\VerifyCsrfToken']);
    $router->aliasMiddleware('IsAdmin', 'App\\Http\\Middleware\\IsAdmin');
    $router->aliasMiddleware('lang', 'App\\Http\\Middleware\\lang');
    $router->middlewareGroup('api', []);
    foreach (['auth'=>'Authenticate','CheckLang'=>'CheckLang','app.scope'=>'AppScopeGuard','custom.jwt'=>'CustomJwtAuth'] as $alias=>$class) $router->aliasMiddleware($alias, 'App\\Http\\Middleware\\'.$class);
    $router->middleware('web')->group(dirname(__DIR__).'/routes/takeaway.php');
    $router->middleware('web')->group(dirname(__DIR__).'/routes/pos_service.php');
    $router->prefix('api')->middleware('api')->group(dirname(__DIR__).'/routes/customer_crm_api.php');
    if ($broken) $router->get('unrelated/broken', 'MissingUnrelatedController@neverRun')->name('unrelated.broken');
    $router->getRoutes()->refreshNameLookups();
    return $router;
}
function mustReject(callable $mutate, string $reason, string $label): void
{
    $router=fixtureRouter(); $mutate($router); $router->getRoutes()->refreshNameLookups();
    try { CustomerCrmRouteCheck::verify($router); }
    catch (Throwable $error) { routeCheck(str_starts_with(CustomerCrmRouteCheck::failure($error)['reason'], $reason), $label); return; }
    throw new RuntimeException('Expected route contract rejection: '.$label);
}

$router=fixtureRouter();
$report=CustomerCrmRouteCheck::verify($router);
routeCheck($report === ['ok'=>true,'verified_routes'=>14,'requests_dispatched'=>0], 'all real customer and POS contracts verified without dispatch');
routeCheck($router->getRoutes()->getByName('unrelated.broken')->controller === null, 'unrelated nonexistent controller is never resolved');

// Reproduce Laravel 8 path filtering behavior: middleware resolution happens before filtering.
$command=new class($router) extends \Illuminate\Foundation\Console\RouteListCommand {
    public function option($key=null) { return ['path'=>'admin/customers','sort'=>'uri'][$key] ?? null; }
    public function inspectForTest() { return $this->getRoutes(); }
};
try { $command->inspectForTest(); throw new RuntimeException('Expected unrelated controller resolution'); }
catch (\Illuminate\Contracts\Container\BindingResolutionException $error) { routeCheck(str_contains($error->getMessage(), 'MissingUnrelatedController'), 'route:list --path still resolves an unrelated broken controller on Laravel 8'); }

mustReject(function ($router) { $router->getRoutes()->getByName('customers.consent')->name('removed-consent'); }, 'CRM_ROUTE_UNEXPECTED', 'renamed customer route is rejected');
mustReject(function ($router) { $routes=new \Illuminate\Routing\RouteCollection; foreach ($router->getRoutes()->getRoutes() as $route) if ($route->getName()!=='customers.consent') $routes->add($route); $router->setRoutes($routes); }, 'CRM_ROUTE_MISSING', 'missing customer consent route is rejected');
mustReject(function ($router) { $router->getRoutes()->getByName('customers.consent')->setUri('elsewhere/consent'); }, 'CRM_ROUTE_URI', 'changed customer URI is rejected');
mustReject(function ($router) { $router->getRoutes()->getByName('customers.consent')->methods=['GET','HEAD']; }, 'CRM_ROUTE_METHODS', 'write endpoint cannot silently become a GET route');
mustReject(function ($router) { $r=$router->getRoutes()->getByName('customers.consent'); $a=$r->getAction(); $a['middleware']=['web','lang']; $r->setAction($a); }, 'CRM_ROUTE_AUTH_MIDDLEWARE', 'removed admin authentication is rejected');
mustReject(function ($router) { $router->getRoutes()->getByName('customers.campaign-queue')->withoutMiddleware('App\\Http\\Middleware\\VerifyCsrfToken'); }, 'CRM_ROUTE_MIDDLEWARE_EXCLUDED', 'CSRF exclusion on campaign endpoint is rejected');
mustReject(function ($router) { $routes=new \Illuminate\Routing\RouteCollection; foreach ($router->getRoutes()->getRoutes() as $route) if ($route->getName()!=='customers.feedback-template-prepare') $routes->add($route); $router->setRoutes($routes); }, 'CRM_ROUTE_MISSING:customers.feedback-template-prepare', 'template preparation route must be published with the customer dashboard');
mustReject(function ($router) { $router->getRoutes()->getByName('customers.feedback-template-prepare')->methods=['GET','HEAD']; }, 'CRM_ROUTE_METHODS:customers.feedback-template-prepare', 'creating a provider template requires a POST route');
mustReject(function ($router) { $r=$router->getRoutes()->getByName('customers.feedback-template-prepare'); $a=$r->getAction(); $a['middleware']=['web','lang']; $r->setAction($a); }, 'CRM_ROUTE_AUTH_MIDDLEWARE:customers.feedback-template-prepare', 'template preparation retains admin authentication');
mustReject(function ($router) { $router->getRoutes()->getByName('customers.feedback-template-prepare')->withoutMiddleware('App\\Http\\Middleware\\VerifyCsrfToken'); }, 'CRM_ROUTE_MIDDLEWARE_EXCLUDED:customers.feedback-template-prepare', 'template preparation cannot exclude CSRF protection');
mustReject(function ($router) { $router->aliasMiddleware('IsAdmin', 'UnprotectedMiddleware'); }, 'CRM_ROUTE_EFFECTIVE_MIDDLEWARE', 'wrong authentication alias is rejected');
mustReject(function ($router) { $router->middlewareGroup('web', ['App\\Http\\Middleware\\VerifyCsrfToken']); }, 'CRM_ROUTE_EFFECTIVE_MIDDLEWARE', 'web group missing session protection is rejected');
mustReject(function ($router) { $router->post('duplicate/customer-consent', [\App\Http\Controllers\Dashboard\BranchOperationsController::class, 'customerConsent'])->name('customers.consent'); }, 'CRM_ROUTE_DUPLICATE', 'duplicate feature route names are rejected');
mustReject(function ($router) { $router->post('admin/customers/unregistered', fn()=>null)->name('customers.unregistered'); }, 'CRM_ROUTE_UNEXPECTED', 'unregistered customer entry point is rejected');
mustReject(function ($router) { $r=$router->getRoutes()->getByName('customers.messaging-status'); $a=$r->getAction(); $a['uses']=\App\Http\Controllers\Dashboard\BranchOperationsController::class.'@queueCampaign'; $r->setAction($a); }, 'CRM_ROUTE_ACTION', 'changed action cannot pass a matching route name');
mustReject(function ($router) { Container::getInstance()->bind(\App\Http\Controllers\Dashboard\BranchOperationsController::class, fn()=>new stdClass); }, 'CRM_ROUTE_CONTROLLER_UNAVAILABLE', 'uncallable resolved customer controller is rejected');
mustReject(function ($router) { $router->getRoutes()->getByName('dining.settle')->where('id', '.*'); }, 'CRM_ROUTE_PARAMETER', 'settlement ID constraint must remain numeric');
mustReject(function ($router) { $router->getRoutes()->getByName('customer-crm.app-consent')->withoutMiddleware('auth:api'); }, 'CRM_ROUTE_MIDDLEWARE_EXCLUDED', 'application consent cannot lose API authentication');
mustReject(function ($router) { $router->aliasMiddleware('app.scope', 'UnprotectedScopeMiddleware'); }, 'CRM_ROUTE_EFFECTIVE_MIDDLEWARE', 'application consent cannot bypass app scope');

// Put an earlier wildcard ahead of the feature routes while keeping all original metadata.
$base=fixtureRouter(false); $shadowContainer=Container::getInstance();
$shadow=new Router(new Dispatcher($shadowContainer), $shadowContainer);
$shadow->middlewareGroup('web', ['Illuminate\\Session\\Middleware\\StartSession','App\\Http\\Middleware\\VerifyCsrfToken']);
$shadow->aliasMiddleware('IsAdmin','App\\Http\\Middleware\\IsAdmin'); $shadow->aliasMiddleware('lang','App\\Http\\Middleware\\lang');
$shadow->middlewareGroup('api', []);foreach (['auth'=>'Authenticate','CheckLang'=>'CheckLang','app.scope'=>'AppScopeGuard','custom.jwt'=>'CustomJwtAuth'] as $alias=>$class) $shadow->aliasMiddleware($alias, 'App\\Http\\Middleware\\'.$class);
$shadow->get('admin/{everything}', 'MissingShadowController@neverRun')->where('everything','.*')->name('unrelated.shadow');
foreach ($base->getRoutes()->getRoutes() as $route) $shadow->getRoutes()->add($route);
$shadow->getRoutes()->refreshNameLookups();
try { CustomerCrmRouteCheck::verify($shadow); throw new RuntimeException('Expected shadow rejection'); }
catch (RuntimeException $error) { routeCheck(str_starts_with(CustomerCrmRouteCheck::failure($error)['reason'], 'CRM_ROUTE_SHADOWED'), 'earlier wildcard shadow is rejected without invoking it'); }
routeCheck($shadow->getRoutes()->getByName('unrelated.shadow')->controller === null, 'shadow route controller was never resolved');
$failure=CustomerCrmRouteCheck::failure(new RuntimeException('private token should never be printed'));
routeCheck($failure['reason']==='CRM_ROUTE_VERIFICATION_FAILED'&&!str_contains(json_encode($failure),'private token'), 'unexpected errors do not disclose private exception text');
$router=fixtureRouter(); $container=Container::getInstance(); $kernelConstructions=0;
$container->bind(\Illuminate\Contracts\Http\Kernel::class, function () use (&$kernelConstructions) { $kernelConstructions++; return new stdClass; });
$command=new \App\Console\Commands\CheckCustomerCrm; $command->setLaravel($container);
$output=new \Symfony\Component\Console\Output\BufferedOutput;
$exit=$command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
$json=json_decode(trim($output->fetch()), true);
routeCheck($exit===0&&$kernelConstructions===1&&($json['verified_routes']??0)===14, 'crm:check command constructs kernel metadata and exits zero on valid scoped routes');
$router->getRoutes()->getByName('customers.consent')->withoutMiddleware('IsAdmin');
$output=new \Symfony\Component\Console\Output\BufferedOutput;
$exit=$command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
$json=json_decode(trim($output->fetch()), true);
routeCheck($exit===1&&($json['reason']??'')==='CRM_ROUTE_MIDDLEWARE_EXCLUDED:customers.consent', 'crm:check exits nonzero with a safe contract reason on broken authentication');

// Optional publication cutover uses real SQLite; ordinary health checks remain read-only.
$router=fixtureRouter(); $container=Container::getInstance();
$container->bind(\Illuminate\Contracts\Http\Kernel::class, fn()=>new stdClass);
$capsule=new \Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','foreign_key_constraints'=>true]); $capsule->setAsGlobal();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn()=>\Illuminate\Support\Facades\DB::connection()->getSchemaBuilder());
Facade::clearResolvedInstances();
function runCutoverHealth(bool $activate): array
{
    $command=new \App\Console\Commands\CheckCustomerCrm; $command->setLaravel(Container::getInstance());
    $output=new \Symfony\Component\Console\Output\BufferedOutput;
    $input=new \Symfony\Component\Console\Input\ArrayInput($activate ? ['--activate-phone-cutover'=>true] : []);
    $exit=$command->run($input, $output);
    return [$exit, json_decode(trim($output->fetch()), true)];
}
[$exit,$json]=runCutoverHealth(true);
routeCheck($exit===1&&($json['reason']??'')==='CRM_ROUTE_PHONE_CUTOVER_SCHEMA', 'cutover activation fails closed when the migration table is missing');
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:10:00Z'));
require dirname(__DIR__).'/database/migrations/2026_10_17_000003_create_crm_customer_sync_states.php';
(new CreateCrmCustomerSyncStates)->up();
\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->delete();
[$exit,$json]=runCutoverHealth(true);
routeCheck($exit===1&&($json['reason']??'')==='CRM_ROUTE_PHONE_CUTOVER_MISSING'
    && \Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->count()===0, 'missing cutover row is not silently created during activation');
\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->insert(['source'=>'phone_requirement_cutover',
    'activated_at'=>'2026-10-17 12:00:00','created_at'=>'2026-10-17 12:00:00','updated_at'=>'2026-10-17 12:00:00']);
$cutoverBefore=\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->first();
[$exit,$json]=runCutoverHealth(false);
routeCheck($exit===0&&!isset($json['phone_requirement_cutover'])
    && (array)\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->first()===(array)$cutoverBefore, 'plain crm:check never activates or modifies the phone cutover');
$consentRoute=$router->getRoutes()->getByName('customers.consent'); $validAction=$consentRoute->getAction();
$consentRoute->withoutMiddleware('IsAdmin');
[$exit,$json]=runCutoverHealth(true);
routeCheck($exit===1&&($json['reason']??'')==='CRM_ROUTE_MIDDLEWARE_EXCLUDED:customers.consent'
    && (array)\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->first()===(array)$cutoverBefore, 'failed route verification cannot finalize the cutover');
$consentRoute->setAction($validAction);
[$exit,$json]=runCutoverHealth(true);
$activated=\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->first();
routeCheck($exit===0&&($json['phone_requirement_cutover']['activated']??false)
    && $activated->activated_at==='2026-10-17 12:10:00'&&$activated->bootstrapped_at==='2026-10-17 12:10:00'
    && $activated->updated_at==='2026-10-17 12:10:00', 'first successful publication sets the final UTC cutover after all route checks');
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:30:00Z'));
[$exit,$json]=runCutoverHealth(true);
routeCheck($exit===0&&($json['phone_requirement_cutover']['activated']??true)===false
    && (array)\Illuminate\Support\Facades\DB::table('crm_customer_sync_states')->first()===(array)$activated, 'later installer retries preserve the finalized phone cutover exactly');
echo $checks.' CRM route contract checks passed'.PHP_EOL;
