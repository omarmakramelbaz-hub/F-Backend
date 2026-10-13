<?php
/** Read only schema, known migration records, and activation booleans. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');
set_error_handler(function () {
    throw new RuntimeException('Desktop metadata inspection failed.');
});

try {
    if (count($argv) !== 2 || PHP_VERSION_ID < 80200) {
        throw new RuntimeException('Invalid metadata inspection invocation.');
    }
    $project = realpath($argv[1]);
    if ($project === false || $project !== $argv[1] || !is_dir($project)
        || !is_file($project.'/vendor/autoload.php') || !is_file($project.'/bootstrap/app.php')) {
        throw new RuntimeException('Application dependencies are unavailable.');
    }
    $directory = realpath(__DIR__);
    if ($directory === false || $directory !== __DIR__ || $directory === $project
        || str_starts_with($directory, $project.'/') || !is_writable($directory)) {
        throw new RuntimeException('Use the private external inspection directory.');
    }
    // Laravel may rebuild these manifests during bootstrap. Keep those writes in
    // the launcher's private temporary directory, which its EXIT trap removes.
    // Preserve the production config/routes cache paths for effective-state reads.
    foreach ([
        'APP_SERVICES_CACHE' => $directory.'/inspection-services.php',
        'APP_PACKAGES_CACHE' => $directory.'/inspection-packages.php',
    ] as $key => $value) {
        if (file_exists($value) || is_link($value) || !putenv($key.'='.$value)) {
            throw new RuntimeException('Private inspection cache is unavailable.');
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    $inspector = __DIR__.'/desktop_dashboard_inspect.php';
    if (!is_file($inspector) || is_link($inspector) || realpath($inspector) !== $inspector) {
        throw new RuntimeException('Pinned schema inspector is unavailable.');
    }
    // Its CLI entrypoint does not run when required by this wrapper.
    require $inspector;
    require $project.'/vendor/autoload.php';
    $app = require $project.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $report = FasakhanstaDesktopSchemaInspection::report($app);

    $known = [
        '2026_10_07_210000_create_desktop_pos',
        '2026_10_08_130000_create_desktop_dashboard_journal',
        '2026_10_08_160000_create_desktop_dashboard_local_state',
        '2026_10_08_210000_create_desktop_dashboard_archived_commands',
        '2026_10_08_210000_create_desktop_dashboard_remote_attempts',
        '2026_10_08_220000_create_desktop_dashboard_media_manifest',
        '2026_10_08_230000_create_desktop_dashboard_source_manifest',
    ];
    $exists = Illuminate\Support\Facades\Schema::hasTable('migrations');
    $records = [];
    if ($exists) {
        if (!Illuminate\Support\Facades\Schema::hasColumns('migrations', ['migration', 'batch'])) {
            throw new RuntimeException('Migration metadata is unavailable.');
        }
        $rows = Illuminate\Support\Facades\DB::table('migrations')->whereIn('migration', $known)
            ->orderBy('migration')->orderBy('batch')->limit(1001)->get(['migration', 'batch']);
        if (count($rows) > 1000) {
            throw new RuntimeException('Migration metadata limit exceeded.');
        }
        foreach ($rows as $row) {
            if (!in_array($row->migration, $known, true)
                || !is_numeric($row->batch) || (int)$row->batch < 0) {
                throw new RuntimeException('Invalid migration metadata.');
            }
            $records[] = ['migration' => $row->migration, 'batch' => (int)$row->batch];
        }
    }
    $report['desktop_migrations'] = ['table_exists' => $exists, 'records' => $records];
    $report['desktop_dashboard_flags'] = [
        'enabled' => (bool)config('desktop_dashboard.enabled', false),
        'local' => (bool)config('desktop_dashboard.local', false),
    ];
    echo json_encode($report, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Never expose exception messages, connection settings, or application data.
    fwrite(STDERR, "Desktop metadata inspection failed.\n");
    exit(1);
}
