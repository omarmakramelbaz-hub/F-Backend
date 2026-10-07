<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Vendor\OrderController as VendorOrderController;
use App\Http\Controllers\Dashboard\OrderController as DashboardOrderController;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Middlewares\PermissionMiddleware;
use Tests\TestCase;

class DashboardOrderIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        DB::purge('sqlite');
        Notification::fake();
        // Keep denial responses independent of the site's branded error page
        // and its unrelated global settings fixture.
        $this->withHeaders(['Accept' => 'application/json']);
        // These tests exercise authentication, route binding and ownership.
        // Permission availability is separately exercised by the board tests.
        $this->withoutMiddleware(PermissionMiddleware::class);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('account_type');
            $table->unsignedBigInteger('owner_resturant_id')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();
        });
        Schema::create('resturants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('status')->nullable();
            $table->string('payment_type')->default('cash');
            $table->string('accepted_notify')->nullable();
            $table->unsignedBigInteger('resturant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
        });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'account_type' => 'admin', 'owner_resturant_id' => null],
            ['id' => 10, 'name' => 'Branch A', 'account_type' => 'vendor', 'owner_resturant_id' => null],
            ['id' => 20, 'name' => 'Branch B', 'account_type' => 'vendor', 'owner_resturant_id' => null],
            ['id' => 30, 'name' => 'Owner A', 'account_type' => 'resturant_owner', 'owner_resturant_id' => 100],
            ['id' => 40, 'name' => 'Missing branch', 'account_type' => 'vendor', 'owner_resturant_id' => null],
        ]);
        DB::table('resturants')->insert([
            ['id' => 100, 'user_id' => 10, 'parent_id' => null],
            ['id' => 101, 'user_id' => 11, 'parent_id' => 100],
            ['id' => 200, 'user_id' => 20, 'parent_id' => null],
        ]);
        DB::table('orders')->insert([
            ['id' => 1001, 'type' => 'current', 'status' => 'pending', 'resturant_id' => 100, 'user_id' => null],
            ['id' => 1002, 'type' => 'current', 'status' => 'pending', 'resturant_id' => 101, 'user_id' => null],
            ['id' => 2001, 'type' => 'current', 'status' => 'pending', 'resturant_id' => 200, 'user_id' => null],
            ['id' => 3001, 'type' => 'wallet', 'status' => 'pending', 'resturant_id' => null, 'user_id' => 20],
            ['id' => 3002, 'type' => 'shipping', 'status' => 'pending', 'resturant_id' => null, 'user_id' => 20],
            ['id' => 3003, 'type' => 'current', 'status' => 'pending', 'resturant_id' => null, 'user_id' => null],
        ]);
        DB::table('carts')->insert(['id' => 501, 'order_id' => 2001]);
    }

    private function signIn(int $id): void
    {
        $this->actingAs(User::withoutGlobalScopes()->findOrFail($id), 'admin');
    }

    public function test_linked_admin_cannot_use_legacy_routes_to_escape_its_branch(): void
    {
        DB::table('users')->where('id', 10)->update(['account_type'=>'admin', 'owner_resturant_id'=>100]);
        $this->signIn(10);
        $this->assertSame([1001], Order::orderBy('id')->pluck('id')->all());
        foreach ([1002,2001,3001,3002] as $id) {
            $this->get('/admin/print-pdf?id='.$id)->assertNotFound();
            $this->post('/admin/ordersChangeStatus/'.$id, ['status'=>'accepted'])->assertNotFound();
        }
        $this->post('/admin/ordersChangeStatus/1001', ['status'=>'accepted'])->assertRedirect();
        $this->assertSame('accepted', DB::table('orders')->where('id',1001)->value('status'));
        $this->assertSame('pending', DB::table('orders')->where('id',2001)->value('status'));
    }

    public function test_admin_sees_all_orders_and_owner_keeps_own_branch_hierarchy(): void
    {
        $this->signIn(1);
        $this->assertSame([1001, 1002, 2001, 3001, 3002, 3003], Order::orderBy('id')->pluck('id')->all());
        $this->signIn(30);
        $this->assertSame([1001, 1002], Order::orderBy('id')->pluck('id')->all());
    }

    public function test_branch_cannot_bypass_ownership_with_wallet_shipping_or_requested_ids(): void
    {
        $this->signIn(10);
        $this->assertSame([1001], Order::orderBy('id')->pluck('id')->all());
        foreach ([1002, 2001, 3001, 3002, 3003, 9999] as $id) {
            $this->assertNull(Order::find($id));
        }
        $this->assertSame(0, Order::where('status', 'completed')->count());
        $this->assertSame(0, Order::where('type', 'wallet')->count());
    }

    public function test_account_without_a_restaurant_fails_closed(): void
    {
        $this->signIn(40);
        $this->assertSame(0, Order::count());
        $this->get('/admin/print-pdf?id=3003')->assertNotFound();
        $this->get('/admin/fetch-product?product_id=3001', ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
    }

    public function test_foreign_details_and_print_routes_return_404_including_wallet_and_shipping(): void
    {
        $this->signIn(10);
        foreach ([2001, 3001, 3002, 9999] as $id) {
            $this->get('/admin/fetch-product?product_id='.$id, ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
            $this->get('/admin/print-pdf?id='.$id)->assertNotFound();
            $this->get('/admin/download-pdf?id='.$id)->assertNotFound();
            $this->get('/admin/vendororders/'.$id)->assertNotFound();
        }
    }

    public function test_foreign_accept_reject_update_and_transfer_routes_do_not_change_orders(): void
    {
        $this->signIn(10);
        foreach ([2001, 3001, 3002] as $id) {
            $this->post('/admin/acceptOrder/'.$id)->assertNotFound();
            $this->post('/admin/updateorders/'.$id, ['type' => 'in_resturant'])->assertNotFound();
            $this->post('/admin/update/orders/'.$id, ['status' => 'declined'])->assertNotFound();
            $this->post('/admin/ordersChangeStatus/'.$id, ['status' => 'accepted'])->assertNotFound();
            $this->post('/admin/ordersTransferPrice/'.$id)->assertNotFound();
        }
        $this->assertSame(3, DB::table('orders')->whereIn('id', [2001, 3001, 3002])->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('orders')->whereNotNull('accepted_notify')->count());
    }

    public function test_branch_can_update_its_own_order_using_existing_dashboard_action(): void
    {
        $this->signIn(10);
        $this->post('/admin/ordersChangeStatus/1001', ['status' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', DB::table('orders')->where('id', 1001)->value('status'));
        $this->assertSame('pending', DB::table('orders')->where('id', 2001)->value('status'));
    }

    public function test_unified_board_details_print_and_actions_recheck_branch_ownership(): void
    {
        $this->signIn(10);
        foreach ([1002, 2001, 3001, 3002, 9999] as $id) {
            $this->get('/admin/order-board/legacy/'.$id)->assertNotFound();
            $this->get('/admin/order-board/legacy/'.$id.'/print')->assertNotFound();
            $this->post('/admin/order-board/legacy/'.$id.'/action', [
                'action' => 'accept', 'expected_status' => 'pending',
            ])->assertNotFound();
        }
        $this->assertSame(6, DB::table('orders')->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('orders')->whereNotNull('accepted_notify')->count());
    }

    public function test_unified_board_endpoints_require_dashboard_login(): void
    {
        auth('admin')->logout();
        $this->get('/admin/order-board/legacy/1001')->assertRedirect('/admin/login');
        $this->get('/admin/order-board/legacy/1001/print')->assertRedirect('/admin/login');
        $this->post('/admin/order-board/legacy/1001/action', [
            'action' => 'accept', 'expected_status' => 'pending',
        ])->assertRedirect('/admin/login');
        $this->assertNull(DB::table('orders')->where('id', 1001)->value('accepted_notify'));
    }

    public function test_already_loaded_foreign_model_is_rechecked_before_mutation(): void
    {
        $foreign = Order::withoutGlobalScopes()->findOrFail(2001);
        $this->signIn(10);
        try {
            (new VendorOrderController())->acceptOrder(Request::create('/admin/acceptOrder/2001', 'POST'), $foreign);
            $this->fail('An already-loaded foreign model must still be denied.');
        } catch (ModelNotFoundException $exception) {
            $this->assertNull(DB::table('orders')->where('id', 2001)->value('accepted_notify'));
        }
        try {
            (new DashboardOrderController())->destroy(Cart::findOrFail(501));
            $this->fail('A foreign cart must not bypass order ownership.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(1, DB::table('carts')->where('id', 501)->count());
        }
    }

    public function test_absent_admin_session_keeps_existing_api_order_query_behavior(): void
    {
        auth('admin')->logout();
        $this->assertSame(6, Order::count());
        $foreign = Order::findOrFail(2001);
        // An unsupported action is a no-op in the existing API controller;
        // importantly, it is not newly blocked by the dashboard-only guard.
        $this->assertNull((new VendorOrderController())->updateOrder(Request::create('/api/vendor/orders/2001/update', 'POST'), $foreign));
        $this->assertSame('pending', DB::table('orders')->where('id', 2001)->value('status'));
    }

    public function test_branch_json_wallet_rejection_refunds_the_actual_customer_with_login_listing_scope(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 12, 2)->default(0);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('declined_by')->nullable();
            $table->string('transfer_price_by')->nullable();
            $table->decimal('delivery_price', 12, 2)->default(0);
            $table->decimal('user_tax', 12, 2)->default(0);
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('qty', 10, 3)->default(1);
            $table->decimal('updated_total', 12, 2)->nullable();
        });
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            foreach (['from_user', 'to_user', 'order_id'] as $field) $table->unsignedBigInteger($field)->nullable();
            $table->decimal('amount', 12, 2);
            foreach (['status', 'payment', 'type'] as $field) $table->string($field);
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->text('payload');
        });
        Schema::create('delegate_notifications', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('delegate_id')->nullable();
        });
        DB::table('users')->insert(['id' => 90, 'name' => 'Actual customer', 'account_type' => 'user', 'balance' => 40]);
        foreach ([1 => 700, 10 => 500, 20 => 600] as $id => $balance) {
            DB::table('users')->where('id', $id)->update(['balance' => $balance]);
        }
        DB::table('orders')->where('id', 1001)->update(['user_id' => 90, 'payment_type' => 'wallet']);
        DB::table('carts')->insert(['id' => 502, 'order_id' => 1001, 'price' => 100, 'qty' => 1]);
        Event::fake();
        Mail::fake();
        $this->signIn(10);
        // signin stores this ID; AdminScope applies it to every JSON user query.
        $this->withSession(['id_user' => 10]);
        $payload = ['action' => 'reject', 'expected_status' => 'pending', 'expected_accepted_notify' => ''];
        $this->postJson('/admin/order-board/legacy/2001/action', $payload)->assertNotFound();
        $this->postJson('/admin/order-board/legacy/1001/action', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('declined', DB::table('orders')->where('id', 1001)->value('status'));
        $this->assertSame('pending', DB::table('orders')->where('id', 2001)->value('status'));
        $this->assertSame(140.0, (float) DB::table('users')->where('id', 90)->value('balance'));
        foreach ([1 => 700, 10 => 500, 20 => 600] as $id => $balance) {
            $this->assertSame((float) $balance, (float) DB::table('users')->where('id', $id)->value('balance'));
        }
        $this->assertDatabaseHas('wallets', ['to_user' => 90, 'order_id' => 1001, 'amount' => 100, 'status' => 'completed']);
        $this->assertSame('admin', DB::table('orders')->where('id', 1001)->value('transfer_price_by'));
        Notification::assertSentTo(User::withoutGlobalScopes()->findOrFail(90), \App\Notifications\NotifyOrderPriceTransferToWalletNotification::class);
        $this->postJson('/admin/order-board/legacy/1001/action', $payload)->assertStatus(409);
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(140.0, (float) DB::table('users')->where('id', 90)->value('balance'));
    }

    private function settlementFixture(array $orderChanges = [], ?float $updatedTotal = null): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('delegate_fees', 6, 2)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            foreach (['declined_by', 'transfer_price_by', 'delegate_from_out', 'reason'] as $field) $table->string($field)->nullable();
            foreach (['delegate_id', 'coupon_wheel_id'] as $field) $table->unsignedBigInteger($field)->nullable();
            foreach (['delivery_price', 'user_tax', 'vendor_tax'] as $field) $table->decimal($field, 12, 2)->default(0);
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0); $table->decimal('qty', 10, 3)->default(1);
            $table->decimal('updated_total', 12, 2)->nullable();
        });
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            foreach (['from_user', 'to_user', 'order_id'] as $field) $table->unsignedBigInteger($field)->nullable();
            $table->decimal('amount', 12, 2);
            foreach (['status', 'payment', 'type'] as $field) $table->string($field);
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id(); $table->string('group'); $table->string('name'); $table->text('payload');
            $table->timestamps();
        });
        // Populate the real settings class so settlement updates the actual app
        // wallet repository; unrelated coupon hooks have empty fixture tables.
        $settingsClass = new \ReflectionClass(\App\Models\GeneralSettings::class);
        foreach ($settingsClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== \App\Models\GeneralSettings::class || $property->isStatic()) continue;
            $value = $property->getType()->getName() === 'bool' ? false : ($property->getName() === 'app_balance' ? '1000' : '0');
            DB::table('settings')->insert(['group' => 'general', 'name' => $property->getName(), 'payload' => json_encode($value)]);
        }
        Schema::create('coupon_wheels', function (Blueprint $table) {
            $table->id(); $table->string('status')->nullable(); $table->date('start_date')->nullable(); $table->date('end_date')->nullable();
        });
        Schema::create('coupon_wheel_resturants', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('coupon_wheel_id'); $table->unsignedBigInteger('resturant_id');
        });
        Schema::create('delegate_notifications', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('order_id'); $table->unsignedBigInteger('delegate_id')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 90, 'name' => 'Customer', 'account_type' => 'user', 'balance' => 40, 'delegate_fees' => null],
            ['id' => 95, 'name' => 'Assigned courier', 'account_type' => 'delegate', 'balance' => 500, 'delegate_fees' => 20],
        ]);
        foreach ([1 => 700, 10 => 500, 20 => 600] as $id => $balance) DB::table('users')->where('id', $id)->update(['balance' => $balance]);
        DB::table('orders')->where('id', 1001)->update(array_replace([
            'user_id' => 90, 'status' => 'accepted', 'accepted_notify' => 'yes',
            'payment_type' => 'cash', 'delegate_from_out' => 'in_resturant', 'vendor_tax' => 10,
        ], $orderChanges));
        DB::table('carts')->insert(['id' => 502, 'order_id' => 1001, 'price' => 100, 'qty' => 1, 'updated_total' => $updatedTotal]);
        Event::fake(); Mail::fake();
        $this->signIn(10);
        $this->withSession(['id_user' => 10]);
    }

    private function completeBoardOrder(string $status = 'accepted'): void
    {
        $payload = ['action' => 'complete', 'expected_status' => $status, 'expected_accepted_notify' => 'yes'];
        $this->postJson('/admin/order-board/legacy/2001/action', $payload)->assertNotFound();
        $this->postJson('/admin/order-board/legacy/1001/action', $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('completed', DB::table('orders')->where('id', 1001)->value('status'));
        $this->assertSame(700.0, (float) DB::table('users')->where('id', 1)->value('balance'));
        $this->assertSame(600.0, (float) DB::table('users')->where('id', 20)->value('balance'));
        $this->postJson('/admin/order-board/legacy/1001/action', $payload)->assertStatus(409);
    }

    public function test_branch_json_cash_completion_settles_commission_from_the_actual_vendor(): void
    {
        $this->settlementFixture();
        $this->completeBoardOrder();
        $this->assertSame(490.0, (float) DB::table('users')->where('id', 10)->value('balance'));
        $this->assertSame(40.0, (float) DB::table('users')->where('id', 90)->value('balance'));
        $this->assertSame(500.0, (float) DB::table('users')->where('id', 95)->value('balance'));
        $this->assertDatabaseHas('wallets', ['from_user' => 10, 'order_id' => 1001, 'amount' => 10]);
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(1010.0, (float) json_decode(DB::table('settings')->where('name', 'app_balance')->value('payload'), true));
        $this->assertSame('vendor', DB::table('orders')->where('id', 1001)->value('transfer_price_by'));
    }

    public function test_branch_json_cash_courier_completion_settles_only_the_assigned_courier_and_vendor(): void
    {
        $this->settlementFixture(['status' => 'shipped', 'delegate_id' => 95, 'delegate_from_out' => 'out_resturant', 'delivery_price' => 30]);
        $this->completeBoardOrder('shipped');
        $this->assertSame(590.0, (float) DB::table('users')->where('id', 10)->value('balance'));
        $this->assertSame(394.0, (float) DB::table('users')->where('id', 95)->value('balance'));
        $this->assertSame(40.0, (float) DB::table('users')->where('id', 90)->value('balance'));
        $this->assertDatabaseHas('wallets', ['from_user' => 95, 'to_user' => 10, 'order_id' => 1001, 'amount' => 90]);
        $this->assertDatabaseHas('wallets', ['from_user' => 95, 'to_user' => null, 'order_id' => 1001, 'amount' => 16]);
        $this->assertSame(2, DB::table('wallets')->count());
        $this->assertSame(1016.0, (float) json_decode(DB::table('settings')->where('name', 'app_balance')->value('payload'), true));
        $this->assertSame('delegate', DB::table('orders')->where('id', 1001)->value('transfer_price_by'));
    }

    public function test_branch_json_wallet_completion_refunds_price_adjustment_to_the_actual_customer(): void
    {
        $this->settlementFixture(['payment_type' => 'wallet'], 80);
        $this->completeBoardOrder();
        $this->assertSame(480.0, (float) DB::table('users')->where('id', 10)->value('balance'));
        $this->assertSame(60.0, (float) DB::table('users')->where('id', 90)->value('balance'));
        $this->assertSame(500.0, (float) DB::table('users')->where('id', 95)->value('balance'));
        $this->assertDatabaseHas('wallets', ['from_user' => 10, 'to_user' => 90, 'order_id' => 1001, 'amount' => 20]);
        $this->assertSame(1, DB::table('wallets')->count());
    }

    public function test_wallet_completion_and_existing_settlement_credit_the_actual_vendor_and_courier(): void
    {
        $this->settlementFixture(['status' => 'shipped', 'payment_type' => 'wallet', 'delegate_id' => 95,
            'delegate_from_out' => 'out_resturant', 'delivery_price' => 30]);
        $this->completeBoardOrder('shipped');
        $this->postJson('/admin/ordersTransferPrice/2001')->assertNotFound();
        $this->postJson('/admin/ordersTransferPrice/1001')->assertRedirect();
        $this->assertSame(590.0, (float) DB::table('users')->where('id', 10)->value('balance'));
        $this->assertSame(524.0, (float) DB::table('users')->where('id', 95)->value('balance'));
        $this->assertSame(40.0, (float) DB::table('users')->where('id', 90)->value('balance'));
        $this->assertDatabaseHas('wallets', ['to_user' => 10, 'order_id' => 1001, 'amount' => 90]);
        $this->assertDatabaseHas('wallets', ['to_user' => 95, 'order_id' => 1001, 'amount' => 24]);
        $this->assertSame(2, DB::table('wallets')->count());
        $this->assertSame('admin', DB::table('orders')->where('id', 1001)->value('transfer_price_by'));
        $this->postJson('/admin/ordersTransferPrice/1001');
        $this->assertSame(2, DB::table('wallets')->count());
        $this->assertSame(524.0, (float) DB::table('users')->where('id', 95)->value('balance'));
    }
}
