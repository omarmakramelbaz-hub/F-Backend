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
namespace App\Models { class User { public $id = 1; public $active = true; public $account_type = 'admin'; public $owner_resturant_id = null; public function getKey() { return $this->id; } } }
namespace App\Services\Dashboard {
    class MetaCommentsFailure extends \RuntimeException {
        public function __construct(public string $reason, public int $status = 503, public array $permissions = []) { parent::__construct($reason); }
    }
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
    foreach (['WhatsAppInboxAccess', 'MetaInboxAccess', 'MetaCommentsService'] as $name) require __DIR__ . '/../app/Services/Dashboard/' . $name . '.php';
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
    $reads = 0;
    $pending = new Comments(function($method, $endpoint, $query, $token) use (&$reads) {
        $reads++; check($method === 'GET', 'badges cannot publish replies');
        $path = parse_url($endpoint, PHP_URL_PATH);
        if (str_ends_with($path, '/101/published_posts')) return jsonResponse(['data'=>[['id'=>'101_901']]]);
        if (str_ends_with($path, '/202/media')) return jsonResponse(['data'=>[['id'=>'901']]]);
        if (str_ends_with($path, '/202')) return jsonResponse(['id'=>'202','username'=>'fasakhansta']);
        if (str_ends_with($path, '/906/comments')) return jsonResponse(['data'=>[['id'=>'809','from'=>['id'=>'101']]]]);
        if (str_ends_with($path, '/901/comments')) return jsonResponse(['data'=>[
            ['id'=>'911','username'=>'buyer','replies'=>['data'=>[]]],
            ['id'=>'912','username'=>'fasakhansta','from'=>['id'=>'999']],
            ['id'=>'913','username'=>'buyer','replies'=>['data'=>[['id'=>'881','username'=>'FASAKHANSTA']]]],
        ]]);
        if (str_ends_with($path, '/101_901/comments')) {
            check(str_contains($query['fields'],'comments.limit(10){id,from}'), 'one bounded nested read establishes reply ownership');
            if (isset($query['after'])) return jsonResponse(['data'=>[['id'=>'907','from'=>['id'=>'309'],'comment_count'=>0]]]);
            return jsonResponse(['data'=>[
                ['id'=>'901','from'=>['id'=>'301'],'comment_count'=>0],
                ['id'=>'902','from'=>['id'=>'302'],'comments'=>['data'=>[['id'=>'802','from'=>['id'=>'101']]]]],
                ['id'=>'903','from'=>['id'=>'303'],'comments'=>['data'=>[['id'=>'803','from'=>['id'=>'999']]]]],
                ['id'=>'904','from'=>['id'=>'101']], ['id'=>'905'],
                ['id'=>'906','from'=>['id'=>'306'],'comments'=>['data'=>[['id'=>'806','from'=>['id'=>'999']]],'paging'=>['next'=>'https://not-followed.invalid/?access_token=SECRET','cursors'=>['after'=>'REPLYNEXT']]]],
            ],'paging'=>['next'=>'https://not-followed.invalid/?access_token=SECRET','cursors'=>['after'=>'ROOTNEXT']]]);
        }
        throw new \RuntimeException('Unexpected fixture request');
    });
    $all = $pending->accounts($actor)['accounts'];
    $fb = $all[0]['account_key']; $ig = $all[1]['account_key'];
    $fbPost = $pending->posts($actor,$fb)['posts'][0];
    $page = $pending->pendingPage($actor,$fbPost['handle']);
    check(array_column($page['comments'],'status') === ['pending','answered','pending','business','unknown','checking'], 'customers, actual business replies, other-customer replies, self comments and missing authors are distinguished');
    check($page['next_cursor'] !== null && $page['comments'][5]['reply_cursor'] !== null, 'root and nested continuation are signed');
    check(!str_contains(json_encode($page),'SECRET') && !isset($page['comments'][0]['native_id']), 'counts disclose no private native IDs, bearer tokens or provider pagination URLs');
    $reply = $pending->pendingPage($actor,$page['comments'][5]['handle'],$page['comments'][5]['reply_cursor'],true);
    check($reply['has_business_reply'] === true && $reply['next_cursor'] === null, 'later reply pages establish a business reply');
    check(count($pending->pendingPage($actor,$fbPost['handle'],$page['next_cursor'])['comments']) === 1, 'all root pages remain accessible');
    expectFailure(fn()=> $pending->pendingPage($actor,$fbPost['handle'],$page['comments'][5]['reply_cursor']), 'INVALID_CURSOR', 'reply cursor cannot be reused as root cursor');
    $igPost = $pending->posts($actor,$ig)['posts'][0];
    check(array_column($pending->pendingPage($actor,$igPost['handle'])['comments'],'status') === ['pending','business','answered'], 'native Instagram identity distinguishes scoped self IDs and case insensitive own usernames');
    $malformed = new Comments(fn()=> jsonResponse(['data'=>[['id'=>'991','from'=>['id'=>'301'],'comments'=>['data'=>[],'paging'=>['next'=>'https://never-followed.invalid']]]]]));
    expectFailure(fn()=> $malformed->pendingPage($actor,$fbPost['handle']), 'META_UNAVAILABLE', 'incomplete paging never becomes a false zero');
    $missing = new Comments(fn()=> jsonResponse(['unavailable'=>'no data']));
    expectFailure(fn()=> $missing->pendingPage($actor,$fbPost['handle']), 'META_UNAVAILABLE', 'missing provider data is unknown instead of zero');
    $branch = new \App\Models\User; $branch->owner_resturant_id = 9; $before=$reads;
    expectDenied(fn()=> $pending->pendingPage($branch,$fbPost['handle']), 'branch account cannot scan central social comments');
    check($reads===$before, 'authorization occurs before provider requests');
    echo "META_PENDING_SERVICE_PASS $checks checks; liveMeta=false; sends=0\n";
}
