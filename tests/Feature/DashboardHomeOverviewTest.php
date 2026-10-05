<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{HomeOverview,BranchShiftClosing};
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardHomeOverviewTest extends TestCase
{
    private string $connection='sqlite';
    protected function setUp(): void
    {
        parent::setUp();$this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported test database');
        if($this->connection==='mysql'&&config('database.connections.mysql.database')!=='takeaway_test')throw new \RuntimeException('Requires isolated takeaway_test');
        config(['app.key'=>'base64:'.base64_encode(str_repeat('h',32)),'app.timezone'=>'Africa/Cairo','database.default'=>$this->connection,'cache.default'=>'array']);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');if($this->connection==='mysql')$this->dropFixtures();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00','Africa/Cairo'));
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('account_type');$t->string('app_scope')->nullable();$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->timestamps();});
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->string('status')->default('opened');$t->string('control')->default('show');$t->timestamps();});
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('status')->default('show');});
        Schema::create('orders',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('user_id')->nullable();$t->string('order_no')->nullable();$t->string('type')->default('current');$t->string('status')->nullable();$t->string('accepted_notify')->nullable();$t->string('delegate_from_out')->nullable();$t->string('payment_type')->default('cash');$t->string('transfer_price_by')->nullable();$t->decimal('total_price',15,2)->default(0);$t->decimal('delivery_price',15,2)->default(0);$t->decimal('user_tax',15,2)->default(0);$t->timestamps();});
        Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->decimal('price',15,2);$t->decimal('qty',10,3);$t->decimal('updated_total',15,2)->nullable();});
        Schema::create('settings',function(Blueprint $t){$t->id();$t->string('name');$t->text('payload');});
        Schema::create('pending_vendors',function(Blueprint $t){$t->id();$t->string('status');});
        foreach(['2026_10_03_060000_create_order_board_clocks.php'=>'CreateOrderBoardClocks','2026_10_03_140000_create_takeaway_pos.php'=>'CreateTakeawayPos','2026_10_03_150000_create_pos_service_tickets.php'=>'CreatePosServiceTickets','2026_10_04_030000_create_branch_expenses.php'=>'CreateBranchExpenses','2026_10_04_100000_create_branch_shift_closings.php'=>'CreateBranchShiftClosings','2026_10_04_210000_create_branch_inventory_recipes.php'=>'CreateBranchInventoryRecipes','2026_10_04_000001_create_pos_branch_print_jobs.php'=>'CreatePosBranchPrintJobs'] as $file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[21,'user',null],[22,'user',null],[30,'admin',null]] as [$id,$type,$parent])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'owner_resturant_id'=>$parent,'app_scope'=>$id===22?'go_customer':'fasakhansta','created_at'=>'2026-10-05 01:00:00']);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main','parent_id'=>null],['id'=>101,'user_id'=>11,'name'=>'Foreign','parent_id'=>null],['id'=>102,'user_id'=>11,'name'=>'Child','parent_id'=>100]]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100],['id'=>2,'resturant_id'=>101]]);
        DB::table('settings')->insert(['name'=>'service_fees','payload'=>'10']);
        DB::table('pending_vendors')->insert(['status'=>'pending']);
    }
    protected function tearDown(): void {Carbon::setTestNow();if($this->connection==='mysql'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();foreach(['branch_recipe_sales','branch_stock_recipes','branch_inventory_movements','branch_inventory','stock_ingredients','branch_shift_sources','branch_shift_closings','branch_expense_commands','branch_expenses','pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_stores','order_board_clocks','carts','orders','wallets','settings','pending_vendors','resturant_products','resturants','users'] as $t)Schema::dropIfExists($t);Schema::enableForeignKeyConstraints();
    }
    private function actor(int $id=1): User {return User::withoutGlobalScopes()->findOrFail($id);}
    private function overview(array $filters=[],int $actor=1): array {return app(HomeOverview::class)->data($filters,$this->actor($actor));}
    private function denied(callable $fn,int $code): void {try{$fn();$this->fail('Expected denial');}catch(HttpException $e){$this->assertSame($code,$e->getStatusCode());}}
    private function sale(int $id,string $branch='f:100',string $channel='takeaway',int $gross=10000,int $delivery=0,string $day='2026-10-05'): void
    {
        DB::table('takeaway_tills')->insertOrIgnore(['branch'=>$branch,'balance_cents'=>0,'tax_bps'=>0,'revision'=>1]);$till=DB::table('takeaway_tills')->where('branch',$branch)->first();
        DB::table('takeaway_orders')->insert(['id'=>$id,'branch'=>$branch,'till_id'=>$till->id,'actor_id'=>10,'request_key'=>sprintf('00000000-0000-4000-8000-%012d',$id),'request_hash'=>str_repeat('a',64),'quote_hash'=>str_repeat('b',64),'business_date'=>$day,'payment_method'=>'card','subtotal_cents'=>$gross-$delivery,'discount_cents'=>0,'tax_cents'=>0,'total_cents'=>$gross,'cash_received_cents'=>0,'change_cents'=>0,'tax_bps'=>0,'branch_snapshot'=>'{}','cashier_snapshot'=>'{}','channel'=>$channel,'delivery_cents'=>$delivery,'created_at'=>$day.' 05:00:00','updated_at'=>$day.' 05:00:00']);
    }
    private function order(int $id,array $values=[]): void {DB::table('orders')->insert($values+['id'=>$id,'resturant_id'=>100,'user_id'=>20,'status'=>'completed','type'=>'current','total_price'=>'100','delivery_price'=>'20','user_tax'=>'5','created_at'=>'2026-10-05 01:00:00','updated_at'=>'2026-10-05 02:00:00']);}
    private function expense(int $id,array $values=[]): void {DB::table('branch_expenses')->insert($values+['id'=>$id,'branch'=>'f:100','actor_id'=>10,'occurred_on'=>'2026-10-05','category'=>'purchases','description'=>'Goods','amount_cents'=>3000,'payment_method'=>'card','status'=>'approved']);}
    public function test_combines_completed_channels_and_approved_expenses_without_counting_drafts_twice(): void
    {
        $this->sale(1,'f:100','dine');$this->sale(2,'f:100','phone',25000,5000);$this->sale(3,'f:101','takeaway',999999);$this->sale(4,'f:100','takeaway',9000,0,'2026-10-04');
        $this->order(1);DB::table('carts')->insert([['order_id'=>1,'price'=>'100','qty'=>'1.500','updated_total'=>null],['order_id'=>1,'price'=>'100','qty'=>'1','updated_total'=>'40']]);
        $this->order(2,['status'=>'pending']);$this->order(3,['status'=>'cancelled']);$this->order(4,['type'=>'wallet']);$this->order(5,['resturant_id'=>101]);
        $this->expense(1);$this->expense(2,['status'=>'pending','amount_cents'=>999999]);$this->expense(3,['status'=>'voided']);$this->expense(4,['branch'=>'f:101']);
        $r=$this->overview(['branch'=>'f:100']);$this->assertSame(58400,$r['sales']['gross_cents']);$this->assertSame(7000,$r['sales']['delivery_cents']);$this->assertSame(3000,$r['sales']['expenses_cents']);$this->assertSame(48400,$r['sales']['net_cents']);$this->assertSame(3,$r['completed']);$this->assertSame(1,$r['sales']['channels']['app']['count']);$this->assertSame(9000,$r['previous']['gross_cents']);$this->assertSame(58400,array_sum(array_column($r['trend'],'amount_cents')));$this->assertSame(1,$r['active']['new']);$this->assertSame(1,$r['cancelled']);$this->assertSame(3,$r['app_orders']);$this->assertCount(1,$r['branch_cards']);
    }
    public function test_completion_clocks_and_cairo_boundaries_are_used_instead_of_last_update_or_creation(): void
    {
        $this->order(1,['created_at'=>'2026-10-04 22:00:00','updated_at'=>'2026-10-06 01:00:00']);
        DB::table('order_board_clocks')->insert(['source'=>'legacy','order_id'=>1,'accepted_at'=>'2026-10-04 20:00:00','closed_at'=>'2026-10-04 21:00:00']);
        $this->order(2,['updated_at'=>'2026-10-05 10:00:00']);DB::table('order_board_clocks')->insert(['source'=>'legacy','order_id'=>2,'accepted_at'=>'2026-10-04 19:00:00','closed_at'=>'2026-10-04 20:59:59']);
        $this->order(3,['updated_at'=>'2026-10-06 10:00:00']);DB::table('branch_recipe_sales')->insert(['branch'=>'f:100','source_type'=>'app','source_id'=>'3','snapshot'=>'{}','created_at'=>'2026-10-04 21:30:00']);
        $this->order(4,['created_at'=>'2025-10-05 01:00:00','updated_at'=>'2025-10-05 02:00:00']);
        $r=$this->overview(['branch'=>'f:100']);$this->assertSame(2,$r['completed']);$this->assertSame(27000,$r['sales']['gross_cents']);$this->assertSame(13500,$r['previous']['gross_cents']);$this->assertSame(0,$r['legacy_app_dates']);$this->assertSame(27000,$r['trend'][0]['amount_cents']);
    }
    public function test_branch_scope_persisted_identity_and_server_side_financial_redaction(): void
    {
        $this->sale(1);$this->sale(2,'f:101','takeaway',87654321);$this->order(1);$this->order(2,['resturant_id'=>101,'user_id'=>21]);
        $r=$this->overview([],10);$this->assertCount(1,$r['branches']);$this->assertSame('f:100',$r['branches'][0]['value']);$this->assertFalse($r['can_view_financials']);$this->assertNull($r['sales']);$this->assertNull($r['previous']);$this->assertArrayNotHasKey('owner_drawer',$r);$this->assertArrayNotHasKey('sales',$r['branch_cards'][0]);$this->assertArrayNotHasKey('amount_cents',$r['trend'][0]);$this->assertSame(1,$r['customers']['total']);$this->assertNull($r['customers']['pending_partners']);$this->assertStringNotContainsString('87654321',json_encode($r));
        $this->denied(fn()=>$this->overview(['branch'=>'f:101'],10),404);$this->denied(fn()=>$this->overview([],30),403);$this->denied(fn()=>$this->overview([],20),403);
        $spoof=$this->actor(10);$spoof->account_type='admin';$this->assertFalse(app(HomeOverview::class)->data([],$spoof)['can_view_financials']);
        $owned=$this->overview([],12);$this->assertSame(['f:100','f:102'],array_column($owned['branches'],'value'));$this->assertTrue($owned['can_view_financials']);$this->assertArrayNotHasKey('owner_drawer',$owned);
    }
    public function test_only_primary_owner_sees_live_cash_using_the_existing_shift_formula(): void
    {
        $this->sale(1);DB::table('takeaway_tills')->where('branch','f:100')->update(['balance_cents'=>123450]);DB::table('takeaway_tills')->insert(['branch'=>'f:101','balance_cents'=>76500,'tax_bps'=>0,'revision'=>1]);
        $owner=$this->overview();$this->assertTrue($owner['owner_drawer']['ready']);$this->assertSame(199950,$owner['owner_drawer']['total_cents']);$this->assertSame(123450,$owner['owner_drawer']['branches']['f:100']['expected_cents']);
        $this->assertSame(123450,$this->overview(['branch'=>'f:100'])['owner_drawer']['total_cents']);
        foreach([4,10,12] as $id){$this->assertArrayNotHasKey('owner_drawer',$this->overview([],$id));$this->denied(fn()=>app(BranchShiftClosing::class)->ownerBalances(['f:100'],$this->actor($id)),403);}
        $spoof=$this->actor(10);$spoof->id=10;$spoof->account_type='admin';$this->denied(fn()=>app(BranchShiftClosing::class)->ownerBalances(['f:100'],$spoof),403);
    }
    public function test_owner_drawer_excludes_cash_delivery_and_includes_vendor_collected_app_cash(): void
    {
        $this->sale(1,'f:100','phone',25000,5000);
        DB::table('takeaway_orders')->where('id',1)->update(['payment_method'=>'cash']);
        $till=DB::table('takeaway_tills')->where('branch','f:100')->first();
        DB::table('takeaway_tills')->where('id',$till->id)->update(['balance_cents'=>148450]);
        DB::table('takeaway_till_entries')->insert(['till_id'=>$till->id,'branch'=>'f:100','actor_id'=>10,'order_id'=>1,'request_key'=>'00000000-0000-4000-8000-000000000001','request_hash'=>str_repeat('a',64),'kind'=>'sale','amount_cents'=>25000,'balance_cents'=>148450,'business_date'=>'2026-10-05','note'=>'Cash sale','created_at'=>'2026-10-05 05:00:00']);
        $this->order(1,['transfer_price_by'=>'vendor']);
        $r=$this->overview(['branch'=>'f:100']);
        $this->assertSame(154950,$r['owner_drawer']['total_cents']);
        $this->assertSame(148450,(int)DB::table('takeaway_tills')->value('balance_cents'));
    }
    public function test_stock_balances_keep_units_negative_branch_warnings_and_unknown_opening_balances(): void
    {
        DB::table('branch_inventory')->insert([['branch'=>'f:100','ingredient_id'=>1,'quantity_units'=>5000000],['branch'=>'f:101','ingredient_id'=>1,'quantity_units'=>-1000000],['branch'=>'f:100','ingredient_id'=>20,'quantity_units'=>3000000],['branch'=>'f:100','ingredient_id'=>22,'quantity_units'=>0]]);
        $r=$this->overview();$this->assertCount(23,$r['inventory']['items']);$i=$r['inventory']['items'][0];$this->assertSame('4',$i['quantity']);$this->assertSame('kg',$i['unit']);$this->assertSame(1,$i['negative_branches']);$this->assertSame(2,$i['tracked_branches']);$this->assertSame(0,$r['inventory']['items'][1]['tracked_branches']);$this->assertSame('piece',$r['inventory']['items'][19]['unit']);$this->assertSame(2,$r['inventory']['unconfigured_recipes']);
        $this->assertSame('negative_stock',$r['alerts'][0]['kind']);$this->assertSame('Foreign',$r['alerts'][0]['branch']);
        $this->assertSame($r['inventory'],$this->overview(['period'=>'week'])['inventory']);$this->assertSame('5',$this->overview([],10)['inventory']['items'][0]['quantity']);
    }
    public function test_live_stages_are_independent_of_the_sales_period_and_platform_customers_are_scoped(): void
    {
        $this->order(1,['status'=>'pending','accepted_notify'=>'yes','created_at'=>'2026-09-01 10:00:00']);$this->order(2,['status'=>'accepted','delegate_from_out'=>'in_resturant']);$this->order(3,['status'=>'shipped']);$this->order(4,['status'=>'pending']);
        $r=$this->overview();$this->assertSame(['new'=>1,'preparing'=>1,'courier'=>2,'awaiting_payment'=>0],$r['active']);$this->assertSame(2,$r['customers']['total']);$this->assertSame(2,$r['customers']['period']);$this->assertTrue($r['customers']['global']);$this->assertSame(0,$r['completed']);$this->assertContains('late_orders',array_column($r['alerts'],'kind'));
        $r=$this->overview(['branch'=>'f:100']);$this->assertFalse($r['customers']['global']);$this->assertSame(1,$r['customers']['total']);
    }
    public function test_filters_reject_future_reversed_and_excessive_ranges(): void
    {
        foreach([['period'=>'custom','from'=>'2026-10-05','to'=>'2026-10-06'],['period'=>'custom','from'=>'2024-01-01','to'=>'2026-10-05']] as $v)$this->denied(fn()=>$this->overview($v),422);
        try{$this->overview(['period'=>'custom','from'=>'2026-10-05','to'=>'2026-10-04']);$this->fail();}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        $r=$this->overview(['period'=>'custom','from'=>'2026-07-01','to'=>'2026-10-05']);$this->assertCount(4,$r['trend']);$this->assertSame('2026-07-01',$r['filters']['from']);
    }
    public function test_dashboard_endpoint_is_private_and_branch_filters_cannot_bypass_authorization(): void
    {
        $this->actingAs($this->actor(10),'admin');$this->getJson('/admin/dashboard/overview?branch=f:100')->assertOk()->assertJsonPath('can_view_financials',false)->assertHeader('Cache-Control','no-store, private');
        $this->getJson('/admin/dashboard/overview?branch=f:101')->assertNotFound();
        $this->actingAs($this->actor(20),'admin');$this->getJson('/admin/dashboard/overview')->assertForbidden();
    }
}
