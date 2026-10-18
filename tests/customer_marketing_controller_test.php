<?php
// Real controller, validation and persisted authorization; isolated SQLite and no provider calls.
require getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require_once $file;
});

use App\Http\Controllers\Dashboard\BranchOperationsController;
use App\Services\Dashboard\{BranchOperations,CustomerMessagingService};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Facade,Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

class MarketingControllerApplication extends Container
{
    public function abort($code, $message = '', array $headers = []) { throw new HttpException($code, $message, null, $headers); }
}
// The production model's third-party traits are unrelated to this authorization boundary.
class MarketingControllerUser extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';
    public function can($permission) { return false; }
}
class_alias(MarketingControllerUser::class, 'App\\Models\\User');

$container = new MarketingControllerApplication; Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','foreign_key_constraints'=>true]);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn()=>DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
$container->alias('validator', \Illuminate\Contracts\Validation\Factory::class);
(new \Illuminate\Foundation\Providers\FoundationServiceProvider($container))->registerRequestValidation();
$auth = new class {
    public $actor;
    public function guard($name) { if ($name !== 'admin') throw new RuntimeException('Unexpected auth guard'); return $this; }
    public function user() { return $this->actor; }
};
$container->instance(\Illuminate\Contracts\Auth\Factory::class, $auth);
$container->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
    public function json($data) { return new \Illuminate\Http\JsonResponse($data); }
});
Schema::create('users', function (Blueprint $table) {
    $table->id(); $table->string('account_type'); $table->unsignedBigInteger('owner_resturant_id')->nullable();
});
Schema::create('resturants', function (Blueprint $table) {
    $table->id(); $table->unsignedBigInteger('user_id'); $table->string('name');
});
DB::table('users')->insert([
    ['id'=>1,'account_type'=>'admin','owner_resturant_id'=>null],
    ['id'=>2,'account_type'=>'admin','owner_resturant_id'=>100],
    ['id'=>3,'account_type'=>'vendor','owner_resturant_id'=>null],
]);
DB::table('resturants')->insert(['id'=>100,'user_id'=>3,'name'=>'شبرا الخيمة']);
$service = new class extends CustomerMessagingService {
    public array $calls = [];
    public function prepareMarketingTemplate($actor, array $input): array
    {
        $this->calls[] = ['actor'=>$actor, 'input'=>$input];
        return ['success'=>true,'created'=>true,'marketing_setup'=>['state'=>'pending']];
    }
};
$controller = new BranchOperationsController;
$ops = $container->make(BranchOperations::class);
$checks = 0;
function controllerCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++; echo 'PASS '.$label.PHP_EOL;
}
function prepareRequest(array $changes = []): Request
{
    $request = Request::create('/admin/customers/marketing-template-prepare', 'POST', array_replace([
        'branch'=>'f:100', 'body'=>'عروض فسخانستا اليوم. لإيقاف الرسائل، أرسل إيقاف.',
        'idempotency_key'=>'10000000-1000-4000-8000-100000000001',
    ], $changes));
    $route = (new \Illuminate\Routing\Route(['POST'], 'admin/customers/marketing-template-prepare', fn()=>null))
        ->name('customers.marketing-template-prepare');
    $request->setRouteResolver(fn()=>$route);
    return $request;
}
function controllerRejected(Request $request, int $status, string $label): void
{
    global $controller,$service,$ops;
    $before = count($service->calls);
    try { $controller->prepareMarketingTemplate($request, $service, $ops); }
    catch (\Illuminate\Validation\ValidationException $error) {
        controllerCheck($status === 422 && count($service->calls) === $before, $label); return;
    } catch (HttpException $error) {
        controllerCheck($error->getStatusCode() === $status && count($service->calls) === $before, $label); return;
    }
    throw new RuntimeException('Expected controller rejection: '.$label);
}

$auth->actor = MarketingControllerUser::find(1);
$response = $controller->prepareMarketingTemplate(prepareRequest([
    'category'=>'UTILITY','template_name'=>'untrusted','language'=>'en_US','to'=>'201000000000',
    'enabled'=>true,'feedback_enabled'=>true,
]), $service, $ops);
$call = $service->calls[0];
controllerCheck($response->getStatusCode() === 200 && $response->getData(true)['marketing_setup']['state'] === 'pending', 'central admin receives the service preparation receipt');
controllerCheck((int)$call['actor']->id === 1 && $call['actor'] !== $auth->actor, 'controller passes the freshly persisted central admin identity');
controllerCheck(array_keys($call['input']) === ['branch','idempotency_key','body'], 'only validated branch, request key and body reach the service');
controllerCheck($call['input']['body'] === prepareRequest()->input('body'), 'controller preserves the entered body for service normalization');
controllerRejected(prepareRequest(['branch'=>'f:999']), 404, 'unknown branch fails before template preparation');
controllerRejected(prepareRequest(['branch'=>'all']), 422, 'branch must be a concrete authorized branch');
controllerRejected(prepareRequest(['body'=>null]), 422, 'missing body fails before template preparation');
controllerRejected(prepareRequest(['body'=>['untrusted']]), 422, 'non-string body fails before template preparation');
controllerRejected(prepareRequest(['body'=>str_repeat('ع',1025)]), 422, 'body exceeds the 1024-character template limit');
controllerRejected(prepareRequest(['idempotency_key'=>'invalid']), 422, 'invalid operation key fails before template preparation');
foreach ([2,3] as $id) {
    $auth->actor = MarketingControllerUser::find($id);
    controllerRejected(prepareRequest(), 403, 'persisted noncentral account '.$id.' cannot prepare shared templates');
}
$auth->actor = (object)['id'=>3,'account_type'=>'admin','owner_resturant_id'=>null];
controllerRejected(prepareRequest(), 403, 'a forged central session role cannot replace the persisted account role');
$auth->actor = null;
controllerRejected(prepareRequest(), 403, 'missing authenticated actor cannot prepare shared templates');
echo $checks.' marketing template controller checks passed'.PHP_EOL;
