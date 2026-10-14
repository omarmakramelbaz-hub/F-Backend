<?php
/** CLI-only readiness reads. Never runs a worker, calls a provider or changes policy. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
// BEGIN COMMENT AI UNIQUE INDEX CHECK
function metaCommentAiUniqueIndexes($connection, string $table): bool
{
    $needs = [
        'meta_comment_ai_settings' => [['id']],
        'meta_comment_ai_targets' => [['target_hash']],
        'meta_comment_ai_poll_states' => [['account_key']],
        'meta_comment_reply_requests' => [['request_hash'], ['pending_key']],
    ];
    if (!isset($needs[$table])) return false;
    $indexes = []; $prefix = $connection->getTablePrefix();
    if ($connection->getDriverName() === 'mysql') {
        $rows = $connection->select('SHOW INDEX FROM `'.str_replace('`', '``', $prefix.$table).'`');
        foreach ($rows as $row) {
            if (!in_array($row->Non_unique ?? null, [0, '0'], true)) continue;
            $name = $row->Key_name ?? null; $column = $row->Column_name ?? null;
            if (!is_string($name)) continue;
            $indexes[$name]['valid'] = $indexes[$name]['valid'] ?? true;
            if (!is_string($column) || ($row->Sub_part ?? null) !== null || (int) ($row->Seq_in_index ?? 0) < 1) {
                $indexes[$name]['valid'] = false; continue;
            }
            $indexes[$name]['columns'][(int) ($row->Seq_in_index ?? 0)] = $column;
            $indexes[$name]['primary'] = $name === 'PRIMARY';
        }
    } elseif ($connection->getDriverName() === 'sqlite') {
        $quote = static fn ($value) => '"'.str_replace('"', '""', $value).'"';
        $physical = $quote($prefix.$table);
        foreach ($connection->select('PRAGMA index_list('.$physical.')') as $index) {
            if ((int) ($index->unique ?? 0) !== 1 || (int) ($index->partial ?? 0) !== 0 || !is_string($index->name ?? null)) continue;
            foreach ($connection->select('PRAGMA index_info('.$quote($index->name).')') as $column) {
                $indexes[$index->name]['columns'][(int) $column->seqno] = $column->name;
                $indexes[$index->name]['primary'] = ($index->origin ?? '') === 'pk';
            }
        }
        foreach ($connection->select('PRAGMA table_info('.$physical.')') as $column) {
            if ((int) ($column->pk ?? 0) > 0) {
                $indexes['PRIMARY']['columns'][(int) $column->pk] = $column->name;
                $indexes['PRIMARY']['primary'] = true;
            }
        }
    } else return false;
    foreach ($needs[$table] as $required) {
        $found = false;
        foreach ($indexes as $index) {
            if (($index['valid'] ?? true) === false || !isset($index['columns'])) continue;
            ksort($index['columns']);
            if (array_values($index['columns']) === $required
                && ($table !== 'meta_comment_ai_settings' || ($index['primary'] ?? false))) $found = true;
        }
        if (!$found) return false;
    }
    return true;
}
// END COMMENT AI UNIQUE INDEX CHECK
$level = ob_get_level(); ob_start(); $exit = 1; $out = ['ok' => false]; $step = 'bootstrap';
try {
    $root = $argv[1] ?? '/home/fasakha/public_html';
    $phase = $argv[2] ?? 'diagnostic';
    $stage = $argv[3] ?? null;
    require $root.'/vendor/autoload.php';
    if ($stage) spl_autoload_register(static function ($class) use ($stage) {
        if (str_starts_with($class, 'App\\')) {
            $path = $stage.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($path)) require $path;
        }
    }, true, true);
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('desktop_dashboard.local', false)) throw new \RuntimeException('LIVE_SERVER_REQUIRED');
    $step = 'access';
    $owner = \App\Models\User::query()->find(1);
    $access = $app->make(\App\Services\Dashboard\MetaInboxAccess::class);
    if (!$owner || !$access->canAccess($owner) || !$access->available()) throw new \RuntimeException('CENTRAL_INBOX_NOT_READY');
    $columns = [
        'meta_comment_reply_requests' => ['id','actor_id','account_key','target_hash','request_hash','payload_hash','pending_key','state','reason','response_details','send_started_at','completed_at','created_at','updated_at'],
        'meta_comment_ai_settings' => ['id','enabled','mode','days','start','end','revision','enabled_at','actor_id','worker_seen_at','last_reason','created_at','updated_at'],
        'meta_comment_ai_targets' => ['id','account_key','target_hash','post_key','comment_created_at','state','source','request_id','revision','reason','response_id','created_at','updated_at'],
        'meta_comment_ai_poll_states' => ['id','account_key','state','created_at','updated_at'],
    ];
    $tables = []; $counts = [];
    foreach ($columns as $table => $required) {
        $step = 'schema:'.$table;
        $tables[$table] = \Illuminate\Support\Facades\Schema::hasTable($table);
        if ($tables[$table] && !\Illuminate\Support\Facades\Schema::hasColumns($table, $required)) throw new \RuntimeException('AI_SCHEMA_DIFFERS');
        if ($tables[$table] && !metaCommentAiUniqueIndexes(\Illuminate\Support\Facades\DB::connection(), $table)) throw new \RuntimeException('AI_UNIQUE_GUARDS_MISSING');
        $counts[$table] = $tables[$table] ? (int) \Illuminate\Support\Facades\DB::table($table)->count() : 0;
    }
    if (!$tables['meta_comment_reply_requests']) throw new \RuntimeException('COMMENTS_LEDGER_MISSING');
    $ready = !in_array(false, $tables, true);
    $step = 'policy';
    $policy = $ready ? $app->make(\App\Services\Dashboard\MetaCommentAiControl::class)->snapshot() : null;
    if ($ready && $policy === null) throw new \RuntimeException('AI_POLICY_INVALID');
    $step = 'provider';
    $configured = $app->make(\App\Services\Dashboard\MetaCommentAiProvider::class)->ready();
    $step = 'accounts';
    $accounts = \App\Services\Dashboard\MetaInboxAccess::accounts();
    $connected = count(array_filter($accounts, static fn ($a) => is_string($a['access_token'] ?? null)
        && strlen($a['access_token']) >= 16 && strlen($a['access_token']) <= 4096));
    $caches = []; $compiled = 0;
    if (isset($argv[4])) {
        $step = 'view_manifest';
        $views = json_decode(file_get_contents($argv[4]), true, 32, JSON_THROW_ON_ERROR);
        foreach ($views as $relative => $path) {
            $step = 'view_compile';
            $code = $app['blade.compiler']->compileString(file_get_contents($path)); token_get_all($code, TOKEN_PARSE);
            $step = 'view_cache_path';
            $cache = $app['blade.compiler']->getCompiledPath($root.'/'.$relative);
            $prefix = $root.'/storage/framework/views/';
            if (strncmp($cache, $prefix, strlen($prefix)) !== 0 || !preg_match('/^[a-f0-9]+\\.php$/D', substr($cache, strlen($prefix)))) throw new \RuntimeException('VIEW_CACHE_PATH_UNSUPPORTED');
            $caches[] = substr($cache, strlen($root)+1); $compiled++;
        }
    }
    $verified = 0;
    if ($phase === 'verify') {
        if (!$ready) throw new \RuntimeException('AI_TABLES_NOT_READY');
        foreach (['status' => 'GET', 'update' => 'POST'] as $action => $method) {
            $step = 'route:'.$action;
            $name = 'meta-comment-ai.'.$action;
            $route = $app['router']->getRoutes()->getByName($name);
            $methods = $route ? $route->methods() : []; sort($methods);
            $expected = $method === 'GET' ? ['GET','HEAD'] : ['POST']; sort($expected);
            if (!$route || $route->uri() !== 'admin/meta/comments/ai/settings'
                || $methods !== $expected
                || $route->getActionName() !== \App\Http\Controllers\Dashboard\MetaCommentAiController::class.'@'.$action
                || !(new \ReflectionMethod(\App\Http\Controllers\Dashboard\MetaCommentAiController::class, $action))->isPublic()
                || !in_array('web', $route->gatherMiddleware(), true)
                || !in_array('lang', $route->gatherMiddleware(), true)
                || !in_array('IsAdmin', $route->gatherMiddleware(), true)
                || count($route->excludedMiddleware()) !== 0
                || $app['router']->getRoutes()->match(\Illuminate\Http\Request::create('/admin/meta/comments/ai/settings', $method))->getName() !== $name)
                throw new \RuntimeException('AI_ROUTES_INVALID');
            $verified++;
        }
    }
    $step = 'result';
    $out = ['ok' => true, 'tables_ready' => $ready, 'tables' => $tables, 'counts' => $counts,
        'configured' => (bool) $configured, 'policy_present' => $policy !== null,
        'configured_accounts' => $connected,
        'enabled' => $policy['enabled'] ?? null, 'mode' => $policy['mode'] ?? null,
        'worker_seen' => !empty($policy['worker_seen_at']),
        'routes_cached' => $app->routesAreCached(), 'config_cached' => $app->configurationIsCached(),
        'verified_routes' => $verified, 'views_compiled' => $compiled, 'view_caches' => $caches];
    $exit = 0;
} catch (\Throwable $error) {
    $known = ['LIVE_SERVER_REQUIRED','CENTRAL_INBOX_NOT_READY','AI_SCHEMA_DIFFERS','COMMENTS_LEDGER_MISSING',
        'AI_POLICY_INVALID','VIEW_CACHE_PATH_UNSUPPORTED','AI_TABLES_NOT_READY','AI_ROUTES_INVALID','AI_UNIQUE_GUARDS_MISSING'];
    $out = ['ok' => false, 'reason' => in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'AI_READINESS_FAILED',
        // Fixed checkpoint and source location only; never exception messages, SQL, arguments or credentials.
        'diagnostic' => ['phase' => in_array($phase ?? null, ['preflight','post_migration','verify','diagnostic'], true) ? $phase : 'diagnostic',
            'step' => $step, 'type' => get_class($error),
            'file' => $error->getFile() !== '' ? basename($error->getFile()) : ($step === 'view_compile' ? 'compiled-view.php' : basename(__FILE__)),
            'line' => $error->getLine()]];
}
while (ob_get_level() > $level) ob_end_clean();
echo json_encode($out, JSON_UNESCAPED_SLASHES)."\n"; exit($exit);
