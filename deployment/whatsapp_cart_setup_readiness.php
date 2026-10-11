<?php

namespace App\Support {
    /** Public catalog inspection only; no customer identities, AI calls or ERP writes. */
    final class WhatsAppCartSetupReadiness
    {
        public const WABA = '468336579702269';
        public const PHONE = '515388018324075';
        public const BRANCHES = ['94', '301', '307', '363'];
        public const ROW_LIMIT = 500;
        private const CATALOG_LIMIT = 2;
        private const PRODUCT_LIMIT = 100;
        private const OPTION_LIMIT = 50;
        private const MAX_BYTES = 4194304;
        private const HELPER_BLOB = '08ffddacee57b3d1a46056a67c56e519143a3cd4';
        private const OUTPUT_LIMIT = 524288;
        private $catalog;
        private $erp;

        public function __construct(callable $catalog, callable $erp)
        {
            $this->catalog = $catalog;
            $this->erp = $erp;
        }

        public static function arguments(array $arguments): array
        {
            $out = ['after_event' => 0, 'through_event' => null]; $seen = [];
            if (count($arguments) > 2) throw new \InvalidArgumentException('INVALID_ARGUMENTS');
            foreach ($arguments as $argument) {
                if (!is_string($argument)
                    || !preg_match('/\A--(after|through)-event=(0|[1-9][0-9]{0,17})\z/', $argument, $match)
                    || isset($seen[$match[1]])) throw new \InvalidArgumentException('INVALID_ARGUMENTS');
                $seen[$match[1]] = true; $value = (int) $match[2];
                if ($match[1] === 'through' && $value === 0) throw new \InvalidArgumentException('INVALID_ARGUMENTS');
                $out[$match[1] . '_event'] = $value;
            }
            if ($out['through_event'] !== null && $out['through_event'] <= $out['after_event']) {
                throw new \InvalidArgumentException('INVALID_ARGUMENTS');
            }
            return $out;
        }

        /** The staged sibling is checked before PHP evaluates any of its code. */
        public static function loadSibling(string $directory, int $uid): void
        {
            $directoryInfo = @lstat($directory);
            $path = $directory . '/whatsapp_catalog_mapping_readiness.php';
            $before = @lstat($path);
            if (!$directoryInfo || ($directoryInfo['mode'] & 0170000) !== 0040000
                || !in_array($directoryInfo['uid'], [0, $uid], true) || ($directoryInfo['mode'] & 0022) !== 0
                || !$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
                || !in_array($before['uid'], [0, $uid], true) || ($before['mode'] & 0022) !== 0
                || $before['size'] > 131072) throw new \RuntimeException('UNSAFE_CATALOG_HELPER');
            $bytes = @file_get_contents($path);
            clearstatcache(true, $path);
            $after = @lstat($path);
            $same = is_array($after);
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
                $same = $same && $before[$key] === ($after[$key] ?? null);
            }
            if (!is_string($bytes) || !$same
                || !hash_equals(self::HELPER_BLOB, sha1('blob ' . strlen($bytes) . "\0" . $bytes))) {
                throw new \RuntimeException('UNVERIFIED_CATALOG_HELPER');
            }
            if (!defined('WHATSAPP_CATALOG_READINESS_LIBRARY_ONLY')) define('WHATSAPP_CATALOG_READINESS_LIBRARY_ONLY', true);
            if (WHATSAPP_CATALOG_READINESS_LIBRARY_ONLY !== true) throw new \RuntimeException('INVALID_HELPER_MODE');
            if (!class_exists(WhatsAppCatalogMappingReadiness::class, false)) require_once $path;
        }

        private static function list($value): bool
        {
            return is_array($value) && array_is_list($value);
        }

        private static function text($value, int $bytes): bool
        {
            return is_string($value) && $value !== '' && strlen($value) <= $bytes
                && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/u', $value) !== 1
                && preg_match('/(?:EAA[A-Za-z0-9_-]{15,}|sk-[A-Za-z0-9_-]{15,})/', $value) !== 1;
        }

        /** Inspect only the standard order keys, never message text or customer fields. */
        public static function order($order): ?array
        {
            if (!is_array($order) || !is_string($order['catalog_id'] ?? null)
                || !preg_match('/\A[1-9][0-9]{0,29}\z/', $order['catalog_id'])
                || !self::list($order['product_items'] ?? null) || $order['product_items'] === []
                || count($order['product_items']) > 100) return null;
            $retailers = [];
            foreach ($order['product_items'] as $item) {
                $id = is_array($item) ? ($item['product_retailer_id'] ?? null) : null;
                if (!self::text($id, 200) || trim($id) !== $id) return null;
                $retailers['r:' . $id] = $id;
            }
            return ['catalog_id' => $order['catalog_id'], 'retailer_ids' => array_values($retailers)];
        }

        public static function rawOrders(array $payload): array
        {
            if (($payload['object'] ?? null) !== 'whatsapp_business_account'
                || !self::list($payload['entry'] ?? null) || count($payload['entry']) > 100) return [];
            $orders = [];
            foreach ($payload['entry'] as $entry) {
                if (!is_array($entry) || ($entry['id'] ?? null) !== self::WABA
                    || !self::list($entry['changes'] ?? null) || count($entry['changes']) > 100) continue;
                foreach ($entry['changes'] as $change) {
                    if (!is_array($change) || !in_array($change['field'] ?? null, ['messages', 'standby'], true)) continue;
                    $value = $change['value'] ?? null;
                    if (!is_array($value) || ($value['messaging_product'] ?? null) !== 'whatsapp'
                        || ($value['metadata']['phone_number_id'] ?? null) !== self::PHONE) continue;
                    $nodes = [$value];
                    if (is_array($value['standby'] ?? null)) $nodes[] = $value['standby'];
                    foreach ($nodes as $node) {
                        if (!self::list($node['messages'] ?? null) || count($node['messages']) > 100) continue;
                        foreach ($node['messages'] as $message) {
                            if (!is_array($message) || ($message['type'] ?? null) !== 'order') continue;
                            $order = self::order($message['order'] ?? null);
                            if ($order !== null) $orders[] = $order;
                            if (count($orders) >= 100) return $orders;
                        }
                    }
                }
            }
            return $orders;
        }

        /** Caller must enforce the persisted conversation scope before decrypting a stored DTO. */
        public static function projectedOrder(array $dto): ?array
        {
            if (($dto['type'] ?? null) !== 'order' || ($dto['direction'] ?? null) !== 'inbound'
                || !in_array($dto['source'] ?? null, ['messages', 'standby'], true)
                || ($dto['content']['message']['type'] ?? null) !== 'order') return null;
            return self::order($dto['content']['message']['order'] ?? null);
        }

        public static function decode($ciphertext): array
        {
            if (!is_string($ciphertext) || strlen($ciphertext) > self::MAX_BYTES * 2) throw new \RuntimeException('INVALID_PAYLOAD');
            $bytes = \Illuminate\Support\Facades\Crypt::decryptString($ciphertext);
            if (strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException('INVALID_PAYLOAD');
            $body = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body)) throw new \RuntimeException('INVALID_PAYLOAD');
            return $body;
        }

        /** Bounded local discovery includes old projected carts and recent unprojected capture. */
        public static function discover(int $afterEvent, ?int $throughEvent = null): array
        {
            $db = \Illuminate\Support\Facades\DB::class;
            $catalogs = []; $catalogObservationsOmitted = 0; $retailerObservationsOmitted = 0;
            $add = static function (array $order) use (&$catalogs, &$catalogObservationsOmitted, &$retailerObservationsOmitted): void {
                $catalog = $order['catalog_id'];
                if (!isset($catalogs[$catalog]) && count($catalogs) >= 20) { $catalogObservationsOmitted++; return; }
                $catalogs[$catalog] ??= [];
                foreach ($order['retailer_ids'] as $id) {
                    if (isset($catalogs[$catalog]['r:' . $id])) continue;
                    if (count($catalogs[$catalog]) < 100) $catalogs[$catalog]['r:' . $id] = $id;
                    else $retailerObservationsOmitted++;
                }
            };
            $ceiling = (int) ($db::table('whatsapp_webhook_events')->max('id') ?? 0);
            if ($throughEvent !== null) $ceiling = min($ceiling, $throughEvent);
            $rawQuery = $db::table('whatsapp_webhook_events')->where('id', '>', $afterEvent)->where('id', '<=', $ceiling);
            $rawTotal = (int) (clone $rawQuery)->count();
            $rawRows = $rawQuery->orderByDesc('id')->limit(self::ROW_LIMIT)->get(['id', 'payload']);
            $unreadable = 0;
            foreach ($rawRows as $row) {
                try { foreach (self::rawOrders(self::decode($row->payload)) as $order) $add($order); }
                catch (\Throwable $error) { $unreadable++; }
            }
            $query = $db::table('whatsapp_inbox_messages as m')
                ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE)
                ->where('m.type', 'order')->where('m.direction', 'inbound');
            $messageCeiling = (int) ((clone $query)->max('m.id') ?? 0);
            $query->where('m.id', '<=', $messageCeiling);
            $projectedTotal = (int) (clone $query)->count();
            $projectedRows = $query->orderByDesc('m.id')->limit(self::ROW_LIMIT)->get(['m.content']);
            foreach ($projectedRows as $row) {
                try { $order = self::projectedOrder(self::decode($row->content)); if ($order !== null) $add($order); }
                catch (\Throwable $error) { $unreadable++; }
            }
            foreach ($catalogs as &$retailers) $retailers = array_values($retailers);
            unset($retailers);
            return ['catalogs' => $catalogs, 'event_ceiling' => $ceiling, 'after_event' => $afterEvent,
                'raw_events_in_window' => $rawTotal, 'raw_events_read' => count($rawRows),
                'raw_window_complete' => $rawTotal === count($rawRows),
                'next_through_event' => $rawTotal > count($rawRows) && count($rawRows) > 0 ? max(0, (int) $rawRows->last()->id - 1) : 0,
                'scoped_projected_orders' => $projectedTotal, 'projected_orders_read' => count($projectedRows),
                'projected_order_window_complete' => $projectedTotal === count($projectedRows),
                'unreadable_rows' => $unreadable, 'catalog_capacity_reached' => $catalogObservationsOmitted > 0,
                'observed_retailer_ids_omitted' => $retailerObservationsOmitted > 0,
                'catalog_observations_omitted' => $catalogObservationsOmitted,
                'retailer_id_observations_omitted' => $retailerObservationsOmitted];
        }

        private static function erpPublic(array $item): ?array
        {
            if (!is_int($item['id'] ?? null) || $item['id'] < 1 || !self::text($item['name'] ?? null, 500)
                || !in_array($item['quantity_mode'] ?? null, ['select', 'piece', 'weight'], true)
                || !is_bool($item['available'] ?? null) || !self::list($item['options'] ?? [])) return null;
            $options = [];
            foreach (array_slice($item['options'] ?? [], 0, self::OPTION_LIMIT) as $option) {
                if (!is_array($option) || !is_string($option['id'] ?? null)
                    || !preg_match('/\Af:(?:0|[1-9][0-9]{0,17}):(?:base|extra_clear|extra_clean|extra_vacuim)\z/', $option['id'])
                    || !self::text($option['label'] ?? null, 300)) return null;
                $options[] = ['id' => $option['id'], 'label' => $option['label']];
            }
            return ['id' => $item['id'], 'name' => $item['name'], 'quantity_mode' => $item['quantity_mode'],
                'available' => $item['available'], 'options' => $options,
                'options_omitted' => max(0, count($item['options'] ?? []) - self::OPTION_LIMIT)];
        }

        public function report(array $discovery): array
        {
            $catalogs = $discovery['catalogs'] ?? null;
            if (!is_array($catalogs) || count($catalogs) > 20) throw new \InvalidArgumentException('INVALID_DISCOVERY');
            $metadata = [];
            foreach (['event_ceiling', 'after_event', 'raw_events_in_window', 'raw_events_read',
                'scoped_projected_orders', 'projected_orders_read', 'unreadable_rows', 'next_through_event',
                'catalog_observations_omitted', 'retailer_id_observations_omitted'] as $key) {
                $metadata[$key] = is_int($discovery[$key] ?? null) && $discovery[$key] >= 0 ? $discovery[$key] : 0;
            }
            foreach (['raw_window_complete', 'projected_order_window_complete', 'catalog_capacity_reached',
                'observed_retailer_ids_omitted'] as $key) $metadata[$key] = ($discovery[$key] ?? false) === true;
            $out = ['diagnostic' => 'READ_ONLY_CART_SETUP', 'status' => 'READY', 'actor_id' => 1,
                'orders_created' => 0, 'mappings_written' => 0, 'customer_fields_disclosed' => 0,
                'cart_found' => $catalogs !== [],
                'catalogs_observed' => count($catalogs), 'catalogs_omitted' => max(0, count($catalogs) - self::CATALOG_LIMIT),
                'discovery' => $metadata, 'branches' => [], 'catalogs' => []];
            if ($out['catalogs_omitted'] || ($discovery['unreadable_rows'] ?? 0) > 0
                || ($discovery['raw_window_complete'] ?? false) !== true
                || ($discovery['projected_order_window_complete'] ?? false) !== true
                || ($discovery['catalog_capacity_reached'] ?? false) === true
                || ($discovery['observed_retailer_ids_omitted'] ?? false) === true) $out['status'] = 'PARTIAL';
            $erp = [];
            foreach (self::BRANCHES as $id) {
                $branch = 'f:' . $id;
                try {
                    $listing = ($this->erp)($branch);
                    if (!is_array($listing) || !self::list($listing['items'] ?? null)
                        || count($listing['items']) > self::PRODUCT_LIMIT
                        || !is_int($listing['pagination']['last_page'] ?? null)
                        || $listing['pagination']['last_page'] < 1) throw new \RuntimeException();
                    $items = [];
                    foreach ($listing['items'] as $item) {
                        if (!is_array($item) || ($public = self::erpPublic($item)) === null) throw new \RuntimeException();
                        $items[] = $public;
                    }
                    $optionsOmitted = array_sum(array_column($items, 'options_omitted'));
                    $complete = $listing['pagination']['last_page'] === 1 && $optionsOmitted === 0;
                    $erp[$branch] = $items;
                    $out['branches'][] = ['branch' => $branch,
                        'status' => $complete ? 'READY' : ($optionsOmitted > 0 ? 'ERP_OPTIONS_TRUNCATED' : 'ERP_TRUNCATED'),
                        'erp_complete' => $complete, 'erp_products' => count($items),
                        'options_omitted' => $optionsOmitted, 'products' => $items];
                    if (!$complete) $out['status'] = 'PARTIAL';
                } catch (\Throwable $error) {
                    $out['branches'][] = ['branch' => $branch, 'status' => 'ERP_SCOPE_OR_CATALOG_UNAVAILABLE',
                        'erp_complete' => false, 'erp_products' => 0, 'products' => []];
                    $out['status'] = 'PARTIAL';
                }
            }
            $inspector = new WhatsAppCatalogMappingReadiness();
            foreach (array_slice($catalogs, 0, self::CATALOG_LIMIT, true) as $catalog => $retailers) {
                $catalog = (string) $catalog;
                if (!preg_match('/\A[1-9][0-9]{0,29}\z/', $catalog) || !self::list($retailers) || count($retailers) > 100) {
                    throw new \InvalidArgumentException('INVALID_DISCOVERY');
                }
                foreach ($retailers as $retailer) if (!self::text($retailer, 200)) throw new \InvalidArgumentException('INVALID_DISCOVERY');
                try { $graph = ($this->catalog)($catalog); }
                catch (\Throwable $error) { $graph = ['status' => 'TRANSPORT_ERROR', 'complete' => false, 'products' => []]; }
                $allowed = ['READY', 'INVALID_INPUT', 'TRANSPORT_ERROR', 'INVALID_RESPONSE', 'INVALID_PRODUCT_IDENTITY',
                    'CATALOG_ACCESS_DENIED', 'API_ERROR', 'INVALID_PAGING', 'CATALOG_TRUNCATED'];
                $record = ['catalog_id' => $catalog, 'observed_retailer_ids' => $retailers,
                    'graph_status' => in_array($graph['status'] ?? null, $allowed, true) ? $graph['status'] : 'INVALID_RESPONSE',
                    'graph_http' => is_int($graph['http'] ?? null) ? $graph['http'] : null,
                    'graph_error_code' => is_int($graph['api_error_code'] ?? null) ? $graph['api_error_code'] : null,
                    'graph_complete' => ($graph['complete'] ?? false) === true,
                    'products_omitted' => 0, 'public_products' => [], 'branch_candidates' => []];
                $products = $graph['products'] ?? null;
                if (!self::list($products) || count($products) > 300) {
                    $record['graph_status'] = 'INVALID_RESPONSE'; $record['graph_complete'] = false; $products = [];
                }
                $public = [];
                foreach ($products as $product) {
                    if (!is_array($product) || !self::text($product['retailer_id'] ?? null, 200)
                        || trim($product['retailer_id']) !== $product['retailer_id'] || !self::text($product['name'] ?? null, 500)) {
                        $record['graph_status'] = 'INVALID_PRODUCT_IDENTITY'; $record['graph_complete'] = false; $public = []; break;
                    }
                    $public[] = ['retailer_id' => $product['retailer_id'], 'name' => $product['name']];
                }
                // Observed cart products come first; other public products follow within the output bound.
                $observed = array_fill_keys($retailers, true);
                $public = array_merge(array_values(array_filter($public, static fn ($p) => isset($observed[$p['retailer_id']]))),
                    array_values(array_filter($public, static fn ($p) => !isset($observed[$p['retailer_id']]))));
                $record['products_omitted'] = max(0, count($public) - self::PRODUCT_LIMIT);
                $record['public_products'] = array_slice($public, 0, self::PRODUCT_LIMIT);
                if ($record['graph_complete']) {
                    foreach ($out['branches'] as $branch) {
                        if (!$branch['erp_complete']) continue;
                        $record['branch_candidates'][] = ['branch' => $branch['branch'],
                            'candidates' => $inspector->candidates($record['public_products'], $erp[$branch['branch']], $branch['branch'])];
                    }
                }
                if (!$record['graph_complete'] || $record['products_omitted'] > 0) $out['status'] = 'PARTIAL';
                $out['catalogs'][] = $record;
            }
            if ($catalogs === [] && $out['status'] === 'READY') $out['status'] = 'NO_SCOPED_CART_FOUND';
            return $out;
        }

        /** Retain compact useful metadata if verbose public product details exceed the byte bound. */
        public static function output(array $report): string
        {
            $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (strlen($json) <= self::OUTPUT_LIMIT) return $json;
            $report['status'] = 'PARTIAL'; $report['output_details_omitted'] = true;
            if (isset($report['branches'])) foreach ($report['branches'] as &$branch) $branch['products'] = [];
            unset($branch);
            if (isset($report['catalogs'])) foreach ($report['catalogs'] as &$catalog) $catalog['branch_candidates'] = [];
            unset($catalog);
            $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (strlen($json) <= self::OUTPUT_LIMIT) return $json;
            $minimal = ['diagnostic' => 'READ_ONLY_CART_SETUP', 'status' => 'PARTIAL',
                'output_details_omitted' => true, 'orders_created' => 0, 'mappings_written' => 0,
                'customer_fields_disclosed' => 0, 'catalogs' => []];
            foreach (array_slice($report['catalogs'] ?? [], 0, self::CATALOG_LIMIT) as $catalog) {
                $id = $catalog['catalog_id'] ?? null;
                if (is_string($id) && preg_match('/\A[1-9][0-9]{0,29}\z/', $id)) $minimal['catalogs'][] = ['catalog_id' => $id];
            }
            return json_encode($minimal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    if (defined('WHATSAPP_CART_SETUP_READINESS_LIBRARY_ONLY') && WHATSAPP_CART_SETUP_READINESS_LIBRARY_ONLY === true) return;
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    try {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) throw new \RuntimeException();
        $options = \App\Support\WhatsAppCartSetupReadiness::arguments(array_slice($argv, 1));
        \App\Support\WhatsAppCartSetupReadiness::loadSibling(__DIR__, posix_geteuid());
        if (!chdir('/home/fasakha/public_html')) throw new \RuntimeException();
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $actor = \App\Models\User::withoutGlobalScopes()->find(1);
        $actor = app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
        $access = app(\App\Services\Dashboard\TakeawayAccess::class);
        if (($access->permissions($actor)['can_checkout'] ?? false) !== true) throw new \RuntimeException();
        $token = config('whatsapp_replies.access_token');
        if (!is_string($token) || $token === '') throw new \RuntimeException();
        $inspector = new \App\Support\WhatsAppCatalogMappingReadiness();
        $service = app(\App\Services\Dashboard\TakeawayCatalog::class);
        $reporter = new \App\Support\WhatsAppCartSetupReadiness(
            static function (string $catalog) use ($inspector, $token): array { return $inspector->products($catalog, $token); },
            static function (string $branch) use ($access, $actor, $service): array {
                $scope = $access->branch($branch, $actor);
                if (($scope['kind'] ?? null) !== 'f' || ($scope['value'] ?? null) !== $branch) throw new \RuntimeException();
                return $service->listing(['branch' => $branch, 'per_page' => 100, 'page' => 1], $actor);
            }
        );
        $report = $reporter->report(\App\Support\WhatsAppCartSetupReadiness::discover($options['after_event'], $options['through_event']));
        unset($token, $reporter); $exit = 0;
    } catch (\Throwable $error) {
        $report = ['diagnostic' => 'READ_ONLY_CART_SETUP', 'status' => 'FAILED',
            'orders_created' => 0, 'mappings_written' => 0, 'customer_fields_disclosed' => 0]; $exit = 1;
    }
    $json = \App\Support\WhatsAppCartSetupReadiness::output($report);
    while (ob_get_level() > 0) ob_end_clean();
    echo $json . "\n";
    exit($exit);
}
