<?php
// Explicit old-reference preparation command, never included by runtime tests.
// Usage: php capture-native-delete-form-old-reference.php verified-0ddf-runtime/application [--check]
// Without --check, writes public baseline JSON to stdout; expected values come
// only from Collective 6.4.1, never from the replacement helper.
use Collective\Html\{FormBuilder, HtmlBuilder};

$application = realpath($argv[1] ?? '');
if (!$application || !is_file($application.'/vendor/composer/installed.json')) throw new RuntimeException('The verified old application is required.');
$manifestPath = dirname($application).'/manifest.json';
$manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['sourceRevision'] ?? '') !== '0ddf0328a77b5090184f6877280956682617825f') throw new RuntimeException('Capture requires the exact original real-MariaDB-verified preparation revision.');
foreach ($manifest['sourceHashes'] as $path => $hash) {
    if (hash_file('sha256', $application.'/'.$path) !== $hash) throw new RuntimeException('Old reference source inventory mismatch: '.$path);
}
$installed = json_decode(file_get_contents($application.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = [];
foreach ($installed['packages'] as $package) {
    if (in_array($package['name'], ['laravel/framework', 'laravelcollective/html'], true)) {
        $packages[$package['name']] = ['version' => $package['version'], 'reference' => $package['source']['reference']];
    }
}
if (($packages['laravel/framework']['version'] ?? '') !== 'v8.83.29'
    || ($packages['laravelcollective/html']['version'] ?? '') !== 'v6.4.1') {
    throw new RuntimeException('Capture requires the explicit Laravel 8.83.29 / Collective 6.4.1 old reference; missing packages cannot be skipped.');
}
$profile = sys_get_temp_dir().'/native-delete-form-old-reference-'.bin2hex(random_bytes(6));
foreach (['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'] as $dir) mkdir($profile.'/'.$dir, 0700, true);
foreach (['DESKTOP_DASHBOARD_LOCAL'=>'true','DESKTOP_DASHBOARD_STORAGE'=>$profile,'APP_ENV'=>'desktop','APP_DEBUG'=>'false',
    'APP_URL'=>'http://127.0.0.1:43144','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)), 'CACHE_DRIVER'=>'file','SESSION_DRIVER'=>'file',
    'APP_CONFIG_CACHE'=>$profile.'/bootstrap/cache/config.php','APP_PACKAGES_CACHE'=>$profile.'/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE'=>$profile.'/bootstrap/cache/services.php','APP_ROUTES_CACHE'=>$profile.'/bootstrap/cache/routes.php'] as $name=>$value) putenv($name.'='.$value);
$app = require $application.'/desktop/bootstrap.php';
require_once __DIR__.'/native-delete-form-contract-support.php';
if (!class_exists(FormBuilder::class)) throw new RuntimeException('The explicit old Collective reference is unavailable.');
app('session.store')->regenerateToken();
$query = ['parent' => 'قسم & "<اختبار>"', 'account_type' => 'vendor & "<test>"'];
app('request')->query->replace($query);
$cases = nativeDeleteFormCases($application);
if (count($cases) !== 22 || count(array_unique(array_column($cases, 'view'))) !== 19) throw new RuntimeException('The verified 22 forms in 19 views are required.');
$baseline = [
    'format' => 1,
    'reference' => [
        'sourceRevision' => $manifest['sourceRevision'],
        'sourceManifestSha256' => hash_file('sha256', $manifestPath),
        'sourceFingerprint' => $manifest['sourceFingerprint'],
        'packages' => $packages,
        'dependencyFilesSha256' => [],
        'expressionHashNormalization' => 'CRLF to LF only',
        'domNormalization' => 'Sorted attributes; replace only current action origin prefix and current-session _token value. Preserve path, query, input order and all other attributes.',
    ],
    'requestQuery' => $query,
    'syntheticRowId' => 42,
    'closeHtml' => null,
    'cases' => [],
];
foreach (['vendor/laravelcollective/html/src/FormBuilder.php','vendor/laravelcollective/html/src/HtmlBuilder.php',
    'vendor/laravel/framework/src/Illuminate/Foundation/helpers.php'] as $path) $baseline['reference']['dependencyFilesSha256'][$path] = hash_file('sha256', $application.'/'.$path);
$origin = rtrim(app('url')->to('/'), '/');
foreach ($cases as $key => $case) {
    $reference = (new FormBuilder(new HtmlBuilder(app('url'), app('view')), app('url'), app('view'), csrf_token(), app('request')))->setSessionStore(app('session.store'));
    $open = (string) $reference->open(nativeDeleteFormOptions($case['expression']));
    $close = (string) $reference->close();
    if ($baseline['closeHtml'] !== null && $baseline['closeHtml'] !== $close) throw new RuntimeException('Old closing tags disagree.');
    $baseline['closeHtml'] = $close;
    $baseline['cases'][$key] = ['view' => $case['view'], 'ordinal' => $case['ordinal'], 'expressionSha256' => $case['expressionSha256'],
        'dom' => nativeDeleteFormDom($open.$close, $origin, csrf_token())];
}
$json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
if (($argv[2] ?? '') === '--check') {
    if ($json !== file_get_contents(__DIR__.'/fixtures/native-delete-form-collective-6.4.1.json')) throw new RuntimeException('The frozen baseline differs from the explicit verified old renderer.');
    echo 'PASS 22 frozen forms exactly reproduce the verified Laravel 8.83.29 / Collective 6.4.1 reference'.PHP_EOL;
} else echo $json;
