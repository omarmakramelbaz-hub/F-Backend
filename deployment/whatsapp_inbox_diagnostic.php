<?php

// Run the pinned helper with PHP CLI. This file must never expose diagnostics over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

/** Read only: no consumer calls, retries, transactions, or database mutations. */
final class WhatsAppInboxReadOnlyDiagnostic
{
    private const ROOT = '/home/fasakha/public_html';
    private const WABA = '468336579702269';
    private const PHONE = '515388018324075';
    private const MAX_BYTES = 4194304;
    private const FAILURE_LIMIT = 5;
    private const MESSAGE_LIMIT = 20;
    private const PROBE_LIMIT = 500;
    private const BODY_FIELDS = [
        'errors', 'text', 'image', 'video', 'document', 'audio', 'sticker',
        'location', 'contacts', 'interactive', 'button', 'reaction', 'order', 'system',
    ];

    public static function run(): array
    {
        if (!chdir(self::ROOT)) throw new \RuntimeException();
        require self::ROOT . '/vendor/autoload.php';
        $app = require self::ROOT . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $key = config('app.key');
        if (!is_string($key) || $key === '') throw new \RuntimeException();
        if (strpos($key, 'base64:') === 0) $key = base64_decode(substr($key, 7), true);
        if (!is_string($key) || strlen($key) < 16) throw new \RuntimeException();

        $db = \Illuminate\Support\Facades\DB::class;
        $failureCount = (int) $db::table('whatsapp_inbox_ingestion_failures')->count();
        $out = [
            'diagnostic' => 'READ_ONLY',
            'conversations' => (int) $db::table('whatsapp_inbox_conversations')
                ->where('waba_id', self::WABA)->where('phone_number_id', self::PHONE)->count(),
            'messages' => (int) $db::table('whatsapp_inbox_messages as m')
                ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE)->count(),
            'pending_events' => (int) $db::table('whatsapp_webhook_events')->whereNull('processed_at')->count(),
            'pending_runnable' => (int) $db::table('whatsapp_webhook_events')->whereNull('processed_at')
                ->whereNotExists(function ($query) {
                    $query->select('event_id')->from('whatsapp_inbox_ingestion_failures')
                        ->whereColumn('whatsapp_inbox_ingestion_failures.event_id', 'whatsapp_webhook_events.id');
                })->count(),
            'quarantine_total' => $failureCount,
            'failures' => [],
        ];
        $rows = $db::table('whatsapp_inbox_ingestion_failures as f')
            ->join('whatsapp_webhook_events as e', 'e.id', '=', 'f.event_id')
            ->orderBy('f.event_id')->limit(self::FAILURE_LIMIT)
            ->select('f.reason', 'e.payload')->get();
        foreach ($rows as $row) {
            $report = \App\Support\WhatsAppInboxProtocol::report(self::decode($row->payload), self::WABA, self::PHONE);
            if (!is_array($report['messages'] ?? null)) throw new \RuntimeException();
            foreach (['quarantined_count', 'ignored_count', 'status_count'] as $field) {
                if (!is_int($report[$field] ?? null) || $report[$field] < 0) throw new \RuntimeException();
            }
            $failure = [
                'label' => 'Q' . (count($out['failures']) + 1),
                'reason' => in_array($row->reason, ['INVALID_EVENT', 'CONFLICTING_MESSAGE'], true) ? $row->reason : 'UNKNOWN',
                'normalized_messages' => count($report['messages']),
                'normalizer_quarantined' => $report['quarantined_count'],
                'ignored_records' => $report['ignored_count'],
                'status_records' => $report['status_count'],
                'messages_truncated' => count($report['messages']) > self::MESSAGE_LIMIT,
                'comparisons' => [],
            ];
            foreach (array_slice($report['messages'], 0, self::MESSAGE_LIMIT) as $message) {
                self::assertDto($message);
                $comparison = self::compare($message, $key);
                $comparison['label'] = 'M' . (count($failure['comparisons']) + 1);
                $failure['comparisons'][] = $comparison;
            }
            $out['failures'][] = $failure;
        }
        $out['quarantine_rows_read'] = count($out['failures']);
        $out['quarantine_rows_omitted'] = max(0, $failureCount - count($out['failures']));
        $out['quarantine_rows_truncated'] = $out['quarantine_rows_omitted'] > 0;
        $out['probe'] = self::probe($key);
        unset($key);
        return $out;
    }

    private static function decode($ciphertext): array
    {
        if (!is_string($ciphertext)) throw new \RuntimeException();
        $json = \Illuminate\Support\Facades\Crypt::decryptString($ciphertext);
        if (strlen($json) > self::MAX_BYTES) throw new \RuntimeException();
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        unset($json);
        if (!is_array($value)) throw new \RuntimeException();
        return $value;
    }

    private static function assertDto($dto): void
    {
        if (!is_array($dto)) throw new \RuntimeException();
        foreach (['message_id', 'peer_identity', 'direction', 'type', 'sent_at', 'source'] as $field) {
            if (!is_string($dto[$field] ?? null) || $dto[$field] === '') throw new \RuntimeException();
        }
        foreach (['peer_user_id', 'peer_phone', 'customer_name', 'text'] as $field) {
            if (!array_key_exists($field, $dto) || ($dto[$field] !== null && !is_string($dto[$field]))) throw new \RuntimeException();
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dto['sent_at'], new \DateTimeZone('UTC'));
        $identity = $dto['peer_user_id'] !== null ? 'user:' . $dto['peer_user_id']
            : ($dto['peer_phone'] !== null ? 'phone:' . $dto['peer_phone'] : null);
        if (!$time || $time->format('Y-m-d H:i:s') !== $dto['sent_at']
            || !in_array($dto['direction'], ['inbound', 'outbound'], true)
            || !in_array($dto['source'], ['messages', 'standby', 'smb_message_echoes'], true)
            || strlen($dto['message_id']) > 512 || strlen($dto['peer_identity']) > 512
            || !preg_match('/\A[a-z_]{1,40}\z/', $dto['type'])
            || $identity === null || $dto['peer_identity'] !== $identity
            || !is_array($dto['content'] ?? null)
            || !is_array($dto['content']['message'] ?? null)
            || !is_array($dto['content']['sources'] ?? null)) throw new \RuntimeException();
        foreach ($dto['content']['sources'] as $source) {
            if (!in_array($source, ['messages', 'standby', 'smb_message_echoes'], true)) throw new \RuntimeException();
        }
    }

    private static function messageKey(array $message): string
    {
        return hash('sha256', 'whatsapp-message-v1:' . self::WABA . ':' . self::PHONE . ':' . $message['message_id']);
    }

    private static function peerHash(array $message, string $key): string
    {
        return hash_hmac('sha256', 'whatsapp-peer-v1:' . self::WABA . ':' . self::PHONE . ':' . $message['peer_identity'], $key);
    }

    private static function compare(array $message, string $key): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $peerHash = self::peerHash($message, $key);
        $existing = $db::table('whatsapp_inbox_messages')->where('message_key', self::messageKey($message))->first();
        $linked = $existing ? $db::table('whatsapp_inbox_conversations')->where('id', $existing->conversation_id)->first() : null;
        $peerConversation = $db::table('whatsapp_inbox_conversations')->where('waba_id', self::WABA)
            ->where('phone_number_id', self::PHONE)->where('peer_hash', $peerHash)->first();
        $out = [
            'direction' => $message['direction'],
            'source' => $message['source'],
            'type' => in_array($message['type'], self::BODY_FIELDS, true) ? $message['type'] : 'OTHER',
            'existing_message' => $existing !== null,
            'linked_conversation' => $linked !== null,
            'peer_conversation' => $peerConversation !== null,
            'scope_match' => $linked ? $linked->waba_id === self::WABA && $linked->phone_number_id === self::PHONE : null,
            'peer_hash_match' => $linked ? hash_equals((string) $linked->peer_hash, $peerHash) : null,
            'direction_match' => $existing ? $existing->direction === $message['direction'] : null,
            'type_match' => $existing ? $existing->type === $message['type'] : null,
            'scope_identity_or_columns_conflict' => false,
            'dto_conflict' => false,
            'body_conflict' => false,
            'customer_identity_conflict' => false,
            'dto_diff_fields' => [],
            'content_diff_fields' => [],
            'customer_identity_diff_fields' => [],
            'identity_comparison' => null,
            'text_comparison' => null,
        ];
        if ($existing) {
            $out['scope_identity_or_columns_conflict'] = !$linked || !$out['scope_match']
                || !$out['peer_hash_match'] || !$out['direction_match'] || !$out['type_match'];
            $saved = self::decode($existing->content);
            self::assertDto($saved);
            foreach (['message_id', 'peer_identity', 'direction', 'type', 'text', 'sent_at'] as $field) {
                if ($saved[$field] !== $message[$field]) $out['dto_diff_fields'][] = $field;
            }
            $out['dto_conflict'] = $out['dto_diff_fields'] !== [];
            $fields = array_unique(array_merge([$message['type']], self::BODY_FIELDS));
            foreach ($fields as $field) {
                if (self::canonical($saved['content']['message'][$field] ?? null)
                    !== self::canonical($message['content']['message'][$field] ?? null)) {
                    // Never emit an unrecognized field name from the payload.
                    $out['content_diff_fields'][] = in_array($field, self::BODY_FIELDS, true) ? $field : 'OTHER';
                }
            }
            $out['body_conflict'] = $out['content_diff_fields'] !== [];
            $out['identity_comparison'] = [
                'old_phone_present' => $saved['peer_phone'] !== null,
                'new_phone_present' => $message['peer_phone'] !== null,
                'phone_match' => $saved['peer_phone'] !== null && $message['peer_phone'] !== null ? $saved['peer_phone'] === $message['peer_phone'] : null,
                'old_user_present' => $saved['peer_user_id'] !== null,
                'new_user_present' => $message['peer_user_id'] !== null,
                'user_match' => $saved['peer_user_id'] !== null && $message['peer_user_id'] !== null ? $saved['peer_user_id'] === $message['peer_user_id'] : null,
                'old_namespace' => $saved['peer_user_id'] !== null ? 'USER' : 'PHONE',
                'new_namespace' => $message['peer_user_id'] !== null ? 'USER' : 'PHONE',
            ];
            if ($message['type'] === 'text') {
                $oldText = $saved['content']['message']['text'] ?? null;
                $newText = $message['content']['message']['text'] ?? null;
                if (!is_array($oldText) || !is_array($newText)) throw new \RuntimeException();
                $out['text_comparison'] = [
                    'body_match' => array_key_exists('body', $oldText) && array_key_exists('body', $newText) && $oldText['body'] === $newText['body'],
                    'old_preview_url_present' => array_key_exists('preview_url', $oldText),
                    'new_preview_url_present' => array_key_exists('preview_url', $newText),
                    'preview_url_match' => self::canonical($oldText['preview_url'] ?? null) === self::canonical($newText['preview_url'] ?? null),
                ];
            }
        }
        // This guard runs only for a new message; compare the candidate thread even
        // when the existing-message lookup found nothing.
        if ($peerConversation) {
            $customer = self::decode($peerConversation->customer);
            foreach (['phone' => 'peer_phone', 'user_id' => 'peer_user_id'] as $field => $incoming) {
                if (($customer[$field] ?? null) !== null && $message[$incoming] !== null
                    && $customer[$field] !== $message[$incoming]) $out['customer_identity_diff_fields'][] = $field;
            }
            $out['customer_identity_conflict'] = !$existing && $out['customer_identity_diff_fields'] !== [];
        }
        return $out;
    }

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
        return $value;
    }

    private static function probe(string $key): array
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $base = function () use ($db) {
            return $db::table('whatsapp_inbox_messages as m')
                ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE);
        };
        // Keep the ceiling in memory so incoming messages cannot move this scan.
        $ceiling = $base()->max('m.id');
        $total = $ceiling === null ? 0 : (int) $base()->where('m.id', '<=', $ceiling)->count();
        $rows = $ceiling === null ? [] : $base()->where('m.id', '<=', $ceiling)
            ->orderByDesc('m.id')->limit(self::PROBE_LIMIT)
            ->select('m.message_key', 'm.conversation_id', 'm.content', 'm.direction', 'm.type', 'c.peer_hash')->get();
        $inbound = 0;
        $outbound = 0;
        $threads = [];
        $scanned = 0;
        foreach ($rows as $row) {
            $scanned++;
            $message = self::decode($row->content);
            self::assertDto($message);
            if ($message['direction'] !== $row->direction || $message['type'] !== $row->type
                || !hash_equals((string) $row->message_key, self::messageKey($message))
                || !hash_equals((string) $row->peer_hash, self::peerHash($message, $key))) throw new \RuntimeException();
            if (!is_string($message['text']) || strpos($message['text'], 'WA-0121') === false) continue;
            if ($message['direction'] === 'inbound') $inbound++;
            else $outbound++;
            $threads[(string) $row->conversation_id] = true;
        }
        $complete = $total <= self::PROBE_LIMIT && $scanned === $total;
        $status = !$complete ? 'PARTIAL' : ($inbound + $outbound === 0 ? 'MISSING'
            : (count($threads) > 1 ? 'AMBIGUOUS' : ($inbound > 0 && $outbound > 0 ? 'YES' : 'PARTIAL')));
        return [
            'label' => 'WA-0121',
            'scoped_messages_at_ceiling' => $total,
            'messages_scanned' => $scanned,
            'range_complete' => $complete,
            'inbound_matches' => $inbound,
            'outbound_matches' => $outbound,
            'matching_conversations' => count($threads),
            'same_conversation' => $status,
        ];
    }
}

try {
    $result = WhatsAppInboxReadOnlyDiagnostic::run();
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $error) {
    while (ob_get_level() > 0) ob_end_clean();
    echo "CHECK_FAILED\n";
    exit(1);
}
