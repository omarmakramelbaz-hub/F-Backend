<?php

namespace App\Support {
    /** Read-only catalog inspection. Candidates are proposals, never persisted mappings. */
    final class WhatsAppCatalogMappingReadiness
    {
        private const BASE = 'https://graph.facebook.com/v25.0/';
        private const MAX_BYTES = 1048576;
        private const MAX_PRODUCTS = 300;
        private $transport;

        /** Transport injection is only used by isolated fixtures. */
        public function __construct(?callable $transport = null) { $this->transport = $transport; }

        public function products(string $catalogId, string $token): array
        {
            $base = ['status' => 'INVALID_INPUT', 'http' => null, 'api_error_code' => null,
                'pages_read' => 0, 'complete' => false, 'products' => []];
            if (!preg_match('/\A[1-9][0-9]{0,29}\z/', $catalogId)
                || $token === '' || strlen($token) > 4096 || preg_match('/\s/', $token)) return $base;
            $after = null;
            $cursors = [];
            $retailers = [];
            for ($page = 0; $page < 3; $page++) {
                // Never follow the Graph next URL: it can contain an access token or another host.
                $url = self::BASE . $catalogId . '/products?fields=id%2Cretailer_id%2Cname&limit=100';
                if ($after !== null) $url .= '&after=' . rawurlencode($after);
                try {
                    $response = $this->transport ? ($this->transport)($url, $token) : $this->request($url, $token);
                } catch (\Throwable $e) {
                    $base['status'] = 'TRANSPORT_ERROR'; return $base;
                }
                $base['pages_read']++;
                $http = $response['status'] ?? null;
                $bytes = $response['body'] ?? null;
                $base['http'] = is_int($http) && $http >= 100 && $http <= 599 ? $http : null;
                if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) {
                    $base['status'] = 'INVALID_RESPONSE'; return $base;
                }
                try { $body = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); }
                catch (\Throwable $e) { $base['status'] = 'INVALID_RESPONSE'; return $base; }
                if (!is_array($body)) { $base['status'] = 'INVALID_RESPONSE'; return $base; }
                if ($http !== 200 || isset($body['error'])) {
                    $code = $body['error']['code'] ?? null;
                    $base['api_error_code'] = is_int($code) ? $code : null;
                    $base['status'] = in_array($code, [10, 200], true) ? 'CATALOG_ACCESS_DENIED' : 'API_ERROR';
                    return $base;
                }
                if (!is_array($body['data'] ?? null) || !array_is_list($body['data']) || count($body['data']) > 100) {
                    $base['status'] = 'INVALID_RESPONSE'; return $base;
                }
                foreach ($body['data'] as $row) {
                    if (!is_array($row) || !is_string($row['id'] ?? null)
                        || !preg_match('/\A[1-9][0-9]{0,29}\z/', $row['id'])
                        || !self::publicText($row['retailer_id'] ?? null, 200)
                        || trim($row['retailer_id']) !== $row['retailer_id']
                        || !self::publicText($row['name'] ?? null, 500)
                        || strpos($row['retailer_id'], $token) !== false || strpos($row['name'], $token) !== false
                        || isset($retailers[$row['retailer_id']])) {
                        $base['status'] = 'INVALID_PRODUCT_IDENTITY'; return $base;
                    }
                    $retailers[$row['retailer_id']] = true;
                    $base['products'][] = ['id' => $row['id'], 'retailer_id' => $row['retailer_id'], 'name' => $row['name']];
                }
                $paging = $body['paging'] ?? [];
                if (!is_array($paging)) { $base['status'] = 'INVALID_PAGING'; return $base; }
                if (empty($paging['next'])) {
                    $base['status'] = 'READY'; $base['complete'] = true; return $base;
                }
                if ($page === 2 || count($base['products']) >= self::MAX_PRODUCTS) {
                    $base['status'] = 'CATALOG_TRUNCATED'; return $base;
                }
                $after = $paging['cursors']['after'] ?? null;
                if (!is_string($after) || $after === '' || strlen($after) > 2048
                    || preg_match('/[^\x21-\x7e]/', $after) || strpos($after, $token) !== false
                    || isset($cursors[$after]) || $body['data'] === []) {
                    $base['status'] = 'INVALID_PAGING'; return $base;
                }
                $cursors[$after] = true;
            }
            return $base;
        }

        public function candidates(array $products, array $erpItems, string $branch): array
        {
            if (!preg_match('/\Af:[1-9][0-9]{0,18}\z/', $branch) || count($products) > 300 || count($erpItems) > 300) {
                throw new \InvalidArgumentException('INVALID_CATALOG_SCOPE');
            }
            $report = [];
            foreach ($products as $product) {
                if (!is_array($product) || !self::publicText($product['retailer_id'] ?? null, 200)
                    || trim($product['retailer_id']) !== $product['retailer_id']
                    || !self::publicText($product['name'] ?? null, 500)) throw new \InvalidArgumentException('INVALID_CATALOG_PRODUCT');
                $name = self::name($product['name']);
                $portion = self::portion($name);
                $matches = [];
                foreach ($erpItems as $item) {
                    if (!is_array($item) || !is_int($item['id'] ?? null) || $item['id'] < 1
                        || !self::publicText($item['name'] ?? null, 500) || ($item['available'] ?? false) !== true) continue;
                    $exact = self::name($item['name']) === $name;
                    $weight = ($item['quantity_mode'] ?? null) === 'weight';
                    $piece = ($item['quantity_mode'] ?? null) === 'piece';
                    $select = ($item['quantity_mode'] ?? null) === 'select';
                    $selectPortion = $select && $portion !== null && !self::packaged($name)
                        && !self::packaged(self::name($item['name'])) && self::name($item['name']) === $portion['base'];
                    $basePortionMatch = ($weight || $select) && $portion !== null
                        && self::name($item['name']) === $portion['base'];
                    if (!$exact && !$basePortionMatch) continue;
                    $quantity = $exact && $piece ? '1' : ($weight && $portion !== null ? $portion['quantity'] : null);
                    $matches[] = ['branch' => $branch, 'product_id' => $item['id'], 'name' => $item['name'],
                        'match' => $exact ? 'EXACT_TITLE' : 'EXACT_TITLE_WITH_EXPLICIT_WEIGHT',
                        'quantity_mode' => $piece ? 'piece' : ($weight ? 'weight' : null),
                        'erp_quantity_mode' => $item['quantity_mode'] ?? null,
                        'mode_resolution' => $select ? 'SELECT_REQUIRES_CHOICE' : 'TYPED_CATALOG_MODE',
                        'quantity_per_unit' => $quantity, 'option_id' => '',
                        'unit_semantics_ready' => $quantity !== null, '_select_portion' => $selectPortion];
                }
                // A select-mode proposal is allowed only for one exact base identity and an explicit raw weight.
                // Packaged portions and multiple branch products stay unresolved; nothing is written here.
                foreach ($matches as &$match) {
                    if (count($matches) === 1 && $match['_select_portion']) {
                        $match['quantity_mode'] = 'weight'; $match['quantity_per_unit'] = $portion['quantity'];
                        $match['unit_semantics_ready'] = true;
                    }
                    unset($match['_select_portion']);
                }
                unset($match);
                $status = count($matches) === 0 ? 'NO_EXACT_MATCH' : (count($matches) !== 1 ? 'AMBIGUOUS_MATCH'
                    : ($matches[0]['unit_semantics_ready'] ? 'CANDIDATE_REQUIRES_MAPPING' : 'UNIT_SEMANTICS_REQUIRED'));
                $report[] = ['retailer_id' => $product['retailer_id'], 'catalog_name' => $product['name'],
                    'status' => $status, 'candidate_count' => count($matches),
                    'candidates_truncated' => count($matches) > 5, 'candidates' => array_slice($matches, 0, 5)];
            }
            return $report;
        }

        private static function portion(string $name): ?array
        {
            $phrases = ['ربع كيلو' => '0.25', 'نصف كيلو' => '0.5', 'نص كيلو' => '0.5', 'كيلو' => '1',
                '250 جرام' => '0.25', '500 جرام' => '0.5', '1000 جرام' => '1',
                '٠.٢٥ كيلو' => '0.25', '٠.٥ كيلو' => '0.5', '0.25 كيلو' => '0.25', '0.5 كيلو' => '0.5'];
            $found = [];
            foreach ($phrases as $phrase => $quantity) {
                if (str_starts_with($name, $phrase . ' ')) $found[] = ['base' => substr($name, strlen($phrase) + 1), 'quantity' => $quantity];
                if (str_ends_with($name, ' ' . $phrase)) $found[] = ['base' => substr($name, 0, -strlen($phrase) - 1), 'quantity' => $quantity];
            }
            // Overlapping suffixes such as "فسيخ ربع كيلو" also match "كيلو". Prefer the longest explicit phrase.
            if (!$found) return null;
            usort($found, static fn ($a, $b) => strlen($a['base']) <=> strlen($b['base']));
            $best = $found[0];
            foreach ($phrases as $phrase => $quantity) {
                if (str_starts_with($best['base'], $phrase . ' ') || str_ends_with($best['base'], ' ' . $phrase)) return null;
            }
            return $best;
        }

        private static function name(string $value): string
        {
            return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
        }

        private static function packaged(string $value): bool
        {
            return preg_match('/(?:\A|[^\p{L}])(?:وجبة|وجبات|علبة|علب|عبوة|عبوات|سندوتش|سندويتش|ساندوتش|ساندويتش|ميكس|كيس|أكياس|اكياس|كرتونة|كرتونه|باكت|باكيت|pack|meal|can|jar|box|sandwich|bundle|portion)(?:[^\p{L}]|\z)/iu', $value) === 1;
        }

        private static function publicText($value, int $maximum): bool
        {
            return is_string($value) && $value !== '' && strlen($value) <= $maximum
                && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/u', $value) !== 1;
        }

        private function request(string $url, string $token): array
        {
            if (!function_exists('curl_init')) throw new \RuntimeException('TRANSPORT_UNAVAILABLE');
            $body = '';
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) return 0;
                    $body .= $chunk; return strlen($chunk);
                }]);
            try {
                $ok = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($ok === false) throw new \RuntimeException('TRANSPORT_ERROR');
                return ['status' => $status, 'body' => $body];
            } finally { curl_close($curl); }
        }
    }
}

namespace {
    // Allows fixture tests to load the inspector without booting a live Laravel application.
    if (defined('WHATSAPP_CATALOG_READINESS_LIBRARY_ONLY') && WHATSAPP_CATALOG_READINESS_LIBRARY_ONLY === true) return;
    ini_set('display_errors', '0');
    try {
        if (PHP_SAPI !== 'cli' || $argc !== 3
            || !preg_match('/\A--catalog=([1-9][0-9]{0,29})\z/', $argv[1], $catalogMatch)
            || !preg_match('/\A--branch=([1-9][0-9]{0,18})\z/', $argv[2], $branchMatch)) throw new \RuntimeException();
        if (!chdir('/home/fasakha/public_html')) throw new \RuntimeException();
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $actor = \App\Models\User::withoutGlobalScopes()->find(1);
        $actor = app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
        $access = app(\App\Services\Dashboard\TakeawayAccess::class);
        if (($access->permissions($actor)['can_checkout'] ?? false) !== true) throw new \RuntimeException();
        $branch = $access->branch('f:' . $branchMatch[1], $actor);
        if (($branch['kind'] ?? null) !== 'f') throw new \RuntimeException();
        $inspector = new \App\Support\WhatsAppCatalogMappingReadiness();
        $token = config('whatsapp_replies.access_token');
        if (!is_string($token) || $token === $catalogMatch[1] || $token === $branchMatch[1]) throw new \RuntimeException();
        $result = $inspector->products($catalogMatch[1], $token);
        unset($token);
        $items = [];
        $erpComplete = false;
        if ($result['complete']) {
            $service = app(\App\Services\Dashboard\TakeawayCatalog::class);
            for ($page = 1; $page <= 3; $page++) {
                $listing = $service->listing(['branch' => $branch['value'], 'per_page' => 100, 'page' => $page], $actor);
                if (!is_array($listing['items'] ?? null) || count($listing['items']) > 100) throw new \RuntimeException();
                $items = array_merge($items, $listing['items']);
                $last = $listing['pagination']['last_page'] ?? null;
                if (!is_int($last) || $last < 1) throw new \RuntimeException();
                if ($last === $page) { $erpComplete = true; break; }
            }
        }
        $report = ['diagnostic' => 'READ_ONLY_CATALOG_MAPPING', 'catalog_id' => $catalogMatch[1],
            'branch' => $branch['value'], 'actor_id' => (int) $actor->id, 'graph_status' => $result['status'],
            'graph_http' => $result['http'], 'graph_error_code' => $result['api_error_code'],
            'graph_pages_read' => $result['pages_read'], 'graph_complete' => $result['complete'],
            'catalog_products' => count($result['products']), 'erp_products' => count($items),
            'erp_complete' => $erpComplete, 'mappings_written' => 0,
            'candidates' => $result['complete'] && $erpComplete ? $inspector->candidates($result['products'], $items, $branch['value']) : []];
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    } catch (\Throwable $e) {
        echo "CATALOG_MAPPING_CHECK_FAILED\n";
        exit(1);
    }
}
