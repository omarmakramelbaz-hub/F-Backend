<?php
namespace {
    $vendor = getenv('WA_INBOX_TEST_VENDOR');
    require is_dir($vendor) ? $vendor . '/autoload.php' : $vendor;
    function app($name = null) { $c = \Illuminate\Container\Container::getInstance(); return $name === null ? $c : $c->make($name); }
    function config($name = null, $default = null) { return app('config')->get($name, $default); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function abort_unless($ok, $status) { if (!$ok) throw new \Symfony\Component\HttpKernel\Exception\HttpException($status); }
}
namespace App\Models {
    class User {
        public int $id; public bool $active = true;
        public static array $users = [];
        public function __construct(int $id) { $this->id = $id; }
        public static function query() { return new class { public function find($id) { return User::$users[$id] ?? null; } }; }
    }
}
namespace App\Services\Dashboard {
    class MetaInboxAccess {
        public function actor($actor) { \abort_unless($actor && $actor->active, 403); return $actor; }
        public function owner($actor) { $this->actor($actor); \abort_unless($actor->id === 1, 403); return $actor; }
        public function canConnect($actor): bool { return $actor->id === 1 && $actor->active; }
    }
    class MetaCommentAiProvider {
        public static bool $configured = true;
        public function ready(): bool { return self::$configured; }
    }
    class MetaCommentAiDispatchLock {
        public static bool $busy = false;
        public function run(callable $operation, int $timeoutMilliseconds = 2500) { return self::$busy ? null : $operation(); }
    }
}
namespace {
    use App\Models\User;
    use App\Services\Dashboard\MetaCommentAiControl;
    use App\Services\Dashboard\MetaCommentAiDispatchLock;
    use App\Services\Dashboard\MetaCommentAiProvider;
    use App\Services\Dashboard\MetaCommentsFailure;
    use Illuminate\Container\Container;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\DB;
    $container = new Container;
    Container::setInstance($container); Facade::setFacadeApplication($container);
    $container->instance('config', new Repository(['desktop_dashboard' => ['local' => false]]));
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal(); $capsule->bootEloquent();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn () => $capsule->schema());
    require __DIR__ . '/../app/Services/Dashboard/MetaCommentsFailure.php';
    require __DIR__ . '/../app/Services/Dashboard/MetaCommentAiControl.php';
    require __DIR__ . '/../database/migrations/2026_10_14_210000_create_meta_comment_reply_requests.php';
    require __DIR__ . '/../database/migrations/2026_10_15_010000_create_meta_comment_ai_tables.php';
    (new \CreateMetaCommentReplyRequests)->up();
    (new \CreateMetaCommentAiTables)->up();
    $checks = 0;
    function check($yes, string $message): void { global $checks; $checks++; if (!$yes) throw new \RuntimeException($message); }
    function fails(callable $operation, string $reason, int $status): void {
        try { $operation(); } catch (MetaCommentsFailure $error) { check($error->reason === $reason && $error->status === $status, $reason); return; }
        throw new \RuntimeException('Expected ' . $reason);
    }
    $control = new MetaCommentAiControl;
    $owner = new User(1); User::$users[1] = $owner;
    $admin = new User(2); User::$users[2] = $admin;
    $status = $control->status($owner);
    check($status['control']['enabled'] === false && $status['control']['active_now'] === false, 'migration installs OFF');
    check($status['can_manage'] && !isset($status['control']['actor_id']), 'owner controls omit internal actor');
    check(!$control->status($admin)['can_manage'], 'central admin may read but cannot operate');
    $request = ['enabled' => true, 'mode' => 'always', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '10:00', 'end' => '23:00', 'expected_revision' => 0];
    try { $control->update($admin, $request); throw new \RuntimeException('admin enabled automation'); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { check($error->getStatusCode() === 403, 'fresh owner authorization'); }
    foreach ([['days' => [1, 1]], ['days' => ['1']], ['days' => [0]], ['days' => []], ['start' => '24:00'], ['end' => '10:00'], ['enabled' => '1'], ['expected_revision' => '0'], ['timezone' => 'UTC']] as $change) {
        fails(fn () => $control->update($owner, array_merge($request, $change)), 'INVALID_REQUEST', 422);
    }
    MetaCommentAiProvider::$configured = false;
    fails(fn () => $control->update($owner, $request), 'AI_NOT_CONFIGURED', 422);
    check(!$control->snapshot()['enabled'], 'missing provider cannot activate');
    MetaCommentAiProvider::$configured = true;
    MetaCommentAiDispatchLock::$busy = true;
    fails(fn () => $control->update($owner, $request), 'AI_BUSY', 409);
    MetaCommentAiDispatchLock::$busy = false;
    $first = $control->update($owner, $request)['control'];
    check($first['enabled'] && $first['revision'] === 1 && $first['enabled_at'] !== null, 'explicit enable establishes revision and cutoff');
    fails(fn () => $control->update($owner, $request), 'REVISION_CONFLICT', 409);
    $request['expected_revision'] = 1;
    $same = $control->update($owner, $request)['control'];
    check($same['revision'] === 1 && $same['enabled_at'] === $first['enabled_at'], 'idempotent save preserves activation cutoff');
    (new \CreateMetaCommentAiTables)->up();
    check($control->snapshot()['enabled'], 'migration rerun never resets established owner policy');
    $request['enabled'] = false;
    MetaCommentAiProvider::$configured = false;
    $off = $control->update($owner, $request)['control'];
    check(!$off['enabled'] && $off['enabled_at'] === null && $off['revision'] === 2, 'stop remains available without AI credentials');
    check($control->withActiveDispatch($first, fn () => 'sent') === null, 'stopped/revised generation cannot send');
    MetaCommentAiProvider::$configured = true;
    $policy = ['enabled' => true, 'mode' => 'scheduled', 'days' => [5], 'start' => '22:00', 'end' => '02:00',
        'timezone' => 'Africa/Cairo', 'enabled_at' => '2026-10-01T00:00:00Z', 'revision' => 1];
    $local = static fn (string $value) => new \DateTimeImmutable($value, new \DateTimeZone('Africa/Cairo'));
    check(!$control->activeAt($policy, $local('2026-10-16 21:59:59')), 'overnight before start is off');
    check($control->activeAt($policy, $local('2026-10-16 22:00:00')), 'overnight Friday start inclusive');
    check($control->activeAt($policy, $local('2026-10-17 01:59:59')), 'Saturday tail belongs Friday');
    check(!$control->activeAt($policy, $local('2026-10-17 02:00:00')), 'overnight end exclusive');
    check(!$control->activeAt($policy, $local('2026-10-17 22:10:00')), 'Saturday start not selected');
    check(!$control->eligible($policy, $local('2026-10-16 21:59:59'), $local('2026-10-16 22:20:00')), 'comments outside schedule never backlog');
    check($control->eligible($policy, $local('2026-10-16 23:10:00'), $local('2026-10-17 01:00:00')), 'new current-window comment eligible');
    check(!$control->eligible($policy, $local('2026-10-09 23:10:00'), $local('2026-10-17 01:00:00')), 'previous weekly window does not flood');
    check(!$control->eligible($policy, null, $local('2026-10-17 01:00:00')), 'missing provider timestamp is ineligible');
    check(!$control->eligible($policy, $local('2026-10-17 01:10:00'), $local('2026-10-17 01:00:00')), 'future provider timestamp is ineligible');
    $policy['mode'] = 'always'; $policy['enabled_at'] = '2026-10-15T00:00:00Z';
    check(!$control->eligible($policy, '2026-10-14T23:59:59Z', '2026-10-15T00:02:00Z'), 'pre-activation old comment rejected');
    check($control->eligible($policy, '2026-10-15T00:00:00Z', '2026-10-15T00:02:00Z'), 'activation cutoff inclusive');
    $policy['mode'] = 'scheduled'; $policy['days'] = [1, 2, 3, 4, 5, 6, 7]; $policy['start'] = '10:00'; $policy['end'] = '12:00';
    check($control->activeAt($policy, '2026-10-22T07:00:00Z'), 'Cairo daylight saving uses UTC+3');
    check(!$control->activeAt($policy, '2026-11-05T07:00:00Z'), 'Cairo winter 09:00 is outside window');
    check($control->activeAt($policy, '2026-11-05T08:00:00Z'), 'Cairo winter uses UTC+2');
    $request = ['enabled' => true, 'mode' => 'always', 'days' => [1, 2, 3, 4, 5, 6, 7], 'start' => '10:00', 'end' => '23:00', 'expected_revision' => 2];
    $control->update($owner, $request);
    DB::table('meta_comment_ai_settings')->where('id', 1)->update(['enabled_at' => '2020-01-01 00:00:00']);
    $fresh = $control->snapshot();
    check($control->withActiveDispatch($fresh, fn () => 'sent') === 'sent', 'fresh active authorized dispatch is allowed');
    $owner->active = false;
    check($control->withActiveDispatch($fresh, fn () => 'sent') === null, 'revoked persisted owner prevents dispatch');
    $owner->active = true;
    $container['config']->set('desktop_dashboard.local', true);
    check(!$control->ready() && $control->snapshot() === null, 'local offline desktop never automates public replies');
    fails(fn () => $control->status($owner), 'CENTRAL_ONLY', 403);
    $container['config']->set('desktop_dashboard.local', false);
    $control->recordHeartbeat('TOKEN=private');
    check(DB::table('meta_comment_ai_settings')->value('last_reason') === null, 'heartbeat never stores arbitrary provider messages');
    echo "meta-comment-ai-control: $checks checks passed\n";
}
