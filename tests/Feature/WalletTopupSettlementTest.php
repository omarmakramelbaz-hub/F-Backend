<?php

namespace Tests\Feature;

use App\Services\Payments\PaymobTransactionInquiry;
use App\Services\Payments\WalletTopupSettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wallet top-ups by card or mobile wallet (Paymob): the balance is credited only for a payment Paymob itself confirms
 * (asked directly, never trusted from the callback), for its exact amount, and only once however often the callback
 * comes back.
 */
class WalletTopupSettlementTest extends TestCase
{
    /** What Paymob answers when asked about the transaction. */
    private array $paymob = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'wallet_topups.integrations.visa' => '4400', 'wallet_topups.integrations.v_cash' => '4500', 'wallet_topups.live' => true]);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->string('account_type')->default('user'); $t->string('status')->nullable(); $t->decimal('balance', 12, 2)->default(0); $t->decimal('min_wallet', 12, 2)->nullable(); $t->timestamp('expiration_date')->nullable(); $t->string('decline_reason')->nullable(); $t->unsignedBigInteger('added_by')->nullable(); $t->timestamps(); });
        Schema::create('orders', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('type'); $t->string('status')->nullable(); $t->unsignedBigInteger('wallet_id')->nullable(); $t->timestamps(); });
        Schema::create('wallets', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('from_user'); $t->unsignedBigInteger('to_user'); $t->string('status'); $t->string('payment'); $t->string('type'); $t->decimal('amount', 12, 2); $t->unsignedBigInteger('order_id')->nullable(); $t->timestamps(); });
        Schema::create('payments', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('order_id'); $t->decimal('total_price', 15, 2)->default(0); $t->string('status')->nullable(); $t->string('transaction_id')->nullable(); $t->string('intention_order_id')->nullable(); $t->timestamps(); });
        DB::table('users')->insert(['id' => 7, 'name' => 'عميل', 'balance' => 50, 'status' => 'accepted']);
        $this->attempt(1, 'visa', 200, '9001');
        $this->paymob = $this->paid('9001', 20000, '4400');
        $test = $this;
        $this->app->instance(WalletTopupSettlement::class, new WalletTopupSettlement(new class($test) extends PaymobTransactionInquiry {
            public function __construct(private $test) {}
            public function transactionForOrder(string $gatewayOrderId, ?string $transactionId = null): array { return $this->test->answer(); }
        }));
        $this->app->instance(\App\Services\Dashboard\OrderProviderDelivery::class, new class { public function event(object $event, ?int $orderId = null): void {} });
    }

    public function answer(): array { return $this->paymob; }

    private function attempt(int $id, string $method, float $amount, string $gatewayOrder): void
    {
        DB::table('wallets')->insert(['id' => $id, 'from_user' => 7, 'to_user' => 7, 'status' => 'pending', 'payment' => $method, 'type' => 'charging', 'amount' => $amount]);
        DB::table('orders')->insert(['id' => 100 + $id, 'user_id' => 7, 'type' => 'wallet', 'status' => null, 'wallet_id' => $id]);
        DB::table('payments')->insert(['id' => $id, 'user_id' => 7, 'order_id' => 100 + $id, 'total_price' => $amount, 'status' => 'pending', 'intention_order_id' => $gatewayOrder]);
    }

    private function paid(string $gatewayOrder, int $cents, string $integration): array
    {
        return ['id' => '777001', 'order' => ['id' => $gatewayOrder], 'currency' => 'EGP', 'integration_id' => $integration, 'amount_cents' => $cents,
            'is_live' => true, 'success' => true, 'is_standalone_payment' => true, 'pending' => false, 'is_auth' => false, 'is_capture' => false,
            'is_voided' => false, 'is_refunded' => false, 'has_parent_transaction' => false, 'error_occured' => false, 'refunded_amount_cents' => 0];
    }

    private function notify(string $gatewayOrder = '9001', string $transaction = '777001')
    {
        return $this->postJson('/api/wallet-topup/paymob/notify', ['type' => 'TRANSACTION', 'obj' => ['id' => $transaction, 'order' => ['id' => $gatewayOrder]]]);
    }

    private function balance(): float { return (float) DB::table('users')->where('id', 7)->value('balance'); }

    public function test_a_confirmed_payment_credits_the_wallet_once_however_often_the_callback_comes(): void
    {
        $this->notify()->assertOk()->assertJson(['accepted' => true, 'state' => 'credited']);
        $this->assertEquals(250, $this->balance());
        $this->assertSame(['completed', 'pending', '1', '777001'], [DB::table('wallets')->value('status'), DB::table('orders')->value('status'),
            DB::table('payments')->value('status'), DB::table('payments')->value('transaction_id')]);
        // Paymob calls back again (and the customer comes back from the payment page): nothing more is credited.
        $this->notify()->assertOk()->assertJson(['accepted' => true, 'state' => 'unchanged']);
        $this->get('/api/wallet-topup/paymob/return?order=9001&id=777001');
        $this->assertEquals(250, $this->balance());
    }

    /** @dataProvider unconfirmed */
    public function test_a_payment_paymob_does_not_confirm_credits_nothing(array $change): void
    {
        $this->paymob = array_replace($this->paymob, $change);
        $this->notify()->assertStatus(503)->assertJson(['accepted' => false, 'retryable' => true]);
        $this->assertEquals(50, $this->balance());
        $this->assertSame(['pending', null], [DB::table('wallets')->value('status'), DB::table('orders')->value('status')]);
    }

    public function unconfirmed(): array
    {
        return [
            'failed' => [['success' => false]],
            'still pending' => [['pending' => true]],
            'refunded' => [['is_refunded' => true]],
            'partly refunded' => [['refunded_amount_cents' => 5000]],
            'voided' => [['is_voided' => true]],
            'a smaller amount' => [['amount_cents' => 100]],
            'another currency' => [['currency' => 'USD']],
            'another integration' => [['integration_id' => '9999']],
            'a test payment' => [['is_live' => false]],
            'another gateway order' => [['order' => ['id' => '9002']]],
        ];
    }

    public function test_one_paymob_transaction_never_credits_two_attempts(): void
    {
        $this->notify()->assertOk();
        // A second attempt pointing at the same paid transaction.
        $this->attempt(2, 'visa', 200, '9002');
        $this->paymob = $this->paid('9002', 20000, '4400');
        $this->notify('9002')->assertStatus(503);
        $this->assertEquals(250, $this->balance());
        $this->assertSame('pending', DB::table('wallets')->where('id', 2)->value('status'));
    }

    public function test_a_callback_that_is_not_a_transaction_or_is_malformed_changes_nothing(): void
    {
        $this->postJson('/api/wallet-topup/paymob/notify', ['type' => 'TOKEN', 'obj' => []])->assertOk()->assertJson(['ignored' => true]);
        $this->postJson('/api/wallet-topup/paymob/notify', ['type' => 'TRANSACTION', 'obj' => 'x'])->assertStatus(400);
        $this->notify('9001 OR 1=1')->assertStatus(503);
        $this->notify('424242')->assertStatus(503);
        $this->assertEquals(50, $this->balance());
    }

    public function test_a_mobile_wallet_payment_is_held_to_its_own_integration(): void
    {
        $this->attempt(3, 'v_cash', 75.5, '9003');
        $this->paymob = $this->paid('9003', 7550, '4400'); // paid through the card integration instead
        $this->notify('9003')->assertStatus(503);
        $this->assertEquals(50, $this->balance());
        $this->paymob = $this->paid('9003', 7550, '4500');
        $this->notify('9003')->assertOk()->assertJson(['state' => 'credited']);
        $this->assertEquals(125.5, $this->balance());
    }
}
