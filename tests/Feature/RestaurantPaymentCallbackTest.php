<?php

namespace Tests\Feature;

use App\Http\Controllers\Payment\RestaurantPaymentController;
use App\Services\Dashboard\BestEffortOrderMail;
use App\Services\Dashboard\OrderProviderDelivery;
use App\Services\GoServices\PaymobHmac;
use App\Services\Payments\RestaurantOrderPayment;
use App\Services\Payments\RestaurantPaymentEffects;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestaurantPaymentCallbackTest extends TestCase
{
    private $settlement;
    private $effects;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32)), 'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'dashboard_payments.hmac_secret' => 'restaurant-callback-secret', 'desktop_dashboard.local' => false]);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('user_id');
            $t->string('type'); $t->string('payment_type'); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->string('intention_order_id');
            $t->string('status')->nullable(); $t->string('transaction_id')->nullable();
        });
        DB::table('orders')->insert(['id' => 101, 'resturant_id' => 100, 'user_id' => 20,
            'type' => 'current', 'payment_type' => 'online', 'status' => null,
            'created_at' => '2026-10-22 09:00:00', 'updated_at' => '2026-10-22 09:00:00']);
        DB::table('payments')->insert(['id' => 7, 'order_id' => 101, 'intention_order_id' => '7700']);
        $this->settlement = new class {
            public array $calls = [];
            public array $states = ['activated'];
            public bool $fail = false;
            public function settle(int $paymentId, ?string $transactionId = null): array
            {
                $this->calls[] = [$paymentId, $transactionId];
                if ($this->fail) throw new \RuntimeException('private upstream token customer@example.test');
                return ['state' => array_shift($this->states) ?? 'unchanged', 'order_id' => 101,
                    'payment_id' => $paymentId, 'transaction_id' => $transactionId,
                    'private_gateway_data' => 'Must not be returned'];
            }
        };
        $this->effects = new class extends RestaurantPaymentEffects {
            public array $calls = [];
            public bool $fail = false;
            public function afterActivation(array $result): void
            {
                $this->calls[] = $result;
                if ($this->fail) throw new \RuntimeException('private SMTP credentials');
            }
        };
        $this->app->instance(RestaurantOrderPayment::class, $this->settlement);
        $this->app->instance(RestaurantPaymentEffects::class, $this->effects);
    }

    private function failureFixture(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->default(20); $t->decimal('total_price', 14, 2)->default(265);
        });
        config(['dashboard_payments.integrations.online' => '765', 'dashboard_payments.integrations.v_cash' => '766']);
    }

    public function test_processed_terminal_failure_is_saved_without_browser_return_or_activation(): void
    {
        $this->failureFixture();
        foreach (['online' => 765, 'v_cash' => 766] as $method => $integration) {
            DB::table('orders')->where('id', 101)->update(['payment_type' => $method]);
            DB::table('payments')->where('id', 7)->update(['status' => null, 'transaction_id' => null]);
            $object = $this->object(['success' => false, 'error_occured' => true, 'integration_id' => $integration]);
            foreach ([1, 2] as $delivery) {
                $response = $this->notification($object);
                $this->assertSame(['accepted' => true, 'state' => 'failed'], $this->body($response));
                $this->assertSame('0', DB::table('payments')->where('id', 7)->value('status'));
                $this->assertSame('991001', DB::table('payments')->where('id', 7)->value('transaction_id'));
                $this->assertNull(DB::table('orders')->where('id', 101)->value('status'));
            }
        }
        $this->assertSame([], $this->settlement->calls);
        $this->assertSame([], $this->effects->calls);
    }

    public function test_pending_mismatched_and_reversal_notifications_never_create_failure_evidence(): void
    {
        $this->failureFixture();
        foreach ([['pending' => true], ['amount_cents' => '48000'], ['integration_id' => 999],
            ['order' => ['id' => 123]], ['is_capture' => true], ['is_voided' => true],
            ['is_refunded' => true], ['is_auth' => true], ['has_parent_transaction' => true]] as $change) {
            $response = $this->notification($this->object(array_replace_recursive(['success' => false], $change)));
            $this->assertSame(['accepted' => true, 'state' => 'unpaid'], $this->body($response));
            $this->assertNull(DB::table('payments')->where('id', 7)->value('status'));
        }
        $this->assertSame(403, $this->notification($this->object(['success' => false]), str_repeat('a', 128))->getStatusCode());
        $this->assertSame([], $this->settlement->calls);
    }

    public function test_late_failure_cannot_override_a_success_newer_attempt_or_active_order(): void
    {
        $this->failureFixture();
        $object = $this->object(['success' => false]);
        DB::table('payments')->where('id', 7)->update(['status' => '1', 'transaction_id' => '1234']);
        $this->assertSame('unpaid', $this->body($this->notification($object))['state']);
        $this->assertSame('1', DB::table('payments')->where('id', 7)->value('status'));
        DB::table('payments')->where('id', 7)->update(['status' => null, 'transaction_id' => null]);
        DB::table('payments')->insert(['id' => 8, 'order_id' => 101, 'intention_order_id' => '8800', 'user_id' => 20, 'total_price' => 265]);
        $this->assertSame('unpaid', $this->body($this->notification($object))['state']);
        $this->assertNull(DB::table('payments')->where('id', 7)->value('status'));
        DB::table('payments')->where('id', 8)->delete();
        DB::table('orders')->where('id', 101)->update(['status' => 'pending']);
        $this->assertSame('unpaid', $this->body($this->notification($object))['state']);
        $this->assertNull(DB::table('payments')->where('id', 7)->value('status'));
    }

    public function test_failure_receipt_write_error_remains_retryable_and_private(): void
    {
        $this->app->instance(\App\Services\Payments\LegacyPaymentFailure::class, new class {
            public function recordNotification($request): bool { throw new \RuntimeException('private database details'); }
        });
        $response = $this->notification($this->object(['success' => false]));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['accepted' => false, 'retryable' => true], $this->body($response));
        $this->assertStringNotContainsString('private', $response->getContent());
    }

    private function object(array $extra = []): array
    {
        return array_replace_recursive(['amount_cents' => '26500', 'created_at' => '2026-10-23T20:10:00',
            'currency' => 'EGP', 'error_occured' => false, 'has_parent_transaction' => false, 'id' => 991001,
            'integration_id' => 765, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 7700], 'owner' => 91, 'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'], 'success' => true], $extra);
    }

    private function notification(array $object, ?string $signature = null)
    {
        $signature = $signature ?? PaymobHmac::digest($object, 'restaurant-callback-secret');
        $request = Request::create('/api/order-payment/paymob/notify?hmac='.$signature, 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode(['type' => 'TRANSACTION', 'obj' => $object]));
        return (new RestaurantPaymentController())->notify($request);
    }

    private function body($response): array { return $response->getData(true); }

    public function test_signed_success_uses_only_gateway_identifiers_and_returns_a_small_receipt(): void
    {
        foreach (['online', 'v_cash'] as $method) {
            DB::table('orders')->where('id', 101)->update(['payment_type' => $method]);
            $response = $this->notification($this->object(['merchant_order_id' => '999999', 'metadata' => ['order_id' => 999999]]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['accepted' => true, 'state' => $method === 'online' ? 'activated' : 'unchanged'], $this->body($response));
            $this->assertSame([7, '991001'], end($this->settlement->calls));
        }
        $this->assertCount(1, $this->effects->calls);
    }

    public function test_duplicate_and_closed_callbacks_do_not_repeat_provider_effects(): void
    {
        $this->settlement->states = ['activated', 'unchanged', 'closed'];
        foreach (['activated', 'unchanged', 'closed'] as $state) {
            $response = $this->notification($this->object());
            $this->assertSame(['accepted' => true, 'state' => $state], $this->body($response));
        }
        $this->assertCount(3, $this->settlement->calls);
        $this->assertCount(1, $this->effects->calls);
    }

    public function test_missing_invalid_or_structured_hmac_cannot_reach_settlement(): void
    {
        foreach (['', str_repeat('a', 128), 'not-a-signature'] as $signature) {
            $this->assertSame(403, $this->notification($this->object(), $signature)->getStatusCode());
        }
        $request = Request::create('/api/order-payment/paymob/notify', 'POST', ['type' => 'TRANSACTION',
            'obj' => $this->object(), 'hmac' => ['wrong-shape']]);
        $this->assertSame(403, (new RestaurantPaymentController())->notify($request)->getStatusCode());
        $this->assertSame([], $this->settlement->calls);
        $this->assertSame([], $this->effects->calls);
    }

    public function test_missing_hmac_configuration_remains_retryable_without_revealing_details(): void
    {
        config(['dashboard_payments.hmac_secret' => '']);
        $response = $this->notification($this->object());
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['accepted' => false, 'retryable' => true], $this->body($response));
        $this->assertSame([], $this->settlement->calls);
    }

    public function test_signed_nonsettlement_events_are_acknowledged_without_activation(): void
    {
        foreach ([['success' => false], ['pending' => true], ['is_auth' => true], ['is_capture' => true],
            ['is_voided' => true], ['is_refunded' => true], ['has_parent_transaction' => true], ['error_occured' => true]] as $flags) {
            $response = $this->notification($this->object($flags));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($this->body($response)['accepted']);
        }
        $this->assertSame([], $this->settlement->calls);
        $this->assertSame([], $this->effects->calls);
    }

    public function test_oversized_nontransaction_and_malformed_objects_are_bounded(): void
    {
        $controller = new RestaurantPaymentController();
        $oversized = Request::create('/api/order-payment/paymob/notify', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 262145));
        $this->assertSame(413, $controller->notify($oversized)->getStatusCode());
        $ignored = $controller->notify(Request::create('/api/order-payment/paymob/notify', 'POST', ['type' => 'TOKEN']));
        $this->assertSame(['ignored' => true], $this->body($ignored));
        $bad = $controller->notify(Request::create('/api/order-payment/paymob/notify', 'POST', ['type' => 'TRANSACTION', 'obj' => 'bad']));
        $this->assertSame(400, $bad->getStatusCode());
        $this->assertSame([], $this->settlement->calls);
    }

    public function test_inquiry_failure_is_retryable_and_never_echoes_the_upstream_error(): void
    {
        $this->settlement->fail = true;
        $response = $this->notification($this->object());
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['accepted' => false, 'retryable' => true], $this->body($response));
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertSame([], $this->effects->calls);
    }

    public function test_provider_failure_cannot_change_the_successful_callback_result(): void
    {
        $this->effects->fail = true;
        $response = $this->notification($this->object());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['accepted' => true, 'state' => 'activated'], $this->body($response));
    }

    public function test_return_does_not_trust_success_query_and_requires_independent_confirmation(): void
    {
        $response = (new RestaurantPaymentController())->returnFromGateway(Request::create('/api/order-payment/paymob/return',
            'GET', ['order' => '7700', 'id' => '991001', 'success' => 'false']));
        $this->assertSame(route('paySuccess'), $response->getTargetUrl());
        $this->assertSame([[7, '991001']], $this->settlement->calls);
        $this->settlement->fail = true;
        $response = (new RestaurantPaymentController())->returnFromGateway(Request::create('/api/order-payment/paymob/return',
            'GET', ['order' => '7700', 'id' => '991001', 'success' => 'true']));
        $this->assertSame(route('payFailed'), $response->getTargetUrl());
        $this->settlement->fail = false;
        $this->settlement->states = ['closed'];
        $response = (new RestaurantPaymentController())->returnFromGateway(Request::create('/api/order-payment/paymob/return',
            'GET', ['order' => '7700', 'id' => '991001', 'success' => 'true']));
        $this->assertSame(route('payFailed'), $response->getTargetUrl());
    }

    public function test_legacy_success_return_is_routed_through_the_verified_service(): void
    {
        $this->get('/api/pament/callback?success=true&order=7700&id=991001')->assertRedirect(route('paySuccess'));
        $this->assertSame([[7, '991001']], $this->settlement->calls);
        $this->assertCount(1, $this->effects->calls);
        $this->assertNull(DB::table('orders')->where('id', 101)->value('status'), 'The legacy callback must not bypass the authoritative service with its old retained mutation.');
        $this->assertNull(DB::table('payments')->where('id', 7)->value('status'));
        $this->settlement->fail = true;
        $this->get('/api/pament/callback?success=true&order=7700&id=991001')->assertRedirect(route('payFailed'));
        $this->assertCount(2, $this->settlement->calls);
        $this->assertCount(1, $this->effects->calls);
    }

    public function test_registered_notify_and_return_routes_use_the_new_controller(): void
    {
        $object = $this->object();
        $signature = PaymobHmac::digest($object, 'restaurant-callback-secret');
        $this->postJson('/api/order-payments/paymob/notify?hmac='.$signature, ['type' => 'TRANSACTION', 'obj' => $object])
            ->assertOk()->assertExactJson(['accepted' => true, 'state' => 'activated']);
        $this->get('/api/order-payments/paymob/return?order=7700&id=991001&success=false')->assertRedirect(route('paySuccess'));
        $this->assertSame([[7, '991001'], [7, '991001']], $this->settlement->calls);
        $this->assertCount(1, $this->effects->calls);
    }

    public function test_new_return_preserves_only_signed_terminal_failure_evidence_when_inquiry_fails(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->default(20); $t->decimal('total_price', 14, 2)->default(265);
        });
        config(['dashboard_payments.integrations.online' => '765']);
        $this->settlement->fail = true;
        $object = $this->object(['success' => false, 'error_occured' => true]);
        foreach ($object as $key => $value) if (is_bool($value)) $object[$key] = $value ? 'true' : 'false';
        $query = $object;
        $query['order'] = $object['order']['id'];
        unset($query['source_data']);
        foreach ($object['source_data'] as $key => $value) $query['source_data.'.$key] = $value;
        $this->get('/api/order-payments/paymob/return?'.http_build_query($query))->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->where('id', 7)->value('status'));
        $query['hmac'] = PaymobHmac::digest($object, 'restaurant-callback-secret');
        $this->get('/api/order-payments/paymob/return?'.http_build_query($query))->assertRedirect(route('payFailed'));
        $payment = DB::table('payments')->where('id', 7)->first();
        $this->assertSame('0', $payment->status);
        $this->assertSame('991001', $payment->transaction_id);
        $this->assertNull(DB::table('orders')->where('id', 101)->value('status'));
        $this->assertSame([], $this->effects->calls);
    }

    public function test_unknown_duplicate_and_nonrestaurant_attempts_never_reach_settlement(): void
    {
        $controller = new RestaurantPaymentController();
        foreach (['wallet', 'shipping'] as $type) {
            DB::table('orders')->where('id', 101)->update(['type' => $type]);
            $this->assertSame(503, $this->notification($this->object())->getStatusCode());
        }
        DB::table('orders')->where('id', 101)->update(['type' => 'current', 'payment_type' => 'cash']);
        $this->assertSame(503, $this->notification($this->object())->getStatusCode());
        DB::table('orders')->where('id', 101)->update(['payment_type' => 'online']);
        DB::table('payments')->insert(['id' => 8, 'order_id' => 101, 'intention_order_id' => '7700']);
        $this->assertSame(503, $this->notification($this->object())->getStatusCode());
        $this->assertSame([], $this->settlement->calls);
        foreach ([null, '', '0', '../7700', ['7700'], str_repeat('7', 21)] as $invalid) {
            try { $controller->confirm($invalid, '991001'); $this->fail('Invalid identifier accepted'); }
            catch (\RuntimeException $error) { $this->assertSame('ORDER_PAYMENT_INVALID_CALLBACK', $error->getMessage()); }
        }
    }

    private function effectFixture(): array
    {
        Schema::create('resturants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id'); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('account_type'); $t->decimal('balance', 12, 2);
        });
        DB::table('resturants')->insert(['id' => 100, 'user_id' => 10]);
        DB::table('users')->insert([
            ['id' => 10, 'name' => 'Branch', 'email' => null, 'account_type' => 'vendor', 'balance' => '500.00'],
            ['id' => 20, 'name' => 'Customer', 'email' => 'customer@example.test', 'account_type' => 'user', 'balance' => '100.00'],
        ]);
        DB::table('orders')->where('id', 101)->update(['status' => 'pending']);
        $delivery = new class extends OrderProviderDelivery {
            public array $events = [];
            public array $deferred = [];
            public bool $fail = false;
            public function event(object $event, ?int $orderId = null): void
            {
                $this->events[] = [$event, $orderId, DB::transactionLevel()];
                if ($this->fail) throw new \RuntimeException('private broadcast credentials');
            }
            public function defer(callable $work, ?int $orderId = null, string $kind = 'notice'): void
            { $this->deferred[] = [$work, $orderId, $kind, DB::transactionLevel()]; }
        };
        $mail = new class extends BestEffortOrderMail {
            public array $calls = [];
            public function send(int $orderId, string $view, array $data, callable $message): void
            { $this->calls[] = [$orderId, $view, $data, $message, DB::transactionLevel()]; }
        };
        $this->app->instance(OrderProviderDelivery::class, $delivery);
        $this->app->instance(BestEffortOrderMail::class, $mail);
        return [$delivery, $mail];
    }

    public function test_effects_run_after_commit_and_never_change_order_or_balances(): void
    {
        [$delivery, $mail] = $this->effectFixture();
        $before = [DB::table('orders')->first(), DB::table('payments')->first(), DB::table('users')->orderBy('id')->get()->all()];
        DB::beginTransaction();
        (new RestaurantPaymentEffects())->afterActivation(['state' => 'activated', 'order_id' => 101]);
        $this->assertSame([], $delivery->events);
        $this->assertSame([], $mail->calls);
        DB::commit();
        $this->assertCount(3, $delivery->events);
        $this->assertCount(1, $delivery->deferred);
        $this->assertCount(1, $mail->calls);
        foreach ($delivery->events as $event) $this->assertSame(0, $event[2]);
        $this->assertSame(0, $delivery->deferred[0][3]);
        $this->assertSame(0, $mail->calls[0][4]);
        $this->assertEquals($before, [DB::table('orders')->first(), DB::table('payments')->first(), DB::table('users')->orderBy('id')->get()->all()]);
    }

    public function test_provider_errors_do_not_prevent_other_effects_or_escape_activation(): void
    {
        [$delivery, $mail] = $this->effectFixture();
        $delivery->fail = true;
        (new RestaurantPaymentEffects())->afterActivation(['state' => 'activated', 'order_id' => 101]);
        $this->assertCount(3, $delivery->events);
        $this->assertCount(1, $delivery->deferred);
        $this->assertCount(1, $mail->calls);
        $this->assertSame('pending', DB::table('orders')->where('id', 101)->value('status'));
    }

    public function test_main_admin_receives_the_existing_new_order_broadcast_after_activation(): void
    {
        [$delivery, $mail] = $this->effectFixture();
        DB::table('users')->insert(['id' => 635, 'name' => 'Main administrator', 'email' => 'omarmakramelbazz@gmail.com',
            'account_type' => 'admin', 'balance' => '0.00']);
        (new RestaurantPaymentEffects())->afterActivation(['state' => 'activated', 'order_id' => 101]);
        $this->assertCount(4, $delivery->events);
        $this->assertSame(635, (int) $delivery->events[0][0]->senderId);
        $this->assertSame('new', $delivery->events[0][0]->action);
        $this->assertSame(0, $delivery->events[0][2]);
    }

    public function test_unchanged_closed_local_and_already_accepted_orders_do_not_emit_new_order_effects(): void
    {
        [$delivery, $mail] = $this->effectFixture();
        $effects = new RestaurantPaymentEffects();
        foreach (['unchanged', 'closed'] as $state) $effects->afterActivation(['state' => $state, 'order_id' => 101]);
        config(['desktop_dashboard.local' => true]);
        $effects->afterActivation(['state' => 'activated', 'order_id' => 101]);
        config(['desktop_dashboard.local' => false]);
        DB::table('orders')->where('id', 101)->update(['status' => 'accepted']);
        $effects->afterActivation(['state' => 'activated', 'order_id' => 101]);
        $this->assertSame([], $delivery->events);
        $this->assertSame([], $mail->calls);
    }
}
