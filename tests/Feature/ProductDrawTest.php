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
        $this->artisan('draws:install-schema')->assertExitCode(0);
        \App\Database\SchemaMemory::forgetEverything();
        DB::table('resturants')->insert(['id' => 302, 'name' => 'فرع المنصورة', 'status' => 'opened']);
        DB::table('resturant_products')->insert(['id' => 11, 'resturant_id' => 302, 'product_id' => 5, 'product_name' => 'فسيخ كيلو', 'product_price' => '400',
            'price' => DB::table('resturant_products')->where('id', 10)->value('price')]);
        DB::table('coupon_wheels')->insert(['id' => 1, 'added_by' => 1, 'name' => 'سحب الفسيخ', 'price' => 0, 'prize_amount' => 5000, 'policy' => 'product', 'product_id' => 5,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'status' => 'show']);
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

    public function test_the_product_carries_the_prize_badge_in_the_participating_branches_only(): void
    {
        $this->assertTrue(ProductDraw::ready());
        $badge = ProductDraw::badge(301, 5);
        $this->assertSame(['coupon_wheel_id' => 1, 'prize_amount' => 5000.0, 'label' => 'يؤهلك لسحب على 5,000 جنيه'],
            array_intersect_key($badge, array_flip(['coupon_wheel_id', 'prize_amount', 'label'])));
        $this->assertNull(ProductDraw::badge(302, 5), 'Not a participating branch');
        $this->assertNull(ProductDraw::badge(301, 6), 'Another product');
        DB::table('coupon_wheels')->where('id', 1)->update(['end_date' => '2026-11-11']);
        $this->assertNull(ProductDraw::badge(301, 5), 'An ended draw shows no badge');
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
        // Half a kilo is one entry.
        $this->deliver($this->order(501, 301, 10, 0.5));
        $this->assertSame(1, $this->entries(501));
        // Another branch's order is not in this draw.
        $this->deliver($this->order(502, 302, 11, 2));
        $this->assertSame(0, $this->entries(502));
        $this->assertSame(4, ProductDraw::entries(1, 7));
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
        DB::table('coupon_wheels')->insert(['id' => 3, 'added_by' => 1, 'name' => 'سحب فاضي', 'price' => 0, 'policy' => 'product', 'product_id' => 6,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'show']);
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
        $this->assertMatchesRegularExpression('/<option value="5"\s+selected[^>]*>فسيخ</', $html);
        $rules = (new \App\Http\Requests\Dashboard\StoreCouponWheelRequest())->merge(['policy' => 'product'])->rules();
        $this->assertSame('nullable|numeric|min:0', $rules['price']);
        $this->assertSame('required|numeric|min:1', (new \App\Http\Requests\Dashboard\StoreCouponWheelRequest())->rules()['price']);
    }
}
