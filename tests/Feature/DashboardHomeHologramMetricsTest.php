<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{HomeHologramMetrics, OrderBoardService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class DashboardHomeHologramMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array',
            'app.key'=>'base64:'.base64_encode(str_repeat('h', 32)), 'app.timezone'=>'Africa/Cairo']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->string('app_scope')->nullable(); $t->string('status')->default('accepted'); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('name');
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type')->default('current'); $t->string('status')->nullable(); $t->string('payment_type')->default('cash');
            $t->string('source_app')->nullable(); $t->string('accepted_notify')->nullable(); $t->string('delegate_from_out')->nullable(); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('user_id'); $t->string('status'); $t->string('transaction_id')->nullable();
        });
        Schema::create('pos_service_tickets', function (Blueprint $t) {
            $t->id(); $t->string('branch'); $t->string('channel'); $t->string('status'); $t->string('payment_status')->default('unpaid');
            $t->integer('revision')->default(1); $t->string('customer_name')->nullable(); $t->string('customer_phone')->nullable();
            $t->string('address')->nullable(); $t->string('area')->nullable(); $t->string('notes')->nullable(); $t->text('quote_snapshot')->nullable();
            $t->integer('last_kitchen_id')->nullable(); $t->timestamp('bill_issued_at')->nullable(); $t->integer('bill_issued_revision')->nullable(); $t->timestamps();
        });
        Schema::create('web_storefront_orders', function (Blueprint $t) {
            $t->id(); $t->integer('ticket_id'); $t->integer('branch_id'); $t->timestamp('created_at');
        });
        Schema::create('takeaway_orders', function (Blueprint $t) {
            $t->id(); $t->string('branch'); $t->string('channel')->default('takeaway'); $t->boolean('payment_confirmed')->default(true);
            $t->string('status')->nullable(); $t->string('refund_status')->nullable();
        });
        foreach (['branch_expenses', 'branch_employees', 'branch_shift_closings'] as $table) Schema::create($table, function (Blueprint $t) use ($table) {
            $t->id(); $t->string('branch');
            if ($table === 'branch_expenses') { $t->string('status'); $t->date('occurred_on')->nullable(); }
        });
        Schema::create('crm_customers', fn (Blueprint $t) => $t->id());
        Schema::create('crm_customer_sources', function (Blueprint $t) { $t->id(); $t->integer('customer_id'); $t->string('branch'); });
        Schema::create('stock_ingredients', function (Blueprint $t) { $t->id(); $t->string('unit'); });
        Schema::create('branch_inventory', function (Blueprint $t) { $t->id(); $t->string('branch'); $t->integer('ingredient_id'); $t->bigInteger('quantity_units'); });
        Schema::create('branch_stock_minimums', function (Blueprint $t) {
            $t->id(); $t->string('branch'); $t->string('stock_type'); $t->integer('stock_item_id'); $t->string('unit'); $t->bigInteger('minimum_units')->nullable();
        });
        Schema::create('resturant_products', function (Blueprint $t) { $t->id(); $t->integer('resturant_id'); });
        Schema::create('branch_stock', function (Blueprint $t) { $t->id(); $t->string('branch'); $t->integer('product_id'); $t->string('unit'); $t->bigInteger('quantity_units'); });
        DB::table('users')->insert([
            ['id'=>1, 'name'=>'Owner', 'account_type'=>'admin', 'owner_resturant_id'=>null],
            ['id'=>10, 'name'=>'Branch A', 'account_type'=>'vendor', 'owner_resturant_id'=>null],
            ['id'=>20, 'name'=>'Branch B', 'account_type'=>'vendor', 'owner_resturant_id'=>null],
            ['id'=>30, 'name'=>'Independent owner', 'account_type'=>'resturant_owner', 'owner_resturant_id'=>100],
            ['id'=>40, 'name'=>'Branch admin', 'account_type'=>'admin', 'owner_resturant_id'=>100],
        ]);
        DB::table('resturants')->insert([
            ['id'=>100, 'user_id'=>10, 'parent_id'=>null, 'name'=>'A'],
            ['id'=>101, 'user_id'=>20, 'parent_id'=>100, 'name'=>'A child'],
            ['id'=>200, 'user_id'=>20, 'parent_id'=>null, 'name'=>'Foreign'],
        ]);
    }

    private function actor(int $id): User { return User::withoutGlobalScopes()->findOrFail($id); }
    private function cards(int $id): array
    {
        $links = array_fill_keys(['ch-app', 'ch-takeaway', 'ch-phone', 'ch-dine', 'expenses', 'employees', 'customers', 'stock', 'drawer'], '/module');
        return collect(app(HomeHologramMetrics::class)->data($this->actor($id), $links)['cards'])->keyBy('key')->all();
    }
    private function order(int $id, int $branch, ?string $status = 'pending', array $extra = []): void
    {
        DB::table('orders')->insert($extra + ['id'=>$id, 'resturant_id'=>$branch, 'user_id'=>900, 'status'=>$status,
            'created_at'=>'2020-01-01 10:00:00', 'updated_at'=>'2020-01-01 10:00:00']);
    }
    private function ticket(int $id, string $branch, string $channel = 'phone', string $status = 'new', string $payment = 'unpaid'): void
    {
        DB::table('pos_service_tickets')->insert(['id'=>$id, 'branch'=>$branch, 'channel'=>$channel, 'status'=>$status,
            'payment_status'=>$payment, 'created_at'=>'2020-01-01 10:00:00', 'updated_at'=>'2020-01-01 10:00:00']);
    }

    public function test_lifetime_order_counts_respect_persisted_branch_and_owner_tree(): void
    {
        $this->order(1, 100); $this->order(2, 101); $this->order(3, 200);
        $this->order(4, 100, 'completed'); $this->order(5, 101, 'completed'); $this->order(6, 200, 'completed');
        $this->order(7, 100, 'cancelled'); $this->order(8, 100, 'pending', ['type'=>'shipping']);
        $this->order(9, 100, 'pending', ['source_app'=>'go_customer']);
        foreach (['f:100', 'f:101', 'f:200'] as $branch) DB::table('takeaway_orders')->insert(['branch'=>$branch]);
        DB::table('takeaway_orders')->insert(['branch'=>'f:100', 'payment_confirmed'=>false]);
        DB::table('takeaway_orders')->insert(['branch'=>'f:100', 'refund_status'=>'refunded']);
        DB::table('takeaway_orders')->insert(['branch'=>'f:100', 'channel'=>'phone']);
        $branch = $this->cards(10); $this->assertSame(1, $branch['ch-app']['count']);
        $this->assertNull($branch['ch-app']['secondary_count']); $this->assertSame(1, $branch['ch-takeaway']['count']);
        $owner = $this->cards(30); $this->assertSame(2, $owner['ch-app']['count']); $this->assertSame(3, $owner['ch-app']['secondary_count']);
        $this->assertSame(2, $owner['ch-takeaway']['count']);
        $central = $this->cards(1); $this->assertSame(3, $central['ch-app']['count']); $this->assertSame(4, $central['ch-app']['secondary_count']);
        $this->assertSame(1, $this->cards(40)['ch-app']['count']);
        $spoof = $this->actor(10); $spoof->account_type = 'admin';
        $data = app(HomeHologramMetrics::class)->data($spoof, ['ch-app'=>'/app']);
        $this->assertSame('branch', $data['scope']); $this->assertSame(1, $data['cards'][0]['count']);
    }

    public function test_website_membership_is_not_conflated_with_branch_phone_orders(): void
    {
        $this->ticket(1, 'f:100'); $this->ticket(2, 'f:100'); $this->ticket(3, 'f:100');
        $this->ticket(4, 'f:100', 'phone', 'finished', 'unpaid'); $this->ticket(5, 'f:100', 'dine', 'awaiting_bill');
        $this->ticket(6, 'f:100', 'dine', 'paid', 'paid'); $this->ticket(7, 'f:200');
        DB::table('web_storefront_orders')->insert([
            ['ticket_id'=>1, 'branch_id'=>100, 'created_at'=>'2020-01-01 10:00:01'],
            ['ticket_id'=>2, 'branch_id'=>100, 'created_at'=>'2019-01-01 10:00:01'], // Stale id reuse must not attach.
            ['ticket_id'=>3, 'branch_id'=>200, 'created_at'=>'2020-01-01 10:00:01'], // Wrong branch must not attach.
        ]);
        $cards = $this->cards(10);
        $this->assertSame(1, $cards['ch-app']['count']); $this->assertSame(3, $cards['ch-phone']['count']);
        $this->assertSame(1, $cards['ch-dine']['count']);
        DB::table('pos_service_tickets')->where('id', 1)->update(['payment_status'=>'paid', 'status'=>'finished']);
        $central = $this->cards(1); $this->assertSame(0, $central['ch-app']['count']); $this->assertSame(1, $central['ch-app']['secondary_count']);
    }

    public function test_confirmed_online_payments_count_and_cancelled_failed_payments_do_not(): void
    {
        $this->order(1, 100, null, ['payment_type'=>'online']); $this->order(2, 100, null, ['payment_type'=>'online']);
        DB::table('payments')->insert([
            ['id'=>1, 'order_id'=>1, 'user_id'=>900, 'status'=>'paid', 'transaction_id'=>'ok'],
            ['id'=>2, 'order_id'=>2, 'user_id'=>900, 'status'=>'failed', 'transaction_id'=>'failed'],
        ]);
        $this->assertSame(1, $this->cards(10)['ch-app']['count']);
    }

    public function test_pending_expenses_employees_shared_directory_and_unavailable_are_explicit(): void
    {
        foreach (['pending', 'approved', 'rejected'] as $status) DB::table('branch_expenses')->insert(['branch'=>'f:100', 'status'=>$status]);
        DB::table('branch_expenses')->insert(['branch'=>'f:200', 'status'=>'pending']);
        DB::table('branch_expenses')->where('branch', 'f:100')->where('status', 'pending')->update(['occurred_on'=>'2020-01-01']);
        DB::table('branch_employees')->insert([['branch'=>'f:100'], ['branch'=>'f:200']]);
        DB::table('crm_customers')->insert([['id'=>1], ['id'=>2], ['id'=>3]]);
        $cards = $this->cards(10);
        $this->assertSame(1, $cards['pending-expenses']['count']); $this->assertTrue($cards['pending-expenses']['warning']);
        $this->assertStringContainsString('from=2020-01-01', $cards['pending-expenses']['url']);
        $this->assertSame(1, $cards['expenses']['count']); $this->assertSame(1, $cards['employees']['count']);
        $this->assertSame(3, $cards['customers']['count']); // Matches deliberately shared directory reads.
        $limited = app(HomeHologramMetrics::class)->data($this->actor(10), ['ch-takeaway'=>'/takeaway']);
        $this->assertCount(1, $limited['cards']);
        Schema::drop('branch_employees');
        $missing = $this->cards(10)['employees']; $this->assertFalse($missing['available']); $this->assertNull($missing['count']);
    }

    public function test_low_stock_uses_configured_units_and_any_authorized_branch_without_netting(): void
    {
        DB::table('stock_ingredients')->insert([['id'=>1, 'unit'=>'kg'], ['id'=>2, 'unit'=>'kg']]);
        DB::table('branch_inventory')->insert([
            ['branch'=>'f:100', 'ingredient_id'=>1, 'quantity_units'=>500000],
            ['branch'=>'f:101', 'ingredient_id'=>1, 'quantity_units'=>10000000],
        ]);
        DB::table('branch_stock_minimums')->insert([
            ['branch'=>'f:100', 'stock_type'=>'ingredient', 'stock_item_id'=>1, 'unit'=>'kg', 'minimum_units'=>500000],
            ['branch'=>'f:101', 'stock_type'=>'ingredient', 'stock_item_id'=>1, 'unit'=>'kg', 'minimum_units'=>1000000],
            ['branch'=>'f:100', 'stock_type'=>'ingredient', 'stock_item_id'=>2, 'unit'=>'piece', 'minimum_units'=>99999999],
            ['branch'=>'f:200', 'stock_type'=>'ingredient', 'stock_item_id'=>2, 'unit'=>'kg', 'minimum_units'=>1000000],
        ]);
        $stock = $this->cards(30)['stock'];
        $this->assertSame(2, $stock['count']); $this->assertSame(1, $stock['secondary_count']); $this->assertTrue($stock['warning']);
        $this->assertSame(1, $stock['low_stock_branches']);
        $this->assertSame(2, $this->cards(1)['stock']['secondary_count']);
        DB::table('branch_stock_minimums')->where('branch', 'f:100')->where('stock_item_id', 2)->update(['unit'=>'kg', 'minimum_units'=>0]);
        $this->assertSame(2, $this->cards(10)['stock']['secondary_count']); // Missing recorded balance is zero, same as goods screen.
    }

    public function test_only_primary_owner_receives_lazy_currency_card_and_other_admin_keeps_shift_card(): void
    {
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables)->up();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('users')->insert(['id'=>50, 'name'=>'Central admin', 'account_type'=>'admin']);
        $permission = \Spatie\Permission\Models\Permission::create(['name'=>'order-list', 'guard_name'=>'admin']);
        DB::table('model_has_permissions')->insert(['permission_id'=>$permission->id, 'model_type'=>User::class, 'model_id'=>50]);
        $shifts = \Mockery::mock(\App\Services\Dashboard\BranchShiftClosing::class);
        $shifts->shouldNotReceive('ownerBalances');
        $this->app->instance(\App\Services\Dashboard\BranchShiftClosing::class, $shifts);
        $links = ['drawer'=>route('branch-shifts.index')];
        $owner = app(HomeHologramMetrics::class)->data($this->actor(1), $links)['cards'][0];
        $this->assertSame('رصيد الفروع', $owner['label']);
        $this->assertSame('currency', $owner['value_type']); $this->assertSame('EGP', $owner['currency']);
        $this->assertNull($owner['amount_cents']); $this->assertNull($owner['count']);
        $this->assertTrue($owner['loading']); $this->assertFalse($owner['available']);
        $this->assertSame('owner-balances', $owner['action']); $this->assertSame('all', $owner['balance_scope']);
        $this->assertSame(['f:100', 'f:101', 'f:200'], $owner['balance_branches']);
        $this->assertSame(route('dashboard.overview', ['section'=>'drawers']), $owner['balance_url']);
        $this->assertSame(route('admin_dash').'#ho-drawers-title', $owner['url']);
        $admin = app(HomeHologramMetrics::class)->data($this->actor(50), $links)['cards'][0];
        $this->assertSame('تقفيل الوردية', $admin['label']); $this->assertSame(3, $admin['count']);
        $this->assertSame(route('branch-shifts.index').'?branch=all', $admin['url']);
        foreach (['value_type', 'amount_cents', 'currency', 'loading', 'action', 'balance_url', 'balance_branches'] as $key) {
            $this->assertArrayNotHasKey($key, $admin);
        }
        // Isolated owner databases also use the id-1 admin identity. The
        // central-platform-only Runtime::primary flag deliberately excludes it.
        $this->app->instance('owner.dashboard.runtime', ['prefix'=>'fixture_owner']);
        $tenant = app(HomeHologramMetrics::class)->data($this->actor(1), $links)['cards'][0];
        $this->assertSame('رصيد الفروع', $tenant['label']); $this->assertSame('currency', $tenant['value_type']);
    }
}
