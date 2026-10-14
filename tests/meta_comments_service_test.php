<?php
namespace {
    $autoload = getenv('WA_INBOX_TEST_VENDOR');
    if (is_dir($autoload)) $autoload .= '/autoload.php';
    require $autoload;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function now($tz = null) { return \Carbon\Carbon::now($tz); }
    function abort_unless($ok, $status) { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status); }
    set_error_handler(function ($s, $m, $f, $l) { if ($s & E_ALL) throw new \ErrorException($m, 0, $s, $f, $l); return false; });
}
namespace App\Models { class User { public $id = 1; public $active = true; public $account_type = 'admin'; public $owner_resturant_id = null; } }
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public static int $calls = 0; public static $before = null;
        public function actor($actor) {
            self::$calls++; if (self::$before) (self::$before)($actor, self::$calls);
            \abort_unless($actor && $actor->active, 403); return $actor;
        }
    }
    class MetaOAuthCredentials {
        public static int $calls = 0; public static $before = null;
        public function overlay(array $accounts): array {
            self::$calls++; return self::$before ? (self::$before)($accounts, self::$calls) : $accounts;
        }
    }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Encryption\Encrypter;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Facade;
    use App\Services\Dashboard\MetaCommentsService as Comments;
    use App\Services\Dashboard\MetaCommentsFailure;

    $c = new Container; Container::setInstance($c); $db = new Manager($c);
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setEventDispatcher(new Dispatcher($c)); $db->setAsGlobal(); $db->bootEloquent();
    $key = random_bytes(32);
    $accounts = [
        ['channel' => 'messenger', 'account_id' => '101', 'access_token' => str_repeat('a', 24), 'label' => 'Facebook Shop'],
        ['channel' => 'instagram', 'account_id' => '202', 'access_token' => str_repeat('b', 24), 'login' => 'instagram', 'label' => 'Instagram Shop'],
        ['channel' => 'instagram', 'account_id' => '303', 'access_token' => str_repeat('c', 24), 'login' => 'facebook'],
        ['channel' => 'messenger', 'account_id' => '404'],
    ];
    $c->instance('config', new Repository(['meta_messaging' => ['accounts' => $accounts, 'api_version' => 'v25.0', 'app_secret' => 'private-secret'],
        'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]] ]));
    $c->instance('db', $db->getDatabaseManager()); $c->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter', new Encrypter($key, 'AES-256-CBC')); Facade::setFacadeApplication($c);
    foreach (['WhatsAppInboxAccess', 'MetaInboxAccess', 'MetaCommentsFailure', 'MetaCommentsService'] as $name) require __DIR__ . '/../app/Services/Dashboard/' . $name . '.php';
    require __DIR__ . '/../database/migrations/2026_10_14_210000_create_meta_comment_reply_requests.php';
    (new \CreateMetaCommentReplyRequests)->up();
    $checks = 0; $serial = 0; $actor = new \App\Models\User;
    function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function expectFailure(callable $call, string $reason, string $label) {
        try { $call(); check(false, $label); } catch (MetaCommentsFailure $e) { check($e->reason === $reason, $label); }
    }
    function expectDenied(callable $call, string $label) {
        try { $call(); check(false, $label); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { check($e->getStatusCode() === 403, $label); }
    }
    function input($handle, $message = 'أهلًا بحضرتك') {
        global $serial; $serial++;
        return ['comment_handle' => $handle, 'message' => $message,
            'client_request_id' => sprintf('%08x-1234-4234-8234-123456789abc', $serial)];
    }
    function jsonResponse(array $body, int $status = 200): array { return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]; }
    $calls = [];
    $service = new Comments(function ($method, $endpoint, $parameters, $token) use (&$calls) {
        $calls[] = [$method, $endpoint, $parameters];
        check(strpos($endpoint, $token) === false && strpos($endpoint, 'access_token') === false, 'bearer token absent from URL');
        check($parameters['appsecret_proof'] === hash_hmac('sha256', $token, 'private-secret'), 'appsecret proof supplied');
        if ($method === 'POST') {
            check(DB::connection()->transactionLevel() === 0, 'POST outside database transaction');
            return jsonResponse(['id' => '880']) + ['timings' => ['meta_dns' => 0.2, 'meta_connect' => 1.5,
                'meta_tls' => 2.5, 'meta_wait' => 4.5, 'private-token' => 99]];
        }
        if (strpos($endpoint, '/published_posts') !== false) {
            check($parameters['limit'] === 20, 'post request bounded');
            return jsonResponse(['data' => [
                ['id' => '101_501', 'message' => '<script>fixture</script>', 'created_time' => '2026-10-14T10:00:00+0000', 'permalink_url' => 'https://www.facebook.com/101/posts/501'],
                ['id' => '999_501', 'message' => 'Foreign page'],
                ['id' => '101_502', 'message' => 'Safe', 'permalink_url' => 'https://evil.test/steal', 'full_picture' => 'https://scontent.fbcdn.net/image.jpg'],
            ], 'paging' => ['cursors' => ['after' => 'safe_cursor='], 'next' => 'https://evil.test/?access_token=do-not-follow']]);
        }
        if (strpos($endpoint, '/media') !== false) return jsonResponse(['data' => [['id' => '600', 'caption' => 'Instagram post', 'permalink' => 'https://www.instagram.com/p/fixture/']]]);
        check($parameters['limit'] === 25, 'comment request bounded');
        return jsonResponse(['data' => [['id' => '700', 'message' => 'سعر الرنجة كام؟', 'text' => 'سعر الرنجة كام؟',
            'from' => ['id' => 'private-customer-id', 'name' => 'عميل'], 'username' => 'customer', 'created_time' => '2026-10-14T10:01:00+0000',
            'timestamp' => '2026-10-14T10:01:00+0000', 'comment_count' => 2]],
            'paging' => ['cursors' => ['after' => 'comment_cursor'], 'next' => 'https://graph.facebook.com/must-not-follow']]);
    });
    $bootstrap = $service->accounts($actor);
    check(count($bootstrap['accounts']) === 4 && $bootstrap['accounts'][0]['channel'] === 'facebook'
        && $bootstrap['accounts'][1]['channel'] === 'instagram' && !$bootstrap['accounts'][3]['connected'], 'bootstrap connected business accounts only');
    check($bootstrap['viewer_key'] === $service->viewerKey($actor), 'viewer key stable');
    $encoded = json_encode($bootstrap);
    foreach (['access_token', str_repeat('a', 24), 'account_id', 'private-secret'] as $private) check(strpos($encoded, $private) === false, 'bootstrap excludes ' . $private);
    $fbKey = $bootstrap['accounts'][0]['account_key']; $igKey = $bootstrap['accounts'][1]['account_key']; $igFbKey = $bootstrap['accounts'][2]['account_key'];
    $posts = $service->posts($actor, $fbKey);
    check(count($posts['posts']) === 2 && $posts['posts'][0]['text'] === '<script>fixture</script>', 'foreign Page post rejected and content represented as plain text');
    check($posts['posts'][1]['permalink'] === null && $posts['posts'][1]['thumbnail_url'] === 'https://scontent.fbcdn.net/image.jpg', 'URL allowlist rejects foreign permalink');
    $post = $posts['posts'][0];
    $second = $service->posts($actor, $fbKey, $posts['next_cursor']);
    check(end($calls)[2]['after'] === 'safe_cursor=' && strpos(end($calls)[1], 'evil.test') === false, 'opaque pagination uses after cursor on fixed endpoint');
    check($second['posts'][0]['key'] === $post['key'] && $second['posts'][0]['handle'] !== $post['handle'], 'stable post identity beside randomized handle');
    expectFailure(fn () => $service->posts($actor, $igKey, $posts['next_cursor']), 'INVALID_CURSOR', 'cursor cannot cross accounts');
    expectFailure(fn () => $service->posts($actor, '101'), 'INVALID_HANDLE', 'raw account ID rejected');
    expectFailure(fn () => $service->posts($actor, str_repeat('0', 64)), 'ACCOUNT_UNAVAILABLE', 'unknown account rejected');
    expectFailure(fn () => $service->posts($actor, $bootstrap['accounts'][3]['account_key']), 'RECONNECT_REQUIRED', 'missing token gives actionable reconnect');
    $comments = $service->comments($actor, $post['handle']); $comment = $comments['comments'][0];
    check($comment['author'] === 'عميل' && $comment['text'] === 'سعر الرنجة كام؟' && $comment['replies_count'] === 2, 'comment DTO supports authors reply counts and dates');
    check(end($calls)[1] === 'https://graph.facebook.com/v25.0/101_501/comments' && end($calls)[2]['filter'] === 'toplevel', 'Page comments fetched from selected owned post');
    $replies = $service->replies($actor, $comment['handle']);
    check(end($calls)[1] === 'https://graph.facebook.com/v25.0/700/comments' && count($replies['replies']) === 1, 'Facebook reply edge');
    $output = json_encode([$post, $comment]);
    foreach (['101_501', 'private-customer-id', 'access_token'] as $private) check(strpos($output, $private) === false, 'public DTO excludes native identity ' . $private);
    $before = count($calls);
    expectFailure(fn () => $service->comments($actor, '101_501'), 'INVALID_HANDLE', 'direct Graph post ID rejected');
    expectFailure(fn () => $service->comments($actor, substr($post['handle'], 0, -4) . 'AAAA'), 'INVALID_HANDLE', 'tampered post rejected');
    expectFailure(fn () => $service->replies($actor, $post['handle']), 'INVALID_HANDLE', 'handle purpose mismatch rejected');
    expectFailure(fn () => $service->comments($actor, $post['handle'], $comments['next_cursor'] . 'X'), 'INVALID_CURSOR', 'tampered cursor rejected');
    check(count($calls) === $before, 'invalid handles never reach Graph');
    $h = json_decode(Crypt::decryptString($post['handle']), true); $h['expires'] = time() - 1;
    expectFailure(fn () => $service->comments($actor, Crypt::encryptString(json_encode($h))), 'INVALID_HANDLE', 'expired handle rejected');
    config()->set('meta_messaging.accounts.0.access_token', str_repeat('z', 24));
    expectFailure(fn () => $service->comments($actor, $post['handle']), 'INVALID_HANDLE', 'credential rotation invalidates existing handles');
    config()->set('meta_messaging.accounts', $accounts);
    $igPost = $service->posts($actor, $igKey)['posts'][0];
    check(end($calls)[1] === 'https://graph.instagram.com/v25.0/202/media', 'Instagram Login uses native Graph host');
    $igComment = $service->comments($actor, $igPost['handle'])['comments'][0];
    $service->replies($actor, $igComment['handle']);
    check(end($calls)[1] === 'https://graph.instagram.com/v25.0/700/replies', 'Instagram reply read edge');
    $igFbPost = $service->posts($actor, $igFbKey)['posts'][0];
    check(end($calls)[1] === 'https://graph.facebook.com/v25.0/303/media', 'Facebook Login Instagram uses Facebook Graph host');
    $igFbComment = $service->comments($actor, $igFbPost['handle'])['comments'][0];

    $replyInput = input($comment['handle']); $before = count($calls);
    \App\Services\Dashboard\TakeawayAccess::$calls = 0;
    \App\Services\Dashboard\MetaOAuthCredentials::$calls = 0;
    DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
    $accepted = $service->send($actor, $replyInput);
    $writeQueries = DB::connection()->getQueryLog(); DB::connection()->disableQueryLog();
    check($accepted['state'] === 'ACCEPTED' && $accepted['success'] && $accepted['reply']['text'] === 'أهلًا بحضرتك', 'accepted reply returned immediately before remote refresh');
    check(count($writeQueries) === 3 && count(array_filter($writeQueries,
        fn ($query) => strpos($query['query'], 'meta_comment_reply_requests') !== false)) === 3,
        'reply storage uses claim read finish only without per-send schema query');
    check(\App\Services\Dashboard\TakeawayAccess::$calls === 2 && \App\Services\Dashboard\MetaOAuthCredentials::$calls === 2,
        'two persisted actor and credential checks retained before provider send');
    $timing = $service->serverTiming(12.5);
    check(strpos($timing, 'reply;dur=12.5') === 0 && strpos($timing, 'meta;dur=') !== false
        && strpos($timing, 'meta_wait;dur=4.5') !== false && strpos($timing, 'private-token') === false,
        'timing attribution exposes only bounded fixed numeric metrics');
    check(end($calls)[1] === 'https://graph.facebook.com/v25.0/700/comments' && end($calls)[0] === 'POST', 'Facebook reply sends correct edge');
    $repeat = $service->send($actor, $replyInput);
    check($repeat['state'] === 'ACCEPTED' && $repeat['replayed'] && count($calls) === $before + 1, 'same UUID returns persisted receipt without second send');
    check($service->serverTiming(5.0) === 'reply;dur=5.0', 'idempotent replay clears old provider timing');
    $row = DB::table('meta_comment_reply_requests')->first();
    check($row->pending_key === null && strpos(json_encode((array) $row), 'أهلًا') === false && strpos($row->response_details, '880') === false, 'minimal reply audit remains encrypted');
    expectFailure(fn () => $service->send($actor, array_merge($replyInput, ['message' => 'Different text'])), 'REQUEST_CONFLICT', 'same UUID payload mismatch blocked');
    expectFailure(fn () => $service->send($actor, array_merge($replyInput, ['account_id' => '999'])), 'INVALID_REQUEST', 'client cannot override account');
    expectFailure(fn () => $service->send($actor, input($comment['handle'], str_repeat('x', 2001))), 'INVALID_REQUEST', 'oversized reply rejected');
    expectFailure(fn () => $service->send($actor, input($comment['handle'], "x\0y")), 'INVALID_REQUEST', 'control characters rejected');
    $service->send($actor, input($igComment['handle']));
    check(end($calls)[1] === 'https://graph.instagram.com/v25.0/700/replies', 'Instagram Login POST reply edge');
    $service->send($actor, input($igFbComment['handle']));
    check(end($calls)[1] === 'https://graph.facebook.com/v25.0/700/replies', 'Instagram Facebook Login POST reply edge');

    $timeoutCalls = 0; $timeoutInput = input($comment['handle']);
    $timeout = new Comments(function () use (&$timeoutCalls) { $timeoutCalls++; throw new \RuntimeException('Private timeout body and token'); });
    check($timeout->send($actor, $timeoutInput)['state'] === 'UNKNOWN', 'timeout becomes durable unknown');
    check($timeout->send($actor, $timeoutInput)['state'] === 'UNKNOWN' && $timeoutCalls === 1, 'timeout UUID never retransmitted');
    check($timeout->send($actor, input($comment['handle']))['state'] === 'UNKNOWN' && $timeoutCalls === 1, 'new UUID cannot bypass unknown target guard');
    $other = new \App\Models\User; $other->id = 2;
    $concurrentInput = input($comment['handle']); $nestedResults = []; $concurrentCalls = 0; $concurrent = null;
    $concurrent = new Comments(function () use (&$concurrent, $other, $concurrentInput, &$nestedResults, &$concurrentCalls, $comment) {
        $concurrentCalls++;
        $nestedResults[] = $concurrent->send($other, $concurrentInput);
        $nestedResults[] = $concurrent->send($other, input($comment['handle']));
        return jsonResponse(['id' => '881']);
    });
    check($concurrent->send($other, $concurrentInput)['state'] === 'ACCEPTED' && $concurrentCalls === 1
        && $nestedResults[0]['state'] === 'UNKNOWN' && $nestedResults[1]['state'] === 'UNKNOWN', 'simultaneous duplicate clicks and new UUID claimed atomically');
    $permissionCalls = 0;
    $denied = new Comments(function () use (&$permissionCalls) { $permissionCalls++; return jsonResponse(['error' => ['code' => 200, 'message' => 'Private Graph message']], 403); });
    $deniedInput = input($igComment['handle']);
    $failed = $denied->send($other, $deniedInput);
    check($failed['state'] === 'FAILED' && $failed['reason'] === 'PERMISSION_REQUIRED'
        && in_array('instagram_business_manage_comments', $failed['required_permissions'], true), 'permission rejection has actionable Instagram Login scopes');
    check($denied->send($other, $deniedInput)['replayed'] && $permissionCalls === 1, 'failed UUID replay never sends again');
    $again = $service->send($other, input($igComment['handle']));
    check($again['state'] === 'ACCEPTED', 'definitive rejected target can be retried explicitly using new UUID');
    expectFailure(fn () => $denied->posts($actor, $fbKey), 'PERMISSION_REQUIRED', 'GET permission failure normalized');
    $broken = new Comments(fn () => jsonResponse(['error' => ['code' => 2, 'message' => 'secret']], 500));
    check($broken->send($other, input($igFbComment['handle']))['state'] === 'UNKNOWN', 'server error cannot cause unsafe resend');
    $reconnect = new Comments(fn () => jsonResponse(['error' => ['code' => 190, 'message' => 'token']], 400));
    expectFailure(fn () => $reconnect->posts($actor, $fbKey), 'RECONNECT_REQUIRED', 'expired credentials normalized');
    $oversize = new Comments(fn () => ['status' => 200, 'body' => str_repeat('x', 2097153)]);
    expectFailure(fn () => $oversize->posts($actor, $fbKey), 'META_UNAVAILABLE', 'body bound enforced with fake transport');
    $hugeList = new Comments(fn () => jsonResponse(['data' => array_fill(0, 100, ['id' => '101_501', 'message' => 'duplicate fixture'])]));
    check(count($hugeList->posts($actor, $fbKey)['posts']) === 20, 'returned post rows bounded even with oversized data array');
    $revoking = new Comments(function () { config()->set('meta_messaging.accounts', []); return jsonResponse(['data' => [['id' => '101_501']]]); });
    expectFailure(fn () => $revoking->posts($actor, $fbKey), 'ACCOUNT_UNAVAILABLE', 'in-flight account revocation prevents response publication');
    config()->set('meta_messaging.accounts', $accounts);
    $branch = new \App\Models\User; $branch->owner_resturant_id = 9;
    expectDenied(fn () => $service->accounts($branch), 'branch actor blocked');
    $nonAdmin = new \App\Models\User; $nonAdmin->account_type = 'customer';
    expectDenied(fn () => $service->accounts($nonAdmin), 'non-admin actor blocked');
    $inactive = new \App\Models\User; $inactive->active = false;
    expectDenied(fn () => $service->posts($inactive, $fbKey), 'revoked actor blocked');
    config()->set('desktop_dashboard.local', true);
    expectFailure(fn () => $service->accounts($actor), 'CENTRAL_ONLY', 'offline desktop has no live social access');
    expectFailure(fn () => $service->send($actor, $replyInput), 'CENTRAL_ONLY', 'offline desktop cannot reply');
    config()->set('desktop_dashboard.local', false);
    $freshActor = new \App\Models\User; $freshActor->id = 90; $preflightCalls = 0;
    $preflightService = new Comments(function () use (&$preflightCalls) { $preflightCalls++; return jsonResponse(['id' => '899']); });
    \App\Services\Dashboard\TakeawayAccess::$calls = 0;
    \App\Services\Dashboard\TakeawayAccess::$before = function ($actor, $count) { if ($count === 2) $actor->active = false; };
    $revokedActor = $preflightService->send($freshActor, input($comment['handle']));
    check($revokedActor['state'] === 'FAILED' && $revokedActor['reason'] === 'ACCESS_REVOKED' && $preflightCalls === 0,
        'persisted actor revocation after claim still prevents provider write');
    \App\Services\Dashboard\TakeawayAccess::$before = null; $freshActor->active = true;
    \App\Services\Dashboard\MetaOAuthCredentials::$calls = 0;
    \App\Services\Dashboard\MetaOAuthCredentials::$before = fn ($accounts, $count) => $count === 2 ? [] : $accounts;
    $revokedAccount = $preflightService->send($freshActor, input($comment['handle']));
    check($revokedAccount['state'] === 'FAILED' && $revokedAccount['reason'] === 'ACCESS_REVOKED' && $preflightCalls === 0,
        'fresh credential overlay revocation after claim still prevents provider write');
    \App\Services\Dashboard\MetaOAuthCredentials::$before = null;
    (new \CreateMetaCommentReplyRequests)->down();
    expectFailure(fn () => $preflightService->send($freshActor, input($comment['handle'])), 'REPLIES_UNAVAILABLE',
        'missing reply table reported before sending without schema preflight query');
    check($preflightCalls === 0, 'storage failure never reaches provider');
    echo "meta-comments-service: $checks checks passed\n";
}
