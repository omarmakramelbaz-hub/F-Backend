<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\OrderBoardService;
use App\Services\GoServices\PaymobHmac;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardOrderPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'dashboard_payments.hmac_secret' => 'test-callback-secret',
            'dashboard_payments.integrations.online' => '765', 'dashboard_payments.integrations.v_cash' => '766']);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_type');
            $table->string('app_scope')->nullable();
        });
        Schema::create('resturants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('resturant_id');
            $table->string('order_no');
            $table->string('type');
            $table->string('status')->nullable();
            $table->string('payment_type');
            $table->decimal('total_price', 14, 2);
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->nullable();
            $table->string('intention_order_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->decimal('total_price', 14, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('go_store_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->text('snapshot');
            $table->string('status');
            $table->string('payment_method');
            $table->string('payment_status');
            $table->unsignedBigInteger('total_cents')->default(12500);
            $table->timestamps();
        });
        Schema::create('go_service_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('profession_key')->default('plumber');
            $table->string('status');
            $table->string('payment_method');
            $table->string('payment_status');
            $table->timestamps();
        });
        Schema::create('go_service_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id');
            $table->string('status');
        });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Administrator', 'account_type' => 'admin', 'app_scope' => 'fasakhansta'],
            ['id' => 20, 'name' => 'Customer', 'account_type' => 'user', 'app_scope' => 'fasakhansta'],
        ]);
        DB::table('resturants')->insert(['id' => 100, 'user_id' => 10, 'name' => 'Restaurant']);
        $this->order();
    }

    private function actor(): User
    {
        return (new User())->forceFill((array) DB::table('users')->find(1));
    }

    private function order(array $extra = []): void
    {
        DB::table('orders')->updateOrInsert(['id' => 101], array_replace([
            'user_id' => 20, 'resturant_id' => 100, 'order_no' => 'R100-101', 'type' => 'current',
            'status' => null, 'payment_type' => 'online', 'total_price' => '125.00',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function attempt(array $extra = []): int
    {
        return DB::table('payments')->insertGetId(array_replace([
            'order_id' => 101, 'user_id' => 20, 'status' => null, 'intention_order_id' => '7700',
            'total_price' => '125.00', 'transaction_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function card(string $source = 'legacy', int $id = 101): array
    {
        return (new OrderBoardService())->detail($source, $id, $this->actor());
    }

    private function deliverFailure(array $extra = [], bool $valid = true)
    {
        $object = array_replace_recursive([
            'amount_cents' => '12500', 'created_at' => '2026-10-03T09:00:00', 'currency' => 'EGP',
            'error_occured' => 'true', 'has_parent_transaction' => 'false', 'id' => '991001',
            'integration_id' => '765', 'is_3d_secure' => 'true', 'is_auth' => 'false', 'is_capture' => 'false',
            'is_refunded' => 'false', 'is_standalone_payment' => 'true', 'is_voided' => 'false',
            'order' => ['id' => '7700'], 'owner' => '91', 'pending' => 'false',
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'], 'success' => 'false',
        ], $extra);
        $hmac = PaymobHmac::digest($object, $valid ? 'test-callback-secret' : 'incorrect-secret');
        $query = $object;
        $query['order'] = $object['order']['id'];
        unset($query['source_data']);
        foreach ($object['source_data'] as $field => $value) $query['source_data.'.$field] = $value;
        $query['hmac'] = $hmac;
        return $this->get('/api/pament/callback?'.http_build_query($query));
    }

    public function test_verified_terminal_card_and_vodafone_failures_record_only_payment_evidence(): void
    {
        foreach (['online' => '765', 'v_cash' => '766'] as $method => $integration) {
            DB::table('payments')->delete();
            $this->order(['payment_type' => $method]);
            $id = $this->attempt();
            $orderBefore = DB::table('orders')->find(101);
            $this->deliverFailure(['integration_id' => $integration])->assertRedirect(route('payFailed'));
            $payment = DB::table('payments')->find($id);
            $this->assertSame('0', $payment->status);
            $this->assertSame('991001', $payment->transaction_id);
            $this->assertSame(125, (int) $payment->total_price);
            $this->assertEquals($orderBefore, DB::table('orders')->find(101));
            $this->assertTrue($this->card()['payment_failed']);
            $beforeDuplicate = $payment;
            $this->deliverFailure(['integration_id' => $integration])->assertRedirect(route('payFailed'));
            $this->assertEquals($beforeDuplicate, DB::table('payments')->find($id));
        }
    }

    public function test_invalid_signature_missing_fields_and_unsigned_redirect_never_record_failure(): void
    {
        $id = $this->attempt();
        $this->deliverFailure([], false)->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        $this->get('/api/pament/callback?success=false&order=7700&id=991001&amount_cents=12500')
            ->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        $this->deliverFailure(['pending' => null])->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        config(['dashboard_payments.hmac_secret' => '']);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        $this->assertFalse($this->card()['payment_failed']);
    }

    public function test_signed_pending_auth_and_reversal_callbacks_are_not_failed_transactions(): void
    {
        $id = $this->attempt();
        foreach (['pending', 'is_auth', 'is_refunded', 'is_voided', 'has_parent_transaction'] as $flag) {
            $this->deliverFailure([$flag => 'true'])->assertRedirect(route('payFailed'));
            $this->assertNull(DB::table('payments')->find($id)->status, $flag);
        }
    }

    public function test_signed_order_amount_currency_integration_and_user_binding_are_required(): void
    {
        $id = $this->attempt();
        foreach ([['order' => ['id' => '8800'], 'merchant_order_id' => '101', 'intention_order_id' => '7700'],
            ['amount_cents' => '12501'], ['currency' => 'USD'], ['integration_id' => '766']] as $mismatch) {
            $this->deliverFailure($mismatch)->assertRedirect(route('payFailed'));
            $this->assertNull(DB::table('payments')->find($id)->status);
            $this->assertFalse($this->card()['payment_failed']);
        }
        DB::table('payments')->where('id', $id)->update(['user_id' => 21]);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        DB::table('payments')->where('id', $id)->update(['user_id' => 20, 'total_price' => '0.00']);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
    }

    public function test_late_failure_does_not_override_success_new_retry_or_an_accepted_order(): void
    {
        $id = $this->attempt();
        DB::table('payments')->where('id', $id)->update(['status' => '1', 'transaction_id' => '991009']);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertSame('1', DB::table('payments')->find($id)->status);
        $this->assertFalse($this->card()['payment_failed']);
        DB::table('payments')->where('id', $id)->update(['status' => null, 'transaction_id' => null]);
        $retry = $this->attempt(['intention_order_id' => '7701']);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        $this->assertNull(DB::table('payments')->find($retry)->status);
        DB::table('payments')->where('id', $retry)->delete();
        $this->order(['status' => 'pending']);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
        $this->order(['type' => 'wallet']);
        $this->deliverFailure()->assertRedirect(route('payFailed'));
        $this->assertNull(DB::table('payments')->find($id)->status);
    }

    public function test_unfinished_online_and_vodafone_checkout_are_not_invented_failures(): void
    {
        foreach (['online', 'v_cash'] as $method) {
            $this->order(['payment_type' => $method]);
            $this->assertFalse($this->card()['payment_failed']);
            foreach ([null, '', '0', 'false', 'pending', 'creating'] as $status) {
                DB::table('payments')->delete();
                $this->attempt(['status' => $status]);
                $this->assertFalse($this->card()['payment_failed'], 'Unresolved attempt: '.var_export($status, true));
            }
        }
    }

    public function test_persisted_terminal_failure_is_visible_but_does_not_mutate_order_or_payment(): void
    {
        foreach (['0', 'false', 'failed'] as $status) {
            DB::table('payments')->delete();
            $this->attempt(['status' => $status, 'transaction_id' => '991001']);
            $orderBefore = DB::table('orders')->find(101);
            $paymentBefore = DB::table('payments')->first();
            $card = $this->card();
            $this->assertTrue($card['payment_failed']);
            $this->assertSame('completed', $card['group']);
            $this->assertSame('125.00', $card['total']);
            $this->assertEquals($orderBefore, DB::table('orders')->find(101));
            $this->assertEquals($paymentBefore, DB::table('payments')->first());
        }
        $this->order(['payment_type' => 'v_cash']);
        $this->assertSame('فودافون كاش', $this->card()['payment_label']);
    }

    public function test_successful_retry_and_new_unresolved_attempt_remove_stale_failure_flag(): void
    {
        $this->attempt(['status' => '0', 'transaction_id' => '991001']);
        $this->assertTrue($this->card()['payment_failed']);
        $this->attempt(['status' => 'pending', 'intention_order_id' => '7701']);
        $this->assertFalse($this->card()['payment_failed']);
        $this->attempt(['status' => '1', 'transaction_id' => '991002', 'intention_order_id' => '7702']);
        $this->assertFalse($this->card()['payment_failed']);
        $this->attempt(['status' => '0', 'transaction_id' => '991003', 'intention_order_id' => '7703']);
        $this->assertFalse($this->card()['payment_failed'], 'An earlier settled successful payment remains authoritative.');
    }

    public function test_cancelled_cash_wallet_refunds_and_payment_review_are_not_failed_payments(): void
    {
        $this->attempt(['status' => '0', 'transaction_id' => '991001']);
        foreach (['cash', 'wallet'] as $method) {
            $this->order(['status' => 'cancelled', 'payment_type' => $method]);
            $this->assertFalse($this->card()['payment_failed']);
        }
        DB::table('go_store_orders')->insert(['id' => 201, 'store_id' => 30, 'customer_id' => 20,
            'snapshot' => '{}', 'status' => 'cancelled', 'payment_method' => 'card', 'payment_status' => 'cancelled',
            'created_at' => now(), 'updated_at' => now()]);
        foreach (['unpaid', 'pending', 'cash_due', 'held', 'paid', 'review', 'cancelled', 'refunded', 'refund_pending'] as $status) {
            DB::table('go_store_orders')->where('id', 201)->update(['payment_status' => $status]);
            $this->assertFalse($this->card('store', 201)['payment_failed'], $status);
        }
        DB::table('go_store_orders')->where('id', 201)->update(['payment_status' => 'failed']);
        $this->assertTrue($this->card('store', 201)['payment_failed']);
    }

    public function test_service_attempt_failure_never_overrides_held_paid_or_review(): void
    {
        DB::table('go_service_jobs')->insert(['id' => 301, 'customer_id' => 20, 'status' => 'cancelled',
            'payment_method' => 'card', 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('go_service_payments')->insert(['job_id' => 301, 'status' => 'failed']);
        $this->assertTrue($this->card('service', 301)['payment_failed']);
        foreach (['held', 'paid', 'review', 'refund_pending', 'cancelled'] as $status) {
            DB::table('go_service_jobs')->where('id', 301)->update(['payment_status' => $status]);
            $this->assertFalse($this->card('service', 301)['payment_failed'], $status);
        }
        DB::table('go_service_jobs')->where('id', 301)->update(['payment_status' => 'unpaid']);
        DB::table('go_service_payments')->update(['status' => 'pending']);
        $this->assertFalse($this->card('service', 301)['payment_failed']);
    }

    public function test_failure_text_is_localized_in_compact_card_detail_and_print(): void
    {
        $this->attempt(['status' => '0', 'transaction_id' => '991001']);
        foreach (['ar' => 'فشل الدفع', 'en' => 'Payment failed'] as $locale => $label) {
            app()->setLocale($locale);
            $card = $this->card();
            $board = view('admin.orders.board_card', compact('card'))->render();
            $detail = view('admin.orders.board_detail', ['card' => $card, 'printing' => false])->render();
            $print = view('admin.orders.board_detail', ['card' => $card, 'printing' => true])->render();
            foreach ([$board, $detail, $print] as $html) {
                $this->assertStringContainsString($label, $html);
                $this->assertStringContainsString('payment-failed', $html);
                $this->assertStringContainsString('125.00', $html === $board ? $card['total'] : $html);
            }
            $this->assertStringContainsString('fasakhansta-logo-transparent.png', $print);
        }
    }

    public function test_older_database_without_optional_payment_tables_still_displays_orders(): void
    {
        Schema::drop('payments');
        Schema::drop('go_service_payments');
        $data = (new OrderBoardService())->data(Request::create('/admin/order-review'), $this->actor());
        $this->assertSame(1, $data['counts']['completed']);
        $this->assertFalse($data['groups']['completed'][0]['payment_failed']);
    }
}
