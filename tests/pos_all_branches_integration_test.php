<?php
/** POS aggregate reads use disposable SQLite; no application .env or server is loaded. */
$autoload = getenv('POS_BRANCH_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set POS_BRANCH_TEST_AUTOLOAD to an Illuminate 8 compatible Composer autoloader.');
require $autoload;
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\Services\\') !== 0) return;
    $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{PosServiceTable, TakeawayService};

function app($abstract = null) { return $abstract ? Container::getInstance()->make($abstract) : Container::getInstance(); }
function config($key, $default = null) { return app('config')->get($key, $default); }
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($value, $code, $message = '') { if (!$value) throw new HttpException($code, $message); }
function route($name, $args = []) { return 'https://pos-test.invalid/'.$name.'?'.http_build_query($args); }
function __($key) { return $key; }
class PosBranchUser extends \Illuminate\Database\Eloquent\Model { protected $table = 'users'; public function can($permission) { return (int)$this->id === 2 && $permission === 'order-list'; } }
class_alias(PosBranchUser::class, 'App\\Models\\User');
$container = new Container; Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository(['app'=>['timezone'=>'Africa/Cairo']]));
$capsule = new Capsule($container); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']); $capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager()); $container->bind('db.schema', fn()=>DB::connection()->getSchemaBuilder()); Facade::setFacadeApplication($container);
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-18T12:00:00Z'));
Schema::create('users', function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('permissions', function(Blueprint $t){$t->id();$t->string('name');$t->string('guard_name');});
DB::table('permissions')->insert(['name'=>'order-list','guard_name'=>'admin']);
Schema::create('resturants', function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
foreach([[1,'admin',null],[2,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100]] as [$id,$type,$parent]) DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Mahalla'],['id'=>101,'user_id'=>11,'name'=>'Mansoura']]);
require dirname(__DIR__).'/database/migrations/2026_10_03_140000_create_takeaway_pos.php'; (new CreateTakeawayPos)->up();
Schema::table('takeaway_orders',fn(Blueprint $t)=>$t->string('channel')->default('takeaway'));
Schema::table('takeaway_orders',fn(Blueprint $t)=>$t->text('tenders_snapshot')->nullable());
Schema::create('pos_service_tickets',function(Blueprint $t){$t->id();foreach(['bill_issued_at','bill_issued_by','bill_issued_revision'] as $column)$t->string($column)->nullable();});
Schema::create('pos_service_tables',function(Blueprint $t){$t->id();$t->string('branch');$t->string('name');$t->integer('capacity')->default(4);$t->boolean('active')->default(true);$t->integer('revision')->default(1);$t->integer('active_ticket_id')->nullable();});
foreach(['pos_service_commands','pos_service_settings','pos_service_kitchen_tickets'] as $table) Schema::create($table,function(Blueprint $t){$t->id();$t->string('branch')->nullable();});
foreach([['f:100','Table 1'],['f:101','Table 1'],['f:999','Inaccessible']] as [$branch,$name]) DB::table('pos_service_tables')->insert(['branch'=>$branch,'name'=>$name]);
foreach([['f:100',12000,'2026-10-18'],['f:101',23000,'2026-10-18'],['f:100',10000,'2026-10-17'],['f:999',90000,'2026-10-18']] as $index=>[$branch,$amount,$date]){
    $till = DB::table('takeaway_tills')->where('branch',$branch)->value('id');
    if (!$till) $till=DB::table('takeaway_tills')->insertGetId(['branch'=>$branch]);
    DB::table('takeaway_orders')->insert(['till_id'=>$till,'branch'=>$branch,'actor_id'=>1,'request_key'=>sprintf('00000000-0000-4000-8000-%012d',$index+1),
        'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),'business_date'=>$date,'payment_method'=>'cash','subtotal_cents'=>$amount,'discount_cents'=>0,'tax_cents'=>0,'total_cents'=>$amount,'cash_received_cents'=>$amount,'change_cents'=>0,'tax_bps'=>0,
        'branch_snapshot'=>json_encode(['value'=>$branch,'name'=>$branch==='f:100'?'Mahalla':'Mansoura']),'cashier_snapshot'=>'{"id":1,"name":"Owner"}','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
}
$checks=0;
function check($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;}
function denied($callback,$status,$message){try{$callback();}catch(HttpException $error){check($error->getStatusCode()===$status,$message);return;}throw new RuntimeException('Expected denial: '.$message);}
function snapshot(){return json_encode(array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->all(),['takeaway_orders','takeaway_tills','takeaway_till_entries','pos_service_tables','pos_service_commands']));}
$before=snapshot();$sales=app(TakeawayService::class);$floor=app(PosServiceTable::class);$owner=PosBranchUser::find(1);$admin=PosBranchUser::find(2);
foreach([$owner,$admin] as $actor){
    $summary=$sales->summary('', $actor);
    check($summary['today']['count']===2&&$summary['today']['total']==='350.00'&&$summary['register']===null,'central actor '.$actor->id.' summary combines available branches and never exposes a selected till');
    $all=$sales->receipts(['branch'=>'all','per_page'=>1],$actor);
    check($all['pagination']['total']===2&&$all['pagination']['last_page']===2&&count($all['items'])===1&&$all['items'][0]['branch']['value']==='f:101','central actor '.$actor->id.' aggregate receipts paginate across allowed branches only');
    check($sales->receipts(['date'=>'2026-10-17'],$actor)['today']['total']==='100.00','aggregate receipts preserve business-date filter');
    $tables=$floor->listing('', $actor)['tables'];
    check(array_column($tables,'branch')===['f:100','f:101']&&array_column($tables,'branch_name')===['Mahalla','Mansoura'],'central actor '.$actor->id.' floor combines branches and names each table branch');
}
check($sales->receipts(['branch'=>'f:100'],$owner)['today']['total']==='120.00','concrete receipt filters retain their exact branch');
check(count($floor->listing('f:100',$owner)['tables'])===1,'concrete floor listing retains its exact branch');
foreach([4,10,12] as $id){
    $actor=PosBranchUser::find($id);
    denied(fn()=>$sales->summary('',$actor),403,'restricted actor '.$id.' cannot request aggregate summary');
    denied(fn()=>$sales->receipts(['branch'=>'all'],$actor),403,'restricted actor '.$id.' cannot request aggregate receipts');
    denied(fn()=>$floor->listing('all',$actor),403,'restricted actor '.$id.' cannot request aggregate floor');
}
denied(fn()=>$sales->receipts(['branch'=>'all','idempotency_key'=>'00000000-0000-4000-8000-000000000001'],$owner),422,'sale recovery requires its concrete branch');
try{$sales->canonicalCart(['branch'=>'all','items'=>[['product_id'=>1,'quantity'=>'1','quantity_mode'=>'piece']]]);throw new RuntimeException('Aggregate cart accepted');}catch(\Illuminate\Validation\ValidationException $error){check(isset($error->errors()['branch']),'aggregate selection cannot become a sale cart');}
check(snapshot()===$before,'all aggregate and concrete reads leave sales, tills, tables and commands unchanged');
echo $checks.' POS all-branches integration checks passed'.PHP_EOL;
