<?php
// Real SQLite, Eloquent events, migrations and transaction rollback; no .env or network.
require getenv('CRM_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
spl_autoload_register(function ($class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require_once $file;
});

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\{Facade, DB, Schema};
use App\Services\Dashboard\{CustomerDirectory, CustomerDirectorySync, GoStoreBoardActions};

function app($abstract = null) { $c=Container::getInstance(); return $abstract === null ? $c : $c->make($abstract); }
function now($zone = null) { return \Carbon\Carbon::now($zone); }
function abort_unless($condition, $code, $message='') { if (!$condition) throw new RuntimeException($message, $code); }
function abort_if($condition, $code, $message='') { if ($condition) throw new RuntimeException($message, $code); }
class SyncUserFixture extends Model { protected $table='users'; protected $guarded=[]; }
class SyncAddressFixture extends Model { protected $table='user_address'; protected $guarded=[]; }
class SyncOrderFixture extends Model { protected $table='orders'; protected $guarded=[]; }
class_alias(SyncUserFixture::class, 'App\\Models\\User');
class_alias(SyncAddressFixture::class, 'App\\Models\\UserAddress');
class_alias(SyncOrderFixture::class, 'App\\Models\\Order');
$container = new Container; Container::setInstance($container);
$capsule = new Capsule($container);
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'foreign_key_constraints'=>true]);
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn()=>DB::connection()->getSchemaBuilder()); Facade::setFacadeApplication($container);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:00:00Z'));
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('mobile'); $t->string('name'); $t->string('account_type'); $t->string('app_scope')->nullable(); $t->string('status')->default('accepted'); $t->decimal('balance')->default(0); $t->timestamps(); });
Schema::create('user_address', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('mobile'); $t->string('street_name'); $t->string('area_name'); $t->string('apartment_no')->nullable(); $t->decimal('lat')->nullable(); $t->decimal('lng')->nullable(); $t->timestamps(); });
Schema::create('orders', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->integer('resturant_id')->nullable(); $t->integer('user_address_id')->nullable(); $t->string('type'); $t->string('status'); $t->string('payment_type'); $t->string('payment_status')->nullable(); $t->timestamps(); });
Schema::create('payments', function (Blueprint $t) { $t->id(); $t->integer('order_id'); $t->integer('user_id'); $t->string('status'); });
Schema::create('branch_customers', function (Blueprint $t) { $t->id(); $t->string('phone'); $t->string('name'); $t->string('branch'); $t->string('address')->nullable(); $t->timestamps(); });
Schema::create('pos_service_tickets', function (Blueprint $t) { $t->id(); $t->string('customer_phone'); $t->string('customer_name'); $t->string('branch'); $t->string('channel'); $t->timestamps(); });
Schema::create('takeaway_orders', function (Blueprint $t) { $t->id(); $t->string('branch'); $t->string('channel'); $t->text('context_snapshot'); $t->timestamps(); });
Schema::create('go_store_orders', function (Blueprint $t) { $t->id(); $t->integer('customer_id'); $t->integer('store_id'); $t->string('status'); $t->string('fulfillment'); $t->integer('revision')->default(1); $t->integer('commission_cents')->default(0); $t->integer('total_cents')->default(10000); $t->string('payment_method'); $t->string('payment_status'); $t->string('reason')->nullable(); $t->text('snapshot')->nullable(); $t->timestamps(); });
Schema::create('go_stores', fn(Blueprint $t)=>$t->integer('user_id')->primary());
Schema::create('wallets', function (Blueprint $t) { $t->id(); $t->integer('order_id')->nullable(); $t->string('transfer_reference')->nullable(); $t->integer('from_user')->nullable(); $t->integer('to_user')->nullable(); $t->decimal('amount')->default(0); $t->string('status'); $t->string('payment'); $t->string('type')->default('payment'); $t->timestamps(); });
Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->string('payload'); });
foreach (['2026_10_17_000001_create_crm_customer_directory.php'=>'CreateCrmCustomerDirectory', '2026_10_17_000003_create_crm_customer_sync_states.php'=>'CreateCrmCustomerSyncStates'] as $file=>$class) { require dirname(__DIR__).'/database/migrations/'.$file; (new $class)->up(); }
$old='2026-09-01 12:00:00';
foreach ([[1,'01010000001','Old customer','user','fasakhansta'],[2,'01010000002','GO customer','user','go'],[3,'01010000003','Staff','admin',null],[4,'01010000004','Partner','vendor','go_partner'],[5,'01010000005','Registered customer','user',null]] as [$id,$mobile,$name,$type,$scope]) DB::table('users')->insert(['id'=>$id,'mobile'=>$mobile,'name'=>$name,'account_type'=>$type,'app_scope'=>$scope,'created_at'=>$old,'updated_at'=>$old]);
DB::table('user_address')->insert(['id'=>1,'user_id'=>1,'mobile'=>'01010000001','street_name'=>'Latest saved street','area_name'=>'Mansoura','created_at'=>$old,'updated_at'=>$old]);
DB::table('orders')->insert(['id'=>1,'user_id'=>1,'resturant_id'=>100,'user_address_id'=>1,'type'=>'current','status'=>'completed','payment_type'=>'cash','created_at'=>$old,'updated_at'=>$old]);
DB::table('branch_customers')->insert(['id'=>1,'branch'=>'f:100','phone'=>'+201010000001','name'=>'Stale delivery snapshot','address'=>'Old street','created_at'=>$old,'updated_at'=>$old]);
DB::table('pos_service_tickets')->insert(['id'=>1,'branch'=>'f:100','channel'=>'dine','customer_phone'=>'01010000001','customer_name'=>'Old dine snapshot','created_at'=>$old,'updated_at'=>$old]);
DB::table('takeaway_orders')->insert(['id'=>1,'branch'=>'f:100','channel'=>'takeaway','context_snapshot'=>json_encode(['customer_phone'=>'01010000001','customer_name'=>'Old takeaway snapshot']),'created_at'=>$old,'updated_at'=>$old]);
DB::table('go_stores')->insert(['user_id'=>4]);
DB::table('go_store_orders')->insert(['id'=>1,'customer_id'=>2,'store_id'=>4,'status'=>'completed','fulfillment'=>'delivery','payment_method'=>'cash','payment_status'=>'cash_collected','created_at'=>$old,'updated_at'=>$old]);
$directory=app(CustomerDirectory::class); $sync=app(CustomerDirectorySync::class); $checks=0;
function syncCheck($condition, $label): void { global $checks; $checks++; if (!$condition) throw new RuntimeException('SYNC_FAILED:'.$label); echo 'PASS '.$label.PHP_EOL; }
$batches=0;
do { $result=$sync->run(2); $batches++; syncCheck($result['processed']<=2*$result['sources'], 'each bootstrap batch has a strict per-source bound'); } while (!$result['bootstrapped']);
syncCheck($batches>=3, 'historical import resumes across durable ID watermarks');
syncCheck(DB::table('crm_customers')->count()===3, 'legacy and GO users import while staff and partners stay excluded');
syncCheck(DB::table('crm_purchases')->count()===0, 'no historical purchase starts a feedback backlog');
$customer=DB::table('crm_customers')->where('phone','+201010000001')->first();
syncCheck($customer->name==='Old customer'&&$customer->address==='Latest saved street', 'historical snapshots preserve the current saved profile');
$channels=DB::table('crm_customer_sources')->where('customer_id',$customer->id)->where('branch','f:100')->orderBy('channel')->pluck('channel')->all();
syncCheck($channels===['app','delivery','dine','takeaway'], 'a single customer carries all real purchase source badges');
syncCheck(DB::table('crm_customer_sources')->where('customer_id',DB::table('crm_customers')->where('phone','+201010000005')->value('id'))->where('branch','')->exists(), 'registered customer without orders remains globally unassigned');
SyncUserFixture::observe(\App\Observers\CustomerDirectoryUserObserver::class);
SyncAddressFixture::observe(\App\Observers\CustomerDirectoryAddressObserver::class);
SyncOrderFixture::observe(\App\Observers\CustomerDirectoryOrderObserver::class);
$new=SyncUserFixture::create(['mobile'=>'01110000006','name'=>'New live customer','account_type'=>'user','app_scope'=>'fasakhansta']);
syncCheck(DB::table('crm_customers')->where('phone','+201110000006')->exists(), 'user saved event captures customer immediately');
$address=SyncAddressFixture::create(['user_id'=>$new->id,'mobile'=>'01110000006','street_name'=>'New saved address','area_name'=>'Cairo']);
syncCheck(DB::table('crm_customers')->where('phone','+201110000006')->value('address')==='New saved address', 'saved address enriches the customer immediately');
$order=SyncOrderFixture::create(['user_id'=>$new->id,'resturant_id'=>100,'user_address_id'=>$address->id,'type'=>'current','status'=>'pending','payment_type'=>'cash']);
syncCheck(DB::table('crm_purchases')->count()===0, 'new unpaid app order only captures a profile');
$order->status='completed'; $order->save();
$purchase=DB::table('crm_purchases')->where('source_type','app_order')->where('source_id',$order->id)->first();
syncCheck($purchase&&$purchase->paid_at==='2026-10-17 12:00:00'&&$purchase->feedback_due_at==='2026-10-18 12:00:00', 'completed app order uses actual completion plus twenty-four hours');
syncCheck($directory->purchaseIsCompleted($purchase), 'app purchase passes authoritative send-time status check');
$order->payment_status='paid'; $order->save(); $sync->captureOrder($order,true);
syncCheck(DB::table('crm_purchases')->count()===1, 'observer and direct retry cannot duplicate feedback or postpone its due time');
$order->payment_status='refunded'; $order->save();
syncCheck(!$directory->purchaseIsCompleted($purchase)&&DB::table('crm_purchases')->where('id',$purchase->id)->value('feedback_status')==='cancelled', 'refund suppresses an already scheduled feedback request');
$failed=SyncOrderFixture::create(['user_id'=>$new->id,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'card']);
$failed->status='completed'; $failed->save();
syncCheck(DB::table('crm_purchases')->count()===1, 'unverified online completion cannot queue feedback');
$paid=SyncOrderFixture::create(['user_id'=>$new->id,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'card']);
DB::table('payments')->insert(['order_id'=>$paid->id,'user_id'=>$new->id,'status'=>'1']); $paid->status='completed'; $paid->save();
syncCheck(DB::table('crm_purchases')->count()===2, 'verified paid app card order queues feedback');
$wallet=SyncOrderFixture::create(['user_id'=>$new->id,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'wallet']);
DB::table('wallets')->insert(['order_id'=>$wallet->id,'from_user'=>$new->id,'to_user'=>null,'payment'=>'wallet','status'=>'completed']); $wallet->status='completed'; $wallet->save();
syncCheck(DB::table('crm_purchases')->count()===3, 'completed wallet debit allows feedback');
$countBefore=DB::table('crm_customers')->count(); $purchaseBefore=DB::table('crm_purchases')->count();
try { DB::transaction(function () { $u=SyncUserFixture::create(['mobile'=>'01210000007','name'=>'Rolled back','account_type'=>'user']); $o=SyncOrderFixture::create(['user_id'=>$u->id,'resturant_id'=>100,'type'=>'current','status'=>'pending','payment_type'=>'cash']); $o->status='completed'; $o->save(); throw new RuntimeException('rollback fixture'); }); } catch (RuntimeException $e) { if ($e->getMessage()!=='rollback fixture') throw $e; }
syncCheck(DB::table('crm_customers')->count()===$countBefore&&DB::table('crm_purchases')->count()===$purchaseBefore, 'sale transaction rollback also rolls back profile and feedback record');
$go=DB::table('go_store_orders')->insertGetId(['customer_id'=>2,'store_id'=>4,'status'=>'ready','fulfillment'=>'pickup','payment_method'=>'cash','payment_status'=>'cash_due','snapshot'=>json_encode(['customer_name'=>'GO checkout customer']),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
app(GoStoreBoardActions::class)->transition($go,4,'complete',1);
$goPurchase=DB::table('crm_purchases')->where('source_type','go_store_order')->where('source_id',$go)->first();
syncCheck($goPurchase&&$goPurchase->branch==='gs:4'&&$directory->purchaseIsCompleted($goPurchase), 'real GO query-builder completion captures paid purchase in its transaction');
app(GoStoreBoardActions::class)->transition($go,4,'complete',1);
syncCheck(DB::table('crm_purchases')->where('source_type','go_store_order')->count()===1, 'GO completion retry is idempotent');
DB::table('go_store_orders')->where('id',$go)->update(['payment_status'=>'refunded']);
syncCheck(!$directory->purchaseIsCompleted($goPurchase), 'send-time check rejects GO refund even when event hook was bypassed');
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:01:00Z'));
DB::table('branch_customers')->where('id',1)->update(['name'=>'Updated branch customer','updated_at'=>now('UTC')]);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-17T12:02:00Z'));
$sync->run(500);
syncCheck(DB::table('crm_customers')->where('id',$customer->id)->value('name')==='Updated branch customer', 'timestamp watermark reconciles direct profile changes');
$sync->run(500); // Drain update keys for rows captured by the new-ID pass.
$quiet=$sync->run(500);
syncCheck($quiet['processed']===0, 'unchanged history is not rescanned every minute');
$lateId=DB::table('branch_customers')->insertGetId(['branch'=>'f:100','phone'=>'01210000008','name'=>'Backdated imported record','created_at'=>$old,'updated_at'=>$old]);
$sync->run(500);
syncCheck(DB::table('crm_customers')->where('phone','+201210000008')->exists(), 'ID watermark also catches backdated newly inserted customer records');
syncCheck(DB::table('crm_purchases')->count()===$purchaseBefore+1, 'reconciliation never turns old or edited sales into new feedback');
echo $checks.' customer directory sync checks passed'.PHP_EOL;
