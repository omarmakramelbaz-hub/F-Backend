<?php
/** Three exact source edits only. No application bootstrap, database, or cache operations. */
final class FasakhanstaTahiniPurchaseServicePatch20261021
{
    public const RELEASE = 'tahini-purchase-service-20261021';
    private const RELATIVE = '/app/Services/Dashboard/ExpenseStockPurchases.php';
    private string $root;
    private string $source;
    private string $stage;
    private string $metadata;

    public function __construct(string $root, string $stage)
    {
        $this->root = $this->directory($root);
        $this->source = $this->root.self::RELATIVE;
        if (!is_file($this->source) || is_link($this->source) || realpath($this->source) !== $this->source) throw new RuntimeException('The live purchase service must be a regular canonical file.');
        $parent = $this->directory(dirname($stage));
        $name = basename($stage);
        if ($stage !== $parent.'/'.$name || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.php$/D', $name)
            || $this->inside($stage, $this->root)) throw new RuntimeException('The candidate must be a dedicated PHP file outside the application.');
        $parentStat = stat($parent); $rootStat = stat($this->root);
        if (($parentStat['mode'] & 0077) !== 0 || (int) $parentStat['uid'] !== (int) $rootStat['uid']) throw new RuntimeException('The candidate directory must be private and owned by the application owner.');
        $this->stage = $stage; $this->metadata = $stage.'.meta.json';
    }

    private function inside(string $path, string $directory): bool { return $path === $directory || str_starts_with($path, $directory.'/'); }

    private function directory(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\')) throw new RuntimeException('An absolute canonical directory is required.');
        $path = rtrim($path, '/'); $actual = realpath($path);
        if ($path === '' || !$actual || !is_dir($actual) || is_link($path) || $actual !== $path) throw new RuntimeException('A directory is missing, noncanonical, or contains symlinks.');
        return $actual;
    }

    private function snapshot(): array
    {
        clearstatcache(true, $this->source);
        if (!is_file($this->source) || is_link($this->source) || realpath($this->source) !== $this->source) throw new RuntimeException('The live service path changed.');
        $bytes = file_get_contents($this->source); $stat = stat($this->source);
        if ($bytes === false || !$stat) throw new RuntimeException('Cannot read the live purchase service.');
        return ['bytes'=>$bytes, 'sha256'=>hash('sha256', $bytes), 'stat'=>['uid'=>(int) $stat['uid'], 'gid'=>(int) $stat['gid'], 'mode'=>$stat['mode'] & 0777,
            'mtime'=>(int) $stat['mtime'], 'size'=>(int) $stat['size']], 'inode'=>(int) $stat['ino'], 'device'=>(int) $stat['dev']];
    }

    /** Parse PHP without loading the service, then require the expected class and methods. */
    private function structure(string $bytes): void
    {
        if (!function_exists('token_get_all')) throw new RuntimeException('PHP tokenizer is required to validate the staged service safely.');
        try { $tokens = token_get_all($bytes, TOKEN_PARSE); } catch (ParseError $error) { throw new RuntimeException('The purchase service is not valid PHP.'); }
        $namespaces = []; $classes = []; $functions = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i]; if (!is_array($token)) continue;
            if ($token[0] === T_NAMESPACE) {
                $name = '';
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) if (is_array($tokens[$j]) && !in_array($tokens[$j][0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) $name .= $tokens[$j][1];
                $namespaces[] = $name;
            }
            if ($token[0] === T_CLASS || $token[0] === T_FUNCTION) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) continue;
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { if ($token[0] === T_CLASS) $classes[] = $tokens[$j][1]; else $functions[] = $tokens[$j][1]; }
                    break;
                }
            }
        }
        if ($namespaces !== ['App\\Services\\Dashboard'] || $classes !== ['ExpenseStockPurchases']) throw new RuntimeException('Unexpected purchase service namespace or class.');
        foreach (['categoryProduct','normalizeQuantity','receive'] as $method) if (count(array_keys($functions, $method, true)) !== 1) throw new RuntimeException('An expected purchase service method is missing or duplicated.');
        if (substr_count($bytes, 'public const CATEGORIES = [') !== 1) throw new RuntimeException('The expected purchase category constant is missing or duplicated.');
    }

    /** Preserve all other bytes, including unrelated changes already present on the server. */
    private function candidate(string $bytes): array
    {
        $this->structure($bytes);
        $newline = str_contains($bytes, "\r\n") ? "\r\n" : "\n";
        $category = "        'purchase_vinegar' => 'خل',".$newline;
        $categoryAdded = "        'purchase_tahini' => 'طحينة',".$newline;
        $aliases = "            'purchase_vinegar'=>['خل', 'الخل', 'vinegar'],".$newline;
        $aliasesAdded = "            'purchase_tahini'=>['طحينه', 'الطحينه', 'tahini'],".$newline;
        $message = 'عدد الكيلوات متاح لبنود مشتريات الفلفل والطماطم والليمون والبصل والزيت والخل فقط.';
        $messageAdded = 'عدد الكيلوات متاح لبنود مشتريات الفلفل والطماطم والليمون والبصل والزيت والخل والطحينة فقط.';
        if (substr_count($bytes, $category) !== 1 || substr_count($bytes, $aliases) !== 1) throw new RuntimeException('Expected purchase category/alias anchors are missing or duplicated.');
        $already = str_contains($bytes, 'purchase_tahini');
        if ($already) {
            if (substr_count($bytes, 'purchase_tahini') !== 2 || substr_count($bytes, $category.$categoryAdded) !== 1
                || substr_count($bytes, $aliases.$aliasesAdded) !== 1 || substr_count($bytes, $messageAdded) !== 1 || str_contains($bytes, $message)) throw new RuntimeException('An existing tahini purchase definition conflicts with this exact patch.');
            return ['bytes'=>$bytes, 'already'=>true];
        }
        if (substr_count($bytes, $message) !== 1 || str_contains($bytes, $messageAdded)) throw new RuntimeException('Expected quantity message anchor is missing, duplicated, or conflicting.');
        $candidate = str_replace([$category,$aliases,$message], [$category.$categoryAdded,$aliases.$aliasesAdded,$messageAdded], $bytes);
        $this->structure($candidate);
        return ['bytes'=>$candidate, 'already'=>false];
    }

    private function exclusiveFile(string $path, string $bytes, ?array $owner = null): void
    {
        if (is_link($path) || file_exists($path)) throw new RuntimeException('A staging or backup file already exists.');
        $handle = fopen($path, 'x+b'); if (!$handle) throw new RuntimeException('Cannot create a private staging or backup file.');
        $success = false;
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('Cannot protect a private file.');
            if ($owner !== null && (!chown($path, $owner['uid']) || !chgrp($path, $owner['gid']))) throw new RuntimeException('Cannot assign the staged candidate to the application owner.');
            $written = 0; $length = strlen($bytes);
            while ($written < $length) { $part = fwrite($handle, substr($bytes, $written)); if ($part === false || $part === 0) throw new RuntimeException('Cannot complete a private file.'); $written += $part; }
            if (!fflush($handle)) throw new RuntimeException('Cannot flush a private file.');
            $success = true;
        } finally { fclose($handle); if (!$success) @unlink($path); }
    }

    private function readPrepared(): array
    {
        foreach ([$this->stage,$this->metadata] as $path) if (!is_file($path) || is_link($path) || (fileperms($path) & 0077)) throw new RuntimeException('Prepared files must be regular private files.');
        $metadata = json_decode(file_get_contents($this->metadata), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || ($metadata['format'] ?? null) !== 1 || ($metadata['release'] ?? '') !== self::RELEASE
            || ($metadata['root'] ?? '') !== $this->root || ($metadata['source_path'] ?? '') !== $this->source || ($metadata['stage_path'] ?? '') !== $this->stage
            || !preg_match('/^[0-9a-f]{64}$/D', $metadata['source_sha256'] ?? '') || !preg_match('/^[0-9a-f]{64}$/D', $metadata['candidate_sha256'] ?? '')
            || !is_array($metadata['source_stat'] ?? null)) throw new RuntimeException('The staged candidate provenance is invalid.');
        $bytes = file_get_contents($this->stage);
        if ($bytes === false || hash('sha256', $bytes) !== $metadata['candidate_sha256']) throw new RuntimeException('The staged candidate changed after preparation.');
        return ['metadata'=>$metadata, 'bytes'=>$bytes];
    }

    private function validatePrepared(array $prepared, array $source): bool
    {
        $metadata = $prepared['metadata'];
        foreach (['uid','gid','mode'] as $key) if (($metadata['source_stat'][$key] ?? null) !== $source['stat'][$key]) throw new RuntimeException('The live service ownership or mode changed after preparation.');
        $already = $source['sha256'] === $metadata['candidate_sha256'];
        if (!$already && $source['sha256'] !== $metadata['source_sha256']) throw new RuntimeException('The live service changed after preparation; no service file was replaced.');
        if (!$already && $metadata['source_stat'] !== $source['stat']) throw new RuntimeException('The live service stat changed after preparation.');
        $expected = $this->candidate($source['bytes']);
        // The stage directory belongs to the application owner: never trust its metadata
        // alone to authorize root publication of bytes outside this three-site patch.
        if ($expected['bytes'] !== $prepared['bytes'] || ($already && !$expected['already'])) throw new RuntimeException('The candidate is not the exact reviewed live-source patch.');
        return $already;
    }

    private function result(string $status, array $metadata, bool $already, bool $changed = false): array
    {
        return ['status'=>$status,'source_path'=>$this->source,'stage_path'=>$this->stage,'meta_path'=>$this->metadata,
            'source_sha256'=>$metadata['source_sha256'],'candidate_sha256'=>$metadata['candidate_sha256'],'already_present'=>$already,'changed'=>$changed];
    }

    public function prepare(): array
    {
        $source = $this->snapshot(); $candidate = $this->candidate($source['bytes']);
        if (file_exists($this->stage) || is_link($this->stage) || file_exists($this->metadata) || is_link($this->metadata)) {
            $prepared = $this->readPrepared(); $already = $this->validatePrepared($prepared, $source);
            return $this->result('TAHINI_PURCHASE_SERVICE_PREPARED', $prepared['metadata'], $already);
        }
        $metadata = ['format'=>1,'release'=>self::RELEASE,'root'=>$this->root,'source_path'=>$this->source,'stage_path'=>$this->stage,
            'source_sha256'=>$source['sha256'],'candidate_sha256'=>hash('sha256', $candidate['bytes']),'source_stat'=>$source['stat'],
            'already_present'=>$candidate['already'],'prepared_at'=>gmdate('c')];
        $owner = stat(dirname($this->stage));
        $this->exclusiveFile($this->stage, $candidate['bytes'], ['uid'=>$owner['uid'],'gid'=>$owner['gid']]);
        try { $this->exclusiveFile($this->metadata, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"); }
        catch (Throwable $error) { @unlink($this->stage); throw $error; }
        return $this->result('TAHINI_PURCHASE_SERVICE_PREPARED', $metadata, $candidate['already']);
    }

    public function apply(string $backup): array
    {
        $backup = $this->directory($backup);
        if ($this->inside($backup, $this->root) || $this->inside($backup, dirname($this->stage)) || (fileperms($backup) & 0077)) throw new RuntimeException('The backup directory must be private and outside the application and candidate directory.');
        $prepared = $this->readPrepared();
        $handle = fopen($this->source, 'rb');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { if (is_resource($handle)) fclose($handle); throw new RuntimeException('Another purchase-service publication is active.'); }
        $temporary = null;
        try {
            $source = $this->snapshot(); $lockedStat = fstat($handle);
            if (!$lockedStat || (int) $lockedStat['ino'] !== $source['inode'] || (int) $lockedStat['dev'] !== $source['device']) throw new RuntimeException('The live service changed while taking its publication lock.');
            $already = $this->validatePrepared($prepared, $source);
            if ($already) return $this->result('TAHINI_PURCHASE_SERVICE_PUBLISHED', $prepared['metadata'], true);
            $copy = $backup.'/ExpenseStockPurchases.php.before'; $copyMeta = $copy.'.meta.json';
            $backupMetadata = ['format'=>1,'release'=>self::RELEASE,'source_path'=>$this->source,'source_sha256'=>$source['sha256'],
                'candidate_sha256'=>$prepared['metadata']['candidate_sha256'],'source_stat'=>$source['stat'],'recorded_at'=>gmdate('c')];
            if (file_exists($copy) || is_link($copy) || file_exists($copyMeta) || is_link($copyMeta)) {
                if (!is_file($copy) || is_link($copy) || !is_file($copyMeta) || is_link($copyMeta)
                    || hash_file('sha256', $copy) !== $source['sha256']) throw new RuntimeException('An existing service backup conflicts with the live original.');
                $old = json_decode(file_get_contents($copyMeta), true, 512, JSON_THROW_ON_ERROR);
                foreach (['format','release','source_path','source_sha256','candidate_sha256','source_stat'] as $key) if (($old[$key] ?? null) !== $backupMetadata[$key]) throw new RuntimeException('Existing service backup provenance conflicts.');
            } else {
                $this->exclusiveFile($copy, $source['bytes']);
                try { $this->exclusiveFile($copyMeta, json_encode($backupMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"); }
                catch (Throwable $error) { @unlink($copy); throw $error; }
            }
            $temporary = tempnam(dirname($this->source), '.tahini-purchase-service-');
            if ($temporary === false || file_put_contents($temporary, $prepared['bytes'], LOCK_EX) !== strlen($prepared['bytes'])
                || !chown($temporary, $source['stat']['uid']) || !chgrp($temporary, $source['stat']['gid']) || !chmod($temporary, $source['stat']['mode'])
                || hash_file('sha256', $temporary) !== $prepared['metadata']['candidate_sha256']) throw new RuntimeException('Cannot stage the purchase-service publication.');
            $latest = $this->snapshot();
            if ($latest['sha256'] !== $source['sha256'] || $latest['stat'] !== $source['stat'] || $latest['inode'] !== $source['inode']) throw new RuntimeException('The live service changed during publication; no replacement was made.');
            if (!rename($temporary, $this->source)) throw new RuntimeException('Cannot atomically publish the purchase-service patch.');
            $temporary = null;
            return $this->result('TAHINI_PURCHASE_SERVICE_PUBLISHED', $prepared['metadata'], false, true)+['backup_path'=>$copy,'backup_meta_path'=>$copyMeta];
        } finally { if (is_string($temporary) && is_file($temporary)) @unlink($temporary); flock($handle, LOCK_UN); fclose($handle); }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        umask(0077);
        $options = getopt('', ['prepare','apply','root:','stage:','backup:']);
        if (isset($options['prepare']) === isset($options['apply'])) throw new RuntimeException('Choose exactly one of --prepare or --apply.');
        $helper = new FasakhanstaTahiniPurchaseServicePatch20261021($options['root'] ?? '', $options['stage'] ?? '');
        $result = isset($options['prepare']) ? $helper->prepare() : $helper->apply($options['backup'] ?? '');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) { fwrite(STDERR, 'TAHINI_PURCHASE_SERVICE_STOPPED: '.$error->getMessage()."\n"); exit(1); }
}
