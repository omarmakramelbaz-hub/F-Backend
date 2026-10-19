<?php
declare(strict_types=1);
namespace {
    require $argv[1];
    $GLOBALS['patch_root'] = $argv[2];
    spl_autoload_register(function ($class) {
        if (!str_starts_with($class, 'App\\')) return;
        $relative = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        $path = $GLOBALS['patch_root'].'/'.$relative;
        if (!is_file($path)) $path = __DIR__.'/../'.$relative;
        if (is_file($path)) require $path;
    });
    function app($key = null) { $c = \Illuminate\Container\Container::getInstance(); return $key ? $c->make($key) : $c; }
    function now($zone = null) { return \Carbon\Carbon::now($zone); }
    function abort_unless($value, $code, $message = '') { if (!$value) throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message); }
    function abort_if($value, $code, $message = '') { abort_unless(!$value, $code, $message); }
    function expectLaunchFeature($value, $message) { if (!$value) throw new \RuntimeException($message); }
    function denyLaunchFeature(callable $work, int $status) {
        try { $work(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { expectLaunchFeature($error->getStatusCode() === $status, 'Wrong denial code'); return; }
        throw new \RuntimeException('Expected authorization denial');
    }
}
namespace App\Services\Dashboard {
    class TakeawayAccess {
        public function actor($actor) { $fresh = \Illuminate\Support\Facades\DB::table('users')->where('id', $actor->id)->first(); \abort_unless($fresh, 403); return $fresh; }
        public function permissions($actor) { return ['can_checkout' => true]; }
        public function branches($actor) { $actor = $this->actor($actor); $keys = $actor->id <= 2 ? ['f:94', 'f:301'] : ($actor->id === 5 ? ['gs:94'] : ['f:94']); return array_map(fn($key) => ['value' => $key, 'kind' => explode(':',$key)[0], 'id' => (int) explode(':',$key)[1], 'name' => $key], $keys); }
        public function branch($key, $actor, $lock = false) { foreach ($this->branches($actor) as $branch) if ($branch['value'] === $key) return $branch; \abort_unless(false, 403); }
        public function has($table, $column) { return \Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, $column); }
    }
    class PosServiceTable { public function requireReady(): void {} }
    class PosServiceTicket { public static function fingerprint(array $input): string { return hash('sha256', json_encode($input)); } }
}
namespace {
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\{DB, Schema, Facade};
    use App\Services\Dashboard\{BranchPayroll, BranchCustomers, CustomerDirectory, PosServicePhone};
    $container = new Container; Container::setInstance($container);
    $db = new Manager($container); $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $db->setAsGlobal(); $db->bootEloquent();
    $container->instance('db', $db->getDatabaseManager()); $container->instance('db.schema', $db->getConnection()->getSchemaBuilder());
    $translator = new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en');
    $container->instance('validator', new \Illuminate\Validation\Factory($translator, $container)); Facade::setFacadeApplication($container);
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-19 11:00:00', 'UTC'));
    Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('account_type'); $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->string('mobile')->nullable(); $t->string('name')->nullable(); });
    Schema::create('pos_service_tickets', function (Blueprint $t) { $t->id(); $t->string('branch'); $t->string('channel'); $t->string('phone_key'); $t->string('customer_name'); $t->string('customer_phone'); $t->string('address'); $t->string('area'); $t->text('delivery_notes')->nullable(); });
    foreach (['2026_10_04_080000_create_branch_operations.php' => 'CreateBranchOperations', '2026_10_06_140000_add_employee_wallet_phone.php' => 'AddEmployeeWalletPhone', '2026_10_08_190000_add_employee_attendance_rules.php' => 'AddEmployeeAttendanceRules', '2026_10_17_000001_create_crm_customer_directory.php' => 'CreateCrmCustomerDirectory'] as $file => $class) { require __DIR__.'/../database/migrations/'.$file; (new $class)->up(); }
    foreach ([[1, 'admin', null], [2, 'admin', null], [3, 'vendor', 94], [4, 'resturant_owner', 94], [5, 'vendor', null]] as [$id, $type, $branch]) DB::table('users')->insert(['id' => $id, 'account_type' => $type, 'owner_resturant_id' => $branch]);
    $actors = DB::table('users')->get()->keyBy('id');
    DB::table('branch_employees')->insert(['id' => 1, 'branch' => 'f:94', 'name' => 'Test employee', 'job_title' => 'cashier', 'hired_on' => '2026-09-01', 'active' => true, 'revision' => 1, 'actor_id' => 1]);
    DB::table('branch_employee_salaries')->insert(['employee_id' => 1, 'branch' => 'f:94', 'effective_month' => '2026-09', 'amount_cents' => 710000, 'actor_id' => 1]);
    $payroll = app(BranchPayroll::class);
    $entry = ['branch' => 'f:94', 'employee_id' => 1, 'day' => '2026-10-19', 'kind' => 'bonus', 'amount' => '50.00', 'reason' => 'Performance', 'idempotency_key' => '00000000-0000-4000-8000-000000000001'];
    foreach ([2, 3, 4] as $id) denyLaunchFeature(fn() => $payroll->entry($entry, $actors[$id]), 403);
    expectLaunchFeature(DB::table('branch_employee_entries')->count() === 0 && DB::table('branch_operation_commands')->count() === 0, 'Denied bonus mutated state');
    $first = $payroll->entry($entry, $actors[1]); $again = $payroll->entry($entry, $actors[1]);
    expectLaunchFeature($first['entry']['amount_cents'] === 5000 && $again['replayed'], 'Owner bonus or idempotency broken');
    foreach ([2, 3, 4] as $id) denyLaunchFeature(fn() => $payroll->entry($entry, $actors[$id]), 403);
    DB::table('users')->where('id', 1)->update(['account_type' => 'vendor', 'owner_resturant_id' => 94]);
    denyLaunchFeature(fn() => $payroll->entry($entry, $actors[1]), 403);
    DB::table('users')->where('id', 1)->update(['account_type' => 'admin', 'owner_resturant_id' => null]);
    foreach (['advance', 'deduction'] as $i => $kind) $payroll->entry(array_replace($entry, ['kind' => $kind, 'idempotency_key' => sprintf('00000000-0000-4000-8000-%012d', $i + 10)]), $actors[3]);
    expectLaunchFeature(DB::table('branch_employee_entries')->count() === 3, 'Other branch entry kinds changed');
    expectLaunchFeature($payroll->listing(['branch' => 'f:94'], $actors[1])['can_award_bonuses'] === true, 'Owner listing permission missing');
    expectLaunchFeature($payroll->listing(['branch' => 'f:94'], $actors[3])['can_award_bonuses'] === false, 'Branch listing grants bonus permission');

    $directory = app(CustomerDirectory::class); $customers = app(BranchCustomers::class);
    $own = $directory->capture(['phone' => '01000000001', 'name' => 'Own'], 'delivery', 'f:94');
    $foreign = $directory->capture(['phone' => '01000000002', 'name' => 'Foreign', 'address' => 'Other branch address'], 'app', 'f:301');
    $import = $directory->capture(['phone' => '01000000003', 'name' => 'Imported', 'address' => 'Imported address', 'latitude' => 30.1, 'longitude' => 31.2], 'delivery', 'legacy:source');
    $unassigned = $directory->capture(['phone' => '01000000004', 'name' => 'No purchase'], '', '');
    $before = json_encode(DB::table('crm_customers')->orderBy('id')->get()->all());
    foreach ([1, 2, 3, 4] as $id) {
        $list = $customers->listing(['branch' => 'f:94'], $actors[$id]);
        expectLaunchFeature($list['pagination']['total'] === 4, 'Directory is still branch-limited');
        expectLaunchFeature($customers->listing(['branch' => 'f:94', 'search' => '٠١٠٠٠٠٠٠٠٠٣'], $actors[$id])['pagination']['total'] === 1, 'Imported customer Arabic-phone search failed');
        expectLaunchFeature($customers->listing(['branch' => 'f:94', 'source' => 'app'], $actors[$id])['pagination']['total'] === 1, 'Global channel filtering incorrect');
    }
    denyLaunchFeature(fn() => $customers->listing(['branch' => 'f:301'], $actors[3]), 403);
    expectLaunchFeature($customers->listing(['branch' => 'gs:94'], $actors[5])['pagination']['total'] === 0, 'Unrelated Go store received global Fasakhansta directory');
    $foreignItem = array_values(array_filter($customers->listing(['branch' => 'f:94'], $actors[3])['items'], fn($row) => $row['id'] === $foreign->id))[0];
    expectLaunchFeature($foreignItem['can_edit_profile'] === false && $foreignItem['channels'] === ['app'], 'Read sharing broadened mutation scope or hid source kind');
    denyLaunchFeature(fn() => $customers->directorySave(['branch' => 'f:94', 'directory_id' => $foreign->id, 'phone' => $foreign->phone, 'channel' => 'delivery', 'name' => 'Forbidden change', 'idempotency_key' => '00000000-0000-4000-8000-000000000020'], $actors[3]), 404);
    foreach ([1, 2, 3, 4] as $id) {
        $phone = app(PosServicePhone::class);
        $exact = $phone->customers(['branch' => 'f:94', 'phone' => '٠١٠٠٠٠٠٠٠٠٣'], $actors[$id]);
        expectLaunchFeature(count($exact['items']) === 1 && $exact['items'][0]['address'] === 'Imported address' && $exact['items'][0]['customer_id'] === null, 'Shared delivery lookup failed or leaked a foreign local ID');
        expectLaunchFeature(count($phone->customers(['branch' => 'f:94', 'phone' => '+2010000000', 'prefix' => true], $actors[$id])['items']) === 4, 'Shared normalized prefix suggestions incomplete');
    }
    expectLaunchFeature(count(app(PosServicePhone::class)->customers(['branch' => 'gs:94', 'phone' => '01000000003'], $actors[5])['items']) === 0, 'Unrelated Go lookup received a global profile');
    expectLaunchFeature($before === json_encode(DB::table('crm_customers')->orderBy('id')->get()->all()), 'Reads changed profiles or consents');
    expectLaunchFeature(DB::table('crm_customer_sources')->where('customer_id', $import->id)->where('branch', 'f:94')->count() === 0, 'Lookup invented an order/source association');
    echo "PASS: owner-only bonus authorization/replay/fresh actor checks; full registry and exact/prefix delivery search for owner/admin/branch; existing write scope and consent preserved\n";
}
