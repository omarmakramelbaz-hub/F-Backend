<?php
// Owns only an in-memory SQLite database. Does not bootstrap the production app or .env.
require __DIR__.'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade, DB, Schema};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Services\Dashboard\{DesktopPos,DesktopPosQuote,TakeawayAccess,TakeawayService};

function app($abstract=null) { $c=Container::getInstance(); return $abstract?$c->make($abstract):$c; }
function config($key=null,$default=null) { if(is_array($key)){foreach($key as $k=>$v)app('config')->set($k,$v);return;}return app('config')->get($key,$default); }
function now($zone=null) { return \Carbon\Carbon::now($zone); }
function abort_unless($condition,$code,$message='') { if(!$condition)throw new HttpException($code,$message); }
function abort_if($condition,$code,$message='') { if($condition)throw new HttpException($code,$message); }
function abort($code,$message='') { throw new HttpException($code,$message); }
function route($name,$args=[]) { return 'https://test.invalid/'.$name.'?'.http_build_query($args); }
function database_path($path) { return dirname(__DIR__,2).'/database/'.$path; }
class TestApplication extends Container { public function getLocale() { return 'ar'; } }
class TestUser extends \Illuminate\Database\Eloquent\Model { protected $table='users'; public function can($permission) { return false; } }
class_alias(TestUser::class,'App\\Models\\User');
$c=new TestApplication; Container::setInstance($c);$c->instance('config',new \Illuminate\Config\Repository);
$capsule=new Capsule($c);$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);$capsule->setAsGlobal();$capsule->bootEloquent();
$c->instance('db',$capsule->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());Facade::setFacadeApplication($c);
$translator=new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader,'en');
$c->instance('validator',new \Illuminate\Validation\Factory($translator,$c));
config(['app.timezone'=>'Africa/Cairo','desktop_pos.enabled'=>true]);
\Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-07T12:00:00Z'));
Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->unsignedBigInteger('owner_resturant_id')->nullable();});
Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('name');$t->string('phone')->default('01000000000');$t->string('address')->default('المنصورة');});
Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name_ar');$t->string('name_en');});
Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id');$t->unsignedBigInteger('category_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->string('status');$t->text('price');});
foreach([[10,'كاشير','vendor',null],[11,'فرع آخر','vendor',null]] as [$id,$name,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>$name,'account_type'=>$type,'owner_resturant_id'=>$parent]);
foreach([[100,10],[101,11]] as [$id,$user])DB::table('resturants')->insert(['id'=>$id,'user_id'=>$user,'name'=>'فرع '.$id]);
DB::table('categories')->insert(['id'=>1,'name_ar'=>'رنجة','name_en'=>'Herring']);
DB::table('resturant_products')->insert(['id'=>1,'resturant_id'=>100,'product_id'=>1,'category_id'=>1,'product_name'=>'رنجة','product_price'=>'100.00','status'=>'show','price'=>'{}']);
foreach([
 '2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos',
 '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
 '2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills',
 '2026_10_04_190000_create_branch_stock.php'=>'CreateBranchStock',
 '2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes',
 '2026_10_07_210000_create_desktop_pos.php'=>'CreateDesktopPos',
] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
Schema::table('branch_stock_recipes',fn(Blueprint $t)=>$t->boolean('raw_stock')->default(false));
DB::table('branch_inventory')->insert(['branch'=>'f:100','ingredient_id'=>6,'quantity_units'=>10000000,'revision'=>1]);
DB::table('branch_stock_recipes')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'kg','revision'=>1,'updated_by'=>10,'variants'=>json_encode(['0'=>[['ingredient_id'=>6,'name'=>'رنجة سمينة','unit'=>'kg','quantity_units'=>1000000]]])]);
DB::table('takeaway_tills')->insert(['branch'=>'f:100','tax_bps'=>1400,'balance_cents'=>0,'revision'=>1]);
$actor=TestUser::find(10); $service=app(DesktopPos::class);$count=0;
function check($value,$message) {global $count;if(!$value)throw new RuntimeException($message);$count++;echo 'PASS '.$message.PHP_EOL;}
function denied(callable $fn,int $status,string $message) {try{$fn();}catch(HttpException $e){check($e->getStatusCode()===$status,$message);return;}catch(\Illuminate\Validation\ValidationException $e){check($status===422,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$code=$service->issue('f:100','كاشير الاختبار',$actor);$pair=$service->pair($code);$device=$service->device($pair['token']);
denied(fn()=>$service->pair($code),401,'pair code is one use');denied(fn()=>$service->device(str_repeat('0',64)),401,'unknown device token denied');
denied(fn()=>$service->issue('f:101','جهاز',$actor),404,'cross-branch enrollment denied');
$snapshot=$service->snapshot($device);check(count($snapshot['products'])===1,'real catalog and recipe snapshot exported');
$data=['channel'=>'takeaway','items'=>[['product_id'=>1,'option_id'=>'','quantity_mode'=>'weight','quantity'=>'0.250']],
 'delivery_cents'=>0,'discount'=>'0.00','discount_reason'=>'','cash_received'=>'100.00','total_cents'=>2850,
 'customer_name'=>'','customer_phone'=>'','address'=>'','table_name'=>'','notes'=>''];
$unpriced=$snapshot;$unpriced['products'][0]['variants'][0]['unit_price_cents']=0;
denied(fn()=>app(DesktopPosQuote::class)->build($unpriced,app(TakeawayService::class)->canonicalCart(['branch'=>'f:100']+$data),'takeaway',0),422,'unconfigured zero-price offline variant cannot be imported as a sale');
$event=['id'=>(string)Str::uuid(),'order_id'=>(string)Str::uuid(),'snapshot_id'=>$snapshot['id'],'kind'=>'sale','revision'=>1,'occurred_at'=>now('UTC')->toIso8601String(),'data'=>$data];
DB::table('resturant_products')->where('id',1)->update(['product_price'=>'400.00']);
$first=$service->ingest($device,$event);check($first['receipt']['total']==='28.50','offline paid price preserved after menu price changes');
check((int)DB::table('branch_inventory')->value('quantity_units')===9750000,'original recipe deducted once');
$retry=$service->ingest($device,$event);check($retry['replayed']&&$retry['receipt']['id']===$first['receipt']['id'],'lost response replays same receipt');
check(DB::table('takeaway_orders')->count()===1&&DB::table('takeaway_till_entries')->count()===1&&DB::table('branch_recipe_sales')->count()===1,'retry creates no financial or stock duplicates');
check((int)DB::table('takeaway_tills')->value('balance_cents')===2850,'one collection changes drawer once');
$modified=$event;$modified['data']['notes']='غير الفاتورة';denied(fn()=>$service->ingest($device,$modified),409,'same operation key with changed payload rejected');
$bad=$event;$bad['id']=(string)Str::uuid();$bad['order_id']=(string)Str::uuid();$bad['data']['total_cents']=1;denied(fn()=>$service->ingest($device,$bad),409,'client cannot alter price total');
$bad['data']=$data;$bad['data']['items'][0]['product_id']=99;denied(fn()=>$service->ingest($device,$bad),422,'unknown product excluded from offline catalog');
$bad['data']=$data;$bad['data']['cash_received']='1.00';denied(fn()=>$service->ingest($device,$bad),422,'underpayment rolls back whole operation');
check(DB::table('takeaway_orders')->count()===1&&DB::table('desktop_pos_operations')->count()===1,'failed imports create no partial operations');
$bill=$event;$bill['id']=(string)Str::uuid();$bill['order_id']=(string)Str::uuid();$bill['kind']='bill';$service->ingest($device,$bill);
$pay=$bill;$pay['id']=(string)Str::uuid();$pay['revision']=2;$pay['kind']='sale';$pay['data']['notes']='تعديل';denied(fn()=>$service->ingest($device,$pay),409,'printed bill contents locked on server');
$pay['data']=$bill['data'];$pay['snapshot_id']=$service->snapshot($device)['id'];denied(fn()=>$service->ingest($device,$pay),409,'order cannot swap catalog snapshots');
$pay['snapshot_id']=$bill['snapshot_id'];$paid=$service->ingest($device,$pay);check($paid['status']==='paid','locked bill can settle original cash total');
$outOfOrder=$event;$outOfOrder['id']=(string)Str::uuid();$outOfOrder['order_id']=(string)Str::uuid();$outOfOrder['revision']=2;denied(fn()=>$service->ingest($device,$outOfOrder),409,'out-of-order revision remains unacknowledged');
DB::table('desktop_pos_devices')->where('id',$device->id)->update(['enabled'=>false]);denied(fn()=>$service->device($pair['token']),401,'revoked device cannot sync');
echo $count.' checks passed against real POS/import/stock services'.PHP_EOL;
