<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Short encrypted provider snapshots; authorization and handle minting remain live. */
class MetaCommentsReadCache
{
    private const MAX_BYTES = 524288;

    public function read(?string $key): ?array
    {
        try {
            if (!$this->validKey($key)) return null;
            $raw = Cache::get($key);
            if (!is_string($raw) || strlen($raw) > self::MAX_BYTES * 2) return null;
            $plain = Crypt::decryptString($raw);
            if (strlen($plain) > self::MAX_BYTES) return null;
            $snapshot = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || !is_int($snapshot['expires'] ?? null) || $snapshot['expires'] <= time()
                || !is_array($snapshot['body'] ?? null) || !$this->successful($snapshot['body'])) return null;
            return $snapshot['body'];
        } catch (\Throwable $error) { return null; }
    }

    public function write(?string $key, string $path, array $body): void
    {
        try {
            if (!$this->validKey($key) || !$this->successful($body)
                || !preg_match('~/(?:published_posts|media|comments|replies)\z~', $path)) return;
            $ttl = preg_match('~/(?:published_posts|media)\z~', $path) ? 30 : 10;
            $plain = json_encode(['expires' => time() + $ttl, 'body' => $body], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($plain) > self::MAX_BYTES) return;
            Cache::put($key, Crypt::encryptString($plain), $ttl);
        } catch (\Throwable $error) { /* Cache trouble must never stop a live read. */ }
    }

    public function invalidate(string $accountKey): void
    {
        try {
            if (!preg_match('/\A[a-f0-9]{64}\z/', $accountKey)) return;
            // A generation expires only after every older provider snapshot has expired.
            Cache::put($this->generationKey($accountKey), bin2hex(random_bytes(16)), 3600);
        } catch (\Throwable $error) { /* Accepted sends remain accepted if cache invalidation fails. */ }
    }

    private function successful(array $body): bool
    {
        return !isset($body['error']) && is_array($body['data'] ?? null);
    }

    /** Capture before live I/O so a concurrent send cannot repopulate its new generation. */
    public function key(string $binding, string $accountKey, string $path, array $parameters): ?string
    {
        try {
            if (!preg_match('/\A[a-f0-9]{64}\z/', $binding) || !preg_match('/\A[a-f0-9]{64}\z/', $accountKey)
                || !preg_match('~\A[1-9][0-9]{0,31}(?:_[1-9][0-9]{0,31})?/(?:published_posts|media|comments|replies)\z~', $path)) return null;
            unset($parameters['appsecret_proof']);
            ksort($parameters, SORT_STRING);
            $generation = Cache::get($this->generationKey($accountKey), '0');
            if (!is_string($generation) || !preg_match('/\A(?:0|[a-f0-9]{32})\z/', $generation)) return null;
            $identity = json_encode([$binding, $accountKey, $generation, $path, $parameters], JSON_THROW_ON_ERROR);
            return 'meta-comments-read:v1:' . hash_hmac('sha256', $identity, Crypt::getKey());
        } catch (\Throwable $error) { return null; }
    }

    private function validKey(?string $key): bool
    {
        return is_string($key) && preg_match('/\Ameta-comments-read:v1:[a-f0-9]{64}\z/', $key) === 1;
    }

    private function generationKey(string $accountKey): string
    {
        return 'meta-comments-read-generation:v1:' . hash_hmac('sha256', $accountKey, Crypt::getKey());
    }
}
