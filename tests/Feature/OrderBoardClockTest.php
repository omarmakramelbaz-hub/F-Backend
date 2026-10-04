<?php

namespace Tests\Feature;

use App\Events\OrderStatusUpdated;
use App\Events\OrderUpdated;
use App\Events\UserUpdated;
use App\Http\Controllers\Api\V1\Vendor\OrderController as VendorOrders;
use App\Http\Controllers\Api\V1\Delegate\DelegateOrderController as DelegateOrders;
use App\Http\Controllers\Dashboard\OrderController as DashboardOrders;
use App\Models\GeneralSettings;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use App\Services\Dashboard\GoStoreBoardActions;
use App\Services\Dashboard\LegacyOrderCompletion;
use App\Services\Dashboard\OrderBoardClock;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderBoardClockTest extends TestCase
{
    private string $originalTimezone;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array',
            'app.timezone'=>'UTC', 'settings.cache.enabled'=>false]);
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        DB::purge('sqlite');
        Carbon::setTestNow(Carbon::parse('2026-10-03 06:00:00', 'UTC'));
        Http::fake(); Notification::fake();
        Event::fake([OrderStatusUpdated::class, OrderUpdated::class, UserUpdated::class,
            \App\Events\VendorUpdated::class, \App\Events\BalanceUpdated::class]);
        Schema::create('users', function (Blueprint $t) {
            $t->id(); foreach (['name','email','account_type','status','app_scope','connected'] as $f) $t->string($f)->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable(); $t->decimal('balance', 14, 2)->default(0);
            $t->decimal('delegate_fees', 6, 2)->nullable(); $t->dateTime('expiration_date')->nullable(); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->decimal('service_fees', 6, 2)->default(10); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); foreach (['user_id','resturant_id','delegate_id','coupon_wheel_id'] as $f) $t->unsignedBigInteger($f)->nullable();
            foreach (['type','status','payment_type','accepted_notify','delegate_from_out','transfer_price_by','reason','order_no'] as $f) $t->string($f)->nullable();
            foreach (['vendor_tax','user_tax','delivery_price'] as $f) $t->decimal($f, 14, 2)->default(0); $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->decimal('price', 14, 2); $t->decimal('qty', 10, 3)->default(1);
            $t->decimal('updated_total', 14, 2)->nullable(); $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id(); foreach (['order_id','from_user','to_user'] as $f) $t->unsignedBigInteger($f)->nullable();
            foreach (['status','payment','type'] as $f) $t->string($f); $t->decimal('amount', 14, 2);
            $t->string('transfer_reference')->nullable()->unique(); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('user_id'); $t->string('status')->nullable();
            $t->decimal('total_price', 14, 2)->default(0);
            $t->string('transaction_id')->nullable(); $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id(); foreach (['group','name'] as $f) $t->string($f); $t->text('payload'); $t->timestamps();
        });
        foreach ((new \ReflectionClass(GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== GeneralSettings::class || $property->isStatic()) continue;
            $value = $property->getType()->getName() === 'bool' ? false : ($property->getName() === 'app_balance' ? '1000.00' : '0');
            DB::table('settings')->insert(['group'=>'general', 'name'=>$property->getName(), 'payload'=>json_encode($value)]);
        }
        Schema::create('coupon_wheels', function (Blueprint $t) {
            $t->id(); $t->string('status')->nullable(); $t->date('start_date')->nullable(); $t->date('end_date')->nullable();
        });
        Schema::create('coupon_wheel_resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('coupon_wheel_id'); $t->unsignedBigInteger('resturant_id');
        });
        Schema::create('go_stores', function (Blueprint $t) {$t->id(); $t->unsignedBigInteger('user_id');});
        Schema::create('delegate_notifications', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('delegate_id'); $t->string('status')->nullable();
        });
        Schema::create('go_store_orders', function (Blueprint $t) {
            $t->id(); foreach (['customer_id','store_id','commission_cents','total_cents'] as $f) $t->unsignedBigInteger($f);
            foreach (['status','fulfillment','payment_method','payment_status'] as $f) $t->string($f);
            $t->unsignedInteger('revision')->default(1); $t->string('reason')->nullable(); $t->timestamps();
        });
        require_once database_path('migrations/2026_10_03_060000_create_order_board_clocks.php');
        (new \CreateOrderBoardClocks)->up();
        DB::table('users')->insert([
            ['id'=>1,'name'=>'Admin','account_type'=>'admin','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'0.00','delegate_fees'=>null],
            ['id'=>10,'name'=>'Restaurant','account_type'=>'vendor','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'500.00','delegate_fees'=>null],
            ['id'=>20,'name'=>'Customer','account_type'=>'user','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'200.00','delegate_fees'=>null],
            ['id'=>30,'name'=>'Courier','account_type'=>'delegate','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>'500.00','delegate_fees'=>'20.00'],
            ['id'=>40,'name'=>'Store','account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted','balance'=>'200.00','delegate_fees'=>null],
        ]);
        DB::table('resturants')->insert(['id'=>100,'user_id'=>10]);
        DB::table('go_stores')->insert(['id'=>3,'user_id'=>40]);
    }

    public function test_completed_app_orders_consume_tracked_stock_once_using_portion_weight(): void
    {
        require_once database_path('migrations/2026_10_04_190000_create_branch_stock.php');(new \CreateBranchStock)->up();
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id');$t->string('product_name');});
        Schema::create('product_features',function(Blueprint $t){$t->id();$t->unsignedBigInteger('product_id');$t->string('name');});
        Schema::table('carts',function(Blueprint $t){$t->unsignedBigInteger('resturant_product_id')->nullable();$t->unsignedBigInteger('product_feature')->nullable();});
        DB::table('resturant_products')->insert(['id'=>5,'resturant_id'=>100,'product_id'=>50,'product_name'=>'Fish']);
        DB::table('product_features')->insert(['id'=>6,'product_id'=>50,'name'=>'quarter']);
        DB::table('branch_stock')->insert(['branch'=>'f:100','product_id'=>5,'unit'=>'kg','quantity_units'=>10000000,'revision'=>1]);
        $order=$this->legacy();DB::table('carts')->where('order_id',$order->id)->update(['resturant_product_id'=>5,'product_feature'=>6,'qty'=>'3.000']);
        $this->assertSame(10000000,(int)DB::table('branch_stock')->value('quantity_units'));
        $this->assertTrue(app(LegacyOrderCompletion::class)->complete($order));
        $this->assertSame(9250000,(int)DB::table('branch_stock')->value('quantity_units'));
        $this->assertFalse(app(LegacyOrderCompletion::class)->complete($order));
        $this->assertSame(1,DB::table('branch_stock_movements')->count());
        $this->assertSame('app',DB::table('branch_stock_movements')->value('source_type'));
        $this->assertSame('completed',$order->fresh()->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function legacy(array $changes = [], ?float $updated = null): Order
    {
        $id = DB::table('orders')->insertGetId($changes + ['type'=>'current','status'=>'pending','accepted_notify'=>null,
            'payment_type'=>'cash','user_id'=>20,'resturant_id'=>100,'delegate_id'=>null,'delegate_from_out'=>null,
            'transfer_price_by'=>null,'vendor_tax'=>10,'user_tax'=>0,'delivery_price'=>30,'order_no'=>'R100-17000',
            'created_at'=>now(),'updated_at'=>now()]);
        DB::table('carts')->insert(['order_id'=>$id,'price'=>100,'qty'=>1,'updated_total'=>$updated]);
        return Order::withoutGlobalScopes()->findOrFail($id);
    }

    private function accept(Order $order): void
    {
        $order->update(['accepted_notify'=>'yes']);
        $this->assertSame(1, DB::table('order_board_clocks')->where('source','legacy')->where('order_id',$order->id)->count());
    }

    private function clock(): OrderBoardClock {return app(OrderBoardClock::class);}
    private function timeAt(string $time): void {Carbon::setTestNow(Carbon::parse('2026-10-03 '.$time, 'UTC'));}
    private function balance(int $id): float {return (float) DB::table('users')->where('id',$id)->value('balance');}
    private function appBalance(): float {return (float) json_decode(DB::table('settings')->where('name','app_balance')->value('payload'), true);}
    private function store(array $changes = []): int
    {
        return DB::table('go_store_orders')->insertGetId($changes + ['customer_id'=>20,'store_id'=>40,'status'=>'pending',
            'fulfillment'=>'delivery','revision'=>1,'total_cents'=>15000,'commission_cents'=>1000,
            'payment_method'=>'cash','payment_status'=>'cash_due','created_at'=>now(),'updated_at'=>now()]);
    }

    public function test_cash_exact_boundaries_are_15_and_90_minutes_from_first_acceptance(): void
    {
        $order = $this->legacy(); $this->accept($order);
        $this->timeAt('06:14:59'); $this->assertSame(0, $this->clock()->run()['courier']);
        $this->assertSame('pending',$order->fresh()->status);
        $this->timeAt('06:15:00'); $this->assertSame(1, $this->clock()->run()['courier']);
        $this->assertSame('accepted',$order->fresh()->status); $this->assertSame('in_resturant',$order->fresh()->delegate_from_out);
        $this->assertSame(500.0,$this->balance(10)); $this->assertSame(0,DB::table('wallets')->count());
        $this->timeAt('07:29:59'); $this->assertSame(0,$this->clock()->run()['completed']);
        $this->timeAt('07:30:00'); $this->assertSame(1,$this->clock()->run()['completed']);
        $this->assertSame('completed',$order->fresh()->status); $this->assertSame('vendor',$order->fresh()->transfer_price_by);
        $this->assertSame(490.0,$this->balance(10)); $this->assertSame(1010.0,$this->appBalance());
        $this->assertSame(1,DB::table('wallets')->count());
        $this->clock()->run(); $this->assertFalse(app(LegacyOrderCompletion::class)->complete($order));
        $this->assertSame(1,DB::table('wallets')->count()); $this->assertSame(490.0,$this->balance(10));
    }

    public function test_90_minute_catchup_settles_actual_assigned_cash_courier_once(): void
    {
        $order = $this->legacy(['delegate_id'=>30,'delegate_from_out'=>'out_resturant']); $this->accept($order);
        $this->timeAt('07:30:00'); $this->assertSame(1,$this->clock()->run()['completed']);
        $this->assertSame(30,(int)$order->fresh()->delegate_id); $this->assertSame('out_resturant',$order->fresh()->delegate_from_out);
        $this->assertSame(590.0,$this->balance(10)); $this->assertSame(394.0,$this->balance(30));
        $this->assertSame(1016.0,$this->appBalance()); $this->assertSame('delegate',$order->fresh()->transfer_price_by);
        $this->clock()->run(); $this->assertSame(2,DB::table('wallets')->count());
    }

    public function test_timer_preserves_external_delivery_selection_without_fabricating_a_courier(): void
    {
        $order = $this->legacy(['delegate_from_out'=>'out_resturant']); $this->accept($order);
        $this->timeAt('06:15:00'); $this->clock()->run();
        $this->assertSame('shipped',$order->fresh()->status); $this->assertSame('out_resturant',$order->fresh()->delegate_from_out);
        $this->assertNull($order->fresh()->delegate_id);
    }

    public function test_assignment_does_not_restart_acceptance_clock_and_manual_completion_wins(): void
    {
        $order = $this->legacy(); $this->accept($order);
        $this->timeAt('06:05:00'); $order->update(['status'=>'accepted','delegate_from_out'=>'in_resturant']);
        $this->assertSame('2026-10-03 06:00:00',DB::table('order_board_clocks')->value('accepted_at'));
        $this->timeAt('06:20:00'); $this->assertTrue(app(LegacyOrderCompletion::class)->complete($order));
        $this->timeAt('07:30:00'); $this->clock()->run();
        $this->assertSame(1,DB::table('wallets')->count()); $this->assertSame(490.0,$this->balance(10));
        $this->assertNotNull(DB::table('order_board_clocks')->value('closed_at'));
    }

    public function test_old_already_accepted_rows_are_never_backfilled_or_bulk_closed(): void
    {
        $order = $this->legacy(['accepted_notify'=>'yes','status'=>'accepted']);
        $this->timeAt('10:00:00'); $order->update(['delegate_from_out'=>'in_resturant']);
        (new \CreateOrderBoardClocks)->up(); $this->clock()->run();
        $this->assertSame(0,DB::table('order_board_clocks')->count()); $this->assertSame('accepted',$order->fresh()->status);
    }

    public function test_mobile_status_first_acceptance_starts_clock_and_cancellation_is_not_delivered(): void
    {
        $order = $this->legacy(); $order->update(['status'=>'accepted']);
        $this->assertSame(1,DB::table('order_board_clocks')->count());
        DB::table('orders')->where('id',$order->id)->update(['status'=>'cancelled']);
        $this->timeAt('07:30:00'); $this->clock()->run();
        $this->assertSame('cancelled',$order->fresh()->status); $this->assertSame(0,DB::table('wallets')->count());
    }

    /** @dataProvider electronicMethods */
    public function test_verified_legacy_electronic_payment_completes_and_old_gross_helper_cannot_double_credit(string $method): void
    {
        $order = $this->legacy(['payment_type'=>$method]);
        DB::table('payments')->insert(['order_id'=>$order->id,'user_id'=>20,'status'=>'1','transaction_id'=>'9001']);
        $this->accept($order); $this->timeAt('07:30:00');
        $this->assertSame(1,$this->clock()->run()['completed']);
        $this->assertSame('admin',$order->fresh()->transfer_price_by); $this->assertSame(620.0,$this->balance(10));
        app(DashboardOrders::class)->transferPrice($order->id);
        $this->assertSame(620.0,$this->balance(10)); $this->assertSame(1,DB::table('wallets')->count());
    }

    public static function electronicMethods(): array {return [['online'],['v_cash']];}

    /** @dataProvider failedPayments */
    public function test_failed_pending_or_missing_electronic_payment_is_never_auto_delivered(?string $status): void
    {
        $order = $this->legacy(['payment_type'=>'online']);
        if ($status !== null) DB::table('payments')->insert(['order_id'=>$order->id,'user_id'=>20,'status'=>$status]);
        $this->accept($order); $this->timeAt('07:30:00'); $counts = $this->clock()->run();
        $this->assertSame(0,$counts['completed']); $this->assertSame(0,$counts['courier']);
        $this->assertSame('pending',$order->fresh()->status); $this->assertSame(0,DB::table('wallets')->count());
        $this->assertSame(500.0,$this->balance(10));
    }

    public static function failedPayments(): array {return [[null],['0'],['false'],['pending']];}

    public function test_wallet_completion_requires_actual_checkout_debit_and_refunds_changed_price_once(): void
    {
        $order = $this->legacy(['payment_type'=>'wallet'],80); $this->accept($order);
        $this->timeAt('07:30:00'); $this->assertSame(0,$this->clock()->run()['completed']);
        DB::table('wallets')->insert(['order_id'=>$order->id,'from_user'=>20,'to_user'=>null,'amount'=>'130.00',
            'status'=>'completed','payment'=>'wallet','type'=>'transfer']);
        $this->assertSame(1,$this->clock()->run()['completed']);
        $this->assertSame(580.0,$this->balance(10)); $this->assertSame(220.0,$this->balance(20));
        $this->clock()->run(); $this->assertSame(3,DB::table('wallets')->count());
    }

    public function test_missing_party_or_app_wallet_rolls_back_status_and_all_money(): void
    {
        $order = $this->legacy(); $this->accept($order);
        DB::table('settings')->where('name','app_balance')->delete();
        $this->timeAt('07:30:00'); $this->assertSame(1,$this->clock()->run()['errors']);
        $this->assertSame('pending',$order->fresh()->status); $this->assertSame(500.0,$this->balance(10));
        $this->assertSame(0,DB::table('wallets')->count()); $this->assertNull(DB::table('order_board_clocks')->value('courier_at'));
    }

    /** @dataProvider storePayments */
    public function test_go_delivery_reuses_financial_lifecycle_and_never_repeats_settlement(string $method, string $payment, float $expectedBalance): void
    {
        $id = $this->store(['payment_method'=>$method,'payment_status'=>$payment]);
        $actions = app(GoStoreBoardActions::class);
        if ($method === 'card') DB::table('users')->where('id',40)->update(['balance'=>'350.00']);
        $actions->transition($id,40,'accept',1);
        $this->assertSame(1,DB::table('order_board_clocks')->where('source','store')->count());
        $this->timeAt('06:14:59'); $this->clock()->run(); $this->assertSame('preparing',DB::table('go_store_orders')->value('status'));
        $this->timeAt('06:15:00'); $this->clock()->run(); $this->assertSame('out_for_delivery',DB::table('go_store_orders')->value('status'));
        $this->timeAt('07:29:59'); $this->clock()->run(); $this->assertSame('out_for_delivery',DB::table('go_store_orders')->value('status'));
        $this->timeAt('07:30:00'); $this->clock()->run(); $this->assertSame('completed',DB::table('go_store_orders')->value('status'));
        $this->assertSame($expectedBalance,$this->balance(40));
        $this->assertSame($method === 'cash' ? 'cash_collected' : 'paid',DB::table('go_store_orders')->value('payment_status'));
        $walletCount = DB::table('wallets')->count(); $this->clock()->run();
        $this->assertFalse($actions->transition($id,40,'complete',1));
        $this->assertSame($walletCount,DB::table('wallets')->count()); $this->assertSame($expectedBalance,$this->balance(40));
    }

    public static function storePayments(): array {return [['cash','cash_due',190.0],['wallet','held',340.0],['card','paid',340.0]];}

    public function test_go_failure_after_acceptance_is_not_completed_and_pickup_has_no_courier_clock(): void
    {
        $id = $this->store(); app(GoStoreBoardActions::class)->transition($id,40,'accept',1);
        DB::table('go_store_orders')->where('id',$id)->update(['payment_method'=>'card','payment_status'=>'failed']);
        $pickup = $this->store(['fulfillment'=>'pickup']); app(GoStoreBoardActions::class)->transition($pickup,40,'accept',1);
        $this->timeAt('07:30:00'); $this->clock()->run();
        $this->assertSame('preparing',DB::table('go_store_orders')->where('id',$id)->value('status'));
        $this->assertSame('preparing',DB::table('go_store_orders')->where('id',$pickup)->value('status'));
        $this->assertSame(1,DB::table('order_board_clocks')->count());
        $this->assertSame(0,DB::table('wallets')->where('transfer_reference','like','%:gross')->count());
    }

    public function test_shipping_and_wallet_orders_are_not_scheduled_and_missing_clock_schema_is_safe(): void
    {
        foreach (['shipping','wallet'] as $type) {
            $order = $this->legacy(['type'=>$type]); $order->update(['accepted_notify'=>'yes']);
        }
        $this->assertSame(0,DB::table('order_board_clocks')->count());
        Schema::drop('order_board_clocks'); $this->assertSame(['courier'=>0,'completed'=>0,'skipped'=>0,'errors'=>0],$this->clock()->run());
        $this->artisan('order-board:advance')->expectsOutput('Order board clocks are not installed; no orders changed.')->assertExitCode(0);
    }

    public function test_stale_mobile_accept_or_delivery_choice_cannot_reopen_a_timer_completed_order(): void
    {
        $order = $this->legacy(); $this->accept($order); $stale = $order->fresh();
        $this->timeAt('07:30:00'); $this->clock()->run();
        $requests = [fn () => app(VendorOrders::class)->acceptOrder(Request::create('/api/vendor/accept','POST'),$stale),
            fn () => app(VendorOrders::class)->updateOrder(Request::create('/api/vendor/update','POST',['type'=>'in_resturant']),$stale)];
        foreach ($requests as $attempt) {
            try {$attempt(); $this->fail('Closed order must remain closed.');}
            catch (HttpException $error) {$this->assertSame(409,$error->getStatusCode());}
        }
        $this->assertSame('completed',$order->fresh()->status); $this->assertSame(1,DB::table('wallets')->count());
    }

    public function test_unauthorized_branch_cannot_use_locked_completion_or_transfer_helpers(): void
    {
        $order = $this->legacy();
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(40),'admin');
        try {app(VendorOrders::class)->updateOrderStatus(Request::create('/admin/status','POST',['status'=>'completed']),$order); $this->fail('Foreign order must not be authorized.');}
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {$this->assertTrue(true);}
        $this->assertSame('pending',Order::withoutGlobalScopes()->whereKey($order->id)->value('status'));
        $this->assertSame(0,DB::table('wallets')->count());
    }

    public function test_clock_uses_utc_even_when_browser_and_server_timezones_differ(): void
    {
        date_default_timezone_set('Africa/Cairo'); config(['app.timezone'=>'Asia/Dubai']);
        $order = $this->legacy(); $this->accept($order);
        $this->assertSame('2026-10-03 06:00:00',DB::table('order_board_clocks')->value('accepted_at'));
        $this->timeAt('06:15:00'); $this->assertSame(1,$this->clock()->run()['courier']);
        $this->timeAt('07:30:00'); $this->assertSame(1,$this->clock()->run()['completed']);
    }

    public function test_notices_wait_for_outer_commit_and_provider_failure_cannot_undo_completion(): void
    {
        $order = $this->legacy(); $this->accept($order); $this->timeAt('07:30:00');
        DB::transaction(function () {
            $this->assertSame(1,$this->clock()->run()['completed']);
            Notification::assertNothingSent();
        });
        Notification::assertSentTo(User::withoutGlobalScopes()->find(20),NotifyUserOrderStatusUpdatedNotification::class);
        $second = $this->legacy(); $this->accept($second); $this->timeAt('09:00:00');
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('Provider unavailable'));
        $this->assertSame(1,$this->clock()->run()['completed']);
        $this->assertSame('completed',$second->fresh()->status); $this->assertSame(480.0,$this->balance(10));
        $this->assertSame(2,DB::table('wallets')->count());
    }

    public function test_reentrant_worker_retry_after_commit_does_not_settle_twice(): void
    {
        $order = $this->legacy(); $this->accept($order); $this->timeAt('07:30:00');
        $retried = false;
        Notification::shouldReceive('send')->andReturnUsing(function () use (&$retried) {
            if (!$retried) {$retried = true; $this->clock()->run();}
        });
        $this->assertSame(1,$this->clock()->run()['completed']); $this->assertTrue($retried);
        $this->assertSame(1,DB::table('wallets')->count()); $this->assertSame(490.0,$this->balance(10));
    }

    public function test_genuine_vendor_api_accept_and_assigned_delegate_completion_remain_available(): void
    {
        $order = $this->legacy(['delegate_id'=>30,'delegate_from_out'=>'out_resturant']);
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(10),'api');
        app(VendorOrders::class)->acceptOrder(Request::create('/api/vendor/orders/'.$order->id.'/accept','POST'),$order);
        $this->assertSame('yes',$order->fresh()->accepted_notify); $this->assertSame(1,DB::table('order_board_clocks')->count());
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(30),'api');
        app(DelegateOrders::class)->orderCompleted($order->fresh());
        $this->assertSame('completed',$order->fresh()->status); $this->assertSame('delegate',$order->fresh()->transfer_price_by);
        $this->assertSame(394.0,$this->balance(30)); $this->assertSame(590.0,$this->balance(10));
    }

    public function test_customer_and_foreign_vendor_api_cannot_accept_complete_or_create_a_clock(): void
    {
        $order = $this->legacy(['delegate_id'=>30]);
        foreach ([20,40] as $actor) {
            $this->actingAs(User::withoutGlobalScopes()->findOrFail($actor),'api');
            $attempts = [fn () => app(VendorOrders::class)->acceptOrder(Request::create('/api/vendor/orders/accept','POST'),$order),
                fn () => app(VendorOrders::class)->updateOrderStatus(Request::create('/api/vendor/orders/status','POST',['status'=>'completed']),$order),
                fn () => app(DelegateOrders::class)->orderCompleted($order)];
            foreach ($attempts as $attempt) {
                try {$attempt(); $this->fail('Foreign API party must be blocked.');}
                catch (HttpException $error) {$this->assertSame(403,$error->getStatusCode());}
            }
        }
        $this->assertSame('pending',$order->fresh()->status); $this->assertSame(0,DB::table('order_board_clocks')->count());
        $this->assertSame(0,DB::table('wallets')->count());
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(20),'api');
        $order->update(['accepted_notify'=>'yes','status'=>'accepted']); // Retained customer mass assignment must not start a timer.
        $this->assertSame(0,DB::table('order_board_clocks')->count());
        $this->timeAt('07:30:00'); $this->clock()->run(); $this->assertSame('accepted',$order->fresh()->status);
    }

    public function test_invited_delegate_can_accept_without_erasing_already_started_courier_phase(): void
    {
        $order = $this->legacy(['delegate_from_out'=>'out_resturant']); $this->accept($order);
        DB::table('delegate_notifications')->insert(['order_id'=>$order->id,'delegate_id'=>30]);
        $this->timeAt('06:15:00'); $this->clock()->run();
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(30),'api');
        app(DelegateOrders::class)->acceptDeclineOrder(Request::create('/api/delegate/accept','POST',['status'=>'accept']),$order->fresh());
        $this->assertSame(30,(int)$order->fresh()->delegate_id); $this->assertSame('shipped',$order->fresh()->status);
        $this->assertSame('2026-10-03 06:00:00',DB::table('order_board_clocks')->value('accepted_at'));
    }

    public function test_retained_gross_helper_rolls_back_swallowed_notice_failure_instead_of_double_crediting(): void
    {
        $order = $this->legacy(['status'=>'completed','payment_type'=>'wallet']);
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('Provider unavailable'));
        try {app(DashboardOrders::class)->transferPrice($order->id); $this->fail('Failed helper must roll back.');}
        catch (\RuntimeException $error) {$this->assertSame('Provider unavailable',$error->getMessage());}
        $this->assertSame(500.0,$this->balance(10)); $this->assertSame(0,DB::table('wallets')->count());
        $this->assertNull($order->fresh()->transfer_price_by);
    }

    public function test_cash_helpers_and_timer_increment_the_real_app_balance_even_with_a_stale_scoped_settings_object(): void
    {
        config(['settings.cache.enabled'=>true]);
        $stale = app(GeneralSettings::class); $this->assertSame('1000.00',$stale->app_balance);
        // Avoid exercising unrelated API resource/media formatting in a money
        // test; the retained helper itself still performs its real DB writes.
        $vendor = new class extends VendorOrders {
            protected function successResponse($data, $message = null, $code = 200) {return response()->json(['status'=>'Success'], $code);}
        };
        $first = $this->legacy(['status'=>'completed','accepted_notify'=>'yes','delegate_from_out'=>'in_resturant']);
        $vendor->transfer_order_price($first->id);
        $timed = $this->legacy(); $this->accept($timed); $this->timeAt('07:30:00'); $this->clock()->run();
        app()->instance(GeneralSettings::class,$stale);
        $last = $this->legacy(['status'=>'completed','accepted_notify'=>'yes','delegate_from_out'=>'in_resturant']);
        $vendor->transfer_order_price($last->id);
        $this->assertSame(1030.0,$this->appBalance()); $this->assertSame(470.0,$this->balance(10));
        $this->assertSame(3,DB::table('wallets')->count());
    }

    public function test_known_successful_payment_amount_cannot_fund_a_later_price_increase(): void
    {
        $order = $this->legacy(['payment_type'=>'online'],200);
        DB::table('payments')->insert(['order_id'=>$order->id,'user_id'=>20,'status'=>'1','transaction_id'=>'9001','total_price'=>'130.00']);
        $this->accept($order); $this->timeAt('07:30:00');
        $this->assertSame(0,$this->clock()->run()['completed']);
        $this->assertSame('pending',$order->fresh()->status); $this->assertSame(500.0,$this->balance(10));
        $this->assertSame(0,DB::table('wallets')->count());
        DB::table('payments')->insert(['order_id'=>$order->id,'user_id'=>20,'status'=>'1','transaction_id'=>'9002','total_price'=>'230.00']);
        $this->assertSame(1,$this->clock()->run()['completed']);
        $this->assertSame('completed',$order->fresh()->status); $this->assertSame(720.0,$this->balance(10));
    }
}
