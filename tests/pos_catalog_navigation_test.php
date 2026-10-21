<?php
/** Real service/controller methods with private SQLite and Illuminate validation; no app bootstrap. */
require getenv('POS_CATALOG_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\Services\\') !== 0 && strpos($class, 'App\\Http\\Controllers\\Dashboard\\') !== 0) return;
    $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require_once $file;
}, true, true);

use App\Services\Dashboard\{BranchShiftClosing, PosServiceTable, PosServiceTicket, TakeawayAccess, TakeawayCatalog, TakeawayService};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract = null) { return $abstract ? Container::getInstance()->make($abstract) : Container::getInstance(); }
function config($key, $default = null) { return app('config')->get($key, $default); }
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($value, $code, $message = '') { if (!$value) throw new HttpException($code, $message); }
function __($key) { return $key; }
function auth($guard = null) { return new class { public function user() { return $GLOBALS['navigation_actor']; } }; }
function response() { return new class { public function json($value) { return $value; } }; }
class NavigationUser extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';
    public function can($permission)
    {
        return $this->account_type === 'admin' && ($permission === 'order-list' || (bool)$this->can_write);
    }
}
class_alias(NavigationUser::class, 'App\\Models\\User');
// The real dashboard parent adds framework middleware only. These fixtures call the action directly.
class NavigationControllerBase {}
class_alias(NavigationControllerBase::class, 'App\\Http\\Controllers\\Controller');
class NavigationRequest
{
    private array $values;
    public function __construct(array $values) { $this->values = $values; }
    public function validate(array $rules) { return app('validator')->make($this->values, $rules)->validate(); }
}
class_alias(NavigationRequest::class, 'Illuminate\\Http\\Request');
class NavigationClosing extends BranchShiftClosing
{
    public int $calls = 0;
    public function __construct() {}
    public function ownerBalances(array $branches, $actor): array
    {
        $this->calls++;
        return array_fill_keys($branches, ['expected_cents'=>99900]);
    }
}
class NavigationCatalog extends TakeawayCatalog
{
    public function listing(array $values, $actor): array
    {
        $branch = app(TakeawayAccess::class)->branch($values['branch'], $actor);
        return ['success'=>true, 'branch'=>$branch, 'items'=>[], 'categories'=>[], 'pagination'=>['page'=>1]];
    }
}

$container = new Container; Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo']]));
$capsule = new Capsule($container); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn()=>DB::connection()->getSchemaBuilder()); Facade::setFacadeApplication($container);
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-21T12:00:00Z'));
Schema::create('users', function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->integer('owner_resturant_id')->nullable();$t->boolean('can_write')->default(false);});
Schema::create('permissions', function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
foreach (['order-list','order-edit','order-create'] as $name) DB::table('permissions')->insert(['name'=>$name,'guard_name'=>'admin']);
Schema::create('resturants', function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('parent_id')->nullable();$t->string('name');});
foreach ([[1,'admin',null],[2,'admin',null],[3,'admin',100],[10,'vendor',null],[11,'vendor',null]] as [$id,$type,$parent]) {
    DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
}
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Mahalla'],['id'=>101,'user_id'=>11,'name'=>'Mansoura']]);
require dirname(__DIR__).'/database/migrations/2026_10_03_140000_create_takeaway_pos.php'; (new CreateTakeawayPos)->up();
Schema::table('takeaway_orders', function(Blueprint $t){$t->string('channel')->default('takeaway');$t->text('tenders_snapshot')->nullable();});
Schema::create('branch_shift_sources', fn(Blueprint $t)=>$t->id());
Schema::create('pos_service_tickets', function(Blueprint $t){$t->id();foreach (['bill_issued_at','bill_issued_by','bill_issued_revision'] as $name) $t->string($name)->nullable();});
foreach (['pos_service_commands','pos_service_tables','pos_service_kitchen_tickets'] as $table) Schema::create($table, fn(Blueprint $t)=>$t->id());
Schema::create('pos_service_settings', function(Blueprint $t){$t->id();$t->string('branch');$t->integer('service_bps')->default(0);$t->integer('revision')->default(1);});
DB::table('takeaway_tills')->insert(['branch'=>'f:100','tax_bps'=>1200,'balance_cents'=>12345]);
DB::table('pos_service_settings')->insert(['branch'=>'f:100','service_bps'=>500,'revision'=>3]);
$closing = new NavigationClosing; $container->instance(BranchShiftClosing::class, $closing);
$sales = app(TakeawayService::class); $tickets = app(PosServiceTicket::class);
$owner = NavigationUser::find(1); $admin = NavigationUser::find(2); $vendor = NavigationUser::find(10);
$checks = 0;
function check($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; echo 'PASS '.$message.PHP_EOL; }
function denied($callback, int $status, string $message): void { try {$callback();} catch(HttpException $error) {check($error->getStatusCode()===$status,$message);return;} throw new RuntimeException('Expected denial: '.$message); }
function noFinancialQueries(array $queries, string $message): void
{
    $forbidden = '/(?:from|join)\s+["`]?((?:takeaway_orders|takeaway_till_entries|branch_shift_[a-z_]+|branch_expenses|branch_expense_commands|orders|payments|carts))["`]?\b/i';
    check(!array_filter($queries, fn($query)=>preg_match($forbidden, $query['query'])), $message);
}
DB::enableQueryLog(); DB::flushQueryLog();
$light = $sales->catalogSummary('f:100', $owner);
$dine = $tickets->catalogSummary('dine', 'f:100', $owner);
$phone = $tickets->catalogSummary('phone', 'f:100', $owner);
noFinancialQueries(DB::getQueryLog(), 'all lightweight channels skip orders, ledgers, expenses and shift reads');
check($closing->calls===0, 'owner drawer reconciliation is not invoked during catalog navigation');
check(array_diff(array_keys($light), ['ready','permissions','policy'])===[] && !isset($dine['today']) && !isset($dine['register']), 'lightweight responses omit register and daily totals');
check($light['ready'] && $light['policy']['tax_rate']==='12.00' && $light['permissions']['can_manage'], 'takeaway lightweight policy uses live tax and owner permissions');
check($dine['policy']['service_rate']==='5.00' && $dine['policy']['settings_revision']===3 && $dine['policy']['channel']==='dine', 'dining lightweight policy reads current service charge and revision');
check($phone['policy']['service_rate']==='0.00' && $phone['policy']['channel']==='phone', 'delivery retains its existing zero service-charge rule');
DB::table('takeaway_tills')->where('branch','f:100')->update(['tax_bps'=>1750]);
DB::table('pos_service_settings')->where('branch','f:100')->update(['service_bps'=>750,'revision'=>4]);
check($sales->catalogSummary('f:100',$owner)['policy']['tax_rate']==='17.50' && $tickets->catalogSummary('dine','f:100',$owner)['policy']['service_rate']==='7.50', 'tax and service settings are reread on each lightweight request');
check(!$sales->catalogSummary('f:100',$admin)['policy']['can_discount'], 'read-only central admin cannot discount');
DB::table('users')->where('id',2)->update(['can_write'=>true]);
check($sales->catalogSummary('f:100',$admin)['policy']['can_discount'], 'changed persisted permissions take effect despite a stale caller object');
denied(fn()=>$sales->catalogSummary('f:101',$vendor),404,'lightweight takeaway rejects a different branch');
denied(fn()=>$tickets->catalogSummary('dine','f:101',NavigationUser::find(3)),404,'lightweight dining rejects a branch outside the manager assignment');
denied(fn()=>$tickets->catalogSummary('forged','f:100',$owner),404,'lightweight service summary rejects an unknown channel');

$catalog = app(NavigationCatalog::class);
$takeaway = (new ReflectionClass(\App\Http\Controllers\Dashboard\TakeawayController::class))->newInstanceWithoutConstructor();
$dining = (new ReflectionClass(\App\Http\Controllers\Dashboard\DineInController::class))->newInstanceWithoutConstructor();
$GLOBALS['navigation_actor'] = $owner;
foreach ([[$takeaway,$sales],[$dining,$tickets]] as [$controller,$service]) {
    DB::flushQueryLog(); $beforeCalls=$closing->calls;
    $result=$controller->catalog(new NavigationRequest(['branch'=>'f:100','catalog_only'=>'1']),$catalog,$service);
    check($result['ready'] && !isset($result['today']) && !isset($result['register']), 'validated catalog_only=1 selects the lightweight controller path');
    noFinancialQueries(DB::getQueryLog(), 'lightweight controller route performs no financial reads');
    check($closing->calls===$beforeCalls, 'lightweight controller bypasses owner reconciliation');
    foreach ([[],['catalog_only'=>'0']] as $flag) {
        $full=$controller->catalog(new NavigationRequest(['branch'=>'f:100']+$flag),$catalog,$service);
        check(isset($full['register']['balance']) && isset($full['today']['count']), 'missing or false catalog_only retains the full summary contract');
    }
    try {$controller->catalog(new NavigationRequest(['branch'=>'f:100','catalog_only'=>'not-boolean']),$catalog,$service);throw new RuntimeException('Malformed flag accepted');}
    catch (\Illuminate\Validation\ValidationException $error) {check(isset($error->errors()['catalog_only']), 'malformed catalog_only flag is rejected by validation');}
}
Schema::drop('pos_service_settings');
check(!$tickets->catalogSummary('dine','f:100',$owner)['ready'], 'missing service schema retains not-ready semantics');
Schema::drop('takeaway_order_items');
$unready=$sales->catalogSummary('f:100',$owner);
check(!$unready['ready'] && $unready['policy']===[] && $unready['permissions']===[], 'missing POS schema returns no purchasable lightweight policy');
DB::disableQueryLog();
echo $checks.' POS catalog navigation checks passed'.PHP_EOL;
