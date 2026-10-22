<?php
/** One-time repair of existing public image metadata. Original files are retained. */
final class GoErpMediaFileRepair
{
    private const EXTENSIONS = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp',
        'image/gif'=>'gif', 'image/x-icon'=>'ico', 'image/vnd.microsoft.icon'=>'ico'];
    private const MAX_FILE = 16777216;
    private PDO $pdo;
    private string $root;
    private string $table;
    private $safePath;

    public function __construct(PDO $pdo, string $publicRoot, callable $safePath, string $table = 'media')
    {
        self::need((bool)preg_match('/^[a-zA-Z0-9_]+$/D', $table), 'TABLE');
        self::need(realpath($publicRoot) === $publicRoot && is_dir($publicRoot) && !is_link($publicRoot), 'PUBLIC_ROOT');
        $this->pdo=$pdo; $this->root=$publicRoot; $this->safePath=$safePath; $this->table=$table;
    }
    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new RuntimeException('GO_ERP_MEDIA_REPAIR_'.$code);
    }
    private function path(string $root, string $relative, bool $missing = false): ?string
    {
        self::need(($this->safePath)($relative.'.png'), 'PATH');
        self::need(realpath($root) === $root && is_dir($root) && !is_link($root), 'ROOT');
        $parts=explode('/', $relative); $current=$root;
        foreach ($parts as $index=>$part) {
            $current.='/'.$part; clearstatcache(true, $current);
            $info=@lstat($current);
            if ($info===false) { self::need($missing, 'FILE_MISSING'); return null; }
            self::need(!is_link($current), 'LINK');
            self::need($index===count($parts)-1 ? is_file($current) : is_dir($current), 'FILE_TYPE');
        }
        self::need(realpath($current) === $current, 'PATH_CHANGED');
        return $current;
    }
    private static function facts(string $file): array
    {
        clearstatcache(true, $file); $before=lstat($file);
        self::need(is_array($before) && !is_link($file) && is_file($file), 'SOURCE');
        $handle=fopen($file, 'rb'); self::need($handle!==false, 'SOURCE_READ');
        try { $opened=fstat($handle); $bytes=stream_get_contents($handle, self::MAX_FILE+1); $after=fstat($handle); }
        finally { fclose($handle); }
        clearstatcache(true, $file); $current=lstat($file);
        foreach (['dev','ino','size','mtime','ctime'] as $key)
            self::need($before[$key]===$opened[$key] && $opened[$key]===$after[$key] && $after[$key]===$current[$key], 'SOURCE_CHANGED');
        self::need(!is_link($file) && is_string($bytes) && strlen($bytes)>0
            && strlen($bytes)===$before['size'] && strlen($bytes)<=self::MAX_FILE, 'SIZE');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        self::need(isset(self::EXTENSIONS[$mime]), 'CONTENT_TYPE');
        self::need($mime==='image/x-icon' || $mime==='image/vnd.microsoft.icon' || @getimagesizefromstring($bytes)!==false, 'IMAGE_CONTENT');
        return ['sha256'=>hash('sha256', $bytes), 'bytes'=>strlen($bytes), 'mime'=>$mime==='image/vnd.microsoft.icon'?'image/x-icon':$mime];
    }
    public function plan(array $row, string $relative, ?string $recoveryRoot = null): array
    {
        self::need(isset($row['id'],$row['file_name'],$row['disk'],$row['size']) && (int)$row['id']>0, 'ROW');
        self::need(basename($relative)===$row['file_name'] && !str_contains($row['file_name'],'/'), 'ROW_PATH');
        self::need(basename(dirname($relative))===(string)$row['id'], 'ROW_DIRECTORY');
        $extension=strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        self::need(in_array($extension, ['', 'png','jpg','jpeg','webp','gif','ico'], true), 'ORIGINAL_EXTENSION');
        $source=$this->path($this->root, $relative, true);
        if ($source!==null) {
            // Renaming originals with pre-existing conversion metadata needs a
            // separate conversion-aware repair, rather than risking broken URLs.
            foreach (['generated_conversions','responsive_images'] as $field) {
                $raw=$row[$field]??null;
                if ($raw===null || $raw==='') continue;
                $converted=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                self::need(is_array($converted) && $converted===[], 'DERIVED_METADATA_NEEDS_REVIEW');
            }
            $facts=self::facts($source); $wanted=self::EXTENSIONS[$facts['mime']];
            $matches=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
                'webp'=>'image/webp','gif'=>'image/gif','ico'=>'image/x-icon'];
            self::need(($matches[$extension]??null)!==$facts['mime'], 'ALREADY_VALID');
            $name=pathinfo($row['file_name'], PATHINFO_FILENAME).'.'.$wanted;
            $destination=dirname($relative).'/'.$name;
            self::need(($this->safePath)($destination) && $destination!==$relative, 'DESTINATION');
            $after=$row; $after['file_name']=$name; $after['mime_type']=$facts['mime']; $after['size']=$facts['bytes'];
            $kind='normalize-extension'; $sourceRoot=$this->root;
        } else {
            self::need($recoveryRoot!==null, 'MISSING_NO_BACKUP');
            $source=$this->path($recoveryRoot, $relative);
            $facts=self::facts($source);
            $expected=['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
                'webp'=>'image/webp','gif'=>'image/gif','ico'=>'image/x-icon'];
            self::need(($expected[$extension]??null)===$facts['mime']
                && ($row['mime_type']??null)===$facts['mime'] && (int)$row['size']===$facts['bytes'], 'BACKUP_METADATA');
            $destination=$relative; $after=$row; $kind='restore-same-backup-path'; $sourceRoot=$recoveryRoot;
        }
        $existing=$this->path($this->root, $destination, true);
        if ($existing!==null) self::need(self::facts($existing)===$facts, 'DESTINATION_COLLISION');
        return ['kind'=>$kind, 'relative'=>$relative, 'destination'=>$destination,
            'source_root'=>$sourceRoot, 'before'=>$row, 'after'=>$after, 'facts'=>$facts];
    }
    private static function rowIdentity(array $row): string
    {
        ksort($row); foreach ($row as &$value) if ($value!==null) $value=(string)$value; unset($value);
        return json_encode($row, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private function parent(string $relative): void
    {
        self::need(($this->safePath)($relative), 'DESTINATION'); $current=$this->root;
        foreach (array_slice(explode('/', $relative), 0, -1) as $part) {
            $current.='/'.$part;
            if (!file_exists($current) && !is_link($current)) self::need(@mkdir($current, 0755), 'MKDIR');
            self::need(is_dir($current) && !is_link($current) && realpath($current)===$current, 'DIRECTORY');
        }
    }
    private function copy(array $plan): void
    {
        $source=$this->path($plan['source_root'], $plan['relative']);
        self::need(self::facts($source)===$plan['facts'], 'SOURCE_CHANGED');
        $existing=$this->path($this->root, $plan['destination'], true);
        if ($existing!==null) { self::need(self::facts($existing)===$plan['facts'], 'DESTINATION_COLLISION'); return; }
        $this->parent($plan['destination']); $target=$this->root.'/'.$plan['destination'];
        $input=fopen($source, 'rb'); self::need($input!==false, 'COPY_SOURCE');
        $output=@fopen($target, 'xb');
        if ($output===false) { fclose($input); throw new RuntimeException('GO_ERP_MEDIA_REPAIR_COPY_TARGET'); }
        try {
            self::need(stream_copy_to_stream($input,$output,self::MAX_FILE+1)===$plan['facts']['bytes'], 'COPY_LENGTH');
            self::need(fflush($output), 'COPY_FLUSH'); if (function_exists('fsync')) self::need(fsync($output), 'COPY_SYNC');
        } finally { fclose($input); fclose($output); }
        self::need(chmod($target,0644), 'COPY_MODE');
        self::need(self::facts($target)===$plan['facts'] && self::facts($source)===$plan['facts'], 'COPY_HASH');
    }
    private static function durable(string $file, array $value): void
    {
        $raw=json_encode($value, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $handle=fopen($file,'xb'); self::need($handle!==false, 'JOURNAL_CREATE');
        try {
            self::need(fwrite($handle,$raw)===strlen($raw) && fflush($handle), 'JOURNAL_WRITE');
            if (function_exists('fsync')) self::need(fsync($handle), 'JOURNAL_SYNC');
        } finally { fclose($handle); }
        self::need(chmod($file,0600), 'JOURNAL_MODE');
    }
    public function apply(array $plans, string $backupRoot): array
    {
        self::need($plans!==[] && count($plans)<=1000, 'PLAN_COUNT');
        self::need(is_dir($backupRoot) && !is_link($backupRoot) && realpath($backupRoot)===$backupRoot
            && !str_starts_with($backupRoot.'/', $this->root.'/'), 'BACKUP_ROOT');
        $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)); $audit=$backupRoot.'/'.$id;
        self::need(mkdir($audit,0700), 'BACKUP_CREATE');
        self::durable($audit.'/before.json', ['format'=>1,'kind'=>'public-image-metadata-repair',
            'original_files_retained'=>true,'public_root'=>$this->root,'plans'=>$plans]);
        $driver=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME); self::need(in_array($driver,['mysql','sqlite'],true), 'DATABASE');
        $seen=[]; $changed=0; $restored=0;
        try {
            self::need(!$this->pdo->inTransaction() && $this->pdo->beginTransaction(), 'TRANSACTION');
            foreach ($plans as $plan) {
                $mediaId=(int)$plan['before']['id']; self::need(!isset($seen[$mediaId]), 'DUPLICATE_ROW'); $seen[$mediaId]=true;
                $query=$this->pdo->prepare('SELECT * FROM `'.$this->table.'` WHERE id=?'.($driver==='mysql'?' FOR UPDATE':''));
                $query->execute([$mediaId]); $current=$query->fetch(PDO::FETCH_ASSOC);
                self::need(is_array($current) && self::rowIdentity($current)===self::rowIdentity($plan['before']), 'ROW_CHANGED');
                $this->copy($plan);
                if ($plan['kind']==='normalize-extension') {
                    $query=$this->pdo->prepare('UPDATE `'.$this->table.'` SET file_name=?, mime_type=?, size=? WHERE id=?');
                    $query->execute([$plan['after']['file_name'],$plan['after']['mime_type'],$plan['after']['size'],$mediaId]);
                    self::need($query->rowCount()===1, 'ROW_UPDATE'); $changed++;
                } else { self::need($plan['kind']==='restore-same-backup-path', 'PLAN_KIND'); $restored++; }
            }
            self::need($this->pdo->commit(), 'COMMIT');
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            self::durable($audit.'/failed.json',['database_committed'=>false,'original_files_retained'=>true,
                'created_copies_retained'=>true,'error_code'=>$error instanceof RuntimeException && str_starts_with($error->getMessage(),'GO_ERP_')?$error->getMessage():'GO_ERP_MEDIA_REPAIR_DATABASE_FAILED']);
            throw new RuntimeException('GO_ERP_MEDIA_REPAIR_ABORTED_BACKUP: '.$audit,0,$error);
        }
        $result=['normalized'=>$changed,'restored'=>$restored,'backup'=>$audit,'database_committed'=>true];
        try { self::durable($audit.'/committed.json',$result); }
        catch (Throwable $error) { $result['audit_completion_pending']=true; }
        return $result;
    }
}
