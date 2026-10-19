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

class DashboardPosServiceTest extends TestCase
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
        require_once database_path('migrations/2026_10_06_130000_create_phone_delivery_batches.php');(new \CreatePhoneDeliveryBatches)->up();
        require_once database_path('migrations/2026_10_06_140000_add_employee_wallet_phone.php');(new \AddEmployeeWalletPhone)->up();
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'app_scope'=>$id===30?'go_partner':'fasakhansta','status'=>'accepted','owner_resturant_id'=>$owner]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Foreign']]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{}','status'=>'show'],['id'=>2,'resturant_id'=>101,'product_name'=>'Foreign','product_price'=>'500.00','price'=>'{}','status'=>'show']]);
        DB::table('go_stores')->insert(['user_id'=>30,'name'=>'GO store','kind'=>'grocery','address'=>'Address','created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_store_products')->insert(['id'=>70,'user_id'=>30,'request_key'=>$this->key(70),'name'=>'Rice','unit'=>'كيلو','price_cents'=>10000,'image_path'=>'rice.jpg','available'=>true,'options'=>'[]','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['amount'=>'100.00']);DB::table('orders')->insert(['status'=>'accepted']);DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }
    protected function tearDown(): void{Carbon::setTestNow();if($this->connection==='mysql'&&config('database.connections.mysql.database')==='takeaway_test'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void{foreach(['phone_delivery_batch_items','phone_delivery_batches','phone_delivery_dispatches','branch_payrolls','branch_employee_entries','branch_employee_days','branch_employee_salaries','branch_employees','branch_delivery_companies','branch_customers','branch_operation_commands','pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_store_products','go_stores','user_address','order_board_clocks','carts','orders','wallets','settings','pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table)Schema::dropIfExists($table);}
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

    public function test_manual_phone_delivery_fee_is_quoted_saved_updated_and_settled_with_receipts(): void
    {
        $v=$this->savePayload('phone',1800);$v['delivery_fee_manual']=true;$v['delivery_fee']='37.25';
        // A fee change must invalidate the previous payable quote.
        $this->denied(fn()=>$this->tickets()->save('phone',$v,$this->actor()),409);
        $q=$this->tickets()->quote('phone',$v,$this->actor());$v['quote_hash']=$q['quote_hash'];
        $this->assertSame('137.25',$q['total']);
        $result=$this->tickets()->save('phone',$v,$this->actor());$ticket=$result['ticket'];
        $this->assertSame('37.25',$ticket['delivery_fee']);$this->assertSame('manual',$ticket['delivery_location']['fee_mode']);
        $this->assertSame('20.00',$ticket['delivery_location']['calculated_delivery_fee']);$this->assertSame(10,$ticket['delivery_location']['fee_actor_id']);
        $this->assertTrue($this->tickets()->save('phone',$v,$this->actor())['replayed']);
        $v=array_replace($v,['ticket_id'=>$ticket['id'],'expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key(1801),'delivery_fee'=>'0.00']);
        $v['quote_hash']=$this->tickets()->quote('phone',$v,$this->actor())['quote_hash'];
        $ticket=$this->tickets()->save('phone',$v,$this->actor())['ticket'];$this->assertSame('100.00',$ticket['total']);$this->assertSame('0.00',$ticket['delivery_fee']);
        $v=array_replace($v,['expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key(1802),'delivery_fee'=>'12.50']);
        $v['quote_hash']=$this->tickets()->quote('phone',$v,$this->actor())['quote_hash'];
        $ticket=$this->tickets()->save('phone',$v,$this->actor())['ticket'];
        $paid=$this->tickets()->settle('phone',$ticket['id'],$this->settlePayload($ticket,1803),$this->actor());
        $this->assertSame(1250,(int)DB::table('takeaway_orders')->value('delivery_cents'));
        $this->assertSame(11250,(int)DB::table('takeaway_orders')->value('total_cents'));
        $this->assertSame('12.50',$this->tickets()->show('phone',$ticket['id'],$this->actor())['ticket']['delivery_fee']);
        $this->assertSame(1,DB::table('takeaway_orders')->count());
    }
    public function test_manual_delivery_fee_keeps_location_scope_and_amount_validation(): void
    {
        $v=$this->savePayload('phone',1810);$v['delivery_fee_manual']=true;$v['delivery_fee']='5.00';
        $this->denied(fn()=>$this->tickets()->quote('phone',$v,$this->actor(11)),404);
        $this->denied(fn()=>$this->tickets()->quote('phone',array_replace($v,['delivery_quote_hash'=>str_repeat('f',64)]),$this->actor()),409);
        foreach(['-1.00','1000000.01'] as $bad)$this->denied(fn()=>$this->tickets()->quote('phone',array_replace($v,['delivery_fee'=>$bad]),$this->actor()),422);
        foreach(['1.234','abc',''] as $bad)$this->invalid(fn()=>$this->tickets()->quote('phone',array_replace($v,['delivery_fee'=>$bad]),$this->actor()));
        $q=$this->tickets()->quote('phone',array_replace($v,['delivery_fee_manual'=>false]),$this->actor());$this->assertSame('120.00',$q['total']);
        $this->assertSame(0,DB::table('pos_service_tickets')->count());
    }

    public function test_branch_staff_can_configure_real_tables_only_in_their_own_branch(): void
    {
        $this->assertCount(0,app(PosServiceTable::class)->listing('f:100',$this->actor())['tables']);
        $v=['branch'=>'f:100','name'=>'Window','capacity'=>4,'active'=>true,'idempotency_key'=>$this->key(1)];
        $this->denied(fn()=>app(PosServiceTable::class)->configure($v,$this->actor(11)),404);
        $table=app(PosServiceTable::class)->configure($v,$this->actor(12));$this->assertSame('Window',$table['table']['name']);
        $this->assertTrue(app(PosServiceTable::class)->configure($v,$this->actor(12))['replayed']);
        $v['name']='Other';$this->denied(fn()=>app(PosServiceTable::class)->configure($v,$this->actor(12)));
        $this->assertSame(1,DB::table('pos_service_tables')->count());
    }
    public function test_unpaid_dine_ticket_persists_real_server_quote_with_no_financial_side_effect(): void
    {
        $ticket=$this->saved();$this->assertSame('open',$ticket['status']);$this->assertSame('unpaid',$ticket['payment_status']);
        $this->assertSame('100.00',$ticket['total']);$this->assertSame('Waiter',$ticket['waiter_name']);$this->assertSame(2,$ticket['guest_count']);
        $this->assertSame('in_service',app(PosServiceTable::class)->listing('f:100',$this->actor())['tables'][0]['status']);
        $this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $this->assertSame(1,DB::table('wallets')->count());$this->assertSame(1,DB::table('orders')->count());$this->assertSame(1,DB::table('order_board_clocks')->count());
    }
    public function test_one_active_ticket_per_table_and_lost_first_save_replays_before_live_menu_checks(): void
    {
        $v=$this->savePayload();$first=$this->tickets()->save('dine',$v,$this->actor());DB::table('resturant_products')->where('id',1)->delete();
        $retry=$this->tickets()->save('dine',$v,$this->actor());$this->assertTrue($retry['replayed']);$this->assertSame($first['ticket']['id'],$retry['ticket']['id']);
        $this->assertSame($v['idempotency_key'],$retry['operation']['idempotency_key']);
        $v['idempotency_key']=$this->key(3);$this->denied(fn()=>$this->tickets()->save('dine',$v,$this->actor()));$this->assertSame(1,DB::table('pos_service_tickets')->count());
    }
    public function test_ticket_edits_require_revision_and_new_confirmed_live_price_quote(): void
    {
        $v=$this->savePayload();$ticket=$this->tickets()->save('dine',$v,$this->actor())['ticket'];
        DB::table('resturant_products')->where('id',1)->update(['product_price'=>'150.00']);
        $v+=['ticket_id'=>$ticket['id'],'expected_revision'=>$ticket['revision']];$v['idempotency_key']=$this->key(3);
        $v['reprice']=true;
        $this->denied(fn()=>$this->tickets()->save('dine',$v,$this->actor()));
        $v['quote_hash']=$this->tickets()->quote('dine',$v,$this->actor())['quote_hash'];$edited=$this->tickets()->save('dine',$v,$this->actor())['ticket'];
        $this->assertSame('150.00',$edited['total']);$this->assertSame(2,$edited['revision']);
        $v['idempotency_key']=$this->key(4);$this->denied(fn()=>$this->tickets()->save('dine',$v,$this->actor()));
    }
    public function test_kitchen_snapshot_is_immutable_and_request_bill_preserves_unpaid_state(): void
    {
        $ticket=$this->saved();$v=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$this->key(3),'action'=>'send_kitchen'];
        $sent=$this->tickets()->action('dine',$ticket['id'],$v,$this->actor());$this->assertSame('preparing',$sent['ticket']['status']);$this->assertNotEmpty($sent['kitchen_print_url']);
        $kitchen=$this->tickets()->kitchen('dine',1,$this->actor());$this->assertSame('100.00',$kitchen['ticket']['total']);
        $this->assertTrue($this->tickets()->action('dine',$ticket['id'],$v,$this->actor())['replayed']);$this->assertSame(1,DB::table('pos_service_kitchen_tickets')->count());
        $v=['branch'=>'f:100','expected_revision'=>2,'idempotency_key'=>$this->key(4),'action'=>'request_bill'];$bill=$this->tickets()->action('dine',$ticket['id'],$v,$this->actor());
        $this->assertSame('awaiting_bill',$bill['ticket']['status']);$this->assertSame('unpaid',$bill['ticket']['payment_status']);
        $this->assertSame('awaiting_bill',app(PosServiceTable::class)->listing('f:100',$this->actor())['tables'][0]['status']);
        $this->assertSame($kitchen,$this->tickets()->kitchen('dine',1,$this->actor()));
    }
    public function test_settlement_uses_saved_bill_after_menu_deleted_and_policy_changed(): void
    {
        app(PosServiceTable::class)->configure(['branch'=>'f:100','service_rate'=>'10.00','note'=>'Service','expected_revision'=>1,'idempotency_key'=>$this->key(20)],$this->actor(12),true);
        app(TakeawayService::class)->changeRegister(['branch'=>'f:100','tax_rate'=>'14.00','note'=>'Tax','expected_revision'=>1,'idempotency_key'=>$this->key(21)],$this->actor(12),true);
        $ticket=$this->saved();$this->assertSame('125.40',$ticket['total']);
        DB::table('resturant_products')->where('id',1)->delete();DB::table('takeaway_tills')->where('branch','f:100')->update(['tax_bps'=>9900]);
        DB::table('pos_service_settings')->where('branch','f:100')->update(['service_bps'=>9900]);
        $payment=$this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($ticket),$this->actor());
        $this->assertSame('125.40',$payment['receipt']['total']);$this->assertSame('10.00',$payment['receipt']['service']);$this->assertSame('14.00',$payment['receipt']['tax_rate']);$this->assertSame('15.40',$payment['receipt']['tax']);
        $this->assertSame('free',app(PosServiceTable::class)->listing('f:100',$this->actor())['tables'][0]['status']);
        $this->assertSame('paid',$payment['ticket']['payment_status']);$this->assertSame($ticket['id'],$payment['receipt']['ticket_id']);
    }
    public function test_settlement_retry_after_table_and_policy_edits_is_exactly_once(): void
    {
        $ticket=$this->saved();$v=$this->settlePayload($ticket);$first=$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor());
        DB::table('pos_service_tables')->where('id',$ticket['table']['id'])->update(['name'=>'Renamed']);DB::table('pos_service_settings')->insertOrIgnore(['branch'=>'f:100','service_bps'=>9000,'revision'=>2]);
        $second=$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor());$this->assertTrue($second['replayed']);$this->assertSame($first['receipt'],$second['receipt']);
        $this->assertSame(1,DB::table('takeaway_orders')->count());$this->assertSame(1,DB::table('takeaway_till_entries')->count());
        $v['idempotency_key']=$this->key(11);$this->denied(fn()=>$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor()));
    }
    public function test_dining_and_phone_reject_all_non_cash_methods_and_keep_tickets_unpaid(): void
    {
        foreach(['dine','phone'] as $channel){
            $ticket=$this->tickets()->save($channel,$this->savePayload($channel,$channel==='dine'?1900:1910),$this->actor())['ticket'];
            foreach(['card','mobile_wallet','wallet','other','mixed'] as $i=>$method){
                $v=$this->settlePayload($ticket,1920+$i,$method);
                if($method==='mixed')$v['tenders']=[['method'=>'cash','amount'=>'30.00'],['method'=>'card','amount'=>'70.00']];
                $this->denied(fn()=>$this->tickets()->settle($channel,$ticket['id'],$v,$this->actor()),422);
            }
            $this->assertSame('unpaid',$this->tickets()->show($channel,$ticket['id'],$this->actor())['ticket']['payment_status']);
        }
        $this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }

    public function test_mixed_underpayment_duplicate_methods_and_unconfirmed_external_parts_rollback(): void
    {
        $ticket=$this->saved();$v=$this->settlePayload($ticket,10,'mixed');$v['tenders']=[['method'=>'cash','amount'=>'30.00'],['method'=>'card','amount'=>'69.99']];
        $this->denied(fn()=>$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor()),422);
        $v['tenders'][1]=['method'=>'cash','amount'=>'70.00'];$this->denied(fn()=>$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor()),422);
        $v['tenders'][1]=['method'=>'card','amount'=>'70.00','payment_confirmed'=>false];$this->denied(fn()=>$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor()),422);
        $this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame('unpaid',$this->tickets()->show('dine',$ticket['id'],$this->actor())['ticket']['payment_status']);
    }
    public function test_phone_stages_do_not_collect_cod_until_explicit_confirmed_settlement(): void
    {
        $ticket=$this->saved('phone');$this->assertSame('120.00',$ticket['total']);
        foreach(['prepare','dispatch','finish'] as $i=>$action){$r=$this->tickets()->action('phone',$ticket['id'],['branch'=>'f:100','expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key(3+$i),'action'=>$action],$this->actor());$ticket=$r['ticket'];}
        $this->assertSame('finished',$ticket['status']);$this->assertSame('unpaid',$ticket['payment_status']);$this->assertSame(0,DB::table('takeaway_orders')->count());
        $v=$this->settlePayload($ticket);$v['payment_confirmed']=false;$this->denied(fn()=>$this->tickets()->settle('phone',$ticket['id'],$v,$this->actor()),422);
        $v['payment_confirmed']=true;$paid=$this->tickets()->settle('phone',$ticket['id'],$v,$this->actor());$this->assertSame('120.00',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
        $this->assertSame('20.00',$paid['receipt']['delivery']);$this->assertSame('Street 1',$paid['receipt']['context']['address']);$this->assertSame('Door 2',$paid['receipt']['context']['delivery_notes']);
    }
    public function test_phone_vat_includes_distance_delivery_fee_without_app_rate_or_wallet_changes(): void
    {
        app(TakeawayService::class)->changeRegister(['branch'=>'f:100','tax_rate'=>'14.00','note'=>'Tax','expected_revision'=>1,'idempotency_key'=>$this->key(21)],$this->actor(12),true);
        $ticket=$this->saved('phone');$this->assertSame('16.80',$ticket['tax']);$this->assertSame('136.80',$ticket['total']);
        $r=$this->tickets()->settle('phone',$ticket['id'],$this->settlePayload($ticket,10,'cash'),$this->actor());$this->assertSame('136.80',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
        $this->assertSame(1,DB::table('wallets')->count());$this->assertSame(500.0,(float)DB::table('users')->where('id',10)->value('balance'));
    }
    public function test_phone_lookup_exact_normalized_number_is_scoped_and_snapshots_no_app_user(): void
    {
        $ticket=$this->saved('phone');$matches=app(PosServicePhone::class)->customers(['branch'=>'f:100','phone'=>'010-1234-5678'],$this->actor());
        $this->assertCount(1,$matches['matches']);$this->assertSame('Street 1',$matches['matches'][0]['address']);
        $this->assertCount(0,app(PosServicePhone::class)->customers(['branch'=>'f:101','phone'=>'01012345678'],$this->actor(11))['matches']);
        $this->denied(fn()=>app(PosServicePhone::class)->customers(['branch'=>'f:101','phone'=>'01012345678'],$this->actor()),404);
        $this->assertSame(7,DB::table('users')->count());
    }
    public function test_cancellation_releases_table_without_receipt_and_cannot_be_settled(): void
    {
        $ticket=$this->saved();$v=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$this->key(3),'action'=>'cancel'];
        $this->invalid(fn()=>$this->tickets()->action('dine',$ticket['id'],$v,$this->actor()));$v['reason']='Customer left';
        $r=$this->tickets()->action('dine',$ticket['id'],$v,$this->actor());$this->assertSame('cancelled',$r['ticket']['status']);
        $this->assertSame('free',app(PosServiceTable::class)->listing('f:100',$this->actor())['tables'][0]['status']);
        $this->denied(fn()=>$this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($r['ticket']),$this->actor()));$this->assertSame(0,DB::table('takeaway_orders')->count());
    }
    public function test_recovery_and_all_read_paths_recheck_scope_after_actor_revocation(): void
    {
        $v=$this->savePayload();$ticket=$this->tickets()->save('dine',$v,$this->actor())['ticket'];
        Carbon::setTestNow(now()->addDays(2));$found=$this->tickets()->recover('dine',['branch'=>'f:100','idempotency_key'=>$v['idempotency_key']],$this->actor());$this->assertTrue($found['found']);
        $this->assertSame($ticket['id'],$found['operation']['ticket_id']);$this->assertFalse($this->tickets()->recover('dine',['branch'=>'f:100','idempotency_key'=>$v['idempotency_key']],$this->actor(12))['found']);
        $this->denied(fn()=>$this->tickets()->show('dine',$ticket['id'],$this->actor(11)),404);
        $actor=$this->actor();DB::table('resturants')->where('id',100)->update(['user_id'=>11]);$this->denied(fn()=>$this->tickets()->recover('dine',['branch'=>'f:100','idempotency_key'=>$v['idempotency_key']],$actor),404);
    }
    public function test_actual_print_endpoints_render_unpaid_kitchen_and_paid_cash_context_safely(): void
    {
        $ticket=$this->saved();$sent=$this->tickets()->action('dine',$ticket['id'],['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$this->key(3),'action'=>'send_kitchen'],$this->actor());
        $this->actingAs($this->actor(),'admin');$sent=$this->tickets()->action('dine',$ticket['id'],['branch'=>'f:100','expected_revision'=>$sent['ticket']['revision'],'idempotency_key'=>$this->key(4),'action'=>'request_bill'],$this->actor())+['kitchen_print_url'=>$sent['kitchen_print_url']];$this->get($ticket['bill_print_url'].'?dashboard_print=1')->assertOk()->assertSee('data-dashboard-receipt=',false)->assertSee('Waiter');
        $this->get($sent['kitchen_print_url'].'?dashboard_print=1')->assertOk()->assertSee('data-dashboard-receipt=',false)->assertSee('Fish');
        $v=$this->settlePayload($sent['ticket'],10,'cash');
        $paid=$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor());$this->get($paid['receipt_url'].'?dashboard_print=1')->assertOk()->assertSee('Waiter')->assertSee('Table 1');
        $this->actingAs($this->actor(11),'admin')->get($sent['kitchen_print_url'],['Accept'=>'application/json'])->assertNotFound();
        $this->actingAs($this->actor(20),'admin')->getJson(route('dining.tickets',['branch'=>'f:100']))->assertForbidden();
    }
    public function test_http_channel_binding_and_empty_real_table_opening(): void
    {
        $table=$this->table();$this->actingAs($this->actor(4),'admin');
        $payload=['branch'=>'f:100','table_id'=>$table['id'],'waiter_name'=>'Staff','guest_count'=>1,'items'=>[],'idempotency_key'=>$this->key(2),'channel'=>'phone'];
        $r=$this->postJson(route('dining.save'),$payload)->assertOk()->assertJsonPath('ticket.channel','dine')->assertJsonPath('ticket.total','0.00')->json();
        $this->getJson(route('phone-orders.show',['id'=>$r['ticket']['id']]))->assertNotFound();
        $this->postJson(route('dining.settle',['id'=>$r['ticket']['id']]),$this->settlePayload($r['ticket']))->assertStatus(422);
        $this->assertSame(0,DB::table('takeaway_orders')->count());
    }
    public function test_metadata_edit_preserves_saved_phone_bill_after_menu_deleted_and_tax_changed(): void
    {
        $v=$this->savePayload('phone');$ticket=$this->tickets()->save('phone',$v,$this->actor())['ticket'];
        DB::table('resturant_products')->where('id',1)->delete();DB::table('takeaway_tills')->insert(['branch'=>'f:100','tax_bps'=>9900,'balance_cents'=>0,'revision'=>1]);
        $v['ticket_id']=$ticket['id'];$v['expected_revision']=1;$v['idempotency_key']=$this->key(3);$v['address']='Corrected street';$v['delivery_notes']='New door';$v['items'][0]['quantity']='1';
        $edited=$this->tickets()->save('phone',$v,$this->actor())['ticket'];$this->assertSame($ticket['total'],$edited['total']);$this->assertSame($ticket['quote_hash'],$edited['quote_hash']);$this->assertSame('0.00',$edited['tax_rate']);
        $paid=$this->tickets()->settle('phone',$ticket['id'],$this->settlePayload($edited),$this->actor());$this->assertSame('Corrected street',$paid['receipt']['context']['address']);$this->assertSame('120.00',$paid['receipt']['total']);
    }
    public function test_changed_financial_cart_intentionally_reprices_and_manager_discount_can_be_settled_by_cashier(): void
    {
        $v=$this->savePayload();$ticket=$this->tickets()->save('dine',$v,$this->actor())['ticket'];
        $v['ticket_id']=$ticket['id'];$v['expected_revision']=1;$v['idempotency_key']=$this->key(3);$v['items'][0]['quantity']='2.000';$v['discount']='10.00';$v['discount_reason']='Owner concession';
        $v['quote_hash']=$this->tickets()->quote('dine',$v,$this->actor(12))['quote_hash'];$edited=$this->tickets()->save('dine',$v,$this->actor(12))['ticket'];$this->assertSame('190.00',$edited['total']);
        $paid=$this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($edited),$this->actor(4));$this->assertSame('190.00',$paid['receipt']['total']);$this->assertSame('Owner concession',$paid['receipt']['discount_reason']);
    }
    public function test_paid_ticket_print_uses_paid_receipt_and_phone_print_contains_customer_delivery_snapshot(): void
    {
        $ticket=$this->saved('phone');$paid=$this->tickets()->settle('phone',$ticket['id'],$this->settlePayload($ticket),$this->actor());$this->actingAs($this->actor(),'admin');
        $html=$this->get($ticket['bill_print_url'].'?dashboard_print=1')->assertOk();$html->assertSee('data-dashboard-receipt="takeaway"',false)->assertSee('Street 1')->assertSee('Door 2');
        $this->assertSame($paid['receipt']['receipt_url'],$paid['receipt_url']);
    }

    public function test_dining_customer_name_survives_save_kitchen_and_receipt(): void
    {
        $v=$this->savePayload();$v['customer_name']='عميل التربيزة';
        $ticket=$this->tickets()->save('dine',$v,$this->actor())['ticket'];
        $this->assertSame('عميل التربيزة',$ticket['customer_name']);
        $sent=$this->tickets()->action('dine',$ticket['id'],['branch'=>'f:100','idempotency_key'=>$this->key(40),'expected_revision'=>1,'action'=>'send_kitchen'],$this->actor());
        $this->assertSame('عميل التربيزة',json_decode(DB::table('pos_service_kitchen_tickets')->value('snapshot'),true)['customer_name']);
        $paid=$this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($sent['ticket']),$this->actor());
        $this->assertSame('عميل التربيزة',$paid['receipt']['context']['customer_name']);
    }
    public function test_callcenter_save_queues_exactly_one_branch_print_and_does_not_collect_money(): void
    {
        $v=$this->savePayload('phone');$v['send_to_kitchen']=true;
        $saved=$this->tickets()->save('phone',$v,$this->actor(1));
        $replayed=$this->tickets()->save('phone',$v,$this->actor(1));
        $this->assertTrue($replayed['replayed']);$this->assertTrue($saved['print_queued']);
        $this->assertSame(1,DB::table('pos_branch_print_jobs')->count());$this->assertSame(1,DB::table('pos_service_kitchen_tickets')->count());
        $this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $this->assertCount(1,$this->tickets()->listing('phone',['branch'=>'f:100'],$this->actor())['items']);
        $this->assertCount(0,$this->tickets()->listing('phone',['branch'=>'f:101'],$this->actor(11))['items']);
    }
    public function test_printer_claim_is_branch_bound_and_never_automatically_replayed(): void
    {
        $v=$this->savePayload('phone');$v['send_to_kitchen']=true;$saved=$this->tickets()->save('phone',$v,$this->actor(1));
        $printing=app(\App\Services\Dashboard\PosBranchPrinting::class);
        $this->denied(fn()=>$printing->listing(['branch'=>'f:100'],$this->actor(1)),403);
        $this->denied(fn()=>$printing->listing(['branch'=>'f:100'],$this->actor(11)),404);
        $job=$printing->listing(['branch'=>'f:100'],$this->actor())['jobs'][0];
        $claim=['branch'=>'f:100','job_id'=>$job['id'],'claim_token'=>$this->key(51)];
        $result=$printing->claim($claim,$this->actor());$this->assertSame($saved['ticket']['id'],$result['ticket_id']);
        $this->denied(fn()=>$printing->claim($claim,$this->actor()));
        $this->assertCount(0,$printing->listing(['branch'=>'f:100'],$this->actor())['jobs']);
        Carbon::setTestNow(now()->addMinutes(3));$this->assertSame(1,$printing->listing(['branch'=>'f:100'],$this->actor())['attention']);
        $this->denied(fn()=>$printing->complete(array_merge($claim,['claim_token'=>$this->key(52),'result'=>'invoked']),$this->actor()),403);
        $this->assertSame('invoked',$printing->complete($claim+['result'=>'invoked'],$this->actor())['status']);
        $this->assertSame('invoked',$printing->complete($claim+['result'=>'invoked'],$this->actor())['status']);
        $this->assertSame(0,$printing->listing(['branch'=>'f:100'],$this->actor())['attention']);
    }
    public function test_cancelled_order_is_not_automatically_printed(): void
    {
        $v=$this->savePayload('phone');$v['send_to_kitchen']=true;$ticket=$this->tickets()->save('phone',$v,$this->actor(1))['ticket'];
        // Historical cancellations remain excluded from automatic branch printing. New cancellations after kitchen are rejected.
        DB::table('pos_service_tickets')->where('id',$ticket['id'])->update(['status'=>'cancelled']);
        $printing=app(\App\Services\Dashboard\PosBranchPrinting::class);
        $this->assertCount(0,$printing->listing(['branch'=>'f:100'],$this->actor())['jobs']);
        $this->denied(fn()=>$printing->claim(['branch'=>'f:100','job_id'=>DB::table('pos_branch_print_jobs')->value('id'),'claim_token'=>$this->key(51)],$this->actor()));
    }
    public function test_branch_can_add_tables_without_permission_to_change_service_charge(): void
    {
        $table=app(PosServiceTable::class)->configure(['branch'=>'f:100','name'=>'1','capacity'=>4,'active'=>true,'idempotency_key'=>$this->key(1)],$this->actor());
        $this->assertSame('1',$table['table']['name']);
        $this->denied(fn()=>app(PosServiceTable::class)->configure(['branch'=>'f:100','service_rate'=>'5.00','note'=>'x','expected_revision'=>1,'idempotency_key'=>$this->key(2)],$this->actor(),true),403);
    }

    public function test_phone_prefix_suggests_normalized_numbers_without_leaking_other_branches(): void
    {
        $this->saved('phone');
        foreach(['0101','٠١٠١','+20101','20101','0020101'] as $phone){
            $items=app(PosServicePhone::class)->customers(['branch'=>'f:100','phone'=>$phone,'prefix'=>true],$this->actor())['items'];
            $this->assertCount(1,$items);$this->assertSame('Customer',$items[0]['name']);$this->assertSame('Door 2',$items[0]['delivery_notes']);
        }
        $this->assertCount(0,app(PosServicePhone::class)->customers(['branch'=>'f:101','phone'=>'010','prefix'=>true],$this->actor(11))['items']);
        $this->assertCount(1,app(PosServicePhone::class)->customers(['branch'=>'f:101','phone'=>'010','prefix'=>true],$this->actor(1))['items']);
        $this->denied(fn()=>app(PosServicePhone::class)->customers(['branch'=>'f:100','phone'=>'01','prefix'=>true],$this->actor()),422);
    }
    public function test_registered_app_customer_addresses_are_found_with_branch_history_scope(): void
    {
        Schema::table('users',function(Blueprint $t){$t->string('mobile')->nullable();});
        Schema::table('orders',function(Blueprint $t){$t->unsignedBigInteger('resturant_id')->nullable();$t->unsignedBigInteger('user_id')->nullable();});
        Schema::create('user_address',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');foreach(['street_name','area_name','floor_no','apartment_no','badge'] as $c)$t->string($c)->nullable();});
        DB::table('users')->where('id',20)->update(['mobile'=>'+20 10644 64499']);
        DB::table('user_address')->insert(['user_id'=>20,'street_name'=>'Test Street','area_name'=>'Mansoura','floor_no'=>'2','apartment_no'=>'4','badge'=>'Near school']);
        $service=app(PosServicePhone::class);$v=['branch'=>'f:100','phone'=>'010644','prefix'=>true];
        $items=$service->customers($v,$this->actor(1))['items'];$this->assertCount(1,$items);$this->assertStringContainsString('Test Street',$items[0]['address']);$this->assertSame('Mansoura',$items[0]['area']);$this->assertSame('Near school',$items[0]['delivery_notes']);
        $this->assertCount(0,$service->customers($v,$this->actor())['items']);
        DB::table('orders')->insert(['status'=>'finished','resturant_id'=>100,'user_id'=>20]);
        $this->assertCount(1,$service->customers($v,$this->actor())['items']);
        $this->assertCount(0,$service->customers(['branch'=>'f:101']+$v,$this->actor(11))['items']);
    }

    public static function serviceChannels(): array {return [['dine'],['phone']];}

    /** @dataProvider serviceChannels */
    public function test_sent_kitchen_cannot_be_cancelled_or_emptied_but_can_be_revised(string $channel): void
    {
        $v=$this->savePayload($channel);$ticket=$this->tickets()->save($channel,$v,$this->actor())['ticket'];
        $command=['branch'=>'f:100','idempotency_key'=>$this->key(100),'expected_revision'=>1,'action'=>'send_kitchen'];
        $sent=$this->tickets()->action($channel,$ticket['id'],$command,$this->actor())['ticket'];
        $this->assertTrue($sent['kitchen_sent']);$this->assertFalse($sent['bill_locked']);
        $snapshot=DB::table('pos_service_kitchen_tickets')->first()->snapshot;
        $cancel=['branch'=>'f:100','idempotency_key'=>$this->key(101),'expected_revision'=>2,'action'=>'cancel','reason'=>'Customer left'];
        $this->denied(fn()=>$this->tickets()->action($channel,$ticket['id'],$cancel,$this->actor()));
        $v['ticket_id']=$ticket['id'];$v['expected_revision']=2;$v['idempotency_key']=$this->key(102);$v['items']=[];
        $this->denied(fn()=>$this->tickets()->save($channel,$v,$this->actor()));
        $v['items']=$this->cart()['items'];$v['items'][0]['quantity']='2';
        $v['quote_hash']=$this->tickets()->quote($channel,$v,$this->actor())['quote_hash'];
        $updated=$this->tickets()->save($channel,$v,$this->actor())['ticket'];
        $this->assertSame('2.000',$updated['items'][0]['quantity']);
        $v['idempotency_key']=$this->key(103);$v['expected_revision']=3;$v['items'][0]['quantity']='1';
        $v['quote_hash']=$this->tickets()->quote($channel,$v,$this->actor())['quote_hash'];
        $reduced=$this->tickets()->save($channel,$v,$this->actor())['ticket'];
        $command['idempotency_key']=$this->key(104);$command['expected_revision']=$reduced['revision'];
        $resent=$this->tickets()->action($channel,$ticket['id'],$command,$this->actor())['ticket'];
        $this->assertSame('1.000',$resent['items'][0]['quantity']);$this->assertSame(2,DB::table('pos_service_kitchen_tickets')->count());
        $this->assertSame($snapshot,DB::table('pos_service_kitchen_tickets')->orderBy('id')->first()->snapshot);
        $this->assertSame(0,DB::table('takeaway_orders')->count());
    }

    /** @dataProvider serviceChannels */
    public function test_issuing_bill_is_revisioned_idempotent_and_locks_all_writes_except_collection(string $channel): void
    {
        $v=$this->savePayload($channel);$ticket=$this->tickets()->save($channel,$v,$this->actor())['ticket'];
        $issue=['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$this->key(110),'action'=>'request_bill'];
        $this->denied(fn()=>$this->tickets()->action($channel,$ticket['id'],$issue,$this->actor(11)),404);
        $result=$this->tickets()->action($channel,$ticket['id'],$issue,$this->actor());$billed=$result['ticket'];
        $this->assertTrue($billed['bill_locked']);$this->assertSame(2,$billed['revision']);$this->assertSame(2,$billed['bill_issued_revision']);$this->assertSame(10,$billed['bill_issued_by']);$this->assertNotNull($billed['bill_issued_at']);
        $this->assertSame($ticket['items'],$billed['items']);$this->assertSame($ticket['total'],$billed['total']);
        $this->assertTrue($this->tickets()->action($channel,$ticket['id'],$issue,$this->actor())['replayed']);
        $this->assertTrue($this->tickets()->recover($channel,['branch'=>'f:100','idempotency_key'=>$issue['idempotency_key']],$this->actor())['ticket']['bill_locked']);
        $v['ticket_id']=$ticket['id'];$v['expected_revision']=1;$v['idempotency_key']=$this->key(111);$v['notes']='Stale edit';
        $this->denied(fn()=>$this->tickets()->save($channel,$v,$this->actor()));
        $v['expected_revision']=2;$this->denied(fn()=>$this->tickets()->save($channel,$v,$this->actor()));
        foreach($channel==='dine'?['send_kitchen','cancel','request_bill']:['send_kitchen','prepare','dispatch','finish','cancel','request_bill'] as $action){
            $command=['branch'=>'f:100','expected_revision'=>2,'idempotency_key'=>$this->key(112),'action'=>$action,'reason'=>'Cancel'];
            $this->denied(fn()=>$this->tickets()->action($channel,$ticket['id'],$command,$this->actor()));
        }
        $this->assertSame(2,DB::table('pos_service_commands')->whereNotNull('ticket_id')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $payment=$this->settlePayload($billed,113);$paid=$this->tickets()->settle($channel,$ticket['id'],$payment,$this->actor());
        $this->assertSame('paid',$paid['ticket']['payment_status']);$this->assertTrue($paid['ticket']['bill_locked']);$this->assertSame($billed['total'],$paid['receipt']['total']);
        $this->assertTrue($this->tickets()->settle($channel,$ticket['id'],$payment,$this->actor())['replayed']);
        $this->assertSame(1,DB::table('takeaway_orders')->count());$this->assertSame(1,DB::table('takeaway_till_entries')->count());
    }

    /** @dataProvider serviceChannels */
    public function test_details_are_read_only_and_print_requires_issued_bill(string $channel): void
    {
        $v=$this->savePayload($channel);$v['notes']='<script>alert(1)</script>';$ticket=$this->tickets()->save($channel,$v,$this->actor())['ticket'];
        $this->actingAs($this->actor(),'admin');
        $this->get($ticket['details_url'])->assertOk()->assertSee('data-dashboard-invoice-details="1"',false)->assertSee('Fish')->assertSee(e($v['notes']),false)->assertDontSee('window.print()',false)->assertDontSee('<script>alert',false);
        $this->getJson($ticket['bill_print_url'])->assertStatus(409);
        $this->assertSame(1,$this->tickets()->show($channel,$ticket['id'],$this->actor())['ticket']['revision']);
        $this->assertSame(1,DB::table('pos_service_commands')->whereNotNull('ticket_id')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $this->actingAs($this->actor(11),'admin')->getJson($ticket['details_url'])->assertNotFound();
        $this->actingAs($this->actor(),'admin');
        $issued=$this->tickets()->action($channel,$ticket['id'],['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$this->key(120),'action'=>'request_bill'],$this->actor())['ticket'];
        for($i=0;$i<2;$i++)$this->get($ticket['bill_print_url'].'?dashboard_print=1')->assertOk()->assertSee('data-dashboard-receipt=',false);
        $this->assertSame(2,$this->tickets()->show($channel,$ticket['id'],$this->actor())['ticket']['revision']);
        $paid=$this->tickets()->settle($channel,$ticket['id'],$this->settlePayload($issued,121),$this->actor());
        foreach([$paid['receipt']['details_url'],$ticket['details_url']] as $url)$this->get($url)->assertOk()->assertSee('data-dashboard-invoice-details="1"',false)->assertSee('Fish')->assertDontSee('window.print()',false);
        $this->actingAs($this->actor(11),'admin')->getJson($paid['receipt']['details_url'])->assertNotFound();
        $this->assertSame(1,DB::table('takeaway_orders')->count());$this->assertSame(1,DB::table('takeaway_till_entries')->count());
    }

    public function test_existing_awaiting_bill_tickets_are_locked_without_backfilling_finances(): void
    {
        $v=$this->savePayload();$ticket=$this->tickets()->save('dine',$v,$this->actor())['ticket'];
        DB::table('pos_service_tickets')->where('id',$ticket['id'])->update(['status'=>'awaiting_bill']);
        $ticket=$this->tickets()->show('dine',$ticket['id'],$this->actor())['ticket'];$this->assertTrue($ticket['bill_locked']);
        $v['ticket_id']=$ticket['id'];$v['expected_revision']=1;$v['idempotency_key']=$this->key(130);
        $this->denied(fn()=>$this->tickets()->save('dine',$v,$this->actor()));
        $this->assertSame('paid',$this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($ticket,131),$this->actor())['ticket']['payment_status']);
    }
    private function employeePayload(int $key=800): array {return ['branch'=>'f:100','idempotency_key'=>$this->key($key),'name'=>'أحمد','phone'=>'01012345678','job_title'=>'كاشير','shift'=>'صباحي','hired_on'=>'2026-09-01','active'=>true,'salary'=>'3000.00','effective_month'=>'2026-09'];}
    public function test_daily_employee_money_cells_and_details_match_date_kind_filters_and_voids(): void
    {
        $s=app(\App\Services\Dashboard\BranchPayroll::class);$a=$s->employeeSave($this->employeePayload(900),$this->actor())['employee'];
        $b=$s->employeeSave(array_replace($this->employeePayload(901),['name'=>'محمود','job_title'=>'شيف','shift'=>'مسائي']),$this->actor())['employee'];
        $base=['branch'=>'f:100','employee_id'=>$a['id'],'day'=>'2026-09-12'];
        foreach([['deduction','50.00'],['deduction','100.00'],['bonus','200.00'],['advance','500.00']] as $i=>$row)$s->entry($base+['kind'=>$row[0],'amount'=>$row[1],'reason'=>'تأخير','notes'=>'ملاحظة مسجلة','idempotency_key'=>$this->key(902+$i)],$this->actor());
        $void=$s->entry($base+['kind'=>'deduction','amount'=>'800.00','reason'=>'خطأ','idempotency_key'=>$this->key(906)],$this->actor())['entry'];
        $s->voidEntry(['branch'=>'f:100','entry_id'=>$void['id'],'expected_revision'=>1,'reason'=>'تصحيح','idempotency_key'=>$this->key(907)],$this->actor(1));
        $s->entry(array_replace($base,['day'=>'2026-09-11'])+['kind'=>'deduction','amount'=>'25.00','reason'=>'سابق','idempotency_key'=>$this->key(908)],$this->actor());
        $s->entry(array_replace($base,['employee_id'=>$b['id']])+['kind'=>'deduction','amount'=>'90.00','reason'=>'غياب','idempotency_key'=>$this->key(909)],$this->actor());
        $s->attendance($base+['status'=>'present','idempotency_key'=>$this->key(910)],$this->actor());$s->attendance(array_replace($base,['employee_id'=>$b['id']])+['status'=>'absent','idempotency_key'=>$this->key(911)],$this->actor());
        $listing=$s->listing(['branch'=>'f:100','day'=>'2026-09-12','month'=>'2026-10','job_title'=>'كاشير'],$this->actor());
        $this->assertCount(1,$listing['items']);$this->assertSame(['count'=>2,'amount'=>'150.00'],$listing['items'][0]['daily']['deduction']);$this->assertSame('200.00',$listing['items'][0]['daily']['bonus']['amount']);$this->assertSame('500.00',$listing['items'][0]['daily']['advance']['amount']);
        $this->assertSame('0.00',$listing['items'][0]['statement']['deduction']);$this->assertSame('150.00',$listing['summary']['deduction']);$this->assertSame(1,$listing['summary']['present']);$this->assertSame(0,$listing['summary']['absent']);$this->assertCount(2,$listing['options']['job_title']);
        $detail=$s->entries($base+['kind'=>'deduction'],$this->actor());$this->assertSame(2,$detail['count']);$this->assertSame('150.00',$detail['total']);$this->assertSame(['50.00','100.00'],array_column($detail['items'],'amount'));$this->assertSame('ملاحظة مسجلة',$detail['items'][0]['notes']);$this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/',$detail['items'][0]['time']);
        $empty=$s->listing(['branch'=>'f:100','day'=>'2026-09-10'],$this->actor());$this->assertSame(['count'=>0,'amount'=>'0.00'],$empty['items'][0]['daily']['advance']);$this->assertSame('0.00',$empty['summary']['deduction']);
        $filtered=$s->listing(['branch'=>'f:100','day'=>'2026-09-12','search'=>'محمود'],$this->actor());$this->assertSame('90.00',$filtered['summary']['deduction']);$this->assertSame(0,$filtered['summary']['present']);$this->assertSame(1,$filtered['summary']['absent']);
        $period=$s->statement(['branch'=>'f:100','employee_id'=>$a['id'],'month'=>'2026-09'],$this->actor())['statement'];$s->close(['branch'=>'f:100','employee_id'=>$a['id'],'month'=>'2026-09','preview_hash'=>$period['preview_hash'],'idempotency_key'=>$this->key(912)],$this->actor());
        $locked=$s->listing(['branch'=>'f:100','day'=>'2026-09-12','month'=>'2026-10','search'=>'أحمد'],$this->actor());$this->assertTrue($locked['items'][0]['day_closed']);$this->assertSame('draft',$locked['items'][0]['statement']['status']);$this->assertSame('150.00',$s->entries($base+['kind'=>'deduction'],$this->actor())['total']);
    }
    public function test_daily_entry_details_remain_branch_scoped_for_branch_and_central_accounts(): void
    {
        $s=app(\App\Services\Dashboard\BranchPayroll::class);$e=$s->employeeSave($this->employeePayload(920),$this->actor())['employee'];$v=['branch'=>'f:100','employee_id'=>$e['id'],'day'=>'2026-09-12','kind'=>'advance'];
        $s->entry($v+['amount'=>'75.50','reason'=>'سبب قديم محفوظ','idempotency_key'=>$this->key(921)],$this->actor());
        $this->denied(fn()=>$s->entries($v,$this->actor(11)),404);$this->denied(fn()=>$s->entries(array_replace($v,['branch'=>'f:101']),$this->actor(1)),404);
        $this->assertSame('75.50',$s->entries($v,$this->actor(1))['total']);$this->assertSame('سبب قديم محفوظ',$s->entries($v,$this->actor())['items'][0]['reason']);
        $foreign=$s->listing(['branch'=>'f:101','day'=>'2026-09-12'],$this->actor(11));$this->assertCount(0,$foreign['items']);$this->assertSame('0.00',$foreign['summary']['advance']);
        $this->invalid(fn()=>$s->entries(array_replace($v,['kind'=>'salary']),$this->actor()));
    }
    public function test_saved_customer_directory_precedes_orders_and_matches_national_international_and_arabic_phones(): void
    {
        $crm=app(\App\Services\Dashboard\BranchCustomers::class);$v=['branch'=>'f:100','idempotency_key'=>$this->key(801),'name'=>'أحمد','phone'=>'٠١٠٦٤٤٦٤٤٩٩','address'=>'شارع الجيش','latitude'=>31.04,'longitude'=>31.37];$saved=$crm->save($v,$this->actor());
        $this->assertTrue($crm->save($v,$this->actor())['replayed']);$this->assertSame(1,DB::table('branch_customers')->count());$this->assertSame('01064464499',$saved['customer']['phone_key']);
        foreach(['01064464499','1064464499','+201064464499','00201064464499','۰۱۰۶۴۴۶۴۴۹۹'] as $number){$items=app(PosServicePhone::class)->customers(['branch'=>'f:100','phone'=>$number],$this->actor())['items'];$this->assertSame('أحمد',$items[0]['name']);$this->assertSame((int)$saved['customer']['id'],$items[0]['customer_id']);}
        $this->assertCount(1,app(PosServicePhone::class)->customers(['branch'=>'f:100','phone'=>'010644','prefix'=>true],$this->actor())['items']);
        $this->assertCount(0,$crm->listing(['branch'=>'f:100','search'=>'غير موجود'],$this->actor())['items']);
        $this->denied(fn()=>$crm->listing(['branch'=>'all'],$this->actor()),403);$this->denied(fn()=>$crm->save($v,$this->actor(11)),404);
        $this->assertCount(0,app(PosServicePhone::class)->customers(['branch'=>'f:101','phone'=>'010644','prefix'=>true],$this->actor(11))['items']);
        $this->assertCount(1,$crm->listing(['branch'=>'all'],$this->actor(1))['items']);
        $this->denied(fn()=>$crm->save(array_replace($v,['idempotency_key'=>$this->key(802),'phone'=>'+201064464499']),$this->actor()),409);
        $this->denied(fn()=>$crm->save(array_replace($v,['customer_id'=>$saved['customer']['id'],'expected_revision'=>99,'idempotency_key'=>$this->key(803)]),$this->actor()),409);
    }
    public function test_existing_app_customers_stored_without_leading_zero_are_found_without_exposing_foreign_branch_customers(): void
    {
        Schema::table('users',function(Blueprint $t){$t->string('mobile')->nullable();});Schema::table('orders',function(Blueprint $t){$t->unsignedBigInteger('user_id')->nullable();$t->unsignedBigInteger('resturant_id')->nullable();});
        DB::table('users')->where('id',20)->update(['name'=>'App Customer','mobile'=>'١٠٦٤٤٦٤٤٩٩']);DB::table('orders')->where('id',1)->update(['user_id'=>20,'resturant_id'=>100]);
        $this->assertSame('App Customer',app(PosServicePhone::class)->customers(['branch'=>'f:100','phone'=>'010644','prefix'=>true],$this->actor())['items'][0]['name']);
        $this->assertCount(0,app(PosServicePhone::class)->customers(['branch'=>'f:101','phone'=>'010644','prefix'=>true],$this->actor(11))['items']);
    }
    public function test_customer_lookup_keeps_selected_branch_identity_for_shared_and_alternate_addresses(): void
    {
        $crm=app(\App\Services\Dashboard\BranchCustomers::class);
        $v=['branch'=>'f:100','idempotency_key'=>$this->key(840),'name'=>'Repeat customer','phone'=>'01064464499','address'=>'Saved address','latitude'=>30.1,'longitude'=>31.2];
        $local=$crm->save($v,$this->actor())['customer'];
        $foreign=$crm->save(array_replace($v,['branch'=>'f:101','idempotency_key'=>$this->key(841)]),$this->actor(11))['customer'];
        DB::table('branch_customers')->where('id',$foreign['id'])->update(['updated_at'=>now()->addMinute()]);
        $service=app(PosServicePhone::class);$lookup=['branch'=>'f:100','phone'=>$v['phone']];
        $match=$service->customers($lookup,$this->actor(1))['items'];
        $this->assertCount(1,$match);$this->assertSame((int)$local['id'],$match[0]['customer_id']);$this->assertSame((int)$local['revision'],$match[0]['customer_revision']);
        // Another delivery address must update this customer's branch record,
        // rather than attempt to create a duplicate customer on the next order.
        DB::table('branch_customers')->where('id',$foreign['id'])->update(['address'=>'Other address','latitude'=>30.2,'longitude'=>31.3]);
        $match=$service->customers($lookup,$this->actor(1))['items'];
        $this->assertCount(2,$match);$this->assertSame('Saved address',$match[0]['address']);
        $this->assertSame((int)$local['id'],$match[1]['customer_id']);$this->assertEquals(30.2,$match[1]['latitude']);
        $second=$crm->save(array_replace($v,['idempotency_key'=>$this->key(842),'customer_id'=>$match[1]['customer_id'],'expected_revision'=>$match[1]['customer_revision'],'address'=>$match[1]['address'],'latitude'=>$match[1]['latitude'],'longitude'=>$match[1]['longitude']]),$this->actor(1))['customer'];
        $this->assertSame((int)$local['id'],(int)$second['id']);$this->assertSame(2,DB::table('branch_customers')->count());
        $this->assertCount(1,$service->customers($lookup,$this->actor())['items']);
        $this->denied(fn()=>$service->customers($lookup,$this->actor(11)),404);
    }
    public function test_customer_lookup_restores_coordinates_from_order_history_and_app_addresses(): void
    {
        Schema::table('users',function(Blueprint $t){$t->string('mobile')->nullable();});
        Schema::create('user_address',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->string('address');$t->decimal('lat',10,7);$t->decimal('lng',10,7);});
        $ticket=$this->saved('phone');$service=app(PosServicePhone::class);
        $items=$service->customers(['branch'=>'f:100','phone'=>$ticket['customer_phone']],$this->actor())['items'];
        $this->assertEquals($ticket['delivery_location']['latitude'],$items[0]['latitude']);$this->assertEquals($ticket['delivery_location']['longitude'],$items[0]['longitude']);
        // A directory entry without coordinates can reuse the same address's
        // verified historical pin, while retaining its own revision and id.
        $customer=app(\App\Services\Dashboard\BranchCustomers::class)->save(['branch'=>'f:100','idempotency_key'=>$this->key(850),'name'=>$ticket['customer_name'],'phone'=>$ticket['customer_phone'],'address'=>$ticket['address'],'area'=>$ticket['area']],$this->actor())['customer'];
        $items=$service->customers(['branch'=>'f:100','phone'=>$ticket['customer_phone']],$this->actor())['items'];
        $this->assertCount(1,$items);$this->assertSame((int)$customer['id'],$items[0]['customer_id']);$this->assertEquals($ticket['delivery_location']['latitude'],$items[0]['latitude']);
        DB::table('users')->where('id',20)->update(['mobile'=>'01055555555']);DB::table('user_address')->insert(['user_id'=>20,'address'=>'App address','lat'=>30.3,'lng'=>31.4]);
        $items=$service->customers(['branch'=>'f:100','phone'=>'01055555555'],$this->actor(1))['items'];
        $this->assertEquals(30.3,$items[0]['latitude']);$this->assertEquals(31.4,$items[0]['longitude']);
        $this->assertCount(0,$service->customers(['branch'=>'f:100','phone'=>'01055555555'],$this->actor())['items']);
    }
    public function test_delivery_fee_is_authoritative_and_stale_or_unconfirmed_location_is_rejected(): void
    {
        $v=$this->savePayload('phone');$v['delivery_fee']='0.01';$q=$this->tickets()->quote('phone',$v,$this->actor());$this->assertSame('20.00',$q['delivery']);
        $v['quote_hash']=$q['quote_hash'];$ticket=$this->tickets()->save('phone',$v,$this->actor())['ticket'];$this->assertSame('20.00',$ticket['delivery_fee']);$this->assertSame(1000,$ticket['delivery_location']['distance_meters']);
        $this->invalid(fn()=>app(\App\Services\Dashboard\PhoneDelivery::class)->quote(array_replace($v,['location_confirmed'=>false]),$this->actor()));
        $v['idempotency_key']=$this->key(812);DB::table('resturants')->where('id',100)->update(['km_price'=>'25.00']);$this->denied(fn()=>$this->tickets()->save('phone',$v,$this->actor()),409);
        $this->assertSame(1,DB::table('pos_service_tickets')->count());$this->denied(fn()=>app(\App\Services\Dashboard\PhoneDelivery::class)->quote(array_replace($v,['branch'=>'f:101']),$this->actor()),404);
    }
    public function test_company_assignment_is_branch_scoped_active_and_snapshotted_on_kitchen_and_invoice(): void
    {
        $s=app(\App\Services\Dashboard\DeliveryCompanies::class);$company=$s->save(['branch'=>'f:100','idempotency_key'=>$this->key(820),'name'=>'شركة النور','phone'=>'01000000000','active'=>true],$this->actor())['company'];
        $v=$this->savePayload('phone');$v['delivery_company_id']=$company['id'];$v['send_to_kitchen']=true;$saved=$this->tickets()->save('phone',$v,$this->actor());$this->assertSame('شركة النور',$saved['ticket']['delivery_company']['name']);
        $count=$s->listing(['branch'=>'f:100'],$this->actor())['items'][0];$this->assertSame(1,$count['orders']);$this->assertSame(1,$count['unpaid']);
        $s->save(['branch'=>'f:100','company_id'=>$company['id'],'expected_revision'=>1,'idempotency_key'=>$this->key(821),'name'=>'اسم جديد','phone'=>'01000000000','active'=>false],$this->actor());
        $this->assertSame('شركة النور',$this->tickets()->show('phone',$saved['ticket']['id'],$this->actor())['ticket']['delivery_company']['name']);
        $v['idempotency_key']=$this->key(822);$this->denied(fn()=>$this->tickets()->save('phone',$v,$this->actor()),422);
        $foreign=$s->save(['branch'=>'f:101','idempotency_key'=>$this->key(823),'name'=>'Foreign','phone'=>'01000000001','active'=>true],$this->actor(11))['company'];$v['delivery_company_id']=$foreign['id'];$this->denied(fn()=>$this->tickets()->save('phone',$v,$this->actor()),422);
        $this->assertSame(1,DB::table('pos_service_tickets')->count());
    }
    public function test_payroll_periods_reconcile_proration_entries_closure_and_recorded_payment_exactly_once(): void
    {
        $s=app(\App\Services\Dashboard\BranchPayroll::class);$employee=$s->employeeSave($this->employeePayload(),$this->actor())['employee'];$id=$employee['id'];
        $this->assertTrue($s->employeeSave($this->employeePayload(),$this->actor())['replayed']);
        $attendance=['branch'=>'f:100','employee_id'=>$id,'day'=>'2026-09-12','status'=>'absent','idempotency_key'=>$this->key(830)];$s->attendance($attendance,$this->actor());$this->assertTrue($s->attendance($attendance,$this->actor())['replayed']);
        foreach([['bonus','500.00'],['deduction','100.00'],['advance','700.00']] as $n=>$entry){$v=['branch'=>'f:100','employee_id'=>$id,'day'=>'2026-09-12','kind'=>$entry[0],'amount'=>$entry[1],'reason'=>'Approved','idempotency_key'=>$this->key(831+$n)];$s->entry($v,$this->actor());$this->assertTrue($s->entry($v,$this->actor())['replayed']);}
        $q=['branch'=>'f:100','employee_id'=>$id,'month'=>'2026-09'];$statement=$s->statement($q,$this->actor())['statement'];$this->assertSame('2700.00',$statement['net']);$this->assertSame('3000.00',$statement['earned_salary']);$this->assertCount(1,$statement['attendance']);
        $close=$q+['idempotency_key'=>$this->key(840),'preview_hash'=>$statement['preview_hash']];$closed=$s->close($close,$this->actor())['statement'];$this->assertSame('closed',$closed['status']);$this->assertTrue($s->close($close,$this->actor())['replayed']);
        $this->denied(fn()=>$s->attendance(array_replace($attendance,['idempotency_key'=>$this->key(841),'expected_revision'=>1]),$this->actor()),409);
        $this->denied(fn()=>$s->entry(array_replace($v,['idempotency_key'=>$this->key(842)]),$this->actor()),409);
        $pay=['branch'=>'f:100','payroll_id'=>$closed['payroll_id'],'expected_revision'=>1,'payment_method'=>'cash','payment_confirmed'=>true,'idempotency_key'=>$this->key(843)];$paid=$s->pay($pay,$this->actor())['statement'];$this->assertSame('paid',$paid['status']);$this->assertTrue($s->pay($pay,$this->actor())['replayed']);
        $this->denied(fn()=>$s->pay(array_replace($pay,['idempotency_key'=>$this->key(844)]),$this->actor()),409);$this->assertSame(0,DB::table('takeaway_till_entries')->count());$this->assertSame(1,DB::table('branch_payrolls')->count());
        $this->denied(fn()=>$s->statement($q,$this->actor(11)),404);
        $this->denied(fn()=>$s->employeeSave(array_replace($this->employeePayload(845),['employee_id'=>$id,'expected_revision'=>1,'salary'=>'6000.00']),$this->actor()),409);
        $this->assertSame('2700.00',$s->statement($q,$this->actor())['statement']['net']);
    }
    public function test_payroll_stale_preview_void_history_hire_proration_and_negative_balance_are_safe(): void
    {
        $s=app(\App\Services\Dashboard\BranchPayroll::class);$e=$s->employeeSave(array_replace($this->employeePayload(),['hired_on'=>'2026-09-16','left_on'=>'']),$this->actor())['employee'];$q=['branch'=>'f:100','employee_id'=>$e['id'],'month'=>'2026-09'];$initial=$s->statement($q,$this->actor())['statement'];$this->assertSame('1500.00',$initial['earned_salary']);
        $v=['branch'=>'f:100','employee_id'=>$e['id'],'day'=>'2026-09-20','kind'=>'advance','amount'=>'1800.00','reason'=>'Advance','idempotency_key'=>$this->key(860)];$entry=$s->entry($v,$this->actor());
        $this->denied(fn()=>$s->close($q+['idempotency_key'=>$this->key(861),'preview_hash'=>$initial['preview_hash']],$this->actor()),409);$preview=$s->statement($q,$this->actor())['statement'];$this->assertSame('-300.00',$preview['net']);
        $id=DB::table('branch_employee_entries')->value('id');$s->voidEntry(['branch'=>'f:100','entry_id'=>$id,'expected_revision'=>1,'reason'=>'Correction','idempotency_key'=>$this->key(862)],$this->actor(1));$preview=$s->statement($q,$this->actor())['statement'];$this->assertSame('1500.00',$preview['net']);$this->assertNotEmpty($preview['entries'][0]['voided_at']);
        $this->assertSame(1,DB::table('branch_employee_entries')->count());$this->assertCount(1,$s->listing(['branch'=>'all','month'=>'2026-09'],$this->actor(1))['items']);
        $this->assertTrue(app(\App\Services\Dashboard\BranchOperations::class)->recover(['branch'=>'f:100','idempotency_key'=>$this->key(862)],$this->actor(1))['found']);
        $this->assertFalse(app(\App\Services\Dashboard\BranchOperations::class)->recover(['branch'=>'f:100','idempotency_key'=>$this->key(862)],$this->actor(12))['found']);
    }
    public function test_open_map_provider_requires_explicit_activation_and_preserves_branch_scope(): void
    {
        \Illuminate\Support\Facades\Http::fake();config(['services.maps.phone_open_enabled'=>false]);$maps=app(\App\Services\Dashboard\PhoneMapProvider::class);
        $this->denied(fn()=>$maps->suggestions(['branch'=>'f:100','query'=>'عنوان اختبار'],$this->actor()),503);
        config(['services.maps.phone_open_enabled'=>true]);$this->denied(fn()=>$maps->suggestions(['branch'=>'f:101','query'=>'عنوان اختبار'],$this->actor()),404);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
    public function test_address_options_filter_country_and_malformed_locations_and_do_not_send_customer_identity(): void
    {
        config(['services.maps.phone_open_enabled'=>true]);$http=\Illuminate\Support\Facades\Http::class;
        $http::fake(['photon.komoot.io/*'=>$http::response(['features'=>[
            ['geometry'=>['type'=>'Point','coordinates'=>[31.2,30.1]],'properties'=>['name'=>'شارع تجريبي','city'=>'مدينة اختبار','countrycode'=>'EG']],
            ['geometry'=>['type'=>'Point','coordinates'=>[31.2,30.1]],'properties'=>['name'=>'شارع تجريبي','city'=>'مدينة اختبار','countrycode'=>'EG']],
            ['geometry'=>['type'=>'Point','coordinates'=>[2,48]],'properties'=>['name'=>'Foreign','countrycode'=>'FR']],
            ['geometry'=>['type'=>'Point','coordinates'=>[999,30]],'properties'=>['name'=>'Bad','countrycode'=>'EG']]
        ]])]);
        $maps=app(\App\Services\Dashboard\PhoneMapProvider::class);$v=['branch'=>'f:100','query'=>'شارع تجريبي'];$r=$maps->suggestions($v,$this->actor());$this->assertCount(1,$r['items']);$this->assertSame(30.1,$r['items'][0]['latitude']);$this->assertSame('شارع تجريبي، مدينة اختبار',$r['items'][0]['label']);$this->assertSame($r,$maps->suggestions($v,$this->actor()));$http::assertSentCount(1);
        $http::assertSent(fn($r)=>$r['q']==='شارع تجريبي'&&$r['countrycode']==='EG'&&!isset($r['customer_phone'])&&!isset($r['customer_name'])&&$r->hasHeader('User-Agent'));
    }
    public function test_address_typeahead_accepts_two_letters_reuses_cache_and_releases_shared_lock_before_http(): void
    {
        config(['services.maps.phone_open_enabled'=>true]);$http=\Illuminate\Support\Facades\Http::class;$cache=\Illuminate\Support\Facades\Cache::store();
        $slot='phone-search-slot:'.hash('sha256','photon.komoot.io');$cache->forget($slot.':next');
        $http::fake(function($request)use($http,$cache,$slot){$lock=$cache->lock($slot,2);$this->assertTrue($lock->get(),'Network call must not hold the global search lock');$lock->release();$this->assertSame('ال',$request['q']);return $http::response(['features'=>[['geometry'=>['type'=>'Point','coordinates'=>[31.2,30.1]],'properties'=>['name'=>'المنصورة','countrycode'=>'EG']]]]);});
        $maps=app(\App\Services\Dashboard\PhoneMapProvider::class);$v=['branch'=>'f:100','query'=>'ال'];$r=$maps->suggestions($v,$this->actor());$this->assertCount(1,$r['items']);
        $cache->put($slot.':next',microtime(true)+10,10);$this->assertSame($r,$maps->suggestions($v,$this->actor()));$http::assertSentCount(1);
        $this->denied(fn()=>$maps->suggestions(['branch'=>'f:100','query'=>'عنوان آخر'],$this->actor()),429);$http::assertSentCount(1);
    }
    public function test_road_fee_is_authoritative_cached_and_cannot_fall_back_to_a_straight_line(): void
    {
        config(['services.maps.phone_open_enabled'=>true]);$http=\Illuminate\Support\Facades\Http::class;
        $http::fake(['routing.openstreetmap.de/*'=>$http::response(['code'=>'Ok','routes'=>[['distance'=>1575,'geometry'=>['type'=>'LineString','coordinates'=>[[31,30],[31.001,30.005],[31,30.009]]]]]])]);
        $v=$this->savePayload('phone');$service=app(\App\Services\Dashboard\PhoneDelivery::class);$q=$service->quote($v,$this->actor())['delivery'];$this->assertSame('31.50',$q['delivery_fee']);$this->assertSame(1575,$q['distance_meters']);$this->assertSame('road_osrm',$q['method']);$this->assertCount(3,$q['route_path']);$http::assertSentCount(1);
        $v['delivery_quote_hash']=$q['delivery_quote_hash'];$v['delivery_fee']='0.01';$ticket=$this->tickets()->save('phone',$v,$this->actor())['ticket'];$this->assertSame('31.50',$ticket['delivery_fee']);$this->assertSame(1575,$ticket['delivery_location']['distance_meters']);$http::assertSentCount(1);
        $http::swap(new \Illuminate\Http\Client\Factory);$http::fake(['routing.openstreetmap.de/*'=>$http::response(['code'=>'NoRoute','routes'=>[]])]);$changed=array_replace($v,['latitude'=>30.02]);$this->denied(fn()=>$service->quote($changed,$this->actor()),422);
        $http::swap(new \Illuminate\Http\Client\Factory);$http::fake(['routing.openstreetmap.de/*'=>$http::response([],503)]);$this->denied(fn()=>$service->quote(array_replace($v,['latitude'=>30.03]),$this->actor()),503);
    }

    public function test_only_primary_owner_can_void_employee_money_even_if_other_accounts_manage_the_branch(): void
    {
        $s=app(\App\Services\Dashboard\BranchPayroll::class);$employee=$s->employeeSave($this->employeePayload(950),$this->actor())['employee'];
        $entry=$s->entry(['branch'=>'f:100','employee_id'=>$employee['id'],'day'=>'2026-09-12','kind'=>'advance','amount'=>'150.00','reason'=>'سلفة','idempotency_key'=>$this->key(951)],$this->actor())['entry'];
        $v=['branch'=>'f:100','entry_id'=>$entry['id'],'expected_revision'=>1,'reason'=>'تصحيح الأونر','idempotency_key'=>$this->key(952)];$q=['branch'=>'f:100','employee_id'=>$employee['id'],'month'=>'2026-09'];
        foreach([10,4,12] as $id){$this->assertFalse($s->statement($q,$this->actor($id))['statement']['can_void_entries']);$this->denied(fn()=>$s->voidEntry($v,$this->actor($id)),403);}
        $this->assertNull(DB::table('branch_employee_entries')->where('id',$entry['id'])->value('voided_at'));$this->assertTrue($s->statement($q,$this->actor(1))['statement']['can_void_entries']);
        $this->actingAs($this->actor(),'admin');$this->postJson(route('employees.void-entry'),$v)->assertForbidden();
        $result=$s->voidEntry($v,$this->actor(1));$this->assertFalse($result['replayed']);$this->assertTrue($s->voidEntry($v,$this->actor(1))['replayed']);$this->assertSame(1,(int)DB::table('branch_employee_entries')->where('id',$entry['id'])->value('voided_by'));$this->assertSame(1,DB::table('branch_employee_entries')->count());
    }

    private function dispatchPhone(int $number=1000,?int $company=null): array
    {
        if(!$company)$company=app(\App\Services\Dashboard\DeliveryCompanies::class)->save(['branch'=>'f:100','idempotency_key'=>$this->key($number),'name'=>'شركة التوصيل','phone'=>'01012345678','active'=>true],$this->actor())['company']['id'];
        $ticket=$this->tickets()->save('phone',$this->savePayload('phone',$number+1),$this->actor())['ticket'];
        app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->dispatch(['branch'=>'f:100','ticket_id'=>$ticket['id'],'company_id'=>$company,'expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key($number+2)],$this->actor());
        return $this->tickets()->show('phone',$ticket['id'],$this->actor())['ticket']+['courier_company_id'=>$company];
    }
    private function batchPayload(array $tickets,int $key=1500): array
    {
        $total=array_sum(array_map(fn($t)=>\App\Services\GoServices\Money::minor($t['total']),$tickets));
        return ['branch'=>'f:100','items'=>array_map(fn($t)=>['id'=>$t['id'],'revision'=>$t['revision'],'quote_hash'=>$t['quote_hash']],$tickets),'courier_name'=>'محمد','total'=>\App\Services\GoServices\Money::decimal($total),'payment_method'=>'cash','cash_received'=>\App\Services\GoServices\Money::decimal($total+1000),'payment_confirmed'=>true,'idempotency_key'=>$this->key($key)];
    }
    public function test_dispatch_is_scoped_recoverable_and_never_changes_an_issued_invoice(): void
    {
        $board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);$companies=app(\App\Services\Dashboard\DeliveryCompanies::class);
        $company=$companies->save(['branch'=>'f:100','idempotency_key'=>$this->key(1000),'name'=>'الشركة','phone'=>'01000000000','active'=>true],$this->actor())['company'];
        $foreign=$companies->save(['branch'=>'f:101','idempotency_key'=>$this->key(1001),'name'=>'Foreign','phone'=>'01000000000','active'=>true],$this->actor(11))['company'];
        $ticket=$this->saved('phone');$ticket=$this->tickets()->action('phone',$ticket['id'],['branch'=>'f:100','expected_revision'=>1,'action'=>'request_bill','idempotency_key'=>$this->key(1002)],$this->actor())['ticket'];
        $old=DB::table('pos_service_tickets')->where('id',$ticket['id'])->first();
        $v=['branch'=>'f:100','ticket_id'=>$ticket['id'],'company_id'=>$company['id'],'expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key(1003)];
        $this->denied(fn()=>$board->dispatch(array_replace($v,['company_id'=>$foreign['id']]),$this->actor()),422);
        $this->denied(fn()=>$board->dispatch($v,$this->actor(11)),404);
        $result=$board->dispatch($v,$this->actor());$this->assertTrue($board->dispatch($v,$this->actor())['replayed']);
        $this->assertSame($result['ticket_id'],app(\App\Services\Dashboard\BranchOperations::class)->recover($v,$this->actor())['ticket_id']);
        $new=DB::table('pos_service_tickets')->where('id',$ticket['id'])->first();foreach(['quote_snapshot','cart_snapshot','delivery_company_snapshot','bill_issued_at','bill_issued_revision'] as $key)$this->assertSame($old->$key,$new->$key);
        $this->assertSame('out_for_delivery',$new->status);$this->assertSame(0,DB::table('takeaway_orders')->count());
        $listing=$board->listing(['branch'=>'f:100'],$this->actor());$this->assertSame(0,$listing['columns']['preparing']['pagination']['total']);$this->assertSame(1,$listing['columns']['courier']['pagination']['total']);$this->assertCount(1,$listing['companies']);
        $counts=$companies->listing(['branch'=>'f:100'],$this->actor())['items'][0];$this->assertSame(1,$counts['orders']);$this->assertSame(1,$counts['unpaid']);
        $this->denied(fn()=>$this->tickets()->action('phone',$ticket['id'],['branch'=>'f:100','expected_revision'=>$new->revision,'action'=>'send_kitchen','idempotency_key'=>$this->key(1004)],$this->actor()));
    }
    public function test_batch_collects_once_prints_frozen_amounts_and_replay_does_not_collect_new_sales(): void
    {
        $a=$this->dispatchPhone();$b=$this->dispatchPhone(1010,$a['courier_company_id']);$board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);$v=$this->batchPayload([$a,$b]);
        $r=$board->finish($v,$this->actor());$this->assertSame('240.00',$r['batch']['total']);$this->assertSame('10.00',$r['batch']['change']);$this->assertCount(2,$r['batch']['items']);
        $this->assertSame(2,DB::table('takeaway_orders')->count());$this->assertSame(24000,(int)DB::table('takeaway_tills')->value('balance_cents'));$this->assertSame(2,DB::table('phone_delivery_batch_items')->count());
        $this->assertTrue($board->finish($v,$this->actor())['replayed']);$this->assertTrue(app(\App\Services\Dashboard\BranchOperations::class)->recover($v,$this->actor())['found']);
        $this->denied(fn()=>$board->finish(array_replace($v,['courier_name'=>'غيره']),$this->actor()));
        $this->denied(fn()=>$board->finish(array_replace($v,['idempotency_key'=>$this->key(1501)]),$this->actor()));
        DB::table('branch_delivery_companies')->where('id',$a['courier_company_id'])->update(['name'=>'Changed']);
        $receipt=$board->receipt($r['batch']['id'],$this->actor());$this->assertSame('شركة التوصيل',$receipt['company']['name']);
        $this->denied(fn()=>$board->receipt($r['batch']['id'],$this->actor(11)),404);
        $html=view('admin.phone_orders.batch_receipt',['batch'=>$receipt])->render();$this->assertStringContainsString('data-dashboard-receipt="phone-batch"',$html);$this->assertStringContainsString('240.00',$html);
        $listing=$board->listing(['branch'=>'f:100'],$this->actor());$this->assertSame(0,$listing['columns']['courier']['pagination']['total']);$this->assertSame(2,$listing['columns']['finished']['pagination']['total']);$this->assertSame($r['batch']['print_url'],$listing['columns']['finished']['items'][0]['batch_print_url']);
        $this->assertSame(24000,(int)DB::table('takeaway_tills')->value('balance_cents'));
    }
    public function test_delivery_batch_rejects_non_cash_collection_without_settling_any_ticket(): void
    {
        $ticket=$this->dispatchPhone();$board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);$v=$this->batchPayload([$ticket]);
        foreach(['card','mobile_wallet','other'] as $method)$this->denied(fn()=>$board->finish(array_replace($v,['payment_method'=>$method]),$this->actor()),422);
        $this->assertSame('unpaid',$this->tickets()->show('phone',$ticket['id'],$this->actor())['ticket']['payment_status']);
        $this->assertSame(0,DB::table('phone_delivery_batches')->count());$this->assertSame(0,DB::table('takeaway_orders')->count());
        $paid=$board->finish($v,$this->actor());$this->assertSame('cash',$paid['batch']['payment_method']);$this->assertTrue($board->finish($v,$this->actor())['replayed']);
    }

    public function test_batch_rejects_mixed_companies_unconfirmed_cash_stale_quotes_and_branch_mismatch(): void
    {
        $a=$this->dispatchPhone();$b=$this->dispatchPhone(1010);$board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);$v=$this->batchPayload([$a,$b]);
        $this->denied(fn()=>$board->finish($v,$this->actor()),422);
        $v=$this->batchPayload([$a]);$this->invalid(fn()=>$board->finish(array_replace($v,['payment_confirmed'=>false]),$this->actor()));
        $this->denied(fn()=>$board->finish(array_replace($v,['cash_received'=>'1.00']),$this->actor()),422);
        $this->denied(fn()=>$board->finish($v,$this->actor(11)),404);
        $this->denied(fn()=>$board->finish(array_replace($v,['total'=>'1.00']),$this->actor()));
        $changed=$v;$changed['items'][0]['quote_hash']=str_repeat('a',64);$this->denied(fn()=>$board->finish($changed,$this->actor()));
        $changed=$v;$changed['items'][0]['revision']=1;$this->denied(fn()=>$board->finish($changed,$this->actor()));
        $changed=$v;$changed['items'][]=$changed['items'][0];$this->invalid(fn()=>$board->finish($changed,$this->actor()));
        $this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame(0,DB::table('phone_delivery_batches')->count());
    }
    public function test_batch_rolls_back_every_collection_when_later_checkout_fails(): void
    {
        $a=$this->dispatchPhone();$b=$this->dispatchPhone(1010,$a['courier_company_id']);$v=$this->batchPayload([$a,$b]);$original=app(TakeawayService::class);$calls=0;
        $mock=\Mockery::mock(TakeawayService::class)->makePartial();$mock->shouldReceive('checkout')->andReturnUsing(function(...$args)use($original,&$calls){$calls++;if($calls===2)abort(409,'Stock changed');return $original->checkout(...$args);});$mock->shouldReceive('receipt')->andReturnUsing(fn(...$args)=>$original->receipt(...$args));$this->app->instance(TakeawayService::class,$mock);
        $board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);$this->denied(fn()=>$board->finish($v,$this->actor()));
        $this->assertSame(2,$calls);$this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());$this->assertSame(0,DB::table('phone_delivery_batches')->count());$this->assertSame(2,DB::table('pos_service_tickets')->where('payment_status','unpaid')->count());
        $this->app->instance(TakeawayService::class,$original);$result=app(\App\Services\Dashboard\PhoneDeliveryBoard::class)->finish($v,$this->actor());$this->assertCount(2,$result['batch']['items']);
    }
    public function test_delivery_board_paginates_each_column_and_hides_foreign_companies(): void
    {
        $ticket=$this->saved('phone');$row=(array)DB::table('pos_service_tickets')->first();unset($row['id']);
        for($i=0;$i<22;$i++)DB::table('pos_service_tickets')->insert($row);
        $board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);$r=$board->listing(['branch'=>'f:100','preparing_page'=>2],$this->actor());
        $this->assertSame(23,$r['columns']['preparing']['pagination']['total']);$this->assertCount(3,$r['columns']['preparing']['items']);$this->assertCount(0,$r['columns']['courier']['items']);
        $this->denied(fn()=>$board->listing(['branch'=>'f:101'],$this->actor()),404);
    }
    public function test_central_delivery_board_lists_all_branches_and_keeps_company_and_write_scopes(): void
    {
        $local=$this->saved('phone');$v=$this->savePayload('phone',1600);$v['branch']='f:101';$v['items'][0]['product_id']=2;
        $v['delivery_quote_hash']=app(\App\Services\Dashboard\PhoneDelivery::class)->quote($v,$this->actor(11))['delivery']['delivery_quote_hash'];
        $v['quote_hash']=$this->tickets()->quote('phone',$v,$this->actor(11))['quote_hash'];
        $foreign=$this->tickets()->save('phone',$v,$this->actor(11))['ticket'];
        $companies=app(\App\Services\Dashboard\DeliveryCompanies::class);$board=app(\App\Services\Dashboard\PhoneDeliveryBoard::class);
        $company=$companies->save(['branch'=>'f:100','idempotency_key'=>$this->key(1601),'name'=>'Local','phone'=>'01011111111','active'=>true],$this->actor())['company'];
        $other=$companies->save(['branch'=>'f:101','idempotency_key'=>$this->key(1602),'name'=>'Foreign','phone'=>'01022222222','active'=>true],$this->actor(11))['company'];
        foreach([[],['branch'=>'all']] as $filter){
            $all=$board->listing($filter,$this->actor(1));$this->assertSame('all',$all['branch']);$this->assertSame(2,$all['columns']['preparing']['pagination']['total']);
            $this->assertSame(['f:100','f:101'],array_column(array_column($all['columns']['preparing']['items'],'branch'),'value'));
            $this->assertEqualsCanonicalizing(['f:100','f:101'],array_column($all['companies'],'branch'));
            foreach([10,11,4,12] as $id)$this->denied(fn()=>$board->listing($filter,$this->actor($id)),403);
        }
        $only=$board->listing(['branch'=>'f:100'],$this->actor(1));$this->assertSame([$local['id']],array_column($only['columns']['preparing']['items'],'id'));$this->assertSame([$company['id']],array_column($only['companies'],'id'));
        $dispatch=['branch'=>'f:101','ticket_id'=>$foreign['id'],'company_id'=>$other['id'],'expected_revision'=>$foreign['revision'],'idempotency_key'=>$this->key(1603)];
        $this->invalid(fn()=>$board->dispatch(array_replace($dispatch,['branch'=>'all']),$this->actor(1)));
        $this->denied(fn()=>$board->dispatch(array_replace($dispatch,['company_id'=>$company['id']]),$this->actor(1)),422);
        $board->dispatch($dispatch,$this->actor(1));$all=$board->listing(['branch'=>'all'],$this->actor(1));
        $this->assertSame(1,$all['columns']['preparing']['pagination']['total']);$this->assertSame([$foreign['id']],array_column($all['columns']['courier']['items'],'id'));
        $mixed=$this->batchPayload([$local,$foreign],1604);$this->denied(fn()=>$board->finish($mixed,$this->actor(1)),404);
        $this->assertSame(0,DB::table('takeaway_orders')->count());
    }
    public function test_employee_wallet_and_auto_notes_are_revisioned_scoped_and_do_not_mark_attendance_or_transfer_money(): void
    {
        $s=app(\App\Services\Dashboard\BranchPayroll::class);$e=$s->employeeSave($this->employeePayload(),$this->actor())['employee'];
        $v=['branch'=>'f:100','employee_id'=>$e['id'],'expected_revision'=>1,'wallet_phone'=>'+20 10 1234 5678','idempotency_key'=>$this->key(1200)];
        $r=$s->wallet($v,$this->actor());$this->assertSame('01012345678',$r['employee']['wallet_phone']);$this->assertTrue($s->wallet($v,$this->actor())['replayed']);
        $this->denied(fn()=>$s->wallet($v,$this->actor(11)),404);$this->denied(fn()=>$s->wallet(array_replace($v,['wallet_phone'=>'123']),$this->actor()),422);
        $this->denied(fn()=>$s->wallet(array_replace($v,['idempotency_key'=>$this->key(1201)]),$this->actor()));
        $n=['branch'=>'f:100','employee_id'=>$e['id'],'day'=>'2026-09-12','notes'=>'ملاحظة','idempotency_key'=>$this->key(1202)];$s->dailyNotes($n,$this->actor());$this->assertTrue($s->dailyNotes($n,$this->actor())['replayed']);
        $list=$s->listing(['branch'=>'f:100','day'=>'2026-09-12','month'=>'2026-09'],$this->actor());$this->assertSame(0,$list['summary']['present']);$this->assertSame('unrecorded',$list['items'][0]['attendance']['status']);$this->assertSame('3000.00',$list['items'][0]['statement']['net']);
        $att=$s->attendance(['branch'=>'f:100','employee_id'=>$e['id'],'day'=>'2026-09-12','status'=>'present','check_in'=>'09:30','notes'=>'ملاحظة','expected_revision'=>1,'idempotency_key'=>$this->key(1203)],$this->actor());$this->assertSame(2,$att['attendance']['revision']);
        $q=['branch'=>'f:100','employee_id'=>$e['id'],'month'=>'2026-09'];$preview=$s->statement($q,$this->actor())['statement'];$s->close($q+['preview_hash'=>$preview['preview_hash'],'idempotency_key'=>$this->key(1204)],$this->actor());
        $this->denied(fn()=>$s->dailyNotes(array_replace($n,['expected_revision'=>2,'idempotency_key'=>$this->key(1205)]),$this->actor()));
        $s->wallet(array_replace($v,['expected_revision'=>2,'wallet_phone'=>'٠١١١٢٣٤٥٦٧٨','idempotency_key'=>$this->key(1206)]),$this->actor());$this->assertSame('01012345678',$s->statement($q,$this->actor())['statement']['employee']['wallet_phone']);
        $this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }
}

