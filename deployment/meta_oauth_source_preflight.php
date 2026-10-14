<?php
/**
 * CLI-only, local read-only metadata. No bootstrap, Graph requests, credentials, or app writes.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$metaOauthRoot = '/home/fasakha/public_html';
$metaOauthReport = ['diagnostic' => 'READ_ONLY_META_OAUTH_SOURCE', 'writes' => 0, 'graph_requests' => 0,
    'status' => 'FAILED', 'live_snapshot_not_atomic' => true, 'php_version' => PHP_VERSION];
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 1 || !function_exists('posix_geteuid')
        || !function_exists('posix_getpwnam')) throw new RuntimeException();
    $metaOauthOwner = posix_getpwnam('fasakha');
    if (!is_array($metaOauthOwner) || posix_geteuid() !== (int) $metaOauthOwner['uid']
        || is_link($metaOauthRoot) || realpath($metaOauthRoot) !== $metaOauthRoot) throw new RuntimeException();

    $metaOauthParents = static function (string $relative) use ($metaOauthRoot): bool {
        if ($relative === '' || strpos($relative, '..') !== false || $relative[0] === '/') return false;
        $current = $metaOauthRoot;
        $parts = explode('/', $relative);
        array_pop($parts);
        foreach ($parts as $part) {
            $current .= '/'.$part;
            $stat = @lstat($current);
            if ($stat === false) continue; // Missing new-file parents are reported separately.
            if (($stat['mode'] & 0170000) !== 0040000 || is_link($current)) return false;
        }
        return true;
    };
    $metaOauthInfo = static function (string $relative, bool $readSource = true) use ($metaOauthRoot, $metaOauthParents, $metaOauthOwner): array {
        if (!$metaOauthParents($relative)) return ['status' => 'PARENT_BLOCKED'];
        $path = $metaOauthRoot.'/'.$relative;
        $stat = @lstat($path);
        if ($stat === false) return ['status' => 'ABSENT', 'present' => false];
        $out = ['present' => true, 'mode' => sprintf('%04o', $stat['mode'] & 07777),
            'uid' => (int) $stat['uid'], 'gid' => (int) $stat['gid'],
            'owned_by_fasakha' => (int) $stat['uid'] === (int) $metaOauthOwner['uid']];
        if (($stat['mode'] & 0170000) === 0120000 || is_link($path)) return array_merge($out, ['status' => 'SYMLINK_BLOCKED']);
        if (($stat['mode'] & 0170000) !== 0100000) return array_merge($out, ['status' => 'NOT_REGULAR']);
        $out['status'] = 'READY';
        if (!$readSource) {
            $out['private_mode'] = ($stat['mode'] & 0077) === 0;
            return $out;
        }
        if ($stat['size'] < 0 || $stat['size'] > 1048576) return array_merge($out, ['status' => 'SOURCE_SIZE_BLOCKED']);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return array_merge($out, ['status' => 'UNREADABLE']);
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || ($opened['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino'])
                return array_merge($out, ['status' => 'CHANGED_DURING_READ']);
            $bytes = stream_get_contents($handle, 1048577);
            $after = fstat($handle);
            if (!is_string($bytes) || strlen($bytes) > 1048576 || !feof($handle)
                || !is_array($after) || $after['size'] !== strlen($bytes)
                || $after['mtime'] !== $opened['mtime'] || $after['ctime'] !== $opened['ctime'])
                return array_merge($out, ['status' => 'CHANGED_DURING_READ']);
            $out['git_blob'] = sha1('blob '.strlen($bytes)."\0".$bytes);
            $out['bytes'] = strlen($bytes);
            return $out;
        } finally { fclose($handle); }
    };

    $metaOauthReport['planned_source'] = [];
    foreach ([
        'app/Http/Controllers/Dashboard/MetaInboxController.php' => 'e3183d9bd1bbb6ded01cb51a9d8bd29bdb9fca91',
        'app/Services/Dashboard/MetaInboxAccess.php' => '260b0a1cc93be44bf9e96f508b55420e5f639f64',
        'routes/meta_inbox.php' => '646634ef68604636bf05e07fc619ae722d6a5f02',
        'resources/views/admin/meta/index.blade.php' => '79f6d85da843ec69a98c36728fc095c6eb22d645',
        'public/css/dashboard-meta-inbox.css' => 'ab4beecd1580769d917e3ddfe3b4139123d9dbbe',
        'app/Http/Controllers/Dashboard/MetaConnectionController.php' => 'ABSENT',
        'app/Http/Middleware/MetaOAuthCallbackPrivacy.php' => 'ABSENT',
        'app/Services/Dashboard/MetaOAuthCredentials.php' => 'ABSENT',
        'app/Services/Dashboard/MetaOAuthService.php' => 'ABSENT',
        'app/Services/Dashboard/MetaOAuthFailure.php' => 'ABSENT',
        'resources/views/admin/meta/connection.blade.php' => 'ABSENT',
    ] as $file => $expected) {
        $info = $metaOauthInfo($file);
        $info['expected_baseline'] = $expected;
        $info['baseline_matches'] = $expected === 'ABSENT'
            ? ($info['status'] ?? null) === 'ABSENT'
            : ($info['status'] ?? null) === 'READY' && ($info['git_blob'] ?? null) === $expected;
        $metaOauthReport['planned_source'][$file] = $info;
    }
    $metaOauthReport['auth_foundation'] = [];
    foreach ([
        'app/Services/Dashboard/TakeawayAccess.php',
        'app/Services/Dashboard/WhatsAppInboxAccess.php',
        'app/Http/Middleware/IsAdmin.php',
        'app/Http/Middleware/VerifyCsrfToken.php',
        'app/Http/Kernel.php',
        'app/Providers/AppServiceProvider.php',
        'app/Providers/RouteServiceProvider.php',
        'routes/web.php',
        'config/meta_messaging.php',
    ] as $file) $metaOauthReport['auth_foundation'][$file] = $metaOauthInfo($file);
    $metaOauthReport['route_cache'] = [];
    foreach (['bootstrap/cache/routes-v7.php', 'bootstrap/cache/routes.php'] as $file)
        $metaOauthReport['route_cache'][$file] = $metaOauthInfo($file);
    $metaOauthReport['route_cache_environment_override_present'] = getenv('APP_ROUTES_CACHE') !== false;
    $metaOauthReport['private_oauth_files'] = [];
    foreach (['storage/app/private/meta-oauth/settings.json', 'storage/app/private/meta-oauth/credentials.json'] as $file)
        $metaOauthReport['private_oauth_files'][$file] = $metaOauthInfo($file, false);
    $metaOauthDirectory = 'storage/app/private/meta-oauth';
    $metaOauthDirectoryStat = @lstat($metaOauthRoot.'/'.$metaOauthDirectory);
    $metaOauthReport['private_oauth_directory'] = $metaOauthDirectoryStat === false
        ? ['status' => 'ABSENT', 'present' => false]
        : ['status' => $metaOauthParents($metaOauthDirectory.'/settings.json')
                && ($metaOauthDirectoryStat['mode'] & 0170000) === 0040000 ? 'READY' : 'BLOCKED',
            'present' => true, 'mode' => sprintf('%04o', $metaOauthDirectoryStat['mode'] & 07777),
            'owned_by_fasakha' => (int) $metaOauthDirectoryStat['uid'] === (int) $metaOauthOwner['uid'],
            'private_mode' => ($metaOauthDirectoryStat['mode'] & 0077) === 0];
    $metaOauthReport['status'] = 'READY';
    foreach (['planned_source', 'auth_foundation', 'route_cache', 'private_oauth_files'] as $group) {
        foreach ($metaOauthReport[$group] as $info) {
            if (!in_array($info['status'] ?? null, ['READY', 'ABSENT'], true)) $metaOauthReport['status'] = 'BLOCKED';
        }
    }
    foreach ($metaOauthReport['auth_foundation'] as $info) {
        if (($info['status'] ?? null) !== 'READY') $metaOauthReport['status'] = 'BLOCKED';
    }
    if (($metaOauthReport['private_oauth_directory']['status'] ?? null) === 'BLOCKED')
        $metaOauthReport['status'] = 'BLOCKED';
    $metaOauthReport['baseline_complete_match'] = count(array_filter(
        $metaOauthReport['planned_source'], static fn ($info) => ($info['baseline_matches'] ?? false) !== true
    )) === 0;
} catch (Throwable $error) {
    // Only the fixed diagnostic status is returned; never paths from exception messages or file contents.
}
echo json_encode($metaOauthReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
