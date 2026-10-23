<?php

namespace Tests\Feature;

use App\Services\Payments\PaymobTransactionInquiry;
use App\Services\Payments\RestaurantOrderPayment;
use Fasakhansta\Deployment\RestaurantPaymentReadOnlyDiagnostic;
use Fasakhansta\Deployment\RestaurantPaymentDiagnosticInquiry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/deployment/diagnose_restaurant_payments.php';

class RestaurantPaymentDiagnosticFakeInquiry extends PaymobTransactionInquiry
{
    public array $receipt = [];
    public array $calls = [];
    public ?\Throwable $failure = null;

    public function transactionForOrder(string $gatewayOrderId, ?string $transactionId = null): array
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Inquiry held a database transaction');
        $this->calls[] = [$gatewayOrderId, $transactionId];
        if ($this->failure) throw $this->failure;
        return $this->receipt;
    }
}

class RestaurantPaymentDiagnosticSettlementGuard extends RestaurantOrderPayment
{
    public function settle(int $paymentId, ?string $transactionId = null): array
    {
        throw new \LogicException('A diagnostic must never call settlement');
    }
}

class RestaurantPaymentDiagnosticWireInquiry extends RestaurantPaymentDiagnosticInquiry
{
    public array $responses = [];
    public array $wireCalls = [];

    protected function exchange(string $method, string $path, array $payload, ?string $token, int $remaining): array
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Inquiry held a database transaction');
        $this->wireCalls[] = [$method, $path, $payload, $token, $remaining];
        if (!$this->responses) throw new \LogicException('Unexpected diagnostic request');
        return array_replace(['success' => true, 'status' => 200, 'errno' => 0, 'too_large' => false, 'body' => '{}'], array_shift($this->responses));
    }
}

class RestaurantPaymentDiagnosticTest extends TestCase
{
    private RestaurantPaymentDiagnosticFakeInquiry $gateway;
    private RestaurantPaymentReadOnlyDiagnostic $diagnostic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'wallet_topups.api_key' => 'diagnostic-test-api-key',
            'wallet_topups.live' => true, 'desktop_dashboard.local' => false,
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
        $this->order(101);
        $this->attempt(1, 101, ['intention_order_id' => '7700']);
        $this->gateway = new RestaurantPaymentDiagnosticFakeInquiry();
        $this->gateway->receipt = ['id' => '991001', 'order' => ['id' => '7700'], 'amount_cents' => '12500',
            'currency' => 'EGP', 'integration_id' => '765', 'is_live' => true, 'success' => true,
            'is_standalone_payment' => true, 'pending' => false, 'is_auth' => false, 'is_capture' => false,
            'is_voided' => false, 'is_refunded' => false, 'has_parent_transaction' => false, 'error_occured' => false];
        $this->diagnostic = new RestaurantPaymentReadOnlyDiagnostic(new RestaurantPaymentDiagnosticSettlementGuard($this->gateway), $this->gateway);
    }

    private function order(int $id, array $extra = []): void
    {
        DB::table('orders')->insert(array_replace(['id' => $id, 'user_id' => 20, 'resturant_id' => 100,
            'type' => 'current', 'status' => null, 'payment_type' => 'online', 'accepted_notify' => 'no',
            'delegate_id' => null, 'delegate_from_out' => null, 'transfer_price_by' => null,
            'total_price' => '125.00', 'tax' => '1.20', 'user_tax' => '2.30', 'vendor_tax' => '3.40',
            'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00'], $extra));
    }

    private function attempt(int $id, int $orderId, array $extra = []): void
    {
        DB::table('payments')->insert(array_replace(['id' => $id, 'order_id' => $orderId, 'user_id' => 20,
            'status' => null, 'transaction_id' => null, 'intention_order_id' => (string) (7700 + $id),
            'total_price' => '125.00', 'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00'], $extra));
    }

    private function state(): array
    {
        $state = [];
        foreach (['users', 'resturants', 'orders', 'payments', 'wallets', 'order_board_clocks'] as $table) $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        return $state;
    }

    private function inspectReadOnly(int $paymentId = 1): array
    {
        $before = $this->state();
        DB::enableQueryLog();DB::flushQueryLog();
        try { $result = $this->diagnostic->inspect($paymentId);$queries = DB::getQueryLog(); }
        finally { DB::disableQueryLog(); }
        $this->assertEquals($before, $this->state(), 'Diagnosis changed stored order, receipt, finance or clock facts');
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) $this->assertMatchesRegularExpression('/\A\s*select\b/i', $query['query'], 'Diagnosis executed a non-SELECT statement');
        return $result;
    }

    public function test_verified_receipt_is_only_reported_without_settlement_or_any_database_write(): void
    {
        $result = $this->inspectReadOnly();
        $this->assertSame(1, $result['payment_id']);$this->assertSame(101, $result['order_id']);
        $this->assertSame('online', $result['payment_method']);$this->assertSame('verified', $result['verification']);
        $this->assertSame([['7700', null]], $this->gateway->calls);
        foreach (['order', 'amount', 'currency', 'integration', 'transaction'] as $field) $this->assertTrue($result['gateway_matches'][$field], $field);
        $this->assertTrue($result['gateway_flags']['success']);$this->assertFalse($result['gateway_flags']['pending']);
    }

    public function test_failed_gateway_flags_are_explained_without_changing_the_order(): void
    {
        $this->gateway->receipt['success'] = false;
        $result = $this->inspectReadOnly();
        $this->assertSame('not_verified', $result['verification']);
        $this->assertSame('RESTAURANT_PAYMENT_PAYMENT_NOT_SETTLED', $result['reason_code']);
        $this->assertFalse($result['gateway_flags']['success']);
    }

    public function test_missing_and_malformed_flags_are_sanitized_and_never_echoed(): void
    {
        $this->gateway->receipt['success'] = 'private-customer-phone-and-token';
        $this->gateway->receipt['pending'] = ['api_key' => 'never-echo-this'];
        unset($this->gateway->receipt['is_auth']);
        $result = $this->inspectReadOnly();
        $this->assertSame('RESTAURANT_PAYMENT_PAYMENT_NOT_SETTLED', $result['reason_code']);
        $this->assertSame('invalid', $result['gateway_flags']['success']);
        $this->assertSame('invalid', $result['gateway_flags']['pending']);
        $this->assertSame('missing', $result['gateway_flags']['is_auth']);
        foreach ($result['gateway_flags'] as $flag) $this->assertTrue(in_array($flag, [true, false, 'missing', 'invalid'], true));
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('private-customer', $encoded);$this->assertStringNotContainsString('never-echo-this', $encoded);
    }

    public function test_each_gateway_attribute_mismatch_has_a_boolean_explanation(): void
    {
        $good = $this->gateway->receipt;
        foreach (['order' => ['order' => ['id' => '7701']], 'amount' => ['amount_cents' => '12501'],
            'currency' => ['currency' => 'USD'], 'integration' => ['integration_id' => '766']] as $field => $change) {
            $this->gateway->receipt = array_replace($good, $change);
            $result = $this->inspectReadOnly();
            $this->assertSame('not_verified', $result['verification']);
            $this->assertSame('RESTAURANT_PAYMENT_GATEWAY_ATTRIBUTES_MISMATCH', $result['reason_code']);
            $this->assertFalse($result['gateway_matches'][$field], $field);
        }
    }

    public function test_known_historical_success_is_checked_by_its_exact_stored_transaction(): void
    {
        DB::table('payments')->where('id', 1)->update(['status' => 'paid', 'transaction_id' => '991001']);
        $result = $this->inspectReadOnly();
        $this->assertSame('verified', $result['verification']);
        $this->assertSame([['7700', '991001']], $this->gateway->calls);
    }

    public function test_failed_stored_transaction_does_not_replace_the_current_order_inquiry(): void
    {
        DB::table('payments')->where('id', 1)->update(['status' => 'failed', 'transaction_id' => '991000']);
        $result = $this->inspectReadOnly();
        $this->assertSame('verified', $result['verification']);
        $this->assertSame([['7700', null]], $this->gateway->calls);
    }

    public function test_a_different_transaction_cannot_satisfy_a_known_stored_success(): void
    {
        DB::table('payments')->where('id', 1)->update(['status' => '1', 'transaction_id' => '991000']);
        $result = $this->inspectReadOnly();
        $this->assertSame('RESTAURANT_PAYMENT_TRANSACTION_MISMATCH', $result['reason_code']);
        $this->assertFalse($result['gateway_matches']['transaction']);
    }

    public function test_transaction_reuse_and_ambiguous_gateway_binding_are_reported(): void
    {
        $this->order(102);$this->attempt(2, 102, ['transaction_id' => '991001']);
        $result = $this->inspectReadOnly();
        $this->assertSame('RESTAURANT_PAYMENT_TRANSACTION_ALREADY_USED', $result['reason_code']);
        DB::table('payments')->where('id', 2)->update(['transaction_id' => null, 'intention_order_id' => '7700']);
        $this->gateway->calls = [];
        $result = $this->inspectReadOnly();
        $this->assertSame('RESTAURANT_PAYMENT_AMBIGUOUS_GATEWAY_ORDER', $result['reason_code']);
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_known_transport_errors_are_allowlisted_but_arbitrary_exception_text_is_removed(): void
    {
        $this->gateway->failure = new \RuntimeException('WALLET_TOPUP_GATEWAY_HTTP_FAILED');
        $result = $this->inspectReadOnly();
        $this->assertSame('WALLET_TOPUP_GATEWAY_HTTP_FAILED', $result['reason_code']);
        foreach (['Authorization: secret-token for customer@example.com', 'WALLET_TOPUP_GATEWAY_HTTP_FAILED api_key=private-secret'] as $message) {
            $this->gateway->failure = new \RuntimeException($message);
            $result = $this->inspectReadOnly();
            $this->assertSame('DIAGNOSTIC_INTERNAL_ERROR', $result['reason_code']);
            $encoded = json_encode($result);
            foreach (['secret-token', 'customer@example.com', 'private-secret', 'diagnostic-test-api-key'] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_arbitrary_gateway_response_fields_are_not_part_of_the_report(): void
    {
        $this->gateway->receipt['billing_data'] = ['phone_number' => 'private-phone', 'email' => 'private-email'];
        $this->gateway->receipt['token'] = 'private-gateway-token';
        $this->gateway->receipt['unknown_sensitive_field'] = 'private-response-data';
        $result = $this->inspectReadOnly();
        $this->assertSame('verified', $result['verification']);
        $encoded = json_encode($result);
        foreach (['private-phone', 'private-email', 'private-gateway-token', 'private-response-data', 'diagnostic-test-api-key'] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        $this->assertArrayNotHasKey('transaction', $result);$this->assertArrayNotHasKey('response', $result);
    }

    public function test_a_local_device_is_refused_before_any_gateway_inquiry(): void
    {
        config(['desktop_dashboard.local' => true]);
        $before = $this->state();
        $result = $this->diagnostic->inspect(1);
        $this->assertSame('RESTAURANT_PAYMENT_CENTRAL_SERVER_REQUIRED', $result['reason_code']);
        $this->assertSame('not_verified', $result['verification']);
        $this->assertSame([], $this->gateway->calls);$this->assertEquals($before, $this->state());
    }

    public function test_a_valid_receipt_reports_existing_order_restrictions_without_reopening_it(): void
    {
        foreach ([['status' => 'cancelled'], ['accepted_notify' => 'yes'], ['delegate_id' => 55]] as $change) {
            DB::table('orders')->where('id', 101)->update(array_replace(['status' => null, 'accepted_notify' => 'no', 'delegate_id' => null], $change));
            $result = $this->inspectReadOnly();
            $this->assertSame('verified', $result['verification']);
            $this->assertContains($result['eligibility'], ['ORDER_CLOSED', 'ORDER_ALREADY_ACCEPTED_OR_ASSIGNED']);
        }
    }

    public function test_refund_amount_is_only_reported_as_a_fixed_category(): void
    {
        foreach ([0 => 'zero', 1 => 'nonzero_or_invalid'] as $amount => $category) {
            $this->gateway->receipt['refunded_amount_cents'] = $amount;
            $result = $this->inspectReadOnly();
            $this->assertSame($category, $result['gateway_flags']['refunded_amount']);
            $this->assertSame($amount === 0 ? 'verified' : 'not_verified', $result['verification']);
        }
        $this->gateway->receipt['refunded_amount_cents'] = ['private_refund_response' => 'private-value'];
        $result = $this->inspectReadOnly();
        $this->assertSame('nonzero_or_invalid', $result['gateway_flags']['refunded_amount']);
        $this->assertSame('RESTAURANT_PAYMENT_PAYMENT_NOT_SETTLED', $result['reason_code']);
        $this->assertStringNotContainsString('private-value', json_encode($result));
    }

    private function wireDiagnostic(array $responses): RestaurantPaymentDiagnosticWireInquiry
    {
        $wire = new RestaurantPaymentDiagnosticWireInquiry();
        $wire->responses = $responses;
        $this->diagnostic = new RestaurantPaymentReadOnlyDiagnostic(new RestaurantPaymentDiagnosticSettlementGuard($wire), $wire);
        return $wire;
    }

    private function authResponse(): array { return ['body' => json_encode(['token' => 'private-auth-token'])]; }

    public function test_http_status_and_no_transaction_category_are_reported_without_error_body_or_credentials(): void
    {
        $wire = $this->wireDiagnostic([$this->authResponse(), ['status' => 400, 'body' => json_encode([
            'message' => 'No Transaction found', 'private_phone' => 'private-user-phone', 'token' => 'private-error-token'])]]);
        $result = $this->inspectReadOnly();
        $this->assertSame('WALLET_TOPUP_GATEWAY_HTTP_FAILED', $result['reason_code']);
        $this->assertSame('order_inquiry', $result['inquiry_stage']);
        $this->assertSame(['stage' => 'order_inquiry', 'http_status' => 400, 'curl_errno' => 0,
            'response_kind' => 'json_object', 'response_category' => 'NO_TRANSACTION_REPORTED',
            'error_fields' => ['message' => 'string']], $result['transport'][1]);
        $this->assertCount(2, $wire->wireCalls);
        $this->assertSame('POST', $wire->wireCalls[1][0]);
        $this->assertSame('/api/ecommerce/orders/transaction_inquiry', $wire->wireCalls[1][1]);
        $this->assertSame('7700', $wire->wireCalls[1][2]['order_id']);
        foreach (['No Transaction found', 'private-auth-token', 'private-user-phone', 'private-error-token', 'diagnostic-test-api-key'] as $secret) $this->assertStringNotContainsString($secret, json_encode($result));
    }

    public function test_unknown_error_fields_are_reduced_to_fixed_key_type_hints(): void
    {
        $this->wireDiagnostic([$this->authResponse(), ['status' => 400, 'body' => json_encode([
            'errors' => ['order_id' => ['private-value'], 'private_custom_field' => 'private-data'],
            'detail' => 'private-error-detail', 'code' => 'private-error-code'])]]);
        $result = $this->inspectReadOnly();
        $this->assertSame('REQUEST_INVALID', $result['transport'][1]['response_category']);
        $this->assertSame(['detail' => 'string', 'errors' => 'object', 'errors.order_id' => 'array', 'code' => 'string'], $result['transport'][1]['error_fields']);
        foreach (['private-value', 'private-data', 'private_custom_field', 'private-error-detail', 'private-error-code'] as $secret) $this->assertStringNotContainsString($secret, json_encode($result));
    }

    public function test_auth_access_limit_and_server_failures_have_fixed_categories(): void
    {
        foreach ([401 => 'AUTHENTICATION_REJECTED', 403 => 'ACCESS_DENIED', 404 => 'RESOURCE_NOT_FOUND',
            429 => 'RATE_LIMITED', 500 => 'GATEWAY_SERVER_ERROR'] as $status => $category) {
            $this->wireDiagnostic([$this->authResponse(), ['status' => $status, 'body' => 'private-html-response']]);
            $result = $this->inspectReadOnly();
            $this->assertSame($status, $result['transport'][1]['http_status']);
            $this->assertSame('non_json', $result['transport'][1]['response_kind']);
            $this->assertSame($category, $result['transport'][1]['response_category']);
            $this->assertStringNotContainsString('private-html-response', json_encode($result));
        }
    }

    public function test_transport_failure_and_oversize_response_do_not_expose_any_received_data(): void
    {
        $this->wireDiagnostic([$this->authResponse(), ['success' => false, 'status' => 0, 'errno' => 28, 'body' => 'private-incomplete-body']]);
        $result = $this->inspectReadOnly();
        $this->assertSame('WALLET_TOPUP_GATEWAY_FAILED', $result['reason_code']);
        $this->assertSame(28, $result['transport'][1]['curl_errno']);
        $this->assertSame('TRANSPORT_FAILURE', $result['transport'][1]['response_category']);
        $this->assertStringNotContainsString('private-incomplete-body', json_encode($result));
        $this->wireDiagnostic([$this->authResponse(), ['body' => str_repeat('x', 1048577)]]);
        $result = $this->inspectReadOnly();
        $this->assertSame('WALLET_TOPUP_GATEWAY_RESPONSE_TOO_LARGE', $result['reason_code']);
        $this->assertSame('oversize', $result['transport'][1]['response_kind']);
        $this->assertSame('RESPONSE_TOO_LARGE', $result['transport'][1]['response_category']);
    }

    public function test_an_exact_saved_failed_transaction_is_a_separate_fully_verified_read_only_probe(): void
    {
        DB::table('payments')->where('id', 1)->update(['status' => 'failed', 'transaction_id' => '991001']);
        $wire = $this->wireDiagnostic([$this->authResponse(), ['status' => 400, 'body' => '{"message":"No Transaction found"}'],
            $this->authResponse(), ['body' => json_encode($this->gateway->receipt)]]);
        $result = $this->inspectReadOnly();
        $this->assertSame('not_verified', $result['verification']);
        $this->assertSame('WALLET_TOPUP_GATEWAY_HTTP_FAILED', $result['reason_code']);
        $this->assertSame('order_inquiry', $result['inquiry_stage']);
        $this->assertSame('verified', $result['saved_transaction_probe']['verification']);
        $this->assertSame('exact_saved_transaction_id', $result['saved_transaction_probe']['inquiry_scope']);
        $this->assertSame('transaction', $result['saved_transaction_probe']['inquiry_stage']);
        $this->assertSame('GET', $wire->wireCalls[3][0]);
        $this->assertSame('/api/acceptance/transactions/991001', $wire->wireCalls[3][1]);
        $this->assertSame([], $wire->wireCalls[3][2]);
        $this->assertCount(4, $wire->wireCalls);$this->assertCount(2, $result['transport']);
        $this->assertCount(2, $result['saved_transaction_probe']['transport']);
        $this->assertSame('ELIGIBLE_FOR_NORMAL_RECONCILIATION', $result['saved_transaction_probe']['eligibility']);
    }

    public function test_saved_transaction_probe_still_rejects_other_orders_and_does_not_invent_references(): void
    {
        DB::table('payments')->where('id', 1)->update(['status' => 'failed', 'transaction_id' => '991001']);
        $receipt = $this->gateway->receipt;$receipt['order']['id'] = '7701';
        $wire = $this->wireDiagnostic([$this->authResponse(), ['status' => 404, 'body' => '{}'],
            $this->authResponse(), ['body' => json_encode($receipt)]]);
        $result = $this->inspectReadOnly();
        $this->assertSame('not_verified', $result['saved_transaction_probe']['verification']);
        $this->assertSame('RESTAURANT_PAYMENT_GATEWAY_ATTRIBUTES_MISMATCH', $result['saved_transaction_probe']['reason_code']);
        $this->assertFalse($result['saved_transaction_probe']['gateway_matches']['order']);
        foreach ($wire->wireCalls as $call) $this->assertArrayNotHasKey('merchant_order_id', $call[2]);
    }

    public function test_an_auth_failure_never_triggers_an_extra_saved_transaction_probe(): void
    {
        DB::table('payments')->where('id', 1)->update(['status' => 'failed', 'transaction_id' => '991001']);
        $wire = $this->wireDiagnostic([['status' => 401, 'body' => '{"detail":"private-auth-error"}']]);
        $result = $this->inspectReadOnly();
        $this->assertSame('auth', $result['inquiry_stage']);
        $this->assertCount(1, $wire->wireCalls);$this->assertArrayNotHasKey('saved_transaction_probe', $result);
        $this->assertStringNotContainsString('private-auth-error', json_encode($result));
    }

    public function test_candidate_scope_matches_reconciliation_and_uses_only_latest_attempt_per_order(): void
    {
        $this->order(102);$this->attempt(2, 102);$this->attempt(3, 102);
        $this->order(103, ['payment_type' => 'v_cash']);$this->attempt(4, 103);
        foreach ([104 => ['status' => 'accepted'], 105 => ['type' => 'wallet'], 106 => ['payment_type' => 'cash'],
            110 => ['accepted_notify' => 'yes'], 111 => ['transfer_price_by' => 'admin']] as $id => $extra) {
            $this->order($id, $extra);$this->attempt($id, $id);
        }
        $this->order(107);$this->attempt(107, 107, ['user_id' => 21]);
        $this->order(108);$this->attempt(108, 108, ['total_price' => '0.00']);
        $this->order(109);$this->attempt(109, 109, ['intention_order_id' => 'not-a-gateway-id']);
        $before = $this->state();
        $this->assertSame([4, 3, 1], $this->diagnostic->candidateIds());
        $this->assertEquals($before, $this->state());$this->assertSame([], $this->gateway->calls);
    }

    public function test_candidates_are_bounded_to_five_newest_distinct_orders(): void
    {
        for ($i = 2; $i <= 8; $i++) { $this->order(100 + $i);$this->attempt($i, 100 + $i); }
        $before = $this->state();
        $this->assertSame([8, 7, 6, 5, 4], $this->diagnostic->candidateIds());
        $this->assertEquals($before, $this->state());$this->assertSame([], $this->gateway->calls);
    }
}
