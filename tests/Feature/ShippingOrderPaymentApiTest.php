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
        Schema::table('shippings', function (Blueprint $t) { $t->unsignedBigInteger('user_id')->nullable(); $t->string('from_lat')->nullable(); $t->string('from_lng')->nullable(); });
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
}
