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
}
