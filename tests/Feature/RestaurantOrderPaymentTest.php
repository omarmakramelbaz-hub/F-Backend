<?php

namespace Tests\Feature;

use App\Services\GoServices\PaymobHmac;
use App\Services\Payments\PaymobTransactionInquiry;
use App\Services\Payments\RestaurantOrderPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A customer app order paid by card or mobile wallet (Paymob): it reaches the branch (status «pending») only once Paymob
 * itself confirms the exact amount, once, and a late callback never brings back an order already cancelled or handled.
 */
class RestaurantOrderPaymentTest extends TestCase
{
    private array $paymob = [];
    public array $activated = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'wallet_topups.api_key' => 'paymob-key',
            'wallet_topups.live' => true, 'dashboard_payments.integrations.online' => '4400', 'dashboard_payments.integrations.v_cash' => '4500',
            'dashboard_payments.hmac_secret' => 'callback-secret']);
        DB::purge('sqlite'); Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
        Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); });
        Schema::create('orders', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('resturant_id'); $t->string('type'); $t->string('payment_type'); $t->string('status')->nullable(); $t->string('accepted_notify')->nullable(); $t->unsignedBigInteger('delegate_id')->nullable(); $t->string('delegate_from_out')->nullable(); $t->string('transfer_price_by')->nullable(); $t->timestamps(); });
        Schema::create('payments', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('order_id'); $t->decimal('total_price', 15, 2)->default(0); $t->string('status')->nullable(); $t->string('transaction_id')->nullable(); $t->string('intention_order_id')->nullable(); $t->timestamps(); });
        DB::table('users')->insert(['id' => 7]);
        DB::table('resturants')->insert(['id' => 301, 'name' => 'فرع شبرا']);
        DB::table('orders')->insert(['id' => 500, 'user_id' => 7, 'resturant_id' => 301, 'type' => 'current', 'payment_type' => 'online']);
        DB::table('payments')->insert(['id' => 1, 'user_id' => 7, 'order_id' => 500, 'total_price' => 937, 'status' => 'pending', 'intention_order_id' => '8001']);
        $this->paymob = $this->paid();
        $test = $this;
        $this->app->instance(RestaurantOrderPayment::class, new RestaurantOrderPayment(new class($test) extends PaymobTransactionInquiry {
            public function __construct(private $test) {}
            public function transactionForOrder(string $gatewayOrderId, ?string $transactionId = null): array { return $this->test->answer(); }
        }));
        $this->app->instance(\App\Services\Payments\RestaurantPaymentEffects::class, new class($test) {
            public function __construct(private $test) {}
            public function afterActivation(array $result): void { $this->test->activated[] = $result['order_id']; }
        });
    }

    public function answer(): array { return $this->paymob; }

    private function paid(array $change = []): array
    {
        return array_replace(['id' => '555001', 'order' => ['id' => '8001'], 'currency' => 'EGP', 'integration_id' => '4400', 'amount_cents' => 93700,
            'is_live' => true, 'success' => true, 'is_standalone_payment' => true, 'pending' => false, 'is_auth' => false, 'is_capture' => false,
            'is_voided' => false, 'is_refunded' => false, 'has_parent_transaction' => false, 'error_occured' => false, 'refunded_amount_cents' => 0], $change);
    }

    private function notify(array $object, ?string $signature = null)
    {
        $signature ??= PaymobHmac::digest($object, 'callback-secret');
        return $this->postJson('/api/order-payments/paymob/notify?hmac='.$signature, ['type' => 'TRANSACTION', 'obj' => $object]);
    }

    private function order(): array
    {
        return [DB::table('orders')->where('id', 500)->value('status'), DB::table('payments')->where('id', 1)->value('status')];
    }

    public function test_a_confirmed_payment_sends_the_order_to_the_branch_once(): void
    {
        $this->notify($this->paid())->assertOk()->assertJson(['accepted' => true, 'state' => 'activated']);
        $this->assertSame(['pending', '1'], $this->order());
        $this->assertSame('555001', DB::table('payments')->value('transaction_id'));
        // Paymob calls back again and the customer comes back from the payment page: nothing is done twice.
        $this->notify($this->paid())->assertOk()->assertJson(['state' => 'unchanged']);
        $this->get('/api/order-payments/paymob/return?order=8001&id=555001')->assertRedirect(route('paySuccess'));
        $this->assertSame([500], $this->activated, 'The branch is told once');
    }

    public function test_a_callback_without_the_right_signature_changes_nothing(): void
    {
        $this->notify($this->paid(), str_repeat('a', 128))->assertForbidden();
        $this->notify($this->paid(), 'not-a-signature')->assertForbidden();
        // Signed for other values than the ones sent.
        $this->notify($this->paid(['amount_cents' => 1]), PaymobHmac::digest($this->paid(), 'callback-secret'))->assertForbidden();
        $this->assertSame([null, 'pending'], $this->order());
        $this->assertSame([], $this->activated);
    }

    /** @dataProvider unconfirmed */
    public function test_an_order_paymob_does_not_confirm_never_reaches_the_branch(array $change): void
    {
        $this->paymob = $this->paid($change);
        $this->get('/api/order-payments/paymob/return?order=8001&id=555001')->assertRedirect(route('payFailed'));
        $this->assertSame([null, 'pending'], $this->order());
        $this->assertSame([], $this->activated);
    }

    public function unconfirmed(): array
    {
        return [
            'failed' => [['success' => false]],
            'still pending' => [['pending' => true]],
            'refunded' => [['is_refunded' => true]],
            'voided' => [['is_voided' => true]],
            'a smaller amount' => [['amount_cents' => 100]],
            'another integration' => [['integration_id' => '4500']],
            'a test payment' => [['is_live' => false]],
            'another transaction' => [['id' => '555999']],
        ];
    }

    public function test_a_late_payment_never_brings_back_a_cancelled_order(): void
    {
        DB::table('orders')->where('id', 500)->update(['status' => 'cancelled']);
        $this->notify($this->paid())->assertOk()->assertJson(['state' => 'closed']);
        $this->assertSame('cancelled', DB::table('orders')->where('id', 500)->value('status'));
        $this->assertSame([], $this->activated);
    }

    public function test_one_paymob_transaction_never_pays_two_orders(): void
    {
        $this->notify($this->paid())->assertOk();
        DB::table('orders')->insert(['id' => 501, 'user_id' => 7, 'resturant_id' => 301, 'type' => 'current', 'payment_type' => 'online']);
        DB::table('payments')->insert(['id' => 2, 'user_id' => 7, 'order_id' => 501, 'total_price' => 937, 'status' => 'pending', 'intention_order_id' => '8002']);
        $this->paymob = $this->paid(['order' => ['id' => '8002']]);
        $this->notify($this->paymob)->assertStatus(503);
        $this->assertNull(DB::table('orders')->where('id', 501)->value('status'));
        $this->assertSame([500], $this->activated);
    }
}
