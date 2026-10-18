<?php
/** One explicit template preparation attempt, with bounded/redacted provider diagnostics only. */
final class CustomerFollowupTemplateDiagnostic
{
    private const ENDPOINT = 'https://graph.facebook.com/v25.0/468336579702269/message_templates';
    private const ROOT = '/home/fasakha/public_html';
    private const SAFE_CODES = ['CRM_SCHEMA_UNAVAILABLE', 'LOCAL_DEVICE_DISABLED', 'EMERGENCY_DISABLED',
        'WHATSAPP_CONNECTION_UNAVAILABLE', 'FEEDBACK_TEMPLATE_CONFIGURATION_INVALID', 'TEMPLATE_LOOKUP_UNAVAILABLE',
        'TEMPLATE_LOOKUP_PERMISSION_REQUIRED', 'TEMPLATE_PAGINATION_LIMIT', 'FEEDBACK_TEMPLATE_CONFLICT',
        'FEEDBACK_TEMPLATE_CREATE_UNCONFIRMED', 'TEMPLATE_MANAGEMENT_PERMISSION_REQUIRED',
        'FEEDBACK_TEMPLATE_PROVIDER_REJECTED', 'DIAGNOSTIC_REQUEST_BLOCKED', 'DIAGNOSTIC_TRANSPORT_UNAVAILABLE',
        'DIAGNOSTIC_INVALID_PROVIDER_RESPONSE'];
    private $transport;
    private array $provider = [];

    public function __construct(?callable $transport = null) { $this->transport = $transport; }

    /** The service receives this transport; every network operation passes the same fixed boundary. */
    public function request(string $method, string $url, array $payload, string $token): array
    {
        if (!in_array($method, ['GET', 'POST'], true) || $url !== self::ENDPOINT) {
            throw new RuntimeException('DIAGNOSTIC_REQUEST_BLOCKED');
        }
        try {
            $response = $this->transport !== null ? ($this->transport)($method, $url, $payload, $token)
                : $this->curlRequest($method, $url, $payload, $token);
        } catch (Throwable $error) {
            $this->provider[] = ['method' => $method, 'transport_error' => true];
            throw new RuntimeException('DIAGNOSTIC_TRANSPORT_UNAVAILABLE');
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) || !is_string($response['body'] ?? null)
            || strlen($response['body']) > 1048576) throw new RuntimeException('DIAGNOSTIC_INVALID_PROVIDER_RESPONSE');
        $entry = ['method' => $method, 'http_status' => $response['status']];
        $body = json_decode($response['body'], true, 64);
        if (is_array($body) && is_array($body['error'] ?? null)) $entry['error'] = self::safeProviderError($body['error'], $token);
        if ($response['status'] === 0) $entry['transport_error'] = true;
        $this->provider[] = $entry;
        return $response;
    }

    public function run(callable $operation): array
    {
        $this->provider = [];
        try {
            $value = $operation();
            $setup = is_array($value['feedback_setup'] ?? null) ? $value['feedback_setup'] : [];
            $state = in_array($setup['state'] ?? null, ['approved', 'pending', 'rejected', 'missing', 'conflict', 'unavailable'], true)
                ? $setup['state'] : 'unavailable';
            $out = ['ok' => true, 'created' => (bool) ($value['created'] ?? false), 'feedback_setup' => ['state' => $state]];
        } catch (Throwable $error) {
            $status = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : null;
            $code = in_array($error->getMessage(), self::SAFE_CODES, true) ? $error->getMessage()
                : ($status === 403 ? 'CENTRAL_ADMIN_REQUIRED' : 'DIAGNOSTIC_UNAVAILABLE');
            $out = ['ok' => false, 'service' => ['http_status' => $status, 'code' => $code]];
        }
        $out['provider'] = $this->provider;
        return $out;
    }

    public static function safeProviderError(array $error, string $token): array
    {
        $safe = [];
        foreach (['code', 'error_subcode'] as $field) {
            $value = $error[$field] ?? null;
            if ((is_int($value) || (is_string($value) && preg_match('/\A[0-9]{1,10}\z/', $value)))
                && $value >= 0 && $value <= 2147483647) $safe[$field] = (int) $value;
        }
        foreach (['type', 'error_user_title', 'error_user_msg', 'message'] as $field) {
            if (is_string($error[$field] ?? null)) $safe[$field] = self::sanitize($error[$field], $token);
        }
        if (is_string($error['error_data']['details'] ?? null)) {
            $safe['details'] = self::sanitize($error['error_data']['details'], $token);
        }
        return $safe;
    }

    private static function sanitize(string $value, string $token): string
    {
        if ($token !== '') $value = str_replace($token, '[redacted]', $value);
        $value = preg_replace('/\bBearer\s+[^\s,"\'<>]+/i', 'Bearer [redacted]', $value);
        $value = preg_replace('/\b(?:access_token|token|authorization)\b["\']?\s*[:=]\s*["\']?[^\s,;"\'<>]+/i', '[redacted credential]', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 600) : substr($value, 0, 600);
    }

    private function curlRequest(string $method, string $url, array $payload, string $token): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('DIAGNOSTIC_TRANSPORT_UNAVAILABLE');
        if ($method === 'GET') $url .= '?' . http_build_query($payload);
        $handle = curl_init($url); $body = '';
        if ($handle === false) throw new RuntimeException('DIAGNOSTIC_TRANSPORT_UNAVAILABLE');
        curl_setopt_array($handle, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 1048576) return 0; $body .= $chunk; return strlen($chunk);
            }]);
        if ($method === 'POST') curl_setopt_array($handle, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        try {
            $ok = curl_exec($handle);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally { curl_close($handle); }
    }

    public static function main(array $arguments): int
    {
        if (PHP_SAPI !== 'cli') { http_response_code(404); return 1; }
        ini_set('display_errors', '0'); ini_set('log_errors', '0');
        if (count($arguments) !== 2 || $arguments[1] !== '--prepare' || getcwd() !== self::ROOT) {
            fwrite(STDOUT, "{\"ok\":false,\"service\":{\"code\":\"EXPECTED_ROOT_AND_PREPARE_ARGUMENT_REQUIRED\"},\"provider\":[]}\n");
            return 1;
        }
        $level = ob_get_level(); ob_start();
        $diagnostic = new self();
        $out = $diagnostic->run(static function () use ($diagnostic) {
            require self::ROOT . '/vendor/autoload.php';
            $app = require self::ROOT . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $actor = \App\Models\User::withoutGlobalScopes()->find(1);
            $service = new \App\Services\Dashboard\CustomerMessagingService([$diagnostic, 'request']);
            return $service->prepareFeedbackTemplate($actor);
        });
        while (ob_get_level() > $level) ob_end_clean();
        fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        return $out['ok'] ? 0 : 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(CustomerFollowupTemplateDiagnostic::main($argv ?? []));
}
