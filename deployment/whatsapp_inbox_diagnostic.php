<?php

// Run the pinned helper with PHP CLI. This file must never expose diagnostics over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

// Deliberate invariant failures carry no private detail.
final class WhatsAppInboxDiagnosticValidationException extends \RuntimeException {}

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
    private static $stage = 'START';
    private const STAGES = [
        'START', 'BOOTSTRAP_CHDIR', 'BOOTSTRAP_AUTOLOAD', 'BOOTSTRAP_APP',
        'BOOTSTRAP_KERNEL', 'BOOTSTRAP_APP_KEY', 'COUNT_QUARANTINE',
        'COUNT_CONVERSATIONS', 'COUNT_MESSAGES', 'COUNT_PENDING',
        'COUNT_RUNNABLE', 'FAILURES_FETCH', 'FAILURE_PAYLOAD_INPUT',
        'FAILURE_PAYLOAD_DECRYPT', 'FAILURE_PAYLOAD_SIZE', 'FAILURE_PAYLOAD_JSON',
        'FAILURE_PAYLOAD_ARRAY', 'FAILURE_NORMALIZE', 'FAILURE_REPORT_VALIDATE',
        'EVENT_DTO_VALIDATE', 'COMPARISON_KEYS', 'COMPARISON_MESSAGE_FETCH',
        'COMPARISON_LINKED_FETCH', 'COMPARISON_PEER_FETCH',
        'COMPARISON_SCOPE_INVARIANTS', 'STORED_CONTENT_INPUT',
        'STORED_CONTENT_DECRYPT', 'STORED_CONTENT_SIZE', 'STORED_CONTENT_JSON',
        'STORED_CONTENT_ARRAY', 'STORED_DTO_VALIDATE', 'COMPARISON_DTO_FIELDS',
        'COMPARISON_CONTENT_FIELDS', 'COMPARISON_IDENTITY', 'COMPARISON_TEXT',
        'CUSTOMER_CONTENT_INPUT', 'CUSTOMER_CONTENT_DECRYPT',
        'CUSTOMER_CONTENT_SIZE', 'CUSTOMER_CONTENT_JSON', 'CUSTOMER_CONTENT_ARRAY',
        'COMPARISON_CUSTOMER_IDENTITY', 'PROBE_CEILING', 'PROBE_COUNT',
        'PROBE_FETCH', 'PROBE_CONTENT_INPUT', 'PROBE_CONTENT_DECRYPT',
        'PROBE_CONTENT_SIZE', 'PROBE_CONTENT_JSON', 'PROBE_CONTENT_ARRAY',
        'PROBE_DTO_VALIDATE', 'PROBE_MESSAGE_INVARIANTS', 'PROBE_MARKER',
        'PROBE_RANGE_VALIDATE', 'OUTPUT_JSON',
    ];
    private const DECODE_STAGES = [
        'FAILURE_PAYLOAD' => ['FAILURE_PAYLOAD_INPUT', 'FAILURE_PAYLOAD_DECRYPT', 'FAILURE_PAYLOAD_SIZE', 'FAILURE_PAYLOAD_JSON', 'FAILURE_PAYLOAD_ARRAY'],
        'STORED_CONTENT' => ['STORED_CONTENT_INPUT', 'STORED_CONTENT_DECRYPT', 'STORED_CONTENT_SIZE', 'STORED_CONTENT_JSON', 'STORED_CONTENT_ARRAY'],
        'CUSTOMER_CONTENT' => ['CUSTOMER_CONTENT_INPUT', 'CUSTOMER_CONTENT_DECRYPT', 'CUSTOMER_CONTENT_SIZE', 'CUSTOMER_CONTENT_JSON', 'CUSTOMER_CONTENT_ARRAY'],
        'PROBE_CONTENT' => ['PROBE_CONTENT_INPUT', 'PROBE_CONTENT_DECRYPT', 'PROBE_CONTENT_SIZE', 'PROBE_CONTENT_JSON', 'PROBE_CONTENT_ARRAY'],
    ];
    private const BODY_FIELDS = [
        'errors', 'text', 'image', 'video', 'document', 'audio', 'sticker',
        'location', 'contacts', 'interactive', 'button', 'reaction', 'order', 'system',
    ];

    public static function stage(string $stage): void
    {
        self::$stage = in_array($stage, self::STAGES, true) ? $stage : 'START';
    }

    public static function failure(\Throwable $error): array
    {
        if ($error instanceof \Illuminate\Database\QueryException || $error instanceof \PDOException) {
            $category = 'SQL';
        } elseif ($error instanceof \Illuminate\Contracts\Encryption\DecryptException) {
            $category = 'DECRYPT';
        } elseif ($error instanceof \JsonException) {
            $category = 'JSON';
        } elseif ($error instanceof WhatsAppInboxDiagnosticValidationException) {
            $category = 'VALIDATION';
        } else {
            $category = 'OTHER';
        }
        return ['failed_stage' => self::$stage, 'error_category' => $category];
    }

    public static function run(): array
    {
        self::stage('BOOTSTRAP_CHDIR');
        if (!chdir(self::ROOT)) throw new \RuntimeException();
        self::stage('BOOTSTRAP_AUTOLOAD');
        require self::ROOT . '/vendor/autoload.php';
        self::stage('BOOTSTRAP_APP');
        $app = require self::ROOT . '/bootstrap/app.php';
        self::stage('BOOTSTRAP_KERNEL');
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        self::stage('BOOTSTRAP_APP_KEY');
        $key = config('app.key');
        if (!is_string($key) || $key === '') throw new WhatsAppInboxDiagnosticValidationException();
        if (strpos($key, 'base64:') === 0) $key = base64_decode(substr($key, 7), true);
        if (!is_string($key) || strlen($key) < 16) throw new WhatsAppInboxDiagnosticValidationException();

        $db = \Illuminate\Support\Facades\DB::class;
        $out = ['diagnostic' => 'READ_ONLY', 'status' => 'READY'];
        self::stage('COUNT_QUARANTINE');
        $failureCount = (int) $db::table('whatsapp_inbox_ingestion_failures')->count();
        self::stage('COUNT_CONVERSATIONS');
        $out['conversations'] = (int) $db::table('whatsapp_inbox_conversations')
            ->where('waba_id', self::WABA)->where('phone_number_id', self::PHONE)->count();
        self::stage('COUNT_MESSAGES');
        $out['messages'] = (int) $db::table('whatsapp_inbox_messages as m')
            ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE)->count();
        self::stage('COUNT_PENDING');
        $out['pending_events'] = (int) $db::table('whatsapp_webhook_events')->whereNull('processed_at')->count();
        self::stage('COUNT_RUNNABLE');
        $out['pending_runnable'] = (int) $db::table('whatsapp_webhook_events')->whereNull('processed_at')
            ->whereNotExists(function ($query) {
                $query->select('event_id')->from('whatsapp_inbox_ingestion_failures')
                    ->whereColumn('whatsapp_inbox_ingestion_failures.event_id', 'whatsapp_webhook_events.id');
            })->count();
        $out['quarantine_total'] = $failureCount;
        $out['failures'] = [];
        self::stage('FAILURES_FETCH');
        $rows = $db::table('whatsapp_inbox_ingestion_failures as f')
            ->join('whatsapp_webhook_events as e', 'e.id', '=', 'f.event_id')
            ->orderBy('f.event_id')->limit(self::FAILURE_LIMIT)
            ->select('f.reason', 'e.payload')->get();
        foreach ($rows as $row) {
            $failure = [
                'label' => 'Q' . (count($out['failures']) + 1),
                'status' => 'READY',
                'reason' => in_array($row->reason, ['INVALID_EVENT', 'CONFLICTING_MESSAGE'], true) ? $row->reason : 'UNKNOWN',
                'normalized_messages' => null,
                'normalizer_quarantined' => null,
                'ignored_records' => null,
                'status_records' => null,
                'messages_truncated' => null,
                'comparisons' => [],
            ];
            try {
                $payload = self::decode($row->payload, 'FAILURE_PAYLOAD');
                self::stage('FAILURE_NORMALIZE');
                $report = \App\Support\WhatsAppInboxProtocol::report($payload, self::WABA, self::PHONE);
                unset($payload);
                self::stage('FAILURE_REPORT_VALIDATE');
                if (!is_array($report['messages'] ?? null)) throw new WhatsAppInboxDiagnosticValidationException();
                $failure['normalized_messages'] = count($report['messages']);
                $failure['messages_truncated'] = count($report['messages']) > self::MESSAGE_LIMIT;
                foreach (['quarantined_count' => 'normalizer_quarantined', 'ignored_count' => 'ignored_records', 'status_count' => 'status_records'] as $field => $counter) {
                    if (!is_int($report[$field] ?? null) || $report[$field] < 0) throw new WhatsAppInboxDiagnosticValidationException();
                    $failure[$counter] = $report[$field];
                }
                foreach (array_slice($report['messages'], 0, self::MESSAGE_LIMIT) as $message) {
                    $comparison = ['label' => 'M' . (count($failure['comparisons']) + 1), 'status' => 'READY'];
                    try {
                        self::assertDto($message, 'EVENT_DTO_VALIDATE');
                        self::compare($message, $key, $comparison);
                    } catch (\Throwable $error) {
                        $comparison['status'] = 'FAILED';
                        $comparison += self::failure($error);
                        $failure['comparisons'][] = $comparison;
                        throw $error;
                    }
                    $failure['comparisons'][] = $comparison;
                }
                unset($report, $message);
            } catch (\Throwable $error) {
                $failure['status'] = 'FAILED';
                $failure += self::failure($error);
                $out['status'] = 'PARTIAL';
            }
            $out['failures'][] = $failure;
        }
        $out['quarantine_rows_read'] = count($out['failures']);
        $out['quarantine_rows_omitted'] = max(0, $failureCount - count($out['failures']));
        $out['quarantine_rows_truncated'] = $out['quarantine_rows_omitted'] > 0;
        $out['probe'] = self::probe($key);
        if ($out['probe']['status'] === 'FAILED') $out['status'] = 'PARTIAL';
        unset($key);
        return $out;
    }

    private static function decode($ciphertext, string $context): array
    {
        $stages = self::DECODE_STAGES[$context];
        self::stage($stages[0]);
        if (!is_string($ciphertext)) throw new WhatsAppInboxDiagnosticValidationException();
        self::stage($stages[1]);
        $json = \Illuminate\Support\Facades\Crypt::decryptString($ciphertext);
        self::stage($stages[2]);
        if (strlen($json) > self::MAX_BYTES) throw new WhatsAppInboxDiagnosticValidationException();
        self::stage($stages[3]);
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        unset($json);
        self::stage($stages[4]);
        if (!is_array($value)) throw new WhatsAppInboxDiagnosticValidationException();
        return $value;
    }

    private static function assertDto($dto, string $stage): void
    {
        self::stage($stage);
        if (!is_array($dto)) throw new WhatsAppInboxDiagnosticValidationException();
        foreach (['message_id', 'peer_identity', 'direction', 'type', 'sent_at', 'source'] as $field) {
            if (!is_string($dto[$field] ?? null) || $dto[$field] === '') throw new WhatsAppInboxDiagnosticValidationException();
        }
        foreach (['peer_user_id', 'peer_phone', 'customer_name', 'text'] as $field) {
            if (!array_key_exists($field, $dto) || ($dto[$field] !== null && !is_string($dto[$field]))) throw new WhatsAppInboxDiagnosticValidationException();
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
            || !is_array($dto['content']['sources'] ?? null)) throw new WhatsAppInboxDiagnosticValidationException();
        foreach ($dto['content']['sources'] as $source) {
            if (!in_array($source, ['messages', 'standby', 'smb_message_echoes'], true)) throw new WhatsAppInboxDiagnosticValidationException();
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

    private static function compare(array $message, string $key, array &$out): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        self::stage('COMPARISON_KEYS');
        $peerHash = self::peerHash($message, $key);
        $out += [
            'direction' => $message['direction'],
            'source' => $message['source'],
            'type' => in_array($message['type'], self::BODY_FIELDS, true) ? $message['type'] : 'OTHER',
            'existing_message' => null,
            'linked_conversation' => null,
            'peer_conversation' => null,
            'scope_match' => null,
            'peer_hash_match' => null,
            'direction_match' => null,
            'type_match' => null,
            'scope_identity_or_columns_conflict' => null,
            'dto_conflict' => null,
            'body_conflict' => null,
            'customer_identity_conflict' => null,
            'dto_diff_fields' => [],
            'content_diff_fields' => [],
            'customer_identity_diff_fields' => [],
            'identity_comparison' => null,
            'text_comparison' => null,
        ];
        self::stage('COMPARISON_MESSAGE_FETCH');
        $existing = $db::table('whatsapp_inbox_messages')->where('message_key', self::messageKey($message))->first();
        $out['existing_message'] = $existing !== null;
        self::stage('COMPARISON_LINKED_FETCH');
        $linked = $existing ? $db::table('whatsapp_inbox_conversations')->where('id', $existing->conversation_id)->first() : null;
        $out['linked_conversation'] = $linked !== null;
        self::stage('COMPARISON_PEER_FETCH');
        $peerConversation = $db::table('whatsapp_inbox_conversations')->where('waba_id', self::WABA)
            ->where('phone_number_id', self::PHONE)->where('peer_hash', $peerHash)->first();
        $out['peer_conversation'] = $peerConversation !== null;
        self::stage('COMPARISON_SCOPE_INVARIANTS');
        $out['scope_match'] = $linked ? $linked->waba_id === self::WABA && $linked->phone_number_id === self::PHONE : null;
        $out['peer_hash_match'] = $linked ? hash_equals((string) $linked->peer_hash, $peerHash) : null;
        $out['direction_match'] = $existing ? $existing->direction === $message['direction'] : null;
        $out['type_match'] = $existing ? $existing->type === $message['type'] : null;
        $out['scope_identity_or_columns_conflict'] = false;
        $out['dto_conflict'] = $existing ? null : false;
        $out['body_conflict'] = $existing ? null : false;
        $out['customer_identity_conflict'] = $existing || !$peerConversation ? false : null;
        if ($existing) {
            $out['scope_identity_or_columns_conflict'] = !$linked || !$out['scope_match']
                || !$out['peer_hash_match'] || !$out['direction_match'] || !$out['type_match'];
            $saved = self::decode($existing->content, 'STORED_CONTENT');
            self::assertDto($saved, 'STORED_DTO_VALIDATE');
            self::stage('COMPARISON_DTO_FIELDS');
            foreach (['message_id', 'peer_identity', 'direction', 'type', 'text', 'sent_at'] as $field) {
                if ($saved[$field] !== $message[$field]) $out['dto_diff_fields'][] = $field;
            }
            $out['dto_conflict'] = $out['dto_diff_fields'] !== [];
            self::stage('COMPARISON_CONTENT_FIELDS');
            $fields = array_unique(array_merge([$message['type']], self::BODY_FIELDS));
            foreach ($fields as $field) {
                if (self::canonical($saved['content']['message'][$field] ?? null)
                    !== self::canonical($message['content']['message'][$field] ?? null)) {
                    // Never emit an unrecognized field name from the payload.
                    $out['content_diff_fields'][] = in_array($field, self::BODY_FIELDS, true) ? $field : 'OTHER';
                }
            }
            $out['body_conflict'] = $out['content_diff_fields'] !== [];
            self::stage('COMPARISON_IDENTITY');
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
                self::stage('COMPARISON_TEXT');
                $oldText = $saved['content']['message']['text'] ?? null;
                $newText = $message['content']['message']['text'] ?? null;
                if (!is_array($oldText) || !is_array($newText)) throw new WhatsAppInboxDiagnosticValidationException();
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
            $customer = self::decode($peerConversation->customer, 'CUSTOMER_CONTENT');
            self::stage('COMPARISON_CUSTOMER_IDENTITY');
            foreach (['phone' => 'peer_phone', 'user_id' => 'peer_user_id'] as $field => $incoming) {
                if (($customer[$field] ?? null) !== null && $message[$incoming] !== null
                    && $customer[$field] !== $message[$incoming]) $out['customer_identity_diff_fields'][] = $field;
            }
            $out['customer_identity_conflict'] = !$existing && $out['customer_identity_diff_fields'] !== [];
        }
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
        $out = [
            'label' => 'WA-0121',
            'status' => 'READY',
            'scoped_messages_at_ceiling' => null,
            'messages_scanned' => 0,
            'range_complete' => false,
            'inbound_matches' => 0,
            'outbound_matches' => 0,
            'matching_conversations' => 0,
            'same_conversation' => 'FAILED',
        ];
        $threads = [];
        try {
            $db = \Illuminate\Support\Facades\DB::class;
            $base = function () use ($db) {
                return $db::table('whatsapp_inbox_messages as m')
                    ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                    ->where('c.waba_id', self::WABA)->where('c.phone_number_id', self::PHONE);
            };
            // Keep the ceiling in memory so incoming messages cannot move this scan.
            self::stage('PROBE_CEILING');
            $ceiling = $base()->max('m.id');
            self::stage('PROBE_COUNT');
            $total = $ceiling === null ? 0 : (int) $base()->where('m.id', '<=', $ceiling)->count();
            $out['scoped_messages_at_ceiling'] = $total;
            self::stage('PROBE_FETCH');
            $rows = $ceiling === null ? [] : $base()->where('m.id', '<=', $ceiling)
                ->orderByDesc('m.id')->limit(self::PROBE_LIMIT)
                ->select('m.message_key', 'm.conversation_id', 'm.content', 'm.direction', 'm.type', 'c.peer_hash')->get();
            foreach ($rows as $row) {
                $message = self::decode($row->content, 'PROBE_CONTENT');
                self::assertDto($message, 'PROBE_DTO_VALIDATE');
                self::stage('PROBE_MESSAGE_INVARIANTS');
                if ($message['direction'] !== $row->direction || $message['type'] !== $row->type
                    || !hash_equals((string) $row->message_key, self::messageKey($message))
                    || !hash_equals((string) $row->peer_hash, self::peerHash($message, $key))) throw new WhatsAppInboxDiagnosticValidationException();
                $out['messages_scanned']++;
                self::stage('PROBE_MARKER');
                if (!is_string($message['text']) || strpos($message['text'], 'WA-0121') === false) continue;
                if ($message['direction'] === 'inbound') $out['inbound_matches']++;
                else $out['outbound_matches']++;
                $threads[(string) $row->conversation_id] = true;
                $out['matching_conversations'] = count($threads);
            }
            self::stage('PROBE_RANGE_VALIDATE');
            $complete = $total <= self::PROBE_LIMIT && $out['messages_scanned'] === $total;
            $out['range_complete'] = $complete;
            $out['same_conversation'] = !$complete ? 'PARTIAL'
                : ($out['inbound_matches'] + $out['outbound_matches'] === 0 ? 'MISSING'
                : (count($threads) > 1 ? 'AMBIGUOUS'
                : ($out['inbound_matches'] > 0 && $out['outbound_matches'] > 0 ? 'YES' : 'PARTIAL')));
        } catch (\Throwable $error) {
            $out['status'] = 'FAILED';
            $out['same_conversation'] = 'FAILED';
            $out['range_complete'] = false;
            $out += self::failure($error);
        }
        return $out;
    }
}

try {
    $result = WhatsAppInboxReadOnlyDiagnostic::run();
    WhatsAppInboxReadOnlyDiagnostic::stage('OUTPUT_JSON');
    $output = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    while (ob_get_level() > 0) ob_end_clean();
    echo $output . "\n";
} catch (\Throwable $error) {
    $failure = ['diagnostic' => 'FAILED', 'status' => 'FAILED'] + WhatsAppInboxReadOnlyDiagnostic::failure($error);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($failure, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}
