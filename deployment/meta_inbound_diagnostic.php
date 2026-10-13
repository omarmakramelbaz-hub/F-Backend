<?php

// CLI only. Counts and fixed Graph GET probes; no subscription, routing or customer writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class MetaInboundDiagnostic
{
    private const DEFAULT_APP = '2177982783065133';
    private const MAX_BYTES = 4194304;
    private const MAX_GRAPH_BYTES = 262144;
    private const MAX_ACCOUNTS = 10;
    private const MAX_REQUESTS = 24;
    private const GRAPH_SECONDS = 20;
    private const LOCAL_SECONDS = 10;
    private const NAME_LIMIT = 100;
    private const FIELDS = ['messages', 'message_echoes', 'standby', 'messaging_postbacks', 'messaging_optins',
        'messaging_deliveries', 'messaging_reads', 'messaging_referrals', 'messaging_handovers',
        'messaging_policy_enforcement', 'messaging_account_linking', 'messaging_game_plays',
        'messaging_checkout_updates', 'message_deliveries', 'message_reads', 'message_reactions',
        'messaging_seen', 'messaging_reactions', 'messaging_feedback', 'comments', 'mentions',
        'live_comments', 'story_insights'];
    private const REASONS = ['SIZE', 'JSON', 'ENVELOPE', 'ACCOUNT', 'EVENTS', 'EVENT', 'MESSAGE',
        'ROUTING', 'TIME', 'TEXT', 'ATTACHMENTS', 'ATTACHMENT', 'LOCATION'];
    private $accounts;
    private $transport;
    private $requests = 0;
    private $graphStarted;

    public function __construct(array $accounts, ?callable $transport = null)
    {
        $this->accounts = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || !in_array($account['channel'] ?? null, ['messenger', 'instagram'], true)
                || !self::id($account['account_id'] ?? null)) throw new \InvalidArgumentException();
            $key = $account['channel'].':'.$account['account_id'];
            if (isset($this->accounts[$key]) || count($this->accounts) >= self::MAX_ACCOUNTS) throw new \InvalidArgumentException();
            $this->accounts[$key] = $account;
        }
        $this->transport = $transport;
    }

    private static function id($value): bool
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,31}\z/', $value) === 1;
    }

    public static function arguments(array $arguments): array
    {
        $out = ['app_id' => self::DEFAULT_APP, 'limit' => 100, 'after_event' => 0, 'through_event' => null]; $seen = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || !preg_match('/\A--(app-id|limit|after-event|through-event)=(0|[1-9][0-9]{0,31})\z/', $argument, $m)) throw new \InvalidArgumentException();
            $name = str_replace('-', '_', $m[1]);
            if (isset($seen[$name])) throw new \InvalidArgumentException();
            $seen[$name] = true;
            if ($name === 'app_id') {
                if (!self::id($m[2])) throw new \InvalidArgumentException();
                $out[$name] = $m[2]; continue;
            }
            if (strlen($m[2]) > strlen((string) PHP_INT_MAX)
                || (strlen($m[2]) === strlen((string) PHP_INT_MAX) && strcmp($m[2], (string) PHP_INT_MAX) > 0)) throw new \InvalidArgumentException();
            $out[$name] = (int) $m[2];
            if (($name === 'limit' && ($out[$name] < 1 || $out[$name] > 200))
                || ($name === 'through_event' && $out[$name] < 1)) throw new \InvalidArgumentException();
        }
        if ($out['through_event'] !== null && $out['through_event'] <= $out['after_event']) throw new \InvalidArgumentException();
        return $out;
    }

    private static function date($value): ?string
    {
        if (!is_string($value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    private static function stamp($value): ?string
    {
        if (!(is_int($value) || is_string($value)) || !preg_match('/\A[0-9]{12,14}\z/', (string) $value)) return null;
        $seconds = (int) floor((float) $value / 1000);
        return $seconds >= 946684800 && $seconds <= time() + 86400 ? gmdate('Y-m-d H:i:s', $seconds) : null;
    }

    public static function emptyCapture(): array
    {
        return ['events' => 0, 'processed_events' => 0, 'pending_events' => 0, 'quarantined_events' => 0,
            'quarantine_categories' => [], 'raw' => ['inbound' => 0, 'outbound' => 0, 'standby_messages' => 0,
                'routing_mismatch' => 0, 'malformed_records' => 0, 'non_message_events' => 0, 'records_omitted' => 0,
                'latest_inbound_at_utc' => null, 'latest_outbound_at_utc' => null],
            'normalized' => ['inbound' => 0, 'outbound' => 0, 'standby_messages' => 0, 'ignored' => 0,
                'invalid_events' => 0, 'categories' => [], 'latest_inbound_at_utc' => null, 'latest_outbound_at_utc' => null],
            'latest_received_at_utc' => null];
    }

    private static function latest(array &$out, string $key, ?string $date): void
    {
        if ($date !== null && ($out[$key] === null || $date > $out[$key])) $out[$key] = $date;
    }

    /** Counts raw shape separately from the actual deployed normalizer; never returns native peer IDs or content. */
    public function inspect(array $payload): array
    {
        $result = ['accounts' => [], 'unscoped_entries' => 0, 'malformed_envelopes' => 0];
        $channel = ($payload['object'] ?? null) === 'page' ? 'messenger'
            : (($payload['object'] ?? null) === 'instagram' ? 'instagram' : null);
        if ($channel === null || !is_array($payload['entry'] ?? null) || !array_is_list($payload['entry']) || count($payload['entry']) > 100) {
            $result['malformed_envelopes']++; return $result;
        }
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !self::id($entry['id'] ?? null)) { $result['malformed_envelopes']++; continue; }
            $key = $channel.':'.$entry['id'];
            if (!isset($this->accounts[$key])) { $result['unscoped_entries']++; continue; }
            if (!isset($result['accounts'][$key])) $result['accounts'][$key] = self::emptyCapture();
            $out = &$result['accounts'][$key];
            foreach (['messaging', 'standby'] as $source) {
                $events = $entry[$source] ?? [];
                if (!is_array($events) || !array_is_list($events)) { $out['raw']['malformed_records']++; continue; }
                $out['raw']['records_omitted'] += max(0, count($events) - 1000);
                foreach (array_slice($events, 0, 1000) as $event) {
                    if (!is_array($event)) { $out['raw']['malformed_records']++; continue; }
                    if (!isset($event['message'])) { $out['raw']['non_message_events']++; continue; }
                    if ($source === 'standby') $out['raw']['standby_messages']++;
                    $sender = $event['sender']['id'] ?? null; $recipient = $event['recipient']['id'] ?? null;
                    if (!is_array($event['message']) || !self::id($sender) || !self::id($recipient)) {
                        $out['raw']['malformed_records']++; continue;
                    }
                    $direction = $sender === $entry['id'] && $recipient !== $entry['id'] ? 'outbound'
                        : ($recipient === $entry['id'] && $sender !== $entry['id'] ? 'inbound' : null);
                    if ($direction === null) { $out['raw']['routing_mismatch']++; continue; }
                    $out['raw'][$direction]++;
                    self::latest($out['raw'], 'latest_'.$direction.'_at_utc', self::stamp($event['timestamp'] ?? null));
                }
            }
            unset($out);
        }
        // The consumer validates the whole event atomically: reflect its behavior instead of guessing partial recovery.
        try {
            $report = \App\Support\MetaMessagingProtocol::report($payload, array_values($this->accounts));
            foreach ($report['messages'] as $dto) {
                $key = $dto['channel'].':'.$dto['account_id'];
                if (!isset($result['accounts'][$key])) continue;
                $out = &$result['accounts'][$key]['normalized'];
                $out[$dto['direction']]++;
                if ($dto['source'] === 'standby') $out['standby_messages']++;
                self::latest($out, 'latest_'.$dto['direction'].'_at_utc', self::date($dto['sent_at'])); unset($out);
            }
            // Report ignored records per account without invoking a different normalization policy.
            foreach ($result['accounts'] as $key => &$out) {
                $scoped = ['object' => $payload['object'], 'entry' => array_values(array_filter($payload['entry'],
                    static fn ($entry) => is_array($entry) && ($entry['id'] ?? null) === substr($key, strpos($key, ':') + 1)))];
                $out['normalized']['ignored'] = \App\Support\MetaMessagingProtocol::report($scoped, array_values($this->accounts))['ignored_count'];
            }
            unset($out);
        } catch (\Throwable $error) {
            $category = $error instanceof \InvalidArgumentException && in_array($error->getMessage(), self::REASONS, true)
                ? $error->getMessage() : 'NORMALIZER_UNAVAILABLE';
            foreach ($result['accounts'] as &$out) {
                $out['normalized']['inbound'] = $out['normalized']['outbound'] = $out['normalized']['standby_messages'] = 0;
                $out['normalized']['latest_inbound_at_utc'] = $out['normalized']['latest_outbound_at_utc'] = null;
                $out['normalized']['invalid_events']++;
                $out['normalized']['categories'][$category] = 1;
            }
            unset($out);
        }
        return $result;
    }

    private function probe(string $host, string $path, string $token): array
    {
        if ($this->graphStarted === null) $this->graphStarted = microtime(true);
        if ($this->requests >= self::MAX_REQUESTS || microtime(true) - $this->graphStarted >= self::GRAPH_SECONDS)
            return ['status' => 'BUDGET_EXHAUSTED', 'body' => null];
        if ($token === '' || strlen($token) > 8192 || preg_match('/[\s\x00-\x1f\x7f]/', $token))
            return ['status' => 'CREDENTIAL_UNAVAILABLE', 'body' => null];
        $this->requests++;
        try {
            $url = 'https://'.$host.'/'.$path;
            if ($this->transport !== null) $response = ($this->transport)($url, $token);
            else {
                if (!function_exists('curl_init')) throw new \RuntimeException();
                $bytes = ''; $curl = curl_init($url);
                $remaining = max(1, min(4, (int) ceil(self::GRAPH_SECONDS - (microtime(true) - $this->graphStarted))));
                curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                    CURLOPT_CONNECTTIMEOUT => min(2, $remaining), CURLOPT_TIMEOUT => $remaining, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json'],
                    CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$bytes) {
                        if (strlen($bytes) + strlen($chunk) > self::MAX_GRAPH_BYTES) return 0;
                        $bytes .= $chunk; return strlen($chunk);
                    }]);
                try { $ok = curl_exec($curl); $response = ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $bytes]; }
                finally { curl_close($curl); }
            }
            if (!is_array($response) || !is_string($response['body'] ?? null) || strlen($response['body']) > self::MAX_GRAPH_BYTES)
                return ['status' => 'INVALID_RESPONSE', 'body' => null];
            $body = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body)) return ['status' => 'INVALID_RESPONSE', 'body' => null];
            if (($response['status'] ?? null) !== 200 || isset($body['error'])) {
                $code = is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null;
                $status = $code === 190 ? 'TOKEN_INVALID' : (in_array($code, [10, 200], true) ? 'PERMISSION_DENIED'
                    : ((($response['status'] ?? null) === 429 || in_array($code, [4, 17, 32, 613], true)) ? 'RATE_LIMITED'
                        : (($response['status'] ?? null) === 0 ? 'TRANSPORT_FAILED' : 'API_REJECTED')));
                return ['status' => $status, 'body' => null];
            }
            return ['status' => 'READY', 'body' => $body];
        } catch (\Throwable $error) { return ['status' => $error instanceof \JsonException ? 'INVALID_RESPONSE' : 'TRANSPORT_FAILED', 'body' => null]; }
    }

    private static function fields($values): array
    {
        $out = ['fields' => [], 'unknown_field_count' => 0, 'valid' => true];
        if (!is_array($values) || !array_is_list($values) || count($values) > 100) { $out['valid'] = false; return $out; }
        foreach ($values as $value) {
            $name = is_array($value) ? ($value['name'] ?? null) : $value;
            if (is_string($name) && in_array($name, self::FIELDS, true)) $out['fields'][] = $name;
            else $out['unknown_field_count']++;
        }
        $out['fields'] = array_values(array_unique($out['fields'])); sort($out['fields']); return $out;
    }

    private function subscribed(string $host, string $id, string $token, string $app, string $version): array
    {
        $probe = $this->probe($host, $version.'/'.$id.'/subscribed_apps?fields=id%2Csubscribed_fields&limit=100', $token);
        $out = ['status' => $probe['status'], 'same_app_subscribed' => null, 'complete' => null,
            'fields' => [], 'unknown_field_count' => 0];
        if ($probe['status'] !== 'READY') return $out;
        $rows = $probe['body']['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) { $out['status'] = 'INVALID_RESPONSE'; return $out; }
        $paging = $probe['body']['paging'] ?? [];
        if (!is_array($paging)) { $out['status'] = 'INVALID_RESPONSE'; return $out; }
        $found = false;
        foreach ($rows as $row) {
            if (!is_array($row) || !self::id($row['id'] ?? null)) { $out['status'] = 'INVALID_RESPONSE'; return $out; }
            if ($row['id'] !== $app) continue;
            $fields = self::fields($row['subscribed_fields'] ?? []);
            if (!$fields['valid']) { $out['status'] = 'INVALID_RESPONSE'; return $out; }
            $found = true; $out['fields'] = $fields['fields']; $out['unknown_field_count'] = $fields['unknown_field_count'];
        }
        $out['complete'] = !isset($paging['next']);
        $out['same_app_subscribed'] = $found ? true : ($out['complete'] ? false : null);
        return $out;
    }

    /** GET only. The only emitted identifiers are the app and configured business accounts. */
    public function graph(string $app, string $secret, string $version, string $callback): array
    {
        if (!self::id($app) || !preg_match('/\Av[0-9]{1,2}\.0\z/', $version)) throw new \InvalidArgumentException();
        $this->requests = 0; $this->graphStarted = microtime(true);
        $out = ['app_id' => $app, 'app_subscriptions' => ['status' => 'APP_SECRET_UNAVAILABLE', 'complete' => null, 'objects' => []], 'accounts' => [], 'requests' => 0];
        if (preg_match('/\A[a-f0-9]{32}\z/i', $secret)) {
            $probe = $this->probe('graph.facebook.com', $version.'/'.$app.'/subscriptions', $app.'|'.$secret);
            $out['app_subscriptions']['status'] = $probe['status'];
            if ($probe['status'] === 'READY') {
                $rows = $probe['body']['data'] ?? null;
                if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100 || !is_array($probe['body']['paging'] ?? [])) $out['app_subscriptions']['status'] = 'INVALID_RESPONSE';
                else {
                    $out['app_subscriptions']['complete'] = !isset($probe['body']['paging']['next']);
                    foreach ($rows as $row) {
                    if (!is_array($row) || !in_array($row['object'] ?? null, ['page', 'instagram'], true)) continue;
                    $fields = self::fields($row['fields'] ?? []);
                    $out['app_subscriptions']['objects'][] = ['object' => $row['object'],
                        'active' => is_bool($row['active'] ?? null) ? $row['active'] : null,
                        'callback_matches' => is_string($row['callback_url'] ?? null)
                            ? rtrim($row['callback_url'], '/') === rtrim($callback, '/') : null,
                        'fields' => $fields['fields'], 'unknown_field_count' => $fields['unknown_field_count'],
                        'fields_valid' => $fields['valid']];
                    }
                }
            }
        }
        // Inspect Pages first so Facebook Login Instagram accounts can reuse their Page checks.
        $ordered = array_values($this->accounts);
        usort($ordered, static fn ($left, $right) => ($left['channel'] === 'messenger' ? 0 : 1) <=> ($right['channel'] === 'messenger' ? 0 : 1));
        foreach ($ordered as $account) {
            $key = $account['channel'].':'.$account['account_id'];
            $host = $account['channel'] === 'instagram' && ($account['login'] ?? 'facebook') === 'instagram'
                ? 'graph.instagram.com' : 'graph.facebook.com';
            $token = is_string($account['access_token'] ?? null) ? $account['access_token'] : '';
            $facebookInstagram = $account['channel'] === 'instagram' && $host === 'graph.facebook.com';
            $out['accounts'][$key] = ['channel' => $account['channel'], 'account_id' => $account['account_id'],
                'login' => $host === 'graph.instagram.com' ? 'instagram' : 'facebook',
                'configured_account_subscriptions' => $facebookInstagram ? ['status' => 'CHECK_VIA_FACEBOOK_PAGE']
                    : $this->subscribed($host, $account['account_id'], $token, $app, $version)];
            if ($facebookInstagram) {
                $pages = [];
                foreach ($this->accounts as $pageKey => $page) {
                    if ($page['channel'] !== 'messenger') continue;
                    $pages[] = ['page_account_id' => $page['account_id'],
                        'same_configured_token' => $token !== '' && $token === ($page['access_token'] ?? null),
                        'subscriptions' => $out['accounts'][$pageKey]['configured_account_subscriptions']];
                }
                $out['accounts'][$key]['configured_page_subscription_checks'] = $pages;
                if ($pages !== []) continue;
                // Facebook Login may use a Page token. Inspect its Page edge without changing app roles.
                $me = $this->probe($host, $version.'/me?fields=id', $token);
                $check = ['status' => $me['status'], 'resolved_asset_equals_configured_account' => null, 'subscriptions' => null];
                if ($me['status'] === 'READY') {
                    $id = $me['body']['id'] ?? null;
                    if (!self::id($id)) $check['status'] = 'INVALID_RESPONSE';
                    else {
                        $check['resolved_asset_equals_configured_account'] = $id === $account['account_id'];
                        $check['subscriptions'] = $this->subscribed($host, $id, $token, $app, $version);
                    }
                }
                $out['accounts'][$key]['token_asset_subscriptions'] = $check;
            }
        }
        $out['requests'] = $this->requests; return $out;
    }

    private static function decode($encrypted): array
    {
        if (!is_string($encrypted) || strlen($encrypted) > self::MAX_BYTES * 2) throw new \RuntimeException();
        $bytes = \Illuminate\Support\Facades\Crypt::decryptString($encrypted);
        if (strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException();
        $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \RuntimeException(); return $value;
    }

    private static function merge(array &$target, array $counts): void
    {
        foreach ($counts as $key => $value) {
            if (is_array($value)) self::merge($target[$key], $value);
            elseif (is_int($value)) $target[$key] = ($target[$key] ?? 0) + $value;
            elseif ($value !== null && ($target[$key] === null || $value > $target[$key])) $target[$key] = $value;
        }
    }

    public function local(array $options): array
    {
        $db = \Illuminate\Support\Facades\DB::class; $started = microtime(true);
        $ceiling = (int) ($db::table('meta_webhook_events')->max('id') ?? 0);
        $through = $options['through_event'] === null ? $ceiling : min($ceiling, $options['through_event']);
        $query = $db::table('meta_webhook_events')->where('id', '>', $options['after_event'])->where('id', '<=', $through);
        $out = ['status' => 'READY', 'counts_are_live_not_atomic' => true, 'accounts' => [],
            'capture' => ['observed_event_ceiling' => $ceiling, 'through_event' => $through, 'after_event' => $options['after_event'],
                'events_in_range' => (int) (clone $query)->count(), 'event_limit' => $options['limit'], 'events_read' => 0,
                'sample_complete' => false, 'unreadable_events' => 0, 'unscoped_entries' => 0, 'malformed_envelopes' => 0,
                'sample_latest_received_at_utc' => null, 'quarantined_events_in_range' => 0, 'pending_events_in_range' => 0,
                'oldest_pending_received_at_utc' => null, 'newest_pending_received_at_utc' => null]];
        foreach ($this->accounts as $key => $account) $out['accounts'][$key] = ['channel' => $account['channel'],
            'account_id' => $account['account_id'], 'capture_sample' => self::emptyCapture(), 'projection' => []];
        $failures = (clone $query)->whereExists(static function ($q) {
            $q->select('event_id')->from('meta_inbox_ingestion_failures')->whereColumn('event_id', 'meta_webhook_events.id');
        });
        $out['capture']['quarantined_events_in_range'] = (int) $failures->count();
        $pending = (clone $query)->whereNull('processed_at')->whereNotExists(static function ($q) {
            $q->select('event_id')->from('meta_inbox_ingestion_failures')->whereColumn('event_id', 'meta_webhook_events.id');
        });
        $out['capture']['pending_events_in_range'] = (int) (clone $pending)->count();
        $out['capture']['oldest_pending_received_at_utc'] = self::date((clone $pending)->min('received_at'));
        $out['capture']['newest_pending_received_at_utc'] = self::date((clone $pending)->max('received_at'));
        $rows = $query->orderByDesc('id')->limit($options['limit'])->get(['id', 'processed_at', 'received_at']);
        $reasons = $db::table('meta_inbox_ingestion_failures')->whereIn('event_id', $rows->pluck('id')->all())->pluck('reason', 'event_id');
        foreach ($rows as $row) {
            if (microtime(true) - $started >= self::LOCAL_SECONDS) break;
            $out['capture']['events_read']++;
            self::latest($out['capture'], 'sample_latest_received_at_utc', self::date($row->received_at));
            try {
                // Retrieve one encrypted payload at a time to bound memory even for maximum-size events.
                $description = $this->inspect(self::decode($db::table('meta_webhook_events')->where('id', $row->id)->value('payload')));
                foreach (['unscoped_entries', 'malformed_envelopes'] as $key) $out['capture'][$key] += $description[$key];
                foreach ($description['accounts'] as $key => $counts) {
                    $counts['events'] = 1;
                    $reason = $reasons[$row->id] ?? null;
                    if ($reason !== null) {
                        $counts['quarantined_events'] = 1;
                        $counts['quarantine_categories'][in_array($reason, ['INVALID_EVENT', 'CONFLICTING_MESSAGE'], true) ? $reason : 'OTHER'] = 1;
                    } elseif ($row->processed_at === null) $counts['pending_events'] = 1;
                    else $counts['processed_events'] = 1;
                    $counts['latest_received_at_utc'] = self::date($row->received_at);
                    self::merge($out['accounts'][$key]['capture_sample'], $counts);
                }
            } catch (\Throwable $error) { $out['capture']['unreadable_events']++; }
        }
        $out['capture']['sample_complete'] = $out['capture']['events_read'] === $out['capture']['events_in_range'];
        $out['capture']['events_omitted'] = max(0, $out['capture']['events_in_range'] - $out['capture']['events_read']);
        $out['capture']['next_through_event'] = $out['capture']['events_omitted'] > 0 && $out['capture']['events_read'] > 0
            ? (int) $rows[$out['capture']['events_read'] - 1]->id - 1 : null;
        foreach ($this->accounts as $key => $account) {
            $conversations = $db::table('meta_inbox_conversations')->where('waba_id', $account['channel'])->where('phone_number_id', $account['account_id']);
            $messages = $db::table('meta_inbox_messages as m')->join('meta_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->where('c.waba_id', $account['channel'])->where('c.phone_number_id', $account['account_id']);
            $projection = ['conversations' => (int) (clone $conversations)->count(), 'inbound' => 0, 'outbound' => 0,
                'standby_messages' => (int) (clone $messages)->where('m.source', 'standby')->count(),
                'latest_inbound_at_utc' => null, 'latest_outbound_at_utc' => null,
                'customer_name_sample' => ['limit' => self::NAME_LIMIT, 'read' => 0, 'named' => 0, 'nameless' => 0,
                    'unreadable' => 0, 'complete' => false]];
            foreach (['inbound', 'outbound'] as $direction) {
                $directionQuery = (clone $messages)->where('m.direction', $direction);
                $projection[$direction] = (int) (clone $directionQuery)->count();
                $projection['latest_'.$direction.'_at_utc'] = self::date($directionQuery->max('m.sent_at'));
            }
            foreach ((clone $conversations)->orderByDesc('id')->limit(self::NAME_LIMIT)->get(['id']) as $row) {
                if (microtime(true) - $started >= self::LOCAL_SECONDS) break;
                $projection['customer_name_sample']['read']++;
                try {
                    $customer = self::decode((clone $conversations)->where('id', $row->id)->value('customer'));
                    $name = $customer['name'] ?? null;
                    $projection['customer_name_sample'][is_string($name) && trim($name) !== '' ? 'named' : 'nameless']++;
                } catch (\Throwable $error) { $projection['customer_name_sample']['unreadable']++; }
            }
            $projection['customer_name_sample']['complete'] = $projection['customer_name_sample']['read'] === $projection['conversations'];
            $out['accounts'][$key]['projection'] = $projection;
        }
        if (!$out['capture']['sample_complete'] || $out['capture']['unreadable_events'] > 0
            || $out['capture']['malformed_envelopes'] > 0) $out['status'] = 'PARTIAL';
        return $out;
    }

    public static function run(): array
    {
        $options = self::arguments(array_slice($_SERVER['argv'] ?? [], 1));
        $root = getcwd();
        if (!is_string($root) || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) throw new \RuntimeException();
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $diagnostic = new self(\App\Services\Dashboard\MetaInboxAccess::accounts());
        $local = $diagnostic->local($options);
        $secret = config('meta_messaging.app_secret', ''); $version = config('meta_messaging.api_version', 'v25.0');
        $callback = rtrim((string) config('app.url', ''), '/').'/api/meta/messaging/webhook';
        $graph = $diagnostic->graph($options['app_id'], is_string($secret) ? $secret : '', is_string($version) ? $version : '', $callback);
        unset($secret);
        return ['diagnostic' => 'READ_ONLY_META_INBOUND', 'status' => $local['status'], 'writes' => 0,
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'media_runtime' => ['curl' => function_exists('curl_init'), 'fileinfo' => class_exists(\finfo::class),
                'voice_converter' => function_exists('proc_open') && function_exists('posix_geteuid')
                    && is_executable('/usr/bin/ffmpeg') && is_executable('/usr/bin/ffprobe')],
            'local' => $local, 'graph' => $graph];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
    try { $result = MetaInboundDiagnostic::run(); $exit = 0; }
    catch (\Throwable $error) { $result = ['diagnostic' => 'READ_ONLY_META_INBOUND', 'status' => 'FAILED', 'writes' => 0]; $exit = 1; }
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    exit($exit);
}
