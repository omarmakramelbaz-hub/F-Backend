<?php

namespace App\Support {
    /** Fixed, read-only subscription probes. Credentials and callback URLs never enter the report. */
    final class WhatsAppStandbySubscriptionDiagnostic
    {
        public const APP = '2177982783065133';
        public const WABA = '468336579702269';
        public const BASE = 'https://graph.facebook.com/v25.0/';
        private const MAX_BYTES = 65536;
        private const FIELDS = ['account_alerts', 'account_review_update', 'account_update',
            'business_capability_update', 'history', 'message_template_components_update',
            'message_template_quality_update', 'message_template_status_update', 'messages',
            'messaging_handovers', 'partner_solutions', 'payment_configuration_update',
            'phone_number_name_update', 'phone_number_quality_update', 'security',
            'smb_app_state_sync', 'smb_message_echoes', 'standby', 'template_category_update',
            'user_preferences', 'calls'];
        private $transport;
        private int $requests = 0;
        public function __construct(?callable $transport = null) { $this->transport = $transport; }

        private function read(string $path, string $bearer): array
        {
            if (++$this->requests > 2) throw new \RuntimeException();
            $url = self::BASE . $path;
            if ($this->transport !== null) return ($this->transport)($url, $bearer);
            if (!function_exists('curl_init')) throw new \RuntimeException();
            $body = ''; $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Accept: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) return 0;
                    $body .= $chunk; return strlen($chunk);
                }]);
            try {
                if (curl_exec($curl) === false) throw new \RuntimeException();
                return ['status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
            } finally { curl_close($curl); }
        }

        private function probe(string $path, string $bearer): array
        {
            $safe = ['status' => 'TRANSPORT_ERROR', 'http' => null, 'code' => null,
                'subcode' => null, 'error_class' => 'NONE'];
            try {
                $response = $this->read($path, $bearer);
                $http = $response['status'] ?? null;
                $safe['http'] = is_int($http) && $http >= 100 && $http <= 599 ? $http : null;
                $bytes = $response['body'] ?? null;
                if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) throw new \UnexpectedValueException();
                $body = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
                if (!is_array($body)) throw new \UnexpectedValueException();
                $error = $body['error'] ?? null;
                if ($error !== null && !is_array($error)) throw new \UnexpectedValueException();
                if ($http !== 200 || $error !== null) {
                    $safe['status'] = 'API_ERROR';
                    foreach (['code' => 'code', 'subcode' => 'error_subcode'] as $target => $key) {
                        $v = $error[$key] ?? null;
                        $safe[$target] = is_int($v) && $v >= 0 && $v <= 2147483647 ? $v : null;
                    }
                    $types = ['OAuthException' => 'OAUTH_EXCEPTION', 'GraphMethodException' => 'GRAPH_METHOD_EXCEPTION'];
                    $safe['error_class'] = $error === null ? 'NONE' : ($types[$error['type'] ?? ''] ?? 'OTHER');
                    return [$safe, null];
                }
                if (!isset($body['data']) || !is_array($body['data']) || !array_is_list($body['data'])
                    || array_filter($body['data'], static fn ($row) => !is_array($row))) {
                    throw new \UnexpectedValueException();
                }
                $safe['status'] = 'READY'; return [$safe, $body];
            } catch (\Throwable $error) {
                $safe['status'] = $error instanceof \JsonException || $error instanceof \UnexpectedValueException
                    ? 'INVALID_RESPONSE' : 'TRANSPORT_ERROR';
                return [$safe, null];
            }
        }

        public function inspect(string $secret, string $token): array
        {
            $this->requests = 0;
            if (!preg_match('/\A[a-fA-F0-9]{32}\z/', $secret)
                || !preg_match('/\A[A-Za-z0-9._~-]{32,4096}\z/', $token)) throw new \RuntimeException();
            [$appProbe, $appBody] = $this->probe(self::APP . '/subscriptions', self::APP . '|' . $secret);
            $subscription = ['whatsapp_object_present' => null, 'active' => null,
                'field_names' => [], 'unknown_field_count' => 0, 'standby_subscribed' => null,
                'complete' => null];
            if ($appBody !== null) {
                $rows = array_values(array_filter($appBody['data'], static fn ($row) =>
                    is_array($row) && ($row['object'] ?? null) === 'whatsapp_business_account'));
                $subscription['complete'] = empty($appBody['paging']['next']);
                if (!$rows) $subscription['whatsapp_object_present'] = $subscription['complete'] ? false : null;
                elseif (count($rows) !== 1 || !is_bool($rows[0]['active'] ?? null)
                    || !is_array($rows[0]['fields'] ?? null) || !array_is_list($rows[0]['fields'])) {
                    $appProbe['status'] = 'INVALID_RESPONSE';
                } else {
                    $subscription['whatsapp_object_present'] = true; $subscription['active'] = $rows[0]['active'];
                    $valid = true;
                    foreach ($rows[0]['fields'] as $field) {
                        $name = is_string($field) ? $field : (is_array($field) ? ($field['name'] ?? null) : null);
                        if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name)) { $valid = false; break; }
                        if (in_array($name, self::FIELDS, true)) $subscription['field_names'][] = $name;
                        else $subscription['unknown_field_count']++;
                    }
                    if (!$valid) {
                        $appProbe['status'] = 'INVALID_RESPONSE'; $subscription['field_names'] = [];
                        $subscription['unknown_field_count'] = 0;
                    } else {
                        $subscription['field_names'] = array_values(array_unique($subscription['field_names']));
                        sort($subscription['field_names']);
                        $subscription['standby_subscribed'] = in_array('standby', $subscription['field_names'], true);
                    }
                }
            }
            [$wabaProbe, $wabaBody] = $this->probe(self::WABA . '/subscribed_apps?limit=100', $token);
            $appMatches = null; $wabaComplete = null;
            if ($wabaBody !== null) {
                $wabaComplete = empty($wabaBody['paging']['next']); $found = false; $valid = true;
                foreach ($wabaBody['data'] as $row) {
                    $id = is_array($row) ? ($row['whatsapp_business_api_data']['id'] ?? null) : null;
                    if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,29}\z/', $id)) { $valid = false; break; }
                    if ($id === self::APP) $found = true;
                }
                if (!$valid) $wabaProbe['status'] = 'INVALID_RESPONSE';
                else $appMatches = $found ? true : ($wabaComplete ? false : null);
            }
            return ['diagnostic' => 'READ_ONLY_STANDBY_SUBSCRIPTION',
                'status' => $appProbe['status'] === 'READY' && $wabaProbe['status'] === 'READY' ? 'READY' : 'PARTIAL',
                'network_requests' => $this->requests, 'writes' => 0,
                'app_subscription' => $subscription, 'waba_subscription' =>
                    ['app_matches' => $appMatches, 'complete' => $wabaComplete],
                'probes' => ['APP_SUBSCRIPTIONS' => $appProbe, 'WABA_SUBSCRIBED_APPS' => $wabaProbe],
                'standby_visibility' => ['status' => 'BUSINESS_UI_REQUIRED',
                    'subscription_alone_is_sufficient' => false, 'api_visibility_checked' => false,
                    'required_business_settings' => ['STANDBY_VISIBILITY', 'META_BUSINESS_AGENTS_PLATFORM_TERMS']]];
        }
    }
}

namespace {
    if (defined('WHATSAPP_STANDBY_DIAGNOSTIC_LIBRARY')) return;
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    ini_set('display_errors', '0'); ini_set('display_startup_errors', '0'); ini_set('log_errors', '0');
    ob_start();
    $finish = static function (array $report): void {
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    };
    try {
        $owner = function_exists('posix_getpwnam') ? posix_getpwnam('fasakha') : false;
        if (!is_array($owner) || !function_exists('posix_geteuid') || posix_geteuid() !== $owner['uid']) {
            $finish(['diagnostic' => 'READ_ONLY_STANDBY_SUBSCRIPTION', 'status' => 'WRONG_PROCESS_OWNER',
                'network_requests' => 0, 'writes' => 0]); exit(1);
        }
        $root = '/home/fasakha/public_html';
        $private = static function (string $path) use ($owner): void {
            $s = @lstat($path);
            if (!$s || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== $owner['uid']
                || ($s['mode'] & 0077) || $s['nlink'] !== 1 || $s['size'] < 1 || $s['size'] > 16777216) {
                throw new \RuntimeException();
            }
        };
        $private($root . '/.env');
        if (file_exists($root . '/bootstrap/cache/config.php')) $private($root . '/bootstrap/cache/config.php');
        if (!chdir($root)) throw new \RuntimeException();
        require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $secret = config('whatsapp.app_secret'); $token = config('whatsapp_replies.access_token');
        $allowed = config('whatsapp.allowed_account_ids', []);
        $allowedMatches = is_array($allowed) && in_array(\App\Support\WhatsAppStandbySubscriptionDiagnostic::WABA, $allowed, true);
        if (!$allowedMatches || !is_string($secret) || !is_string($token)) {
            $finish(['diagnostic' => 'READ_ONLY_STANDBY_SUBSCRIPTION', 'status' => 'INVALID_CONFIGURATION',
                'network_requests' => 0, 'writes' => 0,
                'configuration' => ['allowed_account_matches' => $allowedMatches]]); exit(1);
        }
        $sources = [];
        foreach (['app/Http/Controllers/Api/V1/WhatsAppWebhookController.php',
            'app/Support/WhatsAppWebhookProtocol.php'] as $path) {
            $s = @lstat($root . '/' . $path);
            if (!$s || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== $owner['uid']
                || ($s['mode'] & 0022) || $s['size'] < 1 || $s['size'] > 16777216) throw new \RuntimeException();
            $bytes = file_get_contents($root . '/' . $path);
            $sources[$path] = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
        }
        $report = (new \App\Support\WhatsAppStandbySubscriptionDiagnostic())->inspect($secret, $token);
        unset($secret, $token);
        $report['configuration'] = ['allowed_account_matches' => $allowedMatches,
            'graph_version' => 'v25.0', 'graph_version_valid' => true,
            'app_id_source' => 'VERIFIED_DEPLOYMENT_CONSTANT'];
        $report['live_source_hashes'] = $sources;
        $finish($report); exit(0);
    } catch (\Throwable $error) {
        $finish(['diagnostic' => 'READ_ONLY_STANDBY_SUBSCRIPTION', 'status' => 'CHECK_FAILED',
            'network_requests' => 0, 'writes' => 0]); exit(1);
    }
}
