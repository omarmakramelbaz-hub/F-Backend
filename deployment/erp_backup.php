<?php
/** Operator-console-only backup. No deployment, migrations or business-data writes. */
namespace Fasakhansta\ErpRelease;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class BackupFailure extends \RuntimeException {}

function requireBackup($condition, string $message): void
{
    if (!$condition) { throw new BackupFailure($message); }
}

function runBackupCommand(array $args, ?string $cwd = null, int $timeout = 1200): string
{
    $process = new Process($args, $cwd);
    $process->setTimeout($timeout);
    $process->run();
    requireBackup($process->isSuccessful(), 'A backup command failed; sensitive command output was withheld. No deployment was started.');
    return trim($process->getOutput());
}

function createBackup(string $root): array
{
    requireBackup(DB::connection()->getDriverName() === 'mysql', 'Expected the existing MySQL application database.');
    $config = DB::connection()->getConfig();
    requireBackup(empty($config['read']) && empty($config['write']), 'Split database connections require a reviewed backup procedure.');
    requireBackup(empty($config['options']), 'Custom database connection options require a reviewed backup procedure.');
    $database = (string) $config['database'];
    $tables = DB::select('SELECT TABLE_NAME AS name, ENGINE AS engine, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database]);
    requireBackup(count($tables) > 0, 'The database contains no tables.');
    $dbBytes = 0;
    foreach ($tables as $table) {
        requireBackup($table->engine === null || strtoupper($table->engine) === 'INNODB', 'Non-transactional tables need a separately scheduled consistent backup.');
        $dbBytes += (int)$table->data_bytes + (int)$table->index_bytes;
    }
    $finder = new ExecutableFinder;
    $dump = $finder->find('mysqldump') ?: $finder->find('mariadb-dump');
    requireBackup($dump !== null, 'Install the MySQL/MariaDB dump client before continuing.');
    foreach (['git','tar','gzip','du'] as $tool) { requireBackup($finder->find($tool) !== null, 'Missing required command: '.$tool); }
    requireBackup(realpath(runBackupCommand(['git','rev-parse','--show-toplevel'], $root)) === $root, 'Run from the real application repository.');
    $head = runBackupCommand(['git','rev-parse','HEAD'], $root);
    $dirty = runBackupCommand(['git','status','--porcelain','--untracked-files=no'], $root) !== '';
    // Do not silently omit an external uploads/vendor/storage target.
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        if ($file->isLink()) {
            $target = realpath($file->getPathname());
            requireBackup($target !== false && ($target === $root || str_starts_with($target, $root.'/')), 'External or broken symbolic links need to be included in a reviewed backup first.');
        }
    }
    $parent = dirname($root).'/erp-release-backups';
    requireBackup(!is_link($parent), 'Backup parent cannot be a symbolic link.');
    $size = (int)strtok(runBackupCommand(['du','-sk',$root]), "\t ") * 1024;
    $free = disk_free_space(dirname($root));
    requireBackup($free !== false && $free > 2*($size+$dbBytes)+536870912, 'Insufficient free space for a checked application and database backup.');
    $oldMask = umask(0077);
    $directory = $parent.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    try {
        if (!is_dir($parent)) { requireBackup(mkdir($parent,0700), 'Cannot create private backup directory.'); }
        requireBackup(chmod($parent,0700), 'Cannot secure backup parent.');
        requireBackup(mkdir($directory,0700), 'Cannot create this backup directory.');
        $ini = $directory.'/mysql-client.cnf';
        $quote = static fn($value) => '"'.str_replace(['\\','"',"\n","\r"],['\\\\','\\"','\\n','\\r'],(string)$value).'"';
        $client = "[client]\nuser=".$quote($config['username'])."\npassword=".$quote($config['password']??'')."\nhost=".$quote($config['host']??'127.0.0.1')."\nport=".(int)($config['port']??3306)."\n";
        if (!empty($config['unix_socket'])) { $client .= 'socket='.$quote($config['unix_socket'])."\n"; }
        requireBackup(file_put_contents($ini,$client)!==false, 'Cannot write private database-client configuration.');
        $archive = $directory.'/database.sql.gz';
        $stream = gzopen($archive,'wb6');
        requireBackup($stream !== false, 'Cannot create database archive.');
        try {
            $args = [$dump,'--defaults-extra-file='.$ini,'--single-transaction','--quick','--no-tablespaces','--skip-lock-tables','--routines','--events','--triggers','--hex-blob'];
            $help = runBackupCommand([$dump,'--no-defaults','--help'],null,30);
            if (str_contains($help,'set-gtid-purged')) { $args[]='--set-gtid-purged=OFF'; }
            $args[]=$database;
            $process = new Process($args); $process->setTimeout(1200);
            $process->run(static function ($type,$bytes) use ($stream) {
                if ($type === Process::OUT) { requireBackup(gzwrite($stream,$bytes)===strlen($bytes), 'Database archive write failed.'); }
            });
            requireBackup($process->isSuccessful(), 'Database dump failed; no complete backup or deployment was recorded.');
        } finally { gzclose($stream); @unlink($ini); }
        requireBackup(filesize($archive)>100, 'Database archive is empty.');
        runBackupCommand(['gzip','-t',$archive]);
        // Include code, local edits, dependencies, .env, Git metadata and uploads.
        // Only regenerable caches/sessions and changing logs are excluded.
        $application = $directory.'/application.tar.gz';
        runBackupCommand(['tar','-czf',$application,'--exclude=./storage/logs','--exclude=./storage/debugbar','--exclude=./storage/framework/cache','--exclude=./storage/framework/views','--exclude=./storage/framework/sessions','-C',$root,'.']);
        runBackupCommand(['gzip','-t',$application]);
        $listing = runBackupCommand(['tar','-tzf',$application]);
        foreach (['./artisan','./.env','./vendor/autoload.php'] as $entry) {
            requireBackup(in_array($entry,explode("\n",$listing),true), 'Application archive is missing a required file.');
        }
        requireBackup(runBackupCommand(['git','rev-parse','HEAD'],$root)===$head, 'The deployed commit changed during the backup; stopped.');
        $receipt = ['application_root'=>$root,'previous_commit'=>$head,'tracked_local_edits'=>$dirty,
            'created_at'=>gmdate(DATE_ATOM),'database_tables'=>count($tables),
            'database_sha256'=>hash_file('sha256',$archive),'application_sha256'=>hash_file('sha256',$application),
            'database_bytes'=>filesize($archive),'application_bytes'=>filesize($application),
            'verification'=>'dump exit status, gzip integrity, required archive entries and SHA-256; restore rehearsal not performed',
            'excluded'=>'regenerable Laravel cache, compiled views, sessions, logs and debugbar; external VPS/OS configuration is not included'];
        requireBackup(file_put_contents($directory.'/manifest.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))!==false,'Cannot write backup manifest.');
        requireBackup(file_put_contents($directory.'/SHA256SUMS',$receipt['database_sha256']."  database.sql.gz\n".$receipt['application_sha256']."  application.tar.gz\n")!==false,'Cannot write backup checksums.');
        requireBackup(file_put_contents($directory.'/backup.ok',"ERP_BACKUP_VERIFIED\n")!==false,'Cannot record backup completion.');
        return $receipt+['backup_directory'=>$directory];
    } finally { if (isset($ini) && is_file($ini)) { unlink($ini); } umask($oldMask); }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    try {
        umask(0022); // Shared Laravel manifests remain readable by the web worker.
        $root = realpath($argv[1]??getcwd());
        requireBackup($root!==false && is_file($root.'/artisan') && is_file($root.'/.env') && is_file($root.'/vendor/autoload.php'), 'Application directory is invalid.');
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $result = createBackup($root);
        echo "ERP_BACKUP_VERIFIED\n".json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $owners = DB::table('users')->whereIn('account_type',['admin','super_admin'])->get(['id','name','account_type']);
        echo 'ERP_OWNER_CANDIDATES='.json_encode($owners,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        echo "NO_DEPLOYMENT_PERFORMED\n";
    } catch (\Throwable $e) {
        fwrite(STDERR,'ERP backup stopped: '.($e instanceof BackupFailure ? $e->getMessage() : get_class($e).' (sensitive details withheld)')."\n");
        exit(1);
    }
}
