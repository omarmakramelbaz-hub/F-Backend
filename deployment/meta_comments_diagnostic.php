<?php

/** Read-only capability check. Never print tokens, Graph bodies, comment text, or exception messages. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$level = ob_get_level();
ob_start();
$result = ['check_completed' => false, 'accounts' => []];
$exit = 1;
try {
    $root = $argv[1] ?? '/home/fasakha/public_html';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('desktop_dashboard.local', false)) throw new \RuntimeException('LOCAL');
    $secret = (string) config('meta_messaging.app_secret', '');
    $version = (string) config('meta_messaging.api_version', 'v25.0');
    if (!preg_match('/\Av[0-9]{1,2}\.0\z/', $version)) throw new \RuntimeException('VERSION');
    foreach (\App\Services\Dashboard\MetaInboxAccess::accounts() as $account) {
        $channel = $account['channel'];
        $facebookLogin = $channel === 'messenger' || ($account['login'] ?? 'facebook') === 'facebook';
        $host = $facebookLogin ? 'https://graph.facebook.com' : 'https://graph.instagram.com';
        $token = (string) ($account['access_token'] ?? '');
        $permissions = $channel === 'messenger'
            ? ['pages_read_engagement', 'pages_read_user_content', 'pages_manage_engagement']
            : ($facebookLogin ? ['instagram_basic', 'instagram_manage_comments', 'pages_read_engagement']
                : ['instagram_business_basic', 'instagram_business_manage_comments']);
        $info = ['channel' => $channel === 'messenger' ? 'facebook' : 'instagram',
            'posts_readable' => false, 'token_verified' => false,
            'missing_permissions' => [], 'permission_check' => 'unavailable'];
        if ($token === '') {
            $info['read_status'] = 'TOKEN_MISSING';
            $result['accounts'][] = $info;
            continue;
        }
        $proof = $secret !== '' && $facebookLogin ? hash_hmac('sha256', $token, $secret) : null;
        $params = ['fields' => 'id', 'limit' => '1'];
        if ($proof !== null) $params['appsecret_proof'] = $proof;
        try {
            $read = \Illuminate\Support\Facades\Http::withToken($token)
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->connectTimeout(3)->timeout(8)->get($host.'/'.$version.'/'.$account['account_id'].'/'.
                    ($channel === 'messenger' ? 'published_posts' : 'media'), $params);
            $data = strlen($read->body()) <= 65536 ? $read->json() : null;
            $info['posts_readable'] = $read->successful() && is_array($data) && is_array($data['data'] ?? null);
            $code = (int) ($data['error']['code'] ?? 0);
            $info['read_status'] = $info['posts_readable'] ? 'READ_READY'
                : (in_array($code, [10, 200], true) ? 'PERMISSION_REQUIRED'
                    : ($code === 190 ? 'TOKEN_EXPIRED' : 'READ_UNAVAILABLE'));
        } catch (\Throwable $error) { $info['read_status'] = 'READ_UNAVAILABLE'; }
        if ($facebookLogin && $secret !== '') {
            try {
                // Fixed configured application. The app token and account token stay in memory.
                $appToken = '2177982783065133|'.$secret;
                $debug = \Illuminate\Support\Facades\Http::withToken($appToken)
                    ->withOptions(['allow_redirects' => false, 'verify' => true])
                    ->connectTimeout(3)->timeout(8)->get('https://graph.facebook.com/'.$version.'/debug_token',
                        ['input_token' => $token, 'appsecret_proof' => hash_hmac('sha256', $appToken, $secret)]);
                $data = strlen($debug->body()) <= 65536 ? $debug->json() : null;
                $checked = $data['data'] ?? null;
                if ($debug->successful() && is_array($checked)) {
                    $info['token_verified'] = ($checked['is_valid'] ?? false) === true
                        && (string) ($checked['app_id'] ?? '') === '2177982783065133';
                    if ($info['token_verified'] && is_array($checked['scopes'] ?? null)) {
                        $info['permission_check'] = 'checked';
                        $info['missing_permissions'] = array_values(array_diff($permissions, $checked['scopes']));
                    }
                }
            } catch (\Throwable $error) { /* Return fixed capability status only. */ }
        }
        $result['accounts'][] = $info;
    }
    $result['check_completed'] = true;
    $exit = 0;
} catch (\Throwable $error) { $result['reason'] = 'DIAGNOSTIC_UNAVAILABLE'; }
while (ob_get_level() > $level) ob_end_clean();
echo 'META_COMMENTS_ACCESS='.json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($exit);
