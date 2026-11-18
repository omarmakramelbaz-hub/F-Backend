<?php

namespace App\Services\Payments;

/** Authenticated, bounded, read-only inquiry; never returns or logs credentials. */
class PaymobTransactionInquiry
{
    private const BASE_URL = 'https://accept.paymob.com';
    private const MAX_BYTES = 1048576;
    protected $deadline;

    public function transactionForOrder(string $gatewayOrderId, ?string $transactionId = null): array
    {
        $this->identifier($gatewayOrderId);
        if ($transactionId !== null) $this->identifier($transactionId);
        $key = config('wallet_topups.api_key');
        if (!is_string($key) || trim($key) === '' || strlen($key) > 65536) {
            throw new \RuntimeException('WALLET_TOPUP_API_KEY_REQUIRED');
        }
        // Authentication plus inquiry share an eight-second network budget.
        $this->deadline = microtime(true) + 8;
        $authentication = $this->request('POST', '/api/auth/tokens', ['api_key' => $key]);
        $token = $authentication['token'] ?? null;
        if (!is_string($token) || strlen($token) > 16384
            || !preg_match('/\A[A-Za-z0-9._~+\/=\-]+\z/', $token)) {
            throw new \RuntimeException('WALLET_TOPUP_GATEWAY_AUTH_FAILED');
        }
        if ($transactionId !== null) {
            return $this->request('GET', '/api/acceptance/transactions/' . $transactionId, [], $token);
        }
        return $this->request('POST', '/api/ecommerce/orders/transaction_inquiry',
            ['auth_token' => $token, 'order_id' => $gatewayOrderId], $token);
    }

    /** Kept overridable for transport tests; production URLs are fixed and HTTPS. */
    protected function request(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('WALLET_TOPUP_CURL_REQUIRED');
        $remaining = (int) floor((($this->deadline ?? 0) - microtime(true)) * 1000);
        if ($remaining < 1) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_TIMEOUT');
        $handle = curl_init(self::BASE_URL . $path);
        if ($handle === false) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_FAILED');
        $body = ''; $tooLarge = false;
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining),
                CURLOPT_TIMEOUT_MS => $remaining,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($method === 'POST') $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
            if (!curl_setopt_array($handle, $options)) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_FAILED');
            $success = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($tooLarge) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_RESPONSE_TOO_LARGE');
            if ($success === false) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_FAILED');
            if ($status < 200 || $status >= 300) throw new \RuntimeException('WALLET_TOPUP_GATEWAY_HTTP_FAILED');
            try {
                $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new \RuntimeException('WALLET_TOPUP_GATEWAY_INVALID_RESPONSE');
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \RuntimeException('WALLET_TOPUP_GATEWAY_INVALID_RESPONSE');
            }
            return $decoded;
        } finally {
            curl_close($handle);
        }
    }

    private function identifier(string $value): void
    {
        if (!preg_match('/\A[1-9][0-9]{0,29}\z/', $value)) {
            throw new \RuntimeException('WALLET_TOPUP_INVALID_IDENTIFIER');
        }
    }
}
