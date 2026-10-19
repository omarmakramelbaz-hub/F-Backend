<?php

namespace App\Services\Payments;

use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;

/** Settles one independently verified Paymob wallet top-up, exactly once. */
class WalletTopupSettlement
{
    private $gateway;

    public function __construct(?PaymobTransactionInquiry $gateway = null)
    {
        $this->gateway = $gateway ?? new PaymobTransactionInquiry();
    }

    public function settle(int $paymentId, ?string $transactionId = null, array $expected = []): array
    {
        // Effects must follow the actual commit, never an enclosing transaction.
        if (DB::transactionLevel() !== 0) $this->fail('TRANSACTION_NOT_ALLOWED');
        if ($paymentId < 1) $this->fail('INVALID_IDENTIFIER');
        if ($transactionId !== null) $this->identifier($transactionId);
        $this->expectations($expected);
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (!$payment) $this->fail('PAYMENT_NOT_FOUND');
        $order = DB::table('orders')->where('id', $payment->order_id)->first();
        $wallet = $order ? DB::table('wallets')->where('id', $order->wallet_id ?? 0)->first() : null;
        $facts = $this->facts($payment, $order, $wallet);
        $this->matchExpectations($facts, $expected);
        $this->uniqueGatewayOrder($facts['gateway_order_id'], $paymentId);
        // The network is outside the transaction: slow Paymob cannot hold account locks.
        $transaction = $this->gateway->transactionForOrder($facts['gateway_order_id'], $transactionId);
        $verifiedId = $this->verify($transaction, $facts, $transactionId);

        $result = DB::transaction(function () use ($paymentId, $facts, $transaction, $verifiedId, $expected) {
            // Consistent lock order for callback, CLI recovery and concurrent retries.
            $order = DB::table('orders')->where('id', $facts['order_id'])->lockForUpdate()->first();
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            $wallet = $order ? DB::table('wallets')->where('id', $order->wallet_id ?? 0)->lockForUpdate()->first() : null;
            $lockedFacts = $this->facts($payment, $order, $wallet);
            if ($lockedFacts !== $facts) $this->fail('ATTEMPT_CHANGED');
            $this->matchExpectations($lockedFacts, $expected);
            $this->uniqueGatewayOrder($lockedFacts['gateway_order_id'], $paymentId);
            $this->verify($transaction, $lockedFacts, $verifiedId);
            $user = DB::table('users')->where('id', $lockedFacts['user_id'])->lockForUpdate()->first();
            if (!$user) $this->fail('USER_NOT_FOUND');
            if (DB::table('payments')->where('transaction_id', $verifiedId)->where('id', '<>', $paymentId)->exists()) {
                $this->fail('TRANSACTION_ALREADY_USED');
            }
            $balanceBefore = $this->money($user->balance);
            $storedTransaction = $payment->transaction_id === null ? '' : (string) $payment->transaction_id;
            $status = strtolower(trim((string) $payment->status));
            $succeeded = in_array($status, ['1', 'true', 'paid', 'succeeded', 'success'], true);
            if ($wallet->status === 'completed') {
                if (!$succeeded || $storedTransaction !== $verifiedId || $order->status !== 'pending') {
                    $this->fail('HISTORICAL_STATE_INCONSISTENT');
                }
                return $this->result($lockedFacts, $verifiedId, $balanceBefore, $balanceBefore, 'unchanged');
            }
            if ($wallet->status !== 'pending' || $order->status !== null
                || !in_array($status, ['', 'pending'], true) || $storedTransaction !== '') {
                $this->fail('HISTORICAL_STATE_INCONSISTENT');
            }
            $balanceAfter = $balanceBefore + $lockedFacts['amount_cents'];
            // Money's parser deliberately bounds amounts; reject unsupported balances.
            $this->money(Money::decimal($balanceAfter));
            $amount = Money::decimal($lockedFacts['amount_cents']);
            // DECIMAL arithmetic in SQL avoids floating-point increments in PHP/SQL.
            DB::update('UPDATE ' . $this->quotedTable('users') . ' SET `balance` = '
                . 'CAST(`balance` AS DECIMAL(20,2)) + CAST(? AS DECIMAL(20,2)), `updated_at` = ? WHERE `id` = ?',
                [$amount, now(), $lockedFacts['user_id']]);
            $actualBalance = $this->money(DB::table('users')->where('id', $lockedFacts['user_id'])->value('balance'));
            if ($actualBalance !== $balanceAfter) $this->fail('BALANCE_WRITE_MISMATCH');
            $timestamp = now();
            DB::table('payments')->where('id', $paymentId)->update([
                'status' => '1', 'transaction_id' => $verifiedId, 'updated_at' => $timestamp,
            ]);
            DB::table('wallets')->where('id', $lockedFacts['wallet_id'])->update([
                'status' => 'completed', 'updated_at' => $timestamp,
            ]);
            DB::table('orders')->where('id', $lockedFacts['order_id'])->update([
                'status' => 'pending', 'updated_at' => $timestamp,
            ]);
            return $this->result($lockedFacts, $verifiedId, $balanceBefore, $balanceAfter, 'credited');
        }, 3);
        if ($result['state'] === 'credited') {
            try {
                (new WalletTopupEffects())->afterCredit((int) $result['user_id']);
            } catch (\Throwable $error) {
                // A notification failure must never undo or replay a committed credit.
            }
        }
        return $result;
    }

    private function facts($payment, $order, $wallet): array
    {
        if (!$payment || !$order || !$wallet || $order->type !== 'wallet' || $wallet->type !== 'charging') {
            $this->fail('INVALID_WALLET_ATTEMPT');
        }
        $gatewayOrder = (string) ($payment->intention_order_id ?? '');
        $this->identifier($gatewayOrder);
        $userId = (int) $order->user_id;
        if ($userId < 1 || (int) $payment->user_id !== $userId || (int) $wallet->from_user !== $userId
            || (int) $wallet->to_user !== $userId || (int) $payment->order_id !== (int) $order->id) {
            $this->fail('OWNERSHIP_MISMATCH');
        }
        $integration = config('wallet_topups.integrations.' . (string) $wallet->payment);
        if (!in_array($wallet->payment, ['visa', 'v_cash'], true) || !$this->validIdentifier($integration)) {
            $this->fail('INTEGRATION_REQUIRED');
        }
        $amount = $this->money($payment->total_price ?? null);
        if ($amount <= 0 || $amount !== $this->money($wallet->amount)) $this->fail('AMOUNT_MISMATCH');
        return ['payment_id' => (int) $payment->id, 'order_id' => (int) $order->id,
            'wallet_id' => (int) $wallet->id, 'user_id' => $userId,
            'gateway_order_id' => $gatewayOrder, 'amount_cents' => $amount,
            'integration_id' => (string) $integration];
    }

    private function verify(array $transaction, array $facts, ?string $requestedId): string
    {
        $id = $transaction['id'] ?? null;
        if (!$this->validIdentifier($id) || ($requestedId !== null && (string) $id !== $requestedId)) {
            $this->fail('TRANSACTION_MISMATCH');
        }
        if (!is_array($transaction['order'] ?? null)
            || (string) ($transaction['order']['id'] ?? '') !== $facts['gateway_order_id']
            || (string) ($transaction['currency'] ?? '') !== 'EGP'
            || (string) ($transaction['integration_id'] ?? '') !== $facts['integration_id']
            || !$this->validIdentifier($transaction['amount_cents'] ?? null)
            || (string) $transaction['amount_cents'] !== (string) $facts['amount_cents']) {
            $this->fail('GATEWAY_ATTRIBUTES_MISMATCH');
        }
        if (config('wallet_topups.live') !== true || !$this->truth($transaction['is_live'] ?? null)
            || !$this->truth($transaction['success'] ?? null)
            || !$this->truth($transaction['is_standalone_payment'] ?? null)) $this->fail('PAYMENT_NOT_SETTLED');
        foreach (['pending', 'is_auth', 'is_capture', 'is_voided', 'is_refunded',
            'has_parent_transaction', 'error_occured'] as $flag) {
            if (!$this->isFalse($transaction[$flag] ?? null)) $this->fail('PAYMENT_NOT_SETTLED');
        }
        foreach (['is_void', 'is_refund'] as $flag) {
            if (array_key_exists($flag, $transaction) && !$this->isFalse($transaction[$flag])) {
                $this->fail('PAYMENT_NOT_SETTLED');
            }
        }
        if (isset($transaction['refunded_amount_cents'])
            && !in_array($transaction['refunded_amount_cents'], [0, '0'], true)) $this->fail('PAYMENT_NOT_SETTLED');
        return (string) $id;
    }

    private function uniqueGatewayOrder(string $gatewayOrder, int $paymentId): void
    {
        $matches = DB::table('payments')->where('intention_order_id', $gatewayOrder)->limit(2)->get(['id']);
        if ($matches->count() !== 1 || (int) $matches[0]->id !== $paymentId) $this->fail('AMBIGUOUS_GATEWAY_ORDER');
    }

    private function expectations(array $expected): void
    {
        $keys = ['payment_id', 'order_id', 'wallet_id', 'user_id', 'gateway_order_id', 'amount_cents', 'integration_id'];
        if (array_diff(array_keys($expected), $keys)) $this->fail('INVALID_EXPECTATIONS');
        foreach ($expected as $value) if (!$this->validIdentifier($value)) $this->fail('INVALID_EXPECTATIONS');
    }

    private function matchExpectations(array $facts, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if ((string) $facts[$key] !== (string) $value) $this->fail('EXPECTED_ATTEMPT_MISMATCH');
        }
    }

    private function money($value): int
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) $this->fail('INVALID_AMOUNT');
        try { return Money::minor($value); }
        catch (\InvalidArgumentException $error) { $this->fail('INVALID_AMOUNT'); }
    }

    private function quotedTable(string $name): string
    {
        $table = DB::connection()->getTablePrefix() . $name;
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table)) $this->fail('INVALID_TABLE_PREFIX');
        return '`' . $table . '`';
    }

    private function result(array $facts, string $transactionId, int $before, int $after, string $state): array
    {
        return $facts + ['state' => $state, 'transaction_id' => $transactionId,
            'balance_before' => Money::decimal($before), 'balance_after' => Money::decimal($after)];
    }

    private function validIdentifier($value): bool
    {
        return (is_int($value) || is_string($value)) && (bool) preg_match('/\A[1-9][0-9]{0,29}\z/', (string) $value);
    }

    private function identifier($value): void
    {
        if (!$this->validIdentifier($value)) $this->fail('INVALID_IDENTIFIER');
    }

    private function truth($value): bool { return in_array($value, [true, 1, 'true', '1'], true); }
    private function isFalse($value): bool { return in_array($value, [false, 0, 'false', '0'], true); }
    private function fail(string $code): void { throw new \RuntimeException('WALLET_TOPUP_' . $code); }
}
