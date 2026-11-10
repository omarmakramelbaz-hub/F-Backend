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
        // The fixtures' orders are made on this operating day (10:00 Cairo for the app, 10:00 UTC for the POS).
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2020-01-01 13:00:00', 'Africa/Cairo'));
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
            $t->string('status')->nullable(); $t->string('refund_status')->nullable(); $t->string('business_date')->nullable();
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

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
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

    public function test_only_the_primary_owner_sees_the_branches_drawer_balance_in_place_of_the_pending_expenses(): void
    {
        $reads = 0;
        $this->mock(\App\Services\Dashboard\HomeOverview::class, function ($mock) use (&$reads) {
            $mock->shouldReceive('drawers')->andReturnUsing(function (array $values, $actor) use (&$reads) {
                $reads++;
                $this->assertSame([1, []], [(int) $actor->id, $values], 'The home page\'s own drawers, all branches');
                return ['success'=>true, 'owner_drawer'=>['ready'=>true, 'loaded'=>true, 'total_cents'=>1114550, 'branches'=>[]]];
            });
        });
        $owner = $this->cards(1);
        $this->assertArrayNotHasKey('pending-expenses', $owner);
        $drawers = $owner['drawers'];
        $this->assertSame(['رصيد أدراج الفروع', 11146, 'جنيه', true, false, 'فرع'], [$drawers['label'], $drawers['count'], $drawers['count_label'],
            $drawers['available'], $drawers['warning'], $drawers['secondary_label']]);
        $this->assertGreaterThan(0, $drawers['secondary_count']);
        // It opens each branch's drawer balance on the home page itself, whatever the page's filters.
        $this->assertSame(route('admin_dash').'#branch-drawers', $drawers['url']);
        // The shift closing card is back to the branches to close.
        $this->assertSame(['فروع للتقفيل', null], [$owner['drawer']['count_label'], $owner['drawer']['secondary_count']]);
        $this->cards(1);
        $this->assertSame(1, $reads, 'The opening asks every 15 seconds; one reading serves a minute');
        // Every other account keeps the pending expenses, and its drawers are never read.
        foreach ([10, 20, 30, 40] as $id) {
            $cards = $this->cards($id);
            $this->assertArrayNotHasKey('drawers', $cards, 'account '.$id);
            $this->assertArrayHasKey('pending-expenses', $cards, 'account '.$id);
        }
        $this->assertSame(1, $reads);
    }

    public function test_the_owner_keeps_the_pending_expenses_when_the_drawers_cannot_be_read(): void
    {
        $this->mock(\App\Services\Dashboard\HomeOverview::class, function ($mock) {
            $mock->shouldReceive('drawers')->andReturn(['success'=>true, 'owner_drawer'=>['ready'=>false, 'loaded'=>true, 'total_cents'=>null, 'branches'=>[]]]);
        });
        $cards = $this->cards(1);
        $this->assertArrayNotHasKey('drawers', $cards);
        $this->assertArrayHasKey('pending-expenses', $cards);
        $this->assertSame(['فروع للتقفيل', null], [$cards['drawer']['count_label'], $cards['drawer']['secondary_count']]);
    }

    public function test_todays_order_counts_respect_persisted_branch_and_owner_tree(): void
    {
        $this->order(1, 100); $this->order(2, 101); $this->order(3, 200);
        $this->order(4, 100, 'completed'); $this->order(5, 101, 'completed'); $this->order(6, 200, 'completed');
        $this->order(7, 100, 'cancelled'); $this->order(8, 100, 'pending', ['type'=>'shipping']);
        $this->order(9, 100, 'pending', ['source_app'=>'go_customer']);
        $day = \App\Services\Dashboard\OperatingDay::date();
        foreach (['f:100', 'f:101', 'f:200'] as $branch) DB::table('takeaway_orders')->insert(['branch'=>$branch, 'business_date'=>$day]);
        DB::table('takeaway_orders')->insert(['branch'=>'f:100', 'payment_confirmed'=>false, 'business_date'=>$day]);
        DB::table('takeaway_orders')->insert(['branch'=>'f:100', 'refund_status'=>'refunded', 'business_date'=>$day]);
        DB::table('takeaway_orders')->insert(['branch'=>'f:100', 'channel'=>'phone', 'business_date'=>$day]);
        $branch = $this->cards(10); $this->assertSame(1, $branch['ch-app']['count']);
        $this->assertSame(2, $branch['ch-app']['secondary_count']); $this->assertSame('منتهية اليوم', $branch['ch-app']['secondary_label']); $this->assertSame(1, $branch['ch-takeaway']['count']);
        $owner = $this->cards(30); $this->assertSame(2, $owner['ch-app']['count']); $this->assertSame(3, $owner['ch-app']['secondary_count']);
        $this->assertSame(2, $owner['ch-takeaway']['count']);
        $central = $this->cards(1); $this->assertSame(3, $central['ch-app']['count']); $this->assertSame(4, $central['ch-app']['secondary_count']);
        $this->assertSame(1, $this->cards(40)['ch-app']['count']);
        $spoof = $this->actor(10); $spoof->account_type = 'admin';
        $data = app(HomeHologramMetrics::class)->data($spoof, ['ch-app'=>'/app']);
        $this->assertSame('branch', $data['scope']); $this->assertSame(1, $data['cards'][0]['count']);
    }

    public function test_delivery_dine_in_and_app_count_todays_orders_being_prepared_and_finished(): void
    {
        $yesterday = ['created_at'=>'2019-12-31 22:00:00', 'updated_at'=>'2019-12-31 22:00:00'];
        // App: open and finished today; yesterday's (still open, or finished) are not today's.
        $this->order(1, 100); $this->order(2, 100, 'completed'); $this->order(3, 101, 'completed');
        $this->order(4, 100, 'pending', $yesterday); $this->order(5, 100, 'completed', $yesterday);
        // Before 6 in the morning belongs to the previous working day.
        $this->order(6, 100, 'completed', ['created_at'=>'2020-01-01 05:30:00', 'updated_at'=>'2020-01-01 05:30:00']);
        // Delivery and dine-in: being prepared (unpaid) and finished (paid) today; cancelled is neither.
        $this->ticket(1, 'f:100'); $this->ticket(2, 'f:100', 'phone', 'finished', 'paid'); $this->ticket(3, 'f:100', 'phone', 'cancelled');
        $this->ticket(4, 'f:100', 'dine', 'open'); $this->ticket(5, 'f:100', 'dine', 'paid', 'paid'); $this->ticket(6, 'f:101', 'dine', 'paid', 'paid');
        DB::table('pos_service_tickets')->insert(['id'=>7, 'branch'=>'f:100', 'channel'=>'phone', 'status'=>'new', 'payment_status'=>'unpaid',
            'created_at'=>'2019-12-31 20:00:00', 'updated_at'=>'2019-12-31 20:00:00']);

        $branch = $this->cards(10);
        $this->assertSame([1, 1, 'في التجهيز اليوم', 'منتهية اليوم'], [$branch['ch-app']['count'], $branch['ch-app']['secondary_count'],
            $branch['ch-app']['count_label'], $branch['ch-app']['secondary_label']]);
        $this->assertSame([1, 1], [$branch['ch-phone']['count'], $branch['ch-phone']['secondary_count']]);
        $this->assertSame([1, 1, 'منتهية اليوم'], [$branch['ch-dine']['count'], $branch['ch-dine']['secondary_count'], $branch['ch-dine']['secondary_label']]);

        foreach ([1, 30] as $id) {
            $all = $this->cards($id);
            $this->assertSame([1, 2], [$all['ch-app']['count'], $all['ch-app']['secondary_count']], 'account '.$id);
            $this->assertSame([1, 2, 'منتهية اليوم · جميع الفروع'], [$all['ch-dine']['count'], $all['ch-dine']['secondary_count'], $all['ch-dine']['secondary_label']]);
        }
    }

    public function test_shubras_account_counts_its_stores_app_orders_too(): void
    {
        DB::table('users')->insert(['id'=>50, 'name'=>'Shubra', 'account_type'=>'vendor', 'owner_resturant_id'=>null]);
        DB::table('resturants')->insert([['id'=>301, 'user_id'=>50, 'parent_id'=>null, 'name'=>'Shubra'],
            ['id'=>310, 'user_id'=>60, 'parent_id'=>301, 'name'=>'Store']]);
        $this->order(1, 301); $this->order(2, 310); $this->order(3, 310, 'completed');
        $this->order(4, 310, 'pending', ['created_at'=>'2019-12-31 22:00:00', 'updated_at'=>'2019-12-31 22:00:00']);
        $card = $this->cards(50)['ch-app'];
        $this->assertSame([2, 1], [$card['count'], $card['secondary_count']]);
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

    public function test_takeaway_counts_the_operating_day_only(): void
    {
        $today = \App\Services\Dashboard\OperatingDay::date();
        DB::table('takeaway_orders')->insert([['branch'=>'f:100', 'business_date'=>$today], ['branch'=>'f:100', 'business_date'=>$today], ['branch'=>'f:100', 'business_date'=>'2019-12-30']]);
        $this->assertSame(2, $this->cards(10)['ch-takeaway']['count']);
    }

    public function test_pending_expenses_employees_shared_directory_and_unavailable_are_explicit(): void
    {
        foreach (['pending', 'approved', 'rejected'] as $status) DB::table('branch_expenses')->insert(['branch'=>'f:100', 'status'=>$status, 'occurred_on'=>\App\Services\Dashboard\OperatingDay::date()]);
        // An expense approved on an earlier day is not today's: the count starts again every operating day.
        DB::table('branch_expenses')->insert(['branch'=>'f:100', 'status'=>'approved', 'occurred_on'=>'2019-12-30']);
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
}
