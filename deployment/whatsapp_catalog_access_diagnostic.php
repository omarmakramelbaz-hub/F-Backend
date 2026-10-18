<?php

namespace App\Support {
    /** Fixed read-only Graph probes; response values and credentials never leave this class. */
    final class WhatsAppCatalogAccessDiagnostic
    {
        public const CATALOG = '627343999792522';
        public const APP = '2177982783065133';
        public const WABA = '468336579702269';
        private const BASE = 'https://graph.facebook.com/v25.0/';
        private const MAX_BYTES = 1048576;
        private $transport;
        public function __construct(?callable $transport = null) { $this->transport = $transport; }

        private function read(string $path, string $bearer): array
        {
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

        private static function code($value): ?int
        {
            return is_int($value) && $value >= 0 && $value <= 2147483647 ? $value : null;
        }

        private function probe(string $label, string $path, string $bearer): array
        {
            $safe = ['probe' => $label, 'status' => 'TRANSPORT_ERROR', 'http' => null,
                'code' => null, 'subcode' => null, 'error_type' => 'NONE',
                'unsupported_get_indication' => false, 'object_unavailable_indication' => false,
                'permission_indication' => false, 'field_indication' => false,
                'invalid_token_indication' => false, 'rate_limit_indication' => false];
            try {
                $response = $this->read($path, $bearer);
                $http = $response['status'] ?? null;
                $safe['http'] = is_int($http) && $http >= 100 && $http <= 599 ? $http : null;
                $bytes = $response['body'] ?? null;
                if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) throw new \UnexpectedValueException();
                $body = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($body)) throw new \UnexpectedValueException();
                if (array_key_exists('error', $body) && !is_array($body['error'])) throw new \UnexpectedValueException();
                $error = is_array($body['error'] ?? null) ? $body['error'] : null;
                if ($http !== 200 || $error !== null) {
                    $safe['status'] = 'API_ERROR';
                    $safe['code'] = self::code($error['code'] ?? null);
                    $safe['subcode'] = self::code($error['error_subcode'] ?? null);
                    $types = ['OAuthException' => 'OAUTH_EXCEPTION', 'GraphMethodException' => 'GRAPH_METHOD_EXCEPTION'];
                    $type = is_string($error['type'] ?? null) ? $error['type'] : '';
                    $safe['error_type'] = $error === null ? 'NONE' : ($types[$type] ?? 'OTHER');
                    $message = is_string($error['message'] ?? null) ? strtolower($error['message']) : '';
                    $safe['unsupported_get_indication'] = strpos($message, 'unsupported get request') !== false;
                    $safe['object_unavailable_indication'] = strpos($message, 'does not exist') !== false;
                    $safe['permission_indication'] = in_array($safe['code'], [10, 200], true)
                        || preg_match('/missing (?:scopes|permissions)|insufficient permissions|does not have permission|lack(?:ing)? permissions/', $message) === 1;
                    $safe['field_indication'] = preg_match('/nonexist(?:ing|ent) field|unknown field|cannot query field|not a valid field|does not support (?:the )?field/', $message) === 1;
                    $safe['invalid_token_indication'] = $safe['code'] === 190;
                    $safe['rate_limit_indication'] = $http === 429 || in_array($safe['code'], [4, 17, 32, 613], true);
                    return ['safe' => $safe, 'body' => null];
                }
                $safe['status'] = 'READY';
                return ['safe' => $safe, 'body' => $body];
            } catch (\Throwable $error) {
                $safe['status'] = $error instanceof \JsonException || $error instanceof \UnexpectedValueException
                    ? 'INVALID_RESPONSE' : 'TRANSPORT_ERROR';
                return ['safe' => $safe, 'body' => null];
            }
        }

        private static function successfulShape(array &$probe, bool $valid): void
        {
            if ($probe['safe']['status'] === 'READY' && !$valid) {
                $probe['safe']['status'] = 'INVALID_RESPONSE'; $probe['body'] = null;
            }
        }

        public function inspect(string $token, ?string $secret = null): array
        {
            $out = ['diagnostic' => 'READ_ONLY_CATALOG_ACCESS', 'catalog_id' => self::CATALOG,
                'status' => 'READY', 'network_requests' => 0, 'writes' => 0,
                'token_scope_check' => ['status' => 'APP_SECRET_UNAVAILABLE', 'token_valid' => null,
                    'app_matches' => null, 'catalog_management' => null, 'business_management' => null,
                    'whatsapp_business_management' => null, 'whatsapp_business_messaging' => null],
                'catalog_association' => ['status' => 'UNAVAILABLE', 'present' => null, 'complete' => null,
                    'catalogs_on_page' => null, 'absence_means' => 'NOT_RETURNED_BY_THIS_TOKEN'],
                'probes' => [], 'conclusion' => 'ACCESS_OR_REQUEST_UNRESOLVED'];
            if ($token === '' || strlen($token) > 4096 || preg_match('/\s/', $token)) {
                $out['status'] = 'INVALID_TOKEN_INPUT'; return $out;
            }
            if (is_string($secret) && preg_match('/\A[a-fA-F0-9]{32}\z/', $secret)) {
                $debug = $this->probe('TOKEN_SCOPES', 'debug_token?input_token=' . rawurlencode($token), self::APP . '|' . $secret);
                $out['network_requests']++; $out['probes'][] = $debug['safe'];
                $data = $debug['body']['data'] ?? null;
                $scopes = is_array($data) && is_array($data['scopes'] ?? null) && array_is_list($data['scopes'])
                    && count($data['scopes']) <= 100 ? $data['scopes'] : null;
                foreach ($scopes ?? [] as $scope) {
                    if (!is_string($scope) || !preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $scope)) { $scopes = null; break; }
                }
                $valid = is_array($data) && is_bool($data['is_valid'] ?? null)
                    && ($data['is_valid'] === false || (is_string($data['app_id'] ?? null)
                        && preg_match('/\A[1-9][0-9]{0,29}\z/', $data['app_id']) && $scopes !== null));
                $out['token_scope_check']['status'] = $debug['safe']['status'] === 'READY'
                    ? ($valid ? 'READY' : 'INVALID_RESPONSE') : $debug['safe']['status'];
                if ($valid) {
                    $out['token_scope_check']['token_valid'] = $data['is_valid'];
                    $out['token_scope_check']['app_matches'] = is_string($data['app_id'] ?? null) ? $data['app_id'] === self::APP : null;
                    foreach (['catalog_management', 'business_management', 'whatsapp_business_management', 'whatsapp_business_messaging'] as $scope) {
                        $out['token_scope_check'][$scope] = $scopes === null ? null : in_array($scope, $scopes, true);
                    }
                }
            }
            $association = $this->probe('WABA_CATALOG_ASSOCIATION', self::WABA . '/product_catalogs?fields=id&limit=10', $token);
            $rows = $association['body']['data'] ?? null;
            $paging = array_key_exists('paging', $association['body'] ?? []) ? $association['body']['paging'] : [];
            $valid = is_array($rows) && array_is_list($rows) && count($rows) <= 10 && is_array($paging);
            if (is_array($paging) && array_key_exists('next', $paging)) {
                $valid = $valid && is_string($paging['next']) && $paging['next'] !== '' && strlen($paging['next']) <= 8192;
            }
            $present = false;
            foreach (is_array($rows) ? $rows : [] as $row) {
                $valid = $valid && is_array($row) && is_string($row['id'] ?? null) && preg_match('/\A[1-9][0-9]{0,29}\z/', $row['id']);
                $present = $present || (is_array($row) && ($row['id'] ?? null) === self::CATALOG);
            }
            self::successfulShape($association, (bool) $valid);
            $out['network_requests']++; $out['probes'][] = $association['safe'];
            $out['catalog_association']['status'] = $association['safe']['status'];
            if ($association['safe']['status'] === 'READY') {
                $complete = empty($paging['next']);
                $out['catalog_association']['present'] = $present ? true : ($complete ? false : null);
                $out['catalog_association']['complete'] = $complete;
                $out['catalog_association']['catalogs_on_page'] = count($rows);
            }
            $object = $this->probe('CATALOG_OBJECT', self::CATALOG . '?fields=id%2Cname', $token);
            self::successfulShape($object, ($object['body']['id'] ?? null) === self::CATALOG && is_string($object['body']['name'] ?? null));
            $minimal = $this->probe('PRODUCTS_MINIMAL', self::CATALOG . '/products?fields=id&limit=1', $token);
            $fields = $this->probe('PRODUCTS_FIELDS', self::CATALOG . '/products?fields=id%2Cretailer_id%2Cname&limit=1', $token);
            foreach ([$minimal, $fields] as $index => $productProbe) {
                $rows = $productProbe['body']['data'] ?? null;
                $valid = is_array($rows) && array_is_list($rows) && count($rows) <= 1;
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $valid = $valid && is_array($row) && is_string($row['id'] ?? null)
                        && preg_match('/\A[1-9][0-9]{0,29}\z/', $row['id']);
                    if ($index === 1) $valid = $valid && is_string($row['retailer_id'] ?? null) && is_string($row['name'] ?? null);
                }
                if ($index === 0) self::successfulShape($minimal, $valid); else self::successfulShape($fields, $valid);
            }
            $out['network_requests'] += 3;
            foreach ([$object, $minimal, $fields] as $probe) $out['probes'][] = $probe['safe'];
            if ($fields['safe']['status'] === 'READY') $out['conclusion'] = 'REQUESTED_PRODUCTS_READ_WORKS';
            elseif ($minimal['safe']['status'] === 'READY') $out['conclusion'] = 'FIELD_OR_EDGE_REQUEST_REQUIRES_REVIEW';
            elseif ($object['safe']['status'] === 'READY') $out['conclusion'] = 'CATALOG_VISIBLE_PRODUCTS_EDGE_UNAVAILABLE';
            if ($out['token_scope_check']['token_valid'] === false
                || array_filter($out['probes'], static fn ($p) => $p['invalid_token_indication'])) $out['conclusion'] = 'TOKEN_INVALID_OR_EXPIRED';
            if (array_filter($out['probes'], static fn ($p) => $p['status'] !== 'READY')
                || $out['token_scope_check']['status'] === 'INVALID_RESPONSE') $out['status'] = 'PARTIAL';
            return $out;
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (defined('WHATSAPP_CATALOG_ACCESS_DIAGNOSTIC_LIBRARY_ONLY') && WHATSAPP_CATALOG_ACCESS_DIAGNOSTIC_LIBRARY_ONLY === true) return;
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    try {
        if ($argc !== 1 || !chdir('/home/fasakha/public_html')) throw new \RuntimeException();
        require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $token = config('whatsapp_replies.access_token'); $secret = config('whatsapp.app_secret');
        if (!is_string($token)) throw new \RuntimeException();
        $report = (new \App\Support\WhatsAppCatalogAccessDiagnostic())->inspect($token, is_string($secret) ? $secret : null);
        unset($token, $secret); $exit = 0;
    } catch (\Throwable $error) { $report = ['diagnostic' => 'READ_ONLY_CATALOG_ACCESS', 'status' => 'FAILED', 'writes' => 0]; $exit = 1; }
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($exit);
}
