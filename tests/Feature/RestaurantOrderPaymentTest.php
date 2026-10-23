<?php

namespace Tests\Feature;

use App\Services\Payments\PaymobTransactionInquiry;
use App\Services\Payments\RestaurantOrderPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestaurantOrderPaymentFakeInquiry extends PaymobTransactionInquiry
{
    public array $receipt;
    public array $calls = [];
    public $duringInquiry;

    public function transactionForOrder(string $gatewayOrderId, ?string $transactionId = null): array
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Inquiry held a database transaction');
        $this->calls[] = [$gatewayOrderId, $transactionId];
        if ($this->duringInquiry) ($this->duringInquiry)();
        return $this->receipt;
    }
}

class RestaurantOrderPaymentTest extends TestCase
{
    private RestaurantOrderPaymentFakeInquiry $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'wallet_topups.api_key' => 'test-inquiry-key', 'wallet_topups.live' => true, 'desktop_dashboard.local' => false,
            'dashboard_payments.integrations.online' => '765', 'dashboard_payments.integrations.v_cash' => '766']);
        DB::purge('sqlite');Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $table) { $table->id();$table->decimal('balance', 14, 2); });
        Schema::create('resturants', function (Blueprint $table) { $table->id();$table->unsignedBigInteger('user_id'); });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();$table->unsignedBigInteger('user_id');$table->unsignedBigInteger('resturant_id');
            foreach (['type', 'status', 'payment_type', 'accepted_notify', 'delegate_from_out', 'transfer_price_by'] as $column) $table->string($column)->nullable();
            $table->unsignedBigInteger('delegate_id')->nullable();
            foreach (['total_price', 'tax', 'user_tax', 'vendor_tax'] as $column) $table->decimal($column, 14, 2);
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();$table->unsignedBigInteger('order_id');$table->unsignedBigInteger('user_id');
            foreach (['status', 'transaction_id', 'intention_order_id'] as $column) $table->string($column)->nullable();
            $table->decimal('total_price', 14, 2)->nullable();$table->timestamps();
        });
        Schema::create('wallets', function (Blueprint $table) { $table->id();$table->decimal('amount', 14, 2); });
        Schema::create('order_board_clocks', function (Blueprint $table) { $table->id();$table->unsignedBigInteger('order_id');$table->timestamp('accepted_at')->nullable(); });
        DB::table('users')->insert([['id' => 10, 'balance' => '500.00'], ['id' => 20, 'balance' => '125.00'], ['id' => 21, 'balance' => '300.00']]);
        DB::table('resturants')->insert(['id' => 100, 'user_id' => 10]);
        DB::table('wallets')->insert(['id' => 1, 'amount' => '51.25']);
        DB::table('orders')->insert(['id' => 101, 'user_id' => 20, 'resturant_id' => 100,
            'type' => 'current', 'status' => null, 'payment_type' => 'online', 'accepted_notify' => 'no',
            'delegate_id' => null, 'delegate_from_out' => null, 'transfer_price_by' => null,
            'total_price' => '125.00', 'tax' => '1.20', 'user_tax' => '2.30', 'vendor_tax' => '3.40',
            'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00']);
        DB::table('payments')->insert(['id' => 1, 'order_id' => 101, 'user_id' => 20, 'status' => null,
            'transaction_id' => null, 'intention_order_id' => '7700', 'total_price' => '125.00',
            'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00']);
        $this->gateway = new RestaurantOrderPaymentFakeInquiry();
        $this->gateway->receipt = ['id' => '991001', 'order' => ['id' => '7700'], 'amount_cents' => '12500',
            'currency' => 'EGP', 'integration_id' => '765', 'is_live' => true, 'success' => true,
            'is_standalone_payment' => true, 'pending' => false, 'is_auth' => false, 'is_capture' => false,
            'is_voided' => false, 'is_refunded' => false, 'has_parent_transaction' => false, 'error_occured' => false];
    }

    private function settle(?string $transaction = '991001'): array { return (new RestaurantOrderPayment($this->gateway))->settle(1, $transaction); }
    private function state(): array
    {
        $state=[];foreach (['users', 'resturants', 'orders', 'payments', 'wallets', 'order_board_clocks'] as $table) $state[$table]=DB::table($table)->orderBy('id')->get()->toArray();return $state;
    }
    private function failure(callable $call, string $code): void
    {
        try { $call();$this->fail('Expected '.$code); } catch (\RuntimeException $error) { $this->assertStringContainsString($code, $error->getMessage()); }
    }
    private function paid(): void { DB::table('payments')->where('id', 1)->update(['status' => '1', 'transaction_id' => '991001']); }
    private function retry(array $extra = []): void
    {
        DB::table('payments')->insert(array_replace(['id' => 2, 'order_id' => 101, 'user_id' => 20,
            'status' => null, 'transaction_id' => null, 'intention_order_id' => '7701', 'total_price' => '125.00'], $extra));
    }

    public function test_local_device_cannot_contact_the_gateway_or_activate_a_receipt(): void
    {
        $before = $this->state();config(['desktop_dashboard.local' => true]);
        $this->failure(fn () => $this->settle(), 'CENTRAL_SERVER_REQUIRED');
        $this->assertSame([], $this->gateway->calls);$this->assertEquals($before, $this->state());
    }

    public function test_recovery_rechecks_the_exact_stored_success_instead_of_the_last_gateway_retry(): void
    {
        $this->paid();
        $result = $this->settle(null);
        $this->assertSame('activated', $result['state']);
        $this->assertSame([['7700', '991001']], $this->gateway->calls);
        $this->assertSame('pending', DB::table('orders')->find(101)->status);
    }

    public function test_both_methods_activate_only_after_inquiry_and_keep_all_finance_and_invoice_facts(): void
    {
        foreach (['online' => '765', 'v_cash' => '766'] as $method => $integration) {
            DB::table('orders')->where('id', 101)->update(['status' => null, 'payment_type' => $method]);
            DB::table('payments')->where('id', 1)->update(['status' => null, 'transaction_id' => null]);
            $this->gateway->receipt['integration_id'] = $integration;
            $before=$this->state();$result=$this->settle(null);
            $this->assertSame('activated', $result['state']);$this->assertSame('991001', $result['transaction_id']);
            $this->assertSame('7700', $result['gateway_order_id']);$this->assertSame(12500, $result['amount_cents']);
            $this->assertSame($method, $result['payment_method']);$this->assertSame(100, $result['resturant_id']);
            $order=DB::table('orders')->find(101);$this->assertSame('pending', $order->status);
            foreach (['created_at', 'tax', 'user_tax', 'vendor_tax', 'total_price', 'accepted_notify', 'delegate_id', 'delegate_from_out', 'transfer_price_by'] as $field) $this->assertSame($before['orders'][0]->$field, $order->$field, $field);
            $this->assertSame('1', DB::table('payments')->find(1)->status);
            foreach (['users', 'wallets', 'order_board_clocks'] as $table) $this->assertEquals($before[$table], $this->state()[$table]);
            $after=$this->state();$this->assertSame('unchanged', $this->settle()['state']);$this->assertEquals($after, $this->state());
        }
        $this->assertSame(['7700', null], $this->gateway->calls[0]);
    }

    public function test_same_success_with_null_order_can_recover_once_and_existing_phases_never_regress(): void
    {
        $this->paid();$payment=DB::table('payments')->find(1);$this->assertSame('activated', $this->settle()['state']);
        $this->assertEquals($payment, DB::table('payments')->find(1));
        foreach (['pending', 'new_order', 'another_delegate', 'accepted', 'shipped', 'completed'] as $status) {
            DB::table('orders')->where('id', 101)->update(['status' => $status, 'accepted_notify' => 'yes', 'delegate_id' => 55, 'delegate_from_out' => 'in_resturant']);
            $before=$this->state();$this->assertSame('unchanged', $this->settle()['state']);$this->assertEquals($before, $this->state());
        }
    }

    public function test_terminal_transfer_or_inconsistent_lifecycle_is_closed_without_source_mutation(): void
    {
        foreach (['cancelled', 'canceled', 'declined', 'rejected', 'expired'] as $status) {
            DB::table('orders')->where('id', 101)->update(['status' => $status]);$before=$this->state();
            $this->assertSame('closed', $this->settle()['state']);$this->assertEquals($before, $this->state());
        }
        foreach ([['transfer_price_by' => 'admin'], ['accepted_notify' => 'yes'], ['delegate_id' => 55], ['delegate_from_out' => 'in_resturant'], ['status' => 'accepted']] as $extra) {
            DB::table('orders')->where('id', 101)->update(array_replace(['status' => null, 'transfer_price_by' => null, 'accepted_notify' => 'no', 'delegate_id' => null, 'delegate_from_out' => null], $extra));
            $before=$this->state();$this->assertSame('closed', $this->settle()['state']);$this->assertEquals($before, $this->state());
        }
    }

    public function test_pending_and_failed_attempts_can_become_authoritative_success(): void
    {
        foreach ([null, 'pending', 'creating', '0', 'false', 'failed'] as $status) {
            DB::table('orders')->where('id', 101)->update(['status' => null]);
            DB::table('payments')->where('id', 1)->update(['status' => $status, 'transaction_id' => $status === 'failed' ? '991000' : null]);
            $this->assertSame('activated', $this->settle()['state']);$this->assertSame('991001', DB::table('payments')->find(1)->transaction_id);
        }
    }

    public function test_newer_unresolved_retry_does_not_hide_real_payment_but_competing_success_is_closed(): void
    {
        $this->retry();$retry=DB::table('payments')->find(2);$this->assertSame('activated', $this->settle()['state']);$this->assertEquals($retry, DB::table('payments')->find(2));
        DB::table('orders')->where('id', 101)->update(['status' => null]);
        DB::table('payments')->where('id', 1)->update(['status' => null, 'transaction_id' => null]);
        DB::table('payments')->where('id', 2)->update(['status' => '1', 'transaction_id' => '991002']);
        $before=$this->state();$this->assertSame('closed', $this->settle()['state']);$this->assertEquals($before, $this->state());
    }

    public function test_all_gateway_mismatches_missing_flags_and_nonsettled_results_reject_without_mutation(): void
    {
        $good=$this->gateway->receipt;
        foreach ([['id' => '991002'], ['order' => ['id' => '7701']], ['currency' => 'USD'], ['integration_id' => '766'], ['amount_cents' => '12501']] as $extra) {
            $this->gateway->receipt=array_replace($good, $extra);$before=$this->state();$this->failure(fn()=>$this->settle(), 'MISMATCH');$this->assertEquals($before, $this->state());
        }
        foreach (['is_live', 'success', 'is_standalone_payment', 'pending', 'is_auth', 'is_capture', 'is_voided', 'is_refunded', 'has_parent_transaction', 'error_occured'] as $flag) {
            $this->gateway->receipt=$good;$this->gateway->receipt[$flag]=!$good[$flag];$before=$this->state();$this->failure(fn()=>$this->settle(), 'PAYMENT_NOT_SETTLED');$this->assertEquals($before, $this->state());
            unset($this->gateway->receipt[$flag]);$this->failure(fn()=>$this->settle(), 'PAYMENT_NOT_SETTLED');$this->assertEquals($before, $this->state());
        }
        foreach ([['is_void' => true], ['is_refund' => true], ['refunded_amount_cents' => 1]] as $extra) {
            $this->gateway->receipt=array_replace($good, $extra);$before=$this->state();$this->failure(fn()=>$this->settle(), 'PAYMENT_NOT_SETTLED');$this->assertEquals($before, $this->state());
        }
    }

    public function test_missing_configuration_invalid_amount_identity_and_gateway_order_are_rejected_before_inquiry(): void
    {
        config(['wallet_topups.api_key' => '']);$this->failure(fn()=>$this->settle(), 'GATEWAY_CONFIG_REQUIRED');config(['wallet_topups.api_key' => 'test-key']);
        config(['dashboard_payments.integrations.online' => null]);$this->failure(fn()=>$this->settle(), 'INTEGRATION_REQUIRED');config(['dashboard_payments.integrations.online' => '765']);
        foreach ([null, '0.00', '-1.00', '1.234'] as $amount) {DB::table('payments')->where('id', 1)->update(['total_price' => $amount]);$this->failure(fn()=>$this->settle(), 'INVALID_AMOUNT');}
        DB::table('payments')->where('id', 1)->update(['total_price' => '125.00', 'user_id' => 21]);$this->failure(fn()=>$this->settle(), 'OWNERSHIP_MISMATCH');
        DB::table('payments')->where('id', 1)->update(['user_id' => 20]);DB::table('orders')->where('id', 101)->update(['resturant_id' => 999]);$this->failure(fn()=>$this->settle(), 'PARTY_NOT_FOUND');
        DB::table('orders')->where('id', 101)->update(['resturant_id' => 100, 'type' => 'wallet']);$this->failure(fn()=>$this->settle(), 'INVALID_RESTAURANT_ATTEMPT');
        DB::table('orders')->where('id', 101)->update(['type' => 'current']);$this->retry(['intention_order_id' => '7700']);$this->failure(fn()=>$this->settle(), 'AMBIGUOUS_GATEWAY_ORDER');
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_inquiry_live_configuration_and_reused_transaction_binding_are_enforced(): void
    {
        config(['wallet_topups.live' => false]);$before=$this->state();$this->failure(fn()=>$this->settle(), 'PAYMENT_NOT_SETTLED');$this->assertEquals($before, $this->state());
        config(['wallet_topups.live' => true]);$this->retry(['transaction_id' => '991001']);$before=$this->state();$this->failure(fn()=>$this->settle(), 'TRANSACTION_ALREADY_USED');$this->assertEquals($before, $this->state());
    }

    public function test_payment_identity_changes_during_inquiry_are_rechecked_and_cancellation_wins(): void
    {
        $this->gateway->duringInquiry=function(){DB::table('payments')->where('id', 1)->update(['total_price' => '126.00']);};
        $this->failure(fn()=>$this->settle(), 'ATTEMPT_CHANGED');$this->assertNull(DB::table('orders')->find(101)->status);$this->assertNull(DB::table('payments')->find(1)->status);
        DB::table('payments')->where('id', 1)->update(['total_price' => '125.00']);
        $this->gateway->duringInquiry=function(){DB::table('orders')->where('id', 101)->update(['status' => 'cancelled']);};
        $this->assertSame('closed', $this->settle()['state']);$this->assertSame('cancelled', DB::table('orders')->find(101)->status);$this->assertNull(DB::table('payments')->find(1)->status);
    }

    public function test_swapped_parties_after_inquiry_and_different_existing_success_cannot_be_overwritten(): void
    {
        $this->gateway->duringInquiry=function(){DB::table('orders')->where('id', 101)->update(['user_id' => 21]);DB::table('payments')->where('id', 1)->update(['user_id' => 21]);};
        $this->failure(fn()=>$this->settle(), 'ATTEMPT_CHANGED');$this->assertNull(DB::table('payments')->find(1)->status);
        $this->gateway->duringInquiry=null;DB::table('orders')->where('id', 101)->update(['user_id' => 20]);
        DB::table('payments')->where('id', 1)->update(['user_id' => 20, 'status' => '1', 'transaction_id' => '991002']);
        $before=$this->state();$this->assertSame('closed', $this->settle()['state']);$this->assertEquals($before, $this->state());
    }

    public function test_enclosing_transactions_are_refused_before_any_network_request(): void
    {
        DB::beginTransaction();try {$this->failure(fn()=>$this->settle(), 'TRANSACTION_NOT_ALLOWED');} finally {DB::rollBack();}
        $this->assertSame([], $this->gateway->calls);
    }
}
