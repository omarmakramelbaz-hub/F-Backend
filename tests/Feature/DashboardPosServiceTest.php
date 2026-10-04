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
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->timestamps();});
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->text('price');$t->string('status');$t->timestamps();});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->decimal('amount',14,2);});
        Schema::create('orders',function(Blueprint $t){$t->id();$t->string('status');});
        Schema::create('order_board_clocks',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');});
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php');(new \CreateGoStoreCatalog)->up();
        require_once database_path('migrations/2026_10_03_140000_create_takeaway_pos.php');(new \CreateTakeawayPos)->up();
        require_once database_path('migrations/2026_10_03_150000_create_pos_service_tickets.php');(new \CreatePosServiceTickets)->up();
        require_once database_path('migrations/2026_10_04_000001_create_pos_branch_print_jobs.php');(new \CreatePosBranchPrintJobs)->up();
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'app_scope'=>$id===30?'go_partner':'fasakhansta','status'=>'accepted','owner_resturant_id'=>$owner]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Foreign']]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{}','status'=>'show'],['id'=>2,'resturant_id'=>101,'product_name'=>'Foreign','product_price'=>'500.00','price'=>'{}','status'=>'show']]);
        DB::table('go_stores')->insert(['user_id'=>30,'name'=>'GO store','kind'=>'grocery','address'=>'Address','created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_store_products')->insert(['id'=>70,'user_id'=>30,'request_key'=>$this->key(70),'name'=>'Rice','unit'=>'كيلو','price_cents'=>10000,'image_path'=>'rice.jpg','available'=>true,'options'=>'[]','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['amount'=>'100.00']);DB::table('orders')->insert(['status'=>'accepted']);DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }
    protected function tearDown(): void{Carbon::setTestNow();if($this->connection==='mysql'&&config('database.connections.mysql.database')==='takeaway_test'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void{foreach(['pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_store_products','go_stores','user_address','order_board_clocks','carts','orders','wallets','settings','pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table)Schema::dropIfExists($table);}
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
        $v['quote_hash']=$this->tickets()->quote($channel,$v,$this->actor())['quote_hash'];return $v;
    }
    private function saved(string $channel='dine'): array{return $this->tickets()->save($channel,$this->savePayload($channel),$this->actor())['ticket'];}
    private function settlePayload(array $ticket,int $key=10,string $method='cash'): array{return ['branch'=>$ticket['branch']['value'],'expected_revision'=>$ticket['revision'],'idempotency_key'=>$this->key($key),'quote_hash'=>$ticket['quote_hash'],'payment_method'=>$method,'cash_received'=>'500.00','payment_confirmed'=>true];}
    private function denied(callable $call,int $status=409): void{try{$call();$this->fail('Expected '.$status);}catch(HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    private function invalid(callable $call): void{try{$call();$this->fail('Expected invalid payload');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}

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
    public function test_mixed_tender_moves_only_allocated_cash_and_preserves_breakdown(): void
    {
        $ticket=$this->saved();$v=$this->settlePayload($ticket,10,'mixed');$v['cash_received']='40.00';$v['tenders']=[['method'=>'cash','amount'=>'30.00'],['method'=>'card','amount'=>'50.00'],['method'=>'mobile_wallet','amount'=>'20.00']];
        $result=$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor());$this->assertSame('30.00',$result['register']['balance']);$this->assertSame('10.00',$result['receipt']['change']);
        $this->assertSame(3000,(int)DB::table('takeaway_till_entries')->value('amount_cents'));$this->assertCount(3,$result['receipt']['payment_breakdown']);
        $this->assertSame('30.00',$result['today']['cash']);$this->assertSame('50.00',$result['today']['card']);$this->assertSame('20.00',$result['today']['mobile_wallet']);
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
        $v['payment_confirmed']=true;$paid=$this->tickets()->settle('phone',$ticket['id'],$v,$this->actor());$this->assertSame('120.00',$paid['register']['balance']);
        $this->assertSame('20.00',$paid['receipt']['delivery']);$this->assertSame('Street 1',$paid['receipt']['context']['address']);$this->assertSame('Door 2',$paid['receipt']['context']['delivery_notes']);
    }
    public function test_phone_vat_includes_explicit_delivery_fee_without_app_rate_or_wallet_changes(): void
    {
        app(TakeawayService::class)->changeRegister(['branch'=>'f:100','tax_rate'=>'14.00','note'=>'Tax','expected_revision'=>1,'idempotency_key'=>$this->key(21)],$this->actor(12),true);
        $ticket=$this->saved('phone');$this->assertSame('16.80',$ticket['tax']);$this->assertSame('136.80',$ticket['total']);
        $r=$this->tickets()->settle('phone',$ticket['id'],$this->settlePayload($ticket,10,'card'),$this->actor());$this->assertSame('0.00',$r['register']['balance']);
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
    public function test_actual_print_endpoints_render_unpaid_kitchen_and_paid_mixed_context_safely(): void
    {
        $ticket=$this->saved();$sent=$this->tickets()->action('dine',$ticket['id'],['branch'=>'f:100','expected_revision'=>1,'idempotency_key'=>$this->key(3),'action'=>'send_kitchen'],$this->actor());
        $this->actingAs($this->actor(),'admin');$this->get($ticket['bill_print_url'].'?dashboard_print=1')->assertOk()->assertSee('data-dashboard-receipt=',false)->assertSee('Waiter');
        $this->get($sent['kitchen_print_url'].'?dashboard_print=1')->assertOk()->assertSee('data-dashboard-receipt=',false)->assertSee('Fish');
        $v=$this->settlePayload($sent['ticket'],10,'mixed');$v['tenders']=[['method'=>'cash','amount'=>'30.00'],['method'=>'card','amount'=>'70.00']];
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
        $this->tickets()->action('phone',$ticket['id'],['branch'=>'f:100','idempotency_key'=>$this->key(50),'expected_revision'=>1,'action'=>'cancel','reason'=>'Customer cancelled'],$this->actor(1));
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
}
