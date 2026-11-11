<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A customer's tip to the courier of his completed order (/api/user/commission/order/delegate): only his own order,
 * only a positive amount his wallet covers, taken once.
 */
class CourierTipApiTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Notification::fake();
        DB::table('users')->insert(['id' => 20, 'name' => 'مندوب', 'mobile' => '1000000020', 'account_type' => 'delegate', 'status' => 'accepted', 'balance' => 300]);
        DB::table('orders')->insert(['id' => 500, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'completed', 'type' => 'current', 'payment_type' => 'cash', 'delivery_price' => 25, 'delegate_id' => 20]);
        if (!\Illuminate\Support\Facades\Schema::hasColumn('commissions', 'delegate_id')) {
            \Illuminate\Support\Facades\Schema::table('commissions', function ($t) { $t->unsignedBigInteger('delegate_id')->nullable(); $t->decimal('commission', 10, 2)->default(0); });
        }
    }

    private function tip($amount, int $user = 7, int $order = 500)
    {
        return $this->withHeaders($this->appHeaders($user))->postJson('/api/user/commission/order/delegate', ['order_id' => $order, 'commission' => $amount]);
    }

    private function balances(): array
    {
        return DB::table('users')->whereIn('id', [7, 8, 20])->orderBy('id')->pluck('balance', 'id')->map(fn ($v) => (float) $v)->all();
    }

    public function test_a_tip_moves_from_the_customer_to_his_courier_once(): void
    {
        $this->tip(20)->assertOk();
        $this->assertSame([7 => 980.0, 8 => 0.0, 20 => 320.0], $this->balances());
    }

    public function test_a_negative_or_zero_tip_never_takes_from_the_courier(): void
    {
        foreach ([-500, 0, '-0.01'] as $amount) $this->assertNotSame(200, $this->tip($amount)->status());
        $this->assertSame([7 => 1000.0, 8 => 0.0, 20 => 300.0], $this->balances());
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_only_the_orders_own_customer_tips_and_only_what_his_wallet_covers(): void
    {
        DB::table('users')->where('id', 8)->update(['balance' => 100]);
        $this->assertNotSame(200, $this->tip(10, 8)->status());
        $this->assertNotSame(200, $this->tip(5000)->status());
        $this->assertSame([7 => 1000.0, 8 => 100.0, 20 => 300.0], $this->balances());
    }
}
