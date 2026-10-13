<?php

// CLI-only resolution of five reviewed, non-message administrative captures.
// No replay, projection, Graph request, reply, profile lookup or order workflow.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class MetaAdminNoticeResolutionError extends \RuntimeException {}

final class MetaAdminNoticeResolution
{
    private const APP_ROOT = '/home/fasakha/public_html';
    private const AUDIT_PARENT = '/home/fasakha/meta-release-backups';
    private const PHP_RUNTIME = '/opt/cpanel/ea-php82/root/usr/bin/php';
    private const PAGE = '118160256722323';
    private const REVIEWED_IDS = [5, 12, 13, 18, 22];
    private const MAX_BYTES = 4194304;
    private $root;
    private $auditParent;
    private $uid;
    private $ids;
    private $page;
    private $directories = [];

    /** Alternate paths and IDs exist only for isolated tests; CLI values are fixed. */
    public function __construct(string $root, string $auditParent, int $uid,
        array $reviewedIds = self::REVIEWED_IDS, string $page = self::PAGE)
    {
        if ($uid < 0 || !$reviewedIds || count($reviewedIds) > 5
            || !\App\Support\MetaMessagingProtocol::id($page)) throw new MetaAdminNoticeResolutionError('INVALID_REVIEW_SCOPE');
        foreach ($reviewedIds as $id) if (!is_int($id) || $id < 1) throw new MetaAdminNoticeResolutionError('INVALID_REVIEW_SCOPE');
        $ids = array_values(array_unique($reviewedIds)); sort($ids);
        if (count($ids) !== count($reviewedIds)) throw new MetaAdminNoticeResolutionError('INVALID_REVIEW_SCOPE');
        $this->root = $root; $this->auditParent = $auditParent; $this->uid = $uid;
        $this->ids = $ids; $this->page = $page;
    }

    private static function keys(array $value, array $allowed): bool
    {
        return count(array_diff(array_keys($value), $allowed)) === 0;
    }

    private static function timestamp($value, bool $nullable = false): bool
    {
        if ($nullable && $value === null) return true;
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    /** Every record must be an administrative notice for the exact configured Page. */
    private function administrativeCount(array $payload): int
    {
        if (!self::keys($payload, ['object', 'entry']) || ($payload['object'] ?? null) !== 'page'
            || !is_array($payload['entry'] ?? null) || !array_is_list($payload['entry'])
            || !$payload['entry'] || count($payload['entry']) > 100) throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
        $count = 0;
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !self::keys($entry, ['id', 'time', 'messaging', 'standby'])
                || ($entry['id'] ?? null) !== $this->page) throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
            if (array_key_exists('time', $entry) && !(is_int($entry['time']) || (is_string($entry['time']) && ctype_digit($entry['time']))))
                throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
            $entryCount = 0;
            foreach (['messaging', 'standby'] as $source) {
                $events = $entry[$source] ?? [];
                if (!is_array($events) || !array_is_list($events) || count($events) > 1000)
                    throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
                foreach ($events as $event) {
                    if (!is_array($event) || !self::keys($event, ['sender', 'recipient', 'timestamp', 'message'])
                        || !is_array($event['sender'] ?? null) || !self::keys($event['sender'], ['id'])
                        || !is_array($event['recipient'] ?? null) || !self::keys($event['recipient'], ['id'])
                        || !is_array($event['message'] ?? null) || array_keys($event['message']) !== ['admin_text'])
                        throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
                    // The five reviewed captures were incoming administrative notices.
                    if (($event['recipient']['id'] ?? null) !== $this->page || ($event['sender']['id'] ?? null) === $this->page)
                        throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
                    $entryCount++; $count++;
                }
            }
            if ($entryCount === 0) throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
        }
        return $count;
    }

    private function validateCapture($row, array $account): array
    {
        if (!$row || !is_string($row->payload ?? null) || strlen($row->payload) > self::MAX_BYTES * 2
            || !is_string($row->payload_hash ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $row->payload_hash)
            || !self::timestamp($row->processed_at ?? null, true)) throw new MetaAdminNoticeResolutionError('CAPTURE_NEEDS_REVIEW');
        try {
            $plain = \Illuminate\Support\Facades\Crypt::decryptString($row->payload);
            if (strlen($plain) > self::MAX_BYTES || !hash_equals($row->payload_hash, hash('sha256', $plain)))
                throw new MetaAdminNoticeResolutionError('CAPTURE_HASH_MISMATCH');
            $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW');
            $count = $this->administrativeCount($payload);
            $report = \App\Support\MetaMessagingProtocol::report($payload, [$account]);
            if ($report !== ['messages' => [], 'ignored_count' => $count]) throw new MetaAdminNoticeResolutionError('PROTOCOL_NEEDS_REVIEW');
            return ['id' => (int) $row->id, 'payload_hash' => $row->payload_hash,
                'prior_processed_at' => $row->processed_at, 'administrative_notice_count' => $count];
        } catch (MetaAdminNoticeResolutionError $e) { throw $e; }
        catch (\Throwable $e) { throw new MetaAdminNoticeResolutionError('PAYLOAD_NEEDS_REVIEW'); }
    }

    private static function identity(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']));
    }

    private function directory(string $path, bool $private): void
    {
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') !== false || preg_match('#/(?:\.|\.\.)(?:/|$)#', $path)
            || realpath($path) !== $path) throw new MetaAdminNoticeResolutionError('AUDIT_PATH_NEEDS_REVIEW');
        $current = '';
        foreach (explode('/', substr($path, 1)) as $part) {
            $current .= '/'.$part;
            clearstatcache(true, $current); $stat = lstat($current);
            if (!$stat || ($stat['mode'] & 0170000) !== 0040000 || !in_array($stat['uid'], [0, $this->uid], true)
                || ($stat['mode'] & 0022) !== 0) throw new MetaAdminNoticeResolutionError('AUDIT_PATH_NEEDS_REVIEW');
            $this->directories[$current] = self::identity($stat);
        }
        $stat = lstat($path);
        if ($private && ($stat['uid'] !== $this->uid || ($stat['mode'] & 0777) !== 0700))
            throw new MetaAdminNoticeResolutionError('AUDIT_PATH_NEEDS_REVIEW');
    }

    private function assertDirectories(): void
    {
        foreach ($this->directories as $path => $identity) {
            clearstatcache(true, $path); $stat = lstat($path);
            if (!$stat || self::identity($stat) !== $identity) throw new MetaAdminNoticeResolutionError('AUDIT_PATH_CHANGED');
        }
    }

    private function syncDirectory(string $path): void
    {
        $handle = fopen($path, 'r');
        if (!$handle) throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
        try {
            $stat = fstat($handle);
            if (!$stat || ($stat['mode'] & 0170000) !== 0040000
                || self::identity($stat) !== ($this->directories[$path] ?? null) || !fsync($handle))
                throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
        } finally { fclose($handle); }
    }

    private function saveAudit(string $directory, array $snapshots): string
    {
        $this->assertDirectories();
        $path = $directory.'/admin-notice-before-'.bin2hex(random_bytes(12)).'.json';
        $value = ['audit' => 'META_ADMIN_NOTICE_PRE_WRITE_STATE', 'created_at_utc' => gmdate('Y-m-d H:i:s'),
            'reviewed_event_ids' => $this->ids, 'account' => 'messenger:'.$this->page, 'captures' => $snapshots];
        $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($path, 'xb');
        if (!$handle) throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
        try {
            $stat = fstat($handle); clearstatcache(true, $path); $disk = lstat($path);
            if (!$stat || !$disk || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== $this->uid
                || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600 || self::identity($stat) !== self::identity($disk))
                throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
            for ($offset = 0; $offset < strlen($bytes);) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
                $offset += $written;
            }
            if (!fflush($handle) || !function_exists('fsync') || !fsync($handle)) throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
            $this->assertDirectories();
            clearstatcache(true, $path); $after = lstat($path); $stat = fstat($handle);
            if (!$after || !$stat || self::identity($after) !== self::identity($stat) || $after['nlink'] !== 1 || $after['size'] !== strlen($bytes))
                throw new MetaAdminNoticeResolutionError('AUDIT_WRITE_FAILED');
            // Persist both the audit filename and the wrapper's newly created backup directory.
            $this->syncDirectory($directory); $this->syncDirectory($this->auditParent);
            $this->assertDirectories();
        } finally { fclose($handle); }
        return $path;
    }

    public function resolve(string $auditDirectory): array
    {
        // A new private child directory is supplied by the deployment wrapper.
        if (dirname($auditDirectory) !== $this->auditParent || $auditDirectory === $this->root
            || strncmp($auditDirectory, $this->root.'/', strlen($this->root) + 1) === 0)
            throw new MetaAdminNoticeResolutionError('AUDIT_PATH_NEEDS_REVIEW');
        $this->directories = [];
        $this->directory($this->auditParent, true); $this->directory($auditDirectory, true);
        $matches = array_values(array_filter(\App\Services\Dashboard\MetaInboxAccess::accounts(),
            fn ($a) => $a['channel'] === 'messenger' && $a['account_id'] === $this->page));
        if (count($matches) !== 1 || config('desktop_dashboard.local', false)) throw new MetaAdminNoticeResolutionError('ACCOUNT_NEEDS_REVIEW');
        $account = $matches[0];
        return \Illuminate\Support\Facades\DB::transaction(function () use ($account, $auditDirectory) {
            $snapshots = []; $pending = []; $already = 0;
            foreach ($this->ids as $id) {
                $event = \Illuminate\Support\Facades\DB::table('meta_webhook_events')->where('id', $id)->lockForUpdate()->first();
                $failure = \Illuminate\Support\Facades\DB::table('meta_inbox_ingestion_failures')->where('event_id', $id)->lockForUpdate()->first();
                $snapshot = $this->validateCapture($event, $account);
                if ($event->processed_at !== null && $failure === null) { $snapshot['prior_failure'] = null; $already++; }
                else {
                    if ($event->processed_at !== null || !$failure || !self::keys((array) $failure, ['event_id', 'reason', 'attempts', 'last_attempted_at'])
                        || ($failure->reason ?? null) !== 'INVALID_EVENT' || (int) ($failure->event_id ?? 0) !== $id
                        || !(is_int($failure->attempts ?? null) || (is_string($failure->attempts ?? null) && ctype_digit($failure->attempts)))
                        || (int) $failure->attempts < 1 || (int) $failure->attempts > 4294967295
                        || !self::timestamp($failure->last_attempted_at ?? null)) throw new MetaAdminNoticeResolutionError('QUARANTINE_NEEDS_REVIEW');
                    $snapshot['prior_failure'] = ['event_id' => $id, 'reason' => 'INVALID_EVENT',
                        'attempts' => (int) $failure->attempts, 'last_attempted_at' => $failure->last_attempted_at];
                    $pending[] = $id;
                }
                $snapshots[] = $snapshot;
            }
            // All five captures and their quarantine markers are validated under locks.
            // Save durable metadata before the first mutation; a failed mutation rolls back all rows.
            $audit = $this->saveAudit($auditDirectory, $snapshots);
            foreach ($pending as $id) {
                $changed = \Illuminate\Support\Facades\DB::table('meta_webhook_events')->where('id', $id)->whereNull('processed_at')
                    ->update(['processed_at' => now('UTC')]);
                if ($changed !== 1) throw new MetaAdminNoticeResolutionError('CAPTURE_CHANGED');
                $deleted = \Illuminate\Support\Facades\DB::table('meta_inbox_ingestion_failures')->where('event_id', $id)
                    ->where('reason', 'INVALID_EVENT')->delete();
                if ($deleted !== 1) throw new MetaAdminNoticeResolutionError('QUARANTINE_CHANGED');
            }
            $this->assertDirectories();
            return ['status' => $pending ? 'META_ADMIN_NOTICES_RESOLVED' : 'META_ADMIN_NOTICES_ALREADY_RESOLVED',
                'resolved' => count($pending), 'already_resolved' => $already,
                'messages_created' => 0, 'audit' => $audit];
        }, 1);
    }

    public static function run(): array
    {
        $args = array_slice($_SERVER['argv'] ?? [], 1);
        if (count($args) !== 1 || !preg_match('#\A--audit-dir=(/home/fasakha/meta-release-backups/[A-Za-z0-9_-]{1,100})\z#', $args[0], $match))
            throw new MetaAdminNoticeResolutionError('INVALID_ARGUMENTS');
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) throw new MetaAdminNoticeResolutionError('RUN_AS_APPLICATION_USER');
        $account = posix_getpwnam('fasakha');
        if (!$account || $account['uid'] <= 0 || posix_geteuid() !== $account['uid']) throw new MetaAdminNoticeResolutionError('RUN_AS_APPLICATION_USER');
        if (realpath(PHP_BINARY) !== realpath(self::PHP_RUNTIME) || realpath(self::APP_ROOT) !== self::APP_ROOT
            || !is_file(self::APP_ROOT.'/vendor/autoload.php') || !is_file(self::APP_ROOT.'/bootstrap/app.php'))
            throw new MetaAdminNoticeResolutionError('RUNTIME_NEEDS_REVIEW');
        chdir(self::APP_ROOT);
        require self::APP_ROOT.'/vendor/autoload.php'; $app = require self::APP_ROOT.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return (new self(self::APP_ROOT, self::AUDIT_PARENT, $account['uid']))->resolve($match[1]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077); ob_start();
    try { $out = MetaAdminNoticeResolution::run(); $exit = 0; }
    catch (\Throwable $e) {
        $out = ['status' => 'META_ADMIN_NOTICE_RESOLUTION_FAILED', 'reason' => $e instanceof MetaAdminNoticeResolutionError
            ? $e->getMessage() : 'RUNTIME_OR_DATABASE_NEEDS_REVIEW']; $exit = 1;
    }
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"; exit($exit);
}
