<?php
/** Execute production counter SQL in isolated SQLite without site configuration or .env.
 * HOLOGRAM_TEST_AUTOLOAD points to a disposable Laravel 8 Composer runtime.
 * No vendor, credentials, or runtime cache belongs in the repository. */
$autoload = getenv('HOLOGRAM_TEST_AUTOLOAD');
if (!$autoload || !is_file($autoload)) throw new RuntimeException('Set HOLOGRAM_TEST_AUTOLOAD to a disposable Laravel 8 vendor/autoload.php');
$loader = require $autoload;
$root = dirname(__DIR__, 2);
$loader->addPsr4('App\\', $root.'/app/', true);
$loader->addPsr4('App\\Services\\', $root.'/app/Services/', true);
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Facade, DB, Schema};
use App\Services\Dashboard\{HomeHologramMetrics,TakeawayAccess,OrderBoardService};
final class HologramFixtureUser extends Illuminate\Foundation\Auth\User {
    protected $table='users'; protected $guarded=[];
    public function can($ability, $arguments=[]) { return (int)$this->id===1 || ((int)$this->id===2 && $ability==='order-list'); }
}
class_alias(HologramFixtureUser::class,'App\\Models\\User');
$app = new Application($root);
$app->instance('config', new Repository([
 'app'=>['timezone'=>'Africa/Cairo','locale'=>'ar','url'=>'https://fixture.invalid','key'=>'base64:'.base64_encode(str_repeat('h',32)),'cipher'=>'AES-256-CBC'],
 'auth'=>['defaults'=>['guard'=>'admin'],'guards'=>['admin'=>['driver'=>'session','provider'=>'users']],'providers'=>['users'=>['driver'=>'eloquent','model'=>HologramFixtureUser::class]]],
 'session'=>['driver'=>'array','lifetime'=>120,'cookie'=>'hologram_fixture','path'=>'/','domain'=>null,'secure'=>true,'lottery'=>[0,100]],
 'hashing'=>['driver'=>'bcrypt','bcrypt'=>['rounds'=>4]], 'desktop_dashboard'=>['local'=>false],
 'database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]]],
]));
foreach ([Illuminate\Events\EventServiceProvider::class,Illuminate\Database\DatabaseServiceProvider::class,Illuminate\Filesystem\FilesystemServiceProvider::class,Illuminate\Translation\TranslationServiceProvider::class,Illuminate\Validation\ValidationServiceProvider::class,Illuminate\Hashing\HashServiceProvider::class,Illuminate\Encryption\EncryptionServiceProvider::class,Illuminate\Cookie\CookieServiceProvider::class,Illuminate\Session\SessionServiceProvider::class,Illuminate\Auth\AuthServiceProvider::class,Illuminate\Routing\RoutingServiceProvider::class,Illuminate\Foundation\Providers\FoundationServiceProvider::class] as $provider) $app->register($provider);
Facade::setFacadeApplication($app); $app->boot();
function table(string $name, array $columns): void { Schema::create($name,function(Blueprint $t)use($columns){foreach($columns as $name=>$type) $t->$type($name)->nullable();}); }
function row(string $name,array $values): void {DB::table($name)->insert($values);}
$checks=0;
function same($expected,$actual,string $reason): void {global $checks; if($expected!==$actual)throw new RuntimeException($reason.' expected '.json_encode($expected).' got '.json_encode($actual)); $checks++;}
function cards(int $id): array { $user=HologramFixtureUser::findOrFail($id); $keys=['employees','customers','expenses','drawer','stock','ch-app','ch-takeaway','ch-phone','ch-dine']; return array_column(app(HomeHologramMetrics::class)->data($user,array_fill_keys($keys,'/admin/fixture'))['cards'],null,'key'); }
function fresh(): void { app()->forgetInstance(HomeHologramMetrics::class); app()->forgetInstance(TakeawayAccess::class); app()->forgetInstance(OrderBoardService::class); }
table('users',['id'=>'integer','name'=>'string','account_type'=>'string','owner_resturant_id'=>'integer','status'=>'string','app_scope'=>'string']);
table('resturants',['id'=>'integer','user_id'=>'integer','parent_id'=>'integer','name'=>'string']);
foreach([[1,'admin',null],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[13,'admin',100],[20,'user',null]]as[$id,$type,$parent])row('users',['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent]);
foreach([[100,10,null],[101,11,null],[102,11,100]]as[$id,$owner,$parent])row('resturants',['id'=>$id,'user_id'=>$owner,'parent_id'=>$parent,'name'=>'Branch '.$id]);
$none=cards(10);
same(null,$none['ch-app']['count'],'Absent app module is unavailable, not zero');
same(false,$none['ch-app']['available'],'Unavailable module has explicit availability');
same(null,$none['stock']['count'],'Absent stock is unavailable');
table('orders',['id'=>'integer','resturant_id'=>'integer','user_id'=>'integer','type'=>'string','status'=>'string','payment_type'=>'string','accepted_notify'=>'string','delegate_from_out'=>'string','source_app'=>'string','created_at'=>'dateTime']);
table('payments',['id'=>'integer','order_id'=>'integer','user_id'=>'integer','status'=>'string','transaction_id'=>'string']);
$legacy=[[1,100,'pending'],[2,100,'accepted'],[3,100,'completed'],[4,100,'cancelled'],[5,101,'pending'],[6,102,'shipped'],[7,102,'completed'],[8,100,'completed'],[9,100,null],[10,100,null],[11,100,'pending']];
foreach($legacy as[$id,$branch,$status])row('orders',['id'=>$id,'resturant_id'=>$branch,'user_id'=>20,'type'=>$id===11?'schedule':'current','status'=>$status,'payment_type'=>in_array($id,[9,10],true)?'online':'cash','source_app'=>$id===8?'go_customer':null,'created_at'=>'2026-10-01 10:00:00']);
row('payments',['id'=>1,'order_id'=>9,'user_id'=>20,'status'=>'failed','transaction_id'=>'fixture']);row('payments',['id'=>2,'order_id'=>10,'user_id'=>20,'status'=>'paid']);
table('pos_service_tickets',array_fill_keys(['id','revision','last_kitchen_id','bill_issued_revision'],'integer')+array_fill_keys(['branch','channel','payment_status','status','customer_name','customer_phone','address','area','notes','quote_snapshot'],'string')+['created_at'=>'dateTime','bill_issued_at'=>'dateTime']);
table('web_storefront_orders',['ticket_id'=>'integer','branch_id'=>'integer','created_at'=>'dateTime']);
$phones=[[20,'f:100','phone','new','unpaid'],[21,'f:100','phone','paid','paid'],[22,'f:102','phone','new','unpaid'],[23,'f:100','phone','new','unpaid'],[24,'f:100','phone','preparing','unpaid'],[25,'f:102','phone','preparing','unpaid'],[26,'f:100','dine','preparing','unpaid'],[27,'f:101','dine','preparing','unpaid'],[28,'f:100','dine','paid','paid'],[29,'f:100','phone','cancelled','unpaid']];
foreach($phones as[$id,$branch,$channel,$status,$pay])row('pos_service_tickets',['id'=>$id,'branch'=>$branch,'channel'=>$channel,'status'=>$status,'payment_status'=>$pay,'created_at'=>'2026-10-01 10:00:00']);
foreach([[20,100,'2026-10-01 10:00:01'],[21,100,'2026-10-01 10:00:01'],[22,100,'2026-10-01 10:00:01'],[23,100,'2026-09-01 10:00:00'],[25,102,'2026-10-01 10:00:01']]as[$ticket,$branch,$date])row('web_storefront_orders',['ticket_id'=>$ticket,'branch_id'=>$branch,'created_at'=>$date]);
table('takeaway_orders',['id'=>'integer','branch'=>'string','channel'=>'string','payment_confirmed'=>'boolean','status'=>'string','payment_status'=>'string','refund_status'=>'string']);
foreach([[1,'f:100',true,null],[2,'f:100',false,null],[3,'f:102',true,null],[4,'f:101',true,null],[5,'f:100',true,'cancelled']]as[$id,$branch,$paid,$status])row('takeaway_orders',['id'=>$id,'branch'=>$branch,'channel'=>'takeaway','payment_confirmed'=>$paid,'status'=>$status]);
table('branch_expenses',['id'=>'integer','branch'=>'string','status'=>'string']);
foreach([[1,'f:100','pending'],[2,'f:102','pending'],[3,'f:101','pending'],[4,'f:100','approved'],[5,'f:100','rejected']]as[$id,$branch,$status])row('branch_expenses',compact('id','branch','status'));
table('branch_employees',['id'=>'integer','branch'=>'string']);
foreach([[1,'f:100'],[2,'f:102'],[3,'f:101']]as[$id,$branch])row('branch_employees',compact('id','branch'));
table('crm_customers',['id'=>'integer']);table('crm_customer_sources',['customer_id'=>'integer','branch'=>'string']);
foreach([1,2,3]as$id)row('crm_customers',compact('id'));
row('crm_customer_sources',['customer_id'=>1,'branch'=>'f:100']);row('crm_customer_sources',['customer_id'=>2,'branch'=>'f:101']);
table('branch_shift_closings',['branch'=>'string']);
table('stock_ingredients',['id'=>'integer','unit'=>'string']);
foreach([1,2,3]as$id)row('stock_ingredients',['id'=>$id,'unit'=>'kg']);
table('branch_inventory',['branch'=>'string','ingredient_id'=>'integer','quantity_units'=>'integer']);
foreach([['f:100',1,1],['f:102',1,0],['f:100',2,50],['f:101',3,0]]as[$branch,$item,$qty])row('branch_inventory',['branch'=>$branch,'ingredient_id'=>$item,'quantity_units'=>$qty]);
table('resturant_products',['id'=>'integer','resturant_id'=>'integer']);
foreach([[1000,100],[1001,102],[1002,101]]as[$id,$branch])row('resturant_products',['id'=>$id,'resturant_id'=>$branch]);
table('branch_stock',['branch'=>'string','product_id'=>'integer','unit'=>'string','quantity_units'=>'integer']);
foreach([['f:100',1000,2],['f:102',1001,50],['f:101',1002,0],['f:100',1002,0]]as[$branch,$item,$qty])row('branch_stock',['branch'=>$branch,'product_id'=>$item,'unit'=>'kg','quantity_units'=>$qty]);
table('branch_stock_minimums',['branch'=>'string','stock_type'=>'string','stock_item_id'=>'integer','unit'=>'string','minimum_units'=>'integer']);
foreach([['f:100','ingredient',1,'kg',2],['f:102','ingredient',1,'kg',2],['f:100','ingredient',2,'kg',10],['f:100','ingredient',3,'kg',2],['f:101','ingredient',3,'kg',2],['f:100','ingredient',2,'piece',100],['f:100','product',1000,'kg',2],['f:102','product',1001,'kg',10],['f:101','product',1002,'kg',2]]as[$branch,$type,$item,$unit,$min])row('branch_stock_minimums',['branch'=>$branch,'stock_type'=>$type,'stock_item_id'=>$item,'unit'=>$unit,'minimum_units'=>$min]);
fresh();$vendor=cards(10);$owner=cards(12);$admin=cards(1);$branchAdmin=cards(13);
same(4,$vendor['ch-app']['count'],'Branch app count includes cash+confirmed online+website, excludes cancelled/foreign/GO/scheduled/failed checkout');
same(null,$vendor['ch-app']['secondary_count'],'Branch cannot see aggregate completed app count');
same(6,$owner['ch-app']['count'],'Owner combines parent+child open app orders only');
same(4,$owner['ch-app']['secondary_count'],'Owner ended count matches ended board including cancellation, excluding GO origin');
same(7,$admin['ch-app']['count'],'Central admin sees all authorized branches');
same(4,$branchAdmin['ch-app']['count'],'Branch admin cannot escape persisted branch');
same(1,$vendor['ch-takeaway']['count'],'Branch counts actual paid noncancelled takeaway receipts');
same(2,$owner['ch-takeaway']['count'],'Owner totals both branch receipts');
same(3,$admin['ch-takeaway']['count'],'Admin totals every permitted branch');
same(2,$vendor['ch-phone']['count'],'Delivery excludes website receipt members but includes stale unrelated receipt');
same(1,$vendor['ch-dine']['count'],'Only open unpaid dining counts');
same(1,$vendor['pending-expenses']['count'],'Pending expenses branch scoped');
same(2,$owner['pending-expenses']['count'],'Owner pending expenses across its branches');
same(3,$admin['pending-expenses']['count'],'Admin pending expenses across authorized branches');
same(1,$vendor['employees']['count'],'Employee count branch scoped');
same(2,$owner['employees']['count'],'Owner employees combines only authorized branch tree');
same(3,$vendor['customers']['count'],'Customer count mirrors intentionally shared complete customer directory');
same(4,$vendor['stock']['count'],'Goods count includes raw catalog and only matching branch products');
same(3,$vendor['stock']['secondary_count'],'Low stock counts threshold equality and missing zero balance, ignores mismatching unit');
same(3,$owner['stock']['secondary_count'],'Low item count deduplicates same ingredient across branches');
same(4,$owner['stock']['low_stock_branches'],'Low stock occurrence count remains separate from item count');
same(true,$owner['stock']['warning'],'Positive low stock produces warning');
same(true,$owner['pending-expenses']['warning'],'Pending expense produces warning');
same('/admin/fixture?branch=f%3A100&status=pending',$vendor['pending-expenses']['url'],'Pending expense link carries authorized branch and status');
same('/admin/fixture?branch=all&status=pending',$admin['pending-expenses']['url'],'Admin link explicitly clears stale branch filter');
Schema::table('branch_expenses',function(Blueprint $t){$t->date('occurred_on')->nullable();});
DB::table('branch_expenses')->where('id',1)->update(['occurred_on'=>'2025-01-01']);
DB::table('branch_expenses')->where('id',2)->update(['occurred_on'=>'2026-01-01']);
DB::table('branch_expenses')->where('id',3)->update(['occurred_on'=>'2020-01-01']);
fresh();$oldPending=cards(10);
$oldQuery=[];parse_str(parse_url($oldPending['pending-expenses']['url'],PHP_URL_QUERY),$oldQuery);
same('2025-01-01',$oldQuery['from'],'Pending expense destination includes earliest authorized lifetime pending expense');
same('pending',$oldQuery['status'],'Pending expense destination retains pending status filter');
same('f:100',$oldQuery['branch'],'Pending expense link never expands vendor scope for old foreign pending expense');
DB::table('orders')->where('id',1)->update(['status'=>'completed']);fresh();
same(3,cards(10)['ch-app']['count'],'Refreshing reads a real transition instead of cached count');
$limited=app(HomeHologramMetrics::class)->data(HologramFixtureUser::findOrFail(10),['ch-app'=>'/admin/app']);
same(['ch-app'],array_column($limited['cards'],'key'),'No card or count leaks for sections omitted by authorized links');

// Test actual controller/session one-shot voice gate independently of unrelated overview reports.
final class HologramFixtureOverview extends App\Services\Dashboard\HomeOverview {
    public function data(array $values,$actor): array {return ['branch_home'=>true];}
}
final class HologramCaptureResponses extends Illuminate\Routing\ResponseFactory {
    public array $boot=[];
    public function __construct() {}
    public function view($view,$data=[],$status=200,array $headers=[]) {$this->boot=$data['boot']; return new Illuminate\Http\Response('fixture',$status,$headers);}
}
$app->instance('request',Illuminate\Http\Request::create('https://fixture.invalid/admin/dashboard'));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,$capture=new HologramCaptureResponses());
$session=$app->make('session.store');$session->start();
$app->make('redirect')->setSession($session);
Illuminate\Support\Facades\Route::get('/admin/dashboard/overview',fn()=>null)->name('dashboard.overview');
$app->make('router')->aliasMiddleware('IsAdmin',App\Http\Middleware\IsAdmin::class);
Illuminate\Support\Facades\Route::prefix('admin')->middleware('IsAdmin')->group(function(){require dirname(__DIR__,2).'/routes/hologram_voice.php';});
$app->make('router')->getRoutes()->refreshNameLookups();
class HologramFixtureVoice extends App\Services\Dashboard\HomeHologramVoice {
    public string $fixtureRoot; public int $fixtureNow=1793563200; public ?string $fixtureScope=null;
    public function __construct(string $root) {$this->fixtureRoot=$root;}
    protected function root(): string {return $this->fixtureRoot;}
    protected function now(): int {return $this->fixtureNow;}
    protected function scope(): string {return $this->fixtureScope??parent::scope();}
}
$voiceTemp=sys_get_temp_dir().'/hologram-voice-'.bin2hex(random_bytes(6));
$app->instance(App\Services\Dashboard\HomeHologramVoice::class,new HologramFixtureVoice($voiceTemp));
Illuminate\Support\Facades\Auth::guard('admin')->login(HologramFixtureUser::findOrFail(10));
$request=Illuminate\Http\Request::create('/admin/dashboard');$request->setLaravelSession($session);
$controller=app(App\Http\Controllers\Dashboard\HomeController::class);
$overview=new HologramFixtureOverview(app(TakeawayAccess::class));
$session->put('go_hologram_login',['nonce'=>'fixture-login-nonce-one','name'=>'Actor 10']);
$response=$controller->index($request,$overview);
same('fixture-login-nonce-one',$capture->boot['greeting']['nonce'],'Only first dashboard after login receives greeting nonce');
same('pending',$capture->boot['greeting']['audio']['state'],'Fresh login receives a nonblocking named-audio readiness descriptor');
same(true,$capture->boot['intro'],'Initial dashboard receives visual intro');
same(true,$response->headers->hasCacheControlDirective('private')&&$response->headers->hasCacheControlDirective('no-store'),'Account bootstrap is never shared cache');
$controller->index($request,$overview);
same(null,$capture->boot['greeting'],'Refresh/return cannot replay greeting');
same(false,$capture->boot['intro'],'Refresh does not restart visual intro');
$session->put('go_hologram_login',['nonce'=>'fixture-login-nonce-two','name'=>'Actor 10']);$session->forget('go_hud_intro');
$controller->index($request,$overview);
same('fixture-login-nonce-two',$capture->boot['greeting']['nonce'],'A subsequent explicit login may greet once again');
$cheap=Illuminate\Http\Request::create('/admin/dashboard/overview?section=hologram');
$payload=json_decode($controller->overview($cheap,$overview)->getContent(),true);
same(true,$payload['success'],'Cheap live-counter endpoint is usable');
same(true,is_array($payload['hologram']['cards']),'Cheap endpoint returns same card contract');

Schema::table('users',function(Blueprint $t){$t->string('email')->nullable();$t->string('mobile')->nullable();$t->string('password')->nullable();$t->rememberToken();});
DB::table('users')->where('id',10)->update(['email'=>'hologram-fixture@example.invalid','password'=>password_hash('FixtureOnly123',PASSWORD_BCRYPT,['cost'=>4])]);
if(!class_exists('Auth',false))class_alias(Illuminate\Support\Facades\Auth::class,'Auth');
$login=Illuminate\Http\Request::create('/admin/login','POST',['email'=>'hologram-fixture@example.invalid','password'=>'FixtureOnly123']);$login->setLaravelSession($session);
$app->instance('request',$login);Illuminate\Support\Facades\Auth::guard('admin')->logout();
$controller->signin($login);
same(10,Illuminate\Support\Facades\Auth::guard('admin')->id(),'Actual login authenticates branch account');
same(true,is_string($session->get('go_hologram_login.nonce'))&&strlen($session->get('go_hologram_login.nonce'))>10,'Actual successful login writes unique greeting stamp');
same(false,$session->has('go_hud_intro'),'Actual login resets visual intro gate');

// Per-account cache, authorization and publication coverage lives in named_voice.php.
app('files')->deleteDirectory($voiceTemp);
echo "PASS hologram real SQLite counters, controller sessions and named-login gate: $checks assertions\n";
