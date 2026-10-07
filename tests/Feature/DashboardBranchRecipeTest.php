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

class DashboardBranchRecipeTest extends TestCase
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
        require_once database_path('migrations/2026_10_04_210000_create_branch_inventory_recipes.php');(new \CreateBranchInventoryRecipes)->up();
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'app_scope'=>$id===30?'go_partner':'fasakhansta','status'=>'accepted','owner_resturant_id'=>$owner]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Foreign']]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{}','status'=>'show'],['id'=>2,'resturant_id'=>101,'product_name'=>'Foreign','product_price'=>'500.00','price'=>'{}','status'=>'show']]);
        DB::table('go_stores')->insert(['user_id'=>30,'name'=>'GO store','kind'=>'grocery','address'=>'Address','created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_store_products')->insert(['id'=>70,'user_id'=>30,'request_key'=>$this->key(70),'name'=>'Rice','unit'=>'كيلو','price_cents'=>10000,'image_path'=>'rice.jpg','available'=>true,'options'=>'[]','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['amount'=>'100.00']);DB::table('orders')->insert(['status'=>'accepted']);DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }
    protected function tearDown(): void{Carbon::setTestNow();if($this->connection==='mysql'&&config('database.connections.mysql.database')==='takeaway_test'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void{foreach(['branch_recipe_sales','branch_stock_recipes','branch_inventory_movements','branch_inventory','stock_ingredients','branch_stock_movements','branch_stock','branch_payrolls','branch_employee_entries','branch_employee_days','branch_employee_salaries','branch_employees','branch_delivery_companies','branch_customers','branch_operation_commands','pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_store_products','go_stores','user_address','order_board_clocks','carts','orders','wallets','settings','pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table)Schema::dropIfExists($table);}
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

    private function inventory(): \App\Services\Dashboard\BranchInventory{return app(\App\Services\Dashboard\BranchInventory::class);}
    private function receiveIngredient(int $id,string $quantity,int $key=80,string $unit='kg'): array
    {
        return $this->inventory()->receive(['branch'=>'f:100','ingredient_id'=>$id,'quantity'=>$quantity,'unit'=>$unit,'idempotency_key'=>$this->key($key)],$this->actor());
    }
    private function recipePayload(int $key=90,int $product=1,string $fish='250'): array
    {
        return ['branch'=>'f:100','product_id'=>$product,'feature_id'=>0,'unit'=>'piece','idempotency_key'=>$this->key($key),'components'=>[
            ['ingredient_id'=>1,'quantity'=>$fish,'measure'=>'g'],['ingredient_id'=>16,'quantity'=>'25','measure'=>'g'],['ingredient_id'=>20,'quantity'=>'1','measure'=>'piece'],
        ]];
    }
    private function ingredientBalance(int $id): int{return (int)DB::table('branch_inventory')->where('branch','f:100')->where('ingredient_id',$id)->value('quantity_units');}
    private function pay(array $cart,int $key=100): array
    {
        $s=app(TakeawayService::class);$q=$s->quote($cart,$this->actor());$v=$cart+['quote_hash'=>$q['quote_hash'],'idempotency_key'=>$this->key($key),'payment_method'=>'cash','cash_received'=>'1000.00'];return [$s->checkout($v,$this->actor()),$v];
    }
    private function directStock(int $product,int $units,string $unit='piece',string $branch='f:100'): void
    {
        DB::table('branch_stock')->insert(['branch'=>$branch,'product_id'=>$product,'unit'=>$unit,'quantity_units'=>$units,'revision'=>1]);
    }
    public function test_feseekh_size_cards_share_the_registered_goods_balance_and_refresh_after_one_sale(): void
    {
        DB::table('resturant_products')->where('id',1)->update(['product_name'=>'فسيخ دسوق ٤ قطعة']);
        DB::table('resturant_products')->insert(['id'=>3,'resturant_id'=>100,'product_name'=>'فسيخ نبروه 4 سمكات','product_price'=>'100','price'=>'{}','status'=>'show']);
        $this->receiveIngredient(1,'99.7');DB::table('branch_inventory')->insert(['branch'=>'f:101','ingredient_id'=>1,'quantity_units'=>500000000,'revision'=>1]);
        $items=app(\App\Services\Dashboard\TakeawayCatalog::class)->listing(['branch'=>'f:100'],$this->actor())['items'];
        foreach($items as $item){$this->assertSame('99.7',$item['stock']['quantity']);$this->assertSame('رصيد البضاعة: 99.7 كجم',$item['stock']['label']);$this->assertSame('weight',$item['quantity_mode']);}
        $cart=$this->cart();$cart['items'][0]['quantity_mode']='weight';$cart['items'][0]['quantity']='0.15';[$sale,$v]=$this->pay($cart);
        foreach([1,3] as $id)$this->assertSame('99.55',$sale['stock_balances'][$id]['quantity']);
        $this->assertSame(99550000,$this->ingredientBalance(1));$this->assertSame(500000000,(int)DB::table('branch_inventory')->where('branch','f:101')->value('quantity_units'));
        $this->assertTrue(app(TakeawayService::class)->checkout($v,$this->actor())['replayed']);$this->assertSame(1,DB::table('branch_inventory_movements')->where('source_type','pos')->count());
    }
    public function test_all_feseekh_sizes_are_exact_and_offers_meals_and_sandwiches_are_not_implicitly_linked(): void
    {
        $names=['فسيخ دسوق 1 قطعة','فسيخ نبروه سمكتين','فسيخ دسوق 3 قطعه','فسيخ نبروه 4 سمكات','عرض كيلو فسيخ شم النسيم','وجبة فسيخ','ساندوتش فسيخ'];
        $ids=[];foreach($names as $i=>$name){$id=10+$i;$ids[]=$id;DB::table('resturant_products')->insert(['id'=>$id,'resturant_id'=>100,'product_name'=>$name,'product_price'=>'100','price'=>'{}','status'=>'show']);}
        foreach([1,2,3,4] as $id)$this->receiveIngredient($id,(string)($id*10),80+$id);
        $stocks=$this->inventory()->menuBalances('f:100',$ids);
        foreach([10=>'40',11=>'30',12=>'20',13=>'10'] as $id=>$balance)$this->assertSame($balance,$stocks[$id]['quantity']);
        foreach([14,15,16] as $id)$this->assertFalse($stocks[$id]['configured']);
        DB::table('branch_inventory')->where('ingredient_id',1)->where('branch','f:100')->update(['quantity_units'=>-500000]);$this->assertSame('-0.5',$this->inventory()->menuBalances('f:100',[13])[13]['quantity']);
        DB::table('branch_inventory')->where('ingredient_id',1)->delete();$missing=$this->inventory()->menuBalances('f:100',[13])[13];$this->assertFalse($missing['tracked']);$this->assertSame('رصيد البضاعة لم يسجّل بعد',$missing['label']);
    }
    public function test_saved_recipe_overrides_raw_binding_and_recorded_direct_stock_keeps_its_source(): void
    {
        DB::table('resturant_products')->where('id',1)->update(['product_name'=>'فسيخ دسوق 4 قطعة']);
        $this->receiveIngredient(1,'10');$this->directStock(1,6000000);
        $this->assertSame('direct',$this->inventory()->menuBalances('f:100',[1])[1]['source']);
        $recipe=$this->recipePayload();$recipe['components']=[['ingredient_id'=>1,'quantity'=>'250','measure'=>'g']];$this->inventory()->saveRecipe($recipe,$this->actor(1));
        $this->assertSame('40',$this->inventory()->menuBalances('f:100',[1])[1]['quantity']);
    }
    public function test_raw_weight_portions_consume_only_the_matching_ingredient_once(): void
    {
        Schema::table('resturant_products',function(Blueprint $t){$t->unsignedBigInteger('product_id')->nullable();});Schema::create('product_features',function(Blueprint $t){$t->id();$t->unsignedBigInteger('product_id');$t->string('name');});
        DB::table('resturant_products')->where('id',1)->update(['product_name'=>'فسيخ دسوق 4 قطعة','product_id'=>99]);DB::table('product_features')->insert(['id'=>6,'product_id'=>99,'name'=>'quarter']);
        $this->receiveIngredient(1,'2');$cart=$this->cart();$cart['items'][0]=['product_id'=>1,'quantity'=>'2','quantity_mode'=>'weight','option_id'=>'f:6:base'];[$sale]=$this->pay($cart);
        $this->assertSame('1.5',$sale['stock_balances'][1]['quantity']);$this->assertSame(1500000,$this->ingredientBalance(1));
    }
    public function test_unconfigured_cards_use_scoped_direct_stock_and_refresh_it_after_cash_sale(): void
    {
        $this->directStock(1,7000000);$this->directStock(1,99000000,'piece','f:101');
        $catalog=app(\App\Services\Dashboard\TakeawayCatalog::class)->listing(['branch'=>'f:100'],$this->actor());$item=$catalog['items'][0];
        $this->assertSame('7',$item['stock']['quantity']);$this->assertSame('direct',$item['stock']['source']);$this->assertFalse($item['stock']['configured']);
        $this->assertSame('رصيد الوحدة: 7 قطعة',$item['stock']['label']);$this->assertSame('piece',$item['quantity_mode']);
        $cart=$this->cart();$cart['items'][0]['quantity']='2';[$sale,$v]=$this->pay($cart);
        $this->assertSame('5',$sale['stock_balances'][1]['quantity']);$this->assertSame(5000000,(int)DB::table('branch_stock')->where('branch','f:100')->value('quantity_units'));
        $this->assertSame(99000000,(int)DB::table('branch_stock')->where('branch','f:101')->value('quantity_units'));
        $this->assertTrue(app(TakeawayService::class)->checkout($v,$this->actor())['replayed']);$this->assertSame(1,DB::table('branch_stock_movements')->where('source_type','pos')->count());
        $this->assertSame(0,DB::table('branch_inventory_movements')->count());
        $audit=json_decode(DB::table('branch_recipe_sales')->value('snapshot'),true);$this->assertSame([],$audit['unmapped']);$this->assertSame('direct',$audit['lines'][0]['recipe']['stock_source']);
    }
    public function test_recipe_and_direct_stock_are_deducted_once_without_changing_each_others_balances(): void
    {
        DB::table('resturant_products')->insert(['id'=>3,'resturant_id'=>100,'product_name'=>'Direct SKU','product_price'=>'50','price'=>'{}','status'=>'show']);
        $this->directStock(1,8000000);$this->directStock(3,4000000);
        $this->receiveIngredient(1,'5');$this->receiveIngredient(16,'2',81);$this->receiveIngredient(20,'10',82,'piece');$this->inventory()->saveRecipe($this->recipePayload(),$this->actor(1));
        $cart=$this->cart();$cart['items'][]=['product_id'=>3,'quantity'=>'2','quantity_mode'=>'piece'];[$sale]=$this->pay($cart);
        $this->assertSame(4750000,$this->ingredientBalance(1));$this->assertSame(8000000,(int)DB::table('branch_stock')->where('product_id',1)->value('quantity_units'));
        $this->assertSame('2',$sale['stock_balances'][3]['quantity']);$this->assertSame('9',$sale['stock_balances'][1]['quantity']);
        $this->assertSame(1,DB::table('branch_stock_movements')->count());
    }
    public function test_direct_weight_zero_and_unknown_balances_are_distinct_and_unit_rules_apply(): void
    {
        $missing=$this->inventory()->menuBalances('f:100',[1])[1];$this->assertFalse($missing['tracked']);$this->assertSame('—',$missing['quantity']);$this->assertSame('رصيد الوحدة غير مسجّل',$missing['label']);
        $this->directStock(1,125000,'kg');$cart=$this->cart();$this->denied(fn()=>app(TakeawayService::class)->quote($cart,$this->actor()),422);
        $cart['items'][0]['quantity_mode']='weight';$cart['items'][0]['quantity']='0.125';[$sale]=$this->pay($cart);
        $this->assertSame('0',$sale['stock_balances'][1]['quantity']);$this->assertTrue($sale['stock_balances'][1]['tracked']);
        [$sale]=$this->pay($cart,101);$this->assertSame('-0.125',$sale['stock_balances'][1]['quantity']);$this->assertTrue($sale['stock_balances'][1]['negative']);
        $this->assertSame([],$this->inventory()->menuBalances('gs:30',[1]));
    }
    public function test_saved_direct_stock_source_stays_frozen_if_recipe_is_added_before_settlement(): void
    {
        $this->directStock(1,6000000);$ticket=$this->saved();$this->assertSame('direct',$ticket['items'][0]['inventory']['stock_source']);
        $this->inventory()->saveRecipe($this->recipePayload(),$this->actor(1));$this->receiveIngredient(1,'4');
        $v=$this->settlePayload($ticket);$this->tickets()->settle('dine',$ticket['id'],$v,$this->actor());
        $this->assertSame(5000000,(int)DB::table('branch_stock')->value('quantity_units'));$this->assertSame(4000000,$this->ingredientBalance(1));
        $this->assertTrue($this->tickets()->settle('dine',$ticket['id'],$v,$this->actor())['replayed']);$this->assertSame(1,DB::table('branch_stock_movements')->count());
    }

    public function test_app_sales_use_direct_weight_stock_and_portions_once_when_recipes_are_installed(): void
    {
        $this->directStock(1,2000000,'kg');
        Schema::table('resturant_products',function(Blueprint $t){$t->unsignedBigInteger('product_id')->nullable();});
        Schema::create('product_features',function(Blueprint $t){$t->id();$t->unsignedBigInteger('product_id');$t->string('name');});
        Schema::create('carts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');$t->unsignedBigInteger('resturant_product_id');$t->string('qty');$t->unsignedBigInteger('product_feature')->nullable();});
        DB::table('resturant_products')->where('id',1)->update(['product_id'=>99]);DB::table('product_features')->insert(['id'=>6,'product_id'=>99,'name'=>'quarter']);
        DB::table('carts')->insert(['order_id'=>77,'resturant_product_id'=>1,'qty'=>'2','product_feature'=>6]);
        $order=new \App\Models\Order;$order->forceFill(['id'=>77,'type'=>'current','resturant_id'=>100]);
        DB::transaction(fn()=>$this->inventory()->appSale($order));DB::transaction(fn()=>$this->inventory()->appSale($order));
        $this->assertSame(1500000,(int)DB::table('branch_stock')->value('quantity_units'));$this->assertSame(1,DB::table('branch_stock_movements')->where('source_type','app')->count());
        $this->assertSame(0,DB::table('branch_inventory_movements')->count());
    }

    public function test_pre_update_unpaid_bill_without_source_marker_consumes_its_direct_stock(): void
    {
        $this->directStock(1,3000000);$ticket=$this->saved();
        $q=json_decode(DB::table('pos_service_tickets')->where('id',$ticket['id'])->value('quote_snapshot'),true);
        unset($q['items'][0]['inventory']['stock_source']);$q['items'][0]['inventory']['unit']=null;
        DB::table('pos_service_tickets')->where('id',$ticket['id'])->update(['quote_snapshot'=>json_encode($q)]);
        $this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($ticket),$this->actor());
        $this->assertSame(2000000,(int)DB::table('branch_stock')->value('quantity_units'));$this->assertSame(1,DB::table('branch_stock_movements')->count());
    }

    public function test_goods_dropdown_contains_only_the_twenty_three_requested_raw_goods_and_preserves_legacy_balances(): void
    {
        DB::table('resturant_products')->where('id',1)->update(['product_name'=>'وجبة فسيخ']);
        DB::table('branch_stock')->insert(['branch'=>'f:100','product_id'=>1,'unit'=>'piece','quantity_units'=>7000000,'revision'=>1]);
        $r=app(\App\Services\Dashboard\BranchStock::class)->listing(['branch'=>'f:100'],$this->actor());
        $this->assertCount(23,$r['items']);$this->assertSame('فسيخ كيلو 4 سمكات',$r['items'][0]['name']);$this->assertSame('مياه',$r['items'][22]['name']);$this->assertNotContains('وجبة فسيخ',array_column($r['items'],'name'));
        $this->assertSame('7',$r['legacy'][0]['quantity']);$this->assertSame(1,$r['unconfigured_count']);
        $this->invalid(fn()=>app(\App\Services\Dashboard\BranchStock::class)->receive(['branch'=>'f:100','product_id'=>1,'quantity'=>'1','unit'=>'piece','idempotency_key'=>$this->key(80)],$this->actor()));
        $this->assertSame(7000000,(int)DB::table('branch_stock')->value('quantity_units'));$this->assertSame(0,DB::table('branch_inventory')->count());
    }
    public function test_raw_receipts_enforce_units_branch_access_and_replay_recovery(): void
    {
        $v=['branch'=>'f:100','ingredient_id'=>1,'quantity'=>'1.125','unit'=>'kg','idempotency_key'=>$this->key(80)];$s=$this->inventory();
        $this->denied(fn()=>$s->receive($v,$this->actor(11)),404);$first=$s->receive($v,$this->actor());$this->assertTrue($s->receive($v,$this->actor())['replayed']);
        $this->assertSame(1125000,$this->ingredientBalance(1));$this->assertSame(1,DB::table('branch_inventory_movements')->count());
        $this->assertSame($first['receipt'],app(\App\Services\Dashboard\BranchOperations::class)->recover($v,$this->actor())['receipt']);
        $this->denied(fn()=>$this->receiveIngredient(20,'1.5',81,'piece'),422);$this->denied(fn()=>$this->receiveIngredient(1,'1',82,'piece'),422);$this->denied(fn()=>$this->receiveIngredient(999,'1',83),404);
        $this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }
    public function test_recipe_editing_is_authorized_scoped_validated_versioned_and_idempotent(): void
    {
        $s=$this->inventory();$v=$this->recipePayload();$this->denied(fn()=>$s->saveRecipe($v,$this->actor()),403);
        $this->denied(fn()=>$s->saveRecipe(array_replace($v,['product_id'=>2]),$this->actor(4)),404);
        $bad=$v;$bad['components'][0]['measure']='piece';$this->denied(fn()=>$s->saveRecipe($bad,$this->actor(1)),422);
        $bad=$v;$bad['components'][1]=$bad['components'][0];$this->invalid(fn()=>$s->saveRecipe($bad,$this->actor(1)));
        $first=$s->saveRecipe($v,$this->actor(4));$this->assertSame(1,$first['recipe']['revision']);$this->assertTrue($s->saveRecipe($v,$this->actor(4))['replayed']);
        $this->assertSame(250000,$first['recipe']['variants'][0][0]['quantity_units']);
        $next=$this->recipePayload(91);$this->denied(fn()=>$s->saveRecipe($next,$this->actor(4)),409);$next['expected_revision']=1;$next['components'][0]['quantity']='300';
        $changed=$s->saveRecipe($next,$this->actor(12));$this->assertSame(2,$changed['recipe']['revision']);$this->assertSame(300000,$changed['recipe']['variants'][0][0]['quantity_units']);
        $this->assertSame(0,DB::table('branch_inventory_movements')->count());$this->assertFalse($s->recipes(['branch'=>'f:100'],$this->actor())['can_manage']);
        $this->denied(fn()=>$s->recipes(['branch'=>'f:100'],$this->actor(11)),404);
    }
    public function test_mixed_menu_sales_aggregate_shared_ingredients_once_and_refresh_all_recipe_balances(): void
    {
        DB::table('resturant_products')->insert(['id'=>3,'resturant_id'=>100,'product_name'=>'ساندوتش فسيخ','product_price'=>'50','price'=>'{}','status'=>'show']);
        $this->receiveIngredient(1,'5');$this->receiveIngredient(16,'2',81);$this->receiveIngredient(20,'10',82,'piece');
        $s=$this->inventory();$s->saveRecipe($this->recipePayload(),$this->actor(1));$s->saveRecipe($this->recipePayload(91,3,'100'),$this->actor(1));
        $cart=$this->cart();$cart['items'][0]['quantity']='2';$cart['items'][]=['product_id'=>3,'quantity'=>'1','quantity_mode'=>'piece'];[$sale,$v]=$this->pay($cart);
        $this->assertSame(4400000,$this->ingredientBalance(1));$this->assertSame(1925000,$this->ingredientBalance(16));$this->assertSame(7000000,$this->ingredientBalance(20));
        $this->assertSame(3,DB::table('branch_inventory_movements')->where('source_type','pos')->count());$this->assertSame(1,DB::table('branch_recipe_sales')->count());
        $this->assertSame('7',$sale['stock_balances'][1]['quantity']);$this->assertSame('7',$sale['stock_balances'][3]['quantity']);
        $this->assertTrue(app(TakeawayService::class)->checkout($v,$this->actor())['replayed']);$this->assertSame(4400000,$this->ingredientBalance(1));
        $snapshot=json_decode(DB::table('branch_recipe_sales')->value('snapshot'),true);$this->assertCount(2,$snapshot['lines']);$this->assertCount(3,$snapshot['deductions']);$this->assertSame([],$snapshot['unmapped']);
    }
    public function test_saved_dining_recipe_is_frozen_and_stock_only_moves_at_collection(): void
    {
        $this->receiveIngredient(1,'2');$s=$this->inventory();$first=$s->saveRecipe($this->recipePayload(),$this->actor(1));$ticket=$this->saved();
        $next=$this->recipePayload(91,1,'500');$next['expected_revision']=1;$s->saveRecipe($next,$this->actor(1));
        $sent=$this->tickets()->action('dine',$ticket['id'],['branch'=>'f:100','expected_revision'=>1,'action'=>'send_kitchen','idempotency_key'=>$this->key(3)],$this->actor())['ticket'];
        $bill=$this->tickets()->action('dine',$ticket['id'],['branch'=>'f:100','expected_revision'=>$sent['revision'],'action'=>'request_bill','idempotency_key'=>$this->key(4)],$this->actor())['ticket'];
        $this->assertSame(2000000,$this->ingredientBalance(1));$this->tickets()->settle('dine',$ticket['id'],$this->settlePayload($bill),$this->actor());
        $this->assertSame(1750000,$this->ingredientBalance(1));$snapshot=json_decode(DB::table('branch_recipe_sales')->value('snapshot'),true);$this->assertSame(1,$snapshot['lines'][0]['recipe']['revision']);$this->assertSame(-1000000,$this->ingredientBalance(20));
        $this->assertTrue($s->menuBalances('f:100',[1])[1]['negative']);$this->assertSame('0',$s->menuBalances('f:100',[1])[1]['quantity']);
    }
    public function test_each_size_has_its_own_recipe_without_implicitly_dividing_bread_and_drinks(): void
    {
        Schema::table('resturant_products',function(Blueprint $t){$t->unsignedBigInteger('product_id')->nullable();});Schema::create('product_features',function(Blueprint $t){$t->id();$t->unsignedBigInteger('product_id');$t->string('name');});
        DB::table('resturant_products')->where('id',1)->update(['product_id'=>99]);DB::table('product_features')->insert(['id'=>6,'product_id'=>99,'name'=>'quarter']);
        $s=$this->inventory();$s->saveRecipe($this->recipePayload(90,1,'1000'),$this->actor(1));$cart=$this->cart();$cart['items'][0]['option_id']='f:6:base';
        $this->denied(fn()=>app(TakeawayService::class)->quote($cart,$this->actor()),422);
        $quarter=$this->recipePayload(91,1,'250');$quarter['feature_id']=6;$quarter['expected_revision']=1;$s->saveRecipe($quarter,$this->actor(1));
        $this->receiveIngredient(1,'3');$this->receiveIngredient(20,'6',81,'piece');$this->pay($cart);
        $this->assertSame(2750000,$this->ingredientBalance(1));$this->assertSame(5000000,$this->ingredientBalance(20));
    }
    public function test_weight_recipe_uses_exact_gram_conversion_and_rejects_stale_quotes(): void
    {
        $v=$this->recipePayload();$v['unit']='kg';$v['components']=[['ingredient_id'=>1,'quantity'=>'1000','measure'=>'g']];$s=$this->inventory();$s->saveRecipe($v,$this->actor(1));$this->receiveIngredient(1,'3');
        $cart=$this->cart();$this->denied(fn()=>app(TakeawayService::class)->quote($cart,$this->actor()),422);
        $cart['items'][0]['quantity_mode']='weight';$cart['items'][0]['quantity']='0.125';$sale=app(TakeawayService::class);$old=$sale->quote($cart,$this->actor());
        $v['expected_revision']=1;$v['idempotency_key']=$this->key(91);$v['components'][0]['quantity']='900';$s->saveRecipe($v,$this->actor(1));
        $this->denied(fn()=>$sale->checkout($cart+['quote_hash'=>$old['quote_hash'],'idempotency_key'=>$this->key(101),'payment_method'=>'cash','cash_received'=>'100.00'],$this->actor()),409);
        $this->assertSame(3000000,$this->ingredientBalance(1));$this->pay($cart);$this->assertSame(2887500,$this->ingredientBalance(1));
    }
    public function test_failed_sale_rolls_back_all_ingredients_and_unconfigured_sales_are_explicitly_audited(): void
    {
        $s=$this->inventory();[$sale]=$this->pay($this->cart());$this->assertSame(0,DB::table('branch_inventory_movements')->count());
        $this->assertCount(1,$s->listing(['branch'=>'f:100'],$this->actor())['unmapped_sales']);
        $s->saveRecipe($this->recipePayload(),$this->actor(1));$this->receiveIngredient(1,'1');DB::table('takeaway_tills')->where('branch','f:100')->update(['balance_cents'=>100000000000]);
        $this->denied(fn()=>$this->pay($this->cart(),101),422);$this->assertSame(1000000,$this->ingredientBalance(1));$this->assertSame(1,DB::table('branch_inventory_movements')->count());$this->assertSame(1,DB::table('branch_recipe_sales')->count());
    }
}
