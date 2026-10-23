<?php

namespace Fasakhansta\Deployment;

use App\Services\Payments\PaymobTransactionInquiry;
use App\Services\Payments\RestaurantOrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bootstrap before declaring the inquiry subclass. No boot output is forwarded.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ob_start();
    try {
        if (PHP_SAPI !== 'cli' || count($argv) !== 1) throw new \RuntimeException('DIAGNOSTIC_ENTRY_REQUIRED');
        $root = '/home/fasakha/public_html';
        // Pin the read-only helper implementation used by this diagnostic.
        $hashes = [
            'app/Services/Payments/RestaurantOrderPayment.php' => 'e841b9b25468dec4fcd9e2ba945c81b64c26686f3cb985742bee40534bb6b86a',
            'app/Services/Payments/PaymobTransactionInquiry.php' => '33338e42b28e8ab51445c8349dc0153fb0d731a129c411b235737062c5ec1bc5',
        ];
        foreach ($hashes as $path => $hash) {
            if (!is_file($root.'/'.$path) || is_link($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $hash) {
                throw new \RuntimeException('DIAGNOSTIC_SOURCE_REVIEW_REQUIRED');
            }
        }
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    } catch (\Throwable $error) {
        $code = in_array($error->getMessage(), ['DIAGNOSTIC_ENTRY_REQUIRED', 'DIAGNOSTIC_SOURCE_REVIEW_REQUIRED'], true)
            ? $error->getMessage() : 'DIAGNOSTIC_BOOTSTRAP_FAILED';
        ob_end_clean();
        echo 'RESTAURANT_PAYMENT_DIAGNOSTIC '.json_encode(['mode' => 'read_only', 'reason_code' => $code]).PHP_EOL;
        exit(1);
    }
    ob_end_clean();
}

/** Temporary operator inquiry. Never settles a receipt or dispatches effects. */
class RestaurantPaymentDiagnosticInquiry extends PaymobTransactionInquiry
{
    public $stage = 'not_requested';

    protected function request(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        $this->stage = $path === '/api/auth/tokens' ? 'auth'
            : ($path === '/api/ecommerce/orders/transaction_inquiry' ? 'order_inquiry' : 'transaction');
        return parent::request($method, $path, $payload, $token);
    }
}

final class RestaurantPaymentReadOnlyDiagnostic
{
    private $receipts;
    private $gateway;
    private const ERRORS = [
        'RESTAURANT_PAYMENT_CENTRAL_SERVER_REQUIRED', 'RESTAURANT_PAYMENT_TRANSACTION_NOT_ALLOWED',
        'RESTAURANT_PAYMENT_INVALID_IDENTIFIER', 'RESTAURANT_PAYMENT_GATEWAY_CONFIG_REQUIRED',
        'RESTAURANT_PAYMENT_INVALID_RESTAURANT_ATTEMPT', 'RESTAURANT_PAYMENT_OWNERSHIP_MISMATCH',
        'RESTAURANT_PAYMENT_PARTY_NOT_FOUND', 'RESTAURANT_PAYMENT_INTEGRATION_REQUIRED',
        'RESTAURANT_PAYMENT_INVALID_AMOUNT', 'RESTAURANT_PAYMENT_TRANSACTION_MISMATCH',
        'RESTAURANT_PAYMENT_GATEWAY_ATTRIBUTES_MISMATCH', 'RESTAURANT_PAYMENT_PAYMENT_NOT_SETTLED',
        'RESTAURANT_PAYMENT_AMBIGUOUS_GATEWAY_ORDER', 'RESTAURANT_PAYMENT_TRANSACTION_ALREADY_USED',
        'RESTAURANT_PAYMENT_ATTEMPT_CHANGED', 'WALLET_TOPUP_API_KEY_REQUIRED',
        'WALLET_TOPUP_INVALID_IDENTIFIER', 'WALLET_TOPUP_GATEWAY_AUTH_FAILED',
        'WALLET_TOPUP_CURL_REQUIRED', 'WALLET_TOPUP_GATEWAY_TIMEOUT',
        'WALLET_TOPUP_GATEWAY_FAILED', 'WALLET_TOPUP_GATEWAY_RESPONSE_TOO_LARGE',
        'WALLET_TOPUP_GATEWAY_HTTP_FAILED', 'WALLET_TOPUP_GATEWAY_INVALID_RESPONSE',
        'DIAGNOSTIC_SCHEMA_REQUIRED',
    ];

    public function __construct(RestaurantOrderPayment $receipts, PaymobTransactionInquiry $gateway)
    {
        $this->receipts = $receipts;
        $this->gateway = $gateway;
    }

    public function candidateIds(): array
    {
        if (config('desktop_dashboard.local', false)) throw new \RuntimeException('RESTAURANT_PAYMENT_CENTRAL_SERVER_REQUIRED');
        foreach (['orders' => ['id', 'user_id', 'resturant_id', 'type', 'status', 'payment_type'],
            'payments' => ['id', 'user_id', 'order_id', 'intention_order_id', 'total_price', 'status', 'transaction_id']] as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) throw new \RuntimeException('DIAGNOSTIC_SCHEMA_REQUIRED');
        }
        $query = DB::table('payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.type', 'current')->whereNull('o.status')->whereIn('o.payment_type', ['online', 'v_cash'])
            ->whereColumn('p.user_id', 'o.user_id')->where('p.total_price', '>', 0)->whereNotNull('p.intention_order_id');
        if (Schema::hasColumn('orders', 'accepted_notify')) $query->where(function ($q) {
            $q->whereNull('o.accepted_notify')->orWhere('o.accepted_notify', '!=', 'yes');
        });
        if (Schema::hasColumn('orders', 'transfer_price_by')) $query->whereNull('o.transfer_price_by');
        $ids = []; $seen = [];
        foreach ($query->orderByDesc('p.id')->limit(1000)->get(['p.id', 'p.order_id', 'p.intention_order_id']) as $row) {
            if (isset($seen[$row->order_id]) || !preg_match('/\A[1-9][0-9]{0,29}\z/', (string) $row->intention_order_id)) continue;
            $seen[$row->order_id] = true;
            $ids[] = (int) $row->id;
            if (count($ids) === 5) break;
        }
        return $ids;
    }

    public function inspect(int $paymentId): array
    {
        $result = ['payment_id' => $paymentId, 'verification' => 'not_verified'];
        if ($this->gateway instanceof RestaurantPaymentDiagnosticInquiry) $this->gateway->stage = 'not_requested';
        try {
            if (config('desktop_dashboard.local', false)) throw new \RuntimeException('RESTAURANT_PAYMENT_CENTRAL_SERVER_REQUIRED');
            if (DB::transactionLevel() !== 0) throw new \RuntimeException('RESTAURANT_PAYMENT_TRANSACTION_NOT_ALLOWED');
            if ($paymentId < 1) throw new \RuntimeException('RESTAURANT_PAYMENT_INVALID_IDENTIFIER');
            $payment = DB::table('payments')->where('id', $paymentId)->first();
            $order = $payment ? DB::table('orders')->where('id', $payment->order_id)->first() : null;
            if ($order) {
                $result['order_id'] = (int) $order->id;
                $result['payment_method'] = in_array($order->payment_type ?? '', ['online', 'v_cash'], true) ? $order->payment_type : 'other';
            }
            // These existing private helpers perform only SELECTs and validation.
            // settle(), transactions, locks, provider effects and cache rotation are never invoked.
            $facts = $this->readHelper('facts', $payment, $order);
            $this->readHelper('uniqueGatewayOrder', $facts['gateway_order_id'], $paymentId);
            $requested = $this->readHelper('succeeded', $payment->status ?? null)
                && $this->readHelper('identifier', $payment->transaction_id ?? null) ? (string) $payment->transaction_id : null;
            $transaction = $this->gateway->transactionForOrder($facts['gateway_order_id'], $requested);
            $result['gateway_matches'] = [
                'order' => is_array($transaction['order'] ?? null) && $this->sameScalar($transaction['order']['id'] ?? null, $facts['gateway_order_id']),
                'amount' => $this->sameScalar($transaction['amount_cents'] ?? null, (string) $facts['amount_cents']),
                'currency' => ($transaction['currency'] ?? null) === 'EGP',
                'integration' => $this->sameScalar($transaction['integration_id'] ?? null, $facts['integration_id']),
                'transaction' => $this->readHelper('identifier', $transaction['id'] ?? null)
                    && ($requested === null || $this->sameScalar($transaction['id'], $requested)),
            ];
            $result['gateway_flags'] = [];
            foreach (['is_live', 'success', 'is_standalone_payment', 'pending', 'is_auth', 'is_capture',
                'is_voided', 'is_refunded', 'has_parent_transaction', 'error_occured', 'is_void', 'is_refund'] as $flag) {
                $result['gateway_flags'][$flag] = !array_key_exists($flag, $transaction) ? 'missing' : $this->flag($transaction[$flag]);
            }
            $result['gateway_flags']['refunded_amount'] = !array_key_exists('refunded_amount_cents', $transaction) ? 'missing'
                : (in_array($transaction['refunded_amount_cents'], [0, '0'], true) ? 'zero' : 'nonzero_or_invalid');
            $verified = $this->readHelper('verify', $transaction, $facts, $requested);
            if (DB::table('payments')->where('transaction_id', $verified)->where('id', '<>', $paymentId)->exists()) {
                throw new \RuntimeException('RESTAURANT_PAYMENT_TRANSACTION_ALREADY_USED');
            }
            $currentPayment = DB::table('payments')->where('id', $paymentId)->first();
            $currentOrder = DB::table('orders')->where('id', $facts['order_id'])->first();
            if ($this->readHelper('facts', $currentPayment, $currentOrder) !== $facts) throw new \RuntimeException('RESTAURANT_PAYMENT_ATTEMPT_CHANGED');
            $this->readHelper('uniqueGatewayOrder', $facts['gateway_order_id'], $paymentId);
            $result['verification'] = 'verified';
            $result['eligibility'] = $this->eligibility($currentPayment, $currentOrder, $verified);
        } catch (\Throwable $error) {
            $result['reason_code'] = self::safeError($error);
        }
        $stage = $this->gateway instanceof RestaurantPaymentDiagnosticInquiry ? $this->gateway->stage : 'not_reported';
        $result['inquiry_stage'] = in_array($stage, ['not_requested', 'auth', 'order_inquiry', 'transaction', 'not_reported'], true) ? $stage : 'not_reported';
        return $result;
    }

    public static function safeError(\Throwable $error): string
    {
        return in_array($error->getMessage(), self::ERRORS, true) ? $error->getMessage() : 'DIAGNOSTIC_INTERNAL_ERROR';
    }

    private function eligibility($payment, $order, string $transaction): string
    {
        if (($order->transfer_price_by ?? null) !== null
            || in_array($order->status ?? '', ['cancelled', 'canceled', 'declined', 'rejected', 'expired'], true)) return 'ORDER_CLOSED';
        foreach (DB::table('payments')->where('order_id', $order->id)->get(['id', 'status']) as $attempt) {
            if ((int) $attempt->id !== (int) $payment->id && $this->readHelper('succeeded', $attempt->status ?? null)) return 'OTHER_SUCCESSFUL_ATTEMPT';
        }
        if ($this->readHelper('succeeded', $payment->status ?? null) && (string) ($payment->transaction_id ?? '') !== $transaction) return 'STORED_RECEIPT_MISMATCH';
        if ($order->status !== null) return 'ORDER_ALREADY_ACTIVE_OR_CLOSED';
        if (($order->accepted_notify ?? '') === 'yes' || !empty($order->delegate_id) || !empty($order->delegate_from_out)) return 'ORDER_ALREADY_ACCEPTED_OR_ASSIGNED';
        if (!$this->readHelper('succeeded', $payment->status ?? null)
            && !in_array(strtolower(trim((string) ($payment->status ?? ''))), ['', 'pending', 'creating', '0', 'false', 'failed'], true)) return 'ATTEMPT_STATE_BLOCKED';
        return 'ELIGIBLE_FOR_NORMAL_RECONCILIATION';
    }

    private function readHelper(string $name, ...$args)
    {
        if (!in_array($name, ['facts', 'uniqueGatewayOrder', 'succeeded', 'identifier', 'verify'], true)) throw new \LogicException('READ_HELPER_REQUIRED');
        $method = new \ReflectionMethod(RestaurantOrderPayment::class, $name);
        $method->setAccessible(true);
        return $method->invoke($this->receipts, ...$args);
    }

    private function flag($value)
    {
        if (in_array($value, [true, 1, 'true', '1'], true)) return true;
        if (in_array($value, [false, 0, 'false', '0'], true)) return false;
        return 'invalid';
    }

    private function sameScalar($value, string $expected): bool
    {
        return (is_int($value) || is_string($value)) && (string) $value === $expected;
    }
}

// Requiring this file in tests defines helpers without booting or contacting Paymob.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ob_start();
    try {
        $inquiry = new RestaurantPaymentDiagnosticInquiry();
        $diagnostic = new RestaurantPaymentReadOnlyDiagnostic(new RestaurantOrderPayment(), $inquiry);
        $ids = $diagnostic->candidateIds();
        $orders = [];
        foreach ($ids as $id) $orders[] = $diagnostic->inspect($id);
        $result = ['mode' => 'read_only', 'candidates' => count($ids), 'orders' => $orders];
        $status = 0;
    } catch (\Throwable $error) {
        $safe = in_array($error->getMessage(), ['DIAGNOSTIC_ENTRY_REQUIRED', 'DIAGNOSTIC_SOURCE_REVIEW_REQUIRED'], true)
            ? $error->getMessage() : RestaurantPaymentReadOnlyDiagnostic::safeError($error);
        $result = ['mode' => 'read_only', 'reason_code' => $safe];
        $status = 1;
    }
    ob_end_clean();
    echo 'RESTAURANT_PAYMENT_DIAGNOSTIC '.json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status);
}
