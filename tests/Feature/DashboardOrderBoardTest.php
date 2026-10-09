<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\GeneralSettings;
use App\Http\Controllers\Api\V1\Vendor\OrderController as VendorOrders;
use App\Services\Dashboard\BestEffortOrderMail;
use App\Services\Dashboard\OrderBoardService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardOrderBoardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'settings.cache.enabled' => false,
            'settings.default_repository' => 'database',
        ]);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00', 'Africa/Cairo'));
        Http::fake();
        Notification::fake();

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'mobile', 'email', 'account_type', 'app_scope', 'status'] as $field) {
                $t->string($field)->nullable();
            }
            $t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('added_by')->nullable();
            $t->decimal('balance', 14, 2)->default(0);
            $t->decimal('delegate_fees', 6, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('added_by')->nullable();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            foreach (['user_id', 'resturant_id', 'delegate_id', 'user_address_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            foreach (['order_no', 'type', 'status', 'shipping_status', 'source_app', 'app_scope', 'payment', 'payment_method', 'payment_type', 'accepted_notify', 'delegate_from_out', 'notes'] as $field) {
                $t->string($field)->nullable();
            }
            $t->decimal('total_price', 14, 2)->default(0);
            $t->decimal('shipping_price', 14, 2)->default(0);
            $t->decimal('shipping_fees', 14, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            foreach (['order_id', 'user_id', 'resturant_id', 'resturant_product_id', 'product_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            $t->decimal('price', 14, 2)->default(0);
            $t->decimal('qty', 10, 3)->default(1);
            $t->decimal('updated_total', 14, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('resturant_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('product_name')->nullable();
            $t->decimal('price', 14, 2)->default(0);
        });
        Schema::create('user_address', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            foreach (['address', 'street_name', 'floor_no', 'apartment_no', 'building_no', 'landmark'] as $field) {
                $t->string($field)->nullable();
            }
        });
        Schema::create('go_stores', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary();
            $t->string('name');
            $t->string('kind');
            $t->string('address');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('go_store_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('customer_id');
            $t->text('snapshot');
            $t->string('fulfillment');
            $t->string('status');
            $t->unsignedInteger('revision')->default(1);
            foreach (['subtotal_cents', 'delivery_cents', 'total_cents', 'commission_bps', 'commission_cents'] as $field) {
                $t->unsignedBigInteger($field)->default(0);
            }
            $t->string('payment_method');
            $t->string('payment_status');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('go_service_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('partner_id')->nullable();
            $t->string('profession_key');
            $t->text('description');
            $t->string('address');
            $t->string('phone')->nullable();
            $t->string('status');
            $t->unsignedBigInteger('price_cents')->default(0);
            $t->string('payment_method')->nullable();
            $t->string('payment_status')->nullable();
            $t->text('close_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('partner_service_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('partner_id');
            $t->string('profession_key');
            $t->text('description');
            $t->string('address')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('status');
            $t->decimal('quoted_price', 14, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('from_user')->nullable();
            $t->unsignedBigInteger('to_user')->nullable();
            $t->decimal('amount', 14, 2);
            $t->string('status');
            $t->string('payment');
            $t->string('type');
            $t->string('transfer_reference')->nullable()->unique();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('group');
            $t->string('name');
            $t->text('payload');
        });

        foreach ([
            [1, 'Administrative account', 'admin', 'fasakhansta', null, 0],
            [10, 'First restaurant branch', 'vendor', 'fasakhansta', 100, 500],
            [11, 'Second restaurant branch', 'vendor', 'fasakhansta', 101, 500],
            [20, 'Fasakhansta customer', 'user', 'fasakhansta', null, 200],
            [21, 'Go customer', 'user', 'go', null, 200],
            [30, 'First Go store owner', 'vendor', 'go_partner', null, 500],
            [31, 'Second Go store owner', 'vendor', 'go_partner', null, 500],
            [40, 'Go professional', 'delegate', 'go_partner', null, 500],
        ] as [$id, $name, $type, $scope, $restaurant, $balance]) {
            DB::table('users')->insert([
                'id' => $id, 'name' => $name, 'account_type' => $type, 'app_scope' => $scope,
                'status' => 'accepted', 'owner_resturant_id' => $restaurant, 'balance' => $balance,
                'mobile' => '0100000'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            ]);
        }
        DB::table('resturants')->insert([
            ['id' => 100, 'name' => 'First restaurant', 'user_id' => 10],
            ['id' => 101, 'name' => 'Other restaurant', 'user_id' => 11],
        ]);
        DB::table('go_stores')->insert([
            ['user_id' => 30, 'name' => 'First store', 'kind' => 'supermarket', 'address' => 'First shop address'],
            ['user_id' => 31, 'name' => 'Other store', 'kind' => 'pharmacy', 'address' => 'Other shop address'],
        ]);
        DB::table('settings')->insert(['group' => 'general', 'name' => 'app_balance', 'payload' => '"1000.00"']);
        $this->fakeGeneralSettings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actor(int $id): User
    {
        return (new User())->forceFill((array) DB::table('users')->find($id));
    }

    public function test_linked_admin_uses_branch_scope_on_board_details_and_actions(): void
    {
        DB::table('users')->where('id', 10)->update(['account_type'=>'admin']);
        $this->legacy(1);
        $this->legacy(2, ['resturant_id'=>101]);
        $this->store();
        $this->services();
        $data = $this->board(10);
        $this->assertFalse($data['isAdmin']);
        $this->assertSame(['legacy:1'], $this->keys($data));
        $this->assertSame(['f:100'], array_column($data['branches'], 'value'));
        $this->assertSame([], $this->keys($this->board(10, ['branch'=>'f:101'])));
        foreach ([['legacy',2],['store',200],['service',300],['partner_service',301]] as [$source,$id]) {
            $this->assertCannotView(10, $source, $id);
            $this->actingAs($this->actor(10), 'admin')
                ->postJson(route('order-board.action', ['source'=>$source, 'id'=>$id]), ['action'=>'accept','expected_status'=>'pending'])->assertNotFound();
        }
        $this->assertSame('pending', DB::table('orders')->where('id', 2)->value('status'));
        $this->assertContains('legacy:2', $this->keys($this->board(1)));
    }

    private function board(int $actor = 1, array $filters = []): array
    {
        return app(OrderBoardService::class)->data(Request::create('/admin/order-review', 'GET', $filters), $this->actor($actor));
    }

    private function cards(array $data): array
    {
        return array_merge(...array_values($data['groups']));
    }

    private function keys(array $data): array
    {
        return array_map(function (array $card) {
            return $card['source'].':'.$card['id'];
        }, $this->cards($data));
    }

    private function legacy(int $id, array $extra = []): void
    {
        DB::table('orders')->insert(array_replace([
            'id' => $id, 'user_id' => 20, 'resturant_id' => 100, 'order_no' => 'R100-'.$id,
            'type' => 'current', 'status' => 'pending', 'total_price' => '180.00',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function store(int $id = 200, array $extra = []): void
    {
        DB::table('go_store_orders')->insert(array_replace([
            'id' => $id, 'store_id' => 30, 'customer_id' => 21,
            'snapshot' => json_encode([
                'store_name' => 'Store at checkout', 'customer_name' => 'Customer at checkout',
                'customer_mobile' => '01098765432',
                'address' => ['address' => 'Checkout address', 'street_name' => 'Nile Street', 'floor_no' => '3', 'apartment_no' => '7'],
                'notes' => 'Checkout notes',
                'items' => [['name' => 'Checkout product', 'quantity' => 1, 'unit_price' => '95.00', 'line_total' => '95.00', 'option_label' => 'One kilo']],
            ]),
            'fulfillment' => 'delivery', 'status' => 'pending', 'revision' => 1,
            'subtotal_cents' => 9500, 'delivery_cents' => 500, 'total_cents' => 10000,
            'commission_bps' => 1000, 'commission_cents' => 1000,
            'payment_method' => 'cash', 'payment_status' => 'cash_due',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function services(): void
    {
        DB::table('go_service_jobs')->insert([
            'id' => 300, 'customer_id' => 21, 'partner_id' => 40, 'profession_key' => 'plumber',
            'description' => 'Repair the sink', 'address' => 'Service address', 'status' => 'searching',
            'price_cents' => 25000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('partner_service_requests')->insert([
            'id' => 301, 'user_id' => 21, 'partner_id' => 40, 'profession_key' => 'electrician',
            'description' => 'Repair a socket', 'address' => 'Legacy service address', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertCannotView(int $actor, string $source, int $id): void
    {
        try {
            app(OrderBoardService::class)->detail($source, $id, $this->actor($actor));
            $this->fail('A branch or store must not be able to read an order outside its ownership.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 404]);
        }
    }

    public function test_admin_board_includes_orders_without_carts_go_delivery_stores_and_both_service_generations(): void
    {
        $this->legacy(1);
        $this->legacy(2, ['type' => 'shipping', 'resturant_id' => null, 'user_id' => 21, 'shipping_status' => 'pending']);
        $this->legacy(3, ['resturant_id' => 101]);
        $this->legacy(4, ['type' => 'wallet', 'resturant_id' => null]);
        $this->store();
        $this->services();

        $data = $this->board();
        $this->assertTrue($data['isAdmin']);
        $this->assertEqualsCanonicalizing(['legacy:1', 'legacy:2', 'legacy:3', 'store:200', 'service:300', 'partner_service:301'], $this->keys($data));
        $this->assertSame(0, DB::table('carts')->count());
        if ($path = getenv('ORDER_BOARD_QA_PATH')) {
            $this->exportQaView($path);
        }
    }

    public function test_branch_board_counts_and_detail_are_scoped_to_its_restaurant(): void
    {
        $this->legacy(1);
        $this->legacy(2, ['resturant_id' => 101]);
        $this->legacy(3, ['type' => 'shipping', 'resturant_id' => null, 'user_id' => 21]);
        $this->store();
        $this->services();

        $data = $this->board(10);
        $this->assertFalse($data['isAdmin']);
        $this->assertSame(['legacy:1'], $this->keys($data));
        $this->assertSame(1, array_sum($data['counts']));
        $this->assertSame(1, app(OrderBoardService::class)->detail('legacy', 1, $this->actor(10))['id']);
        foreach ([['legacy', 2], ['legacy', 3], ['store', 200], ['service', 300], ['partner_service', 301]] as [$source, $id]) {
            $this->assertCannotView(10, $source, $id);
        }
    }

    public function test_store_owner_sees_only_own_orders_and_print_detail_uses_checkout_snapshot(): void
    {
        $this->legacy(1);
        $this->store();
        $this->store(201, ['store_id' => 31]);
        $this->services();
        DB::table('go_stores')->where('user_id', 30)->update(['name' => 'Renamed live store']);
        DB::table('users')->where('id', 21)->update(['name' => 'Renamed live customer']);

        $this->assertSame(['store:200'], $this->keys($this->board(30)));
        $detail = app(OrderBoardService::class)->detail('store', 200, $this->actor(30));
        $serialized = json_encode($detail);
        $this->assertStringContainsString('Checkout product', $serialized);
        $this->assertStringContainsString('Checkout address', $serialized);
        $this->assertStringContainsString('Store at checkout', $serialized);
        $this->assertStringNotContainsString('Renamed live store', $serialized);
        foreach ([['legacy', 1], ['store', 201], ['service', 300], ['partner_service', 301]] as [$source, $id]) {
            $this->assertCannotView(30, $source, $id);
        }
    }

    public function test_app_filter_uses_explicit_order_source_then_customer_scope(): void
    {
        $this->legacy(1, ['source_app' => 'go', 'user_id' => 20]);
        $this->legacy(2, ['source_app' => 'fasakhansta', 'user_id' => 21]);
        $this->legacy(3, ['user_id' => 21]);
        $this->legacy(4, ['user_id' => 20]);
        $this->legacy(5, ['app_scope' => 'go', 'user_id' => 20]);
        $this->legacy(6, ['source_app' => 'fasakhansta', 'app_scope' => 'go', 'user_id' => 21]);
        $this->store();

        $this->assertEqualsCanonicalizing(['legacy:1', 'legacy:3', 'legacy:5', 'store:200'], $this->keys($this->board(1, ['app' => 'go'])));
        $this->assertEqualsCanonicalizing(['legacy:2', 'legacy:4', 'legacy:6'], $this->keys($this->board(1, ['app' => 'fasakhansta'])));
    }

    public function test_legacy_order_statuses_have_accurate_branch_column_counts(): void
    {
        foreach ([1 => 'pending', 2 => 'accepted', 3 => 'shipped', 4 => 'completed'] as $id => $status) {
            $this->legacy($id, ['status' => $status]);
        }
        $this->legacy(5, ['resturant_id' => 101]);
        $data = $this->board(10);
        $this->assertEqualsCanonicalizing(['legacy:1', 'legacy:2', 'legacy:3', 'legacy:4'], $this->keys($data));
        $this->assertSame(['new' => 1, 'preparing' => 1, 'courier' => 1, 'completed' => 1], $data['counts']);
    }

    public function test_orders_with_the_same_id_in_different_sources_have_distinct_cards_and_details(): void
    {
        $this->legacy(200);
        $this->store(200);
        $data = $this->board();
        $this->assertEqualsCanonicalizing(['legacy:200', 'store:200'], $this->keys($data));
        $legacy = app(OrderBoardService::class)->detail('legacy', 200, $this->actor(1));
        $store = app(OrderBoardService::class)->detail('store', 200, $this->actor(1));
        $this->assertSame('legacy', $legacy['source']);
        $this->assertSame('store', $store['source']);
        $this->assertNotSame($legacy['key'], $store['key']);
        $this->assertSame('R100-200', $legacy['number']);
        $this->assertSame('180.00', $legacy['total']);
        $this->assertSame('100.00', $store['total']);
        $this->assertSame('Checkout product', $store['items'][0]['name']);
    }

    public function test_restaurant_courier_orders_appear_only_in_the_courier_column(): void
    {
        $this->legacy(1, ['status'=>'accepted', 'accepted_notify'=>'yes', 'delegate_from_out'=>'in_resturant']);
        $this->legacy(2, ['status'=>'accepted', 'accepted_notify'=>'yes', 'delegate_from_out'=>'out_resturant']);
        $this->legacy(3, ['status'=>'pending', 'accepted_notify'=>'yes']);
        $this->legacy(4, ['status'=>'shipped', 'delegate_from_out'=>'out_resturant']);
        $this->legacy(5, ['status'=>'accepted']);
        $this->legacy(6, ['status'=>'accepted', 'delegate_from_out'=>'in_resturant', 'resturant_id'=>101]);

        $data = $this->board(10);
        $this->assertSame(['new'=>0, 'preparing'=>3, 'courier'=>2, 'completed'=>0], $data['counts']);
        $this->assertEqualsCanonicalizing(['legacy:1', 'legacy:4'], array_column($data['groups']['courier'], 'key'));
        $this->assertEqualsCanonicalizing(['legacy:2', 'legacy:3', 'legacy:5'], array_column($data['groups']['preparing'], 'key'));
        $card = app(OrderBoardService::class)->detail('legacy', 1, $this->actor(10));
        $this->assertSame('accepted', $card['status']);
        $this->assertSame('courier', $card['group']);
        $this->assertSame('مع المندوب', $card['status_label']);
        $this->assertSame(['complete'], $card['actions']);
        $this->assertSame(3, $this->board(1)['counts']['courier']);
    }

    public function test_invoice_keeps_full_saved_item_amount_and_matches_existing_order_total(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('delivery_price', 14, 2)->default(0);
            $t->decimal('user_tax', 14, 2)->default(0);
        });
        DB::table('settings')->insert(['group'=>'general', 'name'=>'service_fees', 'payload'=>'2']);
        $this->legacy(1, ['delivery_price'=>'43.12', 'user_tax'=>'150.00']);
        DB::table('resturant_products')->insert([
            'id'=>501, 'resturant_id'=>100, 'product_name'=>'برميل فسيخ نبروه ال 4 سمكات كيلو', 'price'=>'17500.00',
        ]);
        DB::table('carts')->insert([
            'order_id'=>1, 'user_id'=>20, 'resturant_id'=>100, 'resturant_product_id'=>501, 'qty'=>1, 'price'=>'17500.00',
        ]);
        $this->actingAs($this->actor(10), 'admin');
        $detail = $this->get(route('order-board.details', ['legacy', 1]))->assertOk()->assertSee('17,500.00')->assertSee('18,043.12');
        $print = $this->get(route('order-board.print', ['legacy', 1]))->assertOk()->assertSee('17,500.00')->assertSee('18,043.12');
        $card = $print->viewData('card');
        $this->assertSame($detail->viewData('card')['items'], $card['items']);
        $this->assertSame('17500.00', $card['items'][0]['line_total']);
        $this->assertSame([
            ['label'=>'قيمة الأصناف', 'amount'=>'17500.00'],
            ['label'=>'التوصيل', 'amount'=>'43.12'],
            ['label'=>'الضريبة', 'amount'=>'150.00'],
            ['label'=>'رسوم الخدمة', 'amount'=>'350.00'],
        ], $card['totals']);
        $this->assertSame(number_format((float) \App\Models\Order::withoutGlobalScopes()->find(1)->grand_total, 2, '.', ''), $card['total']);
    }

    public function test_forged_branch_filters_cannot_expose_another_restaurant_or_store(): void
    {
        $this->legacy(1);
        $this->legacy(2, ['resturant_id' => 101]);
        $this->store();
        $this->store(201, ['store_id' => 31]);
        $this->assertSame([], $this->keys($this->board(10, ['branch' => 'f:101'])));
        $this->assertSame([], $this->keys($this->board(10, ['branch' => 'gs:31'])));
        $this->assertSame([], $this->keys($this->board(30, ['branch' => 'gs:31'])));
        $this->assertSame([], $this->keys($this->board(30, ['branch' => 'f:100'])));
        $this->assertSame(['legacy:1'], $this->keys($this->board(10, ['branch' => 'f:100'])));
        $this->assertSame(['store:200'], $this->keys($this->board(30, ['branch' => 'gs:30'])));
    }

    public function test_pagination_reaches_every_order_across_sources_and_clamps_out_of_range_pages(): void
    {
        for ($id = 1; $id <= 35; $id++) {
            $this->legacy($id);
            $this->store(200 + $id);
        }
        $first = $this->board();
        $second = $this->board(1, ['page_new' => 2]);
        $firstKeys = $this->keys($first);
        $secondKeys = $this->keys($second);
        $this->assertCount(50, $firstKeys);
        $this->assertCount(20, $secondKeys);
        $this->assertSame(70, $first['counts']['new']);
        $this->assertSame(70, $second['counts']['new']);
        $this->assertSame([], array_values(array_intersect($firstKeys, $secondKeys)));
        $this->assertCount(70, array_unique(array_merge($firstKeys, $secondKeys)));
        $this->assertSame(1, $first['pages']['new']['page']);
        $this->assertSame(2, $second['pages']['new']['page']);
        $this->assertSame(51, $second['pages']['new']['from']);
        $this->assertSame(70, $second['pages']['new']['to']);
        $clamped = $this->board(1, ['page_new' => 9999]);
        $this->assertSame(2, $clamped['pages']['new']['page']);
        $this->assertSame($secondKeys, $this->keys($clamped));
    }

    public function test_http_details_and_print_endpoints_use_scoped_checkout_data(): void
    {
        $this->store();
        $this->store(201, ['store_id' => 31]);
        $this->actingAs($this->actor(30), 'admin');
        $this->get(route('order-board.details', ['store', 200]))->assertOk()
            ->assertViewHas('printing', false)->assertViewHas('card', function (array $card) {
                return $card['source'] === 'store' && $card['id'] === 200
                    && $card['total'] === '100.00' && $card['items'][0]['name'] === 'Checkout product';
            });
        $this->get(route('order-board.print', ['store', 200]))->assertOk()
            ->assertViewHas('printing', true)->assertViewHas('card', function (array $card) {
                return $card['address'] === 'Checkout address، الدور 3، شقة 7';
            });
        $this->get(route('order-board.details', ['store', 201]))->assertNotFound();
        $this->get(route('order-board.print', ['store', 201]))->assertNotFound();
    }

    public function test_http_foreign_actions_are_denied_before_existing_vendor_lifecycle_is_called(): void
    {
        $this->legacy(1);
        $this->legacy(2, ['resturant_id' => 101]);
        $this->store();
        $this->actingAs($this->actor(10), 'admin');
        $vendor = \Mockery::mock(VendorOrders::class);
        $vendor->shouldReceive('acceptOrder')->once()->with(
            \Mockery::type(Request::class),
            \Mockery::on(function ($order) {
                return (int) $order->id === 1 && (int) $order->resturant_id === 100;
            })
        )->andReturnNull();
        app()->instance(VendorOrders::class, $vendor);
        $payload = ['action' => 'accept', 'expected_status' => 'pending', 'expected_accepted_notify' => ''];
        $this->postJson(route('order-board.action', ['legacy', 2]), $payload)->assertNotFound();
        $this->postJson(route('order-board.action', ['store', 200]), $payload + ['expected_revision' => 1])->assertNotFound();
        $this->postJson(route('order-board.action', ['legacy', 1]), $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('pending', DB::table('orders')->where('id', 2)->value('status'));
        $this->assertSame('pending', DB::table('go_store_orders')->where('id', 200)->value('status'));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_http_admin_store_action_uses_actual_owner_and_rejects_stale_revision(): void
    {
        $this->store();
        $this->actingAs($this->actor(1), 'admin');
        $payload = ['action' => 'accept', 'expected_status' => 'pending', 'expected_revision' => 0,
            'store_id' => 31, 'user_id' => 31, 'owner_id' => 31];
        $this->postJson(route('order-board.action', ['store', 200]), $payload)->assertStatus(409);
        $this->assertSame(0, DB::table('wallets')->count());
        $payload['expected_revision'] = 1;
        $this->postJson(route('order-board.action', ['store', 200]), $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('preparing', DB::table('go_store_orders')->where('id', 200)->value('status'));
        $this->assertSame(490.0, (float) DB::table('users')->where('id', 30)->value('balance'));
        $this->assertSame(500.0, (float) DB::table('users')->where('id', 31)->value('balance'));
    }

    public function test_http_branch_acceptance_keeps_the_existing_two_step_restaurant_lifecycle(): void
    {
        $this->legacy(1);
        $this->actingAs($this->actor(10), 'admin');
        Event::fake([\App\Events\OrderUpdated::class, \App\Events\UserUpdated::class]);
        $payload = ['action' => 'accept', 'expected_status' => 'pending', 'expected_accepted_notify' => ''];
        $this->postJson(route('order-board.action', ['legacy', 1]), $payload)->assertOk()->assertJsonPath('success', true);
        $order = DB::table('orders')->find(1);
        $this->assertSame('pending', $order->status);
        $this->assertSame('yes', $order->accepted_notify);
        $card = app(OrderBoardService::class)->detail('legacy', 1, $this->actor(10));
        $this->assertSame('preparing', $card['group']);
        $this->assertSame(['prepare', 'dispatch'], $card['actions']);
        $payload['action'] = 'reject';
        $this->postJson(route('order-board.action', ['legacy', 1]), $payload)->assertStatus(409);
        $this->assertSame('pending', DB::table('orders')->where('id', 1)->value('status'));
        $this->assertSame('yes', DB::table('orders')->where('id', 1)->value('accepted_notify'));
        $this->assertSame(200.0, (float) DB::table('users')->where('id', 20)->value('balance'));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    private function prepareLegacyFixture(): void
    {
        $this->legacy(1, ['accepted_notify' => 'yes', 'payment_type' => 'cash']);
        DB::table('users')->where('id', 20)->update(['email' => 'customer@example.test']);
        Schema::table('wallets', function (Blueprint $t) { $t->unsignedBigInteger('order_id')->nullable(); });
        Schema::table('resturants', function (Blueprint $t) { $t->string('km_price')->default('0'); });
        Schema::create('resturant_areas', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('resturant_id'); });
        foreach (['reviews', 'commissions'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('user_id');
            });
        }
        Schema::create('shippings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('order_id'); });
        Schema::create('delegate_notifications', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('delegate_id'); $t->string('status')->nullable();
        });
        require_once base_path('vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub');
        (new \CreateMediaTable())->up();
        Event::fake([
            \App\Events\OrderUpdated::class, \App\Events\UserUpdated::class,
            \App\Events\OrderFinishedUpdated::class, \App\Events\OrderStatusUpdated::class,
        ]);
        $this->actingAs($this->actor(10), 'admin');
    }

    private function assertPreparationCommittedWithoutWalletChanges(): void
    {
        $order = DB::table('orders')->find(1);
        $this->assertSame('accepted', $order->status);
        $this->assertSame('in_resturant', $order->delegate_from_out);
        $this->assertSame('yes', $order->accepted_notify);
        $board = $this->board(10);
        $this->assertSame(0, $board['counts']['preparing']);
        $this->assertSame(1, $board['counts']['courier']);
        $this->assertSame('legacy:1', $board['groups']['courier'][0]['key']);
        $this->assertSame('مع المندوب', $board['groups']['courier'][0]['status_label']);
        $this->assertSame(500.0, (float) DB::table('users')->where('id', 10)->value('balance'));
        $this->assertSame(200.0, (float) DB::table('users')->where('id', 20)->value('balance'));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_smtp_authentication_failure_does_not_rollback_board_prepare_json_action(): void
    {
        $this->prepareLegacyFixture();
        $sensitive = '535 Authentication Failed sensitive customer@example.test password-token-123';
        Mail::shouldReceive('send')->once()->andThrow(new \Swift_TransportException($sensitive));
        Log::spy();
        $response = $this->postJson(route('order-board.action', ['legacy', 1]), [
            'action' => 'prepare', 'expected_status' => 'pending', 'expected_accepted_notify' => 'yes',
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertPreparationCommittedWithoutWalletChanges();
        $this->assertStringNotContainsString($sensitive, $response->getContent());
        $this->assertStringNotContainsString('535', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with('Order status email unavailable', [
            'order_id' => 1, 'exception' => \Swift_TransportException::class,
        ]);
    }

    public function test_smtp_authentication_failure_does_not_rollback_details_prepare_html_action(): void
    {
        $this->prepareLegacyFixture();
        Mail::shouldReceive('send')->once()->andThrow(new \Swift_TransportException('535 Authentication Failed sensitive transport-token'));
        $response = $this->from(route('order-board.details', ['legacy', 1]))
            ->post(route('order-board.action', ['legacy', 1]), [
                'action' => 'prepare', 'expected_status' => 'pending', 'expected_accepted_notify' => 'yes',
            ])->assertRedirect(route('orders.applies'))->assertSessionHas('success');
        $this->assertPreparationCommittedWithoutWalletChanges();
        $this->assertStringNotContainsString('535', $response->getContent());
        $this->assertStringNotContainsString('transport-token', $response->getContent());
        $this->get(route('order-board.details', ['legacy', 1]))->assertOk()
            ->assertViewHas('card', function (array $card) { return $card['status'] === 'accepted'; });
    }

    public function test_successful_prepare_email_is_delivered_only_after_order_transaction_commits(): void
    {
        $this->prepareLegacyFixture();
        Mail::shouldReceive('send')->once()->andReturnUsing(function ($view, $data, $message) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('emails.send_order_email', $view);
            $this->assertSame('customer@example.test', $data['email']);
            $this->assertSame('accepted', DB::table('orders')->where('id', 1)->value('status'));
            $this->assertSame('in_resturant', DB::table('orders')->where('id', 1)->value('delegate_from_out'));
        });
        $this->postJson(route('order-board.action', ['legacy', 1]), [
            'action' => 'prepare', 'expected_status' => 'pending', 'expected_accepted_notify' => 'yes',
        ])->assertOk()->assertJsonPath('success', true);
        $this->assertPreparationCommittedWithoutWalletChanges();
    }

    public function test_order_email_is_never_sent_for_a_rolled_back_transaction(): void
    {
        $this->legacy(1);
        Mail::shouldReceive('send')->never();
        $mailer = app(BestEffortOrderMail::class);
        try {
            DB::transaction(function () use ($mailer) {
                DB::table('orders')->where('id', 1)->update(['status' => 'accepted']);
                $mailer->send(1, 'emails.send_order_email', ['email' => 'customer@example.test'], function () {});
                throw new \RuntimeException('Deliberate order transaction rollback');
            });
            $this->fail('Expected the order transaction to roll back.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Deliberate order transaction rollback', $error->getMessage());
        }
        $this->assertSame('pending', DB::table('orders')->where('id', 1)->value('status'));
        DB::transaction(function () { DB::table('orders')->where('id', 1)->update(['notes' => 'Later committed change']); });
        $this->assertSame('Later committed change', DB::table('orders')->where('id', 1)->value('notes'));
    }

    public function test_http_store_acceptance_cannot_mutate_a_same_id_fasakhansta_order(): void
    {
        $this->legacy(200);
        $this->store(200);
        $this->actingAs($this->actor(30), 'admin');
        $this->postJson(route('order-board.action', ['store', 200]), [
            'action' => 'accept', 'expected_status' => 'pending', 'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('success', true);
        $legacy = DB::table('orders')->find(200);
        $this->assertSame('pending', $legacy->status);
        $this->assertNull($legacy->accepted_notify);
        $this->assertSame('preparing', DB::table('go_store_orders')->where('id', 200)->value('status'));
        $this->assertSame(490.0, (float) DB::table('users')->where('id', 30)->value('balance'));
        $this->assertSame(200.0, (float) DB::table('users')->where('id', 20)->value('balance'));
    }

    public function test_http_board_endpoints_require_dashboard_access(): void
    {
        $this->legacy(1);
        $this->get(route('order-board.details', ['legacy', 1]))->assertRedirect(url('admin/login'));
        $this->actingAs($this->actor(20), 'admin');
        $this->get(route('order-board.details', ['legacy', 1]))->assertForbidden();
        $this->get(route('order-board.print', ['legacy', 1]))->assertForbidden();
        $this->postJson(route('order-board.action', ['legacy', 1]), [
            'action' => 'accept', 'expected_status' => 'pending',
        ])->assertForbidden();
    }

    public function test_full_board_uses_the_registered_live_feed_and_no_implicit_date_filter(): void
    {
        $this->legacy(1);
        $this->store();
        $this->services();
        $html = $this->exportQaView();
        $this->assertStringContainsString('data-feed-url="'.route('getOrders').'"', $html);
        $this->assertStringContainsString('data-default-date=""', $html);
        $this->getJson(route('getOrders'))->assertOk()->assertJsonPath('count', 7)
            ->assertJsonStructure(['html', 'counts', 'updated_at']);
    }

    public function test_default_board_keeps_older_open_orders_visible_until_a_date_is_selected(): void
    {
        $this->legacy(1, ['created_at' => now()->subDays(2)]);
        $this->legacy(2);
        $all = $this->board(10);
        $this->assertSame('', $all['filters']['date']);
        $this->assertEqualsCanonicalizing(['legacy:1', 'legacy:2'], $this->keys($all));
        $today = $this->board(10, ['date' => now()->toDateString()]);
        $this->assertSame(['legacy:2'], $this->keys($today));
    }

    public function test_selected_operating_date_includes_next_morning_and_excludes_exactly_six(): void
    {
        $this->legacy(1,['created_at'=>'2026-10-03 05:59:59']);
        $this->legacy(2,['created_at'=>'2026-10-03 06:00:00']);
        $this->legacy(3,['created_at'=>'2026-10-04 05:59:59']);
        $this->legacy(4,['created_at'=>'2026-10-04 06:00:00']);
        $this->assertEqualsCanonicalizing(['legacy:2','legacy:3'],$this->keys($this->board(10,['date'=>'2026-10-03'])));
        $this->assertCount(4,$this->keys($this->board(10)));
    }

    public function test_optional_go_tables_can_be_absent_after_a_legacy_server_restore(): void
    {
        foreach (['go_store_orders', 'go_stores', 'go_service_jobs', 'partner_service_requests'] as $table) {
            Schema::drop($table);
        }
        $this->legacy(1);
        $this->assertSame(['legacy:1'], $this->keys($this->board()));
        $this->assertSame(['legacy:1'], $this->keys($this->board(10)));
    }

    private function fakeGeneralSettings(): void
    {
        $values = [];
        foreach ((new \ReflectionClass(GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $values[$property->getName()] = (string) $property->getType() === 'bool' ? true : '';
        }
        $values['site_name'] = 'فسخانستا · إدارة التطبيقات';
        $values['service_fees'] = '0';
        GeneralSettings::fake($values);
    }

    public function test_confirmed_legacy_action_returns_the_new_card_without_waiting_for_a_full_board_feed(): void
    {
        $this->legacy(1);
        DB::table('users')->where('id', 20)->update(['name'=>'<script>customer</script>']);
        $this->actingAs($this->actor(10), 'admin');
        $response = $this->postJson(route('order-board.action', ['legacy', 1]), [
            'action'=>'accept', 'expected_status'=>'pending', 'expected_accepted_notify'=>'',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('card.key', 'legacy:1')
            ->assertJsonPath('card.from_group', 'new')->assertJsonPath('card.group', 'preparing');
        $html = $response->json('card.html');
        $this->assertStringContainsString('data-order-key="legacy:1"', $html);
        $this->assertStringContainsString('name="expected_accepted_notify" value="yes"', $html);
        $this->assertStringContainsString('value="prepare"', $html);
        $this->assertStringNotContainsString('value="accept"', $html);
        $this->assertStringNotContainsString('<script>customer</script>', $html);
        $this->assertStringNotContainsString('ob-column', $html);
        $this->assertDatabaseHas('orders', ['id'=>1, 'status'=>'pending', 'accepted_notify'=>'yes']);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_store_action_patch_contains_the_committed_revision_and_does_not_repeat_financial_movement(): void
    {
        $this->legacy(200);
        $this->store(200);
        $this->actingAs($this->actor(30), 'admin');
        $values = ['action'=>'accept', 'expected_status'=>'pending', 'expected_revision'=>1];
        $response = $this->postJson(route('order-board.action', ['store', 200]), $values)->assertOk()
            ->assertJsonPath('card.key', 'store:200')->assertJsonPath('card.from_group', 'new')
            ->assertJsonPath('card.group', 'preparing');
        $this->assertStringContainsString('name="expected_revision" value="2"', $response->json('card.html'));
        $this->assertStringContainsString('data-order-status="preparing"', $response->json('card.html'));
        $this->assertStringNotContainsString('data-order-key="legacy:200"', $response->json('card.html'));
        $this->assertDatabaseHas('users', ['id'=>30, 'balance'=>490]);
        $this->assertDatabaseHas('orders', ['id'=>200, 'accepted_notify'=>null]);
        $this->postJson(route('order-board.action', ['store', 200]), $values)->assertStatus(409);
        $this->assertDatabaseHas('users', ['id'=>30, 'balance'=>490]);
        $this->assertDatabaseHas('go_store_orders', ['id'=>200, 'revision'=>2]);
    }

    public function test_lightweight_action_authorization_matches_visible_actions_without_loading_receipt_data(): void
    {
        foreach ([
            1=>[], 2=>['accepted_notify'=>'yes'], 3=>['status'=>'accepted', 'delegate_from_out'=>'in_resturant'],
            4=>['status'=>'shipped'], 5=>['resturant_id'=>999],
            6=>['type'=>'shipping', 'resturant_id'=>null],
        ] as $id=>$values) $this->legacy($id, $values);
        $this->store();
        $this->store(201, ['status'=>'awaiting_payment', 'payment_method'=>'card', 'payment_status'=>'pending']);
        $this->services();
        $actor = $this->actor(1);
        $stores = app(\App\Services\Dashboard\GoStoreBoardActions::class);
        foreach ([['legacy',1], ['legacy',2], ['legacy',3], ['legacy',4], ['legacy',5], ['legacy',6],
            ['store',200], ['store',201], ['service',300], ['partner_service',301]] as [$source,$id]) {
            $service = app(OrderBoardService::class);
            DB::enableQueryLog(); DB::flushQueryLog();
            $state = $service->actionState($source, $id, $actor, $stores);
            $queries = DB::getQueryLog(); DB::disableQueryLog();
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/(?:from|join)\s+["`]?\b(?:carts|user_address|payments|resturant_products)\b/i', $query['query']);
            }
            $detail = $service->detail($source, $id, $actor);
            $this->assertSame($detail['actions'], $state['actions'], $source.':'.$id);
            $this->assertSame($detail['group'], $state['group'], $source.':'.$id);
        }
        $this->expectException(HttpException::class);
        app(OrderBoardService::class)->actionState('legacy', 1, $this->actor(11), $stores);
    }

    /** Optional export uses the real page and existing dashboard chrome for visual QA. */
    private function exportQaView(?string $path = null): string
    {
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->fakeGeneralSettings();
        config(['auth.defaults.guard' => 'admin']);
        $actor = $this->actor(1);
        $actor->setRelation('roles', collect([(new Role())->forceFill(['id' => 2, 'name' => 'QA administrative role', 'guard_name' => 'admin'])]));
        $actor->setRelation('permissions', collect());
        $actor->setRelation('media', collect());
        $actor->setRelation('unreadNotifications', collect());
        $this->actingAs($actor, 'admin');
        Gate::before(function () { return true; });
        $request = Request::create('/admin/applies-orders');
        $request->setRouteResolver(function () {
            return (new \Illuminate\Routing\Route('GET', 'admin/applies-orders', function () {}))->name('orders.applies');
        });
        app()->instance('request', $request);
        app()->setLocale('ar');
        URL::forceRootUrl(getenv('ORDER_BOARD_QA_BASE_URL') ?: 'http://127.0.0.1:8089');
        DB::table('users')->where('id', 1)->update(['name' => 'الإدارة']);
        DB::table('users')->where('id', 20)->update(['name' => 'أحمد محمد']);
        DB::table('users')->where('id', 21)->update(['name' => 'حسام محمود']);
        DB::table('resturants')->where('id', 100)->update(['name' => 'فسخانستا · المنصورة']);
        DB::table('resturants')->where('id', 101)->update(['name' => 'فسخانستا · المحلة']);
        foreach ([50 => 'accepted', 51 => 'shipped', 52 => 'completed'] as $id => $status) {
            $this->legacy($id, ['status' => $status, 'payment_type' => 'cash']);
        }
        DB::table('user_address')->insert(['id' => 601, 'address' => 'شارع الجمهورية، المنصورة', 'floor_no' => '3', 'apartment_no' => '7']);
        DB::table('orders')->update(['user_address_id' => 601, 'payment_type' => 'cash']);
        DB::table('resturant_products')->insert(['id' => 501, 'resturant_id' => 100, 'product_name' => 'فسيخ نبروه', 'price' => '90.00']);
        DB::table('carts')->insert(['order_id' => 1, 'user_id' => 20, 'resturant_id' => 100, 'resturant_product_id' => 501, 'qty' => 2, 'price' => '90.00']);
        $snapshot = json_decode(DB::table('go_store_orders')->where('id', 200)->value('snapshot'), true);
        $snapshot['store_name'] = 'متجر المدينة';
        $snapshot['customer_name'] = 'محمود علي';
        $snapshot['address']['address'] = 'شارع قناة السويس، المنصورة';
        $snapshot['items'][0]['name'] = 'أرز مصري';
        $snapshot['items'][0]['option_label'] = 'كيلو';
        $snapshot['notes'] = '';
        DB::table('go_store_orders')->where('id', 200)->update(['snapshot' => json_encode($snapshot)]);
        $html = view('admin.orders.board', [
            'board' => $this->board(), 'errors' => new \Illuminate\Support\ViewErrorBag(),
        ])->render();
        if ($path) file_put_contents($path, $html);
        return $html;
    }
}
