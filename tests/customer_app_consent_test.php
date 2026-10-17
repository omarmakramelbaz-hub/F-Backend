<?php
// Actual CRM SQLite migration, self-service write service and controller; no .env or network.
require getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require_once $file;
});

use App\Http\Controllers\Api\V1\User\CustomerConsentController;
use App\Services\Dashboard\{AppCustomerConsent, CustomerDirectory};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Illuminate\Validation\ValidationException;

function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($condition, $status, $message = '') { if (!$condition) throw new RuntimeException($message, $status); }
function app($abstract = null) { $c = Container::getInstance(); return $abstract === null ? $c : $c->make($abstract); }
function response() { return new class { public function json($value, $status = 200) { return new JsonResponse($value, $status); } }; }
$c = new Container; Container::setInstance($c);
$db = new Capsule($c); $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'foreign_key_constraints'=>true]);
$db->setAsGlobal(); $db->bootEloquent();
$c->instance('db', $db->getDatabaseManager()); $c->bind('db.schema', fn()=>DB::connection()->getSchemaBuilder());
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
$c->instance('validator', new \Illuminate\Validation\Factory($translator, $c));
Facade::setFacadeApplication($c);
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('mobile'); $t->string('name'); $t->string('account_type'); $t->string('app_scope')->nullable(); $t->string('status')->default('accepted'); });
require dirname(__DIR__).'/database/migrations/2026_10_17_000001_create_crm_customer_directory.php';
(new CreateCrmCustomerDirectory)->up();
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T15:00:00Z'));
$directory = new CustomerDirectory; $service = new AppCustomerConsent($directory);
$version = ['whatsapp_consent_version'=>AppCustomerConsent::VERSION];
$checks = 0;
function appConsentCheck($condition, string $label): void { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException('APP_CONSENT_FAILED:'.$label); }
function appConsentInvalid(callable $work, string $field): void { try { $work(); throw new RuntimeException('Expected validation failure'); } catch (ValidationException $error) { appConsentCheck(isset($error->errors()[$field]), 'invalid field '.$field); } }
function appConsentDenied(callable $work, int $status): void { try { $work(); throw new RuntimeException('Expected denial'); } catch (RuntimeException $error) { appConsentCheck($error->getCode() === $status, 'denied with '.$status); } }
function appConsentUser(int $id, string $mobile, string $type = 'user', ?string $scope = 'fasakhansta', string $status = 'accepted'): object {
    DB::table('users')->insert(['id'=>$id, 'mobile'=>$mobile, 'name'=>'عميل '.$id, 'account_type'=>$type, 'app_scope'=>$scope, 'status'=>$status]);
    return (object)['id'=>$id, 'mobile'=>'must never trust this stale value'];
}
$actor = appConsentUser(1, '01012345678');
appConsentDenied(fn()=>$service->update($version, null), 401);
appConsentDenied(fn()=>$service->update($version, (object)['id'=>999]), 401);
appConsentInvalid(fn()=>$service->update([], $actor), 'whatsapp_consent_version');
appConsentInvalid(fn()=>$service->update(['whatsapp_consent_version'=>'unreviewed.v2', 'service_consent'=>true], $actor), 'whatsapp_consent_version');
foreach (['phone', 'mobile', 'customer_phone', 'directory_id', 'customer_id', 'user_id', 'app_scope', 'recorded_by'] as $field) appConsentInvalid(fn()=>$service->update($version + [$field=>'2', 'service_consent'=>true], $actor), $field);
foreach ([null, '', 'true', 'yes', [], 2] as $bad) appConsentInvalid(fn()=>$service->update($version + ['service_consent'=>$bad], $actor), 'service_consent');
appConsentCheck(DB::table('crm_customers')->count() === 0, 'invalid calls create no CRM rows');
$empty = $service->update($version, $actor);
appConsentCheck($empty['changed'] === false && $empty['service_consent'] === false && DB::table('crm_customers')->count() === 0, 'version-only call is a read with no customer creation');
$vendor = appConsentUser(2, '01012345679', 'vendor');
$admin = appConsentUser(3, '01012345670', 'admin');
$go = appConsentUser(4, '01012345671', 'user', 'go');
$partner = appConsentUser(5, '01012345672', 'user', 'go_partner');
$declined = appConsentUser(6, '01012345673', 'user', 'fasakhansta', 'declined');
foreach ([$vendor, $admin, $go, $partner, $declined] as $wrong) appConsentDenied(fn()=>$service->update($version + ['service_consent'=>true], $wrong), 403);
appConsentDenied(fn()=>$service->update($version + ['service_consent'=>true], $actor, 'go'), 403);
appConsentDenied(fn()=>$service->update($version + ['service_consent'=>true], $actor, 'go_partner'), 403);
$invalid = appConsentUser(7, 'not-a-number');
appConsentInvalid(fn()=>$service->update($version + ['service_consent'=>true], $invalid), 'mobile');
appConsentCheck(DB::table('crm_customers')->count() === 0, 'unauthorized identities and invalid stored phones leave the directory empty');
$first = $service->update($version + ['service_consent'=>'1'], $actor);
$customer = DB::table('crm_customers')->where('phone', '+201012345678')->first();
appConsentCheck($first['changed'] && $first['service_consent'] && !$first['marketing_consent'], 'first own feedback opt-in never enables promotions');
appConsentCheck($customer && $customer->name === 'عميل 1' && (int)$customer->service_consented_by === 1, 'phone/name and actor derive from the current account');
appConsentCheck($customer->service_consent_evidence === 'customer-self-choice:authenticated-app-form:'.AppCustomerConsent::VERSION.':fasakhansta:user:1', 'audit distinguishes the customer self-choice and exact reviewed form version');
appConsentCheck(DB::table('crm_customer_sources')->where('customer_id', $customer->id)->where('channel', 'app')->where('branch', '')->exists(), 'own app profile stays unassigned to any branch');
appConsentCheck(DB::table('crm_purchases')->count() === 0, 'choosing messages never invents a purchase or starts feedback');
$serviceStamp = $customer->service_consented_at;
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T15:05:00Z'));
$same = $service->update($version + ['service_consent'=>true], $actor);
appConsentCheck(!$same['changed'] && DB::table('crm_customers')->where('id', $customer->id)->value('service_consented_at') === $serviceStamp, 'repeated unchanged positive choice preserves its original audit time');
DB::table('crm_customers')->where('id', $customer->id)->update(['service_consented_at'=>null]);
$repaired = $service->update($version + ['service_consent'=>true], $actor);
$serviceStamp = DB::table('crm_customers')->where('id', $customer->id)->value('service_consented_at');
appConsentCheck($repaired['changed'] && $serviceStamp !== null, 'an explicit new selection records missing opt-in audit time');
$marketing = $service->update($version + ['marketing_consent'=>true], $actor);
$customer = DB::table('crm_customers')->find($customer->id);
appConsentCheck($marketing['service_consent'] && $marketing['marketing_consent'] && $customer->service_consented_at === $serviceStamp, 'a promotion choice preserves the untouched feedback purpose');
$marketingStamp = $customer->marketing_consented_at;
$revoked = $service->update($version + ['service_consent'=>false], $actor);
appConsentCheck(!$revoked['service_consent'] && $revoked['marketing_consent'] && $revoked['opted_out_at'] === null, 'per-purpose revocation leaves the other purpose working');
appConsentCheck(DB::table('crm_customers')->find($customer->id)->marketing_consented_at === $marketingStamp, 'untouched promotional audit stays unchanged');
$stopped = $service->update($version + ['marketing_consent'=>'0'], $actor);
appConsentCheck(!$stopped['service_consent'] && !$stopped['marketing_consent'] && $stopped['opted_out_at'] !== null, 'revoking the final purpose stops all CRM messages');
$beforeRead = (array)DB::table('crm_customers')->find($customer->id);
$service->update($version, $actor);
$service->update($version + ['service_consent'=>false], $actor);
appConsentCheck((array)DB::table('crm_customers')->find($customer->id) === $beforeRead, 'absent or already disabled choices cannot clear STOP or rewrite its audit');
$again = $service->update($version + ['service_consent'=>true], $actor);
appConsentCheck($again['service_consent'] && !$again['marketing_consent'] && $again['opted_out_at'] === null, 'explicit feedback re-opt-in clears STOP without restoring promotions');
$directory->consent((int)$customer->id, false, false, 'whatsapp-inbound:opt-out');
$beforeStop = (array)DB::table('crm_customers')->find($customer->id);
$service->update($version, $actor);
appConsentCheck((array)DB::table('crm_customers')->find($customer->id) === $beforeStop, 'incoming STOP survives later app calls with no selection');
$legacy = appConsentUser(8, '01112345678', 'user', null);
$legacyChoice = $service->update($version + ['marketing_consent'=>true], $legacy, 'fasakhansta');
appConsentCheck($legacyChoice['marketing_consent'], 'legacy null scope remains the existing Fasakhansta default');
$oldId = $customer->id;
DB::table('users')->where('id', 1)->update(['mobile'=>'01212345678']);
$newNumberChoice = $service->update($version + ['marketing_consent'=>true], $actor);
$newCustomer = DB::table('crm_customers')->where('phone', '+201212345678')->first();
appConsentCheck($newNumberChoice['marketing_consent'] && $newCustomer && $newCustomer->id !== $oldId, 'stale auth object is refreshed and only the currently stored phone receives the new explicit choice');
appConsentCheck((array)DB::table('crm_customers')->find($oldId) === $beforeStop, 'changing account phone does not transfer or mutate the old phone consent');
$rollbackActor = appConsentUser(9, '01512345678');
$failingDirectory = new class extends CustomerDirectory {
    public function consent(int $customerId, ?bool $service, ?bool $marketing, string $evidence, ?int $actorId = null): ?object { throw new RuntimeException('isolated write failure'); }
};
try { (new AppCustomerConsent($failingDirectory))->update($version + ['service_consent'=>true], $rollbackActor); throw new RuntimeException('expected transaction failure'); }
catch (RuntimeException $error) { appConsentCheck($error->getMessage() === 'isolated write failure', 'fixture failed inside the real consent transaction'); }
appConsentCheck(!DB::table('crm_customers')->where('phone', '+201512345678')->exists(), 'failed consent transaction also rolls back the new profile');

$controller = new CustomerConsentController;
$request = Request::create('/api/user/whatsapp-consent', 'POST', $version + ['service_consent'=>true]);
$resolvedGuard = null;
$request->setUserResolver(function ($guard = null) use ($legacy, &$resolvedGuard) { $resolvedGuard = $guard; return $legacy; });
$json = $controller->update($request, $service);
appConsentCheck($json->getStatusCode() === 200 && $json->getData(true)['status'] === 'Success' && $json->getData(true)['data']['service_consent'], 'real controller returns the standard success envelope');
appConsentCheck($resolvedGuard === 'api', 'controller explicitly resolves the API identity instead of the dashboard session');
$badRequest = Request::create('/api/user/whatsapp-consent', 'POST', $version + ['user_id'=>1]);
$badRequest->setUserResolver(fn()=>$legacy);
$badJson = $controller->update($badRequest, $service);
appConsentCheck($badJson->getStatusCode() === 422 && $badJson->getData(true)['status'] === 'Error', 'validation rejection returns the standard API error envelope');

$router = new Router(new Dispatcher($c), $c); $c->instance('router', $router); Facade::clearResolvedInstance('router');
$router->middleware('api')->prefix('api')->group(dirname(__DIR__).'/routes/api.php');
$router->getRoutes()->refreshNameLookups();
$route = $router->getRoutes()->getByName('customer-crm.app-consent');
appConsentCheck($route && $route->uri() === 'api/user/whatsapp-consent' && $route->methods() === ['POST'], 'dedicated self-service route is POST-only at the agreed URI');
foreach (['api', 'CheckLang', 'auth:api', 'app.scope', 'custom.jwt'] as $middleware) appConsentCheck(in_array($middleware, $route->gatherMiddleware(), true), 'route retains '.$middleware);
appConsentCheck($route->getActionName() === CustomerConsentController::class.'@update' && is_callable([$controller, 'update']), 'protected route resolves the intended real controller action');
echo $checks.' app customer consent checks passed'.PHP_EOL;
