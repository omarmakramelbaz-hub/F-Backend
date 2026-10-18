<?php
/** Read-only status or one explicit template preparation, with bounded/redacted diagnostics only. */
final class CustomerFollowupTemplateDiagnostic
{
    private const ENDPOINT = 'https://graph.facebook.com/v25.0/468336579702269/message_templates';
    private const ROOT = '/home/fasakha/public_html';
    private const SAFE_CODES = ['CRM_SCHEMA_UNAVAILABLE', 'LOCAL_DEVICE_DISABLED', 'EMERGENCY_DISABLED',
        'WHATSAPP_CONNECTION_UNAVAILABLE', 'FEEDBACK_TEMPLATE_CONFIGURATION_INVALID', 'TEMPLATE_LOOKUP_UNAVAILABLE',
        'TEMPLATE_LOOKUP_PERMISSION_REQUIRED', 'TEMPLATE_PAGINATION_LIMIT', 'FEEDBACK_TEMPLATE_CONFLICT',
        'FEEDBACK_TEMPLATE_CREATE_UNCONFIRMED', 'TEMPLATE_MANAGEMENT_PERMISSION_REQUIRED',
        'FEEDBACK_TEMPLATE_PROVIDER_REJECTED', 'DIAGNOSTIC_REQUEST_BLOCKED', 'DIAGNOSTIC_TRANSPORT_UNAVAILABLE',
        'DIAGNOSTIC_INVALID_PROVIDER_RESPONSE', 'CRM_SENDER_DISABLED', 'SENDER_UNAVAILABLE',
        'APPROVED_FEEDBACK_TEMPLATE_REQUIRED', 'FEEDBACK_TEMPLATE_NOT_APPROVED'];
    private $transport;
    private array $provider = [];
    private bool $readOnly;

    public function __construct(?callable $transport = null, bool $readOnly = false)
    {
        $this->transport = $transport; $this->readOnly = $readOnly;
    }

    /** The service receives this transport; every network operation passes the same fixed boundary. */
    public function request(string $method, string $url, array $payload, string $token): array
    {
        if (!in_array($method, $this->readOnly ? ['GET'] : ['GET', 'POST'], true) || $url !== self::ENDPOINT) {
            throw new RuntimeException('DIAGNOSTIC_REQUEST_BLOCKED');
        }
        $original = $payload;
        if ($this->readOnly) $payload['fields'] = 'name,status,language,category,components,rejected_reason';
        for ($attempt = 0; $attempt < 2; $attempt++) {
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
            if ($this->readOnly) {
                $entry['fixed_templates'] = [];
                foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $raw) {
                    if (!is_array($raw) || ($raw['name'] ?? null) !== 'fasakhansta_order_feedback_v1') continue;
                    $entry['fixed_templates'][] = ['name' => 'fasakhansta_order_feedback_v1',
                        'language' => self::safeIdentifier($raw['language'] ?? '', true),
                        'category' => in_array($raw['category'] ?? null, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true) ? $raw['category'] : 'UNKNOWN',
                        'status' => self::safeTemplateStatus($raw['status'] ?? null),
                        'rejected_reason' => is_string($raw['rejected_reason'] ?? null) ? self::sanitize($raw['rejected_reason'], $token) : null];
                    if (count($entry['fixed_templates']) >= 20) break;
                }
            }
            if ($response['status'] === 0) $entry['transport_error'] = true;
            $this->provider[] = $entry;
            // Older Graph schemas may not expose rejected_reason. Retry only that read-only code100 response.
            if ($this->readOnly && $attempt === 0 && $payload !== $original && $response['status'] === 400
                && ($body['error']['code'] ?? null) === 100) { $payload = $original; continue; }
            return $response;
        }
        throw new RuntimeException('DIAGNOSTIC_INVALID_PROVIDER_RESPONSE');
    }


    public function run(callable $operation): array
    {
        $this->provider = [];
        try {
            $value = $operation();
            $setup = is_array($value['feedback_setup'] ?? null) ? $value['feedback_setup'] : [];
            $state = in_array($setup['state'] ?? null, ['approved', 'pending', 'rejected', 'missing', 'conflict', 'unavailable'], true)
                ? $setup['state'] : 'unavailable';
            $out = $this->readOnly ? ['ok' => true, 'status' => self::safeStatus(is_array($value) ? $value : [])]
                : ['ok' => true, 'created' => (bool) ($value['created'] ?? false), 'feedback_setup' => ['state' => $state]];
        } catch (Throwable $error) {
            $status = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : null;
            $code = in_array($error->getMessage(), self::SAFE_CODES, true) ? $error->getMessage()
                : ($status === 403 ? 'CENTRAL_ADMIN_REQUIRED' : 'DIAGNOSTIC_UNAVAILABLE');
            $out = ['ok' => false, 'service' => ['http_status' => $status, 'code' => $code]];
        }
        $out['provider'] = $this->provider;
        return $out;
    }

    /** Whitelist operational settings and template identifiers; never expose customers or message bodies. */
    public static function safeStatus(array $value): array
    {
        $out = [];
        foreach (['available', 'enabled', 'feedback_enabled', 'feedback_ready'] as $field) {
            $out[$field] = ($value[$field] ?? false) === true;
        }
        foreach (['reason', 'feedback_reason'] as $field) $out[$field] = self::safeReason($value[$field] ?? null);
        $out['feedback_template'] = self::safeIdentifier($value['feedback_template'] ?? '', false);
        $out['feedback_language'] = self::safeIdentifier($value['feedback_language'] ?? '', true);
        $body = is_string($value['feedback_body'] ?? null) ? $value['feedback_body'] : '';
        $out['expected_body_sha256'] = hash('sha256', $body);
        $suffix = 'شكرًا لاختيارك فسخانستا.';
        $out['contains_substantive_suffix'] = substr($body, -strlen($suffix)) === $suffix;
        $setup = is_array($value['feedback_setup'] ?? null) ? $value['feedback_setup'] : [];
        $out['feedback_setup'] = [
            'state' => in_array($setup['state'] ?? null, ['approved', 'pending', 'rejected', 'missing', 'conflict', 'unavailable'], true)
                ? $setup['state'] : 'unavailable',
            'name' => self::safeIdentifier($setup['name'] ?? '', false),
            'language' => self::safeIdentifier($setup['language'] ?? '', true),
            'category' => in_array($setup['category'] ?? null, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true) ? $setup['category'] : null,
            'template_status' => self::safeTemplateStatus($setup['template_status'] ?? null),
            'reason' => self::safeReason($setup['reason'] ?? null), 'can_prepare' => ($setup['can_prepare'] ?? false) === true,
            'matches' => []];
        foreach (array_slice(is_array($setup['matches'] ?? null) ? $setup['matches'] : [], 0, 20) as $match) {
            if (!is_array($match)) continue;
            $out['feedback_setup']['matches'][] = [
                'name' => self::safeIdentifier($match['name'] ?? '', false),
                'language' => self::safeIdentifier($match['language'] ?? '', true),
                'category' => in_array($match['category'] ?? null, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true) ? $match['category'] : null,
                'status' => self::safeTemplateStatus($match['status'] ?? null)];
        }
        $out['feedback_templates'] = [];
        foreach (array_slice(is_array($value['feedback_templates'] ?? null) ? $value['feedback_templates'] : [], 0, 20) as $template) {
            if (!is_array($template)) continue;
            $out['feedback_templates'][] = ['name' => self::safeIdentifier($template['name'] ?? '', false),
                'language' => self::safeIdentifier($template['language'] ?? '', true)];
        }
        return $out;
    }

    private static function safeReason($value): ?string
    {
        if ($value === null) return null;
        return in_array($value, self::SAFE_CODES, true) ? $value : 'UNRECOGNIZED_STATUS_REASON';
    }

    private static function safeIdentifier($value, bool $language): string
    {
        return is_string($value) && preg_match($language ? '/\A[a-zA-Z_]{2,20}\z/' : '/\A[a-z0-9_]{1,512}\z/', $value) ? $value : '';
    }

    private static function safeTemplateStatus($value): ?string
    {
        if ($value === null) return null;
        return in_array($value, ['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED', 'PENDING_DELETION', 'DELETED',
            'IN_APPEAL', 'LIMIT_EXCEEDED'], true) ? $value : 'UNKNOWN';
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
        if (count($arguments) !== 2 || !in_array($arguments[1], ['--prepare', '--status'], true) || getcwd() !== self::ROOT) {
            fwrite(STDOUT, "{\"ok\":false,\"service\":{\"code\":\"EXPECTED_ROOT_AND_MODE_ARGUMENT_REQUIRED\"},\"provider\":[]}\n");
            return 1;
        }
        $level = ob_get_level(); ob_start();
        $readOnly = $arguments[1] === '--status';
        $diagnostic = new self(null, $readOnly);
        $out = $diagnostic->run(static function () use ($diagnostic, $readOnly) {
            require self::ROOT . '/vendor/autoload.php';
            $app = require self::ROOT . '/bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $actor = \App\Models\User::withoutGlobalScopes()->find(1);
            $service = new \App\Services\Dashboard\CustomerMessagingService([$diagnostic, 'request']);
            if ($readOnly) {
                $app->make(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
                return $service->status();
            }
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
