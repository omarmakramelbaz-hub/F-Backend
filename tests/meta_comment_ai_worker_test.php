<?php
namespace {
    require rtrim((string) getenv('WA_INBOX_TEST_VENDOR'), '/') . '/autoload.php';
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($key = null, $default = null) { return $key === null ? app('config') : app('config')->get($key, $default); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function abort_unless($ok, $status) { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status); }
    set_error_handler(static function ($level, $message, $file, $line) { if ($level & error_reporting()) throw new \ErrorException($message, 0, $level, $file, $line); return false; });
}
namespace App\Models {
    class User {
        public $id = 1; public $active = true; public $account_type = 'admin'; public $owner_resturant_id = null;
        public function getKey() { return $this->id; }
        public function roles() { return new class { public function where(...$args) { return $this; } public function exists() { return true; } }; }
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess { public function actor($actor) { \abort_unless($actor && $actor->active, 403); return $actor; } }
    class MetaOAuthCredentials { public function overlay(array $accounts): array { return $accounts; } }
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
    use App\Services\Dashboard\MetaCommentsService;
    use App\Services\Dashboard\MetaCommentAiWorker;
    use App\Services\Dashboard\MetaCommentReplyGate;

    $c = new Container; Container::setInstance($c); $db = new Manager($c);
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setEventDispatcher(new Dispatcher($c)); $db->setAsGlobal(); $db->bootEloquent();
    $accounts = [['channel' => 'messenger', 'account_id' => '101', 'access_token' => str_repeat('a', 24), 'label' => 'Business'],
        ['channel' => 'instagram', 'account_id' => '202', 'page_id' => '101', 'login' => 'facebook', 'access_token' => str_repeat('b', 24), 'label' => 'Instagram']];
    $c->instance('config', new Repository(['meta_messaging' => ['accounts' => $accounts, 'api_version' => 'v25.0'],
        'database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]] ]));
    $c->instance('db', $db->getDatabaseManager()); $c->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    $c->instance('encrypter', new Encrypter(random_bytes(32), 'AES-256-CBC'));
    $cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore);
    $c->instance('cache', $cache); Facade::setFacadeApplication($c);
    foreach (['WhatsAppInboxAccess', 'MetaInboxAccess', 'MetaCommentsFailure', 'MetaCommentsReadCache', 'MetaCommentReplyGate',
        'MetaCommentAiDispatchLock', 'MetaCommentAiControl', 'MetaCommentAiReplyPolicy', 'MetaCommentAiProvider', 'MetaCommentsService', 'MetaCommentAiWorker'] as $name) {
        require __DIR__ . '/../app/Services/Dashboard/' . $name . '.php';
    }
    require __DIR__ . '/../database/migrations/2026_10_14_210000_create_meta_comment_reply_requests.php';
    require __DIR__ . '/../database/migrations/2026_10_15_010000_create_meta_comment_ai_tables.php';
    (new \CreateMetaCommentReplyRequests)->up(); (new \CreateMetaCommentAiTables)->up();

    class TestCommentControl extends \App\Services\Dashboard\MetaCommentAiControl {
        public bool $enabled = true; public bool $blocked = false; public int $heartbeats = 0;
        public function ready(): bool { return true; }
        public function snapshot(): ?array { return ['enabled' => $this->enabled, 'revision' => 1, 'actor_id' => 1,
            'enabled_at' => '2026-10-15T11:00:00+00:00', 'mode' => 'always']; }
        public function activeAt(array $policy, $time): bool { return $policy['enabled']; }
        public function eligible(array $policy, $created, $now): bool { return $created !== null && new \DateTimeImmutable($created) >= new \DateTimeImmutable($policy['enabled_at']) && new \DateTimeImmutable($created) <= $now; }
        public function withActiveDispatch(array $expected, callable $send) { return $this->blocked || !$this->enabled ? null : $send(); }
        public function recordHeartbeat(?string $reason = null): void { $this->heartbeats++; }
    }
    class TestCommentProvider extends \App\Services\Dashboard\MetaCommentAiProvider {
        public int $calls = 0; public $hook = null; public bool $skip = false; public bool $transient = false;
        public array $contexts = []; public string $message = 'شكرًا لحضرتك، نورتنا'; public string $kind = 'REPLY';
        public function ready(): bool { return true; }
        public function reply(array $context): array {
            $this->calls++; $this->contexts[] = $context; if ($this->hook) ($this->hook)();
            if ($this->transient) return ['ok' => false, 'kind' => 'SKIP', 'message' => null, 'reason' => 'TRANSPORT_ERROR'];
            return $this->skip ? ['ok' => false, 'kind' => 'SKIP', 'message' => null, 'reason' => 'NO_SAFE_REPLY']
                : ['ok' => true, 'kind' => $this->kind, 'message' => $this->message, 'reason' => null];
        }
    }
    $checks = 0;
    function check($ok, $label) { global $checks; $checks++; if (!$ok) throw new \RuntimeException($label); }
    function response(array $body, int $status = 200): array { return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]; }
    function comment(string $id, ?string $author = null, string $when = '2026-10-15T11:30:00+0000'): array {
        return ['id' => $id, 'message' => 'تعليق الاختبار', 'text' => 'تعليق الاختبار', 'from' => $author ? ['id' => $author, 'name' => 'Name'] : null,
            'created_time' => $when, 'timestamp' => $when, 'can_comment' => true];
    }
    $calls = []; $postWrites = 0; $onWrite = null; $uncertain = false;
    $postRows = ['101' => [['id' => '101_501', 'message' => 'بوست الاختبار']], '202' => []];
    $commentRows = ['101_501' => [comment('701')]]; $replyRows = [];
    $transport = function ($method, $url, $query, $token) use (&$calls, &$postWrites, &$onWrite, &$uncertain, &$postRows, &$commentRows, &$replyRows) {
        $path = substr(parse_url($url, PHP_URL_PATH), strlen('/v25.0/'));
        $calls[] = [$method, $path, $query];
        if ($method === 'POST') {
            $postWrites++; if ($onWrite) ($onWrite)();
            if ($uncertain) throw new \RuntimeException('fixture uncertain transport');
            return response(['id' => (string) (9000 + $postWrites)]);
        }
        if ($path === '202') return response(['id' => '202', 'username' => 'Official_Business']);
        [$id, $edge] = explode('/', $path, 2);
        if ($edge === 'published_posts' || $edge === 'media') {
            if (($query['after'] ?? null) === 'OLDER') return response(['data' => [['id' => '101_400', 'message' => 'بوست قديم']]]);
            $body = ['data' => $postRows[$id] ?? []];
            if ($id === '101' && isset($commentRows['101_400'])) $body['paging'] = ['cursors' => ['after' => 'OLDER'], 'next' => 'https://graph.facebook.com/ignored'];
            return response($body);
        }
        if (isset($commentRows[$id])) {
            $offset = ($query['after'] ?? null) === 'MORE' ? 25 : 0;
            $body = ['data' => array_slice($commentRows[$id], $offset, 25)];
            if (count($commentRows[$id]) > $offset + 25) $body['paging'] = ['cursors' => ['after' => 'MORE'], 'next' => 'https://graph.facebook.com/ignored'];
            return response($body);
        }
        return response(['data' => $replyRows[$id] ?? []]);
    };
    $service = new MetaCommentsService($transport); $gate = new MetaCommentReplyGate;
    $c->instance(MetaCommentReplyGate::class, $gate);
    $control = new TestCommentControl; $provider = new TestCommentProvider; $actor = new \App\Models\User;
    $clockNow = new \DateTimeImmutable('2026-10-15T12:00:00+00:00');
    $worker = new MetaCommentAiWorker($control, $service, $provider, $gate, static fn () => $actor,
        static function () use (&$clockNow) { return $clockNow; });
    $fbKey = hash('sha256', 'messenger:101');
    function clearState() {
        global $calls, $postWrites, $onWrite, $uncertain, $provider, $control, $cache, $commentRows, $replyRows, $postRows, $clockNow;
        foreach (['meta_comment_ai_targets', 'meta_comment_reply_requests', 'meta_comment_ai_poll_states'] as $table) DB::table($table)->delete();
        $calls = []; $postWrites = 0; $onWrite = null; $uncertain = false; $provider->calls = 0; $provider->hook = null; $provider->skip = false; $provider->transient = false;
        $provider->contexts = []; $provider->message = 'شكرًا لحضرتك، نورتنا'; $provider->kind = 'REPLY';
        $clockNow = new \DateTimeImmutable('2026-10-15T12:00:00+00:00');
        $control->enabled = true; $control->blocked = false; $control->heartbeats = 0; $cache->flush();
        $commentRows = ['101_501' => [comment('701')]]; $replyRows = [];
        $postRows = ['101' => [['id' => '101_501', 'message' => 'بوست الاختبار']], '202' => []];
    }
    $ready = $worker->check();
    check($ready['ready'] && $ready['configured'] && count($calls) === 0 && $provider->calls === 0 && $control->heartbeats === 0, '--check does no provider I/O or mutations');
    $control->enabled = false; $worker->run();
    check(count($calls) === 0 && $provider->calls === 0, 'disabled worker never reads or sends');
    clearState();
    $commentRows['101_501'] = [comment('700', null, '2026-10-15T10:00:00+0000'), comment('702', '101'), comment('701')];
    $run = $worker->run();
    check($run['sent'] === 1 && $postWrites === 1 && $provider->calls === 1, 'only eligible external comment is sent; absent public author is allowed');
    check($run['skipped_timing'] === 1 && $run['skipped_self'] === 1 && $run['reason'] === null, 'ordinary timing and self exclusions have safe counters without an error reason');
    check(DB::table('meta_comment_ai_targets')->where('reason', 'SELF_COMMENT')->exists(), 'known self author ID is suppressed');
    $repeat = $worker->run(); check($postWrites === 1 && $provider->calls === 1, 'accepted automatic comment is never generated/sent twice');
    check($repeat['skipped_gate'] === 1 && $repeat['skipped_timing'] === 1 && $repeat['skipped_self'] === 1, 'fresh head rescans identify accepted receipts separately from old and self comments');
    $saved = DB::table('meta_comment_ai_poll_states')->first();
    check(strpos($saved->state, '101_501') === false && strpos($saved->state, 'بوست') === false, 'polling state encrypted');
    check($provider->contexts[0]['recent_replies'] === [], 'first automatic reply has no invented style history');
    $styleState = json_decode(Crypt::decryptString(DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first()->state), true);
    check($styleState['recent_replies'] === [$provider->message] && strpos($saved->state, $provider->message) === false,
        'accepted prose is stored only inside encrypted polling state');
    $commentRows['101_501'][] = comment('703'); $worker->run();
    check($provider->contexts[1]['recent_replies'] === [$provider->message], 'next worker pass reuses published wording for variety');
    $commentRows['101_501'][] = comment('704');
    $restartedWorker = new MetaCommentAiWorker($control, $service, $provider, $gate, static fn () => $actor,
        static function () use (&$clockNow) { return $clockNow; });
    $restartedWorker->run();
    check($provider->contexts[2]['recent_replies'] === [$provider->message], 'wording memory survives worker construction/process restart');
    $styleState = json_decode(Crypt::decryptString(DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first()->state), true);
    check(count($styleState['recent_replies']) === 1, 'identical accepted wording is deduplicated in bounded memory');
    // Add distinct prose in one run and prove each following generation sees only prior accepted replies.
    clearState(); $commentRows['101_501'] = [];
    for ($i = 0; $i < 8; $i++) $commentRows['101_501'][] = comment((string) (740 + $i));
    $wording = ['كلامك فرحنا ❤️', 'يا سلام على ذوقك الجميل 😍', 'حلوة منك دي 😂', 'تسلم على كلامك الجميل 🙏',
        'أهو تعليق يفتح النفس 😋', 'ذوقك جميل وبيفرحنا 😊', 'فرحتنا بالتعليق ده ❤️', 'منور بتعليقك الحلو 😍'];
    $provider->hook = function () use ($provider, $wording) { $provider->message = $wording[$provider->calls - 1]; };
    $worker->run(); $worker->run();
    check($provider->contexts[1]['recent_replies'] === [$wording[0]], 'same run passes accepted preceding wording to the next comment');
    check($provider->contexts[7]['recent_replies'] === array_reverse(array_slice($wording, 1, 6)),
        'only the six most recent accepted replies reach the model context');
    $styleRow = DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first();
    $styleState = json_decode(Crypt::decryptString($styleRow->state), true);
    check($styleState['recent_replies'] === array_reverse(array_slice($wording, 2, 6)), 'persisted wording memory remains bounded');
    unset($styleState['recent_replies']);
    DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->update(['state' => Crypt::encryptString(json_encode($styleState))]);
    $commentRows['101_501'][] = comment('748'); $provider->hook = null; $worker->run();
    check(end($provider->contexts)['recent_replies'] === [] && $postWrites === 9,
        'legacy polling state upgrades without dropping saved cursor work or re-sending accepted targets');
    $styleRow = DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first();
    $styleState = json_decode(Crypt::decryptString($styleRow->state), true);
    $styleState['recent_replies'] = ['https://private.invalid', 'رقمك 01012345678', ['unexpected' => 'metadata'], 'كلامك فرحنا ❤️'];
    DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->update(['state' => Crypt::encryptString(json_encode($styleState))]);
    $commentRows['101_501'][] = comment('749'); $worker->run();
    check(end($provider->contexts)['recent_replies'] === ['كلامك فرحنا ❤️'],
        'invalid or sensitive state examples are discarded without losing eligible queue work');
    clearState(); $postRows['202'] = [['id' => '600', 'caption' => 'Instagram post']]; $commentRows['600'] = [comment('800')];
    $worker->run();
    check($provider->calls === 2 && $provider->contexts[1]['recent_replies'] === [], 'Facebook wording history is isolated from Instagram');
    clearState(); $provider->kind = 'PRICE'; $provider->message = \App\Services\Dashboard\MetaCommentAiReplyPolicy::priceRedirect();
    $worker->run();
    $styleState = json_decode(Crypt::decryptString(DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first()->state), true);
    check($styleState['recent_replies'] === [], 'server-owned numbered price redirects never enter model style memory');
    clearState();
    $failedStyleService = new MetaCommentsService(function ($method, $url, $query, $token) use ($transport) {
        return $method === 'POST' ? response(['error' => ['code' => 200]], 403) : $transport($method, $url, $query, $token);
    });
    $failedStyleWorker = new MetaCommentAiWorker($control, $failedStyleService, $provider, $gate, static fn () => $actor,
        static function () use (&$clockNow) { return $clockNow; });
    $failedStyleWorker->run();
    $styleState = json_decode(Crypt::decryptString(DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first()->state), true);
    check($styleState['recent_replies'] === [], 'definitively rejected drafts never enter published wording memory');
    clearState(); $provider->message = "كلامك فرحنا\nنورتنا ❤️";
    $commentRows['101_501'][] = comment('750'); $worker->run();
    check($provider->contexts[1]['recent_replies'] === ['كلامك فرحنا نورتنا ❤️'],
        'accepted multiline response is normalized before it can poison subsequent model inputs');
    $historyProvider = new \App\Services\Dashboard\MetaCommentAiProvider(function ($body, $key) {
        return response(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant',
            'status' => 'completed', 'content' => [['type' => 'output_text',
                'text' => json_encode(['kind' => 'REPLY', 'message' => 'كلامك يسعدنا ❤️'], JSON_UNESCAPED_UNICODE)]]]]]);
    }, ['model' => 'gpt-6-luna', 'api_key' => 'fixture-not-a-live-key']);
    check($historyProvider->reply($provider->contexts[1])['ok'], 'real provider accepts the worker multiline style sample');
    clearState(); $replyRows['701'] = [comment('880', '101')];
    $worker->run(); check($postWrites === 0 && $provider->calls === 0, 'existing business reply suppresses AI before generation');
    clearState(); $provider->hook = function () use (&$replyRows) { $replyRows['701'] = [comment('880', '101')]; };
    $worker->run(); check($postWrites === 0 && $provider->calls === 1, 'fresh reply check catches manual reply arriving during generation');
    clearState();
    $post = $service->posts($actor, $fbKey)['posts'][0]; $manual = $service->comments($actor, $post['handle'])['comments'][0];
    $input = ['comment_handle' => $manual['handle'], 'message' => 'رد يدوي', 'client_request_id' => MetaCommentReplyGate::uuid()];
    $accepted = $service->send($actor, $input); $worker->run();
    check($accepted['state'] === 'ACCEPTED' && $postWrites === 1 && $provider->calls === 0, 'accepted manual receipt blocks automatic response');
    check($service->send($actor, $input)['replayed'] && $postWrites === 1, 'same manual UUID still returns its receipt');
    $input['client_request_id'] = MetaCommentReplyGate::uuid();
    check($service->send($actor, $input)['state'] === 'ACCEPTED' && $postWrites === 2, 'deliberate further manual reply remains allowed');
    clearState();
    $post = $service->posts($actor, $fbKey)['posts'][0]; $manual = $service->comments($actor, $post['handle'])['comments'][0];
    $other = clone $actor; $other->id = 2; $nested = null;
    $onWrite = function () use (&$onWrite, &$nested, $service, $other, $manual) {
        $onWrite = null;
        try { $service->send($other, ['comment_handle' => $manual['handle'], 'message' => 'ثانٍ', 'client_request_id' => MetaCommentReplyGate::uuid()]); }
        catch (\App\Services\Dashboard\MetaCommentsFailure $error) { $nested = $error->reason; }
    };
    $service->send($actor, ['comment_handle' => $manual['handle'], 'message' => 'أول', 'client_request_id' => MetaCommentReplyGate::uuid()]);
    check($nested === 'REPLY_IN_PROGRESS' && $postWrites === 1, 'shared gate blocks concurrent different-admin sends as definitely unsent');
    clearState(); $uncertain = true;
    $run = $worker->run(); $worker->run();
    check($run['unknown'] === 1 && $postWrites === 1 && $provider->calls === 1, 'uncertain AI transport is durable and never automatically retried');
    $styleState = json_decode(Crypt::decryptString(DB::table('meta_comment_ai_poll_states')->where('account_key', $fbKey)->first()->state), true);
    check($styleState['recent_replies'] === [], 'uncertain public sends never become published style examples');
    $post = $service->posts($actor, $fbKey)['posts'][0]; $manual = $service->comments($actor, $post['handle'])['comments'][0];
    check($service->send($other, ['comment_handle' => $manual['handle'], 'message' => 'ثانٍ', 'client_request_id' => MetaCommentReplyGate::uuid()])['state'] === 'UNKNOWN'
        && $postWrites === 1, 'uncertain AI receipt also blocks manual retry from another actor');
    clearState(); $provider->hook = function () use ($control) { $control->blocked = true; };
    $run = $worker->run();
    check($run['reason'] === 'DISPATCH_STOPPED' && $postWrites === 0, 'stop/policy revision after generation prevents publishing');
    $control->blocked = false; $provider->hook = null; $worker->run();
    check($postWrites === 1, 'unpublished pending candidate survives restart/stop');
    clearState(); $provider->skip = true; $worker->run(); $worker->run();
    check($postWrites === 0 && $provider->calls === 1 && DB::table('meta_comment_ai_targets')->where('reason', 'AI_SKIPPED')->exists(), 'provider skip is terminal without sending or blocking the queue');
    clearState(); $provider->transient = true;
    $worker->run(); $worker->run();
    check($postWrites === 0 && $provider->calls === 1 && !DB::table('meta_comment_ai_targets')->where('state', 'SKIPPED')->exists(), 'transient SKIP is deferred with backoff, not permanently skipped or repeatedly billed');
    $provider->transient = false; $clockNow = $clockNow->modify('+61 seconds'); $worker->run();
    check($postWrites === 1 && $provider->calls === 2, 'transient candidate retries after backoff and then sends once');
    clearState();
    $commentRows['101_501'] = []; for ($i = 1; $i <= 27; $i++) $commentRows['101_501'][] = comment((string) (1000 + $i));
    $commentRows['101_400'] = [comment('2001')];
    for ($runNumber = 0; $runNumber < 20; $runNumber++) $worker->run(30, 4);
    check($postWrites === 28, 'durable page remainder/deep cursor and historical posts all receive one reply');
    check(count(array_filter($calls, static fn ($call) => $call[0] === 'GET' && ($call[2]['after'] ?? '') === 'MORE')) > 0, 'comment pagination beyond first25 is read');
    check(count(array_filter($calls, static fn ($call) => $call[0] === 'GET' && $call[1] === '101_400/comments')) > 0, 'new comment on old post is polled by historical rotation');
    clearState();
    $commentRows['101_501'] = []; for ($i = 1; $i <= 10; $i++) $commentRows['101_501'][] = comment((string) (3000 + $i));
    $postRows['202'] = [['id' => '600', 'caption' => 'Instagram post']]; $commentRows['600'] = [comment('800')];
    $worker->run(30, 1); $worker->run(30, 1);
    check(count(array_filter($calls, static fn ($call) => $call[0] === 'POST' && $call[1] === '800/replies')) === 1, 'busy Facebook account does not starve Instagram polling/sending');
    clearState(); $postRows['101'] = [];
    $postRows['202'] = [['id' => '600', 'caption' => 'Instagram post']];
    $commentRows['600'] = [comment('800') + ['username' => 'OFFICIAL_BUSINESS'], comment('801')];
    $replyRows['801'] = [comment('802') + ['username' => 'Official_Business']];
    $worker->run();
    check($postWrites === 0 && $provider->calls === 0 && DB::table('meta_comment_ai_targets')->where('reason', 'BUSINESS_REPLIED')->exists(), 'fresh verified Instagram username identifies own comment/reply when author ID is omitted');
    check(count(array_filter($calls, static fn ($call) => $call[0] === 'GET' && $call[1] === '202')) === 1, 'Instagram native identity is read once per bounded worker pass');
    clearState();
    $a = $service->posts($actor, $fbKey); $b = $service->posts($actor, $fbKey);
    check(count($calls) === 1 && $a['posts'][0]['handle'] !== $b['posts'][0]['handle'], 'GET cache reuses provider body while minting fresh handles');
    $service->automationPosts($actor, $fbKey); $service->automationPosts($actor, $fbKey);
    check(count($calls) === 3, 'automation reads bypass dashboard cache');
    config()->set('meta_messaging.accounts.0.access_token', str_repeat('z', 24)); $service->posts($actor, $fbKey);
    check(count($calls) === 4, 'credential binding separates cache across token rotation');
    config()->set('meta_messaging.accounts', $accounts); $actor->active = false;
    try { $service->posts($actor, $fbKey); check(false, 'revoked actor denied on cached read'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { check($error->getStatusCode() === 403, 'revoked actor denied on cached read'); }
    $actor->active = true; $manual = $service->comments($actor, $a['posts'][0]['handle'])['comments'][0];
    $service->send($actor, ['comment_handle' => $manual['handle'], 'message' => 'رد يدوي', 'client_request_id' => MetaCommentReplyGate::uuid()]);
    $before = count($calls); $service->posts($actor, $fbKey);
    check(count($calls) === $before + 1, 'successful public reply invalidates cached account reads');
    $service->setAutomationDeadline(hrtime(true) - 1); $before = count($calls);
    try { $service->automationPosts($actor, $fbKey); check(false, 'expired automation deadline refuses I/O'); }
    catch (\App\Services\Dashboard\MetaCommentsFailure $error) { check($error->reason === 'WORKER_BUDGET' && count($calls) === $before, 'expired automation deadline refuses I/O'); }
    $service->setAutomationDeadline(null);
    // A busy old comment cursor must not prevent a fresh read of the newest Instagram post.
    clearState();
    $freshHeadRows = []; $freshHeadReads = 0; $freshWrites = 0; $oldRows = [];
    for ($i = 0; $i < 1000; $i++) $oldRows[] = comment((string) (10000 + $i), null, '2026-10-15T10:00:00+0000');
    $freshTransport = function ($method, $url, $query, $token) use (&$freshHeadRows, &$freshHeadReads, &$freshWrites, &$oldRows) {
        $path = substr(parse_url($url, PHP_URL_PATH), strlen('/v25.0/'));
        if ($method === 'POST') { $freshWrites++; return response(['id' => (string) (9900 + $freshWrites)]); }
        if ($path === '202') return response(['id' => '202', 'username' => 'Official_Business']);
        if ($path === '202/media') return response(['data' => [['id' => '600', 'caption' => 'Newest post'], ['id' => '601', 'caption' => 'Old busy post']]]);
        if ($path === '600/comments') { $freshHeadReads++; return response(['data' => $freshHeadRows]); }
        if ($path === '601/comments') {
            $offset = isset($query['after']) ? (int) substr($query['after'], 1) : 0;
            $body = ['data' => array_slice($oldRows, $offset, 25)];
            if ($offset + 25 < count($oldRows)) $body['paging'] = ['cursors' => ['after' => 'C'.($offset + 25)], 'next' => 'https://graph.facebook.com/ignored'];
            return response($body);
        }
        return response(['data' => []]);
    };
    $freshService = new MetaCommentsService($freshTransport);
    $freshWorker = new MetaCommentAiWorker($control, $freshService, $provider, $gate, static fn () => $actor,
        static function () use (&$clockNow) { return $clockNow; });
    $igKey = hash('sha256', 'instagram:202');
    $pollQueue = static function () use ($igKey) {
        $row = DB::table('meta_comment_ai_poll_states')->where('account_key', $igKey)->first();
        return json_decode(Crypt::decryptString($row->state), true, 64, JSON_THROW_ON_ERROR)['queue'];
    };
    $queued = static function (array $queue, string $id): ?array {
        foreach ($queue as $post) if ($post['native_id'] === $id) return $post;
        return null;
    };
    $cursorOffset = static function (array $post): int {
        return (int) substr(json_decode(Crypt::decryptString($post['cursor']), true, 16, JSON_THROW_ON_ERROR)['after'], 1);
    };
    $initial = $freshWorker->run(); $beforeDeep = $queued($pollQueue(), '601');
    check($initial['attempted'] === 0 && $initial['errors'] === 0 && $beforeDeep !== null && $beforeDeep['cursor'] !== null, 'old pre-activation thread retains a deep cursor without generating or sending');
    $freshHeadRows = [comment('98001')];
    $freshRun = $freshWorker->run(); $afterDeep = $queued($pollQueue(), '601');
    check($freshRun['sent'] === 1 && $freshWrites === 1 && $provider->calls === 1 && $freshHeadReads === 2, 'new Instagram comment arriving after the first pass is sent on the next pass despite an old busy queue');
    check($afterDeep !== null && $cursorOffset($afterDeep) > $cursorOffset($beforeDeep), 'fresh-head lane preserves and advances the older deep cursor instead of resetting or starving it');
    $freshWorker->run(); check($freshWrites === 1 && $provider->calls === 1, 'fresh Instagram head rescans preserve the exactly-once target gate');

    clearState(); $freshWrites = 0; $freshHeadRows = [comment('98002')]; $provider->transient = true;
    $failedHead = $freshWorker->run(); $savedHead = $queued($pollQueue(), '600'); $savedDeep = $queued($pollQueue(), '601');
    check($failedHead['errors'] === 1 && $savedHead !== null && $savedHead['pending'][0]['failures'] === 1, 'transient head failure is kept in the existing bounded retry queue');
    $retryAt = $savedHead['pending'][0]['retry_at'];
    $freshWorker->run(); $rescanHead = $queued($pollQueue(), '600'); $rescanDeep = $queued($pollQueue(), '601');
    check($provider->calls === 1 && $freshWrites === 0 && $rescanHead['pending'][0]['failures'] === 1 && $rescanHead['pending'][0]['retry_at'] === $retryAt, 'fresh DTO merge retains retry_at and failures without rebilling during backoff');
    check(count($rescanHead['pending']) === 1 && $cursorOffset($rescanDeep) > $cursorOffset($savedDeep), 'fresh/native ID deduplication keeps one pending row while old cursor progress continues');
    $provider->transient = false; $clockNow = $clockNow->modify('+61 seconds');
    $freshWorker->run(); check($freshWrites === 1 && $provider->calls === 2, 'deferred fresh head sends once after its original backoff expires');
    clearState();
    $post = $service->posts($actor, $fbKey)['posts'][0]; $manual = $service->comments($actor, $post['handle'])['comments'][0];
    $denied = new MetaCommentsService(static fn () => response(['error' => ['code' => 200]], 403));
    $failed = $denied->send($actor, ['comment_handle' => $manual['handle'], 'message' => 'يدوي', 'client_request_id' => MetaCommentReplyGate::uuid()]);
    check($failed['state'] === 'FAILED' && DB::table('meta_comment_ai_targets')->where('state', 'FAILED')->exists(), 'definitive Graph rejection releases shared in-flight state');
    check($service->send($actor, ['comment_handle' => $manual['handle'], 'message' => 'يدوي', 'client_request_id' => MetaCommentReplyGate::uuid()])['state'] === 'ACCEPTED', 'definitively unsent manual target can be retried explicitly');
    $directory = sys_get_temp_dir() . '/meta-comment-ai-lock-' . bin2hex(random_bytes(6)); mkdir($directory, 0700);
    $path = $directory . '/dispatch.lock'; $lock = new \App\Services\Dashboard\MetaCommentAiDispatchLock(null, $path);
    check($lock->run(static fn () => 17) === 17 && (fileperms($path) & 0777) === 0600, 'private real dispatch lock executes callback');
    $nestedLock = new \App\Services\Dashboard\MetaCommentAiDispatchLock(null, $path);
    check($lock->run(static fn () => $nestedLock->run(static fn () => 99, 0)) === null, 'overlapping lock cannot dispatch');
    unlink($path); symlink($directory . '/target', $path);
    check($lock->run(static fn () => 99, 0) === null, 'symlink lock path rejected');
    unlink($path); rmdir($directory);
    \Illuminate\Support\Facades\Schema::drop('meta_comment_ai_targets'); $before = $postWrites;
    check($service->send($actor, ['comment_handle' => $manual['handle'], 'message' => 'يدوي', 'client_request_id' => MetaCommentReplyGate::uuid()])['state'] === 'ACCEPTED'
        && $postWrites === $before + 1, 'manual replies remain functional before AI migration is installed');
    echo "meta-comment-ai-worker: $checks checks passed\n";
}
