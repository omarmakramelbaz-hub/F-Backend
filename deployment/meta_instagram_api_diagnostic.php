<?php
// CLI-only fixed Graph GET diagnostics; no messages, routing, subscriptions or database writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
$out = ['diagnostic' => 'READ_ONLY_INSTAGRAM_API', 'writes' => 0, 'status' => 'FAILED',
    'phase' => 'BOOTSTRAP', 'requests' => 0];
try {
    $root = getcwd();
    if (!is_string($root) || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php'))
        throw new RuntimeException();
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $appId = '2177982783065133'; $pageId = '118160256722323'; $igId = '17841443096102732';
    $out['phase'] = 'CONFIG';
    $account = App\Services\Dashboard\MetaInboxAccess::account('instagram', $igId);
    $token = is_array($account) ? ($account['access_token'] ?? null) : null;
    $secret = (string) config('meta_messaging.app_secret', '');
    $version = (string) config('meta_messaging.api_version', '');
    if (!is_array($account) || ($account['channel'] ?? null) !== 'instagram'
        || ($account['account_id'] ?? null) !== $igId || ($account['login'] ?? 'facebook') !== 'facebook'
        || !is_string($token) || $token === '' || strlen($token) > 8192
        || preg_match('/[\s\x00-\x1f\x7f]/', $token)
        || preg_match('/\A[a-f0-9]{32}\z/i', $secret) !== 1
        || preg_match('/\Av[0-9]{1,2}\.0\z/', $version) !== 1 || !function_exists('curl_init'))
        throw new RuntimeException();
    $out['api_version'] = $version; $out['app_id'] = $appId;
    $out['page_id'] = $pageId; $out['instagram_id'] = $igId;
    $get = static function (string $label, string $path, array $query, string $key) use ($version, &$out): ?array {
        if ($out['requests'] >= 4) throw new RuntimeException();
        $out['requests']++; $out['phase'] = $label;
        $bytes = '';
        $curl = curl_init('https://graph.facebook.com/'.$version.'/'.$path.'?'
            .http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if ($curl === false) throw new RuntimeException();
        curl_setopt_array($curl, [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$bytes) {
                if (strlen($bytes) + strlen($chunk) > 65536) return 0;
                $bytes .= $chunk; return strlen($chunk);
            }]);
        try { $ok = curl_exec($curl); $http = $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); }
        finally { curl_close($curl); }
        try { $json = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { $json = null; }
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $ready = $http === 200 && is_array($json) && !isset($json['error']);
        $out[$label] = ['status' => $ready ? 'READY' : ($http === 0 ? 'TRANSPORT_FAILED'
            : (!is_array($json) ? 'INVALID_RESPONSE' : 'API_REJECTED')),
            'http_status' => $http, 'error_code' => is_int($error['code'] ?? null) ? $error['code'] : null,
            'error_subcode' => is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null];
        return $ready ? $json : null;
    };
    $j = $get('token_debug', 'debug_token', ['input_token' => $token], $appId.'|'.$secret);
    if ($j !== null) {
        $d = $j['data'] ?? null;
        if (!is_array($d)) $out['token_debug']['status'] = 'INVALID_RESPONSE';
        else {
            $out['token_debug']['token_valid'] = ($d['is_valid'] ?? null) === true;
            $out['token_debug']['app_matches'] = ($d['app_id'] ?? null) === $appId;
            $out['token_debug']['is_page_token'] = ($d['type'] ?? null) === 'PAGE';
            $out['token_debug']['profile_matches_page'] = ($d['profile_id'] ?? null) === $pageId;
            $scopes = is_array($d['scopes'] ?? null) ? $d['scopes'] : [];
            foreach (['instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata', 'pages_messaging'] as $scope)
                $out['token_debug']['scopes'][$scope] = in_array($scope, $scopes, true);
        }
    }
    $j = $get('instagram_identity', $igId, ['fields' => 'id,username'], $token);
    if ($j !== null) {
        $name = $j['username'] ?? null;
        $out['instagram_identity']['configured_id_matches'] = ($j['id'] ?? null) === $igId;
        $out['instagram_identity']['username'] = is_string($name)
            && preg_match('/\A[A-Za-z0-9._]{1,30}\z/', $name) === 1 ? $name : null;
        $out['instagram_identity']['username_matches_fasakhansta'] = is_string($name)
            && strcasecmp($name, 'fasakhansta') === 0;
    }
    $j = $get('page_link', $pageId, ['fields' => 'id,instagram_business_account'], $token);
    if ($j !== null) {
        $linked = $j['instagram_business_account'] ?? null;
        $out['page_link']['page_id_matches'] = ($j['id'] ?? null) === $pageId;
        $out['page_link']['linked_instagram_present'] = is_array($linked) && is_string($linked['id'] ?? null);
        $out['page_link']['linked_instagram_matches'] = is_array($linked) && ($linked['id'] ?? null) === $igId;
    }
    $j = $get('instagram_conversations', $pageId.'/conversations',
        ['platform' => 'INSTAGRAM', 'fields' => 'id,updated_time', 'limit' => 1], $token);
    if ($j !== null) {
        $rows = $j['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1 || !is_array($j['paging'] ?? []))
            $out['instagram_conversations']['status'] = 'INVALID_RESPONSE';
        else {
            $out['instagram_conversations']['returned_count'] = count($rows);
            $out['instagram_conversations']['has_next'] = isset($j['paging']['next']);
            $time = $rows[0]['updated_time'] ?? null;
            $out['instagram_conversations']['latest_returned_update'] = is_string($time)
                && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{4}|[+-][0-9]{2}:[0-9]{2})\z/', $time)
                ? $time : null;
        }
    }
    $out['status'] = 'COMPLETE';
    foreach (['token_debug', 'instagram_identity', 'page_link', 'instagram_conversations'] as $label)
        if (($out[$label]['status'] ?? null) !== 'READY') $out['status'] = 'PARTIAL';
    $out['phase'] = 'COMPLETE'; $out['generated_at_utc'] = gmdate('Y-m-d H:i:s');
} catch (Throwable $error) {
    $out['status'] = 'FAILED'; $out['reason'] = 'CHECK_UNAVAILABLE';
}
while (ob_get_level() > 0) ob_end_clean();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit(($out['phase'] ?? null) === 'COMPLETE' ? 0 : 1);
