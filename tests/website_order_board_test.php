<?php
/**
 * Real SQLite board queries, branch authority, operation replay, ticket/kitchen lifecycle and
 * delivery dispatch. Only the final shared sales adapter is replaced by a recording fixture;
 * this suite does not claim to retest the POS inventory/accounting implementation.
 * WEBSITE_BOARD_TEST_AUTOLOAD=/path/to/Illuminate8/vendor/autoload.php php tests/website_order_board_test.php
 */
$autoload = getenv('WEBSITE_BOARD_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) throw new RuntimeException('Set WEBSITE_BOARD_TEST_AUTOLOAD to an Illuminate 8 compatible autoloader.');
require $autoload;
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) return;
    $path = dirname(__DIR__).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require_once $path;
}, true, true);

use App\Services\Dashboard\{OrderBoardService, OrderBoardPrinting, WebsiteOrderBoardActions, BranchOperations, PosServiceTicket, TakeawayAccess, TakeawayService};
use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Facade, Schema, Validator};
use Symfony\Component\HttpKernel\Exception\HttpException;

function app($abstract = null, array $parameters = []) { $c = Container::getInstance(); return $abstract ? $c->make($abstract, $parameters) : $c; }
function config($key = null, $default = null) { if (is_array($key)) { foreach ($key as $k => $v) app('config')->set($k, $v); return; } return app('config')->get($key, $default); }
function now($zone = null) { return Carbon::now($zone); }
function abort_unless($ok, $status, $message = '') { if (!$ok) throw new HttpException($status, $message); }
function abort_if($ok, $status, $message = '') { if ($ok) throw new HttpException($status, $message); }
function abort($status, $message = '') { throw new HttpException($status, $message); }
function route($name, $args = []) { return 'https://website-board.invalid/'.$name.'?'.http_build_query($args); }
function __($key, $replace = [], $locale = null) { return $key; }
function database_path($path = '') { return dirname(__DIR__).'/database/'.$path; }
class WebsiteBoardUser extends \Illuminate\Database\Eloquent\Model {
    protected $table = 'users';
    public function can($name) { return (int) $this->id === 2 && $name === 'order-list'; }
}
class_alias(WebsiteBoardUser::class, 'App\\Models\\User');
class WebsiteBoardAuthority { public function currentControllerFacts($actor) { return null; } }
class_alias(WebsiteBoardAuthority::class, 'App\\Services\\Dashboard\\DesktopDashboardCoreAuthority');
// The lightweight database runtime may omit illuminate/http. Keep HTTP input transport
// faithful to Symfony while running all validation through the real Laravel validator.
if (!class_exists(Request::class)) {
    class WebsiteBoardRequest extends \Symfony\Component\HttpFoundation\Request {
        public function all() { return array_replace($this->query->all(), $this->request->all()); }
        public function input($key, $default = null) { return $this->all()[$key] ?? $default; }
        public function query($key = null, $default = null) { return $key === null ? $this->query->all() : $this->query->get($key, $default); }
        public function validate($rules) { return Validator::make($this->all(), $rules)->validate(); }
    }
    class_alias(WebsiteBoardRequest::class, Request::class);
}

class WebsiteBoardSales extends TakeawayService {
    public array $calls = [];
    public function __construct() {}
    public function checkout(array $values, $actor, array $context = [], bool $requireServiceConsent = false): array {
        // The real ticket service must delegate exactly its saved quote/cart to the shared POS.
        $this->calls[] = [$values, (int) $actor->id, $context, $requireServiceConsent];
        abort_unless($values['payment_method'] === 'cash' && $values['payment_confirmed'] === true, 422);
        $total = \App\Services\GoServices\Money::minor($context['saved_quote']['total']);
        abort_unless(\App\Services\GoServices\Money::minor($values['cash_received']) >= $total, 422);
        $id = DB::table('takeaway_orders')->insertGetId(['branch'=>$values['branch'], 'actor_id'=>$actor->id,
            'request_key'=>$values['idempotency_key'], 'total_cents'=>$total, 'channel'=>'phone',
            'ticket_id'=>$context['ticket_id'], 'created_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
        return ['receipt'=>$this->receipt($id, $actor), 'register'=>[], 'today'=>[]];
    }
    public function receipt(int $id, $actor): array { return ['id'=>$id, 'receipt_url'=>route('takeaway.print', ['id'=>$id])]; }
    public function firstReceiptPrintUrl(int $id, $actor): ?string { return route('takeaway.print', ['id'=>$id]); }
}

$container = new Container;
Container::setInstance($container);
$container->instance('config', new \Illuminate\Config\Repository);
$capsule = new Capsule($container);
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true]);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => DB::connection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'ar');
$container->instance('translator', $translator);
$container->instance('validator', new \Illuminate\Validation\Factory($translator, $container));
if (method_exists(Request::class, 'macro')) Request::macro('validate', function ($rules) { return Validator::make($this->all(), $rules)->validate(); });
config(['app.timezone'=>'Africa/Cairo', 'app.key'=>'synthetic-website-board-key', 'desktop_dashboard.local'=>false]);
Carbon::setTestNow(Carbon::parse('2026-10-21T12:00:00Z'));

Schema::create('users', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable();
    $t->string('app_scope')->nullable(); $t->string('status')->default('accepted');
});
foreach ([[1,'admin',null], [2,'admin',null], [4,'admin',100], [10,'vendor',null], [11,'vendor',null], [12,'resturant_owner',100], [60,'vendor',null]] as [$id,$type,$branch]) {
    DB::table('users')->insert(['id'=>$id, 'name'=>'Actor '.$id, 'account_type'=>$type, 'owner_resturant_id'=>$branch,
        'app_scope'=>$id === 60 ? 'go_partner' : 'fasakhansta']);
}
Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); });
DB::table('permissions')->insert(['name'=>'order-list', 'guard_name'=>'admin']);
Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('name'); });
DB::table('resturants')->insert([
    ['id'=>100,'user_id'=>10,'parent_id'=>null,'name'=>'المنصورة'],
    ['id'=>101,'user_id'=>11,'parent_id'=>null,'name'=>'المحلة'],
    ['id'=>102,'user_id'=>13,'parent_id'=>100,'name'=>'الفرع التابع'],
]);
Schema::create('go_stores', function (Blueprint $t) { $t->unsignedBigInteger('user_id')->primary(); $t->string('name'); });
DB::table('go_stores')->insert(['user_id'=>60,'name'=>'GO متجر']);
Schema::create('takeaway_tills', function (Blueprint $t) { $t->id(); });
Schema::create('takeaway_orders', function (Blueprint $t) {
    $t->id(); $t->string('branch'); $t->unsignedBigInteger('actor_id'); $t->uuid('request_key')->unique();
    $t->unsignedBigInteger('total_cents'); $t->timestamps();
});
Schema::create('takeaway_order_items', function (Blueprint $t) { $t->id(); });
Schema::create('takeaway_till_entries', function (Blueprint $t) { $t->id(); });
foreach ([
    '2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets',
    '2026_10_04_060000_lock_pos_service_bills.php'=>'LockPosServiceBills',
    '2026_10_04_080000_create_branch_operations.php'=>'CreateBranchOperations',
    '2026_10_04_000001_create_pos_branch_print_jobs.php'=>'CreatePosBranchPrintJobs',
    '2026_10_06_130000_create_phone_delivery_batches.php'=>'CreatePhoneDeliveryBatches',
    '2026_10_17_160000_create_web_storefront_orders.php'=>'CreateWebStorefrontOrders',
] as $file=>$class) { require_once database_path('migrations/'.$file); (new $class)->up(); }

$sales = new WebsiteBoardSales;
$container->instance(TakeawayService::class, $sales);
$container->singleton(TakeawayAccess::class);
$container->singleton(BranchOperations::class);
$container->singleton(PosServiceTicket::class);
$container->singleton(WebsiteOrderBoardActions::class);
$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); ++$checks; echo 'PASS '.$label.PHP_EOL; }
function denied(callable $call, int $status, $label) { try { $call(); } catch (HttpException $e) { check($e->getStatusCode() === $status, $label); return; } throw new RuntimeException('Expected '.$status.': '.$label); }
function actor($id = 1) { return WebsiteBoardUser::findOrFail($id); }
function keyFor($id) { return sprintf('00000000-0000-4000-8000-%012d', $id); }
function fixtureTicket($id, $branch='f:100', $status='new', $payment='unpaid', $mapped=true, $channel='phone', $created='2026-10-21 09:00:00') {
    $line = ['product_id'=>7, 'name'=>'رنجة', 'option_id'=>'f:2:extra_clear', 'option_label'=>'نصف / مخلي',
        'unit'=>'kg', 'quantity_mode'=>'weight', 'quantity'=>'0.750', 'quantity_millis'=>750,
        'unit_price_cents'=>22000, 'total_cents'=>16500, 'total'=>'165.00'];
    $quote = ['branch'=>['value'=>$branch,'name'=>'فرع محفوظ'], 'quote_hash'=>str_repeat('a',64),
        'subtotal'=>'165.00','discount'=>'0.00','tax'=>'0.00','service'=>'0.00','delivery'=>'50.00','total'=>'215.00','items'=>[$line]];
    DB::table('pos_service_tickets')->insert(['id'=>$id, 'branch'=>$branch, 'channel'=>$channel, 'actor_id'=>10,
        'status'=>$status, 'payment_status'=>$payment, 'customer_name'=>'عميل الموقع '.$id, 'customer_phone'=>'01012345678',
        'address'=>'شارع قناة السويس 15', 'area'=>'المنصورة', 'notes'=>'طلب الموقع الإلكتروني',
        'delivery_cents'=>5000, 'cart_snapshot'=>json_encode(['branch'=>$branch,'items'=>[['product_id'=>7,'quantity'=>'0.750','quantity_mode'=>'weight','option_id'=>'f:2:extra_clear']], 'discount'=>'0.00']),
        'quote_snapshot'=>json_encode($quote, JSON_UNESCAPED_UNICODE), 'business_date'=>'2026-10-21', 'created_at'=>$created, 'updated_at'=>$created]);
    if ($mapped) DB::table('web_storefront_orders')->insert(['session_hash'=>hash('sha256','session'.$id), 'request_key'=>keyFor($id),
        'payload_hash'=>hash('sha256','payload'.$id), 'branch_id'=>(int) substr($branch,2), 'ticket_id'=>$id, 'created_at'=>$created]);
}
function board(array $filters=[], $id=1) { return app(OrderBoardService::class)->data(Request::create('/admin/applies-orders', 'GET', $filters), actor($id)); }
function identities(array $board): array { $ids=[]; foreach ($board['groups'] as $cards) foreach ($cards as $card) $ids[]=$card['key']; sort($ids); return $ids; }
function transition($id, $action, array $extra=[], $actorId=1) {
    $row=DB::table('pos_service_tickets')->where('id',$id)->first();
    $offset=['accept'=>1000,'reject'=>2000,'dispatch'=>3000,'complete'=>4000][$action];
    $v=$extra+['action'=>$action,'expected_status'=>$row->status,'expected_revision'=>(int)$row->revision,'idempotency_key'=>keyFor($offset+$id)];
    return app(WebsiteOrderBoardActions::class)->transition($id, $v, actor($actorId), app(OrderBoardService::class));
}

fixtureTicket(1); fixtureTicket(2,'f:100','preparing'); fixtureTicket(3,'f:100','out_for_delivery');
fixtureTicket(4,'f:100','finished'); fixtureTicket(5,'f:100','finished','paid'); fixtureTicket(6,'f:100','cancelled');
fixtureTicket(7,'f:101'); fixtureTicket(8,'f:102');
fixtureTicket(20,'f:100','new','unpaid',false); // A staff delivery may share the website note; it is not website-origin.
fixtureTicket(21,'f:100','new','unpaid',true,'dine');
fixtureTicket(22); DB::table('web_storefront_orders')->where('ticket_id',22)->update(['branch_id'=>101]);
fixtureTicket(23); DB::table('web_storefront_orders')->where('ticket_id',23)->update(['created_at'=>'2026-10-20 09:00:00']);
$all=board();
check($all['counts']===['new'=>3,'preparing'=>1,'courier'=>2,'completed'=>2], 'mapped website tickets enter each board column exactly once');
check(count(identities($all))===count(array_unique(identities($all))) && !in_array('website:20',identities($all))
    && !in_array('website:21',identities($all)) && !in_array('website:22',identities($all)), 'normal phone, dine and inconsistent mapping rows do not masquerade as website orders');
check(!in_array('website:23',identities($all)), 'a historical receipt cannot classify a newer reused POS id as a website order');
denied(fn () => transition(23,'accept'),404,'a historical website receipt cannot authorize a newer unrelated POS ticket');
check(identities(board([],2))===identities($all), 'permitted administrative account can receive all website branches');
check(identities(board([],10))===['website:1','website:2','website:3','website:4','website:5','website:6'], 'branch vendor sees only persisted own restaurant website tickets');
check(identities(board([],4))===identities(board([],10)), 'branch manager scope does not widen to another branch');
check(identities(board([],12))===['website:1','website:2','website:3','website:4','website:5','website:6','website:8'], 'restaurant owner receives its own and child branch tickets');
check(identities(board([],60))===[], 'GO-only store identity cannot receive Fasakhansta website tickets');
check(identities(board(['app'=>'go']))===[] && identities(board(['app'=>'fasakhansta']))===identities($all), 'app filters separate GO from Fasakhansta website origin');
check(identities(board(['branch'=>'f:101']))===['website:7'] && identities(board(['branch'=>'gs:60']))===[], 'selected branch filters bind website tickets to their native restaurant');
check(identities(board(['search'=>'WEB-7']))===['website:7'], 'WEB receipt search finds the canonical website ticket');
check(count(identities(board(['search'=>'01012345678'])))===8 && identities(board(['search'=>'عميل الموقع 7']))===['website:7'], 'website customer name and phone are searchable without native customer identity');
$card=app(OrderBoardService::class)->detail('website',1,actor());
check($card['number']==='WEB-1' && $card['app']==='fasakhansta' && $card['app_label']==='الموقع الإلكتروني'
    && $card['store']==='المنصورة' && $card['customer']==='عميل الموقع 1' && $card['phone']==='01012345678', 'website card identifies its real origin, guest customer and receiving branch');
check($card['total']==='215.00' && $card['items'][0]['quantity']==='0.750' && $card['items'][0]['line_total']==='165.00'
    && $card['items'][0]['option_label']==='نصف / مخلي' && $card['items'][0]['quantity_unit']==='كجم', 'website snapshot retains exact totals, weight and preparation choice');
check($card['created_at']==='2026-10-21T12:00:00+03:00', 'UTC POS timestamp is displayed in Cairo time');
check(app(OrderBoardService::class)->pendingAlertKeys(actor(10))===['website:1'], 'only unaccepted own-branch website tickets trigger persistent new-order alerts');
denied(fn()=>app(OrderBoardService::class)->detail('website',7,actor(10)),404,'details endpoint rechecks website branch scope');
fixtureTicket(30,'f:100','new','unpaid',true,'phone','2026-10-21 02:59:59');
fixtureTicket(31,'f:100','new','unpaid',true,'phone','2026-10-21 03:00:00');
fixtureTicket(32,'f:100','new','unpaid',true,'phone','2026-10-22 02:59:59');
fixtureTicket(33,'f:100','new','unpaid',true,'phone','2026-10-22 03:00:00');
$dateIds=identities(board(['date'=>'2026-10-21']));
check(!in_array('website:30',$dateIds) && in_array('website:31',$dateIds) && in_array('website:32',$dateIds)
    && !in_array('website:33',$dateIds), 'website date filter uses the Cairo 06:00 accounting-day boundary against UTC storage');
check(!Schema::hasTable('orders') && DB::table('takeaway_orders')->count()===0, 'visibility and alert reads create no native order or second sale');

// Keep the actual POS ticket and dispatch services; test operation replay across all transitions.
fixtureTicket(100); $input=['action'=>'accept','expected_status'=>'new','expected_revision'=>1,'idempotency_key'=>keyFor(2100)];
$actions=app(WebsiteOrderBoardActions::class); $service=app(OrderBoardService::class);
$accepted=$actions->transition(100,$input,actor(),$service);
check(DB::table('pos_service_tickets')->where('id',100)->value('status')==='preparing'
    && DB::table('pos_service_kitchen_tickets')->where('ticket_id',100)->count()===1
    && DB::table('pos_branch_print_jobs')->where('ticket_id',100)->count()===1, 'acceptance uses the real POS kitchen action and queues one branch print');
$replayed=$actions->transition(100,$input,actor(),$service);
check($replayed['replayed']===true && DB::table('pos_service_commands')->where('ticket_id',100)->count()===1
    && DB::table('pos_branch_print_jobs')->where('ticket_id',100)->count()===1, 'lost accept response replays without duplicate kitchen or print work');
denied(fn()=>$actions->transition(100,array_replace($input,['idempotency_key'=>keyFor(2101)]),actor(),$service),409,'a stale acceptance with a new key is rejected');
denied(fn()=>$actions->transition(100,array_replace($input,['reason'=>'different payload']),actor(),$service),409,'idempotency key cannot be reused for different website action data');
check(!in_array('website:100',$service->pendingAlertKeys(actor())), 'acceptance removes the website alert immediately');
denied(fn()=>transition(7,'accept',[],10),404,'another branch cannot accept a website ticket');
denied(fn()=>transition(20,'accept'),404,'ordinary phone ticket cannot be operated through the website source');
denied(fn()=>transition(100,'reject'),409,'website rejection cannot bypass POS protection after kitchen acceptance');
fixtureTicket(101); transition(101,'reject');
check(DB::table('pos_service_tickets')->where('id',101)->value('status')==='cancelled'
    && DB::table('takeaway_orders')->count()===0, 'rejecting an unaccepted website ticket makes no collectible sale');
DB::table('branch_delivery_companies')->insert([
    ['id'=>1,'branch'=>'f:100','name'=>'مندوب المنصورة','phone'=>'01011111111','active'=>true,'actor_id'=>1],
    ['id'=>2,'branch'=>'f:101','name'=>'مندوب المحلة','phone'=>'01022222222','active'=>true,'actor_id'=>1],
]);
denied(fn()=>transition(100,'dispatch'),422, 'dispatch requires an explicit company selection');
denied(fn()=>transition(100,'dispatch',['company_id'=>2]),422,'dispatch rejects a company from another branch');
$dispatch=['action'=>'dispatch','expected_status'=>'preparing','expected_revision'=>2,'company_id'=>1,'idempotency_key'=>keyFor(2200)];
$actions->transition(100,$dispatch,actor(),$service); $actions->transition(100,$dispatch,actor(),$service);
check(DB::table('pos_service_tickets')->where('id',100)->value('status')==='out_for_delivery'
    && DB::table('phone_delivery_dispatches')->where('ticket_id',100)->count()===1, 'real company handoff changes the website card to courier once');
$completion=['action'=>'complete','expected_status'=>'out_for_delivery','expected_revision'=>3,'quote_hash'=>str_repeat('a',64),
    'cash_received'=>'215.00','payment_confirmed'=>true,'idempotency_key'=>keyFor(2300)];
denied(fn()=>$actions->transition(100,array_replace($completion,['payment_confirmed'=>false]),actor(),$service),422, 'completion requires explicit real cash collection confirmation');
denied(fn()=>$actions->transition(100,array_replace($completion,['quote_hash'=>str_repeat('b',64)]),actor(),$service),409,'changed website amount fingerprint blocks settlement');
$completed=$actions->transition(100,$completion,actor(),$service); $again=$actions->transition(100,$completion,actor(),$service);
check(DB::table('pos_service_tickets')->where('id',100)->value('payment_status')==='paid' && $again['replayed']===true
    && DB::table('takeaway_orders')->where('ticket_id',100)->count()===1 && count($sales->calls)===1, 'completion delegates one sale to the shared POS and response replay cannot collect twice');
check($sales->calls[0][0]['items'][0]['quantity']==='0.750' && $sales->calls[0][0]['items'][0]['option_id']==='f:2:extra_clear'
    && $sales->calls[0][2]['saved_quote']['total']==='215.00' && $sales->calls[0][2]['ticket_id']===100,
    'shared settlement receives the saved website cart, preparation, total and canonical ticket reference');
check(!array_key_exists('service_consent',$sales->calls[0][0]) && !array_key_exists('marketing_consent',$sales->calls[0][0]), 'website acceptance and collection invent no customer follow-up consent');
check($service->detail('website',100,actor())['group']==='completed' && !Schema::hasTable('orders'), 'paid website ticket appears in completed without minting a duplicate app order');

fixtureTicket(110);
$printing=app(OrderBoardPrinting::class);
check(!$printing->allowed('website',DB::table('pos_service_tickets')->find(110),actor(10)), 'new website order cannot be bill-locked before kitchen acceptance');
transition(110,'accept');
check($printing->allowed('website',DB::table('pos_service_tickets')->find(110),actor(10)), 'branch can issue the first website invoice after kitchen acceptance');
$printing->issue('website',110,actor(10),$service);
$printed=DB::table('pos_service_tickets')->find(110);
check(!empty($printed->bill_issued_at) && (int)$printed->bill_issued_revision===3
    && $printing->printed('website',$printed), 'website invoice issuance locks and audits the same POS delivery ticket');
denied(fn()=>$printing->issue('website',110,actor(10),$service),403,'branch cannot reprint website invoice through its second receiving screen');
check($printing->allowed('website',$printed,actor()) && !$printing->allowed('website',$printed,actor(10)), 'invoice reprint remains available to owner/admin only');
$printing->issue('website',110,actor(),$service);
check((int)DB::table('pos_service_tickets')->where('id',110)->value('revision')===3, 'authorized website reprint does not issue another bill revision');
denied(fn()=>app(PosServiceTicket::class)->action('phone',110,['branch'=>'f:100','expected_revision'=>3,
    'idempotency_key'=>keyFor(2500),'action'=>'send_kitchen'],actor(10)),409,'shared delivery screen cannot edit or resend a bill-locked website ticket');
transition(110,'dispatch',['company_id'=>1]);
check(DB::table('pos_service_tickets')->where('id',110)->value('status')==='out_for_delivery', 'operational courier handoff still works after issuing an immutable website bill');
denied(fn()=>$printing->issue('website',7,actor(10),$service),404,'website print endpoint rejects another branch ticket');

Schema::create('orders',function(Blueprint $t){
    $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('user_id')->nullable();
    $t->string('type'); $t->string('status'); $t->string('order_no'); $t->string('payment_type')->default('cash');
    $t->decimal('total_price',14,2)->default(0); $t->timestamps();
});
DB::table('orders')->insert(['id'=>1,'resturant_id'=>100,'type'=>'current','status'=>'pending','order_no'=>'APP-1',
    'created_at'=>'2026-10-21 12:00:00','updated_at'=>'2026-10-21 12:00:00']);
$mixed=identities(board(['branch'=>'f:100']));
check(in_array('legacy:1',$mixed) && in_array('website:1',$mixed)
    && count($mixed)===count(array_unique($mixed)), 'existing native app order and website ticket with the same numeric ID remain distinct in the unified feed');
Schema::rename('web_storefront_orders','website_receipts_temporarily_absent');
check(identities(board())===['legacy:1'], 'pre-migration server safely omits website source while retaining existing application orders');
echo 'Website order board checks passed: '.$checks.PHP_EOL;

