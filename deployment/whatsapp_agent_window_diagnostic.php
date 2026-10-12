<?php

// Read-only CLI evidence for the existing Agent's Cairo morning window.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class WhatsAppAgentWindowDiagnostic
{
    private const ROOT = '/home/fasakha/public_html';
    private const WABA = '468336579702269';
    private const PHONE = '515388018324075';
    private const GAP_BLOB = '01aa70dc695afca5731c13892ef0e42bf8550d64';
    private const SCAN_LIMIT = 500;
    private const SCAN_BYTES = 33554432;
    private const MAX_BYTES = 4194304;
    private const DETAIL_EVENTS = 20;
    private const DETAIL_RECORDS = 40;
    private const OUTPUT_BYTES = 30720;
    private const FIELDS = ['messages', 'standby', 'smb_message_echoes', 'messaging_handovers'];
    private const TYPES = ['text', 'image', 'video', 'document', 'audio', 'sticker', 'location',
        'contacts', 'interactive', 'button', 'reaction', 'order', 'system', 'referral', 'edit',
        'revoke', 'unsupported', 'unknown'];

    public static function arguments(array $arguments): ?string
    {
        if ($arguments === []) return null;
        if (count($arguments) !== 1 || !is_string($arguments[0])
            || !preg_match('/\A--date=([0-9]{4}-[0-9]{2}-[0-9]{2})\z/', $arguments[0], $match)) throw new \RuntimeException();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new \DateTimeZone('Africa/Cairo'));
        if (!$date || $date->format('Y-m-d') !== $match[1]) throw new \RuntimeException();
        return $match[1];
    }

    private static function now(): \DateTimeImmutable
    {
        $test = getenv('WA_AGENT_WINDOW_TEST_NOW');
        if (getenv('GITHUB_ACTIONS') === 'true' && is_string($test) && $test !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $test, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $test) throw new \RuntimeException();
            return $date;
        }
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function window(?string $date, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? self::now();
        $cairo = new \DateTimeZone('Africa/Cairo'); $utc = new \DateTimeZone('UTC');
        $date = $date ?? $now->setTimezone($cairo)->format('Y-m-d');
        self::arguments(['--date=' . $date]);
        $start = new \DateTimeImmutable($date . ' 01:00:00', $cairo);
        $end = new \DateTimeImmutable($date . ' 11:00:00', $cairo);
        return ['date_cairo' => $date, 'timezone' => 'Africa/Cairo',
            'start_local_inclusive' => $start->format('Y-m-d H:i:sP'),
            'end_local_exclusive' => $end->format('Y-m-d H:i:sP'),
            'start_utc_inclusive' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc_exclusive' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'observed_at_utc' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
            'window_elapsed' => $now >= $end];
    }

    private static function loadGap(): void
    {
        $directory = __DIR__; $path = $directory . '/whatsapp_capture_gap_diagnostic.php';
        $process = @stat('/proc/self/status');
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : ($process['uid'] ?? null);
        $parent = @lstat($directory); $before = @lstat($path);
        if (!is_int($uid) || !$parent || ($parent['mode'] & 0170000) !== 0040000
            || !in_array($parent['uid'], [0, $uid], true) || ($parent['mode'] & 0022)
            || !$before || ($before['mode'] & 0170000) !== 0100000
            || !in_array($before['uid'], [0, $uid], true) || ($before['mode'] & 0022) || $before['nlink'] !== 1) throw new \RuntimeException();
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new \RuntimeException();
        try {
            $opened = fstat($handle); $source = stream_get_contents($handle, 1048577); $after = fstat($handle);
            if (!$opened || !$after || !is_string($source) || strlen($source) > 1048576) throw new \RuntimeException();
            foreach (['dev', 'ino', 'uid', 'gid', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
                if ($before[$key] !== $opened[$key] || $opened[$key] !== $after[$key]) throw new \RuntimeException();
            }
            $hash = sha1('blob ' . strlen($source) . "\0" . $source);
            if (!hash_equals(self::GAP_BLOB, $hash) || substr($source, 0, 5) !== '<?php') throw new \RuntimeException();
            // Execute verified bytes. The sibling's direct-CLI guard is false inside eval.
            eval(substr($source, 5));
        } finally { fclose($handle); }
    }

    private static function list($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    private static function rawRecipientPeers(array $record, ?string $businessPhone): array
    {
        $peers = [];
        foreach (['to', 'recipient_id'] as $key) {
            $value = $record[$key] ?? null;
            if (!is_string($value) || !preg_match('/\A\+?[0-9]{7,20}\z/', $value)) continue;
            $phone = ltrim($value, '+');
            if ($phone !== $businessPhone) $peers[hash('sha256', 'P:' . $phone)] = true;
        }
        foreach (['to_user_id', 'recipient_user_id'] as $key) {
            $value = $record[$key] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 512 && !preg_match('/[\x00-\x20\x7f]/', $value)) $peers[hash('sha256', 'U:' . $value)] = true;
        }
        return $peers;
    }

    private static function decode($ciphertext): array
    {
        if (!is_string($ciphertext) || strlen($ciphertext) > 8388608) throw new \RuntimeException();
        $bytes = \Illuminate\Support\Facades\Crypt::decryptString($ciphertext);
        if (strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException();
        $body = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) throw new \RuntimeException();
        return [$body, strlen($bytes)];
    }

    /** Fixed counters and internal native peer hashes only; no message body or customer fields. */
    public static function metadata(array $payload): array
    {
        $result = ['fields' => array_fill_keys([...self::FIELDS, 'OTHER'], 0),
            'channels' => ['messages' => 0, 'message_echoes' => 0, 'message_echo' => 0, 'statuses' => 0,
                'standby.messages' => 0, 'standby.message_echoes' => 0, 'standby.message_echo' => 0,
                'standby.statuses' => 0, 'standby.LIST' => 0],
            'directions' => ['inbound' => 0, 'outbound' => 0, 'OTHER' => 0],
            'types' => array_fill_keys([...self::TYPES, 'OTHER'], 0),
            'normalizer' => ['messages' => 0, 'quarantined' => 0, 'ignored' => 0, 'statuses' => 0],
            'scope_changes' => 0, 'malformed_shapes' => 0, 'native_echo_records' => 0,
            'native_cart_records' => 0, 'native_nonstatus_records' => 0, 'peers' => [], 'inbound_peers' => []];
        $report = \App\Support\WhatsAppInboxProtocol::report($payload, self::WABA, self::PHONE);
        $result['normalizer'] = ['messages' => count($report['messages']), 'quarantined' => $report['quarantined_count'],
            'ignored' => $report['ignored_count'], 'statuses' => $report['status_count']];
        foreach ($report['messages'] as $dto) {
            $direction = in_array($dto['direction'], ['inbound', 'outbound'], true) ? $dto['direction'] : 'OTHER';
            $result['directions'][$direction]++;
            $result['types'][in_array($dto['type'], self::TYPES, true) ? $dto['type'] : 'OTHER']++;
            foreach (['peer_phone' => 'P', 'peer_user_id' => 'U'] as $key => $namespace) {
                if (!is_string($dto[$key] ?? null) || $dto[$key] === '') continue;
                $peer = hash('sha256', $namespace . ':' . $dto[$key]);
                $result['peers'][$peer] = true;
                if ($direction === 'inbound') $result['inbound_peers'][$peer] = true;
            }
        }
        if (($payload['object'] ?? null) !== 'whatsapp_business_account' || !self::list($payload['entry'] ?? null)) {
            $result['malformed_shapes']++; return $result;
        }
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? null) !== self::WABA) continue;
            if (array_key_exists('standby', $entry)) $result['malformed_shapes']++;
            if (!self::list($entry['changes'] ?? null)) { $result['malformed_shapes']++; continue; }
            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || !is_array($change['value'] ?? null)) { $result['malformed_shapes']++; continue; }
                $value = $change['value'];
                if (($value['messaging_product'] ?? null) !== 'whatsapp'
                    || ($value['metadata']['phone_number_id'] ?? null) !== self::PHONE) continue;
                $result['scope_changes']++;
                $field = in_array($change['field'] ?? null, self::FIELDS, true) ? $change['field'] : 'OTHER';
                $result['fields'][$field]++;
                $channels = [];
                foreach (['messages', 'message_echoes', 'message_echo', 'statuses'] as $key) {
                    if (array_key_exists($key, $value)) $channels[$key] = $value[$key];
                }
                if (is_array($value['standby'] ?? null)) {
                    foreach (['messages', 'message_echoes', 'message_echo', 'statuses'] as $key) {
                        if (array_key_exists($key, $value['standby'])) $channels['standby.' . $key] = $value['standby'][$key];
                    }
                    if (self::list($value['standby'])) $channels['standby.LIST'] = $value['standby'];
                } elseif (array_key_exists('standby', $value)) $result['malformed_shapes']++;
                foreach ($channels as $channel => $items) {
                    if (!self::list($items)) { $result['malformed_shapes']++; $items = is_array($items) ? [$items] : []; }
                    $result['channels'][$channel] += count($items);
                    foreach ($items as $record) {
                        if (!is_array($record)) { $result['malformed_shapes']++; continue; }
                        if (strpos($channel, 'statuses') !== false) continue;
                        $message = is_array($record['message'] ?? null) ? $record['message'] : $record;
                        $result['native_nonstatus_records']++;
                        if (strpos($channel, 'echo') !== false || $field === 'smb_message_echoes') {
                            $result['native_echo_records']++;
                            $businessPhone = $value['metadata']['display_phone_number'] ?? null;
                            $businessPhone = is_string($businessPhone) && preg_match('/\A\+?[0-9]{7,20}\z/', $businessPhone) ? ltrim($businessPhone, '+') : null;
                            // Sampling association only. Never normalize, merge or route an unknown echo.
                            $result['peers'] += self::rawRecipientPeers($record, $businessPhone) + self::rawRecipientPeers($message, $businessPhone);
                        }
                        if (($message['type'] ?? null) === 'order' && is_array($message['order'] ?? null)) $result['native_cart_records']++;
                    }
                }
            }
        }
        return $result;
    }

    public static function select(array $candidates): array
    {
        $selected = []; $peers = [];
        foreach (['echo' => 8, 'cart' => 4] as $kind => $limit) {
            $count = 0;
            foreach ($candidates as $candidate) {
                if (!$candidate[$kind] || $count >= $limit) continue;
                $selected[$candidate['id']] = $candidate; $count++;
                $peers += $candidate['peers'];
            }
        }
        foreach ($candidates as $candidate) {
            if (count($selected) >= self::DETAIL_EVENTS) break;
            if (!isset($selected[$candidate['id']]) && array_intersect_key($candidate['inbound_peers'], $peers) !== []) $selected[$candidate['id']] = $candidate;
        }
        foreach ($candidates as $candidate) {
            if (count($selected) >= self::DETAIL_EVENTS) break;
            if (!isset($selected[$candidate['id']])) $selected[$candidate['id']] = $candidate;
        }
        return $selected;
    }

    private static function compact(array $capture, int &$recordCount, array &$omitted): array
    {
        $out = [];
        foreach ($capture['scoped_changes'] as $index => $change) {
            if ($index >= 4) { $omitted['changes']++; continue; }
            $part = ['field' => $change['field'], 'channels' => $change['channels'],
                'contacts' => array_slice($change['contacts'], 0, 2), 'records' => []];
            $omitted['contacts'] += max(0, count($change['contacts']) - 2) + $change['contacts_omitted'];
            foreach ($change['records'] as $record) {
                if ($record['kind'] === 'STATUS') continue;
                if ($recordCount >= self::DETAIL_RECORDS) { $omitted['records']++; continue; }
                $recordCount++;
                $item = ['channel' => $record['channel'], 'kind' => $record['kind'], 'type' => $record['type'],
                    'message' => $record['message'], 'sent_at_utc' => $record['sent_at_utc'],
                    'native_identity' => $record['native_identity'], 'normalized' => array_slice($record['normalized'], 0, 2),
                    'projected' => $record['projected']];
                if ($record['nested_message_identity'] !== []) $item['nested_message_identity'] = $record['nested_message_identity'];
                if (isset($record['cart_shape'])) $item['cart_shape'] = $record['cart_shape'];
                $part['records'][] = $item;
                $omitted['normalizations'] += max(0, count($record['normalized']) - 2) + $record['normalizations_omitted'];
            }
            $out[] = $part;
        }
        foreach (['changes_omitted' => 'changes', 'records_omitted' => 'records', 'entries_omitted' => 'entries', 'normalizations_omitted' => 'normalizations'] as $key => $target) $omitted[$target] += $capture[$key];
        return $out;
    }

    public static function output(array $result): string
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        if (strlen($json) <= self::OUTPUT_BYTES) return $json;
        $events = $result['events']; $result['events'] = [];
        $result['status'] = 'PARTIAL'; $result['details_complete'] = false;
        $result['output_events_omitted'] = count($events);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        usort($events, static function (array $left, array $right): int {
            $rank = static function (array $event): int { return !empty($event['native_echo_event']) ? 0 : (!empty($event['native_cart_event']) ? 1 : 2); };
            return ($rank($left) <=> $rank($right)) ?: (($right['event_id'] ?? 0) <=> ($left['event_id'] ?? 0));
        });
        foreach ($events as $event) {
            $candidate = $result; $candidate['events'][] = $event; $candidate['output_events_omitted']--;
            $encoded = json_encode($candidate, JSON_THROW_ON_ERROR);
            if (strlen($encoded) <= self::OUTPUT_BYTES) { $result = $candidate; $json = $encoded; }
        }
        if (strlen($json) > self::OUTPUT_BYTES) return '{"diagnostic":"READ_ONLY_AGENT_WINDOW","status":"PARTIAL","details_complete":false,"output_details_omitted":true,"events":[]}';
        return $json;
    }

    public static function run(): array
    {
        $date = self::arguments(array_slice($_SERVER['argv'] ?? [], 1));
        $window = self::window($date);
        self::loadGap();
        if (!chdir(self::ROOT)) throw new \RuntimeException();
        require self::ROOT . '/vendor/autoload.php';
        $app = require self::ROOT . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = \Illuminate\Support\Facades\DB::class;
        $ceiling = (int) ($db::table('whatsapp_webhook_events')->max('id') ?? 0);
        $query = $db::table('whatsapp_webhook_events')->where('id', '<=', $ceiling)
            ->where('received_at', '>=', $window['start_utc_inclusive'])->where('received_at', '<', $window['end_utc_exclusive']);
        $total = (int) (clone $query)->count();
        // Only lightweight metadata is retained. Fetch/decrypt one payload at a time.
        $rows = (clone $query)->orderByDesc('id')->limit(self::SCAN_LIMIT)->get(['id', 'received_at', 'processed_at']);
        $summary = self::metadata([]);
        unset($summary['peers'], $summary['inbound_peers']); $summary['malformed_shapes'] = 0; $summary['normalizer']['ignored'] = 0;
        $scanned = 0; $bytes = 0; $unreadable = 0; $candidates = []; $oldestScannedId = null;
        foreach ($rows as $row) {
            try { [$payload, $length] = self::decode($db::table('whatsapp_webhook_events')->where('id', $row->id)->value('payload')); }
            catch (\Throwable $error) { $scanned++; $unreadable++; $oldestScannedId = (int) $row->id; continue; }
            if ($bytes + $length > self::SCAN_BYTES) { unset($payload); break; }
            $bytes += $length; $scanned++; $oldestScannedId = (int) $row->id;
            $meta = self::metadata($payload); unset($payload);
            foreach (['fields', 'channels', 'directions', 'types', 'normalizer'] as $group) foreach ($meta[$group] as $key => $value) $summary[$group][$key] += $value;
            foreach (['scope_changes', 'malformed_shapes', 'native_echo_records', 'native_cart_records', 'native_nonstatus_records'] as $key) $summary[$key] += $meta[$key];
            if ($meta['native_nonstatus_records'] > 0) $candidates[] = ['id' => (int) $row->id,
                'received_at_utc' => $row->received_at, 'processed' => $row->processed_at !== null,
                'echo' => $meta['native_echo_records'] > 0, 'cart' => $meta['native_cart_records'] > 0,
                'peers' => $meta['peers'], 'inbound_peers' => $meta['inbound_peers']];
        }
        $selected = self::select($candidates);
        $out = ['diagnostic' => 'READ_ONLY_AGENT_WINDOW', 'status' => 'READY', 'window' => $window,
            'observation' => 'RECEIVED_AT_WINDOW_WITH_EVENT_ID_CEILING_AND_LIVE_PROJECTION_LOOKUPS',
            'capture_count_scope' => 'ALL_CAPTURED_WEBHOOK_EVENTS_BEFORE_PAYLOAD_SCOPE_FILTER',
            'event_ceiling' => $ceiling, 'window_events_at_ceiling' => $total,
            'scan_event_limit' => self::SCAN_LIMIT,
            'successfully_decoded_payload_byte_limit' => self::SCAN_BYTES,
            'successfully_decoded_payload_bytes' => $bytes,
            'events_scanned' => $scanned, 'events_omitted_by_scan' => max(0, $total - $scanned),
            'oldest_scanned_event_id' => $oldestScannedId,
            'next_older_through_event' => $scanned < $total && $oldestScannedId !== null ? $oldestScannedId - 1 : null,
            'window_complete' => $scanned === $total, 'unreadable_events' => $unreadable,
            'summary' => $summary, 'detail_event_limit' => self::DETAIL_EVENTS,
            'nonstatus_events_in_scanned_window' => count($candidates), 'detail_events_selected' => count($selected),
            'detail_events_omitted' => max(0, count($candidates) - count($selected)),
            'detail_selection' => 'ECHO_CART_THEN_ASSOCIATED_NATIVE_INBOUND_THEN_RECENT_NONSTATUS',
            'details_complete' => count($candidates) === count($selected),
            'detail_omissions' => ['changes' => 0, 'records' => 0, 'contacts' => 0, 'entries' => 0, 'normalizations' => 0],
            'output_events_omitted' => 0, 'events' => []];
        $gap = new \WhatsAppCaptureGapDiagnostic(static function (string $key, callable $sanitize) use ($db): array {
            $row = $db::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.message_key', $key)->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE)
                ->first(['m.id', 'm.conversation_id', 'm.direction', 'm.source', 'm.type', 'm.content']);
            if (!$row) return ['status' => 'MISSING'];
            try {
                [$dto] = self::decode($row->content);
                return ['status' => 'FOUND', 'message_id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id,
                    'identity' => $sanitize($dto), 'columns_agree' => $row->direction === ($dto['direction'] ?? null)
                        && $row->source === ($dto['source'] ?? null) && $row->type === ($dto['type'] ?? null)];
            } catch (\Throwable $error) { return ['status' => 'UNREADABLE']; }
        });
        $recordCount = 0;
        foreach ($selected as $candidate) {
            $event = ['event_id' => $candidate['id'], 'received_at_utc' => $candidate['received_at_utc'],
                'processed' => $candidate['processed'], 'native_echo_event' => $candidate['echo'], 'native_cart_event' => $candidate['cart']];
            $reason = $db::table('whatsapp_inbox_ingestion_failures')->where('event_id', $candidate['id'])->value('reason');
            $event['quarantine_reason'] = $reason === null ? null : (in_array($reason, ['INVALID_EVENT', 'CONFLICTING_MESSAGE'], true) ? $reason : 'OTHER');
            try {
                [$payload] = self::decode($db::table('whatsapp_webhook_events')->where('id', $candidate['id'])->value('payload'));
                $event['changes'] = self::compact($gap->inspect($payload), $recordCount, $out['detail_omissions']);
            } catch (\Throwable $error) { $event['status'] = 'UNREADABLE'; $out['details_complete'] = false; }
            unset($payload); $out['events'][] = $event;
        }
        usort($out['events'], static function (array $left, array $right): int { return $right['event_id'] <=> $left['event_id']; });
        if (array_sum($out['detail_omissions']) > 0) $out['details_complete'] = false;
        if (!$window['window_elapsed'] || !$out['window_complete'] || $unreadable > 0
            || $summary['malformed_shapes'] > 0 || $summary['normalizer']['quarantined'] > 0 || !$out['details_complete']) $out['status'] = 'PARTIAL';
        return $out;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    try { $result = WhatsAppAgentWindowDiagnostic::run(); $exit = 0; }
    catch (\Throwable $error) { $result = ['diagnostic' => 'READ_ONLY_AGENT_WINDOW', 'status' => 'FAILED']; $exit = 1; }
    $json = WhatsAppAgentWindowDiagnostic::output($result);
    while (ob_get_level() > 0) ob_end_clean();
    echo $json . "\n";
    exit($exit);
}
