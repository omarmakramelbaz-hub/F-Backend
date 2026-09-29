<?php

namespace App\Services\GoStores;

use App\Services\GoPayments\Gateway;
use App\Services\GoServices\PaymobHmac;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Payments
{
    public function checkout(int $id, int $customer): array
    {
        $config = Gateway::settings();
        $orders = new Orders();
        $o = DB::transaction(function () use ($id, $customer, $config, $orders) {
            $o = $orders->visible($id, $customer, false, true);
            $u = $orders->actor($customer, 'go');
            abort_unless($o->status === 'awaiting_payment' && in_array($o->payment_method, Gateway::methods(), true), 409, 'الدفع غير متاح لهذا الطلب.');
            if ($o->payment_status === 'pending') {
                abort_if(Carbon::parse($o->expires_at)->lte(now()), 409, 'انتهت مهلة الدفع. ألغِ الطلب أو تواصل مع الدعم.');
                return $o;
            }
            abort_unless($o->payment_status === 'ready', 409, 'جارٍ التحقق من الدفع. راجع حالة الطلب قبل إعادة الدفع.');
            Gateway::billing($u);
            DB::table('go_store_orders')->where('id', $id)->update(['payment_status' => 'creating',
                'integration_id' => (int)$config['methods'][$o->payment_method], 'is_live' => (bool)$config['is_live'],
                'expires_at' => now()->addMinutes(30), 'updated_at' => now()]);
            return DB::table('go_store_orders')->find($id);
        }, 3);
        if ($o->payment_status === 'creating') {
            try {
                $response = (new Gateway())->create(['amount' => (int)$o->total_cents, 'currency' => 'EGP',
                    'payment_methods' => [(int)$o->integration_id], 'special_reference' => 'gs-'.$o->payment_reference,
                    'expiration' => 1800, 'billing_data' => Gateway::billing(DB::table('users')->find($customer)),
                    'notification_url' => route('go-stores.paymob-webhook'), 'redirection_url' => route('go-stores.payment-return'),
                    'items' => [['name' => 'GO store order GS-'.$id, 'amount' => (int)$o->total_cents, 'quantity' => 1]]], $config);
                DB::transaction(function () use ($id, $response) {
                    $current = DB::table('go_store_orders')->where('id', $id)->lockForUpdate()->first();
                    DB::table('go_store_orders')->where('id', $id)->update([
                        'gateway_order_id' => (string)$response['intention_order_id'],
                        'checkout_secret' => Crypt::encryptString($response['client_secret']),
                        'payment_status' => $current->payment_status === 'creating' ? 'pending' : $current->payment_status,
                        'updated_at' => now(),
                    ]);
                }, 3);
            } catch (\Throwable $error) {
                // A timeout has an unknown external outcome. Never create a second payable intention.
                abort(502, 'تعذر تأكيد تجهيز الدفع. راجع الطلب أو تواصل مع الدعم قبل إعادة الدفع.');
            }
            $o = DB::table('go_store_orders')->find($id);
        }
        abort_unless($o->status === 'awaiting_payment' && $o->payment_status === 'pending', 409, 'تم إغلاق الدفع.');
        return ['order_id' => $id, 'link' => 'https://accept.paymob.com/unifiedcheckout/?'.http_build_query([
            'publicKey' => $config['public_key'], 'clientSecret' => Crypt::decryptString($o->checkout_secret),
        ]), 'expires_at' => Carbon::parse($o->expires_at)->toIso8601String()];
    }

    public function callback(array $object, string $signature): void
    {
        $gateway = new Gateway();
        $config = Gateway::settings();
        $verified = $gateway->verify($object, $signature, $config);
        $inquired = empty($config['hmac_secret']);
        // The cumulative refund amount is not covered by the callback HMAC.
        if (!$inquired && PaymobHmac::truth($verified['is_refunded'] ?? false) && !empty($config['api_key'])) {
            $verified = $gateway->inquire((string)$verified['id'], $config);
            $inquired = true;
        }
        $this->settleVerified($verified, $inquired);
    }

    /** Input must come exclusively from signed webhook verification or merchant inquiry. */
    public function settleVerified(array $object, bool $merchantInquiry = false): void
    {
        $o = DB::table('go_store_orders')->where('gateway_order_id', (string)($object['order']['id'] ?? ''))->first();
        abort_unless($o, 404, 'Payment not found');
        abort_unless((string)($object['integration_id'] ?? '') === (string)$o->integration_id
            && ($object['currency'] ?? '') === 'EGP' && (string)($object['amount_cents'] ?? '') === (string)$o->total_cents
            && array_key_exists('is_live', $object) && PaymobHmac::truth($object['is_live']) === (bool)$o->is_live, 422, 'Payment attributes mismatch');
        $transaction = (string)($object['id'] ?? '');
        abort_unless(preg_match('/^\d{1,30}$/D', $transaction), 422, 'Invalid transaction');
        $reversed = PaymobHmac::truth($object['is_refunded'] ?? false) || PaymobHmac::truth($object['is_voided'] ?? false);
        $fullReversal = PaymobHmac::truth($object['is_voided'] ?? false)
            || ($merchantInquiry && (string)($object['refunded_amount_cents'] ?? '') === (string)$o->total_cents);
        if (!$reversed && !Gateway::successful($object)) return;
        $created = DB::transaction(function () use ($o, $transaction, $reversed, $fullReversal) {
            $o = DB::table('go_store_orders')->where('id', $o->id)->lockForUpdate()->first();
            $receipt = DB::table('go_store_payment_receipts')->where('transaction_id', $transaction)->first();
            abort_if($receipt && (int)$receipt->order_id !== (int)$o->id, 409, 'Transaction already bound');
            $orders = new Orders();
            if ($reversed) {
                if (!$fullReversal) {
                    // Partial/unknown refunds require reconciliation. Keep credited receipts
                    // intact so a later verified full refund can reverse the gross exactly once.
                    if (!$receipt) DB::table('go_store_payment_receipts')->insert([
                        'order_id' => $o->id, 'transaction_id' => $transaction, 'amount_cents' => $o->total_cents,
                        'status' => 'refund_due', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!$receipt || $receipt->status !== 'refunded') DB::table('go_store_orders')->where('id', $o->id)->update([
                        'payment_status' => in_array($o->status, ['cancelled', 'rejected'], true) ? 'refund_pending' : 'review',
                        'revision' => $o->revision + 1, 'updated_at' => now(),
                    ]);
                    return false;
                }
                if (!$receipt) {
                    // Refund/void callbacks may arrive before the capture callback.
                    // Record the transaction so a delayed success cannot credit it.
                    DB::table('go_store_payment_receipts')->insert(['order_id' => $o->id, 'transaction_id' => $transaction,
                        'amount_cents' => $o->total_cents, 'status' => 'refunded', 'created_at' => now(), 'updated_at' => now()]);
                    if (in_array($o->payment_status, ['pending', 'cancelled', 'refund_pending'], true)) {
                        $outstanding = DB::table('go_store_payment_receipts')->where('order_id', $o->id)->where('status', '!=', 'refunded')->exists();
                        DB::table('go_store_orders')->where('id', $o->id)->update([
                            'payment_status' => in_array($o->status, ['cancelled', 'rejected'], true) ? ($outstanding ? 'refund_pending' : 'refunded') : 'review',
                            'revision' => $o->revision + 1, 'updated_at' => now(),
                        ]);
                    }
                    return false;
                }
                if ($receipt && $receipt->status === 'credited') {
                    $orders->lockUsers([$o->store_id]);
                    $orders->move($o, 'reversal', (int)$o->store_id, null, (int)$o->total_cents, true);
                    DB::table('go_store_orders')->where('id', $o->id)->update([
                        'payment_status' => in_array($o->status, ['cancelled', 'rejected'], true) ? 'refunded' : 'review',
                        'revision' => $o->revision + 1, 'updated_at' => now(),
                    ]);
                }
                if ($receipt) DB::table('go_store_payment_receipts')->where('id', $receipt->id)->update(['status' => 'refunded', 'updated_at' => now()]);
                if (in_array($o->status, ['cancelled', 'rejected'], true)) {
                    $outstanding = DB::table('go_store_payment_receipts')->where('order_id', $o->id)->where('status', '!=', 'refunded')->exists();
                    DB::table('go_store_orders')->where('id', $o->id)->update(['payment_status' => $outstanding ? 'refund_pending' : 'refunded', 'updated_at' => now()]);
                }
                return false;
            }
            if ($receipt) return false;
            $valid = $o->status === 'awaiting_payment' && $o->payment_status === 'pending';
            DB::table('go_store_payment_receipts')->insert(['order_id' => $o->id, 'transaction_id' => $transaction,
                'amount_cents' => $o->total_cents, 'status' => $valid ? 'credited' : 'refund_due', 'created_at' => now(), 'updated_at' => now()]);
            if (!$valid) {
                if (in_array($o->status, ['cancelled', 'rejected'], true) && $o->payment_status === 'cancelled') {
                    DB::table('go_store_orders')->where('id', $o->id)->update(['payment_status' => 'refund_pending', 'updated_at' => now()]);
                }
                return false;
            }
            $orders->lockUsers([$o->store_id]);
            $orders->move($o, 'gross', null, (int)$o->store_id, (int)$o->total_cents);
            DB::table('go_store_orders')->where('id', $o->id)->update(['status' => 'pending', 'payment_status' => 'paid',
                'revision' => $o->revision + 1, 'updated_at' => now()]);
            return true;
        }, 3);
        if ($created) Notices::send((int)$o->id, true);
    }
}
