<?php
/** One-time recording of the owner's confirmed existing WhatsApp permissions. No messages are sent here. */

use App\Services\Dashboard\CustomerDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CustomerCrmConsentImport
{
    public const RUN_ID = '6eef8d87-b1d5-4ef2-9a17-202610170001';
    public const BRANCH = 'crm:all';
    public const KIND = 'customer.consent.import';
    private const AUDIT = ['consent', 'consented_at', 'consent_evidence', 'consented_by'];
    private $clock;

    public function __construct(?callable $clock = null) { $this->clock = $clock; }

    /** $backup must durably save the supplied before-state and return its path and SHA-256. */
    public function run(bool $apply = false, bool $ownerConfirmed = false, ?callable $backup = null, ?int $snapshotMaximum = null): array
    {
        if ($apply && !$ownerConfirmed) throw new RuntimeException('OWNER_CONFIRMATION_REQUIRED');
        if ($apply && $backup === null) throw new RuntimeException('PRIVATE_BACKUP_REQUIRED');
        if ($snapshotMaximum !== null && $snapshotMaximum < 0) throw new RuntimeException('CRM_IMPORT_SNAPSHOT_INVALID');
        foreach (['users', 'crm_customers', 'branch_operation_commands'] as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException('CRM_IMPORT_SCHEMA_UNAVAILABLE');
        }
        // A single attempt is intentional: filesystem backup writes must not be replayed by transaction retries.
        return DB::transaction(function () use ($apply, $backup, $snapshotMaximum) {
            // The persisted primary owner serializes concurrent imports, including execution on another host.
            $owner = DB::table('users')->where('id', 1)->lockForUpdate()->first();
            if (!$owner || $owner->account_type !== 'admin' || !empty($owner->owner_resturant_id)) {
                throw new RuntimeException('PRIMARY_OWNER_UNAVAILABLE');
            }
            $requestHash = hash('sha256', json_encode([
                'run_id'=>self::RUN_ID, 'owner_id'=>1, 'purposes'=>['service', 'marketing'],
                'declaration'=>'Owner confirmed existing customers previously agreed; original acquisition date and method unknown.',
            ], JSON_THROW_ON_ERROR));
            $receipt = DB::table('branch_operation_commands')->where('branch', self::BRANCH)
                ->where('actor_id', 1)->where('request_key', self::RUN_ID)->lockForUpdate()->first();
            if ($receipt) {
                if ($receipt->kind !== self::KIND || !hash_equals($requestHash, (string)$receipt->request_hash)) {
                    throw new RuntimeException('CRM_IMPORT_RECEIPT_CONFLICT');
                }
                $result = json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($result) || ($result['run_id'] ?? null) !== self::RUN_ID) {
                    throw new RuntimeException('CRM_IMPORT_RECEIPT_INVALID');
                }
                return $result + ['replayed'=>true];
            }

            $when = $this->clock ? CarbonImmutable::parse(($this->clock)(), 'UTC')->utc() : CarbonImmutable::now('UTC');
            $stamp = $when->format('Y-m-d H:i:s');
            // A durable backup from a rolled-back attempt keeps the original audience boundary.
            $maximum = $snapshotMaximum ?? (int)(DB::table('crm_customers')->max('id') ?? 0);
            $columns = ['id', 'phone', 'opted_out_at', 'updated_at'];
            foreach (['service', 'marketing'] as $purpose) foreach (self::AUDIT as $suffix) $columns[] = $purpose.'_'.$suffix;
            $customers = DB::table('crm_customers')->select($columns)->where('id', '<=', $maximum)->orderBy('id')->lockForUpdate()->get();
            $counts = ['snapshot_max_id'=>$maximum, 'snapshot_customers'=>$customers->count(),
                'customers_changed'=>0, 'customers_unchanged'=>0, 'service_granted'=>0, 'marketing_granted'=>0,
                'global_opt_out_excluded'=>0, 'invalid_phone_excluded'=>0,
                'service_existing_preserved'=>0, 'marketing_existing_preserved'=>0,
                'service_withdrawal_or_audit_preserved'=>0, 'marketing_withdrawal_or_audit_preserved'=>0];
            $plans = []; $before = [];
            $evidence = 'Owner-confirmed prior WhatsApp permission; import='.self::RUN_ID.
                '; recorded_at='.$when->toIso8601String().'; original_obtained_at=unknown; original_method=unknown.';
            foreach ($customers as $customer) {
                if ($customer->opted_out_at !== null) { $counts['global_opt_out_excluded']++; continue; }
                if (!is_string($customer->phone) || CustomerDirectory::normalizePhone($customer->phone) !== $customer->phone) {
                    $counts['invalid_phone_excluded']++; continue;
                }
                $changes = []; $purposes = [];
                foreach (['service', 'marketing'] as $purpose) {
                    if ((bool)$customer->{$purpose.'_consent'}) { $counts[$purpose.'_existing_preserved']++; continue; }
                    $audited = false;
                    foreach (['consented_at', 'consent_evidence', 'consented_by'] as $suffix) {
                        $value = $customer->{$purpose.'_'.$suffix};
                        if ($value !== null && (!is_string($value) || trim($value) !== '')) $audited = true;
                    }
                    if ($audited) { $counts[$purpose.'_withdrawal_or_audit_preserved']++; continue; }
                    $changes[$purpose.'_consent'] = true;
                    // This is the actual recording time, not an invented historical acquisition time.
                    $changes[$purpose.'_consented_at'] = $stamp;
                    $changes[$purpose.'_consent_evidence'] = $evidence;
                    $changes[$purpose.'_consented_by'] = 1;
                    $purposes[] = $purpose; $counts[$purpose.'_granted']++;
                }
                if (!$changes) continue;
                $snapshot = ['id'=>(int)$customer->id, 'opted_out_at'=>$customer->opted_out_at, 'updated_at'=>$customer->updated_at];
                foreach (['service', 'marketing'] as $purpose) foreach (self::AUDIT as $suffix) {
                    $key = $purpose.'_'.$suffix; $snapshot[$key] = $customer->{$key};
                }
                $before[] = ['before'=>$snapshot, 'granted_purposes'=>$purposes];
                $plans[] = ['id'=>(int)$customer->id, 'changes'=>$changes + ['updated_at'=>$stamp]];
            }
            $counts['customers_changed'] = count($plans);
            $counts['customers_unchanged'] = $counts['snapshot_customers'] - count($plans);
            $result = ['success'=>true, 'run_id'=>self::RUN_ID, 'recorded_by'=>1,
                'recorded_at'=>$when->toIso8601String(), 'original_obtained_at'=>null,
                'original_method'=>'unknown', 'counts'=>$counts];
            if (!$apply) return $result + ['applied'=>false, 'replayed'=>false];
            $saved = $backup($result + ['before_state'=>$before]);
            if (!is_array($saved) || !is_string($saved['path'] ?? null) || $saved['path'] === ''
                || !is_string($saved['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $saved['sha256'])) {
                throw new RuntimeException('PRIVATE_BACKUP_NOT_DURABLE');
            }
            foreach ($plans as $plan) {
                if (DB::table('crm_customers')->where('id', $plan['id'])->update($plan['changes']) !== 1) {
                    throw new RuntimeException('CRM_IMPORT_CUSTOMER_UPDATE_FAILED');
                }
            }
            $result += ['applied'=>true, 'backup'=>$saved];
            DB::table('branch_operation_commands')->insert(['branch'=>self::BRANCH, 'actor_id'=>1,
                'request_key'=>self::RUN_ID, 'request_hash'=>$requestHash, 'kind'=>self::KIND,
                'result'=>json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at'=>$stamp, 'updated_at'=>$stamp]);
            return $result + ['replayed'=>false];
        }, 1);
    }
}

/** App-user owned private directory and durable file writer; customer profile fields are not copied. */
final class CustomerCrmConsentPrivateBackup
{
    private string $base;
    private string $directory;
    private $lock;

    public function __construct(string $root)
    {
        if (!function_exists('posix_geteuid') || !function_exists('fsync')) throw new RuntimeException('PRIVATE_BACKUP_PLATFORM_UNAVAILABLE');
        $this->base = dirname($root).'/customer-crm-consent-imports';
        $this->privateDirectory($this->base);
        $this->directory = $this->base.'/'.CustomerCrmConsentImport::RUN_ID;
        $this->privateDirectory($this->directory);
        // Persist newly created directory entries as well as the backup file itself.
        $this->syncDirectory(dirname($this->base)); $this->syncDirectory($this->base);
        $path = $this->base.'/import.lock'; $this->regularFileIfExists($path);
        $this->lock = fopen($path, 'c+b');
        if (!is_resource($this->lock) || !chmod($path, 0600) || !flock($this->lock, LOCK_EX)) {
            throw new RuntimeException('PRIVATE_BACKUP_LOCK_UNAVAILABLE');
        }
        $this->regularFileIfExists($path);
    }

    public function __destruct() { if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); } }

    /** Read-only, including for preview; a failed transaction's durable audience ceiling is immutable. */
    public static function snapshotMaximum(string $root): ?int
    {
        if (!function_exists('posix_geteuid')) throw new RuntimeException('PRIVATE_BACKUP_PLATFORM_UNAVAILABLE');
        $base = dirname($root).'/customer-crm-consent-imports';
        $directory = $base.'/'.CustomerCrmConsentImport::RUN_ID;
        foreach ([$base, $directory] as $path) {
            self::noSymlinks($path); clearstatcache(true, $path);
            if (!file_exists($path)) return null;
            if (!is_dir($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0777) !== 0700) {
                throw new RuntimeException('PRIVATE_BACKUP_DIRECTORY_UNSAFE');
            }
        }
        $path = $directory.'/before.json'; self::noSymlinks($path); clearstatcache(true, $path);
        if (!file_exists($path)) return null;
        if (!is_file($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('PRIVATE_BACKUP_FILE_UNSAFE');
        }
        $body = file_get_contents($path);
        if (!is_string($body)) throw new RuntimeException('CRM_IMPORT_BACKUP_INVALID');
        try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { throw new RuntimeException('CRM_IMPORT_BACKUP_INVALID'); }
        $maximum = $data['counts']['snapshot_max_id'] ?? null;
        if (!is_array($data) || ($data['run_id'] ?? null) !== CustomerCrmConsentImport::RUN_ID
            || ($data['recorded_by'] ?? null) !== 1 || !is_int($maximum) || $maximum < 0) {
            throw new RuntimeException('CRM_IMPORT_BACKUP_INVALID');
        }
        return $maximum;
    }

    public function save(array $before): array
    {
        $target = $this->directory.'/before.json'; $this->regularFileIfExists($target);
        $temporary = $this->directory.'/before.'.bin2hex(random_bytes(12)).'.tmp';
        $stream = null;
        try {
            $body = json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $stream = fopen($temporary, 'x+b');
            if (!is_resource($stream) || !chmod($temporary, 0600)) throw new RuntimeException('PRIVATE_BACKUP_WRITE_FAILED');
            $offset = 0; $length = strlen($body);
            while ($offset < $length) {
                $written = fwrite($stream, substr($body, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('PRIVATE_BACKUP_WRITE_FAILED');
                $offset += $written;
            }
            if (!fflush($stream) || !fsync($stream)) throw new RuntimeException('PRIVATE_BACKUP_SYNC_FAILED');
            fclose($stream); $stream = null; $this->regularFileIfExists($target);
            if (!rename($temporary, $target)) throw new RuntimeException('PRIVATE_BACKUP_RENAME_FAILED');
            $this->syncDirectory($this->directory); $this->syncDirectory($this->base);
            return ['path'=>$target, 'sha256'=>hash('sha256', $body)];
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temporary) && !is_link($temporary)) unlink($temporary);
        }
    }

    private function privateDirectory(string $path): void
    {
        self::noSymlinks($path);
        if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('PRIVATE_BACKUP_DIRECTORY_FAILED');
        clearstatcache(true, $path);
        if (!is_dir($path) || is_link($path) || fileowner($path) !== posix_geteuid() || !chmod($path, 0700)) {
            throw new RuntimeException('PRIVATE_BACKUP_DIRECTORY_UNSAFE');
        }
    }

    private static function noSymlinks(string $path): void
    {
        $part = '';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') continue;
            $part .= '/'.$segment;
            if (is_link($part)) throw new RuntimeException('PRIVATE_BACKUP_SYMLINK_REJECTED');
        }
    }

    private function regularFileIfExists(string $path): void
    {
        clearstatcache(true, $path); self::noSymlinks($path);
        if (file_exists($path) && (!is_file($path) || fileowner($path) !== posix_geteuid())) {
            throw new RuntimeException('PRIVATE_BACKUP_FILE_UNSAFE');
        }
    }

    private function syncDirectory(string $path): void
    {
        $directory = fopen($path, 'r');
        if (!is_resource($directory)) throw new RuntimeException('PRIVATE_BACKUP_SYNC_FAILED');
        try { if (!fsync($directory)) throw new RuntimeException('PRIVATE_BACKUP_SYNC_FAILED'); }
        finally { fclose($directory); }
    }
}

function customerCrmConsentImportMain(array $arguments): int
{
    ini_set('display_errors', '0'); umask(0077);
    try {
        $root = null; $apply = false; $confirmed = false; $seen = [];
        foreach (array_slice($arguments, 1) as $argument) {
            $key = str_starts_with($argument, '--root=') ? '--root' : $argument;
            if (isset($seen[$key])) throw new RuntimeException('CRM_IMPORT_ARGUMENTS_INVALID');
            $seen[$key] = true;
            if ($key === '--root') $root = substr($argument, 7);
            elseif ($argument === '--apply') $apply = true;
            elseif ($argument === '--owner-confirmed') $confirmed = true;
            else throw new RuntimeException('CRM_IMPORT_ARGUMENTS_INVALID');
        }
        if (!is_string($root) || $root === '' || !is_dir($root) || realpath($root) !== $root) throw new RuntimeException('CRM_IMPORT_ROOT_INVALID');
        if (!function_exists('posix_geteuid') || fileowner($root) !== posix_geteuid()) throw new RuntimeException('CRM_IMPORT_RUN_AS_APP_USER');
        if ($apply && !$confirmed) throw new RuntimeException('OWNER_CONFIRMATION_REQUIRED');
        require $root.'/vendor/autoload.php';
        $application = require $root.'/bootstrap/app.php';
        $application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        // Acquire the filesystem lock before the database transaction; the DB receipt remains authoritative.
        $files = $apply ? new CustomerCrmConsentPrivateBackup($root) : null;
        $maximum = CustomerCrmConsentPrivateBackup::snapshotMaximum($root);
        $result = (new CustomerCrmConsentImport)->run($apply, $confirmed, $files ? [$files, 'save'] : null, $maximum);
        $prefix = !empty($result['replayed']) ? 'CRM_CONSENT_REPLAYED' : ($apply ? 'CRM_CONSENT_APPLIED' : 'CRM_CONSENT_PREVIEW');
        echo $prefix.' '.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        return 0;
    } catch (Throwable $error) {
        $safeReasons = ['OWNER_CONFIRMATION_REQUIRED', 'PRIVATE_BACKUP_REQUIRED', 'CRM_IMPORT_SCHEMA_UNAVAILABLE',
            'PRIMARY_OWNER_UNAVAILABLE', 'CRM_IMPORT_RECEIPT_CONFLICT', 'CRM_IMPORT_RECEIPT_INVALID',
            'PRIVATE_BACKUP_NOT_DURABLE', 'CRM_IMPORT_CUSTOMER_UPDATE_FAILED', 'PRIVATE_BACKUP_PLATFORM_UNAVAILABLE',
            'PRIVATE_BACKUP_LOCK_UNAVAILABLE', 'PRIVATE_BACKUP_WRITE_FAILED', 'PRIVATE_BACKUP_SYNC_FAILED',
            'PRIVATE_BACKUP_RENAME_FAILED', 'PRIVATE_BACKUP_DIRECTORY_FAILED', 'PRIVATE_BACKUP_DIRECTORY_UNSAFE',
            'PRIVATE_BACKUP_SYMLINK_REJECTED', 'PRIVATE_BACKUP_FILE_UNSAFE', 'CRM_IMPORT_ARGUMENTS_INVALID',
            'CRM_IMPORT_ROOT_INVALID', 'CRM_IMPORT_RUN_AS_APP_USER', 'CRM_IMPORT_SNAPSHOT_INVALID', 'CRM_IMPORT_BACKUP_INVALID'];
        $reason = in_array($error->getMessage(), $safeReasons, true) ? $error->getMessage() : 'CRM_CONSENT_IMPORT_FAILED';
        fwrite(STDERR, 'STOPPED: '.$reason.PHP_EOL);
        return 1;
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(customerCrmConsentImportMain($argv));
}
