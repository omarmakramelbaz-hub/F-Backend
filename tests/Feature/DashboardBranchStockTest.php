<?php
namespace Tests\Feature;

use App\Models\User;
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

class DashboardBranchStockTest extends TestCase
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
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->decimal('lat',10,7)->default(30);$t->decimal('lng',10,7)->default(31);$t->decimal('km_price',10,2)->default(20);$t->timestamps();});
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->text('price');$t->string('status');$t->timestamps();});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->decimal('amount',14,2);});
        Schema::create('orders',function(Blueprint $t){$t->id();$t->string('status');});
        Schema::create('order_board_clocks',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');});
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php');(new \CreateGoStoreCatalog)->up();
        require_once database_path('migrations/2026_10_03_140000_create_takeaway_pos.php');(new \CreateTakeawayPos)->up();
        require_once database_path('migrations/2026_10_03_150000_create_pos_service_tickets.php');(new \CreatePosServiceTickets)->up();
        require_once database_path('migrations/2026_10_04_060000_lock_pos_service_bills.php');(new \LockPosServiceBills)->up();
        require_once database_path('migrations/2026_10_04_000001_create_pos_branch_print_jobs.php');(new \CreatePosBranchPrintJobs)->up();
        require_once database_path('migrations/2026_10_04_080000_create_branch_operations.php');(new \CreateBranchOperations)->up();
        require_once database_path('migrations/2026_10_04_190000_create_branch_stock.php');(new \CreateBranchStock)->up();
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'app_scope'=>$id===30?'go_partner':'fasakhansta','status'=>'accepted','owner_resturant_id'=>$owner]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Foreign']]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{}','status'=>'show'],['id'=>2,'resturant_id'=>101,'product_name'=>'Foreign','product_price'=>'500.00','price'=>'{}','status'=>'show']]);
        DB::table('go_stores')->insert(['user_id'=>30,'name'=>'GO store','kind'=>'grocery','address'=>'Address','created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_store_products')->insert(['id'=>70,'user_id'=>30,'request_key'=>$this->key(70),'name'=>'Rice','unit'=>'كيلو','price_cents'=>10000,'image_path'=>'rice.jpg','available'=>true,'options'=>'[]','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['amount'=>'100.00']);DB::table('orders')->insert(['status'=>'accepted']);DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }
    protected function tearDown(): void{Carbon::setTestNow();if($this->connection==='mysql'&&config('database.connections.mysql.database')==='takeaway_test'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void{foreach(['branch_stock_movements','branch_stock','branch_payrolls','branch_employee_entries','branch_employee_days','branch_employee_salaries','branch_employees','branch_delivery_companies','branch_customers','branch_operation_commands','pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_store_products','go_stores','user_address','order_board_clocks','carts','orders','wallets','settings','pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table)Schema::dropIfExists($table);}
    private function actor(int $id=10): User{return User::withoutGlobalScopes()->findOrFail($id);}
    private function tickets(): PosServiceTicket{return app(PosServiceTicket::class);}
    private function key(int $n): string{return sprintf('00000000-0000-4000-8000-%012d',$n);}
    private function table(string $branch='f:100',int $key=1): array{return app(PosServiceTable::class)->configure(['branch'=>$branch,'name'=>'Table '.$key,'capacity'=>4,'active'=>true,'idempotency_key'=>$this->key($key)],$this->actor(12))['table'];}
    private function cart(string $branch='f:100',int $product=1): array{return ['branch'=>$branch,'items'=>[['product_id'=>$product,'quantity'=>'1.000','quantity_mode'=>'piece']],'discount'=>'0.00','discount_reason'=>''];}
    private function savePayload(string $channel='dine',int $key=2): array
    {
        $cart=$this->cart();$v=$cart+['idempotency_key'=>$this->key($key),'notes'=>'Note'];
        if($channel==='dine')$v+=['table_id'=>$this->table()['id'],'waiter_name'=>'Waiter','guest_count'=>2];
        else $v+=['customer_name'=>'Customer','customer_phone'=>'010 1234 5678','address'=>'Street 1','area'=>'Area','delivery_notes'=>'Door 2','delivery_fee'=>'20.00'];
        if($channel==='phone'){$v+=['latitude'=>30+rad2deg(1/6371),'longitude'=>31,'location_confirmed'=>true];$v['delivery_quote_hash']=app(\App\Services\Dashboard\PhoneDelivery::class)->quote($v,$this->actor())['delivery']['delivery_quote_hash'];}
        $v['quote_hash']=$this->tickets()->quote($channel,$v,$this->actor())['quote_hash'];return $v;
    }
    private function saved(string $channel='dine'): array{return $this->tickets()->save($channel,$this->savePayload($channel),$this->actor())['ticket'];}
    private function settlePayload(array $ticket,int $key=10,string $method='cash'): array{return ['branch'=>$ticket['branch']['value'],'expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key($key),'quote_hash'=>$ticket['quote_hash'],'payment_method'=>$method,'cash_received'=>'500.00','payment_confirmed'=>true];}
    private function denied(callable $call,int $status=409): void{try{$call();$this->fail('Expected '.$status);}catch(HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    private function invalid(callable $call): void{try{$call();$this->fail('Expected invalid payload');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}

    private function stock(): \App\Services\Dashboard\BranchStock {return app(\App\Services\Dashboard\BranchStock::class);}
    private function supply(string $quantity='10.125',string $unit='kg',int $key=80): array{return ['branch'=>'f:100','product_id'=>1,'quantity'=>$quantity,'unit'=>$unit,'supplier'=>'Supplier','notes'=>'Receipt','idempotency_key'=>$this->key($key)];}
    private function balance(): string {return $this->stock()->balances('f:100',[1])[1]['quantity'];}
    public function test_stock_receipt_is_scoped_to_branch_menu_replay_safe_and_recovers_without_cash_movement(): void
    {
        $s=$this->stock();$v=$this->supply();$this->denied(fn()=>$s->receive($v,$this->actor(11)),404);
        $this->denied(fn()=>$s->receive(array_replace($v,['product_id'=>2]),$this->actor()),404);
        $first=$s->receive($v,$this->actor());$this->assertSame('10.125',$first['stock']['quantity']);
        $this->assertTrue($s->receive($v,$this->actor())['replayed']);$this->assertSame('10.125',$this->balance());
        $this->denied(fn()=>$s->receive(array_replace($v,['quantity'=>'1']),$this->actor()));
        $recovered=app(\App\Services\Dashboard\BranchOperations::class)->recover($v,$this->actor());$this->assertTrue($recovered['found']);$this->assertSame($first['receipt'],$recovered['receipt']);
        $this->assertFalse(app(\App\Services\Dashboard\BranchOperations::class)->recover($v,$this->actor(1))['found']);
        $this->assertSame(1,DB::table('branch_stock_movements')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());$this->assertSame(1,DB::table('wallets')->count());
        $this->assertSame(['f:100'],array_column($s->branches($this->actor(4)),'value'));$this->assertCount(2,$s->branches($this->actor(1)));$this->assertSame([],$s->branches($this->actor(30)));
        $this->denied(fn()=>$s->listing(['branch'=>'f:100'],$this->actor(11)),404);
        $this->assertSame('10.125',$s->listing(['branch'=>'f:100'],$this->actor(4))['items'][0]['stock']['quantity']);
    }
    public function test_stock_units_are_fixed_kg_precision_and_whole_pieces_are_validated(): void
    {
        $s=$this->stock();foreach(['0','-1','1.0001','1e2','1000001'] as $bad)$this->denied(fn()=>$s->receive($this->supply($bad),$this->actor()),422);
        $this->denied(fn()=>$s->receive($this->supply('1.5','piece'),$this->actor()),422);
        $s->receive($this->supply('2','piece'),$this->actor(1));$this->assertSame('2',$this->balance());
        $this->denied(fn()=>$s->receive($this->supply('1','kg',81),$this->actor()),422);$this->assertSame('2',$this->balance());
        $cart=$this->cart();$cart['items'][0]['quantity']='0.500';$cart['items'][0]['quantity_mode']='weight';
        $this->denied(fn()=>app(TakeawayService::class)->quote($cart,$this->actor()),422);
        $this->assertSame(1,DB::table('branch_stock_movements')->count());
    }
    public function test_paid_takeaway_deducts_exactly_once_and_updates_both_menu_apis(): void
    {
        $this->stock()->receive($this->supply(),$this->actor());$cart=$this->cart();$cart['items'][0]['quantity']='1.125';$cart['items'][0]['quantity_mode']='weight';
        $s=app(TakeawayService::class);$quote=$s->quote($cart,$this->actor());$v=$cart+['quote_hash'=>$quote['quote_hash'],'idempotency_key'=>$this->key(90),'payment_method'=>'cash','cash_received'=>'200.00'];
        $this->assertSame('10.125',$this->balance());$first=$s->checkout($v,$this->actor());$this->assertSame('9',$this->balance());$this->assertTrue($s->checkout($v,$this->actor())['replayed']);$this->assertSame('9',$this->balance());
        $menu=app(\App\Services\Dashboard\TakeawayCatalog::class)->listing(['branch'=>'f:100'],$this->actor());$this->assertSame('9',$menu['items'][0]['stock']['quantity']);$this->assertSame('weight',$menu['items'][0]['quantity_mode']);
        $request=\Illuminate\Http\Request::create('/admin/applies-orders/menu','GET',['branch'=>'f:100']);$menu=app(\App\Services\Dashboard\OrderBoardMenu::class)->listing($request,$this->actor());$this->assertSame('9',$menu['items'][0]['stock']['quantity']);
        $this->assertSame(2,DB::table('branch_stock_movements')->count());$this->assertSame(1,DB::table('takeaway_orders')->count());
    }
    public function test_dining_and_phone_only_deduct_on_final_settlement_never_kitchen_or_bill_print(): void
    {
        $this->stock()->receive($this->supply('4','piece'),$this->actor());
        foreach(['dine','phone'] as $i=>$channel){
            $v=$this->savePayload($channel,100+$i*10);$ticket=$this->tickets()->save($channel,$v,$this->actor())['ticket'];
            $this->assertSame((string)(4-$i),$this->balance());
            $sent=$this->tickets()->action($channel,$ticket['id'],['branch'=>'f:100','expected_revision'=>$ticket['revision'],'action'=>'send_kitchen','idempotency_key'=>$this->key(101+$i*10)],$this->actor())['ticket'];
            $bill=$this->tickets()->action($channel,$ticket['id'],['branch'=>'f:100','expected_revision'=>$sent['revision'],'action'=>'request_bill','idempotency_key'=>$this->key(102+$i*10)],$this->actor())['ticket'];
            $this->assertSame((string)(4-$i),$this->balance());$pay=$this->settlePayload($bill,103+$i*10);
            $this->tickets()->settle($channel,$ticket['id'],$pay,$this->actor());$this->assertSame((string)(3-$i),$this->balance());
            $this->assertTrue($this->tickets()->settle($channel,$ticket['id'],$pay,$this->actor())['replayed']);$this->assertSame((string)(3-$i),$this->balance());
        }
        $this->assertSame(3,DB::table('branch_stock_movements')->count());
    }
    public function test_portion_weights_aggregate_without_rounding_and_negative_balance_is_visible(): void
    {
        $this->stock()->receive($this->supply('0.001'),$this->actor());
        Schema::table('resturant_products',function(Blueprint $t){$t->unsignedBigInteger('product_id')->nullable();});
        Schema::create('product_features',function(Blueprint $t){$t->id();$t->unsignedBigInteger('product_id');$t->string('name');});
        DB::table('resturant_products')->where('id',1)->update(['product_id'=>99]);DB::table('product_features')->insert([['id'=>1,'product_id'=>99,'name'=>'half'],['id'=>2,'product_id'=>99,'name'=>'quarter']]);
        $cart=$this->cart();$cart['items']=[['product_id'=>1,'quantity'=>'0.003','quantity_mode'=>'weight','option_id'=>'f:1:base'],['product_id'=>1,'quantity'=>'0.001','quantity_mode'=>'weight','option_id'=>'f:2:base']];
        $s=app(TakeawayService::class);$q=$s->quote($cart,$this->actor());$s->checkout($cart+['quote_hash'=>$q['quote_hash'],'idempotency_key'=>$this->key(90),'payment_method'=>'cash','cash_received'=>'1.00'],$this->actor());
        $this->assertSame('-0.00075',$this->balance());$this->assertTrue($this->stock()->balances('f:100',[1])[1]['negative']);$this->assertSame(2,DB::table('branch_stock_movements')->count());
    }
    public function test_tracking_begins_at_first_supply_and_failed_sale_rolls_back_stock(): void
    {
        $s=app(TakeawayService::class);$cart=$this->cart();$q=$s->quote($cart,$this->actor());$v=$cart+['quote_hash'=>$q['quote_hash'],'idempotency_key'=>$this->key(90),'payment_method'=>'cash','cash_received'=>'100.00'];$s->checkout($v,$this->actor());
        $this->assertSame(0,DB::table('branch_stock_movements')->count());$this->stock()->receive($this->supply('1','piece'),$this->actor());$s->checkout($v,$this->actor());$this->assertSame('1',$this->balance());
        DB::table('takeaway_tills')->where('branch','f:100')->update(['balance_cents'=>100000000000]);$v['idempotency_key']=$this->key(91);
        $this->denied(fn()=>$s->checkout($v,$this->actor()),422);$this->assertSame('1',$this->balance());$this->assertSame(1,DB::table('branch_stock_movements')->count());$this->assertSame(1,DB::table('takeaway_orders')->count());
    }
}
