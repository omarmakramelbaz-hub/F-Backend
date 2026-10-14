<?php
/** Real CLI diagnostic + Laravel 8 router/Blade/SQLite integration; no provider calls.
 * META_COMMENT_AI_LARAVEL_VENDOR=/path/to/laravel8/vendor php tests/meta_comment_ai_diagnostic_test.php
 */

function metaCommentDiagnosticFixture(string $root, bool $migrated)
{
    $app = new class($root) extends \Illuminate\Foundation\Application {
        public function configurationIsCached() { return true; }
        public function routesAreCached() { return false; }
    };
    $app->instance('config', new \Illuminate\Config\Repository([
        'app' => ['locale' => 'ar', 'key' => str_repeat('k', 32), 'cipher' => 'AES-256-CBC'],
        'desktop_dashboard' => ['local' => false],
        // Simulates an existing config cache without a meta_comment_ai entry.
        'whatsapp_orders' => ['model' => 'gpt-6-luna', 'api_key' => str_repeat('fixture-key-', 4)],
        'view' => ['compiled' => $root.'/storage/framework/views'],
    ]));
    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $capsule = new \Illuminate\Database\Capsule\Manager($app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $app->instance('db', $capsule->getDatabaseManager());
    $app->bind('db.schema', static fn () => $capsule->schema());
    $schema = $capsule->schema();
    $schema->create('meta_comment_reply_requests', function ($table) {
        $table->bigIncrements('id');
        foreach (['actor_id','account_key','target_hash','payload_hash','state','reason','response_details','send_started_at','completed_at'] as $column) $table->text($column)->nullable();
        $table->string('request_hash', 64)->unique();
        $table->string('pending_key', 64)->nullable()->unique();
        $table->timestamps();
    });
    if ($migrated) {
        require dirname(__DIR__).'/database/migrations/2026_10_15_010000_create_meta_comment_ai_tables.php';
        (new \CreateMetaCommentAiTables())->up();
    }
    // Only the existing account/auth boundary is a fixture; AI/control/schema/router/Blade are real.
    eval('namespace App\\Models; class User { public static function query() { return new class { public function find($id) { return (object)["id" => 1]; } }; } }');
    eval('namespace App\\Services\\Dashboard; class MetaInboxAccess { public function canAccess($owner) { return $owner->id === 1; } public function available() { return true; } public static function accounts() { return [["access_token" => str_repeat("fixture", 4)], ["access_token" => str_repeat("fixture", 4)]]; } }');
    $app->instance(\Illuminate\Contracts\Console\Kernel::class, new class { public function bootstrap() {} });
    $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($app), $app);
    $app->instance('router', $router);
    \Illuminate\Support\Facades\Route::swap($router);
    $router->middleware('web')->group(dirname(__DIR__).'/routes/meta_comment_ai.php');
    $router->getRoutes()->refreshNameLookups();
    $app->instance('blade.compiler', new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), $root.'/storage/framework/views'));
    return $app;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

$vendor = getenv('META_COMMENT_AI_LARAVEL_VENDOR') ?: '/workspace/scratch/2e8ce93ecff2/php-runtime/dependencies/vendor';
if (!is_file($vendor.'/laravel/framework/src/Illuminate/Foundation/Application.php')) {
    fwrite(STDERR, "Set META_COMMENT_AI_LARAVEL_VENDOR to a Laravel 8 vendor directory.\n"); exit(1);
}
$project = dirname(__DIR__);
$php = getenv('META_COMMENT_AI_TEST_PHP') ?: (is_executable(dirname($project).'/php-runtime/php') ? dirname($project).'/php-runtime/php' : PHP_BINARY);
$temporary = sys_get_temp_dir().'/meta-comment-diagnostic-test-'.bin2hex(random_bytes(6));
mkdir($temporary, 0755, true);
$checks = 0;
try {
    foreach (['initial', 'staged', 'installed', 'invalid_blade'] as $case) {
        $root = $temporary.'/'.$case;
        $installed = in_array($case, ['installed', 'invalid_blade'], true);
        foreach (['bootstrap', 'storage/framework/views', 'resources/views/admin/meta'] as $path) mkdir($root.'/'.$path, 0755, true);
        symlink($vendor, $root.'/vendor');
        symlink($project.'/app', $root.'/app');
        $view = 'resources/views/admin/meta/comments.blade.php';
        copy($project.'/'.$view, $root.'/'.$view);
        if ($case === 'invalid_blade') file_put_contents($root.'/'.$view, "\n<?php \$privateFixture = 'PRIVATE-TEMPLATE-MARKER'; if ( ?>", FILE_APPEND);
        $bootstrap = '<?php require '.var_export(__FILE__, true).'; return metaCommentDiagnosticFixture('.var_export($root, true).', '.($case === 'initial' ? 'false' : 'true').');';
        file_put_contents($root.'/bootstrap/app.php', $bootstrap);
        $views = $root.'/views.json';
        file_put_contents($views, json_encode([$view => ($installed ? $root : $project).'/'.$view], JSON_THROW_ON_ERROR));
        $command = [$php, '-d', 'display_errors=0', $project.'/deployment/meta_comment_ai_diagnostic.php', $root,
            $installed ? 'verify' : 'preflight', $installed ? $root : $project, $views];
        $pipes = [];
        $process = proc_open($command, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes, $project);
        if (!is_resource($process)) throw new RuntimeException('Diagnostic process did not start');
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        $body = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        $assert = static function ($ok, string $label) use (&$checks, $case, $code, $body, $stderr) {
            $checks++;
            if (!$ok) throw new RuntimeException($case.': '.$label.'; '.json_encode(['exit'=>$code,'body'=>$body,'stderr'=>$stderr]));
        };
        if ($case === 'invalid_blade') {
            $detail = $body['diagnostic'] ?? [];
            $assert($code === 1 && ($body['ok'] ?? null) === false, 'actual invalid Blade fails closed');
            $assert(($body['reason'] ?? null) === 'AI_READINESS_FAILED', 'unknown PHP exception uses safe reason');
            $assert(($detail['phase'] ?? null) === 'verify' && ($detail['step'] ?? null) === 'view_compile', 'exact failing phase and step reported');
            $assert(($detail['type'] ?? null) === 'ParseError', 'actual PHP parser exception class reported');
            $assert(($detail['file'] ?? null) === 'compiled-view.php' && is_int($detail['line'] ?? null) && $detail['line'] > 0, 'only compiled source basename and line reported');
            $assert(array_keys($body) === ['ok', 'reason', 'diagnostic'] && array_keys($detail) === ['phase', 'step', 'type', 'file', 'line'], 'exception message, stack and arguments excluded');
            $assert(!str_contains($stdout.$stderr, 'PRIVATE-TEMPLATE-MARKER') && !str_contains($stdout.$stderr, 'privateFixture'), 'private template content never leaked');
            $assert(!str_contains($stdout.$stderr, $root) && !str_contains($stdout.$stderr, $project), 'absolute source paths never leaked');
            continue;
        }
        $assert($code === 0 && ($body['ok'] ?? false) === true, 'real CLI readiness succeeds');
        $assert(($body['config_cached'] ?? null) === true, 'cached config path exercised');
        $assert(($body['configured'] ?? null) === true, 'provider reads existing cached WhatsApp credentials');
        $assert(($body['views_compiled'] ?? null) === 1, 'actual comments Blade parsed');
        $assert(($body['view_caches'] ?? null) === ['storage/framework/views/'.sha1('v2'.$root.'/'.$view).'.php'], 'actual cache path matches reviewed view');
        $assert(($body['tables_ready'] ?? null) === ($case !== 'initial'), 'real schema and unique guards checked');
        $assert(($body['policy_present'] ?? null) === ($case !== 'initial'), 'real policy snapshot checked');
        $assert(($body['verified_routes'] ?? null) === ($case === 'installed' ? 2 : 0), 'actual installed routes verified');
        $assert(($body['enabled'] ?? null) === ($case === 'initial' ? null : false), 'installation never enables replies');
    }
    echo "meta-comment-ai-diagnostic: $checks checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
    rmdir($temporary);
}
