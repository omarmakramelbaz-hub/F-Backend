<?php

namespace App\Services\Dashboard;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Private uploaded snapshots and authenticated media bytes; provider URLs never leave this service. */
class WhatsAppInboxMedia
{
    public const MAX_BYTES = 5242880;
    public const MAX_ATTACHMENT_BYTES = 8388608;
    public const ATTACHMENT_TYPES = ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'];
    private $transport;
    private ?array $configuration;
    private ?string $cacheDirectory;
    private WhatsAppInboxMessage $messages;
    private ?string $attachmentDirectory;
    private array $ownedAttachments = [];

    public function __construct(?callable $transport = null, ?array $configuration = null, ?string $cacheDirectory = null,
        ?WhatsAppInboxMessage $messages = null, ?string $attachmentDirectory = null)
    {
        $this->transport = $transport; $this->configuration = $configuration; $this->cacheDirectory = $cacheDirectory;
        $this->messages = $messages ?? new WhatsAppInboxMessage();
        $this->attachmentDirectory = $attachmentDirectory;
    }

    public function attachmentsAvailable(): bool
    {
        return function_exists('finfo_buffer') && function_exists('getimagesizefromstring')
            && function_exists('mb_check_encoding') && function_exists('posix_geteuid')
            && filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN)
            && $this->attachmentLimits()['attachment_max_bytes'] > 0;
    }

    /** Advertise and enforce the PHP request limits without modifying global configuration. */
    public function attachmentLimits(): array
    {
        $maximum = self::MAX_ATTACHMENT_BYTES;
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $limit = $this->iniBytes($setting);
            if ($limit === null) continue; // PHP's zero request limit is unlimited; application caps remain finite.
            if ($setting === 'post_max_size') $limit = max(0, $limit - 65536); // Caption, name, quote, UUID, CSRF and multipart framing.
            $maximum = min($maximum, $limit);
        }
        return ['attachment_max_bytes' => max(0, $maximum), 'image_max_bytes' => min(self::MAX_BYTES, max(0, $maximum))];
    }

    private function iniBytes(string $setting): ?int
    {
        $value = trim((string) ini_get($setting));
        if ($value === '0') return null;
        if (!preg_match('/\A([0-9]+)\s*([KMG]?)\z/i', $value, $parts)) return 0;
        if ((float) $parts[1] === 0.0) return null;
        $factor = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($parts[2])];
        // Values beyond this ceiling cannot tighten either finite application limit.
        return (int) min(self::MAX_ATTACHMENT_BYTES + 65536, (float) $parts[1] * $factor);
    }

    /** Snapshot uploaded bytes locally before the durable claim; no provider operation happens here. */
    public function prepareAttachment(UploadedFile $file): array
    {
        if (!$this->attachmentsAvailable() || !$file->isValid() || is_link($file->getPathname())) throw new HttpException(422);
        $source = $file->getRealPath(); $before = is_string($source) ? @lstat($source) : false;
        $limits = $this->attachmentLimits();
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
            || $before['size'] < 1 || $before['size'] > $limits['attachment_max_bytes']) throw new HttpException(422);
        $reader = @fopen($source, 'rb');
        if (!$reader) throw new HttpException(422);
        try {
            $opened = fstat($reader);
            if (!$opened || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                || $opened['size'] !== $before['size']) throw new HttpException(422);
            $bytes = stream_get_contents($reader, self::MAX_ATTACHMENT_BYTES + 1);
            $after = fstat($reader);
            if (!is_string($bytes) || strlen($bytes) !== $before['size'] || !$after
                || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime']
                || $after['ctime'] !== $before['ctime']) throw new HttpException(422);
        } finally { fclose($reader); }
        $mime = $this->attachmentMime($bytes);
        if ($mime === null || (str_starts_with($mime, 'image/') && strlen($bytes) > $limits['image_max_bytes'])) throw new HttpException(422);
        $filename = $this->attachmentFilename($file->getClientOriginalName(), $mime);
        [$directory, $lock] = $this->privateAttachmentDirectory();
        $path = $directory . '/attachment-' . bin2hex(random_bytes(24)) . '.bin';
        try {
            $writer = @fopen($path, 'xb');
            if (!$writer) throw new HttpException(503);
            try {
                if (!@chmod($path, 0600) || fwrite($writer, $bytes) !== strlen($bytes) || !fflush($writer)) throw new HttpException(503);
                $info = fstat($writer);
                if (!$info) throw new HttpException(503);
                $this->ownedAttachments[$path] = ['dev' => $info['dev'], 'ino' => $info['ino']];
            } catch (\Throwable $error) {
                fclose($writer); @unlink($path); throw $error;
            }
            fclose($writer);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return ['path' => $path, 'mime' => $mime, 'filename' => $filename, 'size' => strlen($bytes),
            'input_sha256' => hash('sha256', $bytes), 'attachment' => true,
            'kind' => str_starts_with($mime, 'image/') ? 'image' : 'document'];
    }

    public function preparedAttachmentValid(array $prepared): bool
    {
        $path = $prepared['path'] ?? null;
        if (!is_string($path) || !isset($this->ownedAttachments[$path]) || !$this->privateFile($path)) return false;
        $info = @lstat($path); $owned = $this->ownedAttachments[$path];
        return $info && $info['dev'] === $owned['dev'] && $info['ino'] === $owned['ino']
            && $info['size'] === ($prepared['size'] ?? null)
            && is_string($prepared['input_sha256'] ?? null)
            && hash_equals($prepared['input_sha256'], (string) @hash_file('sha256', $path));
    }

    public function cleanupAttachment(string $path): void
    {
        if (!isset($this->ownedAttachments[$path])) return;
        $owned = $this->ownedAttachments[$path]; unset($this->ownedAttachments[$path]);
        $info = @lstat($path);
        if ($info && ($info['mode'] & 0170000) === 0100000 && $info['nlink'] === 1 && !($info['mode'] & 0077)
            && $info['uid'] === posix_geteuid() && $info['dev'] === $owned['dev'] && $info['ino'] === $owned['ino']) @unlink($path);
    }

    public function __destruct()
    {
        foreach (array_keys($this->ownedAttachments) as $path) $this->cleanupAttachment($path);
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

    public function replyAttachment($actor, int $conversation, int $reply): array
    {
        return $this->download($actor, $this->messages->resolveReply($actor, $conversation, $reply), true);
    }

    private function download($actor, array $resolved, bool $document = false): array
    {
        $media = $this->messages->media($resolved);
        if ($media === null || $media['kind'] !== ($document ? 'document' : 'image')
            || !in_array($media['mime'], self::ATTACHMENT_TYPES, true)) throw new HttpException(404);
        $limit = $document ? self::MAX_ATTACHMENT_BYTES : self::MAX_BYTES;
        $config = $this->configuration ?? (array) config('whatsapp_replies', []);
        $token = $config['access_token'] ?? null;
        if (($config['enabled'] ?? false) !== true || !is_string($token) || strlen($token) < 16 || strlen($token) > 4096
            || preg_match('/[\s\x00-\x1f\x7f]/', $token) || ($this->transport === null && !function_exists('curl_init'))) throw new HttpException(503);
        $cacheKey = hash('sha256', 'whatsapp-private-media-v2:' . $media['kind'] . ':' . WhatsAppInboxAccess::WABA_ID . ':'
            . WhatsAppInboxAccess::PHONE_ID . ':' . $media['id'] . ':' . ($media['sha256'] ?? '') . ':' . $media['mime']);
        $cache = $this->cachePath($cacheKey);
        if ($cache !== null && $this->privateFile($cache) && filemtime($cache) >= time() - 900) {
            $bytes = file_get_contents($cache);
            if (is_string($bytes) && $this->validAttachmentBytes($bytes, $media['mime'], $media['sha256'])) {
                $this->recheck($actor, $resolved);
                return $this->downloadResult($bytes, $media, $document);
            }
        }
        $metadata = $this->get('https://graph.facebook.com/v25.0/' . $media['id'] . '?phone_number_id='
            . WhatsAppInboxAccess::PHONE_ID, $token, 65536);
        try { $meta = json_decode($metadata['body'], true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable $error) { $meta = []; }
        $digest = $this->messages->digest($meta['sha256'] ?? null);
        if ($metadata['status'] !== 200 || !is_array($meta) || isset($meta['error']) || ($meta['id'] ?? null) !== $media['id']
            || ($meta['mime_type'] ?? null) !== $media['mime'] || !is_int($meta['file_size'] ?? null)
            || $meta['file_size'] < 1 || $meta['file_size'] > $limit || $digest === null
            || (isset($media['size']) && $media['size'] !== $meta['file_size'])
            || ($media['sha256'] !== null && !hash_equals($media['sha256'], $digest))
            || !$this->downloadUrl($meta['url'] ?? null)) throw new HttpException(502);
        $this->messages->actor($actor);
        $download = $this->get($meta['url'], $token, $limit);
        if ($download['status'] !== 200 || strlen($download['body']) !== $meta['file_size']
            || !$this->validAttachmentBytes($download['body'], $media['mime'], $digest)) throw new HttpException(502);
        // Recheck source visibility and actor after download; concurrent hiding cannot reveal it.
        $this->recheck($actor, $resolved);
        if ($cache !== null) $this->writeCache($cache, $download['body']);
        return $this->downloadResult($download['body'], $media, $document);
    }

    private function downloadResult(string $bytes, array $media, bool $document): array
    {
        $result = ['bytes' => $bytes, 'mime' => $media['mime']];
        if ($document) $result['filename'] = $this->attachmentFilename($media['filename'] ?? '', $media['mime']);
        return $result;
    }

    private function validAttachmentBytes(string $bytes, string $mime, ?string $digest): bool
    {
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) return $this->validImage($bytes, $mime, $digest);
        return ($digest === null || hash_equals($digest, hash('sha256', $bytes))) && $this->attachmentMime($bytes) === $mime;
    }

    private function attachmentMime(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_ATTACHMENT_BYTES || !function_exists('finfo_open')) return null;
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if (!$info) return null;
        try { $mime = finfo_buffer($info, $bytes); } finally { finfo_close($info); }
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) return $this->validImage($bytes, $mime, null) ? $mime : null;
        if ($mime === 'application/pdf') return preg_match('/\A%PDF-[12]\.[0-9](?:\r|\n)/', $bytes)
            && preg_match('/%%EOF[\x09\x0a\x0c\x0d\x20]*\z/', substr($bytes, -1024)) ? $mime : null;
        if ($mime === 'text/plain' && mb_check_encoding($bytes, 'UTF-8')
            && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $bytes)) return $mime;
        return null;
    }

    private function attachmentFilename(string $name, string $mime): string
    {
        if (!mb_check_encoding($name, 'UTF-8') || strlen($name) > 1024) $name = '';
        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\<>:"|?*\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '-', $name) ?? '';
        $name = preg_replace('/\.[^.]*\z/u', '', trim($name)) ?? '';
        $name = trim(mb_substr($name, 0, 110), '. -');
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf', 'text/plain' => 'txt'][$mime] ?? 'bin';
        return ($name === '' ? 'attachment' : $name) . '.' . $extension;
    }

    private function privateAttachmentDirectory(): array
    {
        $directory = $this->attachmentDirectory ?? dirname(base_path()) . '/whatsapp-attachment-tmp';
        $parent = dirname($directory); $parentInfo = @lstat($parent);
        if (realpath($parent) !== $parent || !$parentInfo || !in_array($parentInfo['uid'], [0, posix_geteuid()], true)
            || ($parentInfo['mode'] & 0022) || !is_writable($parent)) throw new HttpException(503);
        if (!file_exists($directory) && !@mkdir($directory, 0700)) throw new HttpException(503);
        $info = @lstat($directory);
        if (!$info || is_link($directory) || realpath($directory) !== $directory || ($info['mode'] & 0170000) !== 0040000
            || $info['uid'] !== posix_geteuid() || ($info['mode'] & 0777) !== 0700) throw new HttpException(503);
        $lockPath = $directory . '/.attachments.lock'; $old = @lstat($lockPath);
        if ($old && (($old['mode'] & 0170000) !== 0100000 || $old['nlink'] !== 1
            || $old['uid'] !== posix_geteuid() || ($old['mode'] & 0077))) throw new HttpException(503);
        $lock = @fopen($lockPath, $old ? 'c+b' : 'x+b');
        if (!$lock) throw new HttpException(503);
        try {
            $opened = fstat($lock); $current = @lstat($lockPath);
            if (!$opened || !$current || ($current['mode'] & 0170000) !== 0100000 || $current['nlink'] !== 1
                || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
                || ($old && ($old['dev'] !== $opened['dev'] || $old['ino'] !== $opened['ino']))
                || !@chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) throw new HttpException(503);
            $files = glob($directory . '/attachment-*.bin') ?: []; $total = 0; $active = 0;
            if (count($files) > 128) throw new HttpException(503);
            foreach ($files as $path) {
                if (!preg_match('/\Aattachment-[a-f0-9]{48}\.bin\z/', basename($path))) throw new HttpException(503);
                if (!$this->privateFile($path)) {
                    $metadata = @lstat($path);
                    if ($metadata && ($metadata['mode'] & 0170000) === 0100000 && $metadata['nlink'] === 1
                        && $metadata['uid'] === posix_geteuid() && !($metadata['mode'] & 0077) && $metadata['size'] === 0
                        && $metadata['mtime'] < time() - 900 && @unlink($path)) continue;
                    throw new HttpException(503);
                }
                if (filemtime($path) < time() - 900 && !isset($this->ownedAttachments[$path]) && @unlink($path)) continue;
                $total += filesize($path); $active++;
            }
            if ($active >= 64 || $total > 67108864 - self::MAX_ATTACHMENT_BYTES) throw new HttpException(503);
            return [$directory, $lock];
        } catch (\Throwable $error) {
            flock($lock, LOCK_UN); fclose($lock); throw $error;
        }
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
            && $s['size'] > 0 && $s['size'] <= self::MAX_ATTACHMENT_BYTES
            && (!function_exists('posix_geteuid') || $s['uid'] === posix_geteuid());
    }
    private function writeCache(string $path, string $bytes): void
    {
        $temporary = null; $temporaryOwned = null; $lock = null;
        try {
            if (!function_exists('posix_geteuid') || $bytes === '' || strlen($bytes) > self::MAX_ATTACHMENT_BYTES
                || !preg_match('/\A[a-f0-9]{64}\.bin\z/', basename($path))) return;
            $directory = dirname($path); $directoryInfo = @lstat($directory);
            if (!$directoryInfo || ($directoryInfo['mode'] & 0170000) !== 0040000 || ($directoryInfo['mode'] & 0077)
                || $directoryInfo['uid'] !== posix_geteuid() || realpath($directory) !== $directory) return;
            $lockPath = $directory . '/.cache.lock'; $old = @lstat($lockPath);
            if ($old && (($old['mode'] & 0170000) !== 0100000 || $old['nlink'] !== 1
                || $old['uid'] !== posix_geteuid() || ($old['mode'] & 0077))) return;
            $lock = @fopen($lockPath, $old ? 'r+b' : 'x+b');
            if (!$lock) return;
            $opened = fstat($lock); $current = @lstat($lockPath);
            if (!$opened || !$current || ($current['mode'] & 0170000) !== 0100000 || $current['nlink'] !== 1
                || $opened['uid'] !== posix_geteuid() || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
                || ($old && ($old['dev'] !== $opened['dev'] || $old['ino'] !== $opened['ino']))
                || !@chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) return;
            $files = glob($directory . '/*.bin') ?: []; $total = 0; $count = 0;
            if (count($files) > 128) return;
            foreach ($files as $file) {
                if (!$this->privateFile($file)) return;
                if (filemtime($file) < time() - 900 && @unlink($file)) continue;
                if ($file !== $path) { $total += filesize($file); $count++; }
            }
            // Include the replacement or new file itself while this writer holds the lock.
            if ($count + 1 > 64 || $total + strlen($bytes) > 67108864) return;
            $temporary = tempnam(dirname($path), '.image-');
            if (!is_string($temporary)) return;
            $temporaryOwned = @lstat($temporary);
            if (!$temporaryOwned || ($temporaryOwned['mode'] & 0170000) !== 0100000 || $temporaryOwned['nlink'] !== 1
                || $temporaryOwned['uid'] !== posix_geteuid()) { $temporaryOwned = null; return; }
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) return;
            if (file_exists($path) && !$this->privateFile($path)) return;
            @rename($temporary, $path);
        } catch (\Throwable $error) { /* Caching never changes whether an authenticated download succeeds. */ }
        finally {
            if (is_string($temporary) && is_array($temporaryOwned)) {
                $current = @lstat($temporary);
                if ($current && ($current['mode'] & 0170000) === 0100000 && $current['nlink'] === 1
                    && $current['uid'] === posix_geteuid() && $current['dev'] === $temporaryOwned['dev']
                    && $current['ino'] === $temporaryOwned['ino']) @unlink($temporary);
            }
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
