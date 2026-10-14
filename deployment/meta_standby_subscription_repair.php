<?php
// CLI-only maintenance for the exact Fasakhansta Messenger integration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();

final class FasakhanstaStandbyFailure extends RuntimeException {}
final class FasakhanstaStandbyRepair
{
    private const APP = '2177982783065133';
    private const PAGE = '118160256722323';
    private const HOOK_VERSION = 'v26.0';
    private $secret;
    private $token;
    private $verify;
    private $pageVersion;
    private $callback;
    private $started;
    private $requests = 0;
    private $out;

    public function __construct(array $account, string $secret, string $verify, string $version, string $callback)
    {
        $this->out = ['operation' => 'META_MESSENGER_STANDBY_SUBSCRIPTION', 'status' => 'BLOCKED',
            'phase' => 'CONFIG', 'local_writes' => 0, 'graph_post_attempts' => 0,
            'graph_updates_confirmed' => 0, 'app_id' => self::APP, 'page_id' => self::PAGE];
        $this->started = microtime(true);
        $this->check(($account['channel'] ?? null) === 'messenger'
            && ($account['account_id'] ?? null) === self::PAGE, 'ACCOUNT_MISMATCH');
        $token = $account['access_token'] ?? null;
        $this->check(is_string($token) && $token !== '' && strlen($token) <= 8192
            && !preg_match('/[\s\x00-\x1f\x7f]/', $token), 'PAGE_TOKEN_UNAVAILABLE');
        $this->check(preg_match('/\A[a-f0-9]{32}\z/i', $secret) === 1, 'APP_SECRET_UNAVAILABLE');
        $this->check($verify !== '' && strlen($verify) <= 8192
            && !preg_match('/[\x00-\x1f\x7f]/', $verify), 'VERIFY_TOKEN_UNAVAILABLE');
        $this->check($version === 'v25.0', 'PAGE_API_VERSION_CHANGED');
        $this->check(rtrim($callback, '/') === 'https://fasakhaninja.com/api/meta/messaging/webhook',
            'EXPECTED_CALLBACK_MISMATCH');
        $this->token = $token; $this->secret = $secret; $this->verify = $verify;
        $this->pageVersion = $version; $this->callback = $callback;
    }

    private function check(bool $ok, string $reason): void
    {
        if (!$ok) throw new FasakhanstaStandbyFailure($reason);
    }

    private function request(string $method, string $version, string $path, string $token, array $data = []): array
    {
        $this->check($this->requests < 12 && microtime(true) - $this->started < 60, 'REQUEST_BUDGET');
        $this->requests++;
        $bytes = '';
        $curl = curl_init('https://graph.facebook.com/'.$version.'/'.$path);
        $this->check($curl !== false, 'CURL_UNAVAILABLE');
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $method === 'POST' ? 15 : 8,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$bytes) {
                if (strlen($bytes) + strlen($chunk) > 65536) return 0;
                $bytes .= $chunk; return strlen($chunk);
            },
        ]);
        if ($method === 'POST') {
            $this->out['graph_post_attempts']++;
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986)]);
        } else curl_setopt($curl, CURLOPT_HTTPGET, true);
        try {
            $ok = curl_exec($curl);
            $http = $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally { curl_close($curl); }
        try { $json = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new FasakhanstaStandbyFailure('GRAPH_RESPONSE_UNREADABLE'); }
        if ($http !== 200 || !is_array($json) || isset($json['error'])) {
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $this->out['graph_error'] = ['http_status' => $http,
                'code' => is_int($error['code'] ?? null) ? $error['code'] : null,
                'subcode' => is_int($error['error_subcode'] ?? null) ? $error['error_subcode'] : null];
            throw new FasakhanstaStandbyFailure('GRAPH_REQUEST_REJECTED');
        }
        return $json;
    }

    private function rows(array $json): array
    {
        $rows = $json['data'] ?? null;
        $this->check(is_array($rows) && array_is_list($rows) && count($rows) <= 100
            && is_array($json['paging'] ?? []) && !isset($json['paging']['next']), 'INCOMPLETE_GRAPH_LIST');
        foreach ($rows as $row) $this->check(is_array($row), 'INVALID_GRAPH_ROW');
        return $rows;
    }

    private function appState(): array
    {
        $json = $this->request('GET', self::HOOK_VERSION, self::APP.'/subscriptions', self::APP.'|'.$this->secret);
        $matches = array_values(array_filter($this->rows($json), static fn ($s) => ($s['object'] ?? null) === 'page'));
        $this->check(count($matches) === 1, 'APP_PAGE_SUBSCRIPTION_COUNT');
        $row = $matches[0];
        $this->check(($row['active'] ?? null) === true, 'APP_PAGE_SUBSCRIPTION_INACTIVE');
        $this->check(is_string($row['callback_url'] ?? null)
            && rtrim($row['callback_url'], '/') === rtrim($this->callback, '/'), 'CALLBACK_CHANGED');
        $fields = $row['fields'] ?? null;
        $this->check(is_array($fields) && array_is_list($fields) && count($fields) <= 100, 'INVALID_APP_FIELDS');
        $versions = [];
        foreach ($fields as $f) {
            $this->check(is_array($f) && is_string($f['name'] ?? null)
                && preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $f['name']) === 1, 'INVALID_APP_FIELD');
            $this->check(!array_key_exists($f['name'], $versions)
                && ($f['version'] ?? null) === self::HOOK_VERSION, 'WEBHOOK_VERSION_CHANGED');
            $versions[$f['name']] = $f['version'];
        }
        $this->check(isset($versions['messages'], $versions['message_echoes']), 'BASE_APP_FIELDS_MISSING');
        ksort($versions);
        return ['fields' => $versions, 'include_values' =>
            is_bool($row['include_values'] ?? null) ? $row['include_values'] : null];
    }

    private function pageState(): array
    {
        $json = $this->request('GET', $this->pageVersion,
            self::PAGE.'/subscribed_apps?fields=id,subscribed_fields&limit=100', $this->token);
        $matches = array_values(array_filter($this->rows($json),
            static fn ($s) => ($s['id'] ?? null) === self::APP));
        $this->check(count($matches) === 1, 'PAGE_APP_SUBSCRIPTION_COUNT');
        $fields = $matches[0]['subscribed_fields'] ?? null;
        $this->check(is_array($fields) && array_is_list($fields) && count($fields) <= 100, 'INVALID_PAGE_FIELDS');
        foreach ($fields as $f) $this->check(is_string($f)
            && preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $f) === 1, 'INVALID_PAGE_FIELD');
        $this->check(count($fields) === count(array_unique($fields)), 'DUPLICATE_PAGE_FIELDS');
        $this->check(in_array('messages', $fields, true) && in_array('message_echoes', $fields, true),
            'BASE_PAGE_FIELDS_MISSING');
        sort($fields);
        return $fields;
    }

    private function assertAppContains(array $state, array $expected): void
    {
        foreach ($expected as $name) $this->check(($state['fields'][$name] ?? null) === self::HOOK_VERSION,
            'APP_FIELD_READBACK_FAILED');
    }

    public function run(bool $apply): array
    {
        try {
            $this->out['phase'] = 'TOKEN_IDENTITY';
            $debug = $this->request('GET', $this->pageVersion,
                'debug_token?input_token='.rawurlencode($this->token), self::APP.'|'.$this->secret);
            $d = $debug['data'] ?? null;
            $this->check(is_array($d) && ($d['is_valid'] ?? null) === true
                && ($d['app_id'] ?? null) === self::APP && ($d['type'] ?? null) === 'PAGE'
                && ($d['profile_id'] ?? null) === self::PAGE, 'PAGE_TOKEN_IDENTITY_MISMATCH');
            $scopes = $d['scopes'] ?? null;
            $this->check(is_array($scopes) && in_array('pages_manage_metadata', $scopes, true)
                && in_array('pages_messaging', $scopes, true), 'PAGE_TOKEN_SCOPES_MISSING');
            $me = $this->request('GET', $this->pageVersion, 'me?fields=id', $this->token);
            $this->check(($me['id'] ?? null) === self::PAGE, 'PAGE_TOKEN_ASSET_MISMATCH');
            $this->out['phase'] = 'SUBSCRIPTION_PREFLIGHT';
            $beforeApp = $this->appState(); $beforePage = $this->pageState();
            $appFields = array_keys($beforeApp['fields']);
            $appFields[] = 'standby'; $appFields = array_values(array_unique($appFields)); sort($appFields);
            $pageFields = $beforePage; $pageFields[] = 'standby';
            $pageFields = array_values(array_unique($pageFields)); sort($pageFields);
            $this->out['before'] = ['app_fields' => $beforeApp['fields'], 'page_fields' => $beforePage];
            $this->out['planned'] = ['webhook_version' => self::HOOK_VERSION,
                'page_api_version' => $this->pageVersion, 'app_fields' => $appFields, 'page_fields' => $pageFields];
            if (!$apply) { $this->out['status'] = 'READY_TO_APPLY'; return $this->out; }

            if (!isset($beforeApp['fields']['standby'])) {
                $this->out['phase'] = 'APP_SUBSCRIPTION_ADD_STANDBY';
                $data = ['object' => 'page', 'callback_url' => $this->callback,
                    'verify_token' => $this->verify, 'fields' => implode(',', $appFields)];
                if ($beforeApp['include_values'] !== null) $data['include_values'] = $beforeApp['include_values'] ? 'true' : 'false';
                $reply = $this->request('POST', self::HOOK_VERSION, self::APP.'/subscriptions',
                    self::APP.'|'.$this->secret, $data);
                $this->check(($reply['success'] ?? null) === true, 'APP_SUBSCRIPTION_NOT_ACKNOWLEDGED');
                $this->out['phase'] = 'APP_SUBSCRIPTION_READBACK';
                $afterApp = $this->appState(); $this->assertAppContains($afterApp, $appFields);
                if ($beforeApp['include_values'] !== null) $this->check(
                    $afterApp['include_values'] === $beforeApp['include_values'], 'INCLUDE_VALUES_CHANGED');
                $this->out['graph_updates_confirmed']++;
                $this->out['app_after'] = $afterApp['fields'];
            }
            $this->out['phase'] = 'APP_SUBSCRIPTION_VERIFIED';
            $currentApp = $this->appState(); $this->assertAppContains($currentApp, $appFields);
            $currentPage = $this->pageState();
            $this->check($currentPage === $beforePage, 'PAGE_FIELDS_CHANGED_DURING_PREFLIGHT');
            if (!in_array('standby', $beforePage, true)) {
                $this->out['phase'] = 'PAGE_SUBSCRIPTION_ADD_STANDBY';
                $reply = $this->request('POST', $this->pageVersion, self::PAGE.'/subscribed_apps',
                    $this->token, ['subscribed_fields' => implode(',', $pageFields)]);
                $this->check(($reply['success'] ?? null) === true, 'PAGE_SUBSCRIPTION_NOT_ACKNOWLEDGED');
                $this->out['phase'] = 'PAGE_SUBSCRIPTION_READBACK';
                $afterPage = $this->pageState();
                $this->check(array_diff($pageFields, $afterPage) === [], 'PAGE_FIELD_READBACK_FAILED');
                $this->out['graph_updates_confirmed']++;
                $this->out['page_after'] = $afterPage;
            }
            $this->out['phase'] = 'FINAL_READBACK';
            $finalApp = $this->appState(); $finalPage = $this->pageState();
            $this->assertAppContains($finalApp, $appFields);
            $this->check(array_diff($pageFields, $finalPage) === [], 'FINAL_PAGE_FIELD_READBACK_FAILED');
            $this->out['after'] = ['app_fields' => $finalApp['fields'], 'page_fields' => $finalPage];
            $this->out['status'] = 'VERIFIED'; $this->out['phase'] = 'COMPLETE';
        } catch (Throwable $e) {
            $this->out['reason'] = $e instanceof FasakhanstaStandbyFailure ? $e->getMessage() : 'RUNTIME_FAILURE';
        }
        return $this->out;
    }
}

try {
    $args = array_slice($_SERVER['argv'] ?? [], 1);
    if ($args !== [] && $args !== ['--apply']) throw new FasakhanstaStandbyFailure('INVALID_ARGUMENTS');
    $root = getcwd();
    if (!is_string($root) || !is_file($root.'/vendor/autoload.php') || !is_file($root.'/bootstrap/app.php'))
        throw new FasakhanstaStandbyFailure('APPLICATION_ROOT_UNAVAILABLE');
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $account = App\Services\Dashboard\MetaInboxAccess::account('messenger', '118160256722323');
    if (!is_array($account)) throw new FasakhanstaStandbyFailure('ACCOUNT_UNAVAILABLE');
    $task = new FasakhanstaStandbyRepair($account, (string) config('meta_messaging.app_secret', ''),
        (string) config('meta_messaging.verify_token', ''), (string) config('meta_messaging.api_version', ''),
        rtrim((string) config('app.url', ''), '/').'/api/meta/messaging/webhook');
    $out = $task->run($args === ['--apply']);
} catch (Throwable $e) {
    $out = ['operation' => 'META_MESSENGER_STANDBY_SUBSCRIPTION', 'status' => 'BLOCKED',
        'local_writes' => 0, 'reason' => $e instanceof FasakhanstaStandbyFailure ? $e->getMessage() : 'BOOTSTRAP_FAILED'];
}
while (ob_get_level() > 0) ob_end_clean();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'] ?? null, ['VERIFIED', 'READY_TO_APPLY'], true) ? 0 : 1);
