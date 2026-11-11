<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A customer's delivery request (/api/user/shipping/order/payment): the price is the one proposed when the request was
 * made (and agreed with a courier later), never one sent at payment; the wallet pays it once.
 */
class ShippingOrderPaymentApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Notification::fake();
        Schema::table('shippings', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable(); $t->decimal('expected_price', 10, 2)->nullable();
            foreach (['from_lat', 'from_lng', 'to_lat', 'to_lng', 'from_address', 'to_address', 'description'] as $column) $t->string($column)->nullable();
        });
        foreach (['connected', 'lat', 'lng'] as $column) if (!Schema::hasColumn('users', $column)) Schema::table('users', fn (Blueprint $t) => $t->string($column)->nullable());
        DB::table('orders')->insert(['id' => 600, 'user_id' => 7, 'status' => null, 'type' => 'shipping', 'payment_type' => 'cash', 'delivery_price' => 40]);
        DB::table('shippings')->insert(['order_id' => 600, 'user_id' => 7, 'actual_price' => 40, 'from_lat' => '30.08', 'from_lng' => '31.24']);
        // The courier search (MySQL distance query) is not what is checked here: it only records that it was asked.
        $this->app->bind(\App\Http\Controllers\Api\V1\ShippingController::class, fn () => new class extends \App\Http\Controllers\Api\V1\ShippingController {
            public function searchDelegates($order_id) { return 'success'; }
        });
    }

    private function pay(array $values, int $user = 7)
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), 'ShippingController@order_payment'));
        return $this->withHeaders($this->appHeaders($user))->json($route->methods()[0], '/'.$route->uri(), $values);
    }

    private function order(): object { return DB::table('orders')->where('id', 600)->first(); }

    public function test_paying_never_changes_the_price_or_what_the_server_decides(): void
    {
        $this->pay(['payment_type' => 'wallet', 'delivery_price' => 1, 'delegate_from_out' => 'out_resturant', 'status' => 'completed',
            'transfer_price_by' => 'admin', 'user_id' => 8, 'delegate_id' => 5]);
        $order = $this->order();
        $this->assertSame(['pending', 7, 40.0, null], [$order->status, (int) $order->user_id, (float) $order->delivery_price, $order->transfer_price_by]);
        $this->assertEquals(1000 - 40, (float) DB::table('users')->where('id', 7)->value('balance'), 'The agreed price is paid');
        $this->assertEquals(40, (float) DB::table('wallets')->where('order_id', 600)->value('amount'));
    }

    public function test_a_wallet_without_enough_balance_pays_nothing(): void
    {
        DB::table('users')->where('id', 7)->update(['balance' => 39]);
        $this->pay(['payment_type' => 'wallet']);
        $this->assertNull($this->order()->status);
        $this->assertEquals(39, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_only_the_known_payment_types(): void
    {
        $this->assertNotSame(200, $this->pay(['payment_type' => 'free'])->status());
        $this->assertSame([null, 'cash'], [$this->order()->status, $this->order()->payment_type]);
    }

    private function shipping(string $action, array $values, int $user = 7)
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), 'ShippingController@'.$action));
        return $this->withHeaders($this->appHeaders($user))->json($route->methods()[0], '/'.$route->uri(), $values);
    }

    private function balance(): float { return (float) DB::table('users')->where('id', 7)->value('balance'); }

    public function test_a_negative_or_zero_price_never_adds_to_the_wallet(): void
    {
        if (!Schema::hasTable('payments')) Schema::create('payments', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('order_id'), $t->boolean('status')->default(false)]);
        foreach ([-500, 0] as $price) {
            $this->assertNotSame(200, $this->shipping('store', ['from_lat' => '30', 'from_lng' => '31', 'to_lat' => '30.1', 'to_lng' => '31.1', 'from_address' => 'أ', 'to_address' => 'ب',
                'description' => 'x', 'actual_price' => $price, 'expected_price' => 40, 'payment_type' => 'wallet'])->status());
        }
        $this->assertEquals(1000, $this->balance());
        $this->assertSame(1, DB::table('orders')->where('type', 'shipping')->count(), 'No new request');
    }

    public function test_changing_a_paid_requests_price_moves_only_the_difference_and_never_below_zero(): void
    {
        DB::table('orders')->where('id', 600)->update(['status' => 'pending', 'payment_type' => 'wallet']);
        DB::table('shippings')->where('order_id', 600)->update(['expected_price' => 40]);
        // A negative price (which would refund more than was paid) is refused.
        $this->assertNotSame(200, $this->shipping('updated_actual_price', ['order_id' => 600, 'new_price' => -5000])->status());
        $this->assertEquals(1000, $this->balance());
        // Raised beyond the wallet: refused, nothing taken.
        DB::table('users')->where('id', 7)->update(['balance' => 5]);
        $this->assertNotSame(200, $this->shipping('updated_actual_price', ['order_id' => 600, 'new_price' => 50])->status());
        $this->assertSame([5.0, 40.0], [$this->balance(), (float) DB::table('orders')->where('id', 600)->value('delivery_price')]);
        // Raised within the wallet: the difference is taken.
        DB::table('users')->where('id', 7)->update(['balance' => 100]);
        $this->shipping('updated_actual_price', ['order_id' => 600, 'new_price' => 50])->assertOk();
        $this->assertSame([90.0, 50.0], [$this->balance(), (float) DB::table('orders')->where('id', 600)->value('delivery_price')]);
        // Lowered: the difference comes back.
        $this->shipping('updated_actual_price', ['order_id' => 600, 'new_price' => 45])->assertOk();
        $this->assertEquals(95, $this->balance());
        // Another customer's request: not found.
        $this->assertNotSame(200, $this->shipping('updated_actual_price', ['order_id' => 600, 'new_price' => 40], 8)->status());
    }

    /** A courier (20) offered 100 on the request; the app's commission on deliveries is 10%. */
    private function courierOffer(): void
    {
        foreach (['offer_price' => 'decimal', 'commission_amount' => 'decimal'] as $column => $type) {
            if (!Schema::hasColumn('delegate_notifications', $column)) Schema::table('delegate_notifications', fn (Blueprint $t) => $t->decimal($column, 10, 2)->nullable());
        }
        DB::table('users')->insert(['id' => 20, 'name' => 'مندوب', 'mobile' => '1000000020', 'account_type' => 'delegate', 'status' => 'accepted', 'balance' => 100]);
        if (!DB::table('users')->where('id', 1)->exists()) DB::table('users')->insert(['id' => 1, 'name' => 'الأدمن', 'mobile' => '1000000001', 'account_type' => 'admin', 'status' => 'accepted']);
        DB::table('orders')->where('id', 600)->update(['status' => 'pending']);
        DB::table('delegate_notifications')->insert(['order_id' => 600, 'delegate_id' => 20, 'status' => 'accepted', 'offer_price' => 100]);
        DB::table('settings')->where('group', 'general')->where('name', 'shipping_min_price')->update(['payload' => json_encode('10')]);
    }

    private function uri(string $action, array $parameters): string
    {
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), $action));
        return '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => $parameters[$m[1]], $route->uri());
    }

    private function courierBalance(): float { return (float) DB::table('users')->where('id', 20)->value('balance'); }

    public function test_only_the_requests_customer_accepts_or_declines_a_courier_or_sees_the_offers(): void
    {
        $this->courierOffer();
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'accepted'], 8)->assertJson(['status' => 'Error']);
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'declined'], 8)->assertJson(['status' => 'Error']);
        $this->assertSame(['pending', 1], [$this->order()->status, DB::table('delegate_notifications')->where('order_id', 600)->count()]);
        $this->assertEquals(100, $this->courierBalance());
        $this->withHeaders($this->appHeaders(8))->getJson($this->uri('ShippingController@accepted_delegates', ['id' => 600]))->assertJson(['status' => 'Error']);
        $this->withHeaders($this->appHeaders(7))->getJson($this->uri('ShippingController@accepted_delegates', ['id' => 600]))->assertJson(['status' => 'Success']);
    }

    public function test_accepting_a_courier_charges_the_commission_once(): void
    {
        $this->courierOffer();
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'accepted'])->assertJson(['status' => 'Success']);
        $this->assertSame(['accepted', 20, 100.0], [$this->order()->status, (int) $this->order()->delegate_id, (float) $this->order()->delivery_price]);
        $this->assertEquals(90, $this->courierBalance());
        // The same tap again: nothing more is taken.
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'accepted'])->assertJson(['status' => 'Error']);
        $this->assertEquals(90, $this->courierBalance());
        $this->assertSame(1, DB::table('wallets')->where('order_id', 600)->where('from_user', 20)->count());
        // The courier taken is not dropped by a decline afterwards.
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'declined'])->assertJson(['status' => 'Error']);
        $this->assertSame(1, DB::table('delegate_notifications')->where('order_id', 600)->where('delegate_id', 20)->count());
    }

    public function test_a_courier_without_the_commission_is_not_taken(): void
    {
        $this->courierOffer();
        DB::table('users')->where('id', 20)->update(['balance' => 5]);
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'accepted'])->assertJson(['status' => 'Error']);
        $this->assertSame(['pending', null], [$this->order()->status, $this->order()->delegate_id]);
        $this->assertEquals(5, $this->courierBalance());
    }

    public function test_a_price_revision_the_customer_accepts_settles_the_commission_once(): void
    {
        $this->courierOffer();
        $this->shipping('accept_delegate', ['order_id' => 600, 'delegate_id' => 20, 'status' => 'accepted'])->assertJson(['status' => 'Success']);
        // The courier asks 150: 5 more commission when the customer accepts.
        DB::table('delegate_notifications')->where('order_id', 600)->update(['status' => 'price_revision', 'offer_price' => 150]);
        $respond = fn (int $user) => $this->withHeaders($this->appHeaders($user))->postJson($this->uri('DelegateOrderController@respondShippingRevision', ['order' => 600]), ['status' => 'accepted']);
        $respond(8)->assertJson(['status' => 'Error']);
        $this->assertEquals(90, $this->courierBalance());
        $respond(7)->assertJson(['status' => 'Success']);
        $this->assertEquals(85, $this->courierBalance());
        $this->assertSame([150.0, 'accepted'], [(float) $this->order()->delivery_price, DB::table('delegate_notifications')->where('order_id', 600)->value('status')]);
        $respond(7)->assertJson(['status' => 'Error']);
        $this->assertEquals(85, $this->courierBalance(), 'Settled once');
    }
}
