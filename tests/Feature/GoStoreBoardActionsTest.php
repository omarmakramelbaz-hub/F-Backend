<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\GoStoreOrderNotice;
use App\Services\Dashboard\GoStoreBoardActions;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GoStoreBoardActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'account_type', 'app_scope', 'status'] as $field) $table->string($field);
            $table->unsignedBigInteger('pending_vendor_id')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('go_stores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('name');
        });
        Schema::create('go_store_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('store_id');
            $table->string('status');
            $table->string('fulfillment');
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('delivery_cents');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('commission_cents');
            $table->string('payment_method');
            $table->string('payment_status');
            $table->text('snapshot');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_user')->nullable();
            $table->unsignedBigInteger('to_user')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('amount', 12, 2);
            foreach (['status', 'payment', 'type'] as $field) $table->string($field);
            $table->string('transfer_reference')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->string('name');
            $table->text('payload');
        });

        DB::table('users')->insert([
            ['id' => 10, 'name' => 'Customer', 'account_type' => 'user', 'app_scope' => 'go', 'status' => 'accepted', 'balance' => '250.00'],
            ['id' => 20, 'name' => 'Store', 'account_type' => 'vendor', 'app_scope' => 'go_partner', 'status' => 'accepted', 'balance' => '100.00'],
            ['id' => 30, 'name' => 'Other store', 'account_type' => 'vendor', 'app_scope' => 'go_partner', 'status' => 'accepted', 'balance' => '100.00'],
        ]);
        // Profile IDs deliberately differ from owner user IDs.
        DB::table('go_stores')->insert([
            ['id' => 2, 'user_id' => 20, 'name' => 'First'], ['id' => 3, 'user_id' => 30, 'name' => 'Other'],
        ]);
        DB::table('settings')->insert(['group' => 'general', 'name' => 'app_balance', 'payload' => json_encode('0.00')]);
    }

    private function order(array $changes = []): int
    {
        return DB::table('go_store_orders')->insertGetId(array_replace([
            'customer_id' => 10, 'store_id' => 20, 'status' => 'pending', 'fulfillment' => 'delivery', 'revision' => 1,
            'subtotal_cents' => 10000, 'delivery_cents' => 5000, 'total_cents' => 15000, 'commission_cents' => 1000,
            'payment_method' => 'cash', 'payment_status' => 'cash_due', 'snapshot' => '{}', 'reason' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $changes));
    }

    private function act(int $id, string $action, int $revision = 1, int $owner = 20, ?string $reason = null): bool
    {
        return app(GoStoreBoardActions::class)->transition($id, $owner, $action, $revision, $reason);
    }

    private function balance(int $id): float
    {
        return (float) DB::table('users')->where('id', $id)->value('balance');
    }

    private function appBalance(): string
    {
        return json_decode(DB::table('settings')->where('name', 'app_balance')->value('payload'), true);
    }

    private function assertBlocked(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected the action to be blocked.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function assertLedger(int $id, string $kind, ?int $from, ?int $to, float $amount): void
    {
        $row = DB::table('wallets')->where('transfer_reference', 'gs:'.$id.':'.$kind)->first();
        $this->assertNotNull($row);
        $this->assertSame($from, $row->from_user === null ? null : (int) $row->from_user);
        $this->assertSame($to, $row->to_user === null ? null : (int) $row->to_user);
        $this->assertSame($amount, (float) $row->amount);
        $this->assertNull($row->order_id);
        $this->assertSame('completed', $row->status);
    }

    public function test_accept_debits_stored_commission_and_credits_app_once_even_when_response_is_retried(): void
    {
        $id = $this->order();
        $this->assertTrue($this->act($id, 'accept'));
        $this->assertFalse($this->act($id, 'accept'));
        $this->assertSame(90.0, $this->balance(20));
        $this->assertSame(250.0, $this->balance(10));
        $this->assertSame('10.00', $this->appBalance());
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertLedger($id, 'fee', 20, null, 10.0);
        $row = DB::table('go_store_orders')->find($id);
        $this->assertSame('preparing', $row->status);
        $this->assertSame(2, (int) $row->revision);
    }

    public function test_cash_rejection_changes_no_wallet_and_never_charges_commission(): void
    {
        $id = $this->order();
        $this->act($id, 'reject', 1, 20, 'Unavailable');
        $this->act($id, 'reject', 1, 20, 'Retry');
        $row = DB::table('go_store_orders')->find($id);
        $this->assertSame('rejected', $row->status);
        $this->assertSame('cancelled', $row->payment_status);
        $this->assertSame('Unavailable', $row->reason);
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame(250.0, $this->balance(10));
        $this->assertSame('0.00', $this->appBalance());
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_wallet_rejection_releases_customer_hold_exactly_once(): void
    {
        $id = $this->order(['payment_method' => 'wallet', 'payment_status' => 'held']);
        $this->act($id, 'reject');
        $this->act($id, 'reject');
        $this->assertSame(400.0, $this->balance(10));
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame('refunded', DB::table('go_store_orders')->find($id)->payment_status);
        $this->assertLedger($id, 'refund', null, 10, 150.0);
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame('0.00', $this->appBalance());
    }

    public function test_online_rejection_reverses_credited_gross_once_and_keeps_gateway_refund_pending(): void
    {
        $id = $this->order(['payment_method' => 'card', 'payment_status' => 'paid']);
        DB::table('users')->where('id', 20)->update(['balance' => '250.00']);
        DB::table('wallets')->insert(['from_user' => null, 'to_user' => 20, 'amount' => '150.00', 'status' => 'completed',
            'payment' => 'wallet', 'type' => 'transfer', 'transfer_reference' => 'gs:'.$id.':gross', 'created_at' => now(), 'updated_at' => now()]);
        $this->act($id, 'reject');
        $this->act($id, 'reject');
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame(250.0, $this->balance(10));
        $this->assertSame('refund_pending', DB::table('go_store_orders')->find($id)->payment_status);
        $this->assertLedger($id, 'reversal', 20, null, 150.0);
        $this->assertSame(2, DB::table('wallets')->count());
        $this->assertSame('0.00', $this->appBalance());
    }

    public function test_wallet_delivery_releases_gross_to_owner_once_only_after_completion(): void
    {
        $id = $this->order(['payment_method' => 'wallet', 'payment_status' => 'held']);
        $this->act($id, 'accept');
        $this->act($id, 'ready', 2);
        $this->act($id, 'dispatch', 3);
        $this->assertSame(90.0, $this->balance(20));
        $this->assertSame('held', DB::table('go_store_orders')->find($id)->payment_status);
        $this->assertSame(1, DB::table('wallets')->count());
        $this->act($id, 'complete', 4);
        $this->act($id, 'complete', 4);
        $this->assertSame(240.0, $this->balance(20));
        $this->assertSame(250.0, $this->balance(10));
        $this->assertSame('10.00', $this->appBalance());
        $this->assertLedger($id, 'gross', null, 20, 150.0);
        $this->assertSame(2, DB::table('wallets')->count());
        $row = DB::table('go_store_orders')->find($id);
        $this->assertSame('completed', $row->status);
        $this->assertSame('paid', $row->payment_status);
        $this->assertSame(5, (int) $row->revision);
        $this->assertBlocked(fn () => $this->act($id, 'reject', 5), 409);
        $this->assertSame(240.0, $this->balance(20));
    }

    public function test_cash_pickup_completion_never_credits_virtual_gross(): void
    {
        $id = $this->order(['fulfillment' => 'pickup']);
        $this->act($id, 'accept');
        $this->act($id, 'ready', 2);
        $this->assertBlocked(fn () => $this->act($id, 'dispatch', 3), 409);
        $this->act($id, 'complete', 3);
        $this->act($id, 'complete', 3);
        $this->assertSame(90.0, $this->balance(20));
        $this->assertSame(250.0, $this->balance(10));
        $this->assertSame('10.00', $this->appBalance());
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame('cash_collected', DB::table('go_store_orders')->find($id)->payment_status);
    }

    public function test_awaiting_payment_and_disputed_payment_block_fulfillment_without_financial_changes(): void
    {
        foreach ([['status' => 'awaiting_payment', 'payment_method' => 'card', 'payment_status' => 'pending'],
            ['payment_method' => 'card', 'payment_status' => 'review'], ['payment_method' => 'card', 'payment_status' => 'refund_pending']] as $state) {
            $id = $this->order($state);
            $this->assertBlocked(fn () => $this->act($id, 'accept'), 409);
            $this->assertSame(1, (int) DB::table('go_store_orders')->find($id)->revision);
        }
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame('0.00', $this->appBalance());
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_other_store_and_catalog_profile_id_cannot_act_on_owner_order(): void
    {
        $id = $this->order();
        $this->assertBlocked(fn () => $this->act($id, 'accept', 1, 30), 404);
        $this->assertBlocked(fn () => $this->act($id, 'accept', 1, 2), 404);
        $this->assertSame('pending', DB::table('go_store_orders')->find($id)->status);
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame(100.0, $this->balance(30));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_stale_and_unavailable_actions_leave_money_and_status_unchanged(): void
    {
        $id = $this->order(['revision' => 3]);
        $this->assertBlocked(fn () => $this->act($id, 'accept', 2), 409);
        $this->assertBlocked(fn () => $this->act($id, 'complete', 3), 409);
        $this->assertSame('pending', DB::table('go_store_orders')->find($id)->status);
        $this->assertSame(3, (int) DB::table('go_store_orders')->find($id)->revision);
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_owner_authentication_still_applies_to_repeated_committed_actions(): void
    {
        $id = $this->order();
        $this->act($id, 'accept');
        foreach ([['status' => 'disabled'], ['app_scope' => 'go'], ['account_type' => 'user'], ['account_type' => 'admin']] as $change) {
            DB::table('users')->where('id', 20)->update(array_replace(['status' => 'accepted', 'app_scope' => 'go_partner', 'account_type' => 'vendor'], $change));
            $this->assertBlocked(fn () => $this->act($id, 'accept'), 403);
        }
        DB::table('users')->where('id', 20)->update(['status' => 'accepted', 'app_scope' => 'go_partner', 'account_type' => 'vendor']);
        DB::table('go_stores')->where('user_id', 20)->delete();
        $this->assertBlocked(fn () => $this->act($id, 'accept'), 403);
        $this->assertSame(90.0, $this->balance(20));
        $this->assertSame('10.00', $this->appBalance());
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(2, (int) DB::table('go_store_orders')->find($id)->revision);
    }

    public function test_legacy_delegate_store_requires_the_store_owner_profession(): void
    {
        Schema::create('pending_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('profession_key');
        });
        DB::table('pending_vendors')->insert(['id' => 8, 'profession_key' => 'plumber']);
        DB::table('users')->where('id', 20)->update(['account_type' => 'delegate', 'pending_vendor_id' => 8]);
        $id = $this->order();
        $this->assertBlocked(fn () => $this->act($id, 'accept'), 403);
        $this->assertSame(100.0, $this->balance(20));
        DB::table('pending_vendors')->where('id', 8)->update(['profession_key' => 'store_owner']);
        $this->act($id, 'accept');
        $this->assertSame(90.0, $this->balance(20));
        $this->assertSame('preparing', DB::table('go_store_orders')->find($id)->status);
        $this->assertLedger($id, 'fee', 20, null, 10.0);
    }

    public function test_inconsistent_payment_method_and_status_cannot_refund_or_charge(): void
    {
        foreach ([['cash', 'held'], ['cash', 'paid'], ['wallet', 'cash_due'], ['wallet', 'paid'], ['card', 'held'], ['card', 'cash_due']] as [$method, $payment]) {
            $id = $this->order(['payment_method' => $method, 'payment_status' => $payment]);
            $this->assertBlocked(fn () => $this->act($id, 'accept'), 409);
            $this->assertBlocked(fn () => $this->act($id, 'reject'), 409);
            $this->assertSame('pending', DB::table('go_store_orders')->find($id)->status);
        }
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame(250.0, $this->balance(10));
        $this->assertSame('0.00', $this->appBalance());
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_missing_financial_columns_disable_actions_and_fail_cleanly(): void
    {
        $id = $this->order();
        Schema::drop('wallets');
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_reference')->nullable()->unique();
        });
        $adapter = app(GoStoreBoardActions::class);
        $this->assertFalse($adapter->ready());
        $this->assertSame([], $adapter->available(DB::table('go_store_orders')->find($id)));
        $this->assertBlocked(fn () => $this->act($id, 'accept'), 503);
        $this->assertSame('pending', DB::table('go_store_orders')->find($id)->status);
        $this->assertSame(100.0, $this->balance(20));
    }

    public function test_customer_notification_preserves_go_store_deep_link_and_committed_revision(): void
    {
        Notification::fake();
        $id = $this->order();
        $this->act($id, 'accept');
        app(GoStoreBoardActions::class)->notifyCustomer($id);
        Notification::assertSentTo(User::withoutGlobalScopes()->findOrFail(10), GoStoreOrderNotice::class,
            function (GoStoreOrderNotice $notice) use ($id) {
                $recipient = (object) ['account_type' => 'user', 'my_tokens' => null];
                $payload = $notice->toDatabase($recipient);
                $this->assertSame(12, $payload['data']['notification_type']);
                $this->assertSame($id, $payload['data']['go_store_order_id']);
                $this->assertSame('store:'.$id.':2', $payload['data']['event_id']);
                $this->assertSame('user', $payload['data']['account_type']);
                $this->assertSame('default', $payload['data']['notification_sound']);
                $this->assertArrayNotHasKey('order_id', $payload['data']);
                return true;
            });
        Notification::assertNotSentTo(User::withoutGlobalScopes()->findOrFail(20), GoStoreOrderNotice::class);
    }

    public function test_notification_failure_never_reverts_committed_acceptance(): void
    {
        $id = $this->order();
        $this->act($id, 'accept');
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('notification fixture failure'));
        app(GoStoreBoardActions::class)->notifyCustomer($id);
        $this->assertSame('preparing', DB::table('go_store_orders')->find($id)->status);
        $this->assertSame(90.0, $this->balance(20));
        $this->assertSame('10.00', $this->appBalance());
        $this->assertSame(1, DB::table('wallets')->count());
    }

    public function test_wallet_minimum_is_checked_before_acceptance_but_commission_can_create_debt(): void
    {
        $id = $this->order(['commission_cents' => 6000]);
        DB::table('users')->where('id', 20)->update(['balance' => '49.99']);
        $this->assertBlocked(fn () => $this->act($id, 'accept'), 409);
        $this->assertSame(49.99, $this->balance(20));
        DB::table('users')->where('id', 20)->update(['balance' => '50.00']);
        $this->act($id, 'accept');
        $this->assertSame(-10.0, $this->balance(20));
        $this->assertSame('60.00', $this->appBalance());
        $this->assertLedger($id, 'fee', 20, null, 60.0);
    }

    public function test_missing_app_wallet_and_failed_ledger_insert_roll_back_all_acceptance_writes(): void
    {
        $id = $this->order();
        DB::table('settings')->delete();
        $this->assertBlocked(fn () => $this->act($id, 'accept'), 503);
        $this->assertSame(100.0, $this->balance(20));
        $this->assertSame('pending', DB::table('go_store_orders')->find($id)->status);
        DB::table('settings')->insert(['group' => 'general', 'name' => 'app_balance', 'payload' => json_encode('0.00')]);
        DB::statement("CREATE TRIGGER fail_store_ledger BEFORE INSERT ON wallets BEGIN SELECT RAISE(ABORT, 'ledger fixture failure'); END");
        try {
            $this->act($id, 'accept');
            $this->fail('Expected ledger insert failure.');
        } catch (QueryException $error) {
            $this->assertSame(100.0, $this->balance(20));
            $this->assertSame('0.00', $this->appBalance());
            $this->assertSame('pending', DB::table('go_store_orders')->find($id)->status);
            $this->assertSame(1, (int) DB::table('go_store_orders')->find($id)->revision);
            $this->assertSame(0, DB::table('wallets')->count());
        }
    }
}
