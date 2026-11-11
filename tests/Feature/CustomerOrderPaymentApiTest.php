<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The customer app's checkout (/api/user/order/payment): the cart becomes an order priced from its items, the delivery
 * the app sent, the customer's tax and the service fee; paid in cash or from the wallet. What a customer may and may
 * not decide about their own order.
 */
class CustomerOrderPaymentApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
    }

    private function pay(array $values, int $user = 7)
    {
        app('auth')->forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        $route = collect(app('router')->getRoutes())->first(fn ($route) => str_ends_with($route->getActionName(), 'CartController@order_payment'));
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser(User::query()->find($user)), 'Accept' => 'application/json'])
            ->json($route->methods()[0], '/'.$route->uri(), $values + ['user_address_id' => 70, 'delivery_price' => 25]);
    }

    private function order(): object
    {
        return \App\Models\Order::withoutGlobalScopes()->firstOrFail();
    }

    public function test_a_cash_order_takes_the_cart_and_waits_for_the_branch(): void
    {
        $id = $this->pay(['payment_type' => 'cash', 'notes' => 'من غير بصل'])->assertOk()->assertJsonPath('status', 'Success')->json('data.id');
        $order = $this->order();
        $this->assertSame([(int) $id, 7, 301, 'pending', 'current', 'cash', 'من غير بصل'],
            [$order->id, (int) $order->user_id, (int) $order->resturant_id, $order->status, $order->type, $order->payment_type, $order->notes]);
        // 800 of items + 25 delivery + 14% tax on the items (112).
        $this->assertEquals(800, $order->total);
        $this->assertEquals(112, (float) $order->user_tax);
        $this->assertEquals(937, $order->grand_total);
        $this->assertSame(0, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count(), 'The cart became the order');
        Bus::assertDispatchedAfterResponse(\App\Jobs\SendOrderCreatedAfterResponse::class);
    }

    public function test_a_wallet_order_takes_its_whole_price_from_the_balance(): void
    {
        $live = new class { public array $events = []; public function event(object $event, ?int $orderId = null): void { $this->events[] = $event; } };
        $this->app->instance(\App\Services\Dashboard\OrderProviderDelivery::class, $live);
        $this->pay(['payment_type' => 'wallet'])->assertOk();
        $balance = collect($live->events)->first(fn ($event) => $event instanceof \App\Events\BalanceUpdated);
        $this->assertNotNull($balance, 'The app is told the new balance');
        $this->assertSame([7, 63.0], [(int) $balance->senderId, (float) $balance->user->balance]);
        $order = $this->order();
        $this->assertSame('pending', $order->status);
        $this->assertEquals(1000 - 937, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertEquals(937, (float) DB::table('wallets')->where('order_id', $order->id)->value('amount'));
    }

    public function test_a_customer_never_sets_what_the_order_costs_or_whose_and_which_it_is(): void
    {
        // A changed app asking for its order to be priced as a wallet top-up (nothing to pay), for another customer and branch,
        // already delivered, with the totals and taxes of its choice.
        $this->pay(['payment_type' => 'wallet', 'type' => 'wallet', 'user_id' => 8, 'resturant_id' => 94, 'status' => 'delivered',
            'user_tax' => 0, 'tax' => 0, 'vendor_tax' => 0, 'delegate_id' => 5, 'id' => 999])->assertOk();
        $order = $this->order();
        $this->assertSame(['current', 7, 301, 'pending', null], [$order->type, (int) $order->user_id, (int) $order->resturant_id, $order->status, $order->delegate_id]);
        $this->assertEquals(937, $order->grand_total);
        $this->assertEquals(1000 - 937, (float) DB::table('users')->where('id', 7)->value('balance'), 'The whole price is paid');
    }

    public function test_a_refused_checkout_leaves_the_cart_as_it_was(): void
    {
        $cartBefore = DB::table('carts')->where('user_id', 7)->get(['id', 'is_order', 'order_id'])->toArray();
        // Not enough in the wallet (937 to pay).
        DB::table('users')->where('id', 7)->update(['balance' => 900]);
        $this->pay(['payment_type' => 'wallet'])->assertStatus(500)->assertJsonPath('status', 'Error');
        // Below the branch's minimum order.
        DB::table('resturants')->where('id', 301)->update(['min_order_price' => 5000]);
        $this->pay(['payment_type' => 'cash'])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertEquals($cartBefore, DB::table('carts')->where('user_id', 7)->get(['id', 'is_order', 'order_id'])->toArray(), 'The cart is still there to pay');
        $this->assertSame(0, DB::table('orders')->count(), 'No order left behind');
        $this->assertEquals(900, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_the_wallet_pays_one_order_with_the_same_money_once(): void
    {
        DB::table('users')->where('id', 7)->update(['balance' => 937]);
        $this->pay(['payment_type' => 'wallet'])->assertOk();
        $this->assertEquals(0, (float) DB::table('users')->where('id', 7)->value('balance'));
        // A second order from the same (now empty) balance is refused, and its cart stays.
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 10, 'qty' => 2, 'price' => 400]);
        $this->pay(['payment_type' => 'wallet'])->assertStatus(500);
        $this->assertEquals(0, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(1, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count());
    }

    private function cancel(int $order, int $user = 7)
    {
        app('auth')->forgetGuards();
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser(User::query()->find($user)), 'Accept' => 'application/json'])
            ->postJson('/api/user/cancel/order/'.$order);
    }

    public function test_a_customer_cancels_their_own_waiting_order_once(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $id = $this->pay(['payment_type' => 'cash'])->assertOk()->json('data.id');
        // Not someone else's order (OrderCancellationController serves this address): refused, nothing changes.
        $this->cancel($id, 8)->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame('pending', DB::table('orders')->where('id', $id)->value('status'));
        $this->cancel($id)->assertOk()->assertJsonPath('status', 'Success');
        $this->assertSame('cancelled', DB::table('orders')->where('id', $id)->value('status'));
        // A second tap (or a stale screen) is answered as done, and changes nothing.
        $this->cancel($id)->assertOk()->assertJsonPath('message', __('api.already cancelled'));
        $this->assertSame('cancelled', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_an_order_the_branch_accepted_is_not_cancelled_by_the_customer(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $id = $this->pay(['payment_type' => 'cash'])->assertOk()->json('data.id');
        DB::table('orders')->where('id', $id)->update(['status' => 'accepted']);
        $this->cancel($id)->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame('accepted', DB::table('orders')->where('id', $id)->value('status'));
    }

    public function test_a_cancelled_wallet_order_is_paid_back_once_two_hours_later(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $id = $this->pay(['payment_type' => 'wallet'])->assertOk()->json('data.id');
        $this->assertEquals(63, (float) DB::table('users')->where('id', 7)->value('balance'));
        $this->cancel($id)->assertOk();
        $this->artisan('notify:moneyback')->assertExitCode(0);
        $this->assertEquals(63, (float) DB::table('users')->where('id', 7)->value('balance'), 'Not before two hours');
        $this->travel(121)->minutes();
        $this->artisan('notify:moneyback')->assertExitCode(0);
        $this->assertEquals(1000, (float) DB::table('users')->where('id', 7)->value('balance'), 'The whole price is back');
        $this->artisan('notify:moneyback')->assertExitCode(0);
        $this->assertEquals(1000, (float) DB::table('users')->where('id', 7)->value('balance'), 'Paid back once');
        $this->assertSame('admin', DB::table('orders')->where('id', $id)->value('transfer_price_by'));
        $this->assertEquals([937.0, 937.0], DB::table('wallets')->orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    public function test_an_order_goes_only_to_the_customers_own_address(): void
    {
        $this->pay(['payment_type' => 'cash', 'user_address_id' => 80])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(1, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count(), 'The cart stays as it was');
    }

    public function test_a_delivery_below_the_branch_tariff_is_written_down_and_the_order_still_goes(): void
    {
        $expected = \App\Services\Dashboard\CustomerDeliveryCheck::expected(\App\Models\Resturant::withoutGlobalScopes()->find(301), \App\Models\UserAddress::find(70));
        $this->assertSame(['25.00', 'app_branch_tier', 'default_2_3'], [$expected['delivery_fee'], $expected['pricing_rule'], $expected['pricing_tier']]);
        \Illuminate\Support\Facades\Log::spy();
        $this->pay(['payment_type' => 'cash', 'delivery_price' => 24.5])->assertOk();
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
        DB::table('carts')->insert(['user_id' => 7, 'resturant_id' => 301, 'resturant_product_id' => 10, 'qty' => 2, 'price' => 400]);
        $this->pay(['payment_type' => 'cash', 'delivery_price' => 5])->assertOk();
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'GO_CUSTOMER_DELIVERY_BELOW_TARIFF'
            && $context['sent'] === '5' && $context['tariff'] === '25.00' && $context['customer'] === 7 && $context['branch'] === 301);
        $this->assertSame(2, DB::table('orders')->where('status', 'pending')->count(), 'Watched only: both orders went');
    }

    public function test_the_delivery_price_is_never_negative(): void
    {
        $this->pay(['payment_type' => 'cash', 'delivery_price' => -500])->assertStatus(500)->assertJsonPath('status', 'Error');
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(1, DB::table('carts')->where('user_id', 7)->where('is_order', false)->count(), 'The cart stays as it was');
    }
}
