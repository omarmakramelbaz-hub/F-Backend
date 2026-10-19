<?php

namespace App\Services\Dashboard;

/** Owned-business Facebook Login. Discovery is read-only; activation follows explicit selection. */
class MetaOAuthService
{
    private const MAX_BODY = 262144;
    private const MAX_REQUESTS = 25;
    private const MAX_SECONDS = 60;
    private const REQUIRED_PAGE_FIELDS = ['messages', 'messaging_postbacks', 'message_deliveries', 'message_reads'];
    private MetaOAuthCredentials $credentials;
    private $transport;
    private int $requests = 0;
    private float $started = 0;

    public function __construct(MetaOAuthCredentials $credentials, ?callable $transport = null)
    {
        $this->credentials = $credentials; $this->transport = $transport;
    }

    public function settings(): array { return $this->credentials->settings(); }

    public function authorizationUrl(string $state): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $state)) throw new MetaOAuthFailure('INVALID_AUTHORIZATION');
        $settings = $this->settings();
        return 'https://www.facebook.com/'.$settings['api_version'].'/dialog/oauth?'.http_build_query([
            'client_id' => $settings['app_id'], 'redirect_uri' => $settings['redirect_uri'],
            'config_id' => $settings['config_id'], 'response_type' => 'code',
            'override_default_response_type' => 'true', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Tokens returned here belong only in the controller's encrypted, actor-bound pending session. */
    public function discover(string $code): array
    {
        if ($code === '' || strlen($code) > 4096 || preg_match('/[^\x21-\x7e]/', $code))
            throw new MetaOAuthFailure('INVALID_AUTHORIZATION');
        $this->begin(); $settings = $this->settings(); $secret = $this->secret();
        $first = $this->graph('GET', $settings, 'oauth/access_token', [
            'client_id' => $settings['app_id'], 'client_secret' => $secret,
            'redirect_uri' => $settings['redirect_uri'], 'code' => $code,
        ]);
        $shortToken = $first['access_token'] ?? null;
        if (!$this->token($shortToken)) throw new MetaOAuthFailure('META_REJECTED');
        // The configured Login for Business integration issues a user token. Obtain durable Page tokens.
        $long = $this->graph('GET', $settings, 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $settings['app_id'],
            'client_secret' => $secret, 'fb_exchange_token' => $shortToken,
        ]);
        $userToken = $long['access_token'] ?? null;
        if (!$this->token($userToken)) throw new MetaOAuthFailure('META_REJECTED');
        $me = $this->graph('GET', $settings, 'me', ['fields' => 'id,name'], $userToken);
        if (!$this->id($me['id'] ?? null)) throw new MetaOAuthFailure('META_REJECTED');
        $debug = $this->graph('GET', $settings, 'debug_token', ['input_token' => $userToken],
            $settings['app_id'].'|'.$secret);
        $valid = $debug['data'] ?? null;
        if (!is_array($valid) || ($valid['is_valid'] ?? null) !== true || ($valid['app_id'] ?? null) !== $settings['app_id']
            || ($valid['type'] ?? null) !== 'USER' || ($valid['user_id'] ?? null) !== $me['id'])
            throw new MetaOAuthFailure('META_REJECTED');
        $allowed = $this->allowed(); $found = []; $cursor = null; $seenCursors = []; $seenPages = [];
        for ($page = 0; $page < 10; $page++) {
            $params = ['fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => '100'];
            if ($cursor !== null) $params['after'] = $cursor;
            $list = $this->graph('GET', $settings, 'me/accounts', $params, $userToken);
            $rows = $this->rows($list, 100, 'META_REJECTED');
            foreach ($rows as $row) {
                if (!$this->id($row['id'] ?? null) || isset($seenPages[$row['id']]))
                    throw new MetaOAuthFailure('META_REJECTED');
                $pageId = $row['id']; $seenPages[$pageId] = true;
                $messenger = $allowed['messenger:'.$pageId] ?? null;
                $linked = is_array($row['instagram_business_account'] ?? null) ? $row['instagram_business_account'] : [];
                $igId = $linked['id'] ?? null;
                $instagram = $this->id($igId) ? ($allowed['instagram:'.$igId] ?? null) : null;
                if ($instagram !== null && !$this->pageAllowedForInstagram($instagram, $pageId, $allowed)) $instagram = null;
                if ($messenger === null && $instagram === null) continue;
                $token = $row['access_token'] ?? null;
                if (!$this->token($token)) throw new MetaOAuthFailure('META_REJECTED');
                if ($messenger !== null) $found['messenger:'.$pageId] = [
                    'channel' => 'messenger', 'account_id' => $pageId, 'page_id' => $pageId,
                    'label' => $this->label($row['name'] ?? null, $messenger['label'] ?? 'Messenger'),
                    'access_token' => $token, 'login' => 'facebook',
                ];
                if ($instagram !== null) {
                    $username = $linked['username'] ?? null;
                    $label = is_string($username) && preg_match('/\A[A-Za-z0-9._]{1,30}\z/', $username)
                        ? '@'.$username : ($instagram['label'] ?? 'Instagram');
                    $found['instagram:'.$igId] = ['channel' => 'instagram', 'account_id' => $igId,
                        'page_id' => $pageId, 'label' => $this->label($label, 'Instagram'),
                        'access_token' => $token, 'login' => 'facebook'];
                }
            }
            $paging = $list['paging'] ?? [];
            if (!is_array($paging)) throw new MetaOAuthFailure('META_REJECTED');
            if (!isset($paging['next'])) break;
            // Never follow Meta's returned next URL: rebuild the fixed endpoint with a bounded opaque cursor.
            $cursor = $paging['cursors']['after'] ?? null;
            if (!is_string($cursor) || !preg_match('/\A[A-Za-z0-9_+\/=-]{1,2048}\z/', $cursor)
                || isset($seenCursors[$cursor]) || $page === 9) throw new MetaOAuthFailure('META_REJECTED');
            $seenCursors[$cursor] = true;
        }
        if (!$found) throw new MetaOAuthFailure('NO_ALLOWED_ACCOUNTS');
        return ['accounts' => array_values($found), 'facebook_user_id' => $me['id'],
            'authorized_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /** Subscribe selected owned assets, verify, then atomically save only their encrypted credentials. */
    public function activate(array $pending, array $selected): array
    {
        $this->begin(); $settings = $this->settings(); $this->secret();
        $allowed = $this->allowed(); $choices = [];
        if (!$this->id($pending['facebook_user_id'] ?? null) || !is_string($pending['authorized_at'] ?? null)
            || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $pending['authorized_at']))
            throw new MetaOAuthFailure('INVALID_SELECTION');
        $authorized = strtotime($pending['authorized_at']);
        if ($authorized === false || $authorized < time() - 600 || $authorized > time() + 60)
            throw new MetaOAuthFailure('INVALID_SELECTION');
        $accounts = $pending['accounts'] ?? null;
        if (!is_array($accounts) || !$this->isList($accounts) || count($accounts) > 100)
            throw new MetaOAuthFailure('INVALID_SELECTION');
        foreach ($accounts as $account) {
            if (!is_array($account) || !in_array($account['channel'] ?? null, ['messenger', 'instagram'], true)
                || !$this->id($account['account_id'] ?? null) || !$this->id($account['page_id'] ?? null)
                || ($account['login'] ?? null) !== 'facebook' || !$this->token($account['access_token'] ?? null))
                throw new MetaOAuthFailure('INVALID_SELECTION');
            $key = $account['channel'].':'.$account['account_id'];
            if (isset($choices[$key])) throw new MetaOAuthFailure('INVALID_SELECTION');
            $choices[$key] = $account;
        }
        if (!$this->isList($selected) || !$selected || count($selected) > 20 || count($selected) !== count(array_unique($selected, SORT_REGULAR)))
            throw new MetaOAuthFailure('INVALID_SELECTION');
        $chosen = []; $pages = [];
        foreach ($selected as $key) {
            if (!is_string($key) || !isset($choices[$key], $allowed[$key])) throw new MetaOAuthFailure('ACCOUNT_CHANGED');
            $account = $choices[$key]; $current = $allowed[$key];
            if ($account['channel'] === 'messenger' && $account['account_id'] !== $account['page_id'])
                throw new MetaOAuthFailure('ACCOUNT_CHANGED');
            if ($account['channel'] === 'instagram' && !$this->pageAllowedForInstagram($current, $account['page_id'], $allowed))
                throw new MetaOAuthFailure('ACCOUNT_CHANGED');
            $pageId = $account['page_id'];
            if (isset($pages[$pageId]) && !hash_equals($pages[$pageId]['token'], $account['access_token']))
                throw new MetaOAuthFailure('ACCOUNT_CHANGED');
            if (!isset($pages[$pageId])) $pages[$pageId] = ['token' => $account['access_token'], 'instagram' => null, 'messenger' => false];
            if ($account['channel'] === 'messenger') $pages[$pageId]['messenger'] = true;
            if ($account['channel'] === 'instagram') $pages[$pageId]['instagram'] = $account['account_id'];
            $account['label'] = $this->label($account['label'] ?? null, $current['label'] ?? ucfirst($account['channel']));
            $chosen[] = $account;
        }
        // Read every selected token's actual issuing Page and IG link before any subscription write.
        foreach ($pages as $pageId => &$page) {
            $pageId = (string) $pageId;
            $debug = $this->graph('GET', $settings, 'debug_token', ['input_token' => $page['token']],
                $settings['app_id'].'|'.$this->secret());
            $valid = $debug['data'] ?? null;
            $required = ['pages_manage_metadata'];
            if ($page['messenger']) $required[] = 'pages_messaging';
            if ($page['instagram'] !== null) $required = array_merge($required, ['instagram_basic', 'instagram_manage_messages']);
            if (!is_array($valid) || ($valid['is_valid'] ?? null) !== true || ($valid['app_id'] ?? null) !== $settings['app_id']
                || ($valid['type'] ?? null) !== 'PAGE' || ($valid['profile_id'] ?? null) !== $pageId
                || !is_array($valid['scopes'] ?? null) || array_diff($required, $valid['scopes']))
                throw new MetaOAuthFailure('META_REJECTED');
            $fields = $page['instagram'] === null ? 'id' : 'id,instagram_business_account{id}';
            $identity = $this->graph('GET', $settings, 'me', ['fields' => $fields], $page['token']);
            if (($identity['id'] ?? null) !== $pageId || ($page['instagram'] !== null
                && ($identity['instagram_business_account']['id'] ?? null) !== $page['instagram']))
                throw new MetaOAuthFailure('ACCOUNT_CHANGED');
            $page['before'] = $this->subscriptionFields($settings, $pageId, $page['token']);
        }
        unset($page);
        try {
            foreach ($pages as $pageId => $page) {
                $fields = array_values(array_unique(array_merge($page['before'], self::REQUIRED_PAGE_FIELDS))); sort($fields);
                // Facebook-login IG messaging belongs to its linked Page; no Instagram-login edge is called.
                if (array_diff($fields, $page['before'])) {
                    $fresh = $this->subscriptionFields($settings, (string) $pageId, $page['token']);
                    $before = $page['before']; sort($before); sort($fresh);
                    if ($fresh !== $before) throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
                    $answer = $this->graph('POST', $settings, (string) $pageId.'/subscribed_apps',
                        ['subscribed_fields' => implode(',', $fields)], $page['token']);
                    if (($answer['success'] ?? null) !== true) throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
                }
                $after = $this->subscriptionFields($settings, (string) $pageId, $page['token']);
                if (array_diff($fields, $after)) throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
            }
        } catch (\Throwable $error) {
            // A timed-out POST may have reached Meta. Keep local credentials unchanged and report uncertainty.
            throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
        }
        $bundle = ['accounts' => $chosen, 'facebook_user_id' => $pending['facebook_user_id'],
            'authorized_at' => $pending['authorized_at']];
        try { $this->credentials->save($bundle); }
        catch (\Throwable $error) { throw new MetaOAuthFailure('CREDENTIALS_SAVE_FAILED'); }
        foreach ($bundle['accounts'] as &$account) unset($account['access_token']);
        unset($account);
        return $bundle;
    }

    private function subscriptionFields(array $settings, string $page, string $token): array
    {
        $body = $this->graph('GET', $settings, $page.'/subscribed_apps',
            ['fields' => 'id,subscribed_fields', 'limit' => '100'], $token);
        $rows = $this->rows($body, 100, 'SUBSCRIPTION_FAILED');
        if (!is_array($body['paging'] ?? []) || isset($body['paging']['next'])) throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
        $found = null;
        foreach ($rows as $row) {
            if (!$this->id($row['id'] ?? null)) throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
            if ($row['id'] !== $settings['app_id']) continue;
            if ($found !== null || !is_array($row['subscribed_fields'] ?? null)
                || !$this->isList($row['subscribed_fields']) || count($row['subscribed_fields']) > 100)
                throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
            $found = $row['subscribed_fields'];
            foreach ($found as $field) if (!is_string($field) || !preg_match('/\A[a-z][a-z0-9_]{0,99}\z/', $field))
                throw new MetaOAuthFailure('SUBSCRIPTION_FAILED');
        }
        return $found ?? [];
    }

    private function allowed(): array
    {
        $out = [];
        foreach (MetaInboxAccess::accounts() as $account) {
            $key = $account['channel'].':'.$account['account_id'];
            if (isset($out[$key])) throw new MetaOAuthFailure('ACCOUNT_CHANGED');
            $out[$key] = $account;
        }
        return $out;
    }

    private function pageAllowedForInstagram(array $account, string $page, array $allowed): bool
    {
        // Existing explicit links take precedence; otherwise require the Page already configured for Messenger.
        return isset($account['page_id']) ? $account['page_id'] === $page : isset($allowed['messenger:'.$page]);
    }

    private function begin(): void { $this->requests = 0; $this->started = microtime(true); }

    private function secret(): string
    {
        $secret = config('meta_messaging.app_secret', '');
        if (!is_string($secret) || !preg_match('/\A[a-f0-9]{32}\z/i', $secret)) throw new MetaOAuthFailure('OAUTH_NOT_CONFIGURED');
        return $secret;
    }

    /** No Laravel HTTP client/listener, redirects, raw exception causes or Graph diagnostic text. */
    private function graph(string $method, array $settings, string $path, array $params, ?string $token = null): array
    {
        if (!in_array($method, ['GET', 'POST'], true) || !preg_match('/\A(?:oauth\/access_token|debug_token|me(?:\/accounts)?|[1-9][0-9]{0,31}\/subscribed_apps)\z/', $path)
            || $this->requests >= self::MAX_REQUESTS || microtime(true) - $this->started >= self::MAX_SECONDS)
            throw new MetaOAuthFailure('META_UNAVAILABLE');
        $this->requests++;
        if ($token !== null) {
            if (!$this->token($token)) throw new MetaOAuthFailure('META_UNAVAILABLE');
            $params['appsecret_proof'] = hash_hmac('sha256', $token, $this->secret());
        }
        $url = 'https://graph.facebook.com/'.$settings['api_version'].'/'.$path;
        try {
            if ($this->transport !== null) $reply = ($this->transport)($method, $url, $params, $token);
            else $reply = $this->network($method, $url, $params, $token);
            if (!is_array($reply) || !is_int($reply['status'] ?? null) || !is_string($reply['body'] ?? null)
                || strlen($reply['body']) > self::MAX_BODY) throw new MetaOAuthFailure('META_UNAVAILABLE');
            $body = json_decode($reply['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body)) throw new MetaOAuthFailure('META_UNAVAILABLE');
            if ($reply['status'] !== 200 || isset($body['error'])) throw new MetaOAuthFailure(
                in_array($reply['status'], [400, 401, 403, 404, 422, 429], true) ? 'META_REJECTED' : 'META_UNAVAILABLE');
            return $body;
        } catch (MetaOAuthFailure $error) { throw $error; }
        catch (\Throwable $error) { throw new MetaOAuthFailure('META_UNAVAILABLE'); }
    }

    private function network(string $method, string $url, array $params, ?string $token): array
    {
        if (!function_exists('curl_init')) throw new MetaOAuthFailure('META_UNAVAILABLE');
        $encoded = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($method === 'GET') $url .= '?'.$encoded;
        $curl = curl_init($url); if ($curl === false) throw new MetaOAuthFailure('META_UNAVAILABLE');
        $body = ''; $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer '.$token;
        $remaining = max(1, min(10, (int) ceil(self::MAX_SECONDS - (microtime(true) - $this->started))));
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => min(3, $remaining), CURLOPT_TIMEOUT => $remaining,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded]);
        else curl_setopt($curl, CURLOPT_HTTPGET, true);
        try {
            $ok = curl_exec($curl);
            return ['status' => $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $ok === false ? '' : $body];
        } finally { curl_close($curl); }
    }

    private function rows(array $body, int $maximum, string $reason): array
    {
        $rows = $body['data'] ?? null;
        if (!is_array($rows) || !$this->isList($rows) || count($rows) > $maximum)
            throw new MetaOAuthFailure($reason);
        foreach ($rows as $row) if (!is_array($row)) throw new MetaOAuthFailure($reason);
        return $rows;
    }

    private function id($id): bool { return is_string($id) && preg_match('/\A[1-9][0-9]{0,31}\z/', $id) === 1; }

    private function token($token): bool
    {
        return is_string($token) && strlen($token) >= 16 && strlen($token) <= 4096 && !preg_match('/[^\x21-\x7e]/', $token);
    }

    private function label($value, $fallback): string
    {
        foreach ([$value, $fallback] as $label) if (is_string($label) && strlen($label) <= 800
            && mb_check_encoding($label, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $label) && trim($label) !== '')
            return mb_substr(trim($label), 0, 100, 'UTF-8');
        return 'Meta';
    }

    private function isList(array $value): bool { return $value === [] || array_keys($value) === range(0, count($value) - 1); }
}
