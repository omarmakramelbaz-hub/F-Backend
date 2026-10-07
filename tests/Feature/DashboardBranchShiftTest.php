<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\BranchExpenses;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\Dashboard\PosServiceTicket;
use App\Services\Dashboard\PosServiceTable;
use App\Services\Dashboard\PosServicePhone;
use App\Services\Dashboard\TakeawayService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardBranchShiftTest extends TestCase
{
    private string $connection='sqlite';
    protected function setUp(): void
    {
        parent::setUp();$this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported POS test connection.');
        config(['app.key'=>'base64:'.base64_encode(str_repeat('p',32)),'app.timezone'=>'Africa/Cairo','database.default'=>$this->connection,'cache.default'=>'array']);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        elseif(config('database.connections.mysql.database')!=='takeaway_test')throw new \RuntimeException('POS MySQL tests require dedicated takeaway_test database.');
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');if($this->connection==='mysql')$this->dropFixtures();
        Carbon::setTestNow(Carbon::parse('2026-10-03 16:00:00','Africa/Cairo'));
        Schema::create('users',function(Blueprint $t){$t->id();foreach(['name','account_type','app_scope','status'] as $f)$t->string($f);$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->decimal('balance',14,2)->default(500);$t->timestamps();});
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->timestamps();});
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->text('price');$t->string('status');$t->timestamps();});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->decimal('amount',14,2);});
        Schema::create('orders',function(Blueprint $t){$t->id();$t->string('status');});
        Schema::create('order_board_clocks',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');});
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php');(new \CreateGoStoreCatalog)->up();
        require_once database_path('migrations/2026_10_03_140000_create_takeaway_pos.php');(new \CreateTakeawayPos)->up();
        require_once database_path('migrations/2026_10_03_150000_create_pos_service_tickets.php');(new \CreatePosServiceTickets)->up();
        require_once database_path('migrations/2026_10_04_060000_lock_pos_service_bills.php');(new \LockPosServiceBills)->up();
        require_once database_path('migrations/2026_10_04_000001_create_pos_branch_print_jobs.php');(new \CreatePosBranchPrintJobs)->up();
        require_once database_path('migrations/2026_10_04_030000_create_branch_expenses.php');(new \CreateBranchExpenses)->up();
        require_once database_path('migrations/2026_10_04_100000_create_branch_shift_closings.php');(new \CreateBranchShiftClosings)->up();
        Schema::table('orders',function(Blueprint $t){$t->unsignedBigInteger('resturant_id')->nullable();$t->string('type')->default('current');$t->string('payment_type')->default('cash');$t->string('transfer_price_by')->nullable();$t->decimal('total_price',14,2)->default(0);$t->decimal('delivery_price',14,2)->default(0);$t->decimal('user_tax',14,2)->default(0);$t->timestamps();});
        Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('price',14,2);$t->decimal('updated_total',14,2)->nullable();$t->decimal('qty',10,3);});
        Schema::create('settings',function(Blueprint $t){$t->id();$t->string('name');$t->text('payload');});
        DB::table('settings')->insert(['name'=>'service_fees','payload'=>'0']);
        Storage::fake('local');
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'app_scope'=>$id===30?'go_partner':'fasakhansta','status'=>'accepted','owner_resturant_id'=>$owner]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Foreign']]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{}','status'=>'show'],['id'=>2,'resturant_id'=>101,'product_name'=>'Foreign','product_price'=>'500.00','price'=>'{}','status'=>'show']]);
        DB::table('go_stores')->insert(['user_id'=>30,'name'=>'GO store','kind'=>'grocery','address'=>'Address','created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_store_products')->insert(['id'=>70,'user_id'=>30,'request_key'=>$this->key(70),'name'=>'Rice','unit'=>'كيلو','price_cents'=>10000,'image_path'=>'rice.jpg','available'=>true,'options'=>'[]','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['amount'=>'100.00']);DB::table('orders')->insert(['status'=>'accepted']);DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }
    protected function tearDown(): void{Carbon::setTestNow();if($this->connection==='mysql'&&config('database.connections.mysql.database')==='takeaway_test'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void{foreach(['branch_shift_sources','branch_shift_closings','branch_expense_commands','branch_expenses','pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_store_products','go_stores','order_board_clocks','carts','orders','wallets','settings','pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table)Schema::dropIfExists($table);}
    private function actor(int $id=10): User{return User::withoutGlobalScopes()->findOrFail($id);}
    private function service(): BranchExpenses{return app(BranchExpenses::class);}
    private function key(int $n): string{return sprintf('00000000-0000-4000-8000-%012d',$n);}
    private function payload(int $key=1,array $extra=[]): array{return $extra+['branch'=>'f:100','idempotency_key'=>$this->key($key),'occurred_on'=>'2026-10-03','category'=>'purchases','description'=>'Vegetables','amount'=>'125.50','payment_method'=>'cash'];}
    private function create(int $key=1,array $extra=[],int $actor=10): array{return $this->service()->save($this->payload($key,$extra),$this->actor($actor))['expense'];}
    private function review(array $item,string $action='approve',int $key=20,int $actor=1): array{return $this->service()->review($item['id'],['branch'=>$item['branch'],'action'=>$action,'reason'=>'Reviewed','expected_revision'=>$item['revision'],'idempotency_key'=>$this->key($key)],$this->actor($actor));}
    private function cash(int $amount=100000): void{DB::table('takeaway_tills')->insert(['branch'=>'f:100','balance_cents'=>$amount,'tax_bps'=>0,'revision'=>1]);}
    private function denied(callable $call,int $status=409): void{try{$call();$this->fail('Expected '.$status);}catch(HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    private function invalid(callable $call): void{try{$call();$this->fail('Expected validation error');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}


    private function shifts(){return app(\App\Services\Dashboard\BranchShiftClosing::class);}
    private function sale(int $key,string $channel,int $gross,int $cash,int $delivery=0,string $branch='f:100'): int
    {
        DB::table('takeaway_tills')->insertOrIgnore(['branch'=>$branch,'balance_cents'=>0,'tax_bps'=>0,'revision'=>1,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);$till=DB::table('takeaway_tills')->where('branch',$branch)->first();
        $id=DB::table('takeaway_orders')->insertGetId(['branch'=>$branch,'till_id'=>$till->id,'actor_id'=>10,'request_key'=>$this->key($key),'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),'business_date'=>'2026-10-03','payment_method'=>$cash===$gross?'cash':($cash===0?'card':'mixed'),'payment_confirmed'=>true,'subtotal_cents'=>$gross-$delivery,'discount_cents'=>0,'tax_cents'=>0,'total_cents'=>$gross,'cash_received_cents'=>$cash,'change_cents'=>0,'tax_bps'=>0,'branch_snapshot'=>'{}','cashier_snapshot'=>'{}','channel'=>$channel,'delivery_cents'=>$delivery,'service_cents'=>0,'service_bps'=>0,'tenders_snapshot'=>json_encode([['method'=>'cash','amount_cents'=>$cash],['method'=>'card','amount_cents'=>$gross-$cash]]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $balance=$till->balance_cents+$cash;DB::table('takeaway_tills')->where('id',$till->id)->update(['balance_cents'=>$balance]);DB::table('takeaway_till_entries')->insert(['till_id'=>$till->id,'branch'=>$branch,'actor_id'=>10,'order_id'=>$id,'request_key'=>$this->key($key),'request_hash'=>str_repeat('a',64),'kind'=>'sale','amount_cents'=>$cash,'balance_cents'=>$balance,'business_date'=>'2026-10-03','note'=>'Sale','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);return $id;
    }
    private function appSale(string $method,?string $collector,int $subtotal,int $delivery,string $branch='100'): int
    {
        return DB::table('orders')->insertGetId(['resturant_id'=>$branch,'status'=>'completed','type'=>'current','payment_type'=>$method,'transfer_price_by'=>$collector,'total_price'=>\App\Services\GoServices\Money::decimal($subtotal),'delivery_price'=>\App\Services\GoServices\Money::decimal($delivery),'user_tax'=>'0.00','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    private function closing(int $key=900,string $count='0.00',int $previous=0): array {return ['branch'=>'f:100','idempotency_key'=>$this->key($key),'previous_closing_id'=>$previous,'review_token'=>$this->shifts()->data(['branch'=>'f:100'],$this->actor())['review_token'],'counted_cash'=>$count,'notes'=>'Verified cash count'];}
    public function test_closing_reconciles_four_channels_mixed_cash_delivery_and_expenses_without_revealing_balances(): void
    {
        $this->cash(10000);$this->sale(101,'dine',100000,60000);$this->sale(102,'takeaway',50000,50000);$this->sale(103,'phone',22000,22000,2000);$this->sale(104,'phone',33000,0,3000);$this->sale(105,'takeaway',99900,99900,0,'f:101');
        $this->appSale('cash','vendor',40000,4000);$this->appSale('cash','delegate',60000,6000);$this->appSale('card','admin',70000,7000);$this->appSale('cash','vendor',99900,0,'101');
        $this->create(201,['amount'=>'100.00','approve'=>true],1);
        // Historical bank expenses still reconcile without debiting the cash drawer.
        $legacy=$this->create(202,['amount'=>'50.00'],1);DB::table('branch_expenses')->where('id',$legacy['id'])->update(['payment_method'=>'bank','status'=>'approved']);
        $this->create(203,['amount'=>'99.00']);
        $before=(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents');$preview=$this->shifts()->data(['branch'=>'f:100'],$this->actor());
        $this->assertSame(1,$preview['report']['pending_expenses']);
        foreach(['channels','sales_total','delivery_total','expenses_total','net_sales','expected_cash','opening_cash','variance','counted_cash','till_balance'] as $key)$this->assertArrayNotHasKey($key,$preview['report']);
        $closeValues=$this->closing(900,'1685.00');$result=$this->shifts()->close($closeValues,$this->actor());$id=$result['closing']['id'];$paper=$this->shifts()->receipt($id,$this->actor());
        $this->assertSame('3920.00',$paper['sales_total']);$this->assertSame('220.00',$paper['delivery_total']);$this->assertSame('150.00',$paper['expenses_total']);$this->assertSame('3550.00',$paper['net_sales']);$this->assertSame('1700.00',$paper['expected_cash']);$this->assertSame('1685.00',$paper['counted_cash']);$this->assertSame('-15.00',$paper['variance']);$this->assertSame('عجز',$paper['variance_label']);$this->assertSame('400.00',$paper['app_branch_cash']);$this->assertSame('100.00',$paper['opening_cash']);$this->assertSame('730.00',$paper['noncash_sales']);
        $this->assertSame(0,(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents'));$this->assertSame($before,$paper['till_reset']['before_cents']);$this->assertSame('0.00',$paper['next_shift_opening_cash']);$this->assertSame(-$before,(int)DB::table('takeaway_till_entries')->where('kind','shift_close')->value('amount_cents'));$this->assertArrayNotHasKey('expected_cash',$result['closing']);$this->assertArrayNotHasKey('snapshot',$result['closing']);
        $this->assertTrue($this->shifts()->close($closeValues,$this->actor())['replayed']);$this->assertSame(1,DB::table('branch_shift_closings')->count());
        $this->assertArrayNotHasKey('balance',app(TakeawayService::class)->summary('f:100',$this->actor())['register']);$register=app(TakeawayService::class)->register('f:100',$this->actor());$this->assertArrayNotHasKey('balance',$register['register']);foreach($register['entries'] as $entry)$this->assertArrayNotHasKey('balance',$entry);
        $this->assertArrayNotHasKey('cash_balance',$this->service()->listing(['branch'=>'f:100'],$this->actor())['summary']);$this->assertSame('0.00',app(TakeawayService::class)->summary('f:100',$this->actor(1))['register']['balance']);$this->assertNotContains('shift_close',array_column($register['entries'],'kind'));$this->assertArrayNotHasKey('cash_balance',$this->service()->listing(['branch'=>'f:100'],$this->actor(1))['summary']);$this->assertArrayNotHasKey('sales_total',$this->shifts()->data(['branch'=>'f:100'],$this->actor(1))['report']);
    }
    public function test_next_shift_counts_each_source_once_and_preserves_prior_print_after_refunds_and_late_app_cash(): void
    {
        $this->cash(10000);$this->sale(111,'phone',12000,12000,2000);$pending=$this->appSale('cash',null,5000,500);$expense=$this->create(210,['amount'=>'10.00','approve'=>true],1);
        $first=$this->shifts()->close($this->closing(901,'188.00'),$this->actor())['closing'];$paper=$this->shifts()->receipt($first['id'],$this->actor());$this->assertSame('190.00',$paper['expected_cash']);
        Carbon::setTestNow(Carbon::parse('2026-10-03 17:00:00','Africa/Cairo'));$this->review($expense,'void',211,1);$this->sale(112,'takeaway',2500,2500);DB::table('orders')->where('id',$pending)->update(['transfer_price_by'=>'vendor','updated_at'=>now('UTC')]);
        $second=$this->shifts()->close($this->closing(902,'87.00',$first['id']),$this->actor())['closing'];$next=$this->shifts()->receipt($second['id'],$this->actor());
        $this->assertSame('0.00',$next['opening_cash']);$this->assertSame('85.00',$next['expected_cash']);$this->assertSame('2.00',$next['variance']);$this->assertSame('زيادة',$next['variance_label']);$this->assertSame('-10.00',$next['expenses_total']);$this->assertSame(0,$next['channels']['app']['count']);$this->assertSame('50.00',$next['app_branch_cash']);$this->assertSame('25.00',$next['channels']['takeaway']['gross']);$this->assertSame('0.00',$next['channels']['phone']['gross']);
        $this->assertSame($paper,$this->shifts()->receipt($first['id'],$this->actor()));$empty=$this->shifts()->data(['branch'=>'f:100'],$this->actor());$this->assertArrayNotHasKey('sales_total',$empty['report']);
    }
    public function test_shift_scope_stale_device_and_ambiguous_write_recovery_do_not_duplicate_or_expose_private_totals(): void
    {
        $this->sale(120,'takeaway',1000,1000);$v=$this->closing(910,'10.00');$closed=$this->shifts()->close($v,$this->actor());$id=$closed['closing']['id'];
        $this->denied(fn()=>$this->shifts()->close($this->closing(911,'20.00'),$this->actor()),409);$this->denied(fn()=>$this->shifts()->close(array_replace($v,['counted_cash'=>'11.00']),$this->actor()),409);
        $this->denied(fn()=>$this->shifts()->receipt($id,$this->actor(11)),404);$this->denied(fn()=>$this->shifts()->data(['branch'=>'f:100'],$this->actor(11)),404);
        $recovered=$this->shifts()->recover(['branch'=>'f:100','idempotency_key'=>$v['idempotency_key']],$this->actor());$this->assertTrue($recovered['found']);$this->assertSame($closed['closing'],$recovered['closing']);$this->assertFalse($this->shifts()->recover(['branch'=>'f:100','idempotency_key'=>$v['idempotency_key']],$this->actor(1))['found']);
        $this->get(route('branch-shifts.index'))->assertRedirect();$this->actingAs($this->actor(),'admin');$response=$this->getJson(route('branch-shifts.data',['branch'=>'f:100']))->assertOk()->json();$this->assertArrayNotHasKey('expected_cash',$response['report']);$this->assertArrayNotHasKey('snapshot',$response['history'][0]);
        $this->get(route('branch-shifts.print',['id'=>$id]))->assertOk()->assertSee('data-dashboard-receipt="branch-shift"',false)->assertSee('الكاش الفعلي')->assertSee('النقدية')->assertSee('مطابق');
        $this->getJson(route('branch-shifts.print',['id'=>9999]))->assertNotFound();$this->assertSame(1,DB::table('branch_shift_closings')->count());
    }
    public function test_new_sale_during_cash_count_requires_a_fresh_review_without_creating_a_closing(): void
    {
        $this->sale(130,'takeaway',1000,1000);$v=$this->closing(930,'10.00');$this->sale(131,'takeaway',1000,1000);
        $this->denied(fn()=>$this->shifts()->close($v,$this->actor()),409);$this->assertSame(0,DB::table('branch_shift_closings')->count());$this->assertSame(2000,(int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents'));$this->assertSame(0,DB::table('takeaway_till_entries')->where('kind','shift_close')->count());
        $result=$this->shifts()->close($this->closing(931,'20.00'),$this->actor());$this->assertSame('مطابق',$this->shifts()->receipt($result['closing']['id'],$this->actor())['variance_label']);
    }

    public function test_owner_cash_is_visible_only_to_persisted_owner_and_reset_once_without_touching_other_branch(): void
    {
        $this->sale(950,'phone',12000,12000,2000);$this->sale(951,'takeaway',9000,9000,0,'f:101');$this->appSale('cash','vendor',5000,500);
        $service=app(TakeawayService::class);$this->assertSame('150.00',$service->register('f:100',$this->actor(1))['register']['balance']);
        foreach([4,10,12] as $id){$fake=$this->actor($id);$fake->account_type='admin';$fake->owner_resturant_id=null;$this->assertArrayNotHasKey('balance',$service->register('f:100',$fake)['register']);}
        $v=$this->closing(952,'148.00');$closed=$this->shifts()->close($v,$this->actor());$paper=$this->shifts()->receipt($closed['closing']['id'],$this->actor());
        $this->assertSame('-2.00',$paper['variance']);$this->assertSame('0.00',$service->register('f:100',$this->actor(1))['register']['balance']);
        $this->assertSame('90.00',$service->register('f:101',$this->actor(1))['register']['balance']);
        $revision=$service->register('f:100',$this->actor(1))['register']['revision'];
        $service->changeRegister(['branch'=>'f:100','idempotency_key'=>$this->key(953),'expected_revision'=>$revision,'note'=>'New opening float','direction'=>'in','amount'=>'20.00'],$this->actor(1),false);
        $this->sale(954,'takeaway',3000,3000);$this->create(955,['amount'=>'5.00','approve'=>true],1);
        $this->assertTrue($this->shifts()->close($v,$this->actor())['replayed']);$this->assertTrue($this->shifts()->recover(['branch'=>'f:100','idempotency_key'=>$v['idempotency_key']],$this->actor())['found']);
        $this->assertSame('45.00',$service->register('f:100',$this->actor(1))['register']['balance']);$this->assertSame(1,DB::table('takeaway_till_entries')->where('kind','shift_close')->count());$this->assertSame($paper,$this->shifts()->receipt($closed['closing']['id'],$this->actor()));
    }
    public function test_historical_close_without_reset_uses_its_boundary_and_preserves_saved_paper(): void
    {
        $this->sale(960,'takeaway',10000,10000);$closed=$this->shifts()->close($this->closing(961,'99.00'),$this->actor())['closing'];
        $paper=$this->shifts()->receipt($closed['id'],$this->actor());unset($paper['till_reset'],$paper['next_shift_opening_cash']);
        DB::table('branch_shift_closings')->where('id',$closed['id'])->update(['snapshot'=>json_encode($paper)]);DB::table('takeaway_till_entries')->where('kind','shift_close')->delete();DB::table('takeaway_tills')->where('branch','f:100')->update(['balance_cents'=>10000]);
        $this->assertSame(0,$this->shifts()->ownerBalances(['f:100'],$this->actor(1))['f:100']['expected_cents']);
        $this->sale(962,'takeaway',2000,2000);$this->assertSame(2000,$this->shifts()->ownerBalances(['f:100'],$this->actor(1))['f:100']['expected_cents']);
        $next=$this->shifts()->close($this->closing(963,'20.00',$closed['id']),$this->actor())['closing'];$this->assertSame('0.00',$this->shifts()->receipt($next['id'],$this->actor())['opening_cash']);$this->assertSame($paper,$this->shifts()->receipt($closed['id'],$this->actor()));
    }

}
