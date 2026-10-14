<?php
// Standalone fixture with the real Laravel encrypter; no Graph calls or application writes.
$autoload = getenv('WA_INBOX_TEST_VENDOR');
if (is_dir((string) $autoload)) $autoload .= '/autoload.php';
if (!$autoload || !is_file($autoload)) { fwrite(STDERR, "WA_INBOX_TEST_VENDOR must identify the test autoloader.\n"); exit(2); }
require $autoload;

use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use App\Services\Dashboard\MetaOAuthCredentials;
use App\Services\Dashboard\MetaOAuthFailure;

$container = new Container;
$container->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
Facade::setFacadeApplication($container);
function config($key = null, $default = null) { global $credentialConfig; return $credentialConfig[$key] ?? $default; }
require __DIR__.'/../app/Services/Dashboard/MetaOAuthFailure.php';
require __DIR__.'/../app/Services/Dashboard/MetaOAuthCredentials.php';

$checks = 0;
function check($value, string $label): void { global $checks; $checks++; if (!$value) throw new RuntimeException($label); }
function rejected(callable $call, string $reason, string $label): void {
    try { $call(); check(false, $label); }
    catch (MetaOAuthFailure $error) { check($error->reason() === $reason && $error->getMessage() === $reason, $label); }
}
function putPrivate(string $path, string $contents): void { file_put_contents($path, $contents); chmod($path, 0600); }
function removeTree(string $path): void {
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') removeTree($path.'/'.$entry);
    rmdir($path);
}
$root = sys_get_temp_dir().'/meta-oauth-credentials-'.bin2hex(random_bytes(12));
mkdir($root, 0700);
$parentPid = getmypid();
register_shutdown_function(static function () use ($root, $parentPid) { if (getmypid() === $parentPid) removeTree($root); });
$directory = $root.'/private'; mkdir($directory, 0700);
$settingsPath = $directory.'/settings.json'; $credentialsPath = $directory.'/credentials.json';
$settings = ['app_id' => '2177982783065133', 'config_id' => '3007667439572336',
    'redirect_uri' => 'https://fasakhansta.example/admin/meta/connection/callback', 'api_version' => 'v25.0'];
$settingsJson = json_encode($settings, JSON_THROW_ON_ERROR);
$base = [
    'messenger' => ['channel' => 'messenger', 'account_id' => '101', 'label' => 'Native Page', 'access_token' => str_repeat('n', 24)],
    'instagram' => ['channel' => 'instagram', 'account_id' => '202', 'label' => 'Native Instagram', 'access_token' => str_repeat('i', 24), 'login' => 'instagram'],
    'other' => ['channel' => 'messenger', 'account_id' => '404', 'access_token' => str_repeat('o', 24)],
];
$credentialConfig = ['meta_messaging.accounts' => array_values($base)];
$service = new MetaOAuthCredentials($directory);
$bundle = static function (array $accounts, string $at = '2026-10-14T14:00:00+00:00'): array {
    return ['accounts' => array_values($accounts), 'facebook_user_id' => '5555555555555555555555', 'authorized_at' => $at];
};
$page = ['channel' => 'messenger', 'account_id' => '101', 'label' => 'فسخانستا', 'access_token' => str_repeat('A', 36), 'login' => 'facebook'];
$instagram = ['channel' => 'instagram', 'account_id' => '202', 'label' => 'Instagram', 'access_token' => str_repeat('B', 36), 'login' => 'facebook', 'page_id' => '101'];

check($service->status() === ['configured' => false, 'credentials_available' => false, 'accounts' => []], 'absent settings has no activation');
check($service->overlay($base) === $base, 'absent settings preserves native base');
rejected(static fn () => $service->settings(), 'OAUTH_NOT_CONFIGURED', 'missing settings fixed reason');
rejected(static fn () => $service->save($bundle([$page])), 'CREDENTIALS_SAVE_FAILED', 'unconfigured save fails');
check(!file_exists($credentialsPath), 'unconfigured save writes no credentials');
putPrivate($settingsPath, $settingsJson);
check($service->settings() === $settings, 'validated explicit settings');
check($service->status()['configured'] && !$service->status()['credentials_available'], 'configured without tokens');
check($service->overlay($base) === $base, 'missing credentials preserves native base');

foreach ([
    ['app_id' => '999'], ['config_id' => '999'], ['api_version' => 'v26.0'],
    ['redirect_uri' => 'http://fasakhansta.example/admin/meta/connection/callback'],
    ['redirect_uri' => 'https://fasakhansta.example/admin/meta/connection/callback?code=private'],
    ['redirect_uri' => 'https://user@fasakhansta.example/admin/meta/connection/callback'],
    ['redirect_uri' => 'https://fasakhansta.example:443/admin/meta/connection/callback'],
    ['redirect_uri' => 'https://FASAKHANSTA.example/admin/meta/connection/callback'],
    ['redirect_uri' => 'https://fasakhansta.example/admin/meta/connection/callback/'],
] as $change) {
    putPrivate($settingsPath, json_encode(array_replace($settings, $change), JSON_THROW_ON_ERROR));
    rejected(static fn () => $service->settings(), 'OAUTH_NOT_CONFIGURED', 'invalid settings rejected');
}
putPrivate($settingsPath, $settingsJson);
chmod($settingsPath, 0644);
rejected(static fn () => $service->settings(), 'OAUTH_NOT_CONFIGURED', 'settings privacy required');
chmod($settingsPath, 0600);

$source = $root.'/native-config.json'; putPrivate($source, json_encode($base)); $sourceHash = hash_file('sha256', $source);
$service->save($bundle([$page]));
$firstRaw = file_get_contents($credentialsPath);
$wrapper = json_decode($firstRaw, true, 8, JSON_THROW_ON_ERROR);
check(array_keys($wrapper) === ['version', 'ciphertext'] && $wrapper['version'] === 1, 'disk stores encryption envelope only');
check(strpos($firstRaw, $page['access_token']) === false && strpos($firstRaw, $page['label']) === false
    && strpos($firstRaw, '5555555555555555555555') === false, 'no token or author metadata in plaintext');
$plain = json_decode(Crypt::decryptString($wrapper['ciphertext']), true, 16, JSON_THROW_ON_ERROR);
check($plain['accounts'][0]['access_token'] === $page['access_token'] && $plain['accounts'][0]['page_id'] === '101', 'real Laravel encryption round trip');
check((fileperms($credentialsPath) & 07777) === 0600 && (fileperms($directory) & 07777) === 0700
    && (fileperms($directory.'/credentials.lock') & 07777) === 0600, 'directory credentials lock stay private');
$service->save($bundle([$instagram], '2026-10-14T14:01:00Z'));
$overlaid = $service->overlay($base);
check(array_keys($overlaid) === array_keys($base) && count($overlaid) === count($base), 'overlay preserves list shape and allowlist count');
check($overlaid['messenger']['access_token'] === $page['access_token'] && $overlaid['instagram']['access_token'] === $instagram['access_token'], 'selected merge preserves previous unselected token');
check($overlaid['instagram']['login'] === 'facebook' && $overlaid['instagram']['page_id'] === '101', 'native login host overlays with validated routing');
check($overlaid['messenger']['label'] === 'Native Page' && $overlaid['other'] === $base['other'] && hash_file('sha256', $source) === $sourceHash, 'native labels unrelated tokens and source file stay unchanged');
$status = $service->status();
check($status['credentials_available'] && count($status['accounts']) === 2, 'status shows configured connected accounts');
foreach ($status['accounts'] as $account) check(array_keys($account) === ['channel', 'account_id', 'label', 'connected_at'], 'status exposes sanitized fields only');
check($status['accounts'][0]['connected_at'] === '2026-10-14T14:00:00Z' && $status['accounts'][1]['connected_at'] === '2026-10-14T14:01:00Z', 'unselected connection timestamp preserved');
check(strpos(json_encode($status), $page['access_token']) === false && strpos(json_encode($status), 'facebook_user_id') === false, 'status excludes secrets and author identity');
$differentChannel = [['channel' => 'messenger', 'account_id' => '202', 'access_token' => str_repeat('X', 24)]];
check($service->overlay($differentChannel) === $differentChannel, 'channel identity never collapses');
$credentialConfig['meta_messaging.accounts'] = [$base['instagram']];
check(count($service->status()['accounts']) === 1 && $service->status()['accounts'][0]['channel'] === 'instagram', 'removed allowlist entry is not connected in status');
check(count($service->overlay([$base['instagram']])) === 1, 'saved extra entries never extend allowlist');
$credentialConfig['meta_messaging.accounts'] = array_values($base);

// A later explicit source Page binding wins over an earlier saved OAuth association.
$relinked = $base;
$relinked['instagram']['page_id'] = '505';
$credentialConfig['meta_messaging.accounts'] = array_values($relinked);
$relinkedResult = $service->overlay($relinked);
check($relinkedResult['instagram'] === $relinked['instagram'], 'changed explicit Instagram Page keeps native token and link');
check($relinkedResult['messenger']['access_token'] === $page['access_token'], 'unrelated saved account still overlays after Page relink');
check(array_column($service->status()['accounts'], 'channel') === ['messenger'], 'conflicting saved Page link is not reported connected');
$beforeRelinkAttempt = hash_file('sha256', $credentialsPath);
rejected(static fn () => $service->save($bundle([$instagram])), 'CREDENTIALS_SAVE_FAILED', 'direct save cannot bypass changed source Page link');
check(hash_file('sha256', $credentialsPath) === $beforeRelinkAttempt, 'rejected conflicting Page link preserves credentials file');
$matchingInstagram = array_replace($instagram, ['page_id' => '505', 'access_token' => str_repeat('C', 36)]);
$service->save($bundle([$matchingInstagram]));
check($service->overlay($relinked)['instagram']['access_token'] === $matchingInstagram['access_token'], 'fresh authorization for matching explicit Page link can rotate token');
// Restore the fixture's original account link for subsequent failure/concurrency checks.
$credentialConfig['meta_messaging.accounts'] = array_values($base);
$service->save($bundle([$instagram]));

$validRaw = file_get_contents($credentialsPath); $before = hash('sha256', $validRaw);
foreach ([
    ['account_id' => '999'], ['access_token' => str_repeat('s', 15)], ['access_token' => str_repeat('s', 4097)],
    ['access_token' => str_repeat('s', 24)."\n"], ['login' => 'instagram'], ['page_id' => '202'],
    ['label' => "Bad\nlabel"], ['label' => str_repeat('ل', 101)], ['connected_at' => '2026-02-30T12:00:00Z'],
] as $change) {
    $badAccount = array_replace($page, $change);
    rejected(static fn () => $service->save($bundle([$badAccount])), 'CREDENTIALS_SAVE_FAILED', 'invalid or unconfigured account rejected');
    check(hash_file('sha256', $credentialsPath) === $before, 'rejected account never replaces credentials');
}
rejected(static fn () => $service->save($bundle([$page, $page])), 'CREDENTIALS_SAVE_FAILED', 'duplicate selected identity rejected');
rejected(static fn () => $service->save($bundle([$page], '2026-02-30T12:00:00Z')), 'CREDENTIALS_SAVE_FAILED', 'invalid authorization time rejected');
rejected(static fn () => $service->save($bundle([array_diff_key($instagram, ['page_id' => true])])), 'CREDENTIALS_SAVE_FAILED', 'Instagram requires associated Page');

putPrivate($credentialsPath, json_encode(['version' => 1, 'ciphertext' => str_repeat('bad', 30)]));
$corrupt = file_get_contents($credentialsPath);
check($service->overlay($base) === $base && $service->status()['accounts'] === [], 'broken encrypted file preserves native behavior and empty status');
rejected(static fn () => $service->save($bundle([$page])), 'CREDENTIALS_SAVE_FAILED', 'corrupt previous credentials cannot be silently erased');
check(file_get_contents($credentialsPath) === $corrupt, 'failed merge leaves corrupt existing file intact');
putPrivate($credentialsPath, str_repeat('x', 1048577));
check($service->overlay($base) === $base, 'oversized credential file bounded and ignored');
putPrivate($credentialsPath, $validRaw);
chmod($credentialsPath, 0644);
check($service->overlay($base) === $base, 'nonprivate credential file never used');
rejected(static fn () => $service->save($bundle([$page])), 'CREDENTIALS_SAVE_FAILED', 'nonprivate credential cannot be overwritten');
chmod($credentialsPath, 0600);
chmod($directory, 0755);
check(!$service->status()['configured'] && $service->overlay($base) === $base, 'nonprivate directory never activates');
chmod($directory, 0700);

$outside = $root.'/outside.json'; putPrivate($outside, $validRaw); $outsideHash = hash_file('sha256', $outside);
unlink($credentialsPath); symlink($outside, $credentialsPath);
check($service->overlay($base) === $base, 'credential symlink rejected');
rejected(static fn () => $service->save($bundle([$page])), 'CREDENTIALS_SAVE_FAILED', 'credential symlink cannot overwrite target');
check(hash_file('sha256', $outside) === $outsideHash, 'symlink target remains untouched');
unlink($credentialsPath); putPrivate($credentialsPath, $validRaw);
$settingsOutside = $root.'/settings-outside.json'; putPrivate($settingsOutside, $settingsJson);
unlink($settingsPath); symlink($settingsOutside, $settingsPath);
rejected(static fn () => $service->settings(), 'OAUTH_NOT_CONFIGURED', 'settings symlink rejected');
unlink($settingsPath); putPrivate($settingsPath, $settingsJson);
$linkedDirectory = $root.'/linked'; symlink($directory, $linkedDirectory);
rejected(static fn () => (new MetaOAuthCredentials($linkedDirectory))->settings(), 'OAUTH_NOT_CONFIGURED', 'private directory symlink rejected');
$parentLink = $root.'/parent-link'; symlink($root, $parentLink);
rejected(static fn () => (new MetaOAuthCredentials($parentLink.'/private'))->settings(), 'OAUTH_NOT_CONFIGURED', 'ancestor symlink rejected');
$hardlink = $root.'/hardlink.json'; link($credentialsPath, $hardlink);
check($service->overlay($base) === $base, 'multiply linked credential rejected'); unlink($hardlink);
if (posix_geteuid() === 0 && @chown($credentialsPath, 1)) {
    check($service->overlay($base) === $base, 'credential owner must be effective service user');
    chown($credentialsPath, 0);
}
unlink($directory.'/credentials.lock'); symlink($outside, $directory.'/credentials.lock');
rejected(static fn () => $service->save($bundle([$page])), 'CREDENTIALS_SAVE_FAILED', 'symlink lock rejected');
check(hash_file('sha256', $outside) === $outsideHash, 'symlink lock target unchanged'); unlink($directory.'/credentials.lock');

// Independent writers begin together; the final file must preserve every selected account.
if (function_exists('pcntl_fork')) {
    $children = [];
    for ($i = 0; $i < 4; $i++) $credentialConfig['meta_messaging.accounts'][] = ['channel' => 'messenger', 'account_id' => (string) (700 + $i)];
    for ($i = 0; $i < 4; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            // Child processes must not run the parent's fixture cleanup.
            $childAccount = array_replace($page, ['account_id' => (string) (700 + $i), 'access_token' => str_repeat((string) ($i + 1), 32)]);
            try { (new MetaOAuthCredentials($directory))->save($bundle([$childAccount])); exit(0); }
            catch (Throwable $error) { exit(3); }
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) { pcntl_waitpid($pid, $result); check(pcntl_wifexited($result) && pcntl_wexitstatus($result) === 0, 'concurrent writer succeeds'); }
    $connected = array_column($service->status()['accounts'], 'account_id'); sort($connected);
    check($connected === ['101', '202', '700', '701', '702', '703'], 'lock preserves all concurrently selected accounts');
}
check(hash_file('sha256', $source) === $sourceHash, 'native credential source untouched after all operations');
check(glob($directory.'/.credentials-*') === [], 'atomic temporary files removed');
echo "meta-oauth-credentials: $checks checks passed\n";
