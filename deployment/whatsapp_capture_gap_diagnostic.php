<?php

// CLI only. This helper observes capture/projection gaps; it never changes routing.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class WhatsAppCaptureGapDiagnostic
{
    private const ROOT = '/home/fasakha/public_html';
    private const WABA = '468336579702269';
    private const PHONE = '515388018324075';
    private const SINCE = '2026-10-10 01:47:00';
    private const EVENT_LIMIT = 500;
    private const RECORD_LIMIT = 160;
    private const CHANGE_LIMIT = 200;
    private const OUTPUT_LIMIT = 524288;
    private const MAX_BYTES = 4194304;
    private const FIELDS = ['messages', 'standby', 'smb_message_echoes', 'messaging_handovers'];
    private const TYPES = ['text', 'image', 'video', 'document', 'audio', 'sticker', 'location',
        'contacts', 'interactive', 'button', 'reaction', 'order', 'system', 'referral', 'edit',
        'revoke', 'unsupported', 'unknown'];
    private $labels = [];
    private $labelCounts = [];
    private $projection;
    private $recordCount = 0;
    private $changeCount = 0;

    // The callback is a read-only lookup by a scoped message key, never customer text.
    public function __construct(?callable $projection = null) { $this->projection = $projection; }

    private function label(string $namespace, $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 512
            || preg_match('/[\x00-\x20\x7f]/', $value)) return null;
        if ($namespace === 'P') {
            if (!preg_match('/\A\+?[0-9]{7,20}\z/', $value)) return null;
            $value = ltrim($value, '+');
        }
        $key = $namespace . ':' . $value;
        if (!isset($this->labels[$key])) {
            $this->labelCounts[$namespace] = ($this->labelCounts[$namespace] ?? 0) + 1;
            $this->labels[$key] = $namespace . $this->labelCounts[$namespace];
        }
        return $this->labels[$key];
    }

    private function identity(array $node): array
    {
        $result = [];
        foreach (['from', 'to', 'recipient_id', 'wa_id'] as $key) {
            if (array_key_exists($key, $node)) $result[$key] = $this->label('P', $node[$key]) ?? 'INVALID';
        }
        foreach (['from_user_id', 'to_user_id', 'recipient_user_id', 'user_id'] as $key) {
            if (array_key_exists($key, $node)) $result[$key] = $this->label('U', $node[$key]) ?? 'INVALID';
        }
        foreach (['sender', 'recipient'] as $side) {
            if (!is_array($node[$side] ?? null) || !array_key_exists('id', $node[$side])) continue;
            $value = $node[$side]['id'];
            $result[$side . '.id'] = $this->label('P', $value) ?? $this->label('U', $value) ?? 'INVALID';
        }
        return $result;
    }

    private static function timestamp($value): ?string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]{1,10}\z/', (string) $value)
            || (int) $value < 1 || (int) $value > 2147483647) return null;
        return gmdate('Y-m-d H:i:s', (int) $value);
    }

    private static function date($value): ?string
    {
        if (!is_string($value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    private static function list($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    private function normalized(array $dto): array
    {
        $out = [
            'direction' => in_array($dto['direction'] ?? null, ['inbound', 'outbound'], true) ? $dto['direction'] : 'OTHER',
            'source' => in_array($dto['source'] ?? null, self::FIELDS, true) ? $dto['source'] : 'OTHER',
            'peer_phone' => $this->label('P', $dto['peer_phone'] ?? null),
            'peer_user' => $this->label('U', $dto['peer_user_id'] ?? null),
            'peer_namespace' => ($dto['peer_user_id'] ?? null) !== null ? 'USER' : 'PHONE',
        ];
        return $out;
    }

    /** Pure bounded description; never exposes any message body or native identifier. */
    public function inspect(array $payload): array
    {
        $out = ['scoped_changes' => [], 'unscoped_entry_standby' => 0, 'excluded_changes' => 0,
            'malformed_envelopes' => 0, 'records_omitted' => 0, 'changes_omitted' => 0,
            'entries_omitted' => 0,
            'normalizer' => ['messages' => 0, 'quarantined' => 0, 'ignored' => 0, 'statuses' => 0]];
        if (($payload['object'] ?? null) !== 'whatsapp_business_account' || !self::list($payload['entry'] ?? null)) {
            $out['malformed_envelopes']++; return $out;
        }
        $out['entries_omitted'] = max(0, count($payload['entry']) - 100);
        $normalized = [];
        $report = \App\Support\WhatsAppInboxProtocol::report($payload, self::WABA, self::PHONE);
        foreach (['messages' => 'messages', 'quarantined_count' => 'quarantined', 'ignored_count' => 'ignored', 'status_count' => 'statuses'] as $key => $target) {
            $out['normalizer'][$target] = $key === 'messages' ? count($report[$key]) : $report[$key];
        }
        foreach ($report['messages'] as $dto) $normalized[$dto['message_id']][] = $dto;
        foreach (array_slice($payload['entry'], 0, 100) as $entry) {
            if (!is_array($entry) || ($entry['id'] ?? null) !== self::WABA) continue;
            if (array_key_exists('standby', $entry)) $out['unscoped_entry_standby']++;
            if (!self::list($entry['changes'] ?? null)) { $out['malformed_envelopes']++; continue; }
            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || !is_array($change['value'] ?? null)) { $out['malformed_envelopes']++; continue; }
                $value = $change['value'];
                if (($value['messaging_product'] ?? null) !== 'whatsapp'
                    || ($value['metadata']['phone_number_id'] ?? null) !== self::PHONE) { $out['excluded_changes']++; continue; }
                if (count($out['scoped_changes']) >= 40 || $this->changeCount >= self::CHANGE_LIMIT) { $out['changes_omitted']++; continue; }
                $this->changeCount++;
                $source = in_array($change['field'] ?? null, self::FIELDS, true) ? $change['field'] : 'OTHER';
                $shape = ['field' => $source, 'business_phone' => $this->label('P', $value['metadata']['display_phone_number'] ?? null),
                    'native_identity' => $this->identity($value),
                    'keys' => [], 'contacts' => [], 'contacts_omitted' => 0, 'channels' => [], 'records' => []];
                foreach (['messages', 'message_echoes', 'message_echo', 'statuses', 'standby', 'messaging_handovers', 'contacts'] as $key) {
                    if (array_key_exists($key, $value)) $shape['keys'][$key] = is_array($value[$key]) ? 'ARRAY' : 'OTHER';
                }
                $contacts = $value['contacts'] ?? [];
                if (self::list($contacts)) {
                    $shape['contacts_omitted'] = max(0, count($contacts) - 10);
                    foreach (array_slice($contacts, 0, 10) as $contact) if (is_array($contact)) $shape['contacts'][] = $this->identity($contact);
                }
                $channels = [];
                foreach (['messages', 'message_echoes', 'message_echo', 'statuses'] as $key) {
                    if (array_key_exists($key, $value)) $channels[$key] = $value[$key];
                }
                if (is_array($value['standby'] ?? null)) {
                    foreach (['messages', 'message_echoes', 'message_echo', 'statuses'] as $key) {
                        if (array_key_exists($key, $value['standby'])) $channels['standby.' . $key] = $value['standby'][$key];
                    }
                    if (self::list($value['standby'])) $channels['standby.LIST'] = $value['standby'];
                    if (self::list($value['standby']['contacts'] ?? null)) {
                        foreach (array_slice($value['standby']['contacts'], 0, 10) as $contact) {
                            if (is_array($contact)) $shape['contacts'][] = $this->identity($contact);
                        }
                        $shape['contacts_omitted'] += max(0, count($value['standby']['contacts']) - 10);
                    }
                }
                foreach ($channels as $channel => $items) {
                    $isList = self::list($items);
                    // Singular alternate envelopes are described, never normalized by guesswork.
                    $records = $isList ? $items : (is_array($items) ? [$items] : []);
                    $shape['channels'][$channel] = ['shape' => $isList ? 'LIST' : (is_array($items) ? 'OBJECT' : 'OTHER'), 'records' => count($records)];
                    foreach ($records as $record) {
                        if (!is_array($record)) { $out['malformed_envelopes']++; continue; }
                        if ($this->recordCount >= self::RECORD_LIMIT) { $out['records_omitted']++; continue; }
                        $this->recordCount++;
                        $message = is_array($record['message'] ?? null) ? $record['message'] : $record;
                        $id = $message['id'] ?? ($message['mid'] ?? null);
                        $label = $this->label('M', $id);
                        $data = ['channel' => $channel, 'kind' => strpos($channel, 'statuses') !== false ? 'STATUS' : 'MESSAGE',
                            'message' => $label, 'sent_at_utc' => self::timestamp($message['timestamp'] ?? ($record['timestamp'] ?? null)),
                            'type' => in_array($message['type'] ?? null, self::TYPES, true) ? $message['type'] : 'OTHER',
                            'native_identity' => $this->identity($record), 'nested_message_identity' => $record === $message ? [] : $this->identity($message),
                            'normalized' => is_string($id) ? array_map(function (array $dto): array { return $this->normalized($dto); }, $normalized[$id] ?? []) : [],
                            'projected' => ['status' => 'NOT_LOOKED_UP']];
                        if ($data['kind'] === 'STATUS') {
                            $data['delivery_status'] = in_array($message['status'] ?? null, ['sent', 'delivered', 'read', 'failed', 'deleted'], true)
                                ? $message['status'] : 'OTHER';
                        }
                        if (is_array($message['order'] ?? null)) {
                            $order = $message['order'];
                            $catalog = $order['catalog_id'] ?? null;
                            $lines = self::list($order['product_items'] ?? null) ? $order['product_items'] : null;
                            $quantities = $lines !== null && $lines !== [];
                            $currencies = $quantities;
                            foreach ($lines ?? [] as $line) {
                                $quantity = is_array($line) ? ($line['quantity'] ?? null) : null;
                                $currency = is_array($line) ? ($line['currency'] ?? null) : null;
                                $quantities = $quantities && (is_int($quantity) || is_string($quantity))
                                    && preg_match('/\A[1-9][0-9]{0,3}\z/', (string) $quantity) === 1;
                                $currencies = $currencies && is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) === 1;
                            }
                            $data['cart_shape'] = [
                                'catalog_id' => is_string($catalog) && preg_match('/\A[0-9]{7,30}\z/', $catalog) ? $catalog : 'INVALID',
                                'line_count' => $lines === null ? null : count($lines),
                                'quantities_valid' => $quantities, 'currency_codes_valid' => $currencies,
                            ];
                        }
                        if ($label !== null && $this->projection !== null) {
                            $key = hash('sha256', 'whatsapp-message-v1:' . self::WABA . ':' . self::PHONE . ':' . $id);
                            $data['projected'] = ($this->projection)($key, function (array $dto): array { return $this->normalized($dto); });
                        }
                        $shape['records'][] = $data;
                    }
                }
                $out['scoped_changes'][] = $shape;
            }
        }
        return $out;
    }

    /** A size fallback explicitly withholds details rather than emitting partial identities. */
    public static function output(array $result): string
    {
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (strlen($json) <= self::OUTPUT_LIMIT) return $json;
        $result['status'] = 'PARTIAL';
        $result['details_truncated'] = true;
        $result['output_details_omitted'] = true;
        $result['events'] = [];
        return json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private static function decode($ciphertext): array
    {
        if (!is_string($ciphertext)) throw new \RuntimeException();
        $bytes = \Illuminate\Support\Facades\Crypt::decryptString($ciphertext);
        if (strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException();
        $body = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) throw new \RuntimeException();
        return $body;
    }

    public static function run(): array
    {
        if (($_SERVER['argc'] ?? 1) !== 1 || !chdir(self::ROOT)) throw new \RuntimeException();
        require self::ROOT . '/vendor/autoload.php';
        $app = require self::ROOT . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = \Illuminate\Support\Facades\DB::class;
        $ceiling = (int) ($db::table('whatsapp_webhook_events')->max('id') ?? 0);
        $query = $db::table('whatsapp_webhook_events')->where('id', '<=', $ceiling)->where('received_at', '>=', self::SINCE);
        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('id')->limit(self::EVENT_LIMIT)->get(['id', 'payload', 'received_at', 'processed_at']);
        $diagnostic = new self(static function (string $key, callable $sanitize) use ($db): array {
            $row = $db::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.message_key', $key)->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE)
                ->first(['m.id', 'm.conversation_id', 'm.direction', 'm.source', 'm.type', 'm.content']);
            if (!$row) return ['status' => 'MISSING'];
            try {
                $dto = self::decode($row->content);
                return ['status' => 'FOUND', 'message_id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id,
                    'identity' => $sanitize($dto), 'columns_agree' => $row->direction === ($dto['direction'] ?? null)
                        && $row->source === ($dto['source'] ?? null) && $row->type === ($dto['type'] ?? null)];
            } catch (\Throwable $error) { return ['status' => 'UNREADABLE']; }
        });
        $out = ['diagnostic' => 'READ_ONLY_CAPTURE_GAP', 'status' => 'READY', 'since_received_utc' => self::SINCE,
            'event_ceiling' => $ceiling, 'window_events_at_ceiling' => $total, 'events_read' => count($rows),
            'window_complete' => count($rows) === $total, 'details_truncated' => false,
            'output_details_omitted' => false, 'records_omitted' => 0, 'changes_omitted' => 0,
            'entries_omitted' => 0, 'unreadable_events' => 0, 'events' => []];
        if (!$out['window_complete']) $out['status'] = 'PARTIAL';
        foreach ($rows->reverse() as $row) {
            $event = ['event_id' => (int) $row->id, 'received_at_utc' => self::date($row->received_at), 'processed' => $row->processed_at !== null];
            $failure = $db::table('whatsapp_inbox_ingestion_failures')->where('event_id', $row->id)->value('reason');
            $event['quarantine_reason'] = $failure === null ? null : (in_array($failure, ['INVALID_EVENT', 'CONFLICTING_MESSAGE'], true) ? $failure : 'OTHER');
            try { $event['capture'] = $diagnostic->inspect(self::decode($row->payload)); }
            catch (\Throwable $error) { $event['status'] = 'UNREADABLE'; $out['unreadable_events']++; $out['status'] = 'PARTIAL'; }
            foreach (['records_omitted', 'changes_omitted', 'entries_omitted'] as $counter) {
                $out[$counter] += $event['capture'][$counter] ?? 0;
                if ($out[$counter] > 0) { $out['details_truncated'] = true; $out['status'] = 'PARTIAL'; }
            }
            $out['events'][] = $event;
        }
        return $out;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    try { $result = WhatsAppCaptureGapDiagnostic::run(); $exit = 0; }
    catch (\Throwable $error) { $result = ['diagnostic' => 'READ_ONLY_CAPTURE_GAP', 'status' => 'FAILED']; $exit = 1; }
    $json = WhatsAppCaptureGapDiagnostic::output($result);
    while (ob_get_level() > 0) ob_end_clean();
    echo $json . "\n";
    exit($exit);
}
