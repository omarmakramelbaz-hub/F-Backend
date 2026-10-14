<?php
/** Actor-scoped alert keys on actual Laravel 8 query builders and in-memory SQLite.
 * ORDER_BOARD_TEST_VENDOR=/path/to/vendor php tests/order_alert_keys_runtime.php
 * No server access, production boot, customer hydration, or persisted fixture files.
 */
require rtrim(getenv('ORDER_BOARD_TEST_VENDOR') ?: '', '/').'/autoload.php';
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'App\\')) {
        $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) require $file;
    }
});

use App\Services\Dashboard\OrderBoardService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

function abort_unless($value, $status, $message = '') {
    if (!$value) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message);
}
class AlertActor {
    public function __construct(public int $id, public string $account_type, public ?int $owner_resturant_id = null,
        public bool $permission = false, public string $app_scope = 'fasakhansta', public string $status = 'accepted') {}
    public function can($ability) { return $ability === 'order-list' && $this->permission; }
}

$app = new Container(); Container::setInstance($app);
$database = new Manager($app);
$database->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
$database->setAsGlobal(); $database->bootEloquent();
$app->instance('db', $database->getDatabaseManager());
$app->bind('db.schema', fn () => $database->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($app);
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('mobile'); });
Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->integer('parent_id')->nullable(); });
Schema::create('orders', function (Blueprint $t) {
    $t->id(); $t->integer('resturant_id'); $t->integer('user_id')->default(900);
    $t->string('type'); $t->string('status')->nullable(); $t->string('accepted_notify')->nullable();
    $t->string('order_no')->nullable(); $t->timestamp('created_at');
});
Schema::create('go_stores', function (Blueprint $t) { $t->integer('user_id')->primary(); });
Schema::create('go_store_orders', function (Blueprint $t) {
    $t->id(); $t->integer('store_id'); $t->integer('customer_id')->default(900);
    $t->string('status'); $t->text('snapshot'); $t->timestamp('created_at');
});
foreach (['go_service_jobs', 'partner_service_requests'] as $table) Schema::create($table, function (Blueprint $t) {
    $t->id(); $t->string('status'); $t->integer('user_id')->default(900); $t->integer('customer_id')->default(900);
    $t->text('private_customer_data')->default(''); $t->timestamp('created_at');
});
DB::table('users')->insert(['id'=>900, 'name'=>'PRIVATE CUSTOMER NAME', 'mobile'=>'PRIVATE PHONE']);
DB::table('resturants')->insert([
    ['id'=>100,'user_id'=>10,'parent_id'=>null], ['id'=>101,'user_id'=>11,'parent_id'=>null],
    ['id'=>102,'user_id'=>12,'parent_id'=>100],
]);
$date = '2026-10-14 12:00:00';
$legacy = [];
for ($id = 1; $id <= 60; $id++) $legacy[] = ['id'=>$id,'resturant_id'=>100,'type'=>'current','status'=>'pending','accepted_notify'=>'no','created_at'=>$date];
foreach ([
    [61,100,'current','another_delegate','no'], [62,100,'current','new_order',null],
    [63,101,'current','pending','no'], [64,102,'current','pending','no'],
    [65,100,'schedule','pending','no'], [66,100,'shipping','pending','no'],
    [67,100,'other','pending','no'], [68,100,'current','pending','yes'],
    [69,100,'current','another_delegate','yes'], [70,100,'current','new_order','yes'],
    [71,100,'current','accepted','no'], [72,100,'current','completed','no'],
    [73,100,'current','cancelled','no'], [74,100,'current','declined','no'],
    [75,100,'current','shipped','no'], [76,100,'current',null,'no'],
] as [$id,$restaurant,$type,$status,$ack]) $legacy[] = ['id'=>$id,'resturant_id'=>$restaurant,'type'=>$type,'status'=>$status,'accepted_notify'=>$ack,'created_at'=>$date];
DB::table('orders')->insert($legacy);
DB::table('go_stores')->insert([['user_id'=>50],['user_id'=>52]]);
foreach ([
    [501,50,'pending'], [502,50,'awaiting_payment'], [503,50,'preparing'], [504,50,'completed'],
    [505,50,'cancelled'], [506,50,'rejected'], [507,51,'pending'], [508,51,'awaiting_payment'], [509,10,'pending'],
] as [$id,$store,$status]) DB::table('go_store_orders')->insert(['id'=>$id,'store_id'=>$store,'status'=>$status,
    'snapshot'=>'{"customer_name":"PRIVATE CUSTOMER NAME","phone":"PRIVATE PHONE"}', 'created_at'=>$date]);
foreach ([701=>'searching',702=>'booked',703=>'completed',704=>'cancelled',705=>'expired'] as $id=>$status)
    DB::table('go_service_jobs')->insert(['id'=>$id,'status'=>$status,'private_customer_data'=>'PRIVATE ADDRESS','created_at'=>$date]);
foreach ([801=>'pending',802=>'accepted',803=>'completed',804=>'cancelled',805=>'declined'] as $id=>$status)
    DB::table('partner_service_requests')->insert(['id'=>$id,'status'=>$status,'private_customer_data'=>'PRIVATE ADDRESS','created_at'=>$date]);

$checks = 0;
function same($expected, $actual, $label) {
    global $checks; $checks++;
    if ($expected !== $actual) throw new RuntimeException($label.': '.var_export($actual,true));
}
function denied($actor, $label) {
    try { (new OrderBoardService())->pendingAlertKeys($actor); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { same(403,$error->getStatusCode(),$label); return; }
    throw new RuntimeException($label.' was allowed');
}
function keys($actor) { return (new OrderBoardService())->pendingAlertKeys($actor); }
$expectedBranch = array_map(fn ($id) => 'legacy:'.$id, range(1,62));
$expectedGlobal = array_merge($expectedBranch,['legacy:63','legacy:64','legacy:65','legacy:66',
    'store:501','store:502','store:507','store:508','store:509','service:701','partner_service:801']);
$vendor = new AlertActor(10,'vendor');
$admin = new AlertActor(40,'admin',null,true);
$connection = $database->getConnection();
$beforeRows = [];
foreach (['users','resturants','orders','go_stores','go_store_orders','go_service_jobs','partner_service_requests'] as $table)
    $beforeRows[$table] = DB::table($table)->orderBy($table === 'go_stores' ? 'user_id' : 'id')->get()->toJson();
$connection->enableQueryLog(); $connection->flushQueryLog();

same(array_merge($expectedBranch,['store:509']),keys($vendor),'restaurant vendor sees its branch and own store only');
same(['legacy:63'],keys(new AlertActor(11,'vendor')),'other vendor sees only its own restaurant');
same(array_merge($expectedBranch,['legacy:64']),keys(new AlertActor(20,'resturant_owner',100)),
    'restaurant owner scope includes parent restaurant and child branches');
same($expectedBranch,keys(new AlertActor(30,'admin',100)),
    'restaurant-bound admin sees exactly the bound branch, excluding children, stores and services');
same($expectedGlobal,keys($admin),'authorized unbound admin sees all new orders across every source');
same($expectedGlobal,keys(new AlertActor(1,'admin')),'owner id 1 can view all new orders');
same(['store:501','store:502'],keys(new AlertActor(50,'user',null,false,'go_partner')),
    'accepted registered Go store partner sees its own pending and awaiting-payment orders');
same(['store:507','store:508'],keys(new AlertActor(51,'vendor',null,false,'go_partner')),
    'store vendor scope excludes other stores and every service source');
same(62,count(array_filter(keys($vendor),fn ($key) => str_starts_with($key,'legacy:'))),
    'all new keys are returned beyond the board default 50-row page');
same(true,in_array('legacy:62',keys($vendor),true),'newest key beyond default pagination is present');

$app->instance('request',Request::create('/orders/applies','GET',[
    'branch'=>'f:101','app'=>'go','search'=>'NO MATCH','date'=>'1999-01-01','q'=>'completed',
    'limit'=>10,'page_new'=>999,
]));
same($expectedGlobal,keys($admin),'branch, app, search, date, stage and pagination filters cannot silence alert keys');
denied(null,'missing actor rejected');
denied(new AlertActor(41,'admin'), 'unbound admin without order-list rejected');
denied(new AlertActor(31,'admin',999), 'bound admin with missing restaurant rejected');
denied(new AlertActor(900,'user'), 'customer role rejected');
denied(new AlertActor(51,'user',null,false,'go_partner'), 'unregistered Go partner rejected');
denied(new AlertActor(52,'user',null,false,'go_partner','pending'), 'unaccepted Go partner rejected');

$allKeys = keys($admin);
foreach ([67,68,69,70,71,72,73,74,75,76] as $id) same(false,in_array('legacy:'.$id,$allKeys,true),
    'invalid type, acknowledged or non-new legacy order '.$id.' excluded');
foreach (['store:503','store:504','store:505','store:506','service:702','service:703','service:704','service:705',
    'partner_service:802','partner_service:803','partner_service:804','partner_service:805'] as $key)
    same(false,in_array($key,$allKeys,true),'non-new order '.$key.' excluded');
same(true,array_reduce($allKeys,fn ($ok,$key) => $ok && is_string($key)
    && preg_match('/^(legacy|store|service|partner_service):[0-9]+$/',$key) === 1,true),
    'result exposes source-qualified integer keys only');
same(false,str_contains(json_encode($allKeys),'PRIVATE'),'customer data never appears in returned keys');
$queries = $connection->getQueryLog();
foreach ($queries as $query) {
    same(false,preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i',$query['query']) === 1,
        'alert method never mutates the database');
    same(false,preg_match('/select\s+\*\s+from\s+"?(users|orders|go_store_orders|go_service_jobs|partner_service_requests)"?\b/i',$query['query']) === 1,
        'alert method never hydrates order or customer rows');
}
$connection->disableQueryLog();
foreach ($beforeRows as $table=>$rows) same($rows,DB::table($table)->orderBy($table === 'go_stores' ? 'user_id' : 'id')->get()->toJson(),
    'alert reads preserve '.$table.' rows exactly');
echo 'Order alert keys runtime: '.$checks." assertions passed; actor scopes, all pages, filters, states, privacy and read-only queries verified.\n";
