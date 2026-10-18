#!/usr/bin/env php
<?php
declare(strict_types=1);

/** One-time, owner-directed service follow-up choice for the pinned legacy import.
 * No sends, campaigns, purchases, application settings, or marketing changes.
 * Define IMPORTED_FOLLOWUP_LIBRARY to load the PDO library without bootstrapping Laravel.
 */
const BFU_DATA_SHA256 = 'a49073c70061ea9ef497d7ec423af54f2666d7e2af16216a9b21e3a1340b2f14';
const BFU_BACKUP_SHA256 = 'a754314924cb6afdd70541b88473efb105b6cc02bfaf5efe7fe68b7180c0cde3';
const BFU_EVIDENCE = 'تفعيل متابعة الخدمة جماعياً بتوجيه مالك النظام للأرقام المستوردة؛ مصدر القرار: bulk-owner-directive-20261019. لم تُسجّل موافقة فردية أو شفهية من العميل، ولم تتغير موافقات العروض.';

class ImportedFollowupException extends RuntimeException {}
function bfu_fail(string $message): never { throw new ImportedFollowupException($message); }
function bfu_json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function bfu_expected(): array { return ['raw'=>34027, 'profiles'=>35050, 'valid_profiles'=>34544, 'phones'=>34248]; }
function bfu_rows(PDO $pdo, string $sql, array $bindings = []): array {
    $statement = $pdo->prepare($sql); $statement->execute($bindings); return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function bfu_bool($value): bool {
    if (in_array($value, [true, 1, '1'], true)) return true;
    if (in_array($value, [false, 0, '0'], true)) return false;
    bfu_fail('INVALID_CONSENT_VALUE');
}
function bfu_classify(array $row): string {
    if ($row['opted_out_at'] !== null) return 'global_opted_out';
    if (bfu_bool($row['service_consent'])) return 'already_service_enabled';
    if (trim((string)$row['service_consent_evidence']) !== '' || $row['service_consented_at'] !== null || $row['service_consented_by'] !== null) return 'prior_service_decision';
    return 'eligible';
}
function bfu_canonical_phone($phone): bool {
    return is_string($phone) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) === 1
        && (!str_starts_with($phone, '+20') || preg_match('/^\+20(?:1[0125][0-9]{8}|[2-9][0-9]{7,8})$/D', $phone) === 1);
}
function bfu_schema(PDO $pdo): string {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['mysql', 'sqlite'], true)) bfu_fail('UNSUPPORTED_DATABASE_DRIVER');
    $columns = [
        'users'=>['id','account_type','owner_resturant_id'],
        'legacy_customer_import_datasets'=>['id','schema_version','data_sha256','backup_sha256','source_database','branch','raw_count','profile_count'],
        'legacy_customer_import_customers'=>['id','dataset_id'],
        'legacy_customer_import_profiles'=>['id','dataset_id','phone','crm_customer_id','lookup_eligible','branch'],
        'crm_customers'=>['id','phone','service_consent','service_consented_at','service_consent_evidence','service_consented_by','marketing_consent','marketing_consented_at','marketing_consent_evidence','marketing_consented_by','opted_out_at','updated_at'],
    ];
    foreach ($columns as $table=>$required) {
        if ($driver === 'mysql') {
            $metadata = bfu_rows($pdo, 'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if (count($metadata)!==1 || strtolower((string)$metadata[0]['engine'])!=='innodb') bfu_fail('MISSING_OR_NONTRANSACTIONAL_TABLE:'.$table);
            $available = array_column(bfu_rows($pdo, 'SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), 'column_name');
        } else $available = array_column(bfu_rows($pdo, 'PRAGMA table_info("'.$table.'")'), 'name');
        if (array_diff($required, $available)) bfu_fail('INCOMPATIBLE_TABLE:'.$table);
    }
    return $driver;
}
function bfu_secure_directory(string $path, ?string $applicationRoot = null): string {
    if ($path==='' || $path[0]!=='/' || str_contains($path, "\0")) bfu_fail('INVALID_PRIVATE_DIRECTORY');
    $parts = explode('/', trim($path, '/'));
    if (!$parts || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) bfu_fail('INVALID_PRIVATE_DIRECTORY');
    $current = '';
    foreach ($parts as $part) {
        $current .= '/'.$part;
        if (is_link($current)) bfu_fail('SYMLINK_IN_PRIVATE_DIRECTORY');
        if (file_exists($current) && !is_dir($current)) bfu_fail('PRIVATE_DIRECTORY_IS_NOT_A_DIRECTORY');
    }
    if ($applicationRoot !== null && ($path===$applicationRoot || str_starts_with($path, rtrim($applicationRoot,'/').'/'))) bfu_fail('BACKUP_MUST_BE_OUTSIDE_APPLICATION');
    if (!is_dir($path) && !mkdir($path, 0700, true)) bfu_fail('CANNOT_CREATE_PRIVATE_DIRECTORY');
    if (function_exists('posix_geteuid') && fileowner($path)!==posix_geteuid()) bfu_fail('PRIVATE_DIRECTORY_OWNER_MISMATCH');
    if (!chmod($path, 0700) || (fileperms($path)&0777)!==0700) bfu_fail('CANNOT_SECURE_PRIVATE_DIRECTORY');
    return $path;
}
function bfu_backup(string $base, array $snapshot): string {
    bfu_secure_directory($base);
    $run = $base.'/followup-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
    if (!mkdir($run, 0700)) bfu_fail('CANNOT_CREATE_BACKUP_RUN');
    $path = $run.'/before.json'; $handle = fopen($path, 'xb');
    if ($handle===false) bfu_fail('CANNOT_CREATE_BACKUP_FILE');
    try {
        if (!chmod($path, 0600)) bfu_fail('CANNOT_SECURE_BACKUP_FILE');
        $bytes = bfu_json($snapshot)."\n"; $offset = 0;
        while ($offset<strlen($bytes)) { $written = fwrite($handle, substr($bytes,$offset)); if ($written===false || $written===0) bfu_fail('CANNOT_WRITE_BACKUP_FILE'); $offset += $written; }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) bfu_fail('CANNOT_FLUSH_BACKUP_FILE');
    } finally { fclose($handle); }
    return $path;
}

/** Expected counts may be reduced by library tests; CLI always uses the pinned defaults. */
function bfu_execute(PDO $pdo, bool $apply, ?string $backupDirectory = null, ?array $expected = null): array {
    $expected ??= bfu_expected();
    foreach (['raw','profiles','valid_profiles','phones'] as $key) if (!isset($expected[$key]) || !is_int($expected[$key]) || $expected[$key]<1) bfu_fail('INVALID_EXPECTED_COUNTS');
    $driver = bfu_schema($pdo); $lock = $apply && $driver==='mysql' ? ' FOR UPDATE' : '';
    if ($pdo->inTransaction()) bfu_fail('PREEXISTING_DATABASE_TRANSACTION');
    $backup = null;
    $pdo->beginTransaction();
    try {
        $actors = bfu_rows($pdo, 'SELECT id,account_type,owner_resturant_id FROM users WHERE id=1'.$lock);
        if (count($actors)!==1 || $actors[0]['account_type']!=='admin' || !empty($actors[0]['owner_resturant_id'])) bfu_fail('OWNER_ACTOR_MISMATCH');
        $datasets = bfu_rows($pdo, 'SELECT * FROM legacy_customer_import_datasets WHERE data_sha256=?'.$lock, [BFU_DATA_SHA256]);
        if (count($datasets)!==1) bfu_fail('PINNED_IMPORT_NOT_FOUND_OR_DUPLICATED');
        $dataset = $datasets[0]; $datasetId = (int)$dataset['id'];
        if ($datasetId<1 || (int)$dataset['schema_version']!==1 || $dataset['backup_sha256']!==BFU_BACKUP_SHA256 || $dataset['source_database']!=='intouch' || $dataset['branch']!=='' || (int)$dataset['raw_count']!==$expected['raw'] || (int)$dataset['profile_count']!==$expected['profiles']) bfu_fail('PINNED_IMPORT_METADATA_MISMATCH');
        $rawCount = (int)bfu_rows($pdo, 'SELECT COUNT(*) AS n FROM legacy_customer_import_customers WHERE dataset_id=?', [$datasetId])[0]['n'];
        $profileCount = (int)bfu_rows($pdo, 'SELECT COUNT(*) AS n FROM legacy_customer_import_profiles WHERE dataset_id=?', [$datasetId])[0]['n'];
        $validCount = (int)bfu_rows($pdo, 'SELECT COUNT(*) AS n FROM legacy_customer_import_profiles WHERE dataset_id=? AND phone IS NOT NULL', [$datasetId])[0]['n'];
        if ($rawCount!==$expected['raw'] || $profileCount!==$expected['profiles'] || $validCount!==$expected['valid_profiles']) bfu_fail('PINNED_IMPORT_INCOMPLETE');
        $badProfiles = (int)bfu_rows($pdo, "SELECT COUNT(*) AS n FROM legacy_customer_import_profiles WHERE dataset_id=? AND (branch IS NULL OR branch<>'' OR lookup_eligible IS NULL OR (phone IS NULL AND (crm_customer_id IS NOT NULL OR lookup_eligible<>0)) OR (phone IS NOT NULL AND (crm_customer_id IS NULL OR crm_customer_id<1 OR lookup_eligible<>1)))", [$datasetId])[0]['n'];
        if ($badProfiles!==0) bfu_fail('PINNED_IMPORT_PROFILE_IDENTITY_INVALID');
        $pairs = bfu_rows($pdo, 'SELECT DISTINCT crm_customer_id,phone FROM legacy_customer_import_profiles WHERE dataset_id=? AND phone IS NOT NULL', [$datasetId]);
        $idToPhone = []; $phoneToId = [];
        foreach ($pairs as $pair) {
            $id = (int)$pair['crm_customer_id']; $phone = $pair['phone'];
            if ($id<1 || !bfu_canonical_phone($phone) || (isset($idToPhone[$id]) && $idToPhone[$id]!==$phone) || (isset($phoneToId[$phone]) && $phoneToId[$phone]!==$id)) bfu_fail('PINNED_IMPORT_PROFILE_MAPPING_CONFLICT');
            $idToPhone[$id] = $phone; $phoneToId[$phone] = $id;
        }
        if (count($phoneToId)!==$expected['phones'] || count($idToPhone)!==$expected['phones']) bfu_fail('PINNED_IMPORT_PHONE_COUNT_MISMATCH');
        ksort($idToPhone, SORT_NUMERIC);
        $counts = ['target_customers'=>count($idToPhone),'eligible'=>0,'already_service_enabled'=>0,'global_opted_out'=>0,'prior_service_decision'=>0];
        $eligible = []; $before = [];
        $fields = ['id','phone','service_consent','service_consented_at','service_consent_evidence','service_consented_by','marketing_consent','marketing_consented_at','marketing_consent_evidence','marketing_consented_by','opted_out_at','updated_at'];
        foreach (array_chunk(array_keys($idToPhone), 500) as $chunk) {
            $marks = implode(',', array_fill(0,count($chunk),'?'));
            $rows = bfu_rows($pdo, 'SELECT '.implode(',',$fields).' FROM crm_customers WHERE id IN ('.$marks.') ORDER BY id'.$lock, $chunk);
            if (count($rows)!==count($chunk)) bfu_fail('PINNED_IMPORT_CUSTOMER_MISSING');
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if (!isset($idToPhone[$id]) || $row['phone']!==$idToPhone[$id]) bfu_fail('PINNED_IMPORT_CUSTOMER_PHONE_CHANGED');
                bfu_bool($row['marketing_consent']);
                $category = bfu_classify($row); $counts[$category]++;
                if ($category==='eligible') { $eligible[] = $id; $before[] = $row; }
            }
        }
        if (!$apply) { $pdo->commit(); return ['status'=>'IMPORTED_FOLLOWUP_CHECK_OK','data_sha256'=>BFU_DATA_SHA256,'database_writes'=>0,'filesystem_writes'=>0,'counts'=>$counts]; }
        $changed = 0; $when = gmdate('Y-m-d H:i:s');
        if ($eligible) {
            if ($backupDirectory===null) bfu_fail('PRIVATE_BACKUP_DIRECTORY_REQUIRED');
            $backup = bfu_backup($backupDirectory, ['directive'=>'bulk-owner-directive-20261019','data_sha256'=>BFU_DATA_SHA256,'dataset_id'=>$datasetId,'actor_id'=>1,'recorded_at_utc'=>$when,'counts'=>$counts,'customers'=>$before]);
            foreach (array_chunk($eligible,500) as $chunk) {
                $marks = implode(',',array_fill(0,count($chunk),'?'));
                $statement = $pdo->prepare('UPDATE crm_customers SET service_consent=1,service_consented_at=?,service_consent_evidence=?,service_consented_by=1,updated_at=? WHERE id IN ('.$marks.') AND service_consent=0 AND opted_out_at IS NULL');
                $statement->execute(array_merge([$when,BFU_EVIDENCE,$when],$chunk));
                if ($statement->rowCount()!==count($chunk)) bfu_fail('CONCURRENT_CONSENT_CHANGE_OR_UPDATE_MISMATCH');
                $changed += $statement->rowCount();
            }
        }
        $pdo->commit();
        return ['status'=>'IMPORTED_FOLLOWUP_APPLIED','data_sha256'=>BFU_DATA_SHA256,'service_enabled'=>$changed,'marketing_changed'=>0,'messages_sent'=>0,'backup'=>$backup,'counts'=>$counts];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function bfu_main(array $argv): int {
    try {
        umask(0077); $args = [];
        for ($i=1;$i<count($argv);$i++) {
            if (!str_starts_with($argv[$i],'--')) bfu_fail('UNEXPECTED_ARGUMENT');
            $key = substr($argv[$i],2);
            if (!in_array($key,['root','backup-dir','apply','dry-run'],true) || array_key_exists($key,$args)) bfu_fail('UNKNOWN_OR_DUPLICATED_OPTION');
            if (in_array($key,['apply','dry-run'],true)) $args[$key] = true;
            else { $value = $argv[++$i] ?? ''; if ($value==='' || str_starts_with($value,'--')) bfu_fail('MISSING_OPTION_VALUE'); $args[$key] = $value; }
        }
        if (isset($args['apply'],$args['dry-run'])) bfu_fail('CHOOSE_APPLY_OR_DRY_RUN');
        $apply = isset($args['apply']);
        $root = realpath($args['root'] ?? '');
        if (!$root || !is_dir($root) || !is_file($root.'/artisan') || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) bfu_fail('INVALID_LARAVEL_ROOT');
        if (!function_exists('posix_geteuid') || posix_geteuid()===0 || fileowner($root)!==posix_geteuid()) bfu_fail('RUN_AS_APPLICATION_OWNER');
        $backup = $args['backup-dir'] ?? null;
        if ($apply && $backup===null) bfu_fail('PRIVATE_BACKUP_DIRECTORY_REQUIRED');
        if ($backup!==null && ($backup===$root || str_starts_with($backup,rtrim($root,'/').'/'))) bfu_fail('BACKUP_MUST_BE_OUTSIDE_APPLICATION');
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $connection = Illuminate\Support\Facades\DB::connection();
        if ($connection->getDriverName()!=='mysql' || $connection->getTablePrefix()!=='') bfu_fail('PRODUCTION_REQUIRES_UNPREFIXED_MYSQL');
        echo bfu_json(bfu_execute($connection->getPdo(),$apply,$backup))."\n"; return 0;
    } catch (Throwable $error) { echo bfu_json(['status'=>'IMPORTED_FOLLOWUP_FAILED','error'=>$error instanceof ImportedFollowupException ? $error->getMessage() : 'DATABASE_OR_APPLICATION_FAILURE'])."\n"; return 1; }
}
if (!defined('IMPORTED_FOLLOWUP_LIBRARY')) exit(bfu_main($argv));
