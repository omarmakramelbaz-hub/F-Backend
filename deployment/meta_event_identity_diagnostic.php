<?php

// CLI-only, bounded reads and Graph GETs. Never repairs data, retries events or sends messages.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class MetaEventIdentityDiagnostic
{
    private const APP = '2177982783065133';
    private const EVENT_LIMIT = 50;
    private const SHAPE_LIMIT = 20;
    private const MAX_BYTES = 4194304;
    private const SCOPES = ['pages_messaging', 'pages_show_list', 'pages_manage_metadata',
        'business_management', 'instagram_basic', 'instagram_manage_messages',
        'instagram_business_basic', 'instagram_business_manage_messages'];
    private const KINDS = ['message', 'delivery', 'read', 'postback', 'reaction', 'referral',
        'optin', 'account_linking', 'pass_thread_control', 'take_thread_control', 'request_thread_control'];
    private const CHANGE_FIELDS = ['comments', 'live_comments', 'mentions', 'messages',
        'message_reactions', 'messaging_seen', 'messaging_postbacks', 'messaging_optins', 'standby'];
    // Diagnostic candidates only. Their presence does not establish a supported message format.
    private const EXTRA_MESSAGE_FIELDS = ['seq', 'sticker_id', 'nlp', 'is_redacted', 'redacted',
        'is_unavailable', 'unavailable', 'is_e2ee', 'is_encrypted', 'encrypted', 'is_limited',
        'is_removed', 'is_restricted', 'unsupported', 'is_unsupported_message', 'type',
        'message', 'payload', 'data', 'content', 'value', 'error', 'errors'];
    private $accounts;
    private $transport;
    private $started;
    private $requests = 0;
    private $projectedHashes = [];
    private $hmacKey;

    public function __construct(array $accounts, ?callable $transport = null)
    {
        if (count($accounts) > 10) throw new \InvalidArgumentException();
        $this->accounts = [];
        foreach ($accounts as $a) {
            if (!is_array($a) || !in_array($a['channel'] ?? null, ['messenger', 'instagram'], true)
                || !self::id($a['account_id'] ?? null)) throw new \InvalidArgumentException();
            $key = $a['channel'].':'.$a['account_id'];
            if (isset($this->accounts[$key])) throw new \InvalidArgumentException();
            $this->accounts[$key] = $a;
        }
        $this->transport = $transport;
    }

    private static function id($v): bool
    {
        return is_string($v) && preg_match('/\A[1-9][0-9]{0,31}\z/', $v) === 1;
    }

    private static function shape(array $values, string $key): array
    {
        $present = array_key_exists($key, $values); $v = $values[$key] ?? null;
        return ['present' => $present, 'type' => get_debug_type($v),
            'bytes' => is_string($v) ? strlen($v) : null];
    }

    public function inspect(array $payload): array
    {
        $out = ['kinds' => [], 'changes' => [], 'message_shapes' => [], 'shapes_omitted' => 0];
        $channel = ($payload['object'] ?? null) === 'page' ? 'messenger'
            : (($payload['object'] ?? null) === 'instagram' ? 'instagram' : null);
        $entries = $payload['entry'] ?? null;
        if ($channel === null || !is_array($entries) || !array_is_list($entries) || count($entries) > 100)
            throw new \UnexpectedValueException();
        foreach ($entries as $entry) {
            if (!is_array($entry) || !self::id($entry['id'] ?? null)) throw new \UnexpectedValueException();
            $key = $channel.':'.$entry['id'];
            if (!isset($this->accounts[$key])) continue;
            foreach (['messaging', 'standby'] as $source) {
                $events = $entry[$source] ?? [];
                if (!is_array($events) || !array_is_list($events) || count($events) > 1000) throw new \UnexpectedValueException();
                foreach ($events as $event) {
                    if (!is_array($event)) throw new \UnexpectedValueException();
                    $known = array_intersect(self::KINDS, array_keys($event));
                    foreach ($known ?: ['OTHER'] as $kind) {
                        $bucket = $key.':'.$source.':'.$kind;
                        $out['kinds'][$bucket] = ($out['kinds'][$bucket] ?? 0) + 1;
                    }
                    if (!array_key_exists('message', $event)) continue;
                    $m = $event['message']; $sender = $event['sender']['id'] ?? null;
                    $recipient = $event['recipient']['id'] ?? null;
                    $direction = $recipient === $entry['id'] && $sender !== $entry['id'] ? 'inbound'
                        : ($sender === $entry['id'] && $recipient !== $entry['id'] ? 'outbound' : 'INVALID');
                    if ($direction === 'outbound' && is_array($m) && isset($m['mid'])) continue;
                    if (count($out['message_shapes']) >= self::SHAPE_LIMIT) { $out['shapes_omitted']++; continue; }
                    $peer = $direction === 'outbound' ? $recipient : $sender;
                    $shape = ['account' => $key, 'source' => $source, 'direction' => $direction,
                        'sender_valid' => self::id($sender), 'recipient_valid' => self::id($recipient),
                        'peer_digits' => self::id($peer) ? strlen($peer) : null,
                        'message_array' => is_array($m), 'timestamp_type' => get_debug_type($event['timestamp'] ?? null)];
                    $shape['peer_matches_projected_sample'] = is_string($this->hmacKey) && self::id($peer)
                        ? in_array(hash_hmac('sha256', 'whatsapp-peer-v1:'.$key.':user:'.$peer, $this->hmacKey), $this->projectedHashes[$key] ?? [], true) : null;
                    foreach (['id', 'message_id'] as $field) $shape['event_identifier_fields'][$field] = self::shape($event, $field);
                    if (is_array($m)) {
                        foreach (['mid', 'id', 'message_id'] as $field) $shape[$field] = self::shape($m, $field);
                        foreach (['text', 'attachments', 'is_echo', 'is_deleted', 'is_unsupported', 'metadata',
                            'quick_reply', 'reply_to', 'referral', 'app_id'] as $field)
                            $shape['field_types'][$field] = array_key_exists($field, $m) ? get_debug_type($m[$field]) : 'ABSENT';
                        $shape['unknown_message_key_count'] = count(array_diff(array_keys($m),
                            ['mid', 'id', 'message_id', 'text', 'attachments', 'is_echo', 'is_deleted',
                                'is_unsupported', 'metadata', 'quick_reply', 'reply_to', 'referral', 'app_id']));
                        $shape['extra_field_candidates'] = [];
                        $shape['unrecognized_field_shapes'] = [];
                        $shape['unrecognized_fields_omitted'] = 0;
                        foreach ($m as $field => $value) {
                            if (in_array($field, ['mid', 'id', 'message_id', 'text', 'attachments', 'is_echo', 'is_deleted',
                                'is_unsupported', 'metadata', 'quick_reply', 'reply_to', 'referral', 'app_id'], true)) continue;
                            $detail = ['type' => get_debug_type($value)];
                            if (is_bool($value)) $detail['boolean'] = $value;
                            if (is_array($value)) {
                                $detail['array_count'] = count($value);
                                foreach (['mid', 'id', 'message_id', 'text', 'attachments', 'message', 'payload', 'error', 'code'] as $nested)
                                    if (array_key_exists($nested, $value)) $detail['nested_field_types'][$nested] = get_debug_type($value[$nested]);
                            }
                            if (is_string($field) && in_array($field, self::EXTRA_MESSAGE_FIELDS, true))
                                $shape['extra_field_candidates'][$field] = $detail;
                            elseif (count($shape['unrecognized_field_shapes']) < 8)
                                $shape['unrecognized_field_shapes'][] = ['key_bytes' => is_string($field) ? strlen($field) : null,
                                    'type' => get_debug_type($value)];
                            else $shape['unrecognized_fields_omitted']++;
                        }
                        // Fixed comparison to a common test string; never return any customer text.
                        $shape['text_matches_hello_world'] = ($m['text'] ?? null) === 'Hello world';
                        $shape['text_utf8_valid'] = is_string($m['text'] ?? null) && function_exists('mb_check_encoding')
                            ? mb_check_encoding($m['text'], 'UTF-8') : null;
                        $attachments = $m['attachments'] ?? [];
                        $shape['attachments_list'] = is_array($attachments) && array_is_list($attachments);
                        $shape['attachment_count'] = is_array($attachments) ? count($attachments) : null;
                        $shape['attachment_types'] = [];
                        if (is_array($attachments)) foreach (array_slice($attachments, 0, 30) as $attachment) {
                            $kind = is_array($attachment) ? ($attachment['type'] ?? null) : null;
                            $kind = is_string($kind) && in_array($kind, ['image', 'audio', 'video', 'file', 'location'], true) ? $kind : 'OTHER';
                            $shape['attachment_types'][$kind] = ($shape['attachment_types'][$kind] ?? 0) + 1;
                        }
                    }
                    $out['message_shapes'][] = $shape;
                }
            }
            $changes = $entry['changes'] ?? [];
            if (!is_array($changes) || !array_is_list($changes) || count($changes) > 1000) throw new \UnexpectedValueException();
            foreach ($changes as $change) {
                $field = is_array($change) ? ($change['field'] ?? null) : null;
                $field = is_string($field) && in_array($field, self::CHANGE_FIELDS, true) ? $field : 'OTHER';
                $bucket = $key.':'.$field; $out['changes'][$bucket] = ($out['changes'][$bucket] ?? 0) + 1;
            }
        }
        return $out;
    }

    private static function decode($bytes): array
    {
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES * 2) throw new \UnexpectedValueException();
        $plain = \Illuminate\Support\Facades\Crypt::decryptString($bytes);
        if (strlen($plain) > self::MAX_BYTES) throw new \UnexpectedValueException();
        $p = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($p)) throw new \UnexpectedValueException();
        return $p;
    }

    public function local(): array
    {
        $db = \Illuminate\Support\Facades\DB::class; $started = microtime(true);
        $this->hmacKey = \Illuminate\Support\Facades\Crypt::getKey();
        $peerSamples = [];
        foreach ($this->accounts as $key => $a) {
            $query = $db::table('meta_inbox_conversations')->where('waba_id', $a['channel'])->where('phone_number_id', $a['account_id']);
            $count = (int) (clone $query)->count();
            $this->projectedHashes[$key] = (clone $query)->orderByDesc('id')->limit(100)->pluck('peer_hash')->all();
            $peerSamples[$key] = ['limit' => 100, 'read' => count($this->projectedHashes[$key]), 'conversations' => $count,
                'complete' => count($this->projectedHashes[$key]) === $count];
        }
        $ceiling = (int) $db::table('meta_webhook_events')->max('id');
        $total = (int) $db::table('meta_webhook_events')->where('id', '<=', $ceiling)->count();
        $rows = $db::table('meta_webhook_events')->where('id', '<=', $ceiling)
            ->orderByDesc('id')->limit(self::EVENT_LIMIT)->get(['id']);
        $out = ['through_event' => $ceiling, 'events_in_range' => $total, 'events_read' => 0,
            'unreadable_events' => 0, 'sample_complete' => false, 'kinds' => [], 'changes' => [],
            'message_shapes' => [], 'shapes_omitted' => 0, 'projected_peer_samples' => $peerSamples];
        foreach ($rows as $row) {
            if (microtime(true) - $started >= 10) break;
            $out['events_read']++;
            try {
                $p = self::decode($db::table('meta_webhook_events')->where('id', $row->id)->value('payload'));
                $detail = $this->inspect($p);
                foreach (['kinds', 'changes'] as $group) foreach ($detail[$group] as $key => $n)
                    $out[$group][$key] = ($out[$group][$key] ?? 0) + $n;
                $out['shapes_omitted'] += $detail['shapes_omitted'];
                foreach ($detail['message_shapes'] as $shape) {
                    if (count($out['message_shapes']) >= self::SHAPE_LIMIT) { $out['shapes_omitted']++; continue; }
                    $shape['event_id'] = (int) $row->id;
                    $shape['quarantined'] = $db::table('meta_inbox_ingestion_failures')->where('event_id', $row->id)->exists();
                    $out['message_shapes'][] = $shape;
                }
            } catch (\Throwable $e) { $out['unreadable_events']++; }
        }
        $out['sample_complete'] = $out['events_read'] === $total;
        return $out;
    }

    private function get(string $host, string $path, $token): array
    {
        if ($this->started === null) $this->started = microtime(true);
        $safe = ['status' => 'UNAVAILABLE', 'http_status' => null, 'error_code' => null, 'error_subcode' => null];
        if ($this->requests >= 12 || microtime(true) - $this->started >= 20) {
            $safe['status'] = 'BUDGET_EXHAUSTED'; return [$safe, null];
        }
        if (!is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('/[\s\x00-\x1f\x7f]/', $token)) {
            $safe['status'] = 'CREDENTIAL_UNAVAILABLE'; return [$safe, null];
        }
        $this->requests++;
        try {
            $url = 'https://'.$host.'/'.$path;
            if ($this->transport !== null) $r = ($this->transport)($url, $token);
            else {
                $bytes = ''; $curl = curl_init($url);
                $remaining = max(1, min(4, (int) ceil(20 - (microtime(true) - $this->started))));
                curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => min(2, $remaining), CURLOPT_TIMEOUT => $remaining,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json'],
                    CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$bytes) {
                        if (strlen($bytes) + strlen($chunk) > 65536) return 0;
                        $bytes .= $chunk; return strlen($chunk);
                    }]);
                try { $ok = curl_exec($curl); $r = ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $bytes]; }
                finally { curl_close($curl); }
            }
            if (!is_array($r) || !is_string($r['body'] ?? null) || strlen($r['body']) > 65536) throw new \UnexpectedValueException();
            $j = json_decode($r['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($j)) throw new \UnexpectedValueException();
            $http = $r['status'] ?? null; $safe['http_status'] = is_int($http) && $http >= 100 && $http <= 599 ? $http : null;
            if ($http !== 200 || array_key_exists('error', $j)) {
                $e = is_array($j['error'] ?? null) ? $j['error'] : [];
                foreach (['error_code' => 'code', 'error_subcode' => 'error_subcode'] as $k => $field)
                    $safe[$k] = is_int($e[$field] ?? null) && $e[$field] >= 0 && $e[$field] <= 2147483647 ? $e[$field] : null;
                $safe['status'] = $http === 0 ? 'TRANSPORT_FAILED' : 'API_REJECTED'; return [$safe, null];
            }
            $safe['status'] = 'READY'; return [$safe, $j];
        } catch (\Throwable $e) {
            $safe['status'] = $e instanceof \JsonException || $e instanceof \UnexpectedValueException ? 'INVALID_RESPONSE' : 'TRANSPORT_FAILED';
            return [$safe, null];
        }
    }

    public function graph(string $secret, string $version): array
    {
        if (!preg_match('/\Av[0-9]{1,2}\.0\z/', $version)) throw new \InvalidArgumentException();
        $out = ['expected_app_id' => self::APP, 'accounts' => [], 'requests' => 0];
        foreach (array_slice($this->accounts, 0, 4, true) as $key => $a) {
            $token = $a['access_token'] ?? null;
            $host = $a['channel'] === 'instagram' && ($a['login'] ?? 'facebook') === 'instagram' ? 'graph.instagram.com' : 'graph.facebook.com';
            $check = ['login' => ($a['login'] ?? 'facebook') === 'instagram' ? 'instagram' : 'facebook'];
            $check['token_debug'] = ['status' => 'APP_SECRET_UNAVAILABLE', 'token_valid' => null, 'app_matches' => null,
                'token_type' => null, 'profile_id_present' => null, 'profile_matches_configured_page' => null, 'scopes' => null];
            if ($host === 'graph.instagram.com') $check['token_debug']['status'] = 'FACEBOOK_DEBUG_NOT_APPLICABLE';
            elseif (preg_match('/\A[a-fA-F0-9]{32}\z/', $secret) && is_string($token) && $token !== '' && strlen($token) <= 8192
                && !preg_match('/[\s\x00-\x1f\x7f]/', $token)) {
                [$safe, $j] = $this->get('graph.facebook.com', $version.'/debug_token?input_token='.rawurlencode($token), self::APP.'|'.$secret);
                $check['token_debug'] = $safe + ['token_valid' => null, 'app_matches' => null, 'token_type' => null,
                    'profile_id_present' => null, 'profile_matches_configured_page' => null, 'scopes' => null];
                $d = $j['data'] ?? null;
                if ($safe['status'] === 'READY') {
                    if (!is_array($d) || !is_bool($d['is_valid'] ?? null)) $check['token_debug']['status'] = 'INVALID_RESPONSE';
                    else {
                        $check['token_debug']['token_valid'] = $d['is_valid'];
                        $check['token_debug']['app_matches'] = self::id($d['app_id'] ?? null) ? $d['app_id'] === self::APP : null;
                        $type = $d['type'] ?? null;
                        $check['token_debug']['token_type'] = in_array($type, ['PAGE', 'USER', 'SYSTEM_USER'], true) ? $type : 'OTHER';
                        $profile = $d['profile_id'] ?? null;
                        $check['token_debug']['profile_id_present'] = self::id($profile);
                        $pages = array_filter($this->accounts, static fn ($item) => $item['channel'] === 'messenger');
                        $check['token_debug']['profile_matches_configured_page'] = self::id($profile) && $pages
                            ? (bool) array_filter($pages, static fn ($item) => $item['account_id'] === $profile) : null;
                        $scopes = $d['scopes'] ?? null;
                        if (is_array($scopes) && array_is_list($scopes) && count($scopes) <= 100) {
                            $check['token_debug']['scopes'] = [];
                            foreach (self::SCOPES as $scope) $check['token_debug']['scopes'][$scope] = in_array($scope, $scopes, true);
                        }
                    }
                }
            }
            [$safe, $j] = $this->get($host, $version.'/me?fields=id', $token);
            $check['token_asset'] = $safe + ['matches_configured_account' => null,
                'matches_configured_messenger_page' => null];
            if ($safe['status'] === 'READY') {
                $me = $j['id'] ?? null;
                if (!self::id($me)) $check['token_asset']['status'] = 'INVALID_RESPONSE';
                else {
                    $check['token_asset']['matches_configured_account'] = $a['channel'] === 'messenger' || $host === 'graph.instagram.com'
                        ? $me === $a['account_id'] : null;
                    if ($host === 'graph.facebook.com') {
                        $pages = array_filter($this->accounts, static fn ($item) => $item['channel'] === 'messenger');
                        $check['token_asset']['matches_configured_messenger_page'] = $pages ? (bool) array_filter($pages, static fn ($item) => $item['account_id'] === $me) : null;
                        $igs = array_filter($this->accounts, static fn ($item) => $item['channel'] === 'instagram' && ($item['login'] ?? 'facebook') === 'facebook');
                        if ($igs && $check['token_asset']['matches_configured_messenger_page'] === true) {
                            [$linkedSafe, $linked] = $this->get($host, $version.'/me?fields=instagram_business_account%7Bid%7D', $token);
                            $check['linked_instagram'] = $linkedSafe + ['present' => null, 'matches_configured' => null];
                            if ($linkedSafe['status'] === 'READY') {
                                $ig = $linked['instagram_business_account']['id'] ?? null;
                                $check['linked_instagram']['present'] = self::id($ig);
                                $check['linked_instagram']['matches_configured'] = self::id($ig)
                                    ? (bool) array_filter($igs, static fn ($item) => $item['account_id'] === $ig) : null;
                            }
                        }
                    }
                }
            }
            $out['accounts'][$key] = $check;
        }
        $out['accounts_omitted'] = max(0, count($this->accounts) - 4); $out['requests'] = $this->requests;
        return $out;
    }

    public static function messageFlags(array $local): array
    {
        $out = ['diagnostic' => 'READ_ONLY_META_MESSAGE_FLAGS', 'writes' => 0];
        foreach (['through_event', 'events_in_range', 'events_read', 'unreadable_events', 'sample_complete',
            'kinds', 'changes', 'shapes_omitted'] as $key) $out[$key] = $local[$key];
        $out['message_shapes'] = [];
        foreach ($local['message_shapes'] as $s) $out['message_shapes'][] = [
            'event_id' => $s['event_id'], 'quarantined' => $s['quarantined'], 'account' => $s['account'],
            'direction' => $s['direction'], 'message_array' => $s['message_array'], 'mid' => $s['mid'] ?? null,
            'text_type' => $s['field_types']['text'] ?? null, 'attachment_count' => $s['attachment_count'] ?? null,
            'extra_field_candidates' => $s['extra_field_candidates'] ?? [],
            'unrecognized_field_shapes' => $s['unrecognized_field_shapes'] ?? [],
            'unrecognized_fields_omitted' => $s['unrecognized_fields_omitted'] ?? 0];
        return $out;
    }

    public static function arguments(array $args): bool
    {
        if ($args === []) return false;
        if ($args === ['--message-flags']) return true;
        throw new \InvalidArgumentException();
    }

    public static function run(): array
    {
        $flagsOnly = self::arguments(array_slice($_SERVER['argv'] ?? [], 1));
        $root = getcwd();
        if (!is_string($root) || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new \RuntimeException();
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $d = new self(\App\Services\Dashboard\MetaInboxAccess::accounts());
        $local = $d->local();
        if ($flagsOnly) return self::messageFlags($local);
        $secret = config('meta_messaging.app_secret', ''); $version = config('meta_messaging.api_version', 'v25.0');
        $graph = $d->graph(is_string($secret) ? $secret : '', is_string($version) ? $version : '');
        return ['diagnostic' => 'READ_ONLY_META_EVENT_IDENTITY', 'writes' => 0, 'local' => $local, 'graph' => $graph];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    try { $out = MetaEventIdentityDiagnostic::run(); $exit = 0; }
    catch (\Throwable $e) { $out = ['diagnostic' => 'READ_ONLY_META_EVENT_IDENTITY', 'status' => 'FAILED', 'writes' => 0]; $exit = 1; }
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"; exit($exit);
}
