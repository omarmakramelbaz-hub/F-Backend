<?php

namespace App\Services\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Http, Log, Schema};

/** New website COD orders only. Existing app and POS conversion senders stay separate. */
class WebsiteMetaPurchase
{
    private const TABLE = 'meta_website_purchases';
    private const MAX_ATTEMPTS = 8;
    private const MAX_AGE = 604800;

    /** Called once, outside the checkout transaction, after a NEW website receipt commits. */
    public function enqueue(int $ticketId, int $branchId, Request $request): void
    {
        try {
            $credentials = $this->credentials();
            if (!$credentials || self::optedOut($request)) return;

            $receipt = DB::table('web_storefront_orders')->where('ticket_id', $ticketId)
                ->where('branch_id', $branchId)->first(['ticket_id', 'created_at']);
            $ticket = DB::table('pos_service_tickets')->where('id', $ticketId)
                ->where('branch', 'f:'.$branchId)->where('channel', 'phone')
                ->first(['id', 'customer_phone', 'quote_snapshot']);
            if (!$receipt || !$ticket) return;

            $eventTime = (new \DateTimeImmutable((string) $receipt->created_at, new \DateTimeZone('UTC')))->getTimestamp();
            // This hook never backfills old tickets or fabricates their browser context.
            if ($eventTime < time() - 300 || $eventTime > time()) return;
            $quote = json_decode((string) $ticket->quote_snapshot, true, 64, JSON_THROW_ON_ERROR);
            if (!is_numeric($quote['total'] ?? null)) return;
            $event = self::event($ticketId, $eventTime, (float) $quote['total'],
                (string) $ticket->customer_phone, (string) $request->userAgent(), self::attributionCookies($request));
            if ($event === null) return;

            $payload = ['data' => [$event]];
            $testCode = trim((string) config('services.meta_conversions.test_event_code', ''));
            if ($testCode !== '') $payload['test_event_code'] = $testCode;
            DB::table(self::TABLE)->insertOrIgnore([
                'ticket_id' => $ticketId, 'event_id' => $event['event_id'],
                'dataset_id' => $credentials['dataset'], 'event_time' => $eventTime,
                'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'attempts' => 0, 'next_attempt_at' => now('UTC'),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        } catch (\Throwable $error) {
            // Conversion tracking must never change a customer's successful checkout.
            Log::warning('Meta website Purchase tracking', ['category' => 'enqueue_failed']);
        }
    }

    public static function optedOut(Request $request): bool
    {
        return trim((string) $request->header('Sec-GPC')) === '1'
            || trim((string) $request->header('DNT')) === '1';
    }

    /** EncryptCookies can remove Meta's plaintext cookies; read only these two named values. */
    private static function attributionCookies(Request $request): array
    {
        $cookies = ['fbp' => $request->cookie('_fbp'), 'fbc' => $request->cookie('_fbc')];
        $header = $request->header('Cookie');
        if (!is_string($header)) return $cookies;
        foreach (['fbp' => '_fbp', 'fbc' => '_fbc'] as $field => $name) {
            if ($cookies[$field] === null
                && preg_match('/(?:^|;\s*)'.preg_quote($name, '/').'=([^;]*)/', $header, $match)) {
                // Decode once; event() still validates the complete value before including it.
                $cookies[$field] = rawurldecode($match[1]);
            }
        }
        return $cookies;
    }

    /** Pure payload construction; no customer name, address, coordinates, token or session ID. */
    public static function event(int $ticketId, int $eventTime, float $total, string $phone, string $userAgent, array $cookies = []): ?array
    {
        $phone = strtr($phone, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $phone = preg_replace('/\D+/', '', $phone);
        if (preg_match('/^01[0125][0-9]{8}$/D', $phone)) $phone = '20'.substr($phone, 1);
        elseif (preg_match('/^00201[0125][0-9]{8}$/D', $phone)) $phone = substr($phone, 2);
        $userAgent = trim($userAgent);
        if ($ticketId < 1 || $eventTime < 1 || !is_finite($total) || $total <= 0 || $total > 20000
            || !preg_match('/^201[0125][0-9]{8}$/D', $phone)
            || $userAgent === '' || strlen($userAgent) > 2048 || preg_match('/[\r\n]/', $userAgent)) return null;

        $user = ['ph' => [hash('sha256', $phone)], 'client_user_agent' => $userAgent];
        if (is_string($cookies['fbp'] ?? null) && preg_match('/^fb\.1\.[0-9]{10,13}\.[0-9]{1,20}$/D', $cookies['fbp'])) $user['fbp'] = $cookies['fbp'];
        if (is_string($cookies['fbc'] ?? null) && preg_match('/^fb\.1\.[0-9]{10,13}\.[A-Za-z0-9_-]{5,500}$/D', $cookies['fbc'])) $user['fbc'] = $cookies['fbc'];
        return [
            'event_name' => 'Purchase', 'event_time' => $eventTime,
            'event_id' => 'website_purchase_ticket_'.$ticketId,
            'action_source' => 'website', 'event_source_url' => 'https://fasakhaninja.com/',
            'user_data' => $user,
            'custom_data' => ['currency' => 'EGP', 'value' => round($total, 2), 'order_id' => 'WEB-'.$ticketId],
        ];
    }

    /** Only Meta's explicit acknowledgement marks the outbox row sent. */
    public static function accepted(int $status, $received): bool
    {
        return $status >= 200 && $status < 300 && is_int($received) && $received >= 1;
    }

    public function drain(int $limit = 25): array
    {
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'blocked' => 0];
        try {
            $credentials = $this->credentials();
            if (!$credentials || !Schema::hasTable(self::TABLE)) return $counts;
            $ids = DB::table(self::TABLE)->where('dataset_id', $credentials['dataset'])
                ->whereNull('sent_at')->whereNull('failed_at')
                ->where('next_attempt_at', '<=', now('UTC'))->orderBy('id')
                ->limit(max(1, min(100, $limit)))->pluck('id');
            foreach ($ids as $id) {
                $result = DB::transaction(function () use ($id, $credentials) {
                    $row = DB::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                    if (!$row || $row->sent_at || $row->failed_at
                        || strtotime((string) $row->next_attempt_at.' UTC') > time()) return 'blocked';
                    if (!hash_equals((string) $row->dataset_id, $credentials['dataset'])) {
                        // A changed configuration must never redirect previously captured events.
                        return 'blocked';
                    }
                    if ((int) $row->event_time <= time() - self::MAX_AGE || (int) $row->attempts >= self::MAX_ATTEMPTS) {
                        $this->fail($row, 'expired_or_exhausted', true);
                        return 'failed';
                    }
                    try {
                        $payload = json_decode(Crypt::decryptString($row->payload), true, 64, JSON_THROW_ON_ERROR);
                        $event = $payload['data'][0] ?? [];
                        if (($event['event_id'] ?? null) !== $row->event_id
                            || ($event['event_name'] ?? null) !== 'Purchase'
                            || ($event['action_source'] ?? null) !== 'website'
                            || (int) ($event['event_time'] ?? 0) !== (int) $row->event_time) {
                            $this->fail($row, 'invalid_payload', true);
                            return 'failed';
                        }
                    } catch (\Throwable $error) {
                        $this->fail($row, 'invalid_payload', true);
                        return 'failed';
                    }
                    try {
                        $response = Http::withToken($credentials['token'])->asJson()->connectTimeout(2)->timeout(4)
                            ->post('https://graph.facebook.com/'.$credentials['version'].'/'.$credentials['dataset'].'/events', $payload);
                        if (self::accepted($response->status(), $response->json('events_received'))) {
                            DB::table(self::TABLE)->where('id', $row->id)->update([
                                'attempts' => (int) $row->attempts + 1, 'sent_at' => now('UTC'),
                                'last_error_code' => null, 'updated_at' => now('UTC'),
                            ]);
                            return 'sent';
                        }
                        $category = $response->status() >= 200 && $response->status() < 300
                            ? 'no_events_received' : 'http_'.$response->status();
                    } catch (\Throwable $error) {
                        $category = 'transport_error';
                    }
                    return $this->fail($row, $category) ? 'failed' : 'retry';
                }, 1);
                $counts[$result]++;
            }
        } catch (\Throwable $error) {
            Log::warning('Meta website Purchase tracking', ['category' => 'drain_failed']);
        }
        return $counts;
    }

    public function readiness(): array
    {
        $dataset = trim((string) config('services.meta_conversions.dataset_id', ''));
        $result = [
            'enabled' => filter_var(config('services.meta_conversions.enabled', false), FILTER_VALIDATE_BOOLEAN),
            'dataset_id' => preg_match('/^[0-9]{5,32}$/D', $dataset) ? $dataset : null,
            'access_token_configured' => trim((string) config('services.meta_conversions.access_token', '')) !== '',
            'token_configured' => trim((string) config('services.meta_conversions.access_token', '')) !== '',
            'test_event_code_configured' => trim((string) config('services.meta_conversions.test_event_code', '')) !== '',
            'table_present' => false, 'pending_count' => 0, 'sent_count' => 0, 'failed_count' => 0, 'last_sent_at' => null,
        ];
        try {
            $result['table_present'] = Schema::hasTable(self::TABLE);
            if ($result['table_present']) {
                $result['pending_count'] = DB::table(self::TABLE)->where('dataset_id', $dataset)->whereNull('sent_at')->whereNull('failed_at')->count();
                $result['sent_count'] = DB::table(self::TABLE)->where('dataset_id', $dataset)->whereNotNull('sent_at')->count();
                $result['failed_count'] = DB::table(self::TABLE)->where('dataset_id', $dataset)->whereNotNull('failed_at')->count();
                $result['last_sent_at'] = DB::table(self::TABLE)->where('dataset_id', $dataset)->max('sent_at');
            }
        } catch (\Throwable $error) {
            $result['check_error'] = 'database_unavailable';
        }
        return $result;
    }

    private function credentials(): ?array
    {
        if (!filter_var(config('services.meta_conversions.enabled', false), FILTER_VALIDATE_BOOLEAN)) return null;
        $dataset = trim((string) config('services.meta_conversions.dataset_id', ''));
        $token = trim((string) config('services.meta_conversions.access_token', ''));
        $version = trim((string) config('services.meta_conversions.api_version', 'v24.0'));
        if (!preg_match('/^[0-9]{5,32}$/D', $dataset) || $token === '' || !preg_match('/^v[0-9]{1,3}\.[0-9]{1,2}$/D', $version)) return null;
        return compact('dataset', 'token', 'version');
    }

    /** Return true when no more automatic attempts will be made. */
    private function fail(object $row, string $category, bool $terminal = false): bool
    {
        $attempts = (int) $row->attempts + 1;
        $terminal = $terminal || $attempts >= self::MAX_ATTEMPTS;
        DB::table(self::TABLE)->where('id', $row->id)->update([
            'attempts' => $attempts, 'last_error_code' => $category,
            'next_attempt_at' => now('UTC')->addSeconds(min(3600, 60 * (2 ** min(7, $attempts - 1)))),
            'failed_at' => $terminal ? now('UTC') : null, 'updated_at' => now('UTC'),
        ]);
        return $terminal;
    }
}
