<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DesktopDashboardDevices;
use App\Services\Dashboard\DesktopDashboardCoreAuthority;
use App\Services\Dashboard\DesktopDashboardJournal;
use App\Services\Dashboard\DesktopDashboardReconciliation;
use App\Services\Dashboard\DesktopDashboardWorkspaceAuthority as Authority;
use App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext as Context;
use App\Services\Dashboard\DesktopDashboardWorkspaceScope as Scope;
use App\Services\Dashboard\TakeawayAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** The existing device and journal services retain their actor checks with a typed workspace facade. */
class DesktopDashboardWorkspaceCoreBridgeTest extends TestCase
{
    private const DEVICE = '11111111-1111-4111-8111-111111111111';
    private const LEGACY = '22222222-2222-4222-8222-222222222222';
    private string $connection = 'sqlite';
    private array $enrolled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = env('TAKEAWAY_TEST_CONNECTION', 'sqlite');
        if (!in_array($this->connection, ['sqlite', 'mysql'], true)) throw new \RuntimeException('Unsupported test database');
        if ($this->connection === 'mysql' && config('database.connections.mysql.database') !== 'takeaway_test')
            throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default' => $this->connection, 'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('h', 32)),
            'desktop_dashboard.enabled' => true, 'desktop_dashboard.workspace_enabled' => true,
            'desktop_dashboard.local' => false, 'desktop_dashboard.device_id' => '']);
        if ($this->connection === 'sqlite') config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge($this->connection); Schema::clearResolvedInstance('db.schema');
        if ($this->connection === 'mysql') $this->dropFixtures();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('mobile')->nullable();
            $t->string('password'); $t->string('account_type'); $t->string('app_scope')->nullable();
            $t->string('status')->default('accepted'); $t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable(); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name'); $t->timestamps();
        });
        Schema::create('go_stores', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id')->unique(); $t->string('name'); $t->timestamps();
        });
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables)->up();
        require_once database_path('migrations/2026_10_08_130000_create_desktop_dashboard_journal.php');
        (new \CreateDesktopDashboardJournal)->up();
        require_once database_path('migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php');
        (new \CreateDesktopDashboardWorkspaces)->up();
        require_once database_path('migrations/2026_10_27_030010_create_workspace_command_authorities.php');
        (new \CreateWorkspaceCommandAuthorities)->up();
        foreach ([10, 11, 30] as $id) DB::table('users')->insert([
            'id' => $id, 'name' => 'Actor '.$id, 'email' => 'actor'.$id.'@example.test',
            'mobile' => (string)(1000000000 + $id),
            'password' => password_hash('fixture-password-'.$id, PASSWORD_BCRYPT, ['cost' => 4]),
            'account_type' => $id === 30 ? 'admin' : 'vendor', 'app_scope' => 'fasakhansta', 'status' => 'accepted',
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        DB::table('resturants')->insert([
            ['id' => 100, 'user_id' => 10, 'name' => 'Custodian branch'],
            ['id' => 101, 'user_id' => 11, 'name' => 'Other account branch'],
        ]);
        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'order-list', 'guard_name' => 'admin'],
            ['id' => 2, 'name' => 'order-edit', 'guard_name' => 'admin'],
        ]);
        DB::table('model_has_permissions')->insert(['model_type' => User::class, 'model_id' => 30, 'permission_id' => 1]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('desktop_dashboard_devices')->insert([
            'id' => self::LEGACY, 'actor_id' => 10, 'name' => 'Existing account device',
            'enrollment_hash' => str_repeat('e', 64), 'token_hash' => hash('sha256', str_repeat('c', 64)),
            'enabled' => true, 'branches' => '["f:100"]', 'enrolled_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $this->enrolled = app(Authority::class)->enroll([
            'device_id' => self::DEVICE, 'name' => 'Shared PC', 'nonce' => str_repeat('a', 64),
        ], User::withoutGlobalScopes()->findOrFail(10));
    }

    protected function tearDown(): void
    {
        if ($this->connection === 'mysql') { $this->dropFixtures(); DB::disconnect('mysql'); }
        parent::tearDown();
    }

    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['desktop_dashboard_ws_command_auth', 'desktop_dashboard_workspace_members',
            'desktop_dashboard_workspace_manifests', 'desktop_dashboard_workspaces',
            'desktop_dashboard_entities', 'desktop_dashboard_commands', 'desktop_dashboard_devices',
            'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions',
            'go_stores', 'resturants', 'users'] as $table) Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }

    private function scope(int $actor = 11): Scope
    {
        $authority = app(Authority::class);
        $member = array_values(array_filter($this->enrolled['manifest']['members'],
            fn ($row) => $row['actor_id'] === $actor))[0];
        return $authority->scope($authority->authenticate($this->enrolled['token']), $actor,
            $member['grant'], $this->enrolled['manifest']['revision']);
    }

    private function denied(callable $work, array $statuses): void
    {
        $error = null; try { $work(); } catch (HttpException $caught) { $error = $caught; }
        $this->assertInstanceOf(HttpException::class, $error);
        $this->assertContains($error->getStatusCode(), $statuses);
    }

    public function test_original_devices_service_uses_effective_actor_and_branch_grants_without_rebinding_custody(): void
    {
        $scope = $this->scope(); $devices = app(DesktopDashboardDevices::class);
        $before = DB::table('desktop_dashboard_devices')->get()->toJson();
        $this->assertSame($this->enrolled['root_fingerprint'], $devices->enrollmentFingerprint($scope));
        $this->assertSame(11, (int)$devices->actor($scope)->id);
        $this->assertSame(['f:101'], $devices->baseBranches($scope));
        $locked = DB::transaction(fn () => $devices->lockAuthenticated($scope));
        $this->assertSame($scope, $locked);
        $this->assertSame(self::DEVICE, $locked->id);
        $this->assertSame(10, (int)$locked->root()->custodian_actor_id);
        $this->assertSame(11, $locked->actor_id);
        $devices->branch($locked, 'f:101', $devices->actor($locked));
        $this->denied(fn () => $devices->branch($locked, 'f:100', $devices->actor($locked)), [403]);
        $this->denied(fn () => $devices->lockAuthenticated($scope), [503]);
        $this->assertSame($before, DB::table('desktop_dashboard_devices')->get()->toJson());
    }

    public function test_workspace_bearer_cannot_enter_the_original_single_actor_device_endpoint(): void
    {
        $this->denied(fn () => app(DesktopDashboardDevices::class)->device($this->enrolled['token']), [401]);
    }

    public function test_existing_v1_device_authentication_and_persisted_base_grants_are_unchanged(): void
    {
        $devices = app(DesktopDashboardDevices::class);
        $legacy = $devices->device(str_repeat('c', 64));
        $this->assertSame(self::LEGACY, $legacy->id);
        $this->assertSame(10, (int)$devices->actor($legacy)->id);
        $this->assertSame(['f:100'], $devices->baseBranches((object)['id' => self::LEGACY]));
        $this->assertSame($devices->enrollmentFingerprint($legacy),
            DB::transaction(fn () => $devices->enrollmentFingerprint($devices->lockAuthenticated($legacy))));
    }

    public function test_devices_revalidate_revocation_before_using_an_issued_actor_facade(): void
    {
        $scope = $this->scope(); $devices = app(DesktopDashboardDevices::class);
        DB::table('users')->where('id', 11)->update(['status' => 'disabled']);
        $this->denied(fn () => $devices->actor($scope), [403, 409]);
        $this->denied(fn () => $devices->baseBranches($scope), [403, 409]);
        $this->denied(fn () => DB::transaction(fn () => $devices->lockAuthenticated($scope)), [403, 409]);
    }

    public function test_reconciliation_requires_the_exact_verified_context_scope_before_envelope_validation(): void
    {
        $scope = $this->scope(); $other = $this->scope(10);
        $reconciliation = app(DesktopDashboardReconciliation::class);
        $this->denied(fn () => $reconciliation->ingest($scope, []), [403]);
        Context::run($other, fn () => $this->denied(fn () => $reconciliation->ingest($scope, []), [403]));
        $this->assertNull(Context::current());
        $error = null;
        try { Context::run($scope, fn () => $reconciliation->ingest($scope, [])); }
        catch (ValidationException $caught) { $error = $caught; }
        $this->assertInstanceOf(ValidationException::class, $error);
        $this->assertNull(Context::current());
    }

    public function test_workspace_hooks_do_not_change_the_original_v1_command_or_pending_envelope(): void
    {
        $journal = app(DesktopDashboardJournal::class);
        $command = '33333333-3333-4333-8333-333333333333';
        $payload = ['values' => ['idempotency_key' => $command]];
        $result = $journal->execute(self::LEGACY, $command, 10, 'bridge.fixture', $payload, [],
            fn () => ['saved' => true]);
        $this->assertSame(['saved' => true], $result);
        $this->assertSame($result, $journal->execute(self::LEGACY, $command, 10, 'bridge.fixture',
            $payload, [], function () { throw new \RuntimeException('A duplicate must reuse its existing result.'); }));
        $pending = $journal->pending(self::LEGACY);
        $this->assertCount(1, $pending);
        $this->assertSame(10, $pending[0]['actor_id']);
        $this->assertSame($payload, $pending[0]['payload']);
        $this->assertSame($result, $pending[0]['local_result']);
        $this->assertArrayNotHasKey('workspace_authority', $pending[0]);
        $this->assertSame(1, DB::table('desktop_dashboard_commands')->count());
    }

    public function test_shared_journal_preserves_each_actors_proof_and_global_command_order_across_account_switches(): void
    {
        $journal = app(DesktopDashboardJournal::class); $actorB = $this->scope(11); $actorA = $this->scope(10);
        $first = '33333333-3333-4333-8333-333333333333';
        $second = '44444444-4444-4444-8444-444444444444';
        $firstPayload = ['values' => ['idempotency_key' => $first]];
        Context::run($actorB, fn () => $journal->execute(self::DEVICE, $first, 11, 'bridge.fixture',
            $firstPayload, [], fn () => ['saved_by' => 11]));
        Context::run($actorA, fn () => $journal->execute(self::DEVICE, $second, 10, 'bridge.fixture',
            ['values' => ['idempotency_key' => $second]], [$first], fn () => ['saved_by' => 10]));
        $pending = Context::run($actorA, fn () => $journal->pending(self::DEVICE));
        $this->assertCount(1, $pending);
        $this->assertSame($first, $pending[0]['command_id']);
        $this->assertSame(11, $pending[0]['workspace_authority']['actor_id']);
        $this->assertSame($actorB->rootFingerprint(), $pending[0]['workspace_authority']['root_fingerprint']);
        $this->assertSame($journal->fingerprint([11, 'bridge.fixture', $firstPayload, []]),
            $pending[0]['workspace_authority']['command_sha256']);
        $this->assertSame(['saved_by' => 11], Context::run($actorB,
            fn () => $journal->execute(self::DEVICE, $first, 11, 'bridge.fixture', $firstPayload, [],
                function () { throw new \RuntimeException('Duplicate business work must not execute.'); })));
        $journal->acknowledge(self::DEVICE, $first, ['device_id' => self::DEVICE, 'command_id' => $first,
            'committed' => true, 'result' => ['saved_by' => 11]]);
        $pending = Context::run($actorB, fn () => $journal->pending(self::DEVICE));
        $this->assertSame($second, $pending[0]['command_id']);
        $this->assertSame(10, $pending[0]['workspace_authority']['actor_id']);
        $this->assertSame(2, DB::table('desktop_dashboard_ws_command_auth')->count());
    }

    public function test_missing_command_proof_rolls_back_business_work_and_cannot_return_an_old_success(): void
    {
        $journal = app(DesktopDashboardJournal::class); $scope = $this->scope();
        $command = '33333333-3333-4333-8333-333333333333';
        $payload = ['values' => ['idempotency_key' => $command]];
        Schema::drop('desktop_dashboard_ws_command_auth');
        $this->denied(fn () => Context::run($scope, fn () => $journal->execute(self::DEVICE, $command, 11,
            'bridge.fixture', $payload, [], function () {
                DB::table('users')->where('id', 11)->update(['name' => 'Must roll back']); return ['saved' => true];
            })), [503]);
        $this->assertSame('Actor 11', DB::table('users')->where('id', 11)->value('name'));
        $this->assertSame(0, DB::table('desktop_dashboard_commands')->count());
        (new \CreateWorkspaceCommandAuthorities)->up();
        Context::run($scope, fn () => $journal->execute(self::DEVICE, $command, 11, 'bridge.fixture',
            $payload, [], fn () => ['saved' => true]));
        DB::table('desktop_dashboard_ws_command_auth')->delete();
        $this->denied(fn () => Context::run($scope, fn () => $journal->execute(self::DEVICE, $command, 11,
            'bridge.fixture', $payload, [], function () { throw new \RuntimeException('Never repeat the original work.'); })), [503]);
        $this->assertSame(1, DB::table('desktop_dashboard_commands')->count());
    }

    public function test_core_and_takeaway_authorization_keep_replay_permission_and_branch_intersections(): void
    {
        $authority = app(Authority::class); $root = $authority->authenticate($this->enrolled['token']);
        $old = array_values(array_filter($this->enrolled['manifest']['members'], fn ($row) => $row['actor_id'] === 30))[0];
        DB::table('model_has_permissions')->insert(['model_type' => User::class, 'model_id' => 30, 'permission_id' => 2]);
        DB::table('resturants')->insert(['id' => 102, 'user_id' => 10, 'name' => 'Added after acceptance']);
        $freshManifest = $authority->manifest($root);
        $replay = $authority->scopeForReplay($root, 30, $old['grant'], $this->enrolled['manifest']['revision']);
        $access = app(TakeawayAccess::class); $core = app(DesktopDashboardCoreAuthority::class);
        Context::run($replay, function () use ($replay, $access, $core) {
            $this->assertFalse($access->permissions($replay->actor())['can_manage']);
            $this->assertSame(['f:100', 'f:101'], array_column($access->branches($replay->actor()), 'value'));
            $this->denied(fn () => $access->branch('f:102', $replay->actor()), [403]);
            $this->denied(fn () => $access->actor((object)['id' => 10]), [403]);
            $this->denied(fn () => DB::transaction(fn () => $core->authorize('takeaway.checkout',
                ['branch' => 'f:100', 'discount' => '1'], $replay)), [403]);
            DB::transaction(function () use ($replay, $access, $core) {
                $actor = $core->authorize('takeaway.checkout', ['branch' => 'f:100'], $replay);
                $this->assertSame(30, (int)$actor->id);
                $core->withControllerFacts($actor, function () use ($actor, $access) {
                    $this->assertFalse($access->permissions($actor)['can_manage']);
                });
            });
        });
        $member = array_values(array_filter($freshManifest['members'], fn ($row) => $row['actor_id'] === 30))[0];
        $fresh = $authority->scope($root, 30, $member['grant'], $freshManifest['revision']);
        Context::run($fresh, function () use ($fresh, $access) {
            $this->assertTrue($access->permissions($fresh->actor())['can_manage']);
            $this->assertSame(['f:100', 'f:101', 'f:102'], array_column($access->branches($fresh->actor()), 'value'));
        });
    }

    public function test_tampered_command_authority_cannot_be_exported_or_used_as_a_cached_success(): void
    {
        $journal=app(DesktopDashboardJournal::class);$scope=$this->scope();
        $command='33333333-3333-4333-8333-333333333333';$payload=['values'=>['idempotency_key'=>$command]];
        Context::run($scope,fn()=>$journal->execute(self::DEVICE,$command,11,'bridge.fixture',$payload,[],fn()=>['saved'=>true]));
        $proof=DB::table('desktop_dashboard_ws_command_auth')->first();
        $changed=json_decode($proof->proof_json,true,16,JSON_THROW_ON_ERROR);$changed['actor_id']=10;
        $bytes=Authority::canonical($changed);
        DB::table('desktop_dashboard_ws_command_auth')->update(['actor_id'=>10,
            'proof_json'=>$bytes,'proof_sha256'=>hash('sha256',$bytes)]);
        $this->denied(fn()=>$journal->pending(self::DEVICE),[503]);
        $this->denied(fn()=>Context::run($scope,fn()=>$journal->execute(self::DEVICE,$command,11,'bridge.fixture',
            $payload,[],function(){throw new \RuntimeException('Tampering must never repeat business work.');})),[503]);
        $this->assertSame(1,DB::table('desktop_dashboard_commands')->count());
    }

    public function test_archived_command_proof_survives_retirement_without_reauthorizing_the_actor(): void
    {
        require_once database_path('migrations/2026_10_08_210000_create_desktop_dashboard_archived_commands.php');
        Schema::dropIfExists('desktop_dashboard_archived_commands');(new \CreateDesktopDashboardArchivedCommands)->up();
        try{
            $journal=app(DesktopDashboardJournal::class);$scope=$this->scope();
            $command='33333333-3333-4333-8333-333333333333';
            Context::run($scope,fn()=>$journal->execute(self::DEVICE,$command,11,'bridge.fixture',
                ['values'=>['idempotency_key'=>$command]],[],fn()=>['saved'=>true]));
            $row=DB::table('desktop_dashboard_commands')->first();
            DB::table('desktop_dashboard_archived_commands')->insert(['device_id'=>self::DEVICE,
                'command_id'=>$command,'actor_id'=>11,'route_name'=>$row->route_name,'request_hash'=>$row->request_hash,
                'source_database'=>'fasakhansta_dashboard']);
            DB::table('desktop_dashboard_commands')->delete();
            DB::table('users')->where('id',11)->update(['status'=>'disabled']);
            app(Authority::class)->manifest(app(Authority::class)->authenticate($this->enrolled['token']));
            app(\App\Services\Dashboard\DesktopDashboardWorkspaceCommandAuthorities::class)->assertReplayHistory(self::DEVICE,
                ['device_id'=>self::DEVICE,'workspace'=>['root_fingerprint'=>$scope->rootFingerprint()]]);
            $this->denied(fn()=>$this->scope(),[403,409]);
            DB::table('desktop_dashboard_archived_commands')->update(['request_hash'=>str_repeat('a',64)]);
            $this->denied(fn()=>app(\App\Services\Dashboard\DesktopDashboardWorkspaceCommandAuthorities::class)
                ->assertReplayHistory(self::DEVICE,['device_id'=>self::DEVICE]),[503]);
        }finally{Schema::dropIfExists('desktop_dashboard_archived_commands');}
    }

    public function test_context_restores_after_errors_and_rejects_nested_actor_substitution(): void
    {
        $actorB=$this->scope();$actorA=$this->scope(10);
        try{
            Context::run($actorB,function()use($actorA,$actorB){
                $this->assertSame($actorB,Context::current());
                $this->denied(fn()=>Context::run($actorA,fn()=>null),[403]);
                $this->assertSame($actorB,Context::current());throw new \RuntimeException('fixture stop');
            });
            $this->fail('The fixture must throw.');
        }catch(\RuntimeException $error){$this->assertSame('fixture stop',$error->getMessage());}
        $this->assertNull(Context::current());$this->assertNull(Context::incomingProof());
    }

    public function test_workspace_web_challenge_is_available_to_branch_accounts_and_disabled_routes_fail_closed(): void
    {
        config(['app.url'=>'https://workspace.example.test']);
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(11),'admin');
        $this->get('/admin/desktop-dashboard/workspace/challenge')->assertOk()
            ->assertJson(['protocol'=>2,'actor_id'=>11,'role'=>'vendor','server_origin'=>'https://workspace.example.test'])
            ->assertJsonStructure(['csrf_token'])->assertHeader('Cache-Control','no-store, private');
        config(['desktop_dashboard.workspace_enabled'=>false]);
        $this->get('/admin/desktop-dashboard/workspace/challenge')->assertStatus(503);
        $this->getJson('/api/desktop-dashboard/workspace/manifest')->assertStatus(503);
        $this->assertSame(1,DB::table('desktop_dashboard_workspaces')->count());
    }

    public function test_program_preparation_page_accepts_a_valid_nonfinancial_account_without_owner_selection(): void
    {
        DB::table('model_has_permissions')->where('model_type',User::class)->where('model_id',30)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $updates=\Mockery::mock(\App\Services\Dashboard\DesktopDashboardUpdates::class);
        $updates->shouldReceive('status')->andReturn(['state'=>'unavailable']);
        $updates->shouldReceive('publishedDownloads')->andReturn(['current'=>null,'previous'=>null]);
        $this->app->instance(\App\Services\Dashboard\DesktopDashboardUpdates::class,$updates);
        app('view')->getFinder()->prependLocation(base_path('tests/fixtures/workspace-read-views'));
        $this->actingAs(User::withoutGlobalScopes()->findOrFail(30),'admin');
        $this->get('/admin/desktop-dashboard')->assertOk()->assertSee('SHARED_DEVICE_PREPARATION_AVAILABLE');
        config(['desktop_dashboard.workspace_enabled'=>false]);
        $this->get('/admin/desktop-dashboard')->assertStatus(403);
    }

    public function test_shared_control_dispatch_preserves_legacy_shape_and_rejects_an_unverified_group_context(): void
    {
        $oldToken=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');$oldNative=getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');
        $oldHeader=$_SERVER['HTTP_X_FASAKHANSTA_CONTROL']??null;
        putenv('DESKTOP_DASHBOARD_CONTROL_TOKEN='.str_repeat('a',64));putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');
        $_SERVER['HTTP_X_FASAKHANSTA_CONTROL']=str_repeat('a',64);
        config(['desktop_dashboard.local'=>true,'desktop_dashboard.device_id'=>self::LEGACY]);
        try{
            $control=app(\App\Services\Dashboard\DesktopDashboardControl::class);
            $this->assertSame(['commands'=>[],'counts'=>['pending'=>0,'conflicts'=>0,'acknowledged'=>0],'print_protocol'=>0],
                $control->dispatch(self::LEGACY,['action'=>'pending']));
            $this->denied(fn()=>$control->dispatch(self::LEGACY,['action'=>'unknown']),[422]);
            unset($_SERVER['HTTP_X_FASAKHANSTA_CONTROL']);
            $this->denied(fn()=>$control->dispatch(self::LEGACY,['action'=>'pending']),[403]);
            $_SERVER['HTTP_X_FASAKHANSTA_CONTROL']=str_repeat('a',64);
            putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');config(['desktop_dashboard.device_id'=>self::DEVICE]);
            $this->denied(fn()=>$control->dispatch(self::DEVICE,['action'=>'pending']),[403]);
        }finally{
            putenv($oldToken===false?'DESKTOP_DASHBOARD_CONTROL_TOKEN':'DESKTOP_DASHBOARD_CONTROL_TOKEN='.$oldToken);
            putenv($oldNative===false?'DESKTOP_DASHBOARD_WORKSPACE_LOCAL':'DESKTOP_DASHBOARD_WORKSPACE_LOCAL='.$oldNative);
            if($oldHeader===null)unset($_SERVER['HTTP_X_FASAKHANSTA_CONTROL']);else $_SERVER['HTTP_X_FASAKHANSTA_CONTROL']=$oldHeader;
        }
    }
}
