<?php
// Fixed, bounded read-only inspection. No customer content or native identifiers leave this helper.
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1) throw new \RuntimeException();
    if (!chdir('/home/fasakha/public_html')) throw new \RuntimeException();
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::class;
    $crypt = \Illuminate\Support\Facades\Crypt::class;
    $waba = '468336579702269'; $phone = '515388018324075';
    $decode = static function ($ciphertext) use ($crypt): array {
        if (!is_string($ciphertext) || strlen($ciphertext) > 8388608) throw new \RuntimeException();
        $bytes = $crypt::decryptString($ciphertext);
        if (strlen($bytes) > 4194304) throw new \RuntimeException();
        $result = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new \RuntimeException();
        return $result;
    };
    $stamp = static function ($value): ?string {
        if (!is_string($value)) return null;
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value)) {
            $value = str_replace('T', ' ', substr($value, 0, -1));
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d H:i:s') === $value ? $value : null;
    };
    $reason = static fn ($v) => $v === null ? null
        : (is_string($v) && preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/', $v) ? $v : 'UNKNOWN');
    $marker = static fn ($v) => is_string($v)
        && preg_match('/(?<![A-Za-z0-9])WA-0121(?![A-Za-z0-9])/i', $v) === 1;
    $scoped = static fn () => $db::table('whatsapp_inbox_messages as m')
        ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
        ->where('c.waba_id', $waba)->where('c.phone_number_id', $phone);
    $messageCeiling = (int) ($scoped()->max('m.id') ?? 0);
    $messageTotal = (int) $scoped()->where('m.id', '<=', $messageCeiling)->count();
    $rows = $scoped()->where('m.id', '<=', $messageCeiling)->orderByDesc('m.id')->limit(200)
        ->get(['m.id', 'm.conversation_id', 'm.direction', 'm.content', 'm.sent_at']);
    $probe = null; $scanUnreadable = 0;
    foreach ($rows as $row) {
        if ($row->direction !== 'inbound') continue;
        try { $dto = $decode($row->content); }
        catch (\Throwable $error) { $scanUnreadable++; continue; }
        if (is_string($dto['text'] ?? null) && trim($dto['text']) === 'WA-0121') {
            $probe = ['row' => $row, 'dto' => $dto]; break;
        }
    }
    $out = ['diagnostic' => 'READ_ONLY_LIVE_PROBE', 'writes' => 0, 'network_requests' => 0,
        'marker' => 'WA-0121', 'probe_found' => $probe !== null,
        'message_ceiling' => $messageCeiling, 'message_window_limit' => 200,
        'message_window_rows' => count($rows), 'message_window_complete' => $messageTotal <= 200,
        'message_window_unreadable' => $scanUnreadable];
    if ($probe !== null) {
        $conversation = (int) $probe['row']->conversation_id;
        $dto = $probe['dto'];
        $identity = $dto['peer_identity'] ?? null;
        $peerPhone = $dto['peer_phone'] ?? null; $peerUser = $dto['peer_user_id'] ?? null;
        if (!is_string($identity) || strlen($identity) > 520
            || (!is_string($peerPhone) && !is_string($peerUser))) throw new \RuntimeException();
        $out['conversation_id'] = $conversation;
        $out['probe_message_id'] = (int) $probe['row']->id;
        $out['probe_sent_at_utc'] = $stamp($probe['row']->sent_at);
        $out['peer_namespace'] = $peerUser !== null ? 'USER' : 'PHONE';
        $out['reply_state'] = ['status' => 'FAILED'];
        try {
            $actor = \App\Models\User::withoutGlobalScopes()->find(1);
            if (!$actor) throw new \RuntimeException();
            $state = app(\App\Services\Dashboard\WhatsAppReplyService::class)->state($actor, $conversation);
            $out['reply_state'] = ['status' => 'READY', 'available' => ($state['available'] ?? null) === true,
                'can_reply' => ($state['can_reply'] ?? null) === true,
                'reason' => $reason($state['reason'] ?? null),
                'latest_inbound_id' => is_int($state['latest_inbound_id'] ?? null) ? $state['latest_inbound_id'] : null,
                'window_expires_at_utc' => $stamp($state['window_expires_at'] ?? null),
                'voice_ready' => ($state['voice_ready'] ?? null) === true];
        } catch (\Throwable $error) {}
        $requests = $db::table('whatsapp_reply_requests')->where('conversation_id', $conversation)
            ->where('waba_id', $waba)->where('phone_number_id', $phone);
        $requestTotal = (int) (clone $requests)->count();
        $out['reply_requests_truncated'] = $requestTotal > 10; $out['reply_requests'] = [];
        foreach ($requests->orderByDesc('id')->limit(10)->get([
            'id', 'state', 'reason', 'kind', 'inbound_message_id', 'remote_message_key',
            'claimed_at', 'send_started_at', 'completed_at', 'created_at', 'updated_at'
        ]) as $row) {
            $out['reply_requests'][] = ['id' => (int) $row->id,
                'state' => in_array($row->state, ['CLAIMED', 'UNKNOWN', 'ACCEPTED', 'FAILED'], true) ? $row->state : 'OTHER',
                'reason' => $reason($row->reason), 'kind' => in_array($row->kind, ['text', 'audio'], true) ? $row->kind : 'OTHER',
                'inbound_message_id' => (int) $row->inbound_message_id,
                'remote_message_key_present' => is_string($row->remote_message_key) && $row->remote_message_key !== '',
                'claimed_at_utc' => $stamp($row->claimed_at), 'send_started_at_utc' => $stamp($row->send_started_at),
                'completed_at_utc' => $stamp($row->completed_at), 'created_at_utc' => $stamp($row->created_at),
                'updated_at_utc' => $stamp($row->updated_at)];
        }
        $ownMessages = $db::table('whatsapp_inbox_messages')->where('conversation_id', $conversation);
        $out['projected_messages_truncated'] = (clone $ownMessages)->count() > 20;
        $out['projected_messages'] = [];
        foreach ($ownMessages->orderByDesc('id')->limit(20)->get(['id', 'content', 'direction', 'source', 'type', 'sent_at', 'created_at']) as $row) {
            $summary = ['id' => (int) $row->id, 'direction' => in_array($row->direction, ['inbound', 'outbound'], true) ? $row->direction : 'OTHER',
                'source' => in_array($row->source, ['messages', 'smb_message_echoes', 'standby'], true) ? $row->source : 'OTHER',
                'type' => in_array($row->type, ['text', 'image', 'video', 'audio', 'document', 'order', 'unsupported'], true) ? $row->type : 'OTHER',
                'sent_at_utc' => $stamp($row->sent_at), 'created_at_utc' => $stamp($row->created_at),
                'readable' => false, 'probe_match' => false, 'exact_reply_match' => false];
            try { $message = $decode($row->content); $summary['readable'] = true;
                $summary['probe_match'] = $marker($message['text'] ?? null);
                $summary['exact_reply_match'] = ($message['text'] ?? null) === 'تم الاختبار WA-0121';
            } catch (\Throwable $error) {}
            $out['projected_messages'][] = $summary;
        }
        $library = __DIR__ . '/whatsapp_capture_gap_diagnostic.php';
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        for ($parent = dirname($library); ; $parent = dirname($parent)) {
            $ps = lstat($parent);
            if (!$ps || ($ps['mode'] & 0170000) !== 0040000
                || !in_array($ps['uid'], [0, $owner], true)) throw new \RuntimeException();
            if ($parent === '/') break;
        }
        $fd = fopen($library, 'rb');
        if (!$fd) throw new \RuntimeException();
        $s = fstat($fd); $ls = lstat($library);
        if (!$s || !$ls || ($s['mode'] & 0170000) !== 0100000 || $s['nlink'] !== 1
            || !in_array($s['uid'], [0, $owner], true) || ($s['mode'] & 0022)
            || $s['size'] > 1048576 || $ls['ino'] !== $s['ino'] || $ls['dev'] !== $s['dev']) throw new \RuntimeException();
        $bytes = stream_get_contents($fd, 1048577); fclose($fd);
        if (!is_string($bytes) || sha1('blob ' . strlen($bytes) . "\0" . $bytes)
            !== 'ee3720ac5d198fe2d49282040ea12f7f98bfb66a') throw new \RuntimeException();
        eval(substr($bytes, 5));
        $gap = new \WhatsAppCaptureGapDiagnostic(static function (string $key, callable $sanitize) use ($db, $decode, $waba, $phone, $conversation): array {
            $row = $db::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('m.message_key', $key)->where('c.waba_id', $waba)->where('c.phone_number_id', $phone)
                ->first(['m.id', 'm.conversation_id', 'm.direction', 'm.source', 'm.type', 'm.content']);
            if (!$row) return ['status' => 'MISSING'];
            try { $saved = $decode($row->content);
                return ['status' => 'FOUND', 'message_id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id,
                    'same_probe_conversation' => (int) $row->conversation_id === $conversation, 'identity' => $sanitize($saved),
                    'columns_agree' => $row->direction === ($saved['direction'] ?? null)
                        && $row->source === ($saved['source'] ?? null) && $row->type === ($saved['type'] ?? null)];
            } catch (\Throwable $error) { return ['status' => 'UNREADABLE']; }
        });
        $ownDto = static fn (array $message) => ($message['peer_identity'] ?? null) === $identity
            || ($peerPhone !== null && ($message['peer_phone'] ?? null) === $peerPhone)
            || ($peerUser !== null && ($message['peer_user_id'] ?? null) === $peerUser);
        $nodes = 0; $nativeTruncated = false;
        $nativeMatch = function ($node, int $depth = 0, ?string $side = null) use (&$nativeMatch, &$nodes, &$nativeTruncated, $peerPhone, $peerUser): bool {
            if (!is_array($node)) return false;
            if (++$nodes > 20000 || $depth > 32) { $nativeTruncated = true; return false; }
            foreach ($node as $key => $value) {
                if (is_string($value)) {
                    if ($key === 'id' && in_array($side, ['sender', 'recipient'], true)
                        && (($peerPhone !== null && ltrim($value, '+') === ltrim($peerPhone, '+'))
                            || ($peerUser !== null && $value === $peerUser))) return true;
                    if ($peerPhone !== null && in_array($key, ['from', 'to', 'recipient_id', 'wa_id'], true)
                        && ltrim($value, '+') === ltrim($peerPhone, '+')) return true;
                    if ($peerUser !== null && in_array($key, ['from_user_id', 'to_user_id', 'recipient_user_id', 'user_id'], true)
                        && $value === $peerUser) return true;
                }
                if (is_array($value) && $nativeMatch($value, $depth + 1, is_string($key) ? $key : null)) return true;
            }
            return false;
        };
        $sent = $out['probe_sent_at_utc'];
        if ($sent === null) throw new \RuntimeException();
        $since = (new \DateTimeImmutable($sent, new \DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s');
        $ceiling = (int) ($db::table('whatsapp_webhook_events')->max('id') ?? 0);
        $raw = $db::table('whatsapp_webhook_events')->where('id', '<=', $ceiling)->where('received_at', '>=', $since);
        $rawTotal = (int) (clone $raw)->count();
        $rawRows = $raw->orderByDesc('id')->limit(100)->get(['id', 'received_at', 'processed_at']);
        $rawBytes = 0; $cipherBytes = 0; $read = 0; $unreadable = 0; $matches = 0; $outputOmitted = 0;
        $byteTruncated = false; $captureDetailsIncomplete = false;
        $out['matching_raw_events'] = [];
        foreach ($rawRows as $row) {
            try {
                $ciphertext = $db::table('whatsapp_webhook_events')->where('id', $row->id)->value('payload');
                if (!is_string($ciphertext)) throw new \RuntimeException();
                if ($cipherBytes + strlen($ciphertext) > 16777216) { $byteTruncated = true; break; }
                $cipherBytes += strlen($ciphertext);
                $payload = $decode($ciphertext);
                unset($ciphertext);
                $size = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
                if ($rawBytes + $size > 16777216) { $byteTruncated = true; break; }
                $rawBytes += $size; $read++; $nodes = 0;
                $normal = \App\Support\WhatsAppInboxProtocol::report($payload, $waba, $phone);
                $match = false;
                foreach (($normal['messages'] ?? []) as $message) if (is_array($message) && $ownDto($message)) { $match = true; break; }
                if (!$match && ($payload['object'] ?? null) === 'whatsapp_business_account') {
                    $entries = $payload['entry'] ?? [];
                    if (!is_array($entries)) throw new \RuntimeException();
                    if (count($entries) > 100) $nativeTruncated = true;
                    foreach (array_slice($entries, 0, 100) as $entry) {
                        if (!is_array($entry) || ($entry['id'] ?? null) !== $waba) continue;
                        $changes = $entry['changes'] ?? [];
                        if (!is_array($changes)) throw new \RuntimeException();
                        if (count($changes) > 200) $nativeTruncated = true;
                        foreach (array_slice($changes, 0, 200) as $change) {
                            $value = is_array($change) ? ($change['value'] ?? null) : null;
                            if (!is_array($value) || ($value['metadata']['phone_number_id'] ?? null) !== $phone) continue;
                            if ($nativeMatch($value)) { $match = true; break 2; }
                        }
                    }
                }
                if (!$match) continue;
                $matches++;
                if (count($out['matching_raw_events']) >= 10) { $outputOmitted++; continue; }
                $failure = $db::table('whatsapp_inbox_ingestion_failures')->where('event_id', $row->id)->value('reason');
                $capture = $gap->inspect($payload);
                foreach (['records_omitted', 'changes_omitted', 'entries_omitted', 'normalizations_omitted'] as $counter) {
                    if (($capture[$counter] ?? 0) > 0) $captureDetailsIncomplete = true;
                }
                $out['matching_raw_events'][] = ['event_id' => (int) $row->id, 'received_at_utc' => $stamp($row->received_at),
                    'processed' => $row->processed_at !== null, 'quarantine_reason' => $reason($failure),
                    'capture' => $capture];
            } catch (\Throwable $error) { $unreadable++; }
        }
        $out['raw_window'] = ['since_received_utc' => $since, 'event_ceiling' => $ceiling,
            'total_events' => $rawTotal, 'event_limit' => 100, 'events_read' => $read,
            'events_omitted_by_limit' => max(0, $rawTotal - count($rawRows)), 'unreadable_events' => $unreadable,
            'byte_limit' => 16777216, 'ciphertext_bytes' => $cipherBytes,
            'decoded_bytes' => $rawBytes, 'byte_limit_reached' => $byteTruncated,
            'native_identity_scan_truncated' => $nativeTruncated, 'matching_events' => $matches,
            'matching_events_omitted' => $outputOmitted,
            'capture_details_incomplete' => $captureDetailsIncomplete,
            'range_complete' => $rawTotal <= 100 && !$byteTruncated && !$nativeTruncated && $unreadable === 0,
            'matching_details_complete' => !$captureDetailsIncomplete && $outputOmitted === 0];
    }
    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 1048576) {
        $out['matching_raw_events'] = []; $out['output_details_omitted'] = true;
        if (isset($out['raw_window'])) $out['raw_window']['range_complete'] = false;
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    while (ob_get_level() > 0) ob_end_clean();
    echo $json . "\n";
} catch (\Throwable $error) {
    while (ob_get_level() > 0) ob_end_clean();
    echo "LIVE_PROBE_DIAGNOSTIC_FAILED_WITHOUT_SECRET_OUTPUT\n"; exit(1);
}
