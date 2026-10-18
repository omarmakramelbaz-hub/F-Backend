#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Owner-directed service follow-up for currently visible dashboard customers.
 * Define REMAINING_FOLLOWUP_LIBRARY to use the PDO core without Laravel.
 * This updates no marketing choices, application settings, orders, or messages.
 */
const RF_DIRECTIVE = 'bulk-owner-directive-remaining-20261019';
const RF_EVIDENCE = 'تفعيل متابعة الخدمة جماعياً بتوجيه مالك النظام للعملاء الظاهرين في قائمة العملاء؛ مصدر القرار: bulk-owner-directive-remaining-20261019. لم تُسجّل موافقة فردية أو شفهية من العميل، ولم تتغير موافقات العروض.';
const RF_MAX_CHANGES = 500;
class RemainingFollowupException extends RuntimeException {}
function rf_fail(string $message): never { throw new RemainingFollowupException($message); }
function rf_json($value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function rf_rows(PDO $pdo, string $sql, array $bindings = []): array {
    $statement = $pdo->prepare($sql); $statement->execute($bindings); return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function rf_bool($value): bool {
    if (in_array($value,[true,1,'1'],true)) return true;
    if (in_array($value,[false,0,'0'],true)) return false;
    rf_fail('INVALID_CONSENT_VALUE');
}
function rf_canonical_phone($phone): bool {
    return is_string($phone) && preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)===1
        && (!str_starts_with($phone,'+20') || preg_match('/^\+20(?:1[0125][0-9]{8}|[2-9][0-9]{7,8})$/D',$phone)===1);
}
function rf_classify(array $row): string {
    if ($row['opted_out_at']!==null) return 'global_opted_out';
    if (rf_bool($row['service_consent'])) return 'service_enabled_before';
    if (trim((string)$row['service_consent_evidence'])!=='' || $row['service_consented_at']!==null || $row['service_consented_by']!==null) return 'prior_service_decision';
    if (!rf_canonical_phone($row['phone'])) return 'invalid_phone';
    return 'eligible';
}
function rf_scope(array $scope): array {
    $result = [''=>true];
    foreach ($scope as $branch) {
        if (!is_string($branch) || ($branch!=='' && preg_match('/^(f|gs):[1-9][0-9]{0,18}$/D',$branch)!==1)) rf_fail('INVALID_DASHBOARD_BRANCH_SCOPE');
        $result[$branch] = true;
    }
    $keys = array_keys($result); sort($keys,SORT_STRING); return $keys;
}
function rf_schema(PDO $pdo): string {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver,['mysql','sqlite'],true)) rf_fail('UNSUPPORTED_DATABASE_DRIVER');
    $columns = [
        'users'=>['id','account_type','owner_resturant_id'],
        'crm_customer_sources'=>['customer_id','branch','channel'],
        'crm_customers'=>['id','phone','service_consent','service_consented_at','service_consent_evidence','service_consented_by','marketing_consent','marketing_consented_at','marketing_consent_evidence','marketing_consented_by','opted_out_at','updated_at'],
    ];
    foreach ($columns as $table=>$required) {
        if ($driver==='mysql') {
            $metadata = rf_rows($pdo,'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
            if (count($metadata)!==1 || strtolower((string)$metadata[0]['engine'])!=='innodb') rf_fail('MISSING_OR_NONTRANSACTIONAL_TABLE:'.$table);
            $available = array_column(rf_rows($pdo,'SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]),'column_name');
        } else $available = array_column(rf_rows($pdo,'PRAGMA table_info("'.$table.'")'),'name');
        if (array_diff($required,$available)) rf_fail('INCOMPATIBLE_TABLE:'.$table);
    }
    if ($driver==='mysql' && !rf_rows($pdo,"SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_customer_sources' AND COLUMN_NAME='customer_id' AND SEQ_IN_INDEX=1")) rf_fail('CUSTOMER_SOURCE_ID_INDEX_REQUIRED');
    return $driver;
}
function rf_secure_directory(string $path, ?string $applicationRoot = null): string {
    if ($path==='' || $path[0]!=='/' || str_contains($path,"\0")) rf_fail('INVALID_PRIVATE_DIRECTORY');
    $parts = explode('/',trim($path,'/'));
    if (!$parts || in_array('',$parts,true) || in_array('.',$parts,true) || in_array('..',$parts,true)) rf_fail('INVALID_PRIVATE_DIRECTORY');
    $current = '';
    foreach ($parts as $part) {
        $current .= '/'.$part;
        if (is_link($current)) rf_fail('SYMLINK_IN_PRIVATE_DIRECTORY');
        if (file_exists($current) && !is_dir($current)) rf_fail('PRIVATE_DIRECTORY_IS_NOT_A_DIRECTORY');
    }
    if ($applicationRoot!==null && ($path===$applicationRoot || str_starts_with($path,rtrim($applicationRoot,'/').'/'))) rf_fail('BACKUP_MUST_BE_OUTSIDE_APPLICATION');
    if (!is_dir($path) && !mkdir($path,0700,true)) rf_fail('CANNOT_CREATE_PRIVATE_DIRECTORY');
    if (function_exists('posix_geteuid') && fileowner($path)!==posix_geteuid()) rf_fail('PRIVATE_DIRECTORY_OWNER_MISMATCH');
    if (!chmod($path,0700) || (fileperms($path)&0777)!==0700) rf_fail('CANNOT_SECURE_PRIVATE_DIRECTORY');
    return $path;
}
function rf_backup(string $base,array $snapshot): string {
    rf_secure_directory($base);
    $run = $base.'/remaining-followup-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
    if (!mkdir($run,0700)) rf_fail('CANNOT_CREATE_BACKUP_RUN');
    $path = $run.'/before.json'; $handle = fopen($path,'xb');
    if ($handle===false) rf_fail('CANNOT_CREATE_BACKUP_FILE');
    try {
        if (!chmod($path,0600)) rf_fail('CANNOT_SECURE_BACKUP_FILE');
        $bytes = rf_json($snapshot)."\n"; $offset = 0;
        while ($offset<strlen($bytes)) { $written = fwrite($handle,substr($bytes,$offset)); if ($written===false || $written===0) rf_fail('CANNOT_WRITE_BACKUP_FILE'); $offset += $written; }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) rf_fail('CANNOT_FLUSH_BACKUP_FILE');
    } finally { fclose($handle); }
    return $path;
}

/** $scope must come from BranchOperations::branches('all', owner), not source rows. */
function rf_execute(PDO $pdo,array $scope,bool $apply,?string $backupDirectory = null): array {
    $scope = rf_scope($scope); $driver = rf_schema($pdo);
    $lock = $apply && $driver==='mysql' ? ' FOR UPDATE' : '';
    if ($pdo->inTransaction()) rf_fail('PREEXISTING_DATABASE_TRANSACTION');
    $pdo->beginTransaction(); $backup = null;
    try {
        $actors = rf_rows($pdo,'SELECT id,account_type,owner_resturant_id FROM users WHERE id=1'.$lock);
        if (count($actors)!==1 || $actors[0]['account_type']!=='admin' || !empty($actors[0]['owner_resturant_id'])) rf_fail('OWNER_ACTOR_MISMATCH');
        $fields = ['id','phone','service_consent','service_consented_at','service_consent_evidence','service_consented_by','marketing_consent','marketing_consented_at','marketing_consent_evidence','marketing_consented_by','opted_out_at','updated_at'];
        $marks = implode(',',array_fill(0,count($scope),'?'));
        $visibility = '(EXISTS (SELECT 1 FROM crm_customer_sources s WHERE s.customer_id=c.id AND s.branch IN ('.$marks.')) OR NOT EXISTS (SELECT 1 FROM crm_customer_sources a WHERE a.customer_id=c.id))';
        $rows = rf_rows($pdo,'SELECT '.implode(',',array_map(fn($field)=>'c.'.$field,$fields)).' FROM crm_customers c WHERE '.$visibility.' ORDER BY c.id',$scope);
        $counts = ['visible_total'=>count($rows),'service_enabled_before'=>0,'eligible'=>0,'global_opted_out'=>0,'prior_service_decision'=>0,'invalid_phone'=>0];
        $eligible = [];
        foreach ($rows as $row) {
            rf_bool($row['marketing_consent']); $category = rf_classify($row); $counts[$category]++;
            if ($category==='eligible') $eligible[(int)$row['id']] = $row;
        }
        unset($rows);
        if (!$apply) {
            $pdo->commit();
            return ['status'=>'REMAINING_FOLLOWUP_CHECK_OK','database_writes'=>0,'filesystem_writes'=>0,'max_changes'=>RF_MAX_CHANGES,'within_change_limit'=>count($eligible)<=RF_MAX_CHANGES,'scope_branches'=>$scope,'counts'=>$counts];
        }
        if (count($eligible)>RF_MAX_CHANGES) rf_fail('ELIGIBLE_COUNT_EXCEEDS_500_CHECK_DRY_RUN');
        // Lock only pending candidates, then reread current choices and source scope.
        // A late STOP or service decision is preserved rather than overwritten.
        $before = [];
        foreach (array_chunk(array_keys($eligible),500) as $chunk) {
            $idMarks = implode(',',array_fill(0,count($chunk),'?'));
            $locked = rf_rows($pdo,'SELECT '.implode(',',$fields).' FROM crm_customers WHERE id IN ('.$idMarks.') ORDER BY id'.$lock,$chunk);
            if (count($locked)!==count($chunk)) rf_fail('CUSTOMER_SCOPE_CHANGED_RETRY');
            foreach ($locked as $row) {
                $id = (int)$row['id'];
                $links = rf_rows($pdo,'SELECT branch FROM crm_customer_sources WHERE customer_id=?'.$lock,[$id]);
                if ($links && !array_intersect(array_column($links,'branch'),$scope)) rf_fail('CUSTOMER_SCOPE_CHANGED_RETRY');
                rf_bool($row['marketing_consent']); $category = rf_classify($row);
                if ($category!=='eligible') { $counts['eligible']--; $counts[$category]++; continue; }
                $before[] = $row;
            }
        }
        $changed = 0; $when = gmdate('Y-m-d H:i:s');
        if ($before) {
            if ($backupDirectory===null) rf_fail('PRIVATE_BACKUP_DIRECTORY_REQUIRED');
            $backup = rf_backup($backupDirectory,['directive'=>RF_DIRECTIVE,'actor_id'=>1,'recorded_at_utc'=>$when,'scope_branches'=>$scope,'counts'=>$counts,'customers'=>$before]);
            $update = $pdo->prepare('UPDATE crm_customers SET service_consent=1,service_consented_at=?,service_consent_evidence=?,service_consented_by=1,updated_at=? WHERE id=? AND phone=? AND service_consent=0 AND opted_out_at IS NULL AND service_consented_at IS NULL AND service_consented_by IS NULL AND (service_consent_evidence=? OR (service_consent_evidence IS NULL AND ? IS NULL))');
            foreach ($before as $row) {
                $update->execute([$when,RF_EVIDENCE,$when,(int)$row['id'],$row['phone'],$row['service_consent_evidence'],$row['service_consent_evidence']]);
                if ($update->rowCount()!==1) rf_fail('CONCURRENT_CONSENT_CHANGE_OR_UPDATE_MISMATCH');
                $changed++;
            }
        }
        $pdo->commit();
        return ['status'=>'REMAINING_FOLLOWUP_APPLIED','service_enabled'=>$changed,'service_enabled_after'=>$counts['service_enabled_before']+$changed,'marketing_changed'=>0,'messages_sent'=>0,'backup'=>$backup,'counts'=>$counts];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function rf_main(array $argv): int {
    try {
        umask(0077); $args = [];
        for ($i=1;$i<count($argv);$i++) {
            if (!str_starts_with($argv[$i],'--')) rf_fail('UNEXPECTED_ARGUMENT');
            $key = substr($argv[$i],2);
            if (!in_array($key,['root','backup-dir','apply','dry-run'],true) || array_key_exists($key,$args)) rf_fail('UNKNOWN_OR_DUPLICATED_OPTION');
            if (in_array($key,['apply','dry-run'],true)) $args[$key] = true;
            else { $value = $argv[++$i] ?? ''; if ($value==='' || str_starts_with($value,'--')) rf_fail('MISSING_OPTION_VALUE'); $args[$key] = $value; }
        }
        if (isset($args['apply'],$args['dry-run'])) rf_fail('CHOOSE_APPLY_OR_DRY_RUN');
        $apply = isset($args['apply']); $root = realpath($args['root'] ?? '');
        if (!$root || !is_dir($root) || !is_file($root.'/artisan') || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php')) rf_fail('INVALID_LARAVEL_ROOT');
        if (!function_exists('posix_geteuid') || posix_geteuid()===0 || fileowner($root)!==posix_geteuid()) rf_fail('RUN_AS_APPLICATION_OWNER');
        $backup = $args['backup-dir'] ?? null;
        if ($apply && $backup===null) rf_fail('PRIVATE_BACKUP_DIRECTORY_REQUIRED');
        if ($backup!==null && ($backup===$root || str_starts_with($backup,rtrim($root,'/').'/'))) rf_fail('BACKUP_MUST_BE_OUTSIDE_APPLICATION');
        require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $actor = App\Models\User::withoutGlobalScopes()->find(1);
        if (!$actor || $actor->account_type!=='admin' || !empty($actor->owner_resturant_id)) rf_fail('OWNER_ACTOR_MISMATCH');
        $branches = $app->make(App\Services\Dashboard\BranchOperations::class)->branches('all',$actor);
        if (!is_array($branches)) rf_fail('INVALID_DASHBOARD_BRANCH_SCOPE');
        foreach ($branches as $branch) if (!is_array($branch) || !array_key_exists('value',$branch)) rf_fail('INVALID_DASHBOARD_BRANCH_SCOPE');
        $scope = rf_scope(array_column($branches,'value'));
        $connection = Illuminate\Support\Facades\DB::connection();
        if ($connection->getDriverName()!=='mysql' || $connection->getTablePrefix()!=='') rf_fail('PRODUCTION_REQUIRES_UNPREFIXED_MYSQL');
        echo rf_json(rf_execute($connection->getPdo(),$scope,$apply,$backup))."\n"; return 0;
    } catch (Throwable $error) { echo rf_json(['status'=>'REMAINING_FOLLOWUP_FAILED','error'=>$error instanceof RemainingFollowupException ? $error->getMessage() : 'DATABASE_OR_APPLICATION_FAILURE'])."\n"; return 1; }
}
if (!defined('REMAINING_FOLLOWUP_LIBRARY')) exit(rf_main($argv));
