<?php
/** Read-only post-backup metadata. No migration, compilation or cache commands. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();
$phase = 'ARGUMENTS';
$db = null;
$transaction = false;
$result = null;
$available = false;
try {
    $root = '/home/fasakha/public_html';
    $private = $argv[2] ?? '';
    $manifestPath = $argv[3] ?? '';
    if (PHP_VERSION_ID < 80200 || count($argv) !== 4 || ($argv[1] ?? '') !== $root
        || realpath($root) !== $root || realpath($private) !== $private
        || !is_dir($private) || !is_writable($private) || $private === $root
        || str_starts_with($private, $root.'/') || is_link($private)
        || realpath($manifestPath) !== $manifestPath || !is_file($manifestPath)
        || is_link($manifestPath) || $manifestPath === $root || str_starts_with($manifestPath, $root.'/')) {
        throw new RuntimeException();
    }
    $privateMetadata = lstat($private);
    $manifestMetadata = lstat($manifestPath);
    if (!$privateMetadata || !$manifestMetadata || ($privateMetadata['mode'] & 07777) !== 0700
        || ($manifestMetadata['mode'] & 0022) !== 0 || $manifestMetadata['nlink'] !== 1
        || $privateMetadata['uid'] !== 1001 || !in_array($manifestMetadata['uid'], [0, 1001], true)
        || $manifestMetadata['size'] < 1 || $manifestMetadata['size'] > 2097152) throw new RuntimeException();
    if (function_exists('posix_geteuid')) {
        $actor = posix_geteuid();
        if ($actor !== 1001) throw new RuntimeException();
    }
    $phase = 'MANIFEST';
    $manifestBytes = file_get_contents($manifestPath);
    if ($manifestBytes === false || strlen($manifestBytes) !== $manifestMetadata['size']
        || hash('sha1', 'blob '.strlen($manifestBytes)."\0".$manifestBytes)
            !== 'da47b5e5dc50676d6046a6511b3905a0cd053ed1') throw new RuntimeException();
    $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_array($manifest['sources'] ?? null)
        || count($manifest['sources']) !== 266) throw new RuntimeException();
    $views = [];
    foreach ($manifest['sources'] as $row) {
        $name = $row['path'] ?? null;
        if (is_string($name) && str_starts_with($name, 'resources/views/') && str_ends_with($name, '.blade.php')) {
            if (!preg_match('#\Aresources/views/[A-Za-z0-9_.@/-]+\.blade\.php\z/D', $name)
                || in_array('..', explode('/', $name), true) || isset($views[$name])) throw new RuntimeException();
            $views[$name] = true;
        }
    }
    foreach (['APP_SERVICES_CACHE'=>'probe-services.php', 'APP_PACKAGES_CACHE'=>'probe-packages.php'] as $key=>$name) {
        $path = $private.'/'.$name;
        if (file_exists($path) || is_link($path)) throw new RuntimeException();
        putenv($key.'='.$path); $_ENV[$key] = $path; $_SERVER[$key] = $path;
    }
    $phase = 'BOOTSTRAP';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->version() !== '8.83.29') throw new RuntimeException();
    $db = Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql' || $db->transactionLevel() !== 0) throw new RuntimeException();
    $phase = 'READ_ONLY_TRANSACTION';
    $db->statement('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $transaction = true;
    $mapping = [
        '2026_10_08_130000_create_desktop_dashboard_journal'=>['desktop_dashboard_commands','desktop_dashboard_entities','desktop_dashboard_devices'],
        '2026_10_08_160000_create_desktop_dashboard_local_state'=>['desktop_dashboard_local_state','desktop_dashboard_refreshes'],
        '2026_10_08_210000_create_desktop_dashboard_archived_commands'=>['desktop_dashboard_archived_commands'],
        '2026_10_08_210000_create_desktop_dashboard_remote_attempts'=>['desktop_dashboard_remote_attempts'],
        '2026_10_08_220000_create_desktop_dashboard_media_manifest'=>['desktop_dashboard_media_manifest'],
        '2026_10_08_230000_create_desktop_dashboard_source_manifest'=>['desktop_dashboard_source_manifest'],
        '2026_10_20_000001_create_desktop_dashboard_print_ownership'=>['desktop_dashboard_print_ownership'],
        '2026_10_20_000002_create_desktop_dashboard_created_scopes'=>['desktop_dashboard_created_scopes'],
    ];
    $phase = 'MIGRATION_METADATA';
    $schema = Illuminate\Support\Facades\Schema::class;
    if (!$schema::hasTable('migrations')) throw new RuntimeException();
    $migrations = [];
    foreach ($mapping as $migration=>$tables) {
        $members = [];
        foreach ($tables as $table) $members[] = ['name'=>$table, 'exists'=>(bool)$schema::hasTable($table)];
        $migrations[] = ['migration'=>$migration,
            'records'=>(int)$db->table('migrations')->where('migration', $migration)->count(), 'tables'=>$members];
    }
    $phase = 'COMPILED_VIEW_METADATA';
    $compiler = $app->make('blade.compiler');
    $compiled = [];
    foreach (array_keys($views) as $source) {
        $target = $compiler->getCompiledPath($root.'/'.$source);
        if (!preg_match('#\A'.preg_quote($root, '#').'/storage/framework/views/[a-f0-9]{40}\.php\z/D', $target)) {
            throw new RuntimeException();
        }
        $metadata = null;
        if (file_exists($target) || is_link($target)) {
            $item = lstat($target);
            if (!$item) throw new RuntimeException();
            $metadata = ['uid'=>(int)$item['uid'], 'gid'=>(int)$item['gid'],
                'mode'=>sprintf('%04o', $item['mode'] & 07777), 'links'=>(int)$item['nlink']];
        }
        $compiled[] = ['source'=>$source, 'target'=>substr($target, strlen($root)+1), 'metadata'=>$metadata];
    }
    $result = ['kind'=>'go-erp-postbackup-read-only-probe', 'available'=>true,
        'framework'=>'8.83.29', 'driver'=>'mysql', 'migrations'=>$migrations, 'compiled_views'=>$compiled];
    $available = true;
} catch (Throwable $error) {
    $class = get_class($error);
    $allowed = ['RuntimeException','Error','TypeError','ValueError','JsonException',
        'PDOException','Illuminate\\Database\\QueryException','Illuminate\\Contracts\\Container\\BindingResolutionException'];
    $result = ['kind'=>'go-erp-postbackup-read-only-probe', 'available'=>false,
        'phase'=>$phase, 'error_class'=>in_array($class, $allowed, true) ? $class : 'Throwable'];
} finally {
    if ($transaction) {
        try { $db->rollBack(); }
        catch (Throwable $ignored) {
            $available = false;
            $result = ['kind'=>'go-erp-postbackup-read-only-probe', 'available'=>false,
                'phase'=>'READ_ONLY_ROLLBACK', 'error_class'=>'Throwable'];
        }
    }
}
while (ob_get_level() > 0) ob_end_clean();
echo json_encode($result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
exit($available ? 0 : 1);
