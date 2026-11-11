<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * notify:percentage (every five minutes): each completed app order is settled once.
 * - Paid ahead (card, mobile wallet, GO wallet): the branch is credited its share (items less its commission, plus the
 *   delivery when no courier took it); the courier is credited the delivery less the courier fee.
 * - Cash kept by the branch: the app's commission is taken from the branch's balance.
 * - Cash kept by the courier: the branch is credited its share and the app its commission, both from the courier.
 * An account left in debt is paused for two hours and told to top up.
 */
class OrderSettlementJobTest extends TestCase
{
    use Concerns\CustomerAppSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerAppSchema();
        Notification::fake();
        Schema::table('users', function (Blueprint $t) { $t->decimal('delegate_fees', 5, 2)->nullable(); $t->timestamp('expiration_date')->nullable(); $t->string('connected')->nullable(); });
        Schema::table('orders', function (Blueprint $t) { $t->string('reason')->nullable(); $t->string('declined_by')->nullable(); });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'الأدمن', 'mobile' => '1000000001', 'account_type' => 'admin', 'status' => 'accepted', 'balance' => 0, 'delegate_fees' => null],
            ['id' => 30, 'name' => 'تاجر شبرا', 'mobile' => '1000000030', 'account_type' => 'vendor', 'status' => 'accepted', 'balance' => 1000, 'delegate_fees' => null],
            ['id' => 20, 'name' => 'مندوب', 'mobile' => '1000000020', 'account_type' => 'delegate', 'status' => 'accepted', 'balance' => 1000, 'delegate_fees' => 10],
        ]);
        DB::table('resturants')->where('id', 301)->update(['user_id' => 30]);
        DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->update(['payload' => json_encode('0.00')]);
        // 800 of items (the customer's cart), 80 branch commission, 112 customer tax, 25 delivery.
        DB::table('orders')->insert(['id' => 500, 'user_id' => 7, 'resturant_id' => 301, 'status' => 'completed', 'type' => 'current', 'user_address_id' => 70,
            'payment_type' => 'wallet', 'delivery_price' => 25, 'vendor_tax' => 80, 'user_tax' => 112, 'tax' => 0, 'delegate_from_out' => 'in_resturant']);
        DB::table('carts')->where('user_id', 7)->update(['order_id' => 500, 'is_order' => true]);
    }

    private function balances(): array
    {
        return DB::table('users')->whereIn('id', [30, 20])->orderBy('id')->pluck('balance', 'id')->map(fn ($v) => (float) $v)->all();
    }

    private function appBalance(): float
    {
        return (float) json_decode(DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->value('payload'), true);
    }

    private function settle(): void
    {
        $this->artisan('notify:percentage')->assertExitCode(0);
    }

    public function test_a_prepaid_order_the_branch_delivered_credits_the_branch_its_share_and_the_delivery_once(): void
    {
        $this->settle();
        $this->assertSame([20 => 1000.0, 30 => 1000.0 + 720 + 25], $this->balances());
        $this->assertSame('admin', DB::table('orders')->where('id', 500)->value('transfer_price_by'));
        $this->settle();
        $this->assertSame([20 => 1000.0, 30 => 1745.0], $this->balances(), 'Settled once');
        $this->assertSame(1, DB::table('wallets')->where('order_id', 500)->count());
    }

    public function test_a_prepaid_order_with_a_courier_credits_the_courier_the_delivery_less_the_fee(): void
    {
        DB::table('orders')->where('id', 500)->update(['payment_type' => 'online', 'delegate_id' => 20, 'delegate_from_out' => 'out_resturant']);
        $this->settle();
        $this->assertSame([20 => 1000.0 + 22.5, 30 => 1000.0 + 720], $this->balances());
        $this->assertSame(2, DB::table('wallets')->where('order_id', 500)->count());
    }

    public function test_a_cash_order_the_branch_kept_takes_the_apps_commission_from_the_branch(): void
    {
        DB::table('orders')->where('id', 500)->update(['payment_type' => 'cash']);
        $before = $this->appBalance();
        $this->settle();
        // 80 branch commission + 112 customer tax (no service fee set).
        $this->assertSame([20 => 1000.0, 30 => 1000.0 - 192], $this->balances());
        $this->assertEquals($before + 192, $this->appBalance());
        $this->assertSame('vendor', DB::table('orders')->where('id', 500)->value('transfer_price_by'));
        $this->settle();
        $this->assertSame([20 => 1000.0, 30 => 808.0], $this->balances());
    }

    public function test_a_cash_order_the_courier_kept_pays_the_branch_and_the_app_from_the_courier(): void
    {
        DB::table('orders')->where('id', 500)->update(['payment_type' => 'cash', 'delegate_id' => 20, 'delegate_from_out' => 'out_resturant']);
        $before = $this->appBalance();
        $this->settle();
        // App: 80 + 112 + the courier fee on the delivery (2.50).
        $this->assertSame([20 => 1000.0 - 720 - 194.5, 30 => 1000.0 + 720], $this->balances());
        $this->assertEquals($before + 194.5, $this->appBalance());
        $this->assertSame('delegate', DB::table('orders')->where('id', 500)->value('transfer_price_by'));
    }

    public function test_a_courier_left_in_debt_by_a_cash_order_is_paused_and_told_to_top_up(): void
    {
        DB::table('users')->where('id', 20)->update(['balance' => 100]);
        DB::table('orders')->where('id', 500)->update(['payment_type' => 'cash', 'delegate_id' => 20, 'delegate_from_out' => 'out_resturant']);
        $this->settle();
        $courier = DB::table('users')->where('id', 20)->first();
        $this->assertEquals(100 - 914.5, (float) $courier->balance);
        $this->assertSame('inactive', $courier->connected);
        $this->assertNotNull($courier->expiration_date);
        Notification::assertSentTo(\App\Models\User::withoutGlobalScopes()->find(20), \App\Notifications\NotifyMinWalletBalanceNotification::class);
    }

    public function test_a_branch_left_in_debt_by_a_cash_order_is_paused_and_told_to_top_up(): void
    {
        DB::table('users')->where('id', 30)->update(['balance' => 50]);
        DB::table('orders')->where('id', 500)->update(['payment_type' => 'cash']);
        $this->settle();
        $branch = DB::table('users')->where('id', 30)->first();
        $this->assertEquals(50 - 192, (float) $branch->balance);
        $this->assertSame('inactive', $branch->connected);
        Notification::assertSentTo(\App\Models\User::withoutGlobalScopes()->find(30), \App\Notifications\NotifyMinWalletBalanceNotification::class);
    }
}
