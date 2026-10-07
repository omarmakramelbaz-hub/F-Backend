<?php
// Deployment checks and private database backup. Never print credentials or records.
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
$backup = $argv[3] ?? '';
if (PHP_VERSION_ID < 80200 || !is_dir($project) || realpath($project) !== $project) {
    fwrite(STDERR, "PHP 8.2 and an existing canonical application path are required.\n");
    exit(1);
}
require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$desktop = [
    'desktop_pos_devices'=>['id','branch','actor_id','name','pair_hash','pair_expires_at','token_hash','enabled','last_seen_at'],
    'desktop_pos_snapshots'=>['id','device_id','payload'],
    'desktop_pos_orders'=>['device_id','local_id','snapshot_id','branch','channel','status','revision','data','paid_order_id','occurred_at'],
    'desktop_pos_operations'=>['device_id','request_key','request_hash','local_id','branch','kind','revision','result','occurred_at'],
];

try {
    if (!app(App\Services\Dashboard\TakeawayAccess::class)->ready()
        || !app(App\Services\Dashboard\PosServiceTable::class)->ready()) {
        throw new RuntimeException('Existing POS schema is not ready.');
    }
    $connection = Illuminate\Support\Facades\DB::connection();
    if ($connection->getDriverName() !== 'mysql') throw new RuntimeException('A MySQL-compatible production connection is required.');
    if ($mode === 'preflight') {
        $present = array_filter(array_keys($desktop), fn($table)=>Illuminate\Support\Facades\Schema::hasTable($table));
        if (count($present) !== 0 && count($present) !== count($desktop)) {
            throw new RuntimeException('Partial desktop schema found; reconcile it before activation.');
        }
        if ($present && !Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_07_210000_create_desktop_pos')->exists()) {
            throw new RuntimeException('Desktop tables exist without a recorded migration; reconcile before activation.');
        }
        echo "EXISTING POS PREFLIGHT PASSED\n";
    } elseif ($mode === 'backup') {
        if (!is_dir($backup) || realpath($backup) !== $backup || !is_writable($backup)) throw new RuntimeException('Private backup directory is not writable.');
        $binary = null;
        foreach (['/usr/bin/mariadb-dump','/usr/bin/mysqldump','/usr/local/bin/mysqldump'] as $candidate) {
            if (is_executable($candidate)) { $binary = $candidate; break; }
        }
        if (!$binary) throw new RuntimeException('Install mysqldump/mariadb-dump before activation.');
        $config = $connection->getConfig();
        $escape = fn($value)=>'"'.strtr((string)$value, ['\\'=>'\\\\','"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t']).'"';
        $settings = ['user'=>$config['username'] ?? '', 'password'=>$config['password'] ?? ''];
        if (!empty($config['unix_socket'])) $settings['socket']=$config['unix_socket'];
        else { $settings['host']=$config['host'] ?? '127.0.0.1'; $settings['port']=$config['port'] ?? 3306; }
        foreach ($settings as $value) if (!is_scalar($value)) throw new RuntimeException('Unsupported database connection settings.');
        $database = $config['database'] ?? '';
        if (!is_string($database) || !preg_match('/\A[A-Za-z0-9_$-]+\z/D',$database)) throw new RuntimeException('Unsupported database name.');
        $old = umask(0077);
        $defaults = tempnam($backup,'dump-client-');
        if (!$defaults) throw new RuntimeException('Cannot create private dump configuration.');
        try {
            $ini = "[client]\n";
            foreach ($settings as $key=>$value) $ini .= $key.'='.$escape($value)."\n";
            if (file_put_contents($defaults,$ini) === false) throw new RuntimeException('Cannot write dump configuration.');
            $dump = $backup.'/database.sql';
            $process = proc_open([$binary,'--defaults-extra-file='.$defaults,'--single-transaction','--skip-lock-tables','--quick','--hex-blob','--default-character-set=utf8mb4','--databases',$database],
                [0=>['file','/dev/null','r'],1=>['file',$dump,'w'],2=>['file',$backup.'/database-dump.log','w']],$pipes);
            if (!is_resource($process) || proc_close($process) !== 0 || !is_file($dump) || filesize($dump) === 0) {
                throw new RuntimeException('Database backup failed; inspect the private backup log before retrying.');
            }
        } finally { unlink($defaults); umask($old); }
        echo "PRIVATE DATABASE BACKUP READY\n";
    } elseif ($mode === 'verify') {
        if (!config('desktop_pos.enabled')) throw new RuntimeException('Desktop POS is not enabled.');
        foreach ($desktop as $table=>$columns) {
            if (!Illuminate\Support\Facades\Schema::hasColumns($table,$columns)) throw new RuntimeException('Desktop schema verification failed: '.$table);
        }
        foreach (['desktop_pos_orders'=>['device_id','local_id'],'desktop_pos_operations'=>['device_id','request_key']] as $table=>$columns) {
            $indexes=[];
            foreach ($connection->select('SHOW INDEX FROM `'.$table.'`') as $index) {
                if ((int)$index->Non_unique===0) $indexes[$index->Key_name][(int)$index->Seq_in_index]=$index->Column_name;
            }
            $valid=false;
            foreach ($indexes as $index) { ksort($index); if (array_values($index)===$columns) $valid=true; }
            if (!$valid) throw new RuntimeException('Desktop idempotency index verification failed.');
        }
        foreach (['desktop-pos.index','desktop-pos.issue','desktop-pos.revoke','desktop-pos.download'] as $name) {
            if (!Illuminate\Support\Facades\Route::has($name)) throw new RuntimeException('Desktop dashboard route is missing.');
        }
        $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
        $request=Illuminate\Http\Request::create(rtrim(config('app.url'),'/').'/api/desktop-pos/health','GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
        $response=$kernel->handle($request);
        $kernel->terminate($request,$response);
        if ($response->getStatusCode()!==401) throw new RuntimeException('Unauthenticated desktop health must return HTTP 401.');
        echo "DESKTOP POS SCHEMA AND AUTHENTICATION VERIFIED\n";
        echo 'Dashboard page: '.route('desktop-pos.index')."\n";
        echo is_file(config('desktop_pos.installer')) ? "WINDOWS INSTALLER AVAILABLE\n" : "Pairing is ready. Upload Fasakhansta-POS-Setup.exe to storage/app/desktop-pos/ to enable dashboard downloads.\n";
    } else throw new RuntimeException('Unknown release check.');
} catch (Throwable $error) {
    // Known validation messages have no credentials; framework/database exceptions are suppressed.
    fwrite(STDERR, $error instanceof RuntimeException && get_class($error)===RuntimeException ? $error->getMessage()."\n" : "Desktop release check failed. Inspect the application log privately.\n");
    exit(1);
}
