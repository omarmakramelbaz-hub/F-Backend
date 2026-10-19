<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payments\WalletTopupSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Callback data locates an attempt; authenticated gateway inquiry proves payment. */
class WalletTopupController extends Controller
{
    public function notify(Request $request)
    {
        if (strlen($request->getContent()) > 262144) return response()->json(['accepted' => false], 413);
        if ($request->input('type') !== 'TRANSACTION') return response()->json(['ignored' => true]);
        $object = $request->input('obj');
        if (!is_array($object)) return response()->json(['accepted' => false], 400);
        try {
            $result = $this->settle($object['order']['id'] ?? null, $object['id'] ?? null);
            return response()->json(['accepted' => true, 'state' => $result['state']]);
        } catch (\Throwable $error) {
            // A temporary inquiry failure must remain retryable. Never log tokens
            // or echo the callback, billing data, or an exception response body.
            return response()->json(['accepted' => false, 'retryable' => true], 503);
        }
    }

    public function returnFromGateway(Request $request)
    {
        try {
            $this->settle($request->query('order'), $request->query('id'));
            return redirect()->route('paySuccess');
        } catch (\Throwable $error) {
            return redirect()->route('payFailed');
        }
    }

    private function settle($gatewayOrder, $transaction): array
    {
        foreach ([$gatewayOrder, $transaction] as $value) {
            if ((!is_string($value) && !is_int($value))
                || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string) $value)) {
                throw new \RuntimeException('WALLET_TOPUP_INVALID_CALLBACK');
            }
        }
        $attempts = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.intention_order_id', (string) $gatewayOrder)->where('orders.type', 'wallet')
            ->select('payments.id')->limit(2)->get();
        if ($attempts->count() !== 1) throw new \RuntimeException('WALLET_TOPUP_ATTEMPT_NOT_UNIQUE');
        return app(WalletTopupSettlement::class)->settle((int) $attempts->first()->id, (string) $transaction);
    }
}
