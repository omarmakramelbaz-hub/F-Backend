<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Draws\ProductDraw;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * «مسابقة السحب» by product: a product picked on the dashboard carries a badge with the prize in the participating
 * branches; each piece of it in a delivered order is one entry; the winner is drawn from the entries. The order-total
 * draws keep their own rule.
 */
class ProductDrawTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(12, 0));
        // The live tables as they are before the draw columns.
        Schema::create('coupon_wheels', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('added_by'); $t->string('name'); $t->decimal('price', 8, 2); $t->decimal('prize_amount', 10, 2)->nullable();
            $t->date('start_date')->nullable(); $t->date('end_date')->nullable(); $t->decimal('amount', 8, 2)->nullable(); $t->string('status')->default('show'); $t->timestamps();
        });
        Schema::create('coupon_wheel_resturants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('coupon_wheel_id'); $t->unsignedBigInteger('resturant_id'); $t->timestamps(); });
        Schema::create('coupon_subscripes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('user_coupon_code'); $t->decimal('price', 8, 2); $t->decimal('amount', 8, 2);
            $t->string('status')->nullable(); $t->unsignedBigInteger('coupon_wheel_id'); $t->timestamps();
        });
        if (!Schema::hasColumn('orders', 'coupon_wheel_id')) Schema::table('orders', fn (Blueprint $t) => $t->unsignedBigInteger('coupon_wheel_id')->nullable());
        if (!Schema::hasColumn('orders', 'updated_total')) Schema::table('orders', fn (Blueprint $t) => $t->decimal('updated_total', 10, 2)->nullable());
        if (!Schema::hasTable('user_tokens')) Schema::create('user_tokens', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('token'); $t->timestamps(); });
        $this->artisan('draws:install-schema')->assertExitCode(0);
        \App\Database\SchemaMemory::forgetEverything();
        DB::table('resturants')->insert(['id' => 302, 'name' => 'فرع المنصورة', 'status' => 'opened']);
        DB::table('resturant_products')->insert(['id' => 11, 'resturant_id' => 302, 'product_id' => 5, 'product_name' => 'فسيخ كيلو', 'product_price' => '400',
            'price' => DB::table('resturant_products')->where('id', 10)->value('price')]);
        DB::table('products')->insert(['id' => 6, 'name_ar' => 'رنجة', 'name_en' => 'Herring']);
        DB::table('products')->insert(['id' => 7, 'name_ar' => 'سردين', 'name_en' => 'Sardine']);
        DB::table('resturant_products')->insert(['id' => 12, 'resturant_id' => 301, 'product_id' => 6, 'product_name' => 'رنجة', 'product_price' => '200',
            'price' => DB::table('resturant_products')->where('id', 10)->value('price')]);
        DB::table('coupon_wheels')->insert(['id' => 1, 'added_by' => 1, 'name' => 'سحب الفسيخ', 'price' => 0, 'prize_amount' => 5000, 'policy' => 'product',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'show']);
        // Chosen by the items' names on the branches' menus (written «رنجه» here, «رنجة» on the menu).
        ProductDraw::choose(1, ['فسيخ كيلو', 'رنجه']);
        DB::table('coupon_wheel_resturants')->insert(['coupon_wheel_id' => 1, 'resturant_id' => 301]);
    }

    private function order(int $id, int $restaurant, int $product, float $qty): Order
    {
        DB::table('orders')->insert(['id' => $id, 'user_id' => 7, 'resturant_id' => $restaurant, 'status' => 'accepted', 'type' => 'current', 'payment_type' => 'cash']);
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => $restaurant, 'resturant_product_id' => $product, 'qty' => $qty, 'price' => 400, 'order_id' => $id, 'is_order' => true]);
        return Order::withoutGlobalScopes()->find($id);
    }

    private function deliver(Order $order): void
    {
        $order->status = 'completed';
        $order->save();
    }

    private function entries(?int $order = null): int
    {
        return DB::table('coupon_subscripes')->where('coupon_wheel_id', 1)->when($order, fn ($q) => $q->where('order_id', $order))->count();
    }

    public function test_the_chosen_products_carry_the_prize_badge_in_every_branch(): void
    {
        $this->assertTrue(ProductDraw::ready());
        $badge = ProductDraw::badge('فسيخ كيلو');
        $this->assertSame(['coupon_wheel_id' => 1, 'prize_amount' => 5000.0, 'label' => 'يؤهلك للدخول في السحب بقيمة 5,000 جنيه'],
            array_intersect_key($badge, array_flip(['coupon_wheel_id', 'prize_amount', 'label'])));
        $this->assertSame(1, ProductDraw::badge(' فسيخ  كيلو ')['coupon_wheel_id'], 'The same item on another branch menu, spaces aside');
        $this->assertSame(1, ProductDraw::badge('رنجة')['coupon_wheel_id'], 'Every chosen item carries it, ة or ه');
        $this->assertNull(ProductDraw::badge('سردين'), 'An item not chosen');
        $this->assertNull(ProductDraw::badge('فسيخ كيلو مخلي'), 'Another item with a longer name');
        DB::table('coupon_wheels')->where('id', 1)->update(['end_date' => '2026-11-11']);
        $this->assertNull(ProductDraw::badge('فسيخ كيلو'), 'An ended draw shows no badge');
    }

    public function test_each_piece_in_a_delivered_order_is_one_entry_once(): void
    {
        $order = $this->order(500, 301, 10, 3);
        $this->assertSame(0, $this->entries(), 'Nothing before it is delivered');
        $this->deliver($order);
        $this->assertSame(3, $this->entries(500));
        $this->assertSame(3, DB::table('coupon_subscripes')->where('order_id', 500)->distinct()->count('user_coupon_code'), 'Each entry has its own code');
        // Saved again (or completed again by a retry): no more entries.
        $this->assertSame(0, app(ProductDraw::class)->enter($order->fresh()));
        $this->assertSame(3, $this->entries(500));
        // Pieces of every chosen product count: 2 of one and 1 of the other.
        $both = $this->order(503, 301, 10, 2);
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 12, 'qty' => 1, 'price' => 200, 'order_id' => 503, 'is_order' => true]);
        $this->deliver($both);
        $this->assertSame(3, $this->entries(503));
        // Half a kilo is one entry.
        $this->deliver($this->order(501, 301, 10, 0.5));
        $this->assertSame(1, $this->entries(501));
        // Bought from any branch.
        $this->deliver($this->order(502, 302, 11, 2));
        $this->assertSame(2, $this->entries(502));
        $this->assertSame(9, ProductDraw::entries(1, 7));
        // Each order's new codes are sent to the customer (once: the second save sent nothing).
        Notification::assertSentToTimes(\App\Models\User::withoutGlobalScopes()->find(7), \App\Notifications\NotifyUserDrawEntryNotification::class, 4);
        $notice = null;
        Notification::assertSentTo(\App\Models\User::withoutGlobalScopes()->find(7), \App\Notifications\NotifyUserDrawEntryNotification::class,
            function ($sent) use (&$notice) { $notice = $sent; return true; });
        $text = $notice->toDatabase(\App\Models\User::withoutGlobalScopes()->find(7))['text'];
        $this->assertStringContainsString('بقيمة 5,000 جنيه', $text);
        $this->assertStringContainsString('تابع الكود الفائز على شاشة المسابقة', $text);
    }

    public function test_the_draw_screen_shows_the_customers_codes_and_the_winning_code(): void
    {
        $this->deliver($this->order(500, 301, 10, 2));
        $codes = DB::table('coupon_subscripes')->where('coupon_wheel_id', 1)->orderBy('id')->pluck('user_coupon_code')->all();
        $draw = (new \App\Http\Resources\Api\Home\CouponWheelResource(\App\Models\CouponWheel::find(1)))->resolve(request());
        $this->assertSame(['product', 'يؤهلك للدخول في السحب بقيمة 5,000 جنيه', ['فسيخ كيلو', 'رنجه'], [], null],
            [$draw['policy'], $draw['badge_label'], array_column($draw['products'], 'name'), $draw['my_codes'], $draw['winner_code']]);
        $this->travelTo(now()->setDate(2026, 12, 1));
        $this->artisan('notify:coupon')->assertExitCode(0);
        $winner = DB::table('coupon_subscripes')->where('status', 'winner')->value('user_coupon_code');
        $this->assertContains($winner, $codes);
        $draw = (new \App\Http\Resources\Api\Home\CouponWheelResource(\App\Models\CouponWheel::find(1)))->resolve(request());
        $this->assertSame($winner, $draw['winner_code']);
    }

    public function test_the_order_total_draw_keeps_its_own_rule(): void
    {
        DB::table('coupon_wheels')->insert(['id' => 2, 'added_by' => 1, 'name' => 'سحب الطلبات', 'price' => 100, 'prize_amount' => 1000, 'policy' => 'order_total',
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'show']);
        DB::table('coupon_wheel_resturants')->insert(['coupon_wheel_id' => 2, 'resturant_id' => 301]);
        $order = $this->order(500, 301, 10, 1);
        DB::table('orders')->where('id', 500)->update(['updated_total' => 450]);
        $this->deliver($order->fresh());
        $this->assertSame(2, (int) DB::table('orders')->where('id', 500)->value('coupon_wheel_id'), 'The order joins the order-total draw');
        $this->assertSame(1, DB::table('coupon_subscripes')->where('coupon_wheel_id', 2)->count());
        $this->assertSame(1, $this->entries(500), 'And its piece is an entry in the product draw');
    }

    public function test_the_winner_is_drawn_from_the_entries_after_the_draw_ends(): void
    {
        $this->deliver($this->order(500, 301, 10, 2));
        DB::table('coupon_wheels')->insert(['id' => 3, 'added_by' => 1, 'name' => 'سحب فاضي', 'price' => 0, 'policy' => 'product',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'show']);
        ProductDraw::choose(3, ['سردين']);
        $this->travelTo(now()->setDate(2026, 12, 1));
        $this->artisan('notify:coupon')->assertExitCode(0);
        $this->assertSame(['loser', 'winner'], DB::table('coupon_subscripes')->where('coupon_wheel_id', 1)->orderBy('status')->pluck('status')->all());
        Notification::assertSentTo(\App\Models\User::withoutGlobalScopes()->find(7), \App\Notifications\NotifyUserCouponWheelWinnerNotification::class);
        // Run again: the winner stays.
        $winner = DB::table('coupon_subscripes')->where('status', 'winner')->value('id');
        $this->artisan('notify:coupon')->assertExitCode(0);
        $this->assertSame($winner, DB::table('coupon_subscripes')->where('status', 'winner')->value('id'));
    }

    public function test_the_schema_command_can_run_again(): void
    {
        $this->artisan('draws:install-schema')->expectsOutput('{"result":"DRAW_SCHEMA_READY","added":[]}')->assertExitCode(0);
    }

    public function test_the_dashboard_form_offers_the_policy_and_the_product(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'الأدمن', 'mobile' => '1000000001', 'account_type' => 'admin', 'status' => 'accepted']);
        if (!Schema::hasColumn('products', 'status')) Schema::table('products', fn (Blueprint $t) => $t->string('status')->default('show'));
        $this->actingAs(\App\Models\User::withoutGlobalScopes()->find(1), 'admin');
        $html = view('admin.coupon_wheels.form', ['coupon_wheel' => \App\Models\CouponWheel::find(1), 'errors' => new \Illuminate\Support\ViewErrorBag()])->render();
        $this->assertStringContainsString('name="policy"', $html);
        $this->assertMatchesRegularExpression('/<option value="product"\s+selected/', $html);
        // The branches' menu items, once each, the chosen ones selected.
        $this->assertSame(1, substr_count($html, '>فسيخ كيلو</option>'));
        $this->assertMatchesRegularExpression('/<option value="فسيخ كيلو"\s+selected[^>]*>فسيخ كيلو</', $html);
        $this->assertMatchesRegularExpression('/<option value="رنجة"\s+selected[^>]*>رنجة</', $html);
        $rules = (new \App\Http\Requests\Dashboard\StoreCouponWheelRequest())->merge(['policy' => 'product'])->rules();
        $this->assertSame('nullable|numeric|min:0', $rules['price']);
        $this->assertSame('nullable|array', $rules['restraunt_id'], 'A draw by products runs in every branch');
        $this->assertSame('required|numeric|min:1', (new \App\Http\Requests\Dashboard\StoreCouponWheelRequest())->rules()['price']);
    }

    public function test_a_collected_website_order_gives_entries_to_the_app_account_with_the_same_mobile(): void
    {
        Schema::create('pos_service_tickets', function (Blueprint $t) {
            $t->id(); $t->string('branch', 40); $t->string('channel', 10); $t->string('status', 30); $t->string('payment_status', 10)->default('unpaid');
            $t->string('customer_phone', 30)->nullable(); $t->mediumText('cart_snapshot'); $t->timestamps();
        });
        Schema::create('web_storefront_orders', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('ticket_id'); $t->unsignedBigInteger('branch_id'); $t->timestamps(); });
        DB::table('users')->where('id', 7)->update(['mobile' => '1012345678']);
        $cart = json_encode(['branch' => 'f:301', 'items' => [['product_id' => 10, 'quantity' => '2.000'], ['product_id' => 12, 'quantity' => '0.5']]]);
        DB::table('pos_service_tickets')->insert([
            ['id' => 900, 'branch' => 'f:301', 'channel' => 'phone', 'status' => 'finished', 'payment_status' => 'paid', 'customer_phone' => '01012345678', 'cart_snapshot' => $cart],
            ['id' => 901, 'branch' => 'f:301', 'channel' => 'phone', 'status' => 'finished', 'payment_status' => 'paid', 'customer_phone' => '01012345678', 'cart_snapshot' => $cart],
            ['id' => 902, 'branch' => 'f:301', 'channel' => 'phone', 'status' => 'finished', 'payment_status' => 'paid', 'customer_phone' => '01099999999', 'cart_snapshot' => $cart],
            ['id' => 903, 'branch' => 'f:301', 'channel' => 'phone', 'status' => 'finished', 'payment_status' => 'unpaid', 'customer_phone' => '01012345678', 'cart_snapshot' => $cart],
        ]);
        DB::table('web_storefront_orders')->insert([['ticket_id' => 900, 'branch_id' => 301], ['ticket_id' => 902, 'branch_id' => 301], ['ticket_id' => 903, 'branch_id' => 301]]);
        $draws = app(ProductDraw::class);
        // 2 of one chosen product and half a kilo of the other: 3 entries, for the customer whose app account has this mobile.
        $this->assertSame(3, $draws->enterWebsiteTicket(900));
        $this->assertSame(0, $draws->enterWebsiteTicket(900), 'Once');
        $this->assertSame(3, DB::table('coupon_subscripes')->where('user_id', 7)->where('entry_key', 'like', 'ticket:900:%')->count());
        $this->assertSame(0, $draws->enterWebsiteTicket(901), 'A delivery order not from the website');
        $this->assertSame(0, $draws->enterWebsiteTicket(902), 'No app account with this mobile');
        $this->assertSame(0, $draws->enterWebsiteTicket(903), 'Not collected yet');
        $this->assertSame(7, ProductDraw::customerByPhone('+201012345678'));
    }

    public function test_a_draw_saved_with_catalog_products_keeps_them_by_name(): void
    {
        Schema::drop('coupon_wheel_items');
        Schema::create('coupon_wheel_products', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('coupon_wheel_id'); $t->unsignedBigInteger('product_id'); $t->timestamps(); });
        DB::table('coupon_wheel_products')->insert(['coupon_wheel_id' => 1, 'product_id' => 5]);
        \App\Database\SchemaMemory::forgetEverything();
        $this->artisan('draws:install-schema')->assertExitCode(0);
        $this->assertSame(['فسيخ'], ProductDraw::items(1));
        $this->assertNull(ProductDraw::badge('فسيخ كيلو'), 'Matched by the whole name');
    }
}
