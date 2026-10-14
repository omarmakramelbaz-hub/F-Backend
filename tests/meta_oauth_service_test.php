<?php

namespace App\Services\Dashboard {
    // Isolated transport tests deliberately do not load Laravel, private files or live Graph.
    class MetaOAuthCredentials {
        public array $saved = [];
        public bool $failSave = false;
        public function settings(): array { return [
            'app_id' => '2177982783065133', 'config_id' => '3007667439572336',
            'redirect_uri' => 'https://fasakhaninja.com/admin/meta/connection/callback', 'api_version' => 'v25.0',
        ]; }
        public function save(array $bundle): void {
            if ($this->failSave) throw new \RuntimeException('PRIVATE_DISK_ERROR');
            $this->saved[] = $bundle;
        }
    }
    class MetaInboxAccess {
        public static array $configured = [];
        public static function accounts(): array { return self::$configured; }
    }
}

namespace {
    require __DIR__.'/../app/Services/Dashboard/MetaOAuthFailure.php';
    require __DIR__.'/../app/Services/Dashboard/MetaOAuthService.php';
    use App\Services\Dashboard\MetaOAuthCredentials;
    use App\Services\Dashboard\MetaOAuthFailure;
    use App\Services\Dashboard\MetaOAuthService;
    use App\Services\Dashboard\MetaInboxAccess;

    function config($key, $fallback = null) { return $key === 'meta_messaging.app_secret' ? str_repeat('a', 32) : $fallback; }
    $checks = 0;
    function check(bool $ok, string $name): void {
        global $checks;
        if (!$ok) throw new \RuntimeException($name);
        $checks++;
    }
    function rejects(callable $action, string $reason): void {
        try { $action(); } catch (MetaOAuthFailure $error) {
            check($error->reason() === $reason && $error->getPrevious() === null, 'fixed_failure_'.$reason); return;
        }
        throw new \RuntimeException('failure_not_raised');
    }
    function configured(): void {
        MetaInboxAccess::$configured = [
            ['channel' => 'messenger', 'account_id' => '101', 'label' => 'Fasakhansta', 'access_token' => 'OLD_PRIVATE_PAGE_TOKEN'],
            ['channel' => 'instagram', 'account_id' => '303', 'label' => 'Fasakhansta Instagram', 'access_token' => 'OLD_PRIVATE_IG_TOKEN', 'login' => 'facebook'],
        ];
    }
    function pending(): array {
        return ['facebook_user_id' => '909', 'authorized_at' => gmdate('Y-m-d\TH:i:s\Z'), 'accounts' => [
            ['channel' => 'messenger', 'account_id' => '101', 'page_id' => '101', 'label' => 'Fasakhansta',
                'access_token' => 'NEW_PRIVATE_PAGE_TOKEN', 'login' => 'facebook'],
            ['channel' => 'instagram', 'account_id' => '303', 'page_id' => '101', 'label' => '@fasakhansta',
                'access_token' => 'NEW_PRIVATE_PAGE_TOKEN', 'login' => 'facebook'],
        ]];
    }
    function jsonReply(array $value): array { return ['status' => 200, 'body' => json_encode($value, JSON_THROW_ON_ERROR)]; }
    function activationTransport(array &$calls, array &$fields, ?callable $override = null): callable {
        return static function ($method, $url, $params, $token) use (&$calls, &$fields, $override) {
            $calls[] = [$method, $url, $params, $token];
            if ($override !== null) { $result = $override($method, $url, $params, $token); if ($result !== null) return $result; }
            if (substr($url, -12) === '/debug_token') return jsonReply(['data' => [
                'is_valid' => true, 'app_id' => '2177982783065133', 'type' => 'PAGE', 'profile_id' => '101',
                'scopes' => ['pages_manage_metadata', 'pages_messaging', 'instagram_basic', 'instagram_manage_messages'],
            ]]);
            if (substr($url, -3) === '/me') return jsonReply(['id' => '101', 'instagram_business_account' => ['id' => '303']]);
            if ($url === 'https://graph.facebook.com/v25.0/101/subscribed_apps') {
                if ($method === 'POST') { $fields = explode(',', $params['subscribed_fields']); return jsonReply(['success' => true]); }
                return jsonReply(['data' => [['id' => '2177982783065133', 'subscribed_fields' => $fields],
                    ['id' => '808', 'subscribed_fields' => ['OTHER_APP_PRIVATE_FIELD']]]]);
            }
            throw new \RuntimeException('Unexpected endpoint PRIVATE_TOKEN');
        };
    }
    try {
        configured(); $credentials = new MetaOAuthCredentials; $calls = [];
        $service = new MetaOAuthService($credentials, static function ($method, $url, $params, $token) use (&$calls) {
            $calls[] = [$method, $url, $params, $token];
            if (substr($url, -19) === '/oauth/access_token') return jsonReply(['access_token' =>
                isset($params['code']) ? 'SHORT_PRIVATE_USER_TOKEN' : 'LONG_PRIVATE_USER_TOKEN']);
            if (substr($url, -3) === '/me') return jsonReply(['id' => '909', 'name' => 'PRIVATE_OPERATOR_NAME']);
            if (substr($url, -12) === '/debug_token') return jsonReply(['data' => [
                'is_valid' => true, 'app_id' => '2177982783065133', 'type' => 'USER', 'user_id' => '909',
            ]]);
            if (substr($url, -12) === '/me/accounts') return jsonReply(['data' => [
                ['id' => '404', 'name' => 'FOREIGN_PAGE', 'access_token' => 'FOREIGN_PRIVATE_PAGE_TOKEN', 'instagram_business_account' => ['id' => '303']],
                ['id' => '101', 'name' => 'Fasakhansta', 'access_token' => 'NEW_PRIVATE_PAGE_TOKEN', 'instagram_business_account' => ['id' => '303', 'username' => 'fasakhansta']],
            ]]);
            throw new \RuntimeException('unexpected');
        });
        parse_str(parse_url($service->authorizationUrl(str_repeat('a', 64)), PHP_URL_QUERY), $query);
        check(($query['config_id'] ?? null) === '3007667439572336' && !isset($query['scope'], $query['access_token']), 'business_config_no_scope_or_tokens');
        rejects(static fn () => $service->authorizationUrl('invalid'), 'INVALID_AUTHORIZATION');
        $result = $service->discover('PRIVATE_CODE');
        check(count($result['accounts']) === 2 && $result['accounts'][0]['account_id'] === '101'
            && $result['accounts'][1]['account_id'] === '303' && $result['accounts'][1]['page_id'] === '101', 'only_configured_owned_linked_assets');
        check($credentials->saved === [] && count($calls) === 5 && !array_filter($calls, static fn ($call) => $call[0] !== 'GET'), 'discovery_does_not_activate_or_save');
        foreach ($calls as $call) {
            check(strpos($call[1], 'https://graph.facebook.com/v25.0/') === 0 && strpos($call[1], '?') === false, 'fixed_host_secrets_not_in_transport_url');
            if ($call[3] !== null) check(hash_equals(hash_hmac('sha256', $call[3], str_repeat('a', 32)), $call[2]['appsecret_proof']), 'bearer_request_appsecret_proof');
        }
        $calls = []; $fields = ['messages', 'message_echoes', 'standby']; $credentials = new MetaOAuthCredentials;
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields));
        $result = $service->activate(pending(), ['messenger:101', 'instagram:303']);
        $writes = array_values(array_filter($calls, static fn ($call) => $call[0] === 'POST'));
        check(count($writes) === 1 && $writes[0][1] === 'https://graph.facebook.com/v25.0/101/subscribed_apps', 'one_linked_page_subscription_for_both_channels');
        check(in_array('standby', $fields, true) && in_array('message_echoes', $fields, true)
            && !in_array('OTHER_APP_PRIVATE_FIELD', $fields, true), 'preserve_own_fields_without_other_app_fields');
        check(count($credentials->saved) === 1 && count($credentials->saved[0]['accounts']) === 2
            && !isset($result['accounts'][0]['access_token']), 'save_after_verified_subscription_public_result_strips_tokens');
        check($calls[0][0] === 'GET' && $calls[1][0] === 'GET' && $calls[2][0] === 'GET'
            && $calls[3][0] === 'GET' && $calls[4][0] === 'POST' && $calls[5][0] === 'GET', 'all_identity_and_subscription_checks_precede_post');
        $calls = []; $credentials = new MetaOAuthCredentials;
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields));
        $service->activate(pending(), ['messenger:101']);
        check(!array_filter($calls, static fn ($call) => $call[0] === 'POST') && count($credentials->saved) === 1, 'already_complete_subscription_is_not_overwritten');
        $calls = []; $credentials = new MetaOAuthCredentials;
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields));
        rejects(static fn () => $service->activate(pending(), ['messenger:404']), 'ACCOUNT_CHANGED');
        check($calls === [] && $credentials->saved === [], 'foreign_selection_rejected_before_graph');
        $stale = pending(); $stale['authorized_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 601);
        rejects(static fn () => $service->activate($stale, ['messenger:101']), 'INVALID_SELECTION');
        MetaInboxAccess::$configured[1]['page_id'] = '404';
        rejects(static fn () => $service->activate(pending(), ['instagram:303']), 'ACCOUNT_CHANGED');
        configured();
        $calls = []; $credentials = new MetaOAuthCredentials; $fields = ['messages', 'standby'];
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields, static function ($method, $url) {
            if ($method === 'POST') throw new \RuntimeException('PRIVATE_TOKEN PRIVATE_GRAPH_BODY'); return null;
        }));
        rejects(static fn () => $service->activate(pending(), ['messenger:101']), 'SUBSCRIPTION_FAILED');
        check($credentials->saved === [], 'timeout_never_saves_locally');
        $calls = []; $credentials = new MetaOAuthCredentials; $fields = ['messages'];
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields, static function ($method, $url) {
            if ($method === 'POST') return jsonReply(['success' => true]); return null;
        }));
        rejects(static fn () => $service->activate(pending(), ['messenger:101']), 'SUBSCRIPTION_FAILED');
        check($credentials->saved === [], 'positive_post_without_readback_does_not_save');
        $calls = []; $credentials = new MetaOAuthCredentials;
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields, static function ($method, $url) {
            if (substr($url, -12) === '/debug_token') return jsonReply(['data' => ['is_valid' => true,
                'app_id' => '808', 'type' => 'PAGE', 'profile_id' => '101', 'scopes' => ['pages_messaging', 'pages_manage_metadata']]]);
            return null;
        }));
        rejects(static fn () => $service->activate(pending(), ['messenger:101']), 'META_REJECTED');
        check(!array_filter($calls, static fn ($call) => $call[0] === 'POST') && $credentials->saved === [], 'foreign_app_page_token_never_subscribed');
        $credentials = new MetaOAuthCredentials; $calls = []; $fields = ['messages']; $credentials->failSave = true;
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields));
        rejects(static fn () => $service->activate(pending(), ['messenger:101']), 'CREDENTIALS_SAVE_FAILED');
        $credentials = new MetaOAuthCredentials; $calls = []; $fields = ['messages']; $gets = 0;
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields, static function ($method, $url) use (&$gets) {
            if ($method === 'GET' && substr($url, -16) === '/subscribed_apps' && ++$gets === 2)
                return jsonReply(['data' => [['id' => '2177982783065133', 'subscribed_fields' => ['messages', 'message_reactions']]]]);
            return null;
        }));
        rejects(static fn () => $service->activate(pending(), ['messenger:101']), 'SUBSCRIPTION_FAILED');
        check(!array_filter($calls, static fn ($call) => $call[0] === 'POST') && $credentials->saved === [], 'concurrent_subscription_change_not_overwritten');
        $credentials = new MetaOAuthCredentials; $calls = []; $fields = ['messages'];
        $service = new MetaOAuthService($credentials, activationTransport($calls, $fields, static function ($method, $url) {
            if (substr($url, -12) === '/debug_token') return jsonReply(['data' => ['is_valid' => true,
                'app_id' => '2177982783065133', 'type' => 'PAGE', 'profile_id' => '101', 'scopes' => ['pages_manage_metadata']]]);
            return null;
        }));
        rejects(static fn () => $service->activate(pending(), ['messenger:101']), 'META_REJECTED');
        check(!array_filter($calls, static fn ($call) => $call[0] === 'POST') && $credentials->saved === [], 'missing_selected_channel_scope_never_subscribed');
        $credentials = new MetaOAuthCredentials; $calls = [];
        $service = new MetaOAuthService($credentials, static function ($method, $url, $params, $token) use (&$calls) {
            $calls[] = [$method, $url, $params, $token];
            if (substr($url, -19) === '/oauth/access_token') return jsonReply(['access_token' => 'LONG_PRIVATE_USER_TOKEN']);
            if (substr($url, -3) === '/me') return jsonReply(['id' => '909']);
            if (substr($url, -12) === '/debug_token') return jsonReply(['data' => [
                'is_valid' => true, 'app_id' => '2177982783065133', 'type' => 'USER', 'user_id' => '909']]);
            if (substr($url, -12) === '/me/accounts') return isset($params['after'])
                ? jsonReply(['data' => [['id' => '404', 'name' => 'Foreign', 'access_token' => 'FOREIGN_PRIVATE_TOKEN']]])
                : jsonReply(['data' => [['id' => '101', 'name' => 'Fasakhansta', 'access_token' => 'NEW_PRIVATE_PAGE_TOKEN']],
                    'paging' => ['next' => 'https://attacker.invalid/collect?access_token=PRIVATE_TOKEN', 'cursors' => ['after' => 'ABC/+=']]]);
            throw new \RuntimeException('unexpected');
        });
        $result = $service->discover('PRIVATE_CODE');
        check(count($result['accounts']) === 1 && count($calls) === 6 && $calls[5][2]['after'] === 'ABC/+=', 'bounded_opaque_pagination_cursor_preserved');
        check(!array_filter($calls, static fn ($call) => strpos($call[1], 'attacker.invalid') !== false), 'untrusted_next_url_never_followed');
        $credentials = new MetaOAuthCredentials; $calls = [];
        $service = new MetaOAuthService($credentials, static function ($method, $url, $params, $token) use (&$calls) {
            $calls[] = [$method, $url, $params, $token];
            if (substr($url, -19) === '/oauth/access_token') return jsonReply(['access_token' => 'LONG_PRIVATE_USER_TOKEN']);
            if (substr($url, -3) === '/me') return jsonReply(['id' => '909']);
            if (substr($url, -12) === '/debug_token') return jsonReply(['data' => [
                'is_valid' => true, 'app_id' => '808', 'type' => 'USER', 'user_id' => '909']]);
            throw new \RuntimeException('discovery_must_not_continue');
        });
        rejects(static fn () => $service->discover('PRIVATE_CODE'), 'META_REJECTED');
        check(count($calls) === 4 && $credentials->saved === [], 'foreign_app_user_token_rejected_before_discovery');
        check((new MetaOAuthFailure('PRIVATE_RAW_ERROR'))->reason() === 'META_UNAVAILABLE', 'unknown_failure_text_redacted');
        echo json_encode(['test' => 'META_OAUTH_SERVICE', 'status' => 'PASS', 'checks' => $checks], JSON_THROW_ON_ERROR)."\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, 'META_OAUTH_SERVICE_TEST_FAILED: '.$error->getMessage()."\n"); exit(1);
    }
}
