<?php

namespace App\Services\Payments;

use App\Services\GoServices\Money;
use App\Services\GoServices\PaymobHmac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Records signed failure evidence for display, without settling an order. */
final class LegacyPaymentFailure
{
    private const FIELDS = ['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction',
        'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'owner', 'pending', 'success'];

    public function record(Request $request): bool
    {
        if (!$request->isMethod('get')) return false;
        $hmac = $request->query('hmac');
        if (!is_string($hmac)) return false;
        $object = $this->object($request->query());
        if (!$object || !PaymobHmac::valid($object, $hmac,
            (string) config('dashboard_payments.hmac_secret'))) return false;
        if (!$this->isFalse($object['success']) || !$this->isFalse($object['pending'])) return false;
        foreach (['is_auth', 'is_voided', 'is_refunded', 'has_parent_transaction'] as $flag) {
            if (!$this->isFalse($object[$flag])) return false;
        }
        if ($object['currency'] !== 'EGP' || !$this->identifier($object['id'])
            || !$this->identifier($object['order']['id']) || !$this->identifier($object['integration_id'])
            || !$this->identifier($object['amount_cents'])) return false;
        if (!Schema::hasTable('payments') || !Schema::hasTable('orders')) return false;
        $columns = Schema::getColumnListing('payments');
        if (array_diff(['id', 'order_id', 'user_id', 'status', 'intention_order_id', 'transaction_id', 'total_price'], $columns)) return false;

        // Only the order identifier covered by the HMAC binds an attempt.
        // Unsigned merchant_order_id / intention_order_id query values are ignored.
        $matches = DB::table('payments')->where('intention_order_id', (string) $object['order']['id'])->limit(2)->get();
        if ($matches->count() !== 1) return false;
        $paymentId = (int) $matches[0]->id;
        $orderId = (int) $matches[0]->order_id;
        return DB::transaction(function () use ($object, $paymentId, $orderId, $columns) {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (!$order || $order->status !== null || !in_array($order->type ?? '', ['current', 'schedule', 'shipping'], true)) return false;
            $integration = config('dashboard_payments.integrations.'.($order->payment_type ?? ''));
            if (!$this->identifier($integration) || (string) $integration !== (string) $object['integration_id']) return false;
            $attempts = DB::table('payments')->where('order_id', $orderId)->orderByDesc('id')->lockForUpdate()->get();
            $payment = $attempts->firstWhere('id', $paymentId);
            if (!$payment || (int) $payment->user_id !== (int) $order->user_id
                || (string) $payment->intention_order_id !== (string) $object['order']['id']) return false;
            foreach ($attempts as $attempt) {
                if ($this->succeeded($attempt->status)) return false;
            }
            try {
                $amount = Money::minor($payment->total_price);
            } catch (\InvalidArgumentException $exception) {
                return false;
            }
            if ($amount <= 0 || (string) $amount !== (string) $object['amount_cents']) return false;
            // A newer retry has replaced this checkout. Do not make its pending
            // attempt look failed when an older gateway redirect arrives late.
            if ((int) $attempts->first()->id !== $paymentId) return false;
            if ((string) $payment->status === '0' && (string) $payment->transaction_id === (string) $object['id']) return true;
            $changes = ['status' => '0', 'transaction_id' => (string) $object['id']];
            if (in_array('updated_at', $columns, true)) $changes['updated_at'] = now();
            // Protect against the established success callback winning a race.
            return DB::table('payments')->where('id', $paymentId)->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', ['1', 'true', 'paid', 'succeeded', 'success']);
            })->update($changes) === 1;
        });
    }

    private function object(array $query): ?array
    {
        $object = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $query) || !is_scalar($query[$field])) return null;
            $object[$field] = $query[$field];
        }
        if (!array_key_exists('order', $query) || !is_scalar($query['order'])) return null;
        $object['order'] = ['id' => $query['order']];
        foreach (['pan', 'sub_type', 'type'] as $field) {
            // PHP converts dots in redirect query names to underscores.
            $dotted = 'source_data.'.$field;
            $key = array_key_exists($dotted, $query) ? $dotted : 'source_data_'.$field;
            if (!array_key_exists($key, $query) || (!is_scalar($query[$key]) && $query[$key] !== null)) return null;
            $object['source_data'][$field] = $query[$key];
        }
        return $object;
    }

    private function identifier($value): bool
    {
        return (is_string($value) || is_int($value)) && (bool) preg_match('/^[1-9][0-9]*$/D', (string) $value);
    }

    private function isFalse($value): bool
    {
        return in_array($value, [false, 0, 'false', '0'], true);
    }

    private function succeeded($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'paid', 'succeeded', 'success'], true);
    }
}
