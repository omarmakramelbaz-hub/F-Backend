<?php

namespace App\Services\Payments;

use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;

/** Authoritative Paymob receipt and restaurant-order activation; never transfers money. */
class RestaurantOrderPayment
{
    private $gateway;

    public function __construct(?PaymobTransactionInquiry $gateway = null)
    {
        $this->gateway = $gateway ?? new PaymobTransactionInquiry();
    }

    public function settle(int $paymentId, ?string $transactionId = null): array
    {
        if (config('desktop_dashboard.local', false)) $this->fail('CENTRAL_SERVER_REQUIRED');
        if (DB::transactionLevel() !== 0) $this->fail('TRANSACTION_NOT_ALLOWED');
        if ($paymentId < 1 || ($transactionId !== null && !$this->identifier($transactionId))) $this->fail('INVALID_IDENTIFIER');
        $key = config('wallet_topups.api_key');
        if (!is_string($key) || trim($key) === '' || strlen($key) > 65536) $this->fail('GATEWAY_CONFIG_REQUIRED');
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $order = $payment ? DB::table('orders')->where('id', $payment->order_id)->first() : null;
        $facts = $this->facts($payment, $order);
        $this->uniqueGatewayOrder($facts['gateway_order_id'], $paymentId);
        // Historical success may precede a later failed retry on the same intention.
        // Recheck its exact stored transaction instead of assuming the latest one paid.
        if ($transactionId === null && $this->succeeded($payment->status ?? null)
            && $this->identifier($payment->transaction_id ?? null)) $transactionId = (string) $payment->transaction_id;
        // The external request must never hold an order, payment or account lock.
        $transaction = $this->gateway->transactionForOrder($facts['gateway_order_id'], $transactionId);
        $verifiedId = $this->verify($transaction, $facts, $transactionId);

        return DB::transaction(function () use ($paymentId, $facts, $transaction, $verifiedId) {
            // Same ordering as cancellation and the retained checkout path.
            $order = DB::table('orders')->where('id', $facts['order_id'])->lockForUpdate()->first();
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            $locked = $this->facts($payment, $order);
            if ($locked !== $facts) $this->fail('ATTEMPT_CHANGED');
            $this->uniqueGatewayOrder($facts['gateway_order_id'], $paymentId);
            $this->verify($transaction, $locked, $verifiedId);
            if (DB::table('payments')->where('transaction_id', $verifiedId)->where('id', '<>', $paymentId)->exists()) {
                $this->fail('TRANSACTION_ALREADY_USED');
            }
            $result = $locked + ['transaction_id' => $verifiedId];
            // A gateway callback must never revive a closed/refunded order or reset delivery.
            if (($order->transfer_price_by ?? null) !== null
                || in_array($order->status ?? '', ['cancelled', 'canceled', 'declined', 'rejected', 'expired'], true)) {
                return $result + ['state' => 'closed'];
            }
            $attempts = DB::table('payments')->where('order_id', $facts['order_id'])->orderBy('id')->lockForUpdate()->get();
            foreach ($attempts as $attempt) {
                if ((int) $attempt->id !== $paymentId && $this->succeeded($attempt->status ?? null)) {
                    return $result + ['state' => 'closed'];
                }
            }
            $succeeded = $this->succeeded($payment->status ?? null);
            $storedId = (string) ($payment->transaction_id ?? '');
            if ($succeeded && $storedId !== $verifiedId) return $result + ['state' => 'closed'];
            if ($order->status !== null) {
                // Only exact receipts can be replayed after staff have acted.
                return $result + ['state' => $succeeded && in_array($order->status,
                    ['pending', 'new_order', 'another_delegate', 'accepted', 'shipped', 'completed'], true) ? 'unchanged' : 'closed'];
            }
            if (($order->accepted_notify ?? '') === 'yes' || !empty($order->delegate_id)
                || !empty($order->delegate_from_out)) return $result + ['state' => 'closed'];
            if (!$succeeded && !in_array(strtolower(trim((string) ($payment->status ?? ''))),
                ['', 'pending', 'creating', '0', 'false', 'failed'], true)) return $result + ['state' => 'closed'];
            $when = now();
            if (!$succeeded) DB::table('payments')->where('id', $paymentId)->update([
                'status' => '1', 'transaction_id' => $verifiedId, 'updated_at' => $when,
            ]);
            DB::table('orders')->where('id', $facts['order_id'])->update(['status' => 'pending', 'updated_at' => $when]);
            return $result + ['state' => 'activated'];
        }, 3);
    }

    private function facts($payment, $order): array
    {
        if (!$payment || !$order || ($order->type ?? '') !== 'current'
            || !in_array($order->payment_type ?? '', ['online', 'v_cash'], true)) $this->fail('INVALID_RESTAURANT_ATTEMPT');
        foreach ([$payment->id, $payment->order_id, $payment->user_id, $order->id, $order->user_id,
            $order->resturant_id ?? null, $payment->intention_order_id ?? null] as $id) {
            if (!$this->identifier($id)) $this->fail('INVALID_IDENTIFIER');
        }
        if ((string) $payment->order_id !== (string) $order->id || (string) $payment->user_id !== (string) $order->user_id) {
            $this->fail('OWNERSHIP_MISMATCH');
        }
        if (!DB::table('users')->where('id', $order->user_id)->exists()
            || !DB::table('resturants')->where('id', $order->resturant_id)->exists()) $this->fail('PARTY_NOT_FOUND');
        $integration = config('dashboard_payments.integrations.' . $order->payment_type);
        if (!$this->identifier($integration)) $this->fail('INTEGRATION_REQUIRED');
        $value = $payment->total_price ?? null;
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) $this->fail('INVALID_AMOUNT');
        try { $amount = Money::minor($value); }
        catch (\InvalidArgumentException $error) { $this->fail('INVALID_AMOUNT'); }
        if ($amount <= 0 || $amount > 100000000) $this->fail('INVALID_AMOUNT');
        return ['payment_id' => (int) $payment->id, 'order_id' => (int) $order->id, 'user_id' => (int) $order->user_id,
            'resturant_id' => (int) $order->resturant_id, 'payment_method' => $order->payment_type,
            'gateway_order_id' => (string) $payment->intention_order_id, 'amount_cents' => $amount,
            'integration_id' => (string) $integration];
    }

    private function verify(array $transaction, array $facts, ?string $requestedId): string
    {
        $id = $transaction['id'] ?? null;
        if (!$this->identifier($id) || ($requestedId !== null && (string) $id !== $requestedId)) $this->fail('TRANSACTION_MISMATCH');
        if (!is_array($transaction['order'] ?? null)
            || (string) ($transaction['order']['id'] ?? '') !== $facts['gateway_order_id']
            || ($transaction['currency'] ?? '') !== 'EGP'
            || (string) ($transaction['integration_id'] ?? '') !== $facts['integration_id']
            || !$this->identifier($transaction['amount_cents'] ?? null)
            || (string) $transaction['amount_cents'] !== (string) $facts['amount_cents']) $this->fail('GATEWAY_ATTRIBUTES_MISMATCH');
        if (config('wallet_topups.live') !== true || !$this->truth($transaction['is_live'] ?? null)
            || !$this->truth($transaction['success'] ?? null)
            || !$this->truth($transaction['is_standalone_payment'] ?? null)) $this->fail('PAYMENT_NOT_SETTLED');
        foreach (['pending', 'is_auth', 'is_capture', 'is_voided', 'is_refunded', 'has_parent_transaction', 'error_occured'] as $flag) {
            if (!$this->isFalse($transaction[$flag] ?? null)) $this->fail('PAYMENT_NOT_SETTLED');
        }
        foreach (['is_void', 'is_refund'] as $flag) {
            if (array_key_exists($flag, $transaction) && !$this->isFalse($transaction[$flag])) $this->fail('PAYMENT_NOT_SETTLED');
        }
        if (isset($transaction['refunded_amount_cents']) && !in_array($transaction['refunded_amount_cents'], [0, '0'], true)) $this->fail('PAYMENT_NOT_SETTLED');
        return (string) $id;
    }

    private function uniqueGatewayOrder(string $gatewayOrder, int $paymentId): void
    {
        $matches = DB::table('payments')->where('intention_order_id', $gatewayOrder)->limit(2)->get(['id']);
        if ($matches->count() !== 1 || (int) $matches[0]->id !== $paymentId) $this->fail('AMBIGUOUS_GATEWAY_ORDER');
    }

    private function identifier($value): bool
    {
        return (is_int($value) || is_string($value)) && (bool) preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $value);
    }

    private function succeeded($value): bool { return in_array(strtolower(trim((string) $value)), ['1', 'true', 'paid', 'succeeded', 'success'], true); }
    private function truth($value): bool { return in_array($value, [true, 1, 'true', '1'], true); }
    private function isFalse($value): bool { return in_array($value, [false, 0, 'false', '0'], true); }
    private function fail(string $code): void { throw new \RuntimeException('RESTAURANT_PAYMENT_' . $code); }
}
