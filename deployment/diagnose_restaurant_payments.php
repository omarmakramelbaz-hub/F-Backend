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
    private $transport = [];
    private const MAX_BYTES = 1048576;

    public function resetDiagnostic(): void { $this->stage = 'not_requested';$this->transport = []; }
    public function safeTransport(): array { return $this->transport; }

    protected function request(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        $this->stage = $path === '/api/auth/tokens' ? 'auth'
            : ($path === '/api/ecommerce/orders/transaction_inquiry' ? 'order_inquiry' : 'transaction');
        if (!(($method === 'POST' && in_array($path, ['/api/auth/tokens', '/api/ecommerce/orders/transaction_inquiry'], true))
            || ($method === 'GET' && preg_match('/\A\/api\/acceptance\/transactions\/[1-9][0-9]{0,29}\z/', $path)))) {
            throw new \RuntimeException('WALLET_TOPUP_INVALID_IDENTIFIER');
        }
        $remaining = (int) floor((($this->deadline ?? 0) - microtime(true)) * 1000);
        if ($remaining < 1) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_TIMEOUT');
        $wire = $this->exchange($method, $path, $payload, $token, $remaining);
        $body = is_string($wire['body'] ?? null) ? $wire['body'] : '';
        $status = (int) ($wire['status'] ?? 0);$status = $status >= 100 && $status <= 599 ? $status : 0;
        $errno = (int) ($wire['errno'] ?? 0);$errno = $errno >= 0 && $errno <= 999 ? $errno : 0;
        $tooLarge = ($wire['too_large'] ?? false) === true || strlen($body) > self::MAX_BYTES;
        $decoded = null;$kind = $body === '' ? 'empty' : 'non_json';
        if (!$tooLarge && $body !== '') {
            try {
                $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                $kind = is_array($decoded) ? (str_starts_with(ltrim($body), '{') ? 'json_object' : 'json_array') : 'json_scalar';
            } catch (\JsonException $error) { /* No response text is exposed. */ }
        }
        $this->transport[] = ['stage' => $this->stage, 'http_status' => $status, 'curl_errno' => $errno,
            'response_kind' => $tooLarge ? 'oversize' : $kind,
            'response_category' => $this->category($status, $errno, $tooLarge, $decoded),
            'error_fields' => $this->errorFields($decoded)];
        if ($tooLarge) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_RESPONSE_TOO_LARGE');
        if (($wire['success'] ?? false) !== true) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_FAILED');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_HTTP_FAILED');
        if (!is_array($decoded) || array_is_list($decoded)) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_INVALID_RESPONSE');
        return $decoded;
    }

    /** Only this bounded read-only HTTP exchange is overridable for fixture tests. */
    protected function exchange(string $method, string $path, array $payload, ?string $token, int $remaining): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('WALLET_TOPUP_CURL_REQUIRED');
        $handle = curl_init('https://accept.paymob.com'.$path);
        if ($handle === false) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_FAILED');
        $body = '';$tooLarge = false;
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
        try {
            $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => $remaining,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true;return 0; }
                    $body .= $chunk;return strlen($chunk);
                }];
            if ($method === 'POST') $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            if (!curl_setopt_array($handle, $options)) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_FAILED');
            $success = curl_exec($handle);
            return ['success' => $success !== false, 'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'errno' => curl_errno($handle), 'too_large' => $tooLarge, 'body' => $body];
        } finally { curl_close($handle); }
    }

    private function category(int $status, int $errno, bool $tooLarge, $decoded): string
    {
        if ($tooLarge) return 'RESPONSE_TOO_LARGE';
        if ($errno !== 0 || $status === 0) return 'TRANSPORT_FAILURE';
        if ($status >= 200 && $status < 300) return 'HTTP_SUCCESS';
        // Match only fixed known messages/codes internally. Never forward unknown content.
        $known = [
            'no transaction found' => 'NO_TRANSACTION_REPORTED', 'no transactions found' => 'NO_TRANSACTION_REPORTED',
            'no transaction found for this order' => 'NO_TRANSACTION_REPORTED', 'no transactions found for this order' => 'NO_TRANSACTION_REPORTED',
            'no transaction found against this order' => 'NO_TRANSACTION_REPORTED', 'no transactions found against this order' => 'NO_TRANSACTION_REPORTED',
            'no transaction exists for this order' => 'NO_TRANSACTION_REPORTED', 'no transactions exist for this order' => 'NO_TRANSACTION_REPORTED',
            'transaction not found' => 'TRANSACTION_NOT_FOUND', 'transaction_not_found' => 'TRANSACTION_NOT_FOUND',
            'order not found' => 'ORDER_NOT_FOUND', 'order_not_found' => 'ORDER_NOT_FOUND',
            'not found' => 'RESOURCE_NOT_FOUND', 'not_found' => 'RESOURCE_NOT_FOUND',
            'authentication_failed' => 'AUTHENTICATION_REJECTED', 'not_authenticated' => 'AUTHENTICATION_REJECTED',
            'permission_denied' => 'ACCESS_DENIED', 'throttled' => 'RATE_LIMITED',
            'required' => 'REQUEST_FIELD_VALIDATION', 'invalid' => 'REQUEST_FIELD_VALIDATION',
        ];
        foreach ($this->knownErrorValues($decoded) as $value) {
            $normalized = strtolower(trim($value, " \t\n\r\0\x0B."));
            if (isset($known[$normalized])) return $known[$normalized];
        }
        if ($status === 401) return 'AUTHENTICATION_REJECTED';
        if ($status === 403) return 'ACCESS_DENIED';
        if ($status === 404) return 'RESOURCE_NOT_FOUND';
        if ($status === 429) return 'RATE_LIMITED';
        if ($status >= 500) return 'GATEWAY_SERVER_ERROR';
        if ($status >= 300 && $status < 400) return 'UNEXPECTED_REDIRECT';
        if ($status === 400 || $status === 422) return 'REQUEST_INVALID';
        return 'HTTP_ERROR';
    }

    private function knownErrorValues($decoded): array
    {
        if (!is_array($decoded) || array_is_list($decoded)) return [];
        $values = [];
        foreach (['detail', 'message', 'error', 'errors', 'code', 'non_field_errors'] as $key) {
            $field = $decoded[$key] ?? null;
            if (is_string($field) && strlen($field) <= 1024) $values[] = $field;
            if (is_array($field)) foreach (['detail', 'message', 'code', 'order_id', 'merchant_order_id', 'non_field_errors', 0] as $nested) {
                $value = $field[$nested] ?? null;
                if (is_string($value) && strlen($value) <= 1024) $values[] = $value;
                if (is_array($value) && is_string($value[0] ?? null) && strlen($value[0]) <= 1024) $values[] = $value[0];
            }
        }
        return $values;
    }

    private function errorFields($decoded): array
    {
        if (!is_array($decoded) || array_is_list($decoded)) return [];
        $fields = [];
        foreach (['detail', 'message', 'error', 'errors', 'code', 'status', 'non_field_errors', 'order_id', 'merchant_order_id'] as $key) {
            if (!array_key_exists($key, $decoded)) continue;
            $fields[$key] = $this->typeHint($decoded[$key]);
            if (is_array($decoded[$key]) && !array_is_list($decoded[$key])) {
                foreach (['detail', 'message', 'code', 'order_id', 'merchant_order_id', 'auth_token', 'non_field_errors'] as $nested) {
                    if (array_key_exists($nested, $decoded[$key])) $fields[$key.'.'.$nested] = $this->typeHint($decoded[$key][$nested]);
                }
            }
        }
        return $fields;
    }

    private function typeHint($value): string
    {
        if (is_array($value)) return array_is_list($value) ? 'array' : 'object';
        if (is_string($value)) return 'string';
        if (is_int($value) || is_float($value)) return 'number';
        if (is_bool($value)) return 'boolean';
        return 'null';
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
        return $this->inspectOnce($paymentId, null, true);
    }

    private function inspectOnce(int $paymentId, ?string $savedTransaction, bool $allowSavedProbe): array
    {
        $result = ['payment_id' => $paymentId, 'verification' => 'not_verified'];
        if ($savedTransaction !== null) $result['inquiry_scope'] = 'exact_saved_transaction_id';
        if ($this->gateway instanceof RestaurantPaymentDiagnosticInquiry) $this->gateway->resetDiagnostic();
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
            if ($savedTransaction !== null) {
                if (!$this->readHelper('identifier', $payment->transaction_id ?? null)
                    || (string) $payment->transaction_id !== $savedTransaction) throw new \RuntimeException('RESTAURANT_PAYMENT_ATTEMPT_CHANGED');
                $requested = $savedTransaction;
            }
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
        if ($this->gateway instanceof RestaurantPaymentDiagnosticInquiry) $result['transport'] = $this->gateway->safeTransport();
        // An old failed callback may still have saved its exact numeric transaction.
        // Investigate that ID separately after a failed order lookup; never invent a
        // merchant reference, promote the primary result, settle, or update a receipt.
        if ($allowSavedProbe && ($result['reason_code'] ?? '') === 'WALLET_TOPUP_GATEWAY_HTTP_FAILED'
            && $stage === 'order_inquiry' && isset($payment) && ($requested ?? null) === null
            && $this->readHelper('identifier', $payment->transaction_id ?? null)) {
            $result['saved_transaction_probe'] = $this->inspectOnce($paymentId, (string) $payment->transaction_id, false);
        }
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
