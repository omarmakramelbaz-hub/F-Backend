<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\GoServices\PaymobHmac;
use App\Services\Payments\LegacyPaymentFailure;
use App\Services\Payments\RestaurantOrderPayment;
use App\Services\Payments\RestaurantPaymentEffects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Callback identifiers locate an attempt; authenticated Paymob inquiry proves payment. */
class RestaurantPaymentController extends Controller
{
    public function notify(Request $request)
    {
        if (strlen($request->getContent()) > 262144) return response()->json(['accepted' => false], 413);
        if ($request->input('type') !== 'TRANSACTION') return response()->json(['ignored' => true]);
        $object = $request->input('obj');
        if (!is_array($object)) return response()->json(['accepted' => false], 400);
        $secret = config('dashboard_payments.hmac_secret');
        if (!is_string($secret) || trim($secret) === '') {
            return response()->json(['accepted' => false, 'retryable' => true], 503);
        }
        $signature = $request->query('hmac', $request->input('hmac'));
        try { $valid = is_string($signature) && PaymobHmac::valid($object, $signature, $secret); }
        catch (\Throwable $error) { $valid = false; }
        if (!$valid) {
            return response()->json(['accepted' => false], 403);
        }
        // A signed pending, failed or reversal event must not start an activation/retry loop.
        // A success event is still independently checked against Paymob before any write.
        if (!PaymobHmac::truth($object['success'] ?? null)) return response()->json(['accepted' => true, 'state' => 'unpaid']);
        foreach (['pending', 'is_auth', 'is_capture', 'is_voided', 'is_refunded', 'has_parent_transaction', 'error_occured'] as $flag) {
            if (PaymobHmac::truth($object[$flag] ?? null)) return response()->json(['accepted' => true, 'state' => 'ignored']);
        }
        try {
            $result = $this->confirm($object['order']['id'] ?? null, $object['id'] ?? null);
            return response()->json(['accepted' => true, 'state' => $result['state']]);
        } catch (\Throwable $error) {
            // Never echo or log callback billing data, credentials or upstream response bodies.
            return response()->json(['accepted' => false, 'retryable' => true], 503);
        }
    }

    public function returnFromGateway(Request $request)
    {
        try {
            $result = $this->confirm($request->query('order'), $request->query('id'));
            return redirect()->route(in_array($result['state'], ['activated', 'unchanged'], true) ? 'paySuccess' : 'payFailed');
        } catch (\Throwable $error) {
            if (in_array($request->query('success'), [false, 0, 'false', '0'], true)) {
                try { app(LegacyPaymentFailure::class)->record($request); }
                catch (\Throwable $failure) {
                    // Failure display evidence is best effort and requires its own valid HMAC.
                }
            }
            return redirect()->route('payFailed');
        }
    }

    /** Also shared by the retained restaurant return URL. No caller-supplied success flag is trusted. */
    public function confirm($gatewayOrder, $transaction): array
    {
        foreach ([$gatewayOrder, $transaction] as $value) {
            if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string) $value)) {
                throw new \RuntimeException('ORDER_PAYMENT_INVALID_CALLBACK');
            }
        }
        $attempts = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.intention_order_id', (string) $gatewayOrder)->where('orders.type', 'current')
            ->whereIn('orders.payment_type', ['online', 'v_cash'])->select('payments.id')->limit(2)->get();
        if ($attempts->count() !== 1) throw new \RuntimeException('ORDER_PAYMENT_ATTEMPT_NOT_UNIQUE');
        $result = app(RestaurantOrderPayment::class)->settle((int) $attempts->first()->id, (string) $transaction);
        if (!in_array($result['state'] ?? null, ['activated', 'unchanged', 'closed'], true)) {
            throw new \RuntimeException('ORDER_PAYMENT_RESULT_INVALID');
        }
        if ($result['state'] === 'activated') {
            try {
                app(RestaurantPaymentEffects::class)->afterActivation($result);
            } catch (\Throwable $error) {
                // Payment activation already committed. Provider availability cannot undo it.
            }
        }
        return $result;
    }
}
