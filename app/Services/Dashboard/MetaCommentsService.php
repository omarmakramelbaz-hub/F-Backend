<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Bounded live reads and explicit human replies to posts owned by configured business accounts. */
class MetaCommentsService
{
    private const POST_LIMIT = 20;
    private const COMMENT_LIMIT = 25;
    private const HANDLE_TTL = 21600;
    private const MAX_BODY = 2097152;
    private $transport;
    private array $sendTimings = [];

    /** Test transport receives (method, fixed endpoint, query/form, private bearer token). */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function viewerKey($actor): string
    {
        $actor = $this->actor($actor);
        return $this->key('viewer:' . (int) $actor->id);
    }

    public function accounts($actor): array
    {
        $actor = $this->actor($actor);
        $items = [];
        foreach (MetaInboxAccess::accounts() as $account) {
            $items[] = ['account_key' => $this->accountKey($account),
                'channel' => $account['channel'] === 'messenger' ? 'facebook' : 'instagram',
                'label' => $this->text($account['label'] ?? null, 100)
                    ?? ($account['channel'] === 'messenger' ? 'Facebook' : 'Instagram'),
                'connected' => $this->configured($account),
                'required_permissions' => $this->permissions($account, false)];
        }
        return ['success' => true, 'accounts' => $items, 'viewer_key' => $this->key('viewer:' . (int) $actor->id)];
    }

    public function posts($actor, string $accountKey, ?string $cursor = null): array
    {
        $actor = $this->actor($actor);
        $account = $this->account($accountKey);
        $this->requireConnection($account);
        $edge = $account['channel'] === 'messenger' ? 'published_posts' : 'media';
        $fields = $account['channel'] === 'messenger'
            ? 'id,message,created_time,permalink_url,full_picture'
            : 'id,caption,timestamp,permalink,media_type,thumbnail_url';
        $query = ['fields' => $fields, 'limit' => self::POST_LIMIT];
        $context = ['kind' => 'posts', 'account' => $accountKey];
        $this->addCursor($query, $cursor, $account, $context);
        $body = $this->graph($account, 'GET', $account['account_id'] . '/' . $edge, $query);
        $this->unchanged($actor, $account);
        $items = [];
        foreach ($this->rows($body, self::POST_LIMIT) as $row) {
            $id = $row['id'] ?? null;
            if (!$this->nativeId($id)) continue;
            // The Page edge contains published Page-owned posts; reject mismatching Page composites too.
            if ($account['channel'] === 'messenger' && strpos($id, $account['account_id'] . '_') !== 0) continue;
            $items[] = ['key' => $this->objectKey($account, $id),
                'handle' => $this->handle($account, 'post', ['post' => $id]),
                'text' => $this->text($row['message'] ?? $row['caption'] ?? null, 10000) ?? '',
                'created_at' => $this->timestamp($row['created_time'] ?? $row['timestamp'] ?? null),
                'permalink' => $this->url($row['permalink_url'] ?? $row['permalink'] ?? null, false),
                'thumbnail_url' => $this->url($row['full_picture'] ?? $row['thumbnail_url'] ?? null, true)];
        }
        return ['success' => true, 'posts' => $items,
            'next_cursor' => $this->nextCursor($body, $account, $context)];
    }

    public function comments($actor, string $postHandle, ?string $cursor = null): array
    {
        $actor = $this->actor($actor);
        $handle = $this->decodeHandle($postHandle, 'post');
        return $this->commentList($actor, $handle, false, $cursor);
    }

    public function replies($actor, string $commentHandle, ?string $cursor = null): array
    {
        $actor = $this->actor($actor);
        $handle = $this->decodeHandle($commentHandle, 'comment');
        return $this->commentList($actor, $handle, true, $cursor);
    }

    private function commentList($actor, array $handle, bool $replies, ?string $cursor): array
    {
        $account = $handle['account'];
        $this->requireConnection($account);
        $facebook = $account['channel'] === 'messenger';
        $parent = $replies ? $handle['comment'] : $handle['post'];
        $edge = $replies && !$facebook ? 'replies' : 'comments';
        $fields = $facebook ? 'id,message,from,created_time,comment_count,can_comment' : 'id,text,username,timestamp';
        $query = ['fields' => $fields, 'limit' => self::COMMENT_LIMIT];
        if (!$replies && $facebook) $query['filter'] = 'toplevel';
        $context = ['kind' => $replies ? 'replies' : 'comments', 'account' => $this->accountKey($account),
            'post' => $handle['post'], 'parent' => $parent];
        $this->addCursor($query, $cursor, $account, $context);
        $body = $this->graph($account, 'GET', $parent . '/' . $edge, $query);
        $this->unchanged($actor, $account);
        $items = [];
        foreach ($this->rows($body, self::COMMENT_LIMIT) as $row) {
            if (!$this->nativeId($row['id'] ?? null)) continue;
            $items[] = $this->commentDto($account, $handle['post'], $row, $replies);
        }
        return ['success' => true, $replies ? 'replies' : 'comments' => $items,
            'next_cursor' => $this->nextCursor($body, $account, $context)];
    }

    public function send($actor, array $input): array
    {
        $this->sendTimings = [];
        $actor = $this->actor($actor);
        if (array_diff(array_keys($input), ['comment_handle', 'message', 'client_request_id'])
            || array_diff(['comment_handle', 'message', 'client_request_id'], array_keys($input))
            || !is_string($input['comment_handle']) || !is_string($input['message'])
            || !$this->uuid($input['client_request_id'])) throw new MetaCommentsFailure('INVALID_REQUEST', 422);
        $message = trim($input['message']);
        if ($message === '' || !mb_check_encoding($message, 'UTF-8') || mb_strlen($message, 'UTF-8') > 1000
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)) {
            throw new MetaCommentsFailure('INVALID_REQUEST', 422);
        }
        $handle = $this->decodeHandle($input['comment_handle'], 'comment');
        $account = $handle['account'];
        $this->requireConnection($account);
        $target = $this->objectKey($account, $handle['post'], $handle['comment']);
        $requestHash = $this->key('request:' . (int) $actor->id . ':' . strtolower($input['client_request_id']));
        $payloadHash = $this->key('payload:' . $target . ':' . $message);
        $pendingKey = $this->key('pending:' . (int) $actor->id . ':' . $target);
        // The unique request and pending keys claim the only send before any provider I/O.
        // A fatal process interruption leaves SENDING durable and will never resend automatically.
        try {
            // Attempt the actual claim directly instead of a separate schema probe on every reply.
            // Any storage error here occurs before provider I/O and is safe to report as unsent.
            $inserted = DB::table('meta_comment_reply_requests')->insertOrIgnore([
                'actor_id' => (int) $actor->id, 'account_key' => $this->accountKey($account),
                'target_hash' => $target, 'request_hash' => $requestHash, 'payload_hash' => $payloadHash,
                'pending_key' => $pendingKey, 'state' => 'SENDING', 'send_started_at' => now('UTC'),
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $row = DB::table('meta_comment_reply_requests')->where('request_hash', $requestHash)->first();
        } catch (\Illuminate\Database\QueryException $error) {
            throw new MetaCommentsFailure('REPLIES_UNAVAILABLE', 503);
        }
        if (!$inserted) {
            if ($row) {
                if (!hash_equals($row->payload_hash, $payloadHash)) throw new MetaCommentsFailure('REQUEST_CONFLICT', 409);
                return $this->receipt($row, true);
            }
            if (DB::table('meta_comment_reply_requests')->where('pending_key', $pendingKey)->exists()) {
                return $this->result('UNKNOWN', 'DELIVERY_UNKNOWN', null, false);
            }
            throw new MetaCommentsFailure('REPLIES_UNAVAILABLE', 503);
        }
        if (!$row) throw new MetaCommentsFailure('REPLIES_UNAVAILABLE', 503);
        try {
            // Reload central permissions and the exact credential binding immediately before sending.
            $this->unchanged($actor, $account);
        } catch (\Throwable $error) {
            return $this->finish((int) $row->id, 'FAILED', 'ACCESS_REVOKED');
        }
        try {
            $edge = $account['channel'] === 'messenger' ? 'comments' : 'replies';
            $body = $this->graph($account, 'POST', $handle['comment'] . '/' . $edge, ['message' => $message]);
            if (!$this->nativeId($body['id'] ?? null)) return $this->finish((int) $row->id, 'UNKNOWN', 'DELIVERY_UNKNOWN');
            $reply = $this->commentDto($account, $handle['post'], ['id' => $body['id'],
                'message' => $message, 'from' => ['name' => $account['label'] ?? ($account['channel'] === 'messenger' ? 'Facebook' : 'Instagram')],
                'created_time' => gmdate('c'), 'can_comment' => true], true);
            return $this->finish((int) $row->id, 'ACCEPTED', null, $reply);
        } catch (MetaCommentsFailure $error) {
            // Definitive Graph rejection is safe to retry after the stated issue is fixed.
            // Transport errors, malformed success and 5xx can mean accepted by Meta: keep the guard.
            if (in_array($error->reason, ['PERMISSION_REQUIRED', 'RECONNECT_REQUIRED', 'RATE_LIMITED', 'CONTENT_UNAVAILABLE', 'META_REJECTED'], true)) {
                return $this->finish((int) $row->id, 'FAILED', $error->reason, null, $error->permissions);
            }
        } catch (\Throwable $error) { /* No raw response or text may escape. */ }
        return $this->finish((int) $row->id, 'UNKNOWN', 'DELIVERY_UNKNOWN');
    }

    /** Fixed numeric timing names for attribution; contains no URLs, account IDs or Graph data. */
    public function serverTiming(float $requestMilliseconds): string
    {
        $metrics = ['reply' => $requestMilliseconds] + $this->sendTimings;
        $parts = [];
        foreach (['reply', 'meta', 'meta_dns', 'meta_connect', 'meta_tls', 'meta_wait'] as $name) {
            $value = $metrics[$name] ?? null;
            if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value)) continue;
            $parts[] = $name . ';dur=' . number_format(max(0, min(60000, $value)), 1, '.', '');
        }
        return implode(', ', $parts);
    }

    private function actor($actor)
    {
        if (config('desktop_dashboard.local', false)) throw new MetaCommentsFailure('CENTRAL_ONLY', 403);
        return app(MetaInboxAccess::class)->actor($actor);
    }

    private function account(string $key): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $key) !== 1) throw new MetaCommentsFailure('INVALID_HANDLE', 422);
        foreach (MetaInboxAccess::accounts() as $account) if (hash_equals($this->accountKey($account), $key)) return $account;
        throw new MetaCommentsFailure('ACCOUNT_UNAVAILABLE', 404);
    }

    private function accountKey(array $account): string
    {
        return hash('sha256', $account['channel'] . ':' . $account['account_id']);
    }

    private function binding(array $account): string
    {
        return $this->key(json_encode([$account['channel'], $account['account_id'], $account['login'] ?? 'facebook',
            $account['page_id'] ?? null, $account['access_token'] ?? null, $this->version()], JSON_THROW_ON_ERROR));
    }

    private function unchanged($actor, array $account): void
    {
        $this->actor($actor);
        $fresh = $this->account($this->accountKey($account));
        if (!hash_equals($this->binding($account), $this->binding($fresh))) throw new MetaCommentsFailure('ACCOUNT_CHANGED', 409);
    }

    private function handle(array $account, string $kind, array $objects): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, 'kind' => $kind, 'account' => $this->accountKey($account),
            'binding' => $this->binding($account), 'expires' => time() + self::HANDLE_TTL] + $objects, JSON_THROW_ON_ERROR));
    }

    private function decodeHandle(string $value, string $kind): array
    {
        try {
            if (!$this->ciphertext($value, 4096)) throw new \RuntimeException();
            $h = json_decode(Crypt::decryptString($value), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($h) || ($h['v'] ?? null) !== 1 || ($h['kind'] ?? null) !== $kind
                || !is_string($h['account'] ?? null) || !is_string($h['binding'] ?? null)
                || !is_int($h['expires'] ?? null) || $h['expires'] < time() || $h['expires'] > time() + self::HANDLE_TTL + 5
                || !$this->nativeId($h['post'] ?? null) || ($kind === 'comment' && !$this->nativeId($h['comment'] ?? null))) throw new \RuntimeException();
            $account = $this->account($h['account']);
            if (!hash_equals($this->binding($account), $h['binding'])) throw new \RuntimeException();
            $h['account'] = $account;
            return $h;
        } catch (\Throwable $error) {
            throw new MetaCommentsFailure('INVALID_HANDLE', 422);
        }
    }

    private function addCursor(array &$query, ?string $cursor, array $account, array $context): void
    {
        if ($cursor === null || $cursor === '') return;
        try {
            if (!$this->ciphertext($cursor, 6000)) throw new \RuntimeException();
            $h = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($h) || ($h['v'] ?? null) !== 1 || ($h['context'] ?? null) !== $context
                || ($h['binding'] ?? null) !== $this->binding($account)
                || !is_int($h['expires'] ?? null) || $h['expires'] < time() || $h['expires'] > time() + self::HANDLE_TTL + 5
                || !$this->cursorString($h['after'] ?? null)) throw new \RuntimeException();
            $query['after'] = $h['after'];
        } catch (\Throwable $error) {
            throw new MetaCommentsFailure('INVALID_CURSOR', 422);
        }
    }

    private function nextCursor(array $body, array $account, array $context): ?string
    {
        $after = $body['paging']['cursors']['after'] ?? null;
        // "next" is a signal only. Its URL and any credentials in it are never followed or exposed.
        if (!$this->cursorString($after) || !is_string($body['paging']['next'] ?? null)) return null;
        return Crypt::encryptString(json_encode(['v' => 1, 'context' => $context, 'binding' => $this->binding($account),
            'expires' => time() + self::HANDLE_TTL, 'after' => $after], JSON_THROW_ON_ERROR));
    }

    private function cursorString($cursor): bool
    {
        return is_string($cursor) && strlen($cursor) >= 1 && strlen($cursor) <= 2048
            && preg_match('/\A[A-Za-z0-9_+=\/-]+\z/', $cursor) === 1;
    }

    private function ciphertext(string $value, int $max): bool
    {
        if ($value === '' || strlen($value) > $max) return false;
        $decoded = base64_decode($value, true);
        return is_string($decoded) && base64_encode($decoded) === $value;
    }

    private function commentDto(array $account, string $post, array $row, bool $reply = false): array
    {
        $id = $row['id'];
        $count = $row['comment_count'] ?? null;
        $author = $this->text($row['from']['name'] ?? $row['username'] ?? null, 200);
        return ['key' => $this->objectKey($account, $post, $id),
            // Instagram supports one reply level. Reply handles cannot be used as new reply targets.
            'handle' => $this->handle($account, $reply ? 'reply' : 'comment', ['post' => $post, 'comment' => $id]),
            'text' => $this->text($row['message'] ?? $row['text'] ?? null, 10000) ?? '',
            'author' => $author, 'created_at' => $this->timestamp($row['created_time'] ?? $row['timestamp'] ?? null),
            'replies_count' => is_int($count) && $count >= 0 ? min($count, 1000000) : null,
            'can_reply' => !$reply && ($row['can_comment'] ?? true) !== false];
    }

    private function objectKey(array $account, string $post, ?string $comment = null): string
    {
        return $this->key('object:' . $this->accountKey($account) . ':' . $post . ':' . ($comment ?? ''));
    }

    private function key(string $value): string
    {
        return hash_hmac('sha256', 'meta-comments-v1:' . $value, Crypt::getKey());
    }

    private function nativeId($id): bool
    {
        return is_string($id) && preg_match('/\A[1-9][0-9]{0,31}(?:_[1-9][0-9]{0,31})?\z/', $id) === 1;
    }

    private function uuid($id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id) === 1;
    }

    private function configured(array $account): bool
    {
        $token = $account['access_token'] ?? null;
        return is_string($token) && strlen($token) >= 16 && strlen($token) <= 4096
            && preg_match('/[\s\x00-\x1f\x7f]/', $token) === 0
            && in_array($account['login'] ?? 'facebook', ['facebook', 'instagram'], true)
            && ($this->transport !== null || function_exists('curl_init'));
    }

    private function requireConnection(array $account): void
    {
        if (!$this->configured($account)) throw new MetaCommentsFailure('RECONNECT_REQUIRED', 409, $this->permissions($account, false));
    }

    private function version(): string
    {
        $version = config('meta_messaging.api_version', 'v25.0');
        return is_string($version) && preg_match('/\Av[0-9]{1,2}\.0\z/', $version) === 1 ? $version : 'v25.0';
    }

    private function permissions(array $account, bool $write): array
    {
        if ($account['channel'] === 'messenger') return $write
            ? ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement']
            : ['pages_read_engagement', 'pages_read_user_content'];
        return ($account['login'] ?? 'facebook') === 'instagram'
            ? ['instagram_business_basic', 'instagram_business_manage_comments']
            : ['instagram_basic', 'instagram_manage_comments', 'pages_read_engagement'];
    }

    private function graph(array $account, string $method, string $path, array $parameters): array
    {
        // All path segments are validated IDs and fixed edge names; no browser-supplied URLs.
        if (preg_match('/\A[1-9][0-9]{0,31}(?:_[1-9][0-9]{0,31})?\/(?:published_posts|media|comments|replies)\z/', $path) !== 1) {
            throw new MetaCommentsFailure('INVALID_HANDLE', 422);
        }
        $host = $account['channel'] === 'instagram' && ($account['login'] ?? 'facebook') === 'instagram'
            ? 'graph.instagram.com' : 'graph.facebook.com';
        $endpoint = 'https://' . $host . '/' . $this->version() . '/' . $path;
        $secret = config('meta_messaging.app_secret', '');
        if (is_string($secret) && $secret !== '') $parameters['appsecret_proof'] = hash_hmac('sha256', $account['access_token'], $secret);
        $started = hrtime(true);
        try {
            $response = $this->transport !== null ? ($this->transport)($method, $endpoint, $parameters, $account['access_token'])
                : $this->network($method, $endpoint, $parameters, $account['access_token']);
            if ($method === 'POST') {
                foreach (['meta_dns', 'meta_connect', 'meta_tls', 'meta_wait'] as $phase) {
                    $duration = $response['timings'][$phase] ?? null;
                    if ((is_float($duration) || is_int($duration)) && is_finite((float) $duration)) $this->sendTimings[$phase] = $duration;
                }
            }
            $status = is_array($response) && is_int($response['status'] ?? null) ? $response['status'] : 0;
            $raw = $response['body'] ?? null;
            if (!is_string($raw) || strlen($raw) > self::MAX_BODY) throw new \RuntimeException();
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($body)) throw new \RuntimeException();
        } catch (\Throwable $error) {
            throw new MetaCommentsFailure('META_UNAVAILABLE', 503);
        } finally {
            if ($method === 'POST') $this->sendTimings['meta'] = (hrtime(true) - $started) / 1000000;
        }
        if ($status === 200 && !isset($body['error'])) return $body;
        $code = $body['error']['code'] ?? null;
        $definitive = in_array($status, [400, 401, 403, 404, 405, 409, 410, 413, 415, 422, 429], true) && is_int($code);
        if (!$definitive) throw new MetaCommentsFailure('META_UNAVAILABLE', 503);
        if ($code === 190 || $status === 401) throw new MetaCommentsFailure('RECONNECT_REQUIRED', 409, $this->permissions($account, $method === 'POST'));
        if (in_array($code, [10, 200], true) || $status === 403) throw new MetaCommentsFailure('PERMISSION_REQUIRED', 403, $this->permissions($account, $method === 'POST'));
        if ($status === 429 || in_array($code, [4, 17, 32, 613], true)) throw new MetaCommentsFailure('RATE_LIMITED', 429);
        if ($status === 404 || $code === 100) throw new MetaCommentsFailure('CONTENT_UNAVAILABLE', 404, $this->permissions($account, $method === 'POST'));
        throw new MetaCommentsFailure('META_REJECTED', 422);
    }

    private function network(string $method, string $endpoint, array $parameters, string $token): array
    {
        if ($method === 'GET') $endpoint .= '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $body = ''; $curl = curl_init($endpoint);
        $options = [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $method === 'GET' ? 15 : 25,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) return 0;
                $body .= $chunk; return strlen($chunk);
            }];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER][] = 'Expect:';
        }
        if (defined('CURL_HTTP_VERSION_2TLS') && defined('CURL_VERSION_HTTP2')
            && (curl_version()['features'] & CURL_VERSION_HTTP2) !== 0) $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        curl_setopt_array($curl, $options);
        try {
            $ok = curl_exec($curl);
            $info = curl_getinfo($curl);
            $dns = (float) ($info['namelookup_time'] ?? 0);
            $connect = (float) ($info['connect_time'] ?? 0);
            $tls = (float) curl_getinfo($curl, CURLINFO_APPCONNECT_TIME);
            return ['status' => $ok === false ? 0 : (int) ($info['http_code'] ?? 0), 'body' => $ok === false ? '' : $body,
                'timings' => ['meta_dns' => $dns * 1000, 'meta_connect' => max(0, $connect - $dns) * 1000,
                    'meta_tls' => max(0, $tls - $connect) * 1000,
                    'meta_wait' => max(0, (float) ($info['starttransfer_time'] ?? 0) - (float) ($info['pretransfer_time'] ?? 0)) * 1000]];
        } finally { curl_close($curl); }
    }

    private function rows(array $body, int $limit): array
    {
        if (!is_array($body['data'] ?? null)) throw new MetaCommentsFailure('META_UNAVAILABLE', 503);
        return array_values(array_filter(array_slice($body['data'], 0, $limit), 'is_array'));
    }

    private function text($value, int $limit): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return null;
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value);
        return $value !== '' ? mb_substr($value, 0, $limit, 'UTF-8') : null;
    }

    private function timestamp($value): ?string
    {
        if (!is_string($value) || strlen($value) > 50
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/', $value) !== 1) return null;
        try { return (new \DateTimeImmutable($value))->format(DATE_ATOM); } catch (\Throwable $error) { return null; }
    }

    private function url($value, bool $media): ?string
    {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) return null;
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || !is_string($parts['host'] ?? null)
            || preg_match('/(?:access_token|appsecret_proof|authorization)=/i', $parts['query'] ?? '')) return null;
        $host = strtolower($parts['host']);
        $allowed = $media ? preg_match('/(?:\A|\.)(?:fbcdn\.net|cdninstagram\.com)\z/', $host) === 1
            : in_array($host, ['facebook.com', 'www.facebook.com', 'instagram.com', 'www.instagram.com'], true);
        return $allowed ? $value : null;
    }

    private function finish(int $id, string $state, ?string $reason, ?array $reply = null, array $permissions = []): array
    {
        $details = $reply !== null || $permissions ? Crypt::encryptString(json_encode(['reply' => $reply,
            'required_permissions' => $permissions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) : null;
        $values = ['state' => $state, 'reason' => $reason, 'response_details' => $details,
            'completed_at' => now('UTC'), 'updated_at' => now('UTC')];
        if ($state !== 'UNKNOWN') $values['pending_key'] = null;
        $changed = DB::table('meta_comment_reply_requests')->where('id', $id)->where('state', 'SENDING')->update($values);
        return $changed === 1 ? $this->result($state, $reason, $reply, false, $permissions)
            : $this->result('UNKNOWN', 'DELIVERY_UNKNOWN', null, false);
    }

    private function receipt(object $row, bool $replayed): array
    {
        $state = in_array($row->state, ['ACCEPTED', 'FAILED'], true) ? $row->state : 'UNKNOWN';
        $details = [];
        if (is_string($row->response_details)) {
            try { $details = json_decode(Crypt::decryptString($row->response_details), true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable $error) { return $this->result('UNKNOWN', 'DELIVERY_UNKNOWN', null, $replayed); }
        }
        return $this->result($state, $state === 'UNKNOWN' ? 'DELIVERY_UNKNOWN' : $row->reason,
            is_array($details['reply'] ?? null) ? $details['reply'] : null, $replayed,
            is_array($details['required_permissions'] ?? null) ? $details['required_permissions'] : []);
    }

    private function result(string $state, ?string $reason, ?array $reply, bool $replayed, array $permissions = []): array
    {
        return ['success' => $state === 'ACCEPTED', 'state' => $state, 'reason' => $reason,
            'reply' => $reply, 'replayed' => $replayed, 'required_permissions' => $permissions];
    }
}
