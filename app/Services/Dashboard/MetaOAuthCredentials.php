<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;

/** Private OAuth credentials may replace tokens only for existing business identities. */
class MetaOAuthCredentials
{
    private const APP_ID = '2177982783065133';
    private const CONFIG_ID = '3007667439572336';
    private const API_VERSION = 'v25.0';
    private const MAX_ACCOUNTS = 100;
    private const MAX_PLAIN_BYTES = 524288;
    private const MAX_FILE_BYTES = 1048576;
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? storage_path('app/private/meta-oauth'), '/');
    }

    /** Settings must be explicitly installed; absent settings never activate OAuth. */
    public function settings(): array
    {
        try {
            $raw = $this->readPrivateFile('settings.json', 8192);
            if ($raw === null) throw new \RuntimeException('MISSING');
            $settings = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($settings) || ($settings['app_id'] ?? null) !== self::APP_ID
                || ($settings['config_id'] ?? null) !== self::CONFIG_ID
                || ($settings['api_version'] ?? null) !== self::API_VERSION) {
                throw new \RuntimeException('SETTINGS');
            }
            $uri = $settings['redirect_uri'] ?? null;
            if (!is_string($uri) || strlen($uri) > 512) throw new \RuntimeException('URI');
            $parts = parse_url($uri);
            if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                || ($parts['path'] ?? null) !== '/admin/meta/connection/callback'
                || !is_string($parts['host'] ?? null)
                || !preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $parts['host'])
                || array_diff(array_keys($parts), ['scheme', 'host', 'path'])
                || $uri !== 'https://'.$parts['host'].'/admin/meta/connection/callback') {
                throw new \RuntimeException('URI');
            }
            return ['app_id' => self::APP_ID, 'config_id' => self::CONFIG_ID,
                'redirect_uri' => $uri, 'api_version' => self::API_VERSION];
        } catch (\Throwable $error) {
            throw new MetaOAuthFailure('OAUTH_NOT_CONFIGURED');
        }
    }

    /** Merge selected accounts while holding one lock; all token data is encrypted together. */
    public function save(array $bundle): void
    {
        $lock = null;
        try {
            $this->settings();
            $incoming = $this->validateBundle($bundle);
            $allowed = $this->allowedPairs();
            foreach ($incoming['accounts'] as $account) {
                $baseline = $allowed[$account['channel'].':'.$account['account_id']] ?? null;
                if (!is_array($baseline) || !$this->matchesPageBinding($account, $baseline)) throw new \RuntimeException('ALLOWLIST');
            }
            $lock = $this->acquireLock();
            $existing = $this->readBundle();
            $merged = [];
            foreach ($existing['accounts'] ?? [] as $account) $merged[$account['channel'].':'.$account['account_id']] = $account;
            foreach ($incoming['accounts'] as $account) {
                $account['connected_at'] = $incoming['authorized_at'];
                $merged[$account['channel'].':'.$account['account_id']] = $account;
            }
            $incoming['accounts'] = array_values($merged);
            $saved = $this->validateBundle($incoming);
            $plain = json_encode($saved, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($plain) > self::MAX_PLAIN_BYTES) throw new \RuntimeException('SIZE');
            $encrypted = Crypt::encryptString($plain);
            if (!is_string($encrypted) || strlen($encrypted) > self::MAX_FILE_BYTES - 100) throw new \RuntimeException('SIZE');
            $wrapper = json_encode(['version' => 1, 'ciphertext' => $encrypted], JSON_THROW_ON_ERROR);
            $this->atomicWrite($wrapper);
        } catch (\Throwable $error) {
            throw new MetaOAuthFailure('CREDENTIALS_SAVE_FAILED');
        } finally {
            if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    }

    /** Fail quietly to native credentials; persisted accounts can never enlarge the allowlist. */
    public function overlay(array $base): array
    {
        try {
            $this->settings();
            $saved = $this->readBundle();
            if ($saved === null) return $base;
            $byIdentity = [];
            foreach ($saved['accounts'] as $account) $byIdentity[$account['channel'].':'.$account['account_id']] = $account;
            foreach ($base as $key => $account) {
                if (!is_array($account) || !in_array($account['channel'] ?? null, ['messenger', 'instagram'], true)
                    || !$this->validId($account['account_id'] ?? null)) continue;
                $savedAccount = $byIdentity[$account['channel'].':'.$account['account_id']] ?? null;
                if ($savedAccount === null || !$this->matchesPageBinding($savedAccount, $account)) continue;
                foreach (['access_token', 'login', 'page_id'] as $field) $account[$field] = $savedAccount[$field];
                $base[$key] = $account;
            }
        } catch (\Throwable $error) { /* A corrupt or unavailable overlay never disables native accounts. */ }
        return $base;
    }

    /** The connection screen receives no tokens, authorized-user IDs, or raw errors. */
    public function status(): array
    {
        $status = ['configured' => false, 'credentials_available' => false, 'accounts' => []];
        try {
            $this->settings();
            $status['configured'] = true;
            $saved = $this->readBundle();
            if ($saved === null) return $status;
            $allowed = $this->allowedPairs();
            foreach ($saved['accounts'] as $account) {
                $baseline = $allowed[$account['channel'].':'.$account['account_id']] ?? null;
                if (!is_array($baseline) || !$this->matchesPageBinding($account, $baseline)) continue;
                $status['accounts'][] = ['channel' => $account['channel'], 'account_id' => $account['account_id'],
                    'label' => $account['label'], 'connected_at' => $account['connected_at']];
            }
            $status['credentials_available'] = count($status['accounts']) > 0;
        } catch (\Throwable $error) { /* Return fixed metadata only. */ }
        return $status;
    }

    private function readBundle(): ?array
    {
        $raw = $this->readPrivateFile('credentials.json', self::MAX_FILE_BYTES);
        if ($raw === null) return null;
        $wrapper = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($wrapper) || ($wrapper['version'] ?? null) !== 1
            || !is_string($wrapper['ciphertext'] ?? null)
            || array_diff(array_keys($wrapper), ['version', 'ciphertext'])) throw new \RuntimeException('ENVELOPE');
        $plain = Crypt::decryptString($wrapper['ciphertext']);
        if (!is_string($plain) || strlen($plain) > self::MAX_PLAIN_BYTES) throw new \RuntimeException('SIZE');
        $bundle = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($bundle)) throw new \RuntimeException('BUNDLE');
        return $this->validateBundle($bundle);
    }

    private function validateBundle(array $bundle): array
    {
        if (!$this->validId($bundle['facebook_user_id'] ?? null)
            || !is_array($bundle['accounts'] ?? null)
            || count($bundle['accounts']) < 1 || count($bundle['accounts']) > self::MAX_ACCOUNTS
            || array_keys($bundle['accounts']) !== range(0, count($bundle['accounts']) - 1)) throw new \RuntimeException('BUNDLE');
        $at = $this->timestamp($bundle['authorized_at'] ?? null);
        $accounts = []; $seen = [];
        foreach ($bundle['accounts'] as $account) {
            if (!is_array($account) || !in_array($account['channel'] ?? null, ['messenger', 'instagram'], true)
                || !$this->validId($account['account_id'] ?? null) || ($account['login'] ?? null) !== 'facebook'
                || !is_string($account['access_token'] ?? null)
                || !preg_match('/\A[\x21-\x7e]{16,4096}\z/', $account['access_token'])
                || !is_string($account['label'] ?? null) || strlen($account['label']) > 400
                || !preg_match('/\A[^\x00-\x1f\x7f]{1,100}\z/u', $account['label'])) throw new \RuntimeException('ACCOUNT');
            $identity = $account['channel'].':'.$account['account_id'];
            if (isset($seen[$identity])) throw new \RuntimeException('DUPLICATE');
            $seen[$identity] = true;
            $pageId = $account['page_id'] ?? ($account['channel'] === 'messenger' ? $account['account_id'] : null);
            if (!$this->validId($pageId) || ($account['channel'] === 'messenger' && $pageId !== $account['account_id'])) throw new \RuntimeException('PAGE');
            $accounts[] = ['channel' => $account['channel'], 'account_id' => $account['account_id'],
                'label' => $account['label'], 'access_token' => $account['access_token'], 'login' => 'facebook',
                'page_id' => $pageId, 'connected_at' => $this->timestamp($account['connected_at'] ?? $at)];
        }
        return ['accounts' => $accounts, 'facebook_user_id' => $bundle['facebook_user_id'], 'authorized_at' => $at];
    }

    private function validId($value): bool
    {
        return is_string($value) && (bool) preg_match('/\A[1-9][0-9]{0,31}\z/', $value);
    }

    private function allowedPairs(): array
    {
        $base = config('meta_messaging.accounts', []);
        if (!is_array($base)) throw new \RuntimeException('ALLOWLIST');
        $allowed = [];
        foreach ($base as $account) {
            if (is_array($account) && in_array($account['channel'] ?? null, ['messenger', 'instagram'], true)
                && $this->validId($account['account_id'] ?? null)) $allowed[$account['channel'].':'.$account['account_id']] = $account;
        }
        return $allowed;
    }

    /** A configured Instagram-to-Page link remains authoritative after token rotation. */
    private function matchesPageBinding(array $saved, array $baseline): bool
    {
        return $saved['channel'] !== 'instagram' || !array_key_exists('page_id', $baseline)
            || $baseline['page_id'] === $saved['page_id'];
    }

    private function timestamp($value): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\+00:00)\z/', $value)) throw new \RuntimeException('TIME');
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', substr($value, 0, 19).'Z', new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== substr($value, 0, 19).'Z') throw new \RuntimeException('TIME');
        return $time->format('Y-m-d\TH:i:s\Z');
    }

    private function effectiveUid(): int
    {
        if (!function_exists('posix_geteuid')) throw new \RuntimeException('OWNER');
        return posix_geteuid();
    }

    /** Every existing parent is inspected; a linked private directory is never followed. */
    private function assertDirectory(): void
    {
        if ($this->directory === '' || $this->directory[0] !== '/' || strpos($this->directory, "\0") !== false) throw new \RuntimeException('DIRECTORY');
        $path = '';
        foreach (explode('/', substr($this->directory, 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..') throw new \RuntimeException('DIRECTORY');
            $path .= '/'.$component;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000) throw new \RuntimeException('DIRECTORY');
            if ($path === $this->directory && (($stat['mode'] & 07777) !== 0700 || (int) $stat['uid'] !== $this->effectiveUid())) throw new \RuntimeException('PRIVATE');
        }
    }

    private function assertFile(array $stat): void
    {
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
            || (int) $stat['uid'] !== $this->effectiveUid() || (int) $stat['nlink'] !== 1) throw new \RuntimeException('PRIVATE');
    }

    private function sameFile(array $first, array $second): bool
    {
        return $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
    }

    private function readPrivateFile(string $name, int $limit): ?string
    {
        $this->assertDirectory();
        $path = $this->directory.'/'.$name;
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before === false) return null;
        $this->assertFile($before);
        if ($before['size'] < 1 || $before['size'] > $limit) throw new \RuntimeException('SIZE');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new \RuntimeException('READ');
        try {
            $opened = fstat($handle);
            if (!is_array($opened)) throw new \RuntimeException('READ');
            $this->assertFile($opened);
            if (!$this->sameFile($before, $opened)) throw new \RuntimeException('CHANGED');
            $raw = stream_get_contents($handle, $limit + 1);
            if (!is_string($raw) || strlen($raw) > $limit || strlen($raw) !== (int) $opened['size']) throw new \RuntimeException('SIZE');
            $this->assertDirectory();
            clearstatcache(true, $path); $after = @lstat($path);
            if (!is_array($after) || !$this->sameFile($opened, $after)) throw new \RuntimeException('CHANGED');
            $this->assertFile($after);
            return $raw;
        } finally { @fclose($handle); }
    }

    private function acquireLock()
    {
        $this->assertDirectory();
        $path = $this->directory.'/credentials.lock';
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before === false) {
            $oldMask = umask(0077);
            try { $handle = @fopen($path, 'x+b'); } finally { umask($oldMask); }
            if (!is_resource($handle)) { clearstatcache(true, $path); $before = @lstat($path); }
        } else $handle = null;
        if (!is_resource($handle)) {
            if (!is_array($before)) throw new \RuntimeException('LOCK');
            $this->assertFile($before);
            $handle = @fopen($path, 'r+b');
        }
        if (!is_resource($handle)) throw new \RuntimeException('LOCK');
        try {
            $opened = fstat($handle);
            if (!is_array($opened)) throw new \RuntimeException('LOCK');
            $this->assertFile($opened);
            clearstatcache(true, $path); $atPath = @lstat($path);
            if (!is_array($atPath) || !$this->sameFile($opened, $atPath)) throw new \RuntimeException('LOCK');
            $this->assertFile($atPath);
            $deadline = microtime(true) + 3;
            do {
                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    $this->assertDirectory();
                    clearstatcache(true, $path); $lockedPath = @lstat($path);
                    if (!is_array($lockedPath) || !$this->sameFile($opened, $lockedPath)) throw new \RuntimeException('LOCK');
                    $this->assertFile($lockedPath);
                    return $handle;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('LOCK');
        } catch (\Throwable $error) { @fclose($handle); throw $error; }
    }

    private function atomicWrite(string $contents): void
    {
        $temporary = null; $handle = null;
        try {
            $this->assertDirectory();
            $destination = $this->directory.'/credentials.json';
            clearstatcache(true, $destination); $before = @lstat($destination);
            if (is_array($before)) $this->assertFile($before);
            $temporary = @tempnam($this->directory, '.credentials-');
            if (!is_string($temporary) || dirname($temporary) !== $this->directory) throw new \RuntimeException('WRITE');
            clearstatcache(true, $temporary); $stat = @lstat($temporary);
            if (!is_array($stat)) throw new \RuntimeException('WRITE');
            $this->assertFile($stat);
            $handle = @fopen($temporary, 'wb');
            if (!is_resource($handle)) throw new \RuntimeException('WRITE');
            $opened = fstat($handle);
            if (!is_array($opened) || !$this->sameFile($stat, $opened)) throw new \RuntimeException('WRITE');
            $this->assertFile($opened);
            $offset = 0; $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written < 1) throw new \RuntimeException('WRITE');
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) throw new \RuntimeException('WRITE');
            @fclose($handle); $handle = null;
            $this->assertDirectory();
            clearstatcache(true, $destination); $atDestination = @lstat($destination);
            if ($before === false ? $atDestination !== false : !is_array($atDestination) || !$this->sameFile($before, $atDestination)) throw new \RuntimeException('CHANGED');
            if (is_array($atDestination)) $this->assertFile($atDestination);
            clearstatcache(true, $temporary); $atTemporary = @lstat($temporary);
            if (!is_array($atTemporary) || !$this->sameFile($stat, $atTemporary)) throw new \RuntimeException('CHANGED');
            $this->assertFile($atTemporary);
            if (!@rename($temporary, $destination)) throw new \RuntimeException('WRITE');
            $temporary = null;
        } finally {
            if (is_resource($handle)) @fclose($handle);
            if (is_string($temporary)) @unlink($temporary);
        }
    }
}
