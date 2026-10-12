<?php

namespace App\Services\Dashboard;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Authenticated image bytes only; Graph URLs and credentials never leave this service. */
class WhatsAppInboxMedia
{
    public const MAX_BYTES = 5242880;
    private $transport;
    private ?array $configuration;
    private ?string $cacheDirectory;
    private WhatsAppInboxMessage $messages;

    public function __construct(?callable $transport = null, ?array $configuration = null, ?string $cacheDirectory = null,
        ?WhatsAppInboxMessage $messages = null)
    {
        $this->transport = $transport; $this->configuration = $configuration; $this->cacheDirectory = $cacheDirectory;
        $this->messages = $messages ?? new WhatsAppInboxMessage();
    }

    public function image($actor, int $conversation, int $message): array
    {
        $resolved = $this->messages->resolve($actor, $conversation, $message);
        return $this->download($actor, $resolved);
    }

    public function replyImage($actor, int $conversation, int $reply): array
    {
        return $this->download($actor, $this->messages->resolveReply($actor, $conversation, $reply));
    }

    private function download($actor, array $resolved): array
    {
        $media = $this->messages->media($resolved);
        if ($media === null || $media['kind'] !== 'image') throw new HttpException(404);
        $config = $this->configuration ?? (array) config('whatsapp_replies', []);
        $token = $config['access_token'] ?? null;
        if (($config['enabled'] ?? false) !== true || !is_string($token) || strlen($token) < 16 || strlen($token) > 4096
            || preg_match('/[\s\x00-\x1f\x7f]/', $token) || ($this->transport === null && !function_exists('curl_init'))) throw new HttpException(503);
        $cacheKey = hash('sha256', 'whatsapp-private-image-v1:' . WhatsAppInboxAccess::WABA_ID . ':'
            . WhatsAppInboxAccess::PHONE_ID . ':' . $media['id'] . ':' . ($media['sha256'] ?? '') . ':' . $media['mime']);
        $cache = $this->cachePath($cacheKey);
        if ($cache !== null && $this->privateFile($cache) && filemtime($cache) >= time() - 900) {
            $bytes = file_get_contents($cache);
            if (is_string($bytes) && $this->validImage($bytes, $media['mime'], $media['sha256'])) {
                $this->recheck($actor, $resolved);
                return ['bytes' => $bytes, 'mime' => $media['mime']];
            }
        }
        $metadata = $this->get('https://graph.facebook.com/v25.0/' . $media['id'] . '?phone_number_id='
            . WhatsAppInboxAccess::PHONE_ID, $token, 65536);
        try { $meta = json_decode($metadata['body'], true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable $error) { $meta = []; }
        $digest = $this->messages->digest($meta['sha256'] ?? null);
        if ($metadata['status'] !== 200 || !is_array($meta) || isset($meta['error']) || ($meta['id'] ?? null) !== $media['id']
            || ($meta['mime_type'] ?? null) !== $media['mime'] || !is_int($meta['file_size'] ?? null)
            || $meta['file_size'] < 1 || $meta['file_size'] > self::MAX_BYTES || $digest === null
            || ($media['sha256'] !== null && !hash_equals($media['sha256'], $digest))
            || !$this->downloadUrl($meta['url'] ?? null)) throw new HttpException(502);
        $this->messages->actor($actor);
        $download = $this->get($meta['url'], $token, self::MAX_BYTES);
        if ($download['status'] !== 200 || strlen($download['body']) !== $meta['file_size']
            || !$this->validImage($download['body'], $media['mime'], $digest)) throw new HttpException(502);
        // Recheck source visibility and actor after download; concurrent hiding cannot reveal it.
        $this->recheck($actor, $resolved);
        if ($cache !== null) $this->writeCache($cache, $download['body']);
        return ['bytes' => $download['body'], 'mime' => $media['mime']];
    }

    private function downloadUrl($url): bool
    {
        if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host'] ?? '');
        return $host === 'lookaside.fbsbx.com' || $host === 'lookaside.facebook.com'
            || (preg_match('/\A[a-z0-9][a-z0-9.-]*\.fbcdn\.net\z/', $host) === 1);
    }

    private function recheck($actor, array $resolved): void
    {
        $current = $resolved['source_kind'] === 'reply'
            ? $this->messages->resolveReply($actor, $resolved['conversation_id'], $resolved['id'])
            : $this->messages->resolve($actor, $resolved['conversation_id'], $resolved['id']);
        if (!hash_equals($current['fingerprint'], $resolved['fingerprint'])) throw new HttpException(409);
    }

    private function validImage(string $bytes, string $mime, ?string $digest): bool
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || !in_array($mime, ['image/jpeg', 'image/png'], true)
            || ($digest !== null && !hash_equals($digest, hash('sha256', $bytes)))) return false;
        $info = @getimagesizefromstring($bytes);
        return is_array($info) && ($info['mime'] ?? null) === $mime && $info[0] > 0 && $info[1] > 0
            && $info[0] <= 12000 && $info[1] <= 12000 && $info[0] * $info[1] <= 40000000;
    }

    private function get(string $url, string $token, int $limit): array
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($url, $token, $limit);
            return is_array($result) && is_int($result['status'] ?? null) && is_string($result['body'] ?? null)
                && strlen($result['body']) <= $limit ? $result : ['status' => 0, 'body' => ''];
        }
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = is_string($host) ? @gethostbynamel($host) : false;
        if (!is_array($addresses) || !$addresses || count($addresses) > 32) return ['status' => 0, 'body' => ''];
        foreach ($addresses as $address) if (!$this->publicAddress($address)) return ['status' => 0, 'body' => ''];
        $body = ''; $handle = curl_init($url);
        if ($handle === false) return ['status' => 0, 'body' => ''];
        curl_setopt_array($handle, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: */*'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
            CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use (&$body, $limit) {
                if (strlen($body) + strlen($chunk) > $limit) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try { $ok = curl_exec($handle); return $ok === false ? ['status' => 0, 'body' => '']
            : ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body]; }
        finally { curl_close($handle); }
    }

    private function publicAddress($address): bool
    {
        if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
        $number = unpack('N', inet_pton($address))[1];
        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
            ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$base, $bits]) {
            $mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
            if (($number & $mask) === (unpack('N', inet_pton($base))[1] & $mask)) return false;
        }
        return true;
    }

    private function cachePath(string $key): ?string
    {
        try {
            $directory = $this->cacheDirectory ?? dirname(base_path()) . '/whatsapp-private-images';
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
            if (is_link($directory) || realpath($directory) !== $directory) return null;
            $info = lstat($directory);
            if (!$info || ($info['mode'] & 0170000) !== 0040000 || ($info['mode'] & 0077)
                || (function_exists('posix_geteuid') && $info['uid'] !== posix_geteuid())) return null;
            $files = glob($directory . '/*.bin') ?: [];
            if (count($files) > 64) return null;
            $total = 0;
            foreach ($files as $file) {
                if (!$this->privateFile($file)) return null;
                if (filemtime($file) < time() - 900) @unlink($file); else $total += filesize($file);
            }
            return $total > 67108864 ? null : $directory . '/' . $key . '.bin';
        } catch (\Throwable $error) { return null; }
    }

    private function privateFile(string $path): bool
    {
        $s = @lstat($path);
        return $s && ($s['mode'] & 0170000) === 0100000 && $s['nlink'] === 1 && !($s['mode'] & 0077)
            && $s['size'] > 0 && $s['size'] <= self::MAX_BYTES
            && (!function_exists('posix_geteuid') || $s['uid'] === posix_geteuid());
    }
    private function writeCache(string $path, string $bytes): void
    {
        $temporary = null;
        try {
            $temporary = tempnam(dirname($path), '.image-');
            if (!is_string($temporary) || !chmod($temporary, 0600) || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) return;
            if (file_exists($path) && !$this->privateFile($path)) return;
            @rename($temporary, $path);
        } catch (\Throwable $error) { /* Caching never changes whether an authenticated download succeeds. */ }
        finally { if (is_string($temporary) && is_file($temporary)) @unlink($temporary); }
    }
}
