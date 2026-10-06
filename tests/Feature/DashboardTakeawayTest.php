<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayCatalog;
use App\Services\Dashboard\TakeawayService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardTakeawayTest extends TestCase
{
    private const OPTION = '00000000-0000-4000-8000-000000000050';
    private string $testConnection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();
        $this->testConnection = env('TAKEAWAY_TEST_CONNECTION', 'sqlite');
        if (!in_array($this->testConnection, ['sqlite', 'mysql'], true)) throw new \RuntimeException('Unsupported takeaway test database connection.');
        config(['app.key'=>'base64:'.base64_encode(str_repeat('t', 32)), 'app.timezone'=>'Africa/Cairo',
            'database.default'=>$this->testConnection, 'cache.default'=>'array',
            'filesystems.disks.public.url'=>'https://assets.example.test/storage']);
        if ($this->testConnection === 'sqlite') config(['database.connections.sqlite.database'=>':memory:']);
        else {
            // Explicit opt-in and a dedicated database are required before dropping fixtures.
            if (config('database.connections.mysql.database') !== 'takeaway_test') throw new \RuntimeException('MySQL takeaway tests require the dedicated takeaway_test database.');
        }
        DB::purge($this->testConnection); Schema::clearResolvedInstance('db.schema');
        if ($this->testConnection === 'mysql') $this->dropFixtures();
        Carbon::setTestNow(Carbon::parse('2026-10-03 14:00:00', 'Africa/Cairo'));
        Schema::create('users', function (Blueprint $t) {
            $t->id(); foreach (['name','account_type','app_scope','status'] as $field) $t->string($field);
            $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->decimal('balance',14,2)->default(250); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name'); $t->string('address')->default('عنوان الفرع'); $t->string('phone')->default('01000000000');
            $t->decimal('service_fees',6,2)->default(90); $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) {$t->id(); $t->string('name_ar'); $t->string('name_en');});
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id'); $t->string('product_name'); $t->decimal('product_price',14,2);
            $t->text('price'); $t->string('status'); $t->timestamps();
        });
        Schema::create('product_features', function (Blueprint $t) {$t->id(); $t->unsignedBigInteger('product_id'); $t->string('name');});
        Schema::create('pending_vendors', function (Blueprint $t) {$t->id(); $t->string('profession_key'); $t->string('status');});
        Schema::create('settings', function (Blueprint $t) {$t->id(); $t->string('group'); $t->string('name'); $t->text('payload');});
        Schema::create('wallets', function (Blueprint $t) {$t->id(); $t->decimal('amount',14,2);});
        Schema::create('orders', function (Blueprint $t) {$t->id(); $t->string('status');});
        Schema::create('carts', function (Blueprint $t) {$t->id(); $t->unsignedBigInteger('order_id');});
        Schema::create('order_board_clocks', function (Blueprint $t) {$t->id(); $t->unsignedBigInteger('order_id');});
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php'); (new \CreateGoStoreCatalog)->up();
        require_once database_path('migrations/2026_10_03_140000_create_takeaway_pos.php'); (new \CreateTakeawayPos)->up();
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php'); (new \CreatePermissionTables)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['order-list','order-create','order-edit'] as $name) Permission::create(['name'=>$name,'guard_name'=>'admin']);
        $users = [
            [1,'Root','admin','fasakhansta',null,null,'accepted'], [2,'Read admin','admin','fasakhansta',null,null,'accepted'],
            [3,'Write admin','admin','fasakhansta',null,null,'accepted'], [4,'Cashier','admin','fasakhansta',100,null,'accepted'],
            [5,'Branch manager','admin','fasakhansta',100,null,'accepted'], [6,'No grant admin','admin','fasakhansta',null,null,'accepted'],
            [10,'Vendor','vendor','fasakhansta',null,null,'accepted'], [11,'Other vendor','vendor','fasakhansta',null,null,'accepted'],
            [12,'Owner','resturant_owner','fasakhansta',100,null,'accepted'], [13,'Unlinked','vendor','fasakhansta',null,null,'accepted'],
            [20,'Customer','user','go',null,null,'accepted'], [21,'Courier','delegate','fasakhansta',null,null,'accepted'],
            [30,'Go owner','vendor','go_partner',null,null,'accepted'], [31,'Other Go owner','vendor','go_partner',null,null,'accepted'],
            [32,'Unapproved','vendor','go_partner',null,null,'pending'], [33,'Approved store role','delegate','go_partner',null,1,'accepted'],
            [34,'Professional','delegate','go_partner',null,2,'accepted'],
        ];
        foreach ($users as [$id,$name,$type,$scope,$owner,$pending,$status]) DB::table('users')->insert([
            'id'=>$id,'name'=>$name,'account_type'=>$type,'app_scope'=>$scope,'owner_resturant_id'=>$owner,'pending_vendor_id'=>$pending,'status'=>$status,
        ]);
        foreach ([2=>['order-list'],3=>['order-list','order-create'],5=>['order-edit']] as $id=>$names) foreach ($names as $name) {
            DB::table('model_has_permissions')->insert(['permission_id'=>Permission::where('name',$name)->value('id'),'model_type'=>User::class,'model_id'=>$id]);
        }
        foreach ([[100,10,null],[101,11,null],[102,11,100],[103,11,102],[104,10,null]] as [$id,$user,$parent]) DB::table('resturants')->insert([
            'id'=>$id,'user_id'=>$user,'parent_id'=>$parent,'name'=>'فرع '.$id,
        ]);
        DB::table('categories')->insert([['id'=>1,'name_ar'=>'أسماك','name_en'=>'Fish'],['id'=>2,'name_ar'=>'غير مصرح','name_en'=>'Foreign']]);
        foreach ([[1,100,'12.50','show',501,1],[2,100,'0.01','show',501,1],[3,101,'90.00','show',502,2],
            [4,102,'10.00','show',501,1],[5,100,'5.00','hide',501,1],[6,100,'-1.00','show',501,1]] as [$id,$branch,$price,$status,$product,$category]) {
            DB::table('resturant_products')->insert(['id'=>$id,'resturant_id'=>$branch,'product_id'=>$product,'category_id'=>$category,
                'product_name'=>'صنف '.$id,'product_price'=>$price,'status'=>$status,
                'price'=>'{"extra_clean":"2.00","extra_vacuim":"3.00","extra_large":"5.00"}','created_at'=>now(),'updated_at'=>now()]);
        }
        foreach ([[1001,501,'half'],[1002,501,'quarter'],[1003,501,'large'],[1004,502,'large'],[1005,501,'foreign']] as [$id,$product,$name]) {
            DB::table('product_features')->insert(['id'=>$id,'product_id'=>$product,'name'=>$name]);
        }
        DB::table('pending_vendors')->insert([['id'=>1,'profession_key'=>'store_owner','status'=>'accepted'],['id'=>2,'profession_key'=>'plumber','status'=>'accepted']]);
        foreach ([30,31,32,33,34] as $user) DB::table('go_stores')->insert(['user_id'=>$user,'name'=>'متجر '.$user,'kind'=>'grocery','address'=>'العنوان','created_at'=>now(),'updated_at'=>now()]);
        foreach ([[70,30],[71,31],[72,33]] as [$id,$user]) DB::table('go_store_products')->insert([
            'id'=>$id,'user_id'=>$user,'request_key'=>$this->key($id),'name'=>'أرز '.$id,'unit'=>'كيلو','price_cents'=>1200,
            'image_path'=>'go-stores/'.$user.'/rice.jpg','available'=>true,
            'options'=>json_encode([['id'=>self::OPTION,'label'=>'عبوة كبيرة','price_cents'=>1800]]),'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('settings')->insert([['group'=>'general','name'=>'tax','payload'=>'99'],['group'=>'general','name'=>'service_fees','payload'=>'99'],['group'=>'general','name'=>'app_balance','payload'=>'999']]);
        DB::table('wallets')->insert(['amount'=>'25.00']); DB::table('orders')->insert(['status'=>'accepted']);
        DB::table('carts')->insert(['order_id'=>1]); DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->testConnection === 'mysql' && config('database.connections.mysql.database') === 'takeaway_test') {
            $this->dropFixtures(); DB::disconnect('mysql');
        }
        parent::tearDown();
    }

    private function dropFixtures(): void
    {
        // Child tables precede their parents, including the real POS foreign keys.
        foreach (['takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills',
            'model_has_roles','model_has_permissions','role_has_permissions','permissions','roles',
            'go_store_products','go_stores','order_board_clocks','carts','orders','wallets','settings',
            'pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table) Schema::dropIfExists($table);
    }
    private function actor(int $id = 10): User { return User::withoutGlobalScopes()->findOrFail($id); }
    private function service(): TakeawayService { return app(TakeawayService::class); }

    public function test_callcenter_branch_directory_uses_real_accounts_and_branch_scope(): void
    {
        $access = app(TakeawayAccess::class);
        $controller = app(\App\Http\Controllers\Dashboard\BranchOrdersController::class);
        $this->actingAs($this->actor(2), 'admin');
        $branches = collect($controller->index($access)->getData()['branches'])->keyBy('value');
        $this->assertTrue($branches->has('f:100'));
        $this->assertTrue($branches->has('f:101'));
        $this->assertEqualsCanonicalizing([4,5,10], array_column($branches['f:100']['accounts'], 'id'));
        $this->assertTrue($branches['f:100']['has_receiver']);
        $this->assertFalse($branches['f:101']['has_receiver']);
        $this->assertNotContains(4, array_column($branches['f:101']['accounts'], 'id'));
        $this->actingAs($this->actor(4), 'admin');
        $this->assertSame(['f:100'], array_column($controller->index($access)->getData()['branches'], 'value'));
        $this->actingAs($this->actor(6), 'admin');
        $this->denied(fn () => $controller->index($access), 403);
    }
    private function key(int $id = 1): string { return sprintf('00000000-0000-4000-8000-%012d', $id); }
    private function cart(string $branch = 'f:100', int $product = 1, string $quantity = '1.000', string $mode = 'piece'): array
    {
        return ['branch'=>$branch,'items'=>[['product_id'=>$product,'quantity'=>$quantity,'quantity_mode'=>$mode]],'discount'=>'0.00'];
    }
    private function payment(array $cart, int $id = 1, string $method = 'cash', int $actor = 10): array
    {
        $quote = $this->service()->quote($cart,$this->actor($actor));
        return $cart + ['idempotency_key'=>$this->key($id),'quote_hash'=>$quote['quote_hash'],'payment_method'=>$method,
            'cash_received'=>'100.00','payment_confirmed'=>true,'payment_reference'=>'Receipt 100','notes'=>'ملاحظة الفاتورة'];
    }
    private function denied(callable $call, int $status): void
    {
        try { $call(); $this->fail('Expected HTTP '.$status); }
        catch (HttpException $error) { $this->assertSame($status,$error->getStatusCode()); }
    }
    private function invalid(callable $call): void
    {
        try { $call(); $this->fail('Expected validation failure'); }
        catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
    }

    public function test_scope_uses_persisted_branch_and_store_relationships_without_session_widening(): void
    {
        session(['id_user'=>11]); $access = app(TakeawayAccess::class);
        $this->assertSame(['f:100','f:104'],array_column($access->branches($this->actor(10)),'value'));
        $this->assertSame(['f:100','f:102'],array_column($access->branches($this->actor(12)),'value'));
        $this->assertSame(['f:100'],array_column($access->branches($this->actor(4)),'value'));
        $this->assertSame(['gs:30'],array_column($access->branches($this->actor(30)),'value'));
        $this->assertSame(['gs:33'],array_column($access->branches($this->actor(33)),'value'));
        foreach ([6,13,20,21,32,34] as $id) $this->assertFalse($access->canAccess($this->actor($id)));
        $this->denied(fn()=> $access->branch('f:102',$this->actor(10)),404);
        $this->denied(fn()=> $access->branch('f:103',$this->actor(12)),404);
        $this->denied(fn()=> $access->branch('gs:31',$this->actor(30)),404);
    }

    public function test_catalog_returns_actual_prices_options_categories_and_scoped_pagination(): void
    {
        $this->actingAs($this->actor(),'admin');
        $data = $this->getJson(route('takeaway.catalog',['branch'=>'f:100','per_page'=>2]))->assertOk()->json();
        $this->assertSame(4,$data['pagination']['total']); $this->assertCount(2,$data['items']);
        $this->assertSame('12.50',$data['items'][0]['unit_price']);
        $this->assertSame('0.00',$data['policy']['tax_rate']); $this->assertFalse($data['policy']['can_discount']);
        $this->assertSame([1],array_column($data['categories'],'id'));
        $options = collect($data['items'][0]['options'])->keyBy('id');
        $this->assertSame('7.25',$options['f:1001:extra_clean']['price']);
        $this->assertSame('17.50',$options['f:1003:base']['price']);
        $this->assertFalse($options->has('f:1004:base'));
        $this->assertCount(0,$this->getJson(route('takeaway.catalog',['branch'=>'f:100','search'=>'صنف 3']))->assertOk()->json('items'));
        $this->getJson(route('takeaway.catalog',['branch'=>'f:101']))->assertNotFound();
    }

    public function test_callcenter_order_admin_can_sell_across_branches_without_financial_settings_grant(): void
    {
        $access = app(TakeawayAccess::class);
        $this->assertCount(8,$access->branches($this->actor(2)));
        $this->assertTrue($access->permissions($this->actor(2))['can_checkout']);
        $this->assertFalse($access->permissions($this->actor(2))['can_manage']);
        $payload = $this->payment($this->cart(),1,'cash',2);
        $this->assertSame('12.50',$this->service()->checkout($payload,$this->actor(2))['receipt']['total']);
        $this->assertTrue($access->permissions($this->actor(3))['can_checkout']);
        $this->assertTrue($access->permissions($this->actor(5))['can_manage']);
        $this->assertSame('12.50',$this->service()->checkout($payload,$this->actor(3))['receipt']['total']);
    }

    public function test_branch_bound_admin_is_exact_branch_cashier_without_manage_privilege(): void
    {
        $this->actingAs($this->actor(4),'admin');
        $this->getJson(route('takeaway.catalog',['branch'=>'f:100']))->assertOk()->assertJsonPath('permissions.can_checkout',true)->assertJsonPath('permissions.can_manage',false);
        $this->getJson(route('takeaway.catalog',['branch'=>'f:102']))->assertNotFound();
        $payload = $this->payment($this->cart(),1,'cash',4);
        $this->postJson(route('takeaway.checkout'),$payload)->assertOk();
        $this->postJson(route('takeaway.movements'),['branch'=>'f:100','direction'=>'in','amount'=>'10.00','note'=>'فتح الخزنة','expected_revision'=>2,'idempotency_key'=>$this->key(2)])->assertForbidden();
    }

    public function test_server_prices_and_integer_weight_rounding_determine_immutable_sale(): void
    {
        $cart = $this->cart('f:100',1,'0.750','weight'); $payload = $this->payment($cart);
        $payload['items'][0]['price']='0.01'; $payload['total']='0.01';
        $result = $this->service()->checkout($payload,$this->actor());
        $this->assertSame('9.38',$result['receipt']['total']); $this->assertSame('100.00',$result['receipt']['cash_received']);
        $this->assertSame('90.62',$result['receipt']['change']); $this->assertSame('9.38',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
        $line = DB::table('takeaway_order_items')->first(); $this->assertSame(750,(int)$line->quantity_millis);
        $this->assertSame(1250,(int)$line->unit_price_cents); $this->assertSame(938,(int)$line->total_cents);
        $this->assertSame(938,(int)DB::table('takeaway_till_entries')->value('amount_cents'));
    }

    public function test_half_cent_weight_rounds_once_per_line_without_float_drift(): void
    {
        $quote = $this->service()->quote($this->cart('f:100',2,'0.500','weight'),$this->actor());
        $this->assertSame('0.01',$quote['total']);
        $quote = $this->service()->quote($this->cart('f:100',2,'0.499','weight'),$this->actor());
        $this->assertSame('0.00',$quote['total']);
    }

    public function test_price_or_availability_change_after_quote_prevents_sale_and_cash_entry(): void
    {
        $payload = $this->payment($this->cart());
        DB::table('resturant_products')->where('id',1)->update(['product_price'=>'13.00']);
        $this->denied(fn()=> $this->service()->checkout($payload,$this->actor()),409);
        $payload = $this->payment($this->cart()); DB::table('resturant_products')->where('id',1)->update(['status'=>'hide']);
        $this->denied(fn()=> $this->service()->checkout($payload,$this->actor()),409);
        $this->assertSame(0,DB::table('takeaway_orders')->count()); $this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $this->assertSame('0.00',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
    }

    public function test_idempotent_sale_retry_returns_saved_receipt_before_live_menu_checks(): void
    {
        $payload = $this->payment($this->cart()); $first = $this->service()->checkout($payload,$this->actor());
        DB::table('resturant_products')->where('id',1)->delete();
        $second = $this->service()->checkout($payload,$this->actor());
        $this->assertTrue($second['replayed']); $this->assertSame($first['receipt'],$second['receipt']);
        $this->assertSame(1,DB::table('takeaway_orders')->count()); $this->assertSame(1,DB::table('takeaway_till_entries')->count());
        $this->assertSame('12.50',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
        $payload['notes']='غيرت الفاتورة'; $this->denied(fn()=> $this->service()->checkout($payload,$this->actor()),409);
    }

    public function test_revoked_actor_or_branch_relationship_cannot_replay_or_read_old_receipt(): void
    {
        $actor = $this->actor(); $payload = $this->payment($this->cart()); $sale = $this->service()->checkout($payload,$actor);
        DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
        $this->denied(fn()=> $this->service()->checkout($payload,$actor),404);
        $this->denied(fn()=> $this->service()->receipt($sale['receipt']['id'],$actor),404);
        DB::table('users')->where('id',10)->update(['account_type'=>'user']);
        $this->denied(fn()=> $this->service()->quote($this->cart('f:104'),$actor),403);
    }

    public function test_same_key_is_scoped_by_cashier_and_branch_and_recovery_does_not_leak(): void
    {
        $payload = $this->payment($this->cart()); $this->service()->checkout($payload,$this->actor());
        $this->service()->checkout($payload,$this->actor(4));
        $this->assertSame(2,DB::table('takeaway_orders')->count());
        $found = $this->service()->receipts(['branch'=>'f:100','idempotency_key'=>$this->key()],$this->actor());
        $this->assertSame(10,$found['receipt']['cashier']['id']);
        $found = $this->service()->receipts(['branch'=>'f:100','idempotency_key'=>$this->key()],$this->actor(12));
        $this->assertNull($found['receipt']);
        $this->denied(fn()=> $this->service()->receipts(['branch'=>'f:101','idempotency_key'=>$this->key()],$this->actor()),404);
    }

    public function test_new_takeaway_sales_accept_only_cash_without_mutating_accounts_on_rejected_methods(): void
    {
        $before=DB::table('users')->pluck('balance','id')->all();
        foreach(['card','mobile_wallet','wallet','other','mixed'] as $index=>$method){
            $payload=$this->payment($this->cart(),$index+1,$method);$payload['payment_confirmed']=true;
            $this->denied(fn()=>$this->service()->checkout($payload,$this->actor()),422);
        }
        $this->assertSame(['cash'],$this->service()->summary('f:100',$this->actor())['policy']['payment_methods']);
        $this->assertSame(0,DB::table('takeaway_orders')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $this->assertSame($before,DB::table('users')->pluck('balance','id')->all());$this->assertSame(1,DB::table('wallets')->count());
        $payload=$this->payment($this->cart(),20);$result=$this->service()->checkout($payload,$this->actor());
        $this->assertSame('cash',$result['receipt']['payment_method']);$this->assertTrue($this->service()->checkout($payload,$this->actor())['replayed']);
        $this->assertSame(1,DB::table('takeaway_orders')->count());
    }

    public function test_received_cash_below_total_and_wallet_alias_are_rejected(): void
    {
        $payload = $this->payment($this->cart()); $payload['cash_received']='12.49';
        $this->invalid(fn()=> $this->service()->checkout($payload,$this->actor()));
        $payload['payment_method']='wallet'; $payload['payment_confirmed']=true;
        $this->denied(fn()=>$this->service()->checkout($payload,$this->actor()),422);
        $this->assertSame(0,(int)DB::table('takeaway_tills')->value('balance_cents'));
    }

    public function test_historical_card_receipt_is_recoverable_without_a_second_sale(): void
    {
        $payload=$this->payment($this->cart(),40);$saved=$this->service()->checkout($payload,$this->actor());$id=$saved['receipt']['id'];
        $payload['payment_method']='card';$payload['payment_confirmed']=true;
        $canonical=$this->service()->canonicalCart($payload);
        $hash=hash('sha256',json_encode([$canonical,$payload['quote_hash'],'card',0,trim($payload['payment_reference']??''),trim($payload['notes']??'')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        DB::table('takeaway_orders')->where('id',$id)->update(['payment_method'=>'card','cash_received_cents'=>0,'change_cents'=>0,'request_hash'=>$hash]);
        $before=DB::table('takeaway_tills')->value('balance_cents');
        $replayed=$this->service()->checkout($payload,$this->actor());$this->assertTrue($replayed['replayed']);$this->assertSame('card',$replayed['receipt']['payment_method']);
        $this->assertSame($before,DB::table('takeaway_tills')->value('balance_cents'));$this->assertSame(1,DB::table('takeaway_orders')->count());
        $payload['idempotency_key']=$this->key(41);$this->denied(fn()=>$this->service()->checkout($payload,$this->actor()),422);
    }

    public function test_pos_tax_is_independent_configured_audited_and_quote_change_is_detected(): void
    {
        $payload = $this->payment($this->cart());
        $settings = ['branch'=>'f:100','tax_rate'=>'14.00','note'=>'ضريبة الفرع','expected_revision'=>1,'idempotency_key'=>$this->key(10)];
        $result = $this->service()->changeRegister($settings,$this->actor(12),true);
        $this->assertSame('14.00',$result['register']['tax_rate']);
        $this->assertSame(0,(int)DB::table('takeaway_till_entries')->value('amount_cents'));
        $this->denied(fn()=> $this->service()->checkout($payload,$this->actor()),409);
        $sale = $this->service()->checkout($this->payment($this->cart()),$this->actor());
        $this->assertSame('1.75',$sale['receipt']['tax']); $this->assertSame('14.25',$sale['receipt']['total']);
        $this->assertSame('0.00',$sale['receipt']['service']);
        $this->assertTrue($this->service()->changeRegister($settings,$this->actor(12),true)['replayed']);
        $this->assertSame(2,DB::table('takeaway_till_entries')->count());
    }

    public function test_discount_requires_manager_and_cannot_exceed_subtotal(): void
    {
        $cart = $this->cart(); $cart['discount']='2.50';
        $this->denied(fn()=> $this->service()->quote($cart,$this->actor()),403);
        $this->invalid(fn()=> $this->service()->quote($cart,$this->actor(12)));
        $cart['discount_reason']='خصم المالك';
        $quote = $this->service()->quote($cart,$this->actor(12)); $this->assertSame('10.00',$quote['total']);
        $sale = $this->service()->checkout($this->payment($cart,1,'cash',12),$this->actor(12));
        $this->assertSame('خصم المالك',$sale['receipt']['discount_reason']);
        $cart['discount']='12.51'; $this->denied(fn()=> $this->service()->quote($cart,$this->actor(12)),422);
        $cart['discount']='12.50'; $this->assertSame('0.00',$this->service()->quote($cart,$this->actor(12))['total']);
    }

    public function test_cash_movements_are_atomic_audited_replay_safe_and_cannot_overdraw(): void
    {
        $values = ['branch'=>'f:100','direction'=>'in','amount'=>'100.00','note'=>'عهدة بداية الوردية','expected_revision'=>1,'idempotency_key'=>$this->key(10)];
        $first = $this->service()->changeRegister($values,$this->actor(12),false); $this->assertSame('100.00',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
        $this->assertTrue($this->service()->changeRegister($values,$this->actor(12),false)['replayed']);
        $values['amount']='99.00'; $this->denied(fn()=> $this->service()->changeRegister($values,$this->actor(12),false),409);
        $values['idempotency_key']=$this->key(11); $values['direction']='out'; $values['amount']='100.01'; $values['expected_revision']=2;
        $this->denied(fn()=> $this->service()->changeRegister($values,$this->actor(12),false),409);
        $values['amount']='40.00'; $last = $this->service()->changeRegister($values,$this->actor(12),false);
        $this->assertSame('60.00',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents'))); $this->assertSame(2,DB::table('takeaway_till_entries')->count());
        $this->assertSame(-4000,(int)DB::table('takeaway_till_entries')->orderByDesc('id')->value('amount_cents'));
    }

    public function test_stale_register_revision_and_cross_kind_request_keys_cannot_double_move_cash(): void
    {
        $sale = $this->service()->checkout($this->payment($this->cart()),$this->actor(12));
        $values = ['branch'=>'f:100','direction'=>'in','amount'=>'1.00','note'=>'صحيح','expected_revision'=>1,'idempotency_key'=>$this->key(2)];
        $this->denied(fn()=> $this->service()->changeRegister($values,$this->actor(12),false),409);
        $values['expected_revision']=$sale['register']['revision']; $values['idempotency_key']=$this->key();
        $this->denied(fn()=> $this->service()->changeRegister($values,$this->actor(12),false),409);
        $this->assertSame('12.50',\App\Services\GoServices\Money::decimal((int)DB::table('takeaway_tills')->where('branch','f:100')->value('balance_cents')));
    }

    public function test_nonempty_cash_audit_note_and_exact_money_rates_are_required(): void
    {
        $base = ['branch'=>'f:100','direction'=>'in','amount'=>'1.00','note'=>'   ','expected_revision'=>1,'idempotency_key'=>$this->key(1)];
        $this->invalid(fn()=> $this->service()->changeRegister($base,$this->actor(12),false));
        $base['note']='سبب'; $base['amount']='1.001'; $this->invalid(fn()=> $this->service()->changeRegister($base,$this->actor(12),false));
        $base['tax_rate']='100.01'; $this->invalid(fn()=> $this->service()->changeRegister($base,$this->actor(12),true));
        $base['tax_rate']='0.125'; $this->invalid(fn()=> $this->service()->changeRegister($base,$this->actor(12),true));
        $this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }

    public function test_foreign_product_features_unconfigured_extras_and_hidden_items_are_rejected(): void
    {
        $cart = $this->cart(); $cart['items'][0]['feature_id']=1004;
        $this->denied(fn()=> $this->service()->quote($cart,$this->actor()),422);
        $cart['items'][0]['feature_id']=0; $cart['items'][0]['product_clean']='extra_clear';
        $this->denied(fn()=> $this->service()->quote($cart,$this->actor()),422);
        $this->denied(fn()=> $this->service()->quote($this->cart('f:100',3),$this->actor()),422);
        $this->denied(fn()=> $this->service()->quote($this->cart('f:100',5),$this->actor()),409);
        $this->invalid(fn()=> $this->service()->quote($this->cart('f:100',6),$this->actor()));
    }

    public function test_legacy_feature_and_cleaning_prices_are_calculated_using_existing_sequence(): void
    {
        $cart = $this->cart(); $cart['items'][0]['option_id']='f:1001:extra_clean';
        $quote = $this->service()->quote($cart,$this->actor()); $this->assertSame('7.25',$quote['total']);
        $this->assertSame('f:1001:extra_clean',$quote['items'][0]['option_id']);
        $cart['items'][0]['option_id']='f:1002:base'; $this->assertSame('3.13',$this->service()->quote($cart,$this->actor())['total']);
        $cart['items'][0]['option_id']='f:1003:extra_vacuim'; $this->assertSame('20.50',$this->service()->quote($cart,$this->actor())['total']);
    }

    public function test_go_options_replace_price_and_go_owner_uses_users_id_as_branch(): void
    {
        $this->actingAs($this->actor(30),'admin');
        $catalog = $this->getJson(route('takeaway.catalog',['branch'=>'gs:30']))->assertOk()->json();
        $this->assertSame('12.00',$catalog['items'][0]['price']); $this->assertSame('18.00',$catalog['items'][0]['options'][0]['price']);
        $this->assertStringContainsString('go-stores/30/rice.jpg',$catalog['items'][0]['image_url']);
        $cart = $this->cart('gs:30',70,'0.750','weight'); $cart['items'][0]['option_id']=self::OPTION;
        $sale = $this->service()->checkout($this->payment($cart,1,'cash',30),$this->actor(30));
        $this->assertSame('13.50',$sale['receipt']['total']); $this->assertSame('كيلو',$sale['receipt']['items'][0]['unit']);
        $this->assertSame('gs:30',$sale['register']['branch']);
        $cart['items'][0]['option_id']=$this->key(99); $this->denied(fn()=> $this->service()->quote($cart,$this->actor(30)),422);
    }

    public function test_weight_and_piece_quantity_boundaries_reject_ambiguous_or_oversized_input(): void
    {
        foreach (['0.000','-1','1.0001','1e2','1001','9999'] as $qty) $this->invalid(fn()=> $this->service()->quote($this->cart('f:100',1,$qty,'weight'),$this->actor()));
        $this->invalid(fn()=> $this->service()->quote($this->cart('f:100',1,'0.500','piece'),$this->actor()));
        $this->assertSame('0.01',$this->service()->quote($this->cart('f:100',1,'0.001','weight'),$this->actor())['total']);
        $this->assertSame('12500.00',$this->service()->quote($this->cart('f:100',1,'1000','piece'),$this->actor())['total']);
    }

    public function test_business_day_is_cairo_and_uncertain_post_recovery_survives_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 21:30:00','UTC')); // Cairo 00:30 next day.
        $payload = $this->payment($this->cart()); $sale = $this->service()->checkout($payload,$this->actor());
        $this->assertSame('2026-10-04',$sale['receipt']['business_date']);
        $this->assertSame('2026-10-04T00:30:00+03:00',$sale['receipt']['created_at']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:30:00','Africa/Cairo'));
        $daily = $this->service()->receipts(['branch'=>'f:100'],$this->actor()); $this->assertSame(0,$daily['today']['count']);
        $recovery = $this->service()->receipts(['branch'=>'f:100','idempotency_key'=>$this->key()],$this->actor());
        $this->assertSame($sale['receipt'],$recovery['receipt']);
    }

    public function test_sale_and_cash_entry_share_the_same_cairo_business_day_across_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 23:59:59','Africa/Cairo'));
        DB::listen(function ($query) {
            if (str_starts_with($query->sql,'insert into') && str_contains($query->sql,'takeaway_order_items')) Carbon::setTestNow(Carbon::parse('2026-10-04 00:00:00','Africa/Cairo'));
        });
        $sale = $this->service()->checkout($this->payment($this->cart()),$this->actor());
        $this->assertSame('2026-10-03',$sale['receipt']['business_date']);
        $this->assertSame($sale['receipt']['business_date'],DB::table('takeaway_till_entries')->value('business_date'));
    }

    public function test_receipt_remains_snapshot_after_branch_cashier_menu_and_tax_edits(): void
    {
        $sale = $this->service()->checkout($this->payment($this->cart()),$this->actor());
        DB::table('resturant_products')->where('id',1)->update(['product_name'=>'اسم جديد','product_price'=>'99.00']);
        DB::table('resturants')->where('id',100)->update(['name'=>'فرع جديد']); DB::table('users')->where('id',10)->update(['name'=>'كاشير جديد']);
        DB::table('takeaway_tills')->where('branch','f:100')->update(['tax_bps'=>9000]);
        $this->assertSame($sale['receipt'],$this->service()->receipt($sale['receipt']['id'],$this->actor()));
        $this->denied(fn()=> $this->service()->receipt($sale['receipt']['id'],$this->actor(11)),404);
    }

    public function test_http_routes_require_login_scope_and_reject_forged_checkout_price(): void
    {
        $this->get(route('takeaway.catalog',['branch'=>'f:100']))->assertRedirect();
        $this->actingAs($this->actor(20),'admin')->getJson(route('takeaway.catalog',['branch'=>'f:100']))->assertForbidden();
        $this->actingAs($this->actor(),'admin');
        $quote = $this->postJson(route('takeaway.quote'),$this->cart())->assertOk()->assertJsonPath('total','12.50')->json();
        $payload = $this->payment($this->cart()); $payload['quote_hash']=$quote['quote_hash']; $payload['total']='0.00';
        $this->postJson(route('takeaway.checkout'),$payload)->assertOk()->assertJsonPath('receipt.total','12.50');
        $response=$this->getJson(route('takeaway.till',['branch'=>'f:100']))->assertOk()->json();$this->assertArrayNotHasKey('balance',$response['register']);
        $this->getJson(route('takeaway.receipts',['branch'=>'f:101']))->assertNotFound();
    }

    public function test_listing_variants_does_not_issue_queries_per_feature_and_clean_combo(): void
    {
        DB::enableQueryLog();
        app(TakeawayCatalog::class)->listing(['branch'=>'f:100'],$this->actor());
        $featureQueries = array_filter(DB::getQueryLog(),fn($query)=>str_contains($query['query'],'product_features') && str_starts_with($query['query'],'select'));
        DB::disableQueryLog();
        $this->assertCount(1,$featureQueries);
    }

    public function test_print_endpoint_uses_scoped_immutable_receipt_and_embedded_print_marker(): void
    {
        $payload = $this->payment($this->cart()); $payload['notes']='<script>alert(1)</script>';
        $sale = $this->service()->checkout($payload,$this->actor());
        $this->actingAs($this->actor(),'admin');
        $embedded = $this->get($sale['receipt_url'].'?dashboard_print=1')->assertOk();
        $embedded->assertSee('data-dashboard-receipt="takeaway"',false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false);
        $embedded->assertDontSee("window.addEventListener('load'",false)->assertDontSee('<script>alert(1)</script>',false);
        $this->get($sale['receipt_url'])->assertOk()->assertSee("window.addEventListener('load'",false);
        $this->actingAs($this->actor(11),'admin')->get($sale['receipt_url'],['Accept'=>'application/json'])->assertNotFound();
    }
}

