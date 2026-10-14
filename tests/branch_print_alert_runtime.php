<?php
/** Real branch print listing queries and SQLite; no production connection or writes.
 * BRANCH_PRINT_TEST_VENDOR=/path/to/vendor php tests/branch_print_alert_runtime.php
 */
namespace {
    function app($abstract = null) { $app = \Illuminate\Container\Container::getInstance(); return $abstract === null ? $app : $app->make($abstract); }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function abort($status, $message = '') { throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message); }
    function abort_unless($value, $status, $message = '') { if (!$value) abort($status, $message); }
    $vendor = getenv('BRANCH_PRINT_TEST_VENDOR');
    if (is_dir((string) $vendor)) $vendor .= '/autoload.php';
    if (!$vendor || !is_file($vendor)) throw new \RuntimeException('Set BRANCH_PRINT_TEST_VENDOR to an installed Laravel vendor directory.');
    require $vendor;
}
namespace App\Services\Dashboard {
    // Authorization fixture only: the actual printing service, validator and queries run unchanged.
    class TakeawayAccess {
        public array $calls = [];
        public function branch(string $branch, $actor, bool $lock = false): array {
            $this->calls[] = [$branch, $actor->id, $lock];
            \abort_unless(in_array($branch, $actor->allowed_branches, true), 404);
            return ['value' => $branch];
        }
        public function receiverBranch($actor): ?string { return $actor->receiver_branch; }
    }
}
namespace {
    use App\Services\Dashboard\PosBranchPrinting;
    use App\Services\Dashboard\TakeawayAccess;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Schema;

    require dirname(__DIR__).'/app/Services/Dashboard/PosBranchPrinting.php';
    $app = new Container(); Container::setInstance($app);
    $db = new Capsule($app);
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $db->setAsGlobal(); $db->bootEloquent();
    $app->instance('db', $db->getDatabaseManager());
    $app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
    $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en');
    $app->instance('validator', new \Illuminate\Validation\Factory($translator, $app));
    Facade::setFacadeApplication($app);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-14 19:30:00', 'UTC'));
    Schema::create('users', function (Blueprint $t) {
        $t->id(); $t->string('account_type'); $t->integer('owner_resturant_id')->nullable();
    });
    Schema::create('pos_service_tickets', function (Blueprint $t) {
        $t->id(); $t->string('branch'); $t->string('channel'); $t->string('status');
        $t->integer('actor_id'); $t->string('payment_status'); $t->string('customer_name');
    });
    Schema::create('pos_branch_print_jobs', function (Blueprint $t) {
        $t->id(); $t->string('branch'); $t->integer('ticket_id'); $t->integer('kitchen_id');
        $t->string('status'); $t->timestamp('claimed_at')->nullable(); $t->timestamps();
    });
    $checks = 0;
    function same($expected, $actual, string $label): void {
        global $checks; $checks++;
        if ($expected !== $actual) throw new \RuntimeException($label.': '.var_export($actual, true));
    }
    function blocked(callable $call, int $status, string $label): void {
        try { $call(); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { same($status, $error->getStatusCode(), $label); return; }
        catch (\Illuminate\Validation\ValidationException $error) { same($status, 422, $label); return; }
        throw new \RuntimeException($label.' was allowed');
    }
    function ticket(int $id, string $branch = 'f:1', string $channel = 'phone', string $status = 'preparing', int $actorId = 30, string $payment = 'unpaid'): void {
        DB::table('pos_service_tickets')->insert(compact('id', 'branch', 'channel', 'status') +
            ['actor_id' => $actorId, 'payment_status' => $payment, 'customer_name' => 'Private fixture customer']);
    }
    function job(int $id, int $ticketId, string $branch = 'f:1', string $status = 'pending', $claimedAt = null): void {
        DB::table('pos_branch_print_jobs')->insert(['id' => $id, 'ticket_id' => $ticketId, 'branch' => $branch,
            'kitchen_id' => $ticketId * 10, 'status' => $status, 'claimed_at' => $claimedAt, 'created_at' => now(), 'updated_at' => now()]);
    }
    function snapshot(): array {
        return [DB::table('users')->orderBy('id')->get()->toJson(), DB::table('pos_service_tickets')->orderBy('id')->get()->toJson(), DB::table('pos_branch_print_jobs')->orderBy('id')->get()->toJson()];
    }
    DB::table('users')->insert([
        ['id' => 10, 'account_type' => 'admin', 'owner_resturant_id' => 1],
        ['id' => 20, 'account_type' => 'admin', 'owner_resturant_id' => null],
        ['id' => 21, 'account_type' => 'admin', 'owner_resturant_id' => 0],
        ['id' => 30, 'account_type' => 'vendor', 'owner_resturant_id' => null],
        ['id' => 31, 'account_type' => 'resturant_owner', 'owner_resturant_id' => 1],
    ]);
    $access = new TakeawayAccess(); $printing = new PosBranchPrinting($access);
    $branchActor = (object) ['id' => 10, 'allowed_branches' => ['f:1'], 'receiver_branch' => 'f:1'];
    $beforeReads = 0;
    function readListing(PosBranchPrinting $printing, $actor, string $branch = 'f:1'): array {
        global $beforeReads;
        $before = snapshot(); $result = $printing->listing(['branch' => $branch], $actor);
        same($before, snapshot(), 'listing never mutates tickets or print jobs'); $beforeReads++;
        return $result;
    }

    // Higher IDs must not become print deliveries for unsent, cancelled, foreign or non-phone orders.
    ticket(900); // Locally created unsent draft: neither call-center arrival nor print delivery.
    ticket(1000, 'f:1', 'phone', 'cancelled'); job(1000, 1000);
    ticket(1200, 'f:2'); job(1200, 1200, 'f:2');
    ticket(1300, 'f:2'); job(1300, 1300, 'f:1'); // Mismatched ticket tenant.
    ticket(1400, 'f:1', 'takeaway'); job(1400, 1400);
    ticket(1500); job(1500, 1500, 'f:2'); // Mismatched job tenant.
    job(1600, 9999); // Orphaned job cannot ring or print.
    $result = readListing($printing, $branchActor);
    same(['success' => true, 'branch' => 'f:1', 'jobs' => [], 'attention' => 0, 'latest_ticket_id' => 0, 'incoming_ticket_ids' => []], $result, 'excluded tickets leave branch alert idle');

    ticket(10); job(10, 10);
    $result = readListing($printing, $branchActor);
    same(10, $result['latest_ticket_id'], 'valid pending delivery advances queued delivery cursor');
    same([['id' => 10, 'ticket_id' => 10]], $result['jobs'], 'pending queue excludes cancelled and foreign jobs');
    same(0, $result['attention'], 'pending delivery needs no printer review');

    ticket(20); job(20, 20, 'f:1', 'claimed', now()->subMinutes(3));
    $result = readListing($printing, $branchActor);
    same(20, $result['latest_ticket_id'], 'already claimed delivery remains in queued delivery cursor');
    same([['id' => 10, 'ticket_id' => 10]], $result['jobs'], 'claim does not requeue or duplicate a print');
    same(1, $result['attention'], 'stale claim still needs review');

    ticket(30); job(30, 30, 'f:1', 'claimed', now()->subMinute());
    ticket(40); job(40, 40, 'f:1', 'failed');
    ticket(50); job(50, 50, 'f:1', 'invoked');
    job(1001, 1000, 'f:1', 'failed'); // Cancelled job cannot inflate attention.
    job(1301, 1300, 'f:1', 'claimed', now()->subMinutes(3));
    $result = readListing($printing, $branchActor);
    same(50, $result['latest_ticket_id'], 'completed printer invocation remains a real received ticket');
    same(2, $result['attention'], 'only valid failed and stale claimed jobs need review');
    same([['id' => 10, 'ticket_id' => 10]], $result['jobs'], 'printer states do not alter pending queue');

    for ($id = 51; $id <= 61; $id++) { ticket($id); job($id, $id); }
    $result = readListing($printing, $branchActor);
    same(61, $result['latest_ticket_id'], 'latest valid delivery survives ten-job page limit');
    same(array_merge([['id' => 10, 'ticket_id' => 10]], array_map(fn ($id) => ['id' => $id, 'ticket_id' => $id], range(51, 59))), $result['jobs'], 'pending print jobs retain ordering and ten-job cap');
    same(2, $result['attention'], 'pending queue pagination preserves attention count');
    same(5, $beforeReads, 'all successful scenarios verified read-only behavior');

    // A real call-center arrival must ring independently of an optional kitchen/print send.
    ticket(2000, 'f:1', 'phone', 'new', 20);
    ticket(2001, 'f:1', 'phone', 'preparing', 21);
    ticket(2010, 'f:1', 'phone', 'new', 10); // Branch-bound admin.
    ticket(2011, 'f:1', 'phone', 'new', 30); // Branch vendor.
    ticket(2012, 'f:1', 'phone', 'new', 31); // Restaurant owner.
    ticket(2013, 'f:2', 'phone', 'new', 20);
    ticket(2014, 'f:1', 'takeaway', 'new', 20);
    ticket(2015, 'f:1', 'phone', 'new', 20, 'paid');
    ticket(2016, 'f:1', 'phone', 'cancelled', 20);
    ticket(2017, 'f:1', 'phone', 'completed', 20);
    ticket(2018, 'f:1', 'phone', 'out_for_delivery', 20);
    ticket(2019, 'f:1', 'phone', 'new', 9999); // Missing persisted creator.
    $result = readListing($printing, $branchActor);
    same([2001, 2000], $result['incoming_ticket_ids'], 'only current-branch unpaid new/preparing call-center arrivals included');
    same(61, $result['latest_ticket_id'], 'unprinted call-center orders do not become queued print deliveries');
    same(10, count($result['jobs']), 'arrival visibility preserves print queue cap');
    same(2, $result['attention'], 'arrival visibility preserves printer review count');
    same(['success', 'branch', 'jobs', 'attention', 'latest_ticket_id', 'incoming_ticket_ids'], array_keys($result), 'listing exposes only receiver contract fields');
    same(true, array_reduce($result['incoming_ticket_ids'], fn ($all, $id) => $all && is_int($id), true), 'arrival payload contains integer IDs only');

    for ($id = 3000; $id <= 3104; $id++) ticket($id, 'f:1', 'phone', 'new', 20);
    $result = readListing($printing, $branchActor);
    same(array_merge(range(3104, 3000), [2001, 2000]), $result['incoming_ticket_ids'], 'complete authoritative arrival IDs are not truncated at one hundred');
    same(107, count($result['incoming_ticket_ids']), 'all outstanding call-center arrivals returned');
    same(61, $result['latest_ticket_id'], 'large arrival set preserves queued print cursor');
    same(2, $result['attention'], 'large arrival set preserves printer attention');
    DB::table('pos_service_tickets')->where('id', 2000)->update(['status' => 'completed']);
    DB::table('pos_service_tickets')->where('id', 2001)->update(['payment_status' => 'paid']);
    $result = readListing($printing, $branchActor);
    same(range(3104, 3000), $result['incoming_ticket_ids'], 'handled arrivals disappear from authoritative pending set');
    same(8, $beforeReads, 'extended receiver scenarios all verified read-only');

    $before = snapshot();
    blocked(fn () => $printing->listing(['branch' => 'f:2'], $branchActor), 404, 'branch actor cannot read another branch');
    $admin = (object) ['id' => 1, 'allowed_branches' => ['f:1', 'f:2'], 'receiver_branch' => null];
    blocked(fn () => $printing->listing(['branch' => 'f:1'], $admin), 403, 'administrative visibility cannot enable automatic branch receiving');
    $wrongReceiver = (object) ['id' => 11, 'allowed_branches' => ['f:1', 'f:2'], 'receiver_branch' => 'f:2'];
    blocked(fn () => $printing->listing(['branch' => 'f:1'], $wrongReceiver), 403, 'receiver must match selected branch exactly');
    blocked(fn () => $printing->listing(['branch' => 'f:0'], $branchActor), 422, 'invalid branch rejected by actual validator');
    same($before, snapshot(), 'rejected listings mutate no records');
    same(['f:1', 10, false], $access->calls[0], 'listing requests persisted branch authorization without a write lock');
    echo 'PASS branch print alert runtime: '.$checks." checks\n";
}
