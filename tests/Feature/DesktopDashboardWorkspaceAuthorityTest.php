<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DesktopDashboardWorkspaceAuthority as Authority;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real persisted users, role pivots and isolated SQL; no HTTP or production data. */
class DesktopDashboardWorkspaceAuthorityTest extends TestCase
{
    public function test_protocol_canonical_json_keeps_unicode_line_terminators_like_native_json(): void
    {
        $text="line\u{2028}paragraph\u{2029}end";
        $this->assertSame('{"a":"'.$text.'"}',Authority::canonical(['a'=>$text]));
        $this->assertStringNotContainsString('\\u2028',Authority::canonical(['a'=>$text]));
        $this->assertStringNotContainsString('\\u2029',Authority::canonical(['a'=>$text]));
    }
    private string $connection = 'sqlite';
    private const DEVICE = '11111111-1111-4111-8111-111111111111';
    private const LEGACY = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const WORKSPACE_TABLES = ['desktop_dashboard_workspace_members',
        'desktop_dashboard_workspace_manifests', 'desktop_dashboard_workspaces'];
    private array $passwords = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = env('TAKEAWAY_TEST_CONNECTION', 'sqlite');
        if (!in_array($this->connection, ['sqlite', 'mysql'], true)) throw new \RuntimeException('Unsupported test database');
        if ($this->connection === 'mysql' && config('database.connections.mysql.database') !== 'takeaway_test')
            throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default' => $this->connection, 'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('h', 32)),
            'app.timezone' => 'Africa/Cairo', 'desktop_dashboard.enabled' => true,
            'desktop_dashboard.workspace_enabled' => true, 'desktop_dashboard.local' => false]);
        if ($this->connection === 'sqlite') config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge($this->connection); Schema::clearResolvedInstance('db.schema');
        if ($this->connection === 'mysql') $this->dropFixtures();
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', 'UTC'));
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('mobile')->nullable();
            $t->string('password'); $t->string('remember_token')->nullable(); $t->string('account_type');
            $t->string('app_scope')->nullable(); $t->string('status')->default('accepted');
            $t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable(); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name'); $t->string('status')->default('opened'); $t->timestamps();
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
        foreach ([[1, 'admin', 'accepted'], [10, 'vendor', 'accepted'], [11, 'vendor', 'accepted'],
            [20, 'user', 'accepted'], [30, 'admin', 'accepted'], [40, 'vendor', 'disabled'],
            [41, 'vendor', 'declined'], [42, 'vendor', 'accepted']] as [$id, $type, $status]) {
            $hash = $id === 42 ? '!desktop-disabled-test' : password_hash('fixture-password-'.$id, PASSWORD_BCRYPT, ['cost' => 4]);
            $this->passwords[$id] = $hash;
            DB::table('users')->insert(['id' => $id, 'name' => 'Actor '.$id,
                'email' => 'actor'.$id.'@example.test', 'mobile' => (string)(1000000000 + $id),
                'password' => $hash, 'remember_token' => 'private-recaller-'.$id,
                'account_type' => $type, 'app_scope' => 'fasakhansta', 'status' => $status,
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
        DB::table('resturants')->insert([
            ['id' => 100, 'user_id' => 10, 'name' => 'First branch'],
            ['id' => 101, 'user_id' => 11, 'name' => 'Second branch'],
        ]);
        foreach (['order-list', 'order-create', 'order-edit', 'contract-list'] as $i => $name)
            DB::table('permissions')->insert(['id' => $i + 1, 'name' => $name, 'guard_name' => 'admin']);
        DB::table('permissions')->insert(['id' => 20, 'name' => 'web-only-secret', 'guard_name' => 'web']);
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Super Admin', 'guard_name' => 'admin'],
            ['id' => 2, 'name' => 'Contract Reader', 'guard_name' => 'admin'],
            ['id' => 3, 'name' => 'Branch Operator', 'guard_name' => 'admin'],
            ['id' => 4, 'name' => 'Unrelated web role', 'guard_name' => 'web'],
        ]);
        DB::table('role_has_permissions')->insert([
            ['role_id' => 2, 'permission_id' => 4], ['role_id' => 3, 'permission_id' => 2],
            ['role_id' => 4, 'permission_id' => 20],
        ]);
        foreach ([[1, 1], [30, 2], [30, 4], [10, 3], [11, 3]] as [$actor, $role])
            DB::table('model_has_roles')->insert(['model_type' => User::class, 'model_id' => $actor, 'role_id' => $role]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        DB::table('desktop_dashboard_devices')->insert(['id' => self::LEGACY, 'actor_id' => 10, 'name' => 'Existing prepared device',
            'enrollment_hash' => str_repeat('e', 64), 'token_hash' => str_repeat('f', 64),
            'enabled' => true, 'branches' => '["f:100"]', 'enrolled_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_dashboard_commands')->insert(['device_id' => self::LEGACY,
            'command_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'actor_id' => 10,
            'route_name' => 'takeaway.sale', 'request_hash' => str_repeat('c', 64),
            'command_cipher' => 'EXISTING_PENDING_COMMAND_BYTES', 'local_result_cipher' => 'EXISTING_RESULT_BYTES',
            'dependencies' => '[]', 'status' => 'pending', 'attempts' => 2,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->connection === 'mysql') { $this->dropFixtures(); DB::disconnect('mysql'); }
        parent::tearDown();
    }

    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (array_merge(self::WORKSPACE_TABLES, ['desktop_dashboard_print_ownership','desktop_dashboard_ws_command_auth','desktop_dashboard_entities', 'desktop_dashboard_commands',
            'desktop_dashboard_devices', 'role_has_permissions', 'model_has_roles', 'model_has_permissions',
            'roles', 'permissions', 'go_stores', 'resturants', 'users']) as $table) Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }

    private function actor(int $id): User { return User::withoutGlobalScopes()->findOrFail($id); }
    private function authority(): Authority { return app(Authority::class); }
    private function input(array $extra = []): array
    { return $extra + ['device_id' => self::DEVICE, 'name' => 'Shared PC', 'nonce' => str_repeat('a', 64)]; }
    private function enroll(int $actor = 10): array { return $this->authority()->enroll($this->input(), $this->actor($actor)); }
    private function root(array $enrolled): object { return $this->authority()->authenticate($enrolled['token']); }
    private function member(array $manifest, int $actor): array
    {
        $rows = array_values(array_filter($manifest['members'], fn ($row) => $row['actor_id'] === $actor));
        $this->assertCount(1, $rows); return $rows[0];
    }
    private function legacy(): array
    {
        return ['devices' => DB::table('desktop_dashboard_devices')->orderBy('id')->get()->toJson(),
            'commands' => DB::table('desktop_dashboard_commands')->orderBy('sequence')->get()->toJson(),
            'entities' => DB::table('desktop_dashboard_entities')->orderBy('id')->get()->toJson()];
    }
    private function denied(callable $work, array $statuses): void
    {
        $error = null; try { $work(); } catch (HttpException $caught) { $error = $caught; }
        $this->assertInstanceOf(HttpException::class, $error);
        $this->assertContains($error->getStatusCode(), $statuses);
    }
    private function immutable(callable $work): void
    {
        $error = null; try { $work(); } catch (\Throwable $caught) { $error = $caught; }
        $this->assertInstanceOf(\Throwable::class, $error, 'A client cannot mutate or serialize an internal actor scope.');
    }
    private function assertCanonical($expected, $actual): void
    { $this->assertSame(Authority::canonical($expected), Authority::canonical($actual)); }
    private function writes(callable $work): array
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try { $work(); return array_values(array_filter(DB::getQueryLog(),
            fn ($row) => preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $row['query']))); }
        finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_disabled_workspace_feature_has_no_enrollment_or_authentication_writes(): void
    {
        $before = $this->legacy(); config(['desktop_dashboard.workspace_enabled' => false]);
        $writes = $this->writes(function () {
            $this->denied(fn () => $this->enroll(), [404, 503]);
            $this->denied(fn () => $this->authority()->authenticate(str_repeat('a', 64)), [404, 503]);
        });
        $this->assertSame([], $writes);
        foreach (self::WORKSPACE_TABLES as $table) $this->assertSame(0, DB::table($table)->count());
        $this->assertSame($before, $this->legacy());
    }

    public function test_any_active_branch_can_prepare_one_workspace_for_all_dashboard_accounts_without_widening_their_grants(): void
    {
        $before = $this->legacy(); $enrolled = $this->enroll(10); $manifest = $enrolled['manifest'];
        $this->assertSame(2, $enrolled['protocol']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $enrolled['token']);
        $this->assertSame(2, $manifest['format']);
        $this->assertSame('desktop-dashboard-workspace-members', $manifest['kind']);
        $this->assertSame($enrolled['workspace_id'], $manifest['workspace_id']);
        $this->assertSame($enrolled['root_fingerprint'], $manifest['root_fingerprint']);
        $keys = array_keys($manifest); sort($keys);
        $this->assertSame(['format', 'kind', 'members', 'revision', 'root_fingerprint', 'roster_sha256', 'signature', 'workspace_id'], $keys);
        $ids = array_column($manifest['members'], 'actor_id'); sort($ids);
        $this->assertSame([1, 10, 11, 30], $ids);
        $owner = $this->member($manifest, 1); $branch = $this->member($manifest, 10); $other = $this->member($manifest, 11);
        $admin = $this->member($manifest, 30);
        $this->assertTrue($owner['permissions']['super_admin']);
        $this->assertSame(['f:100'], $branch['branches']); $this->assertSame(['f:101'], $other['branches']);
        $this->assertSame([], $admin['branches']); $this->assertFalse($admin['permissions']['super_admin']);
        $this->assertContains('contract-list', $admin['permissions']['names']);
        $this->assertNotContains('order-list', $admin['permissions']['names']);
        $this->assertNotContains('web-only-secret', $admin['permissions']['names']);
        foreach ($manifest['members'] as $member) {
            $this->assertTrue($member['enabled']);
            $this->assertContains($member['role'], ['admin', 'vendor', 'resturant_owner']);
            $this->assertContains('actor'.$member['actor_id'].'@example.test', $member['aliases']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $member['password_binding']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $member['grant']);
            $unsignedMember = $member; unset($unsignedMember['grant']);
            $grantBody = ['workspace_id' => $manifest['workspace_id'], 'root_fingerprint' => $manifest['root_fingerprint'],
                'revision' => $manifest['revision'], 'member' => $unsignedMember];
            $this->assertSame(hash_hmac('sha256', "desktop-workspace-v2\0member\0".Authority::canonical($grantBody), str_repeat('h', 32)), $member['grant']);
        }
        $this->assertSame(hash('sha256', Authority::canonical($manifest['members'])), $manifest['roster_sha256']);
        $unsigned = $manifest; unset($unsigned['signature']);
        $this->assertSame(hash_hmac('sha256', "desktop-workspace-v2\0manifest\0".Authority::canonical($unsigned), str_repeat('h', 32)), $manifest['signature']);
        $public = json_encode($manifest, JSON_THROW_ON_ERROR);
        foreach ($this->passwords as $hash) $this->assertStringNotContainsString($hash, $public);
        $this->assertStringNotContainsString('fixture-password-', $public);
        $this->assertStringNotContainsString('private-recaller-', $public);
        $this->assertStringNotContainsString($enrolled['token'], $public);
        $root = $this->root($enrolled); $this->assertSame(10, (int)$root->custodian_actor_id);
        $this->assertSame(hash('sha256', $enrolled['token']), $root->token_hash);
        $this->assertSame($before, $this->legacy());
    }

    public function test_same_device_nonce_retries_from_another_account_reuse_the_root_and_token(): void
    {
        $first = $this->enroll(10); $before = $this->legacy();
        $second = $this->enroll(1);
        $this->assertCanonical($first, $second);
        $root = $this->root($second); $this->assertSame(10, (int)$root->custodian_actor_id);
        $this->assertSame(1, DB::table('desktop_dashboard_workspaces')->count());
        $this->denied(fn () => $this->authority()->enroll($this->input(['nonce' => str_repeat('b', 64)]), $this->actor(11)), [409]);
        $this->assertSame($before, $this->legacy());
    }

    public function test_client_cannot_supply_actor_role_branch_or_permission_authority(): void
    {
        $before = $this->legacy();
        foreach (['actor_id' => 1, 'role' => 'admin', 'branches' => ['f:101'],
            'permissions' => ['super_admin' => true], 'members' => [1], 'grant' => str_repeat('c', 64)] as $field => $value) {
            $error = null;
            try { $this->authority()->enroll($this->input([$field => $value]), $this->actor(10)); }
            catch (ValidationException $caught) { $error = $caught; }
            $this->assertInstanceOf(ValidationException::class, $error, 'Rejected unsupported enrollment field: '.$field);
        }
        $this->assertSame(0, DB::table('desktop_dashboard_workspaces')->count());
        $this->assertSame($before, $this->legacy());
    }

    public function test_internal_scope_uses_the_chosen_actor_but_preserves_the_immutable_root_custodian(): void
    {
        $enrolled = $this->enroll(10); $root = $this->root($enrolled); $manifest = $enrolled['manifest'];
        $member = $this->member($manifest, 11);
        $scope = $this->authority()->scope($root, 11, $member['grant'], $manifest['revision']);
        $this->assertSame($root->id, $scope->id); $this->assertSame(11, $scope->actor_id);
        $this->assertSame(['f:101'], json_decode($scope->branches, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(11, (int)$scope->actor()->id); $this->assertCanonical($member, $scope->member());
        $this->assertSame($manifest['revision'], $scope->manifestRevision());
        $this->assertSame(10, (int)$scope->root()->custodian_actor_id);
        $this->assertSame($enrolled['root_fingerprint'], $scope->rootFingerprint());
        $this->assertSame($this->authority()->rootFingerprint($root), $this->authority()->rootFingerprint($scope));
        $actorClone = $scope->actor(); $actorClone->id = 1;
        $rootClone = $scope->root(); $rootClone->custodian_actor_id = 1;
        $this->assertSame(11, (int)$scope->actor()->id);
        $this->assertSame(10, (int)$scope->root()->custodian_actor_id);
        $this->immutable(function () use ($scope) { $scope->actor_id = 1; });
        $this->immutable(fn () => serialize($scope));
        $this->immutable(fn () => \App\Services\Dashboard\DesktopDashboardWorkspaceScope::issue(
            $this->authority(), new \stdClass, $root, $this->actor(11), $member,
            $enrolled['root_fingerprint'], $manifest['revision']));
        $this->assertSame([], get_object_vars($scope));
        $this->authority()->assertScope($scope);
    }

    public function test_grant_actor_revision_and_root_bindings_cannot_be_substituted(): void
    {
        $enrolled = $this->enroll(); $root = $this->root($enrolled); $manifest = $enrolled['manifest'];
        $member = $this->member($manifest, 11);
        $this->denied(fn () => $this->authority()->validateMember($root, 1, $member['grant'], $manifest['revision']), [403, 409]);
        $this->denied(fn () => $this->authority()->validateMember($root, 11, str_repeat('0', 64), $manifest['revision']), [403, 409]);
        $this->denied(fn () => $this->authority()->validateMember($root, 11, $member['grant'], $manifest['revision'] + 1), [409]);
        $other = $this->authority()->enroll($this->input(['device_id' => '22222222-2222-4222-8222-222222222222']), $this->actor(10));
        $this->denied(fn () => $this->authority()->validateMember($this->root($other), 11, $member['grant'], $manifest['revision']), [403, 409]);
    }

    public function test_unchanged_roster_manifest_is_read_only_and_keeps_its_revision(): void
    {
        $enrolled = $this->enroll(); $root = $this->root($enrolled); $next = null;
        $history = DB::table('desktop_dashboard_workspace_manifests')->orderBy('revision')->get()->toJson();
        $writes = $this->writes(function () use ($root, &$next) { $next = $this->authority()->manifest($root); });
        $this->assertCanonical($enrolled['manifest'], $next); $this->assertSame([], $writes);
        $this->assertSame($history, DB::table('desktop_dashboard_workspace_manifests')->orderBy('revision')->get()->toJson());
    }

    public function test_password_permission_branch_and_status_changes_refresh_grants_and_preserve_old_manifest_history(): void
    {
        $beforeLegacy = $this->legacy(); $enrolled = $this->enroll(); $root = $this->root($enrolled);
        $first = $enrolled['manifest']; $oldMember = $this->member($first, 11);
        $savedHistory = DB::table('desktop_dashboard_workspace_manifests')->where('revision', $first['revision'])->first();
        $hash = password_hash('changed-fixture-password', PASSWORD_BCRYPT, ['cost' => 4]);
        DB::table('users')->where('id', 11)->update(['password' => $hash]);
        $second = $this->authority()->manifest($root);
        $this->assertSame($first['revision'] + 1, $second['revision']);
        $this->assertNotSame($oldMember['password_binding'], $this->member($second, 11)['password_binding']);
        $this->assertNotSame($oldMember['grant'], $this->member($second, 11)['grant']);
        $this->denied(fn () => $this->authority()->validateMember($root, 11, $oldMember['grant'], $first['revision']), [409]);
        DB::table('model_has_permissions')->insert(['model_type' => User::class, 'model_id' => 30, 'permission_id' => 1]);
        $third = $this->authority()->manifest($root);
        $this->assertSame($second['revision'] + 1, $third['revision']);
        $this->assertContains('order-list', $this->member($third, 30)['permissions']['names']);
        $this->assertSame(['f:100', 'f:101'], $this->member($third, 30)['branches']);
        DB::table('resturants')->where('id', 101)->update(['user_id' => 10]);
        $fourth = $this->authority()->manifest($root);
        $this->assertSame($third['revision'] + 1, $fourth['revision']);
        $this->assertSame([], $this->member($fourth, 11)['branches']);
        DB::table('users')->where('id', 11)->update(['status' => 'disabled']);
        $fifth = $this->authority()->manifest($root);
        $this->assertSame($fourth['revision'] + 1, $fifth['revision']);
        $this->assertNotContains(11, array_column($fifth['members'], 'actor_id'));
        $row = DB::table('desktop_dashboard_workspace_members')->where('workspace_id', $root->id)->where('actor_id', 11)->first();
        $this->assertNotNull($row); $this->assertFalse((bool)$row->enabled);
        $this->denied(fn () => $this->authority()->validateMember($root, 11, $oldMember['grant'], $first['revision']), [403, 409]);
        $this->assertSame($enrolled['root_fingerprint'], $this->authority()->rootFingerprint($root));
        $this->assertEquals($savedHistory, DB::table('desktop_dashboard_workspace_manifests')->where('revision', $first['revision'])->first());
        $this->assertSame($beforeLegacy, $this->legacy());
    }

    public function test_disabling_the_original_preparer_does_not_disable_other_workspace_accounts(): void
    {
        $enrolled = $this->enroll(10); $root = $this->root($enrolled);
        DB::table('users')->where('id', 10)->update(['status' => 'disabled']);
        $authenticated = $this->authority()->authenticate($enrolled['token']);
        $manifest = $this->authority()->manifest($authenticated);
        $ids = array_column($manifest['members'], 'actor_id'); sort($ids);
        $this->assertSame([1, 11, 30], $ids);
        $this->assertSame(10, (int)$authenticated->custodian_actor_id);
        $member = $this->member($manifest, 11);
        $this->assertCanonical($member, $this->authority()->validateMember($root, 11, $member['grant'], $manifest['revision']));
        $this->assertSame($enrolled['root_fingerprint'], $this->authority()->rootFingerprint($authenticated));
    }

    public function test_unrelated_roster_updates_and_password_rotation_preserve_accepted_command_evidence_but_not_old_login_grants(): void
    {
        $beforeLegacy = $this->legacy(); $enrolled = $this->enroll(); $root = $this->root($enrolled);
        $old = $enrolled['manifest']; $accepted = $this->member($old, 11);
        DB::table('users')->insert(['id' => 50, 'name' => 'New unrelated branch account',
            'email' => 'actor50@example.test', 'mobile' => '1000000050',
            'password' => password_hash('new-member-only', PASSWORD_BCRYPT, ['cost' => 4]),
            'account_type' => 'vendor', 'app_scope' => 'fasakhansta', 'status' => 'accepted']);
        $current = $this->authority()->manifest($root);
        $this->assertSame($old['revision'] + 1, $current['revision']);
        $this->assertCanonical($accepted, $this->authority()->validateHistoricalMember($root, 11, $accepted['grant'], $old['revision']));
        $this->denied(fn () => $this->authority()->validateMember($root, 11, $accepted['grant'], $old['revision']), [409]);
        $replay = $this->authority()->scopeForReplay($root, 11, $accepted['grant'], $old['revision']);
        $this->assertSame($current['revision'], $replay->manifestRevision());
        $this->assertSame($old['revision'], $replay->acceptedManifestRevision());
        $this->assertCanonical($accepted, $replay->acceptedMember());
        $this->assertCanonical($this->member($current, 11), $replay->member());
        $this->authority()->assertScope($replay);
        DB::table('users')->where('id', 11)->update([
            'password' => password_hash('rotated-member-password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $afterPassword = $this->authority()->manifest($root);
        $this->assertSame($current['revision'] + 1, $afterPassword['revision']);
        $this->assertCanonical($accepted, $this->authority()->validateHistoricalMember($root, 11, $accepted['grant'], $old['revision']));
        $this->denied(fn () => $this->authority()->validateMember($root, 11, $accepted['grant'], $old['revision']), [409]);
        $after = $this->authority()->scopeForReplay($root, 11, $accepted['grant'], $old['revision']);
        $this->assertSame($afterPassword['revision'], $after->manifestRevision());
        $this->assertSame($old['revision'], $after->acceptedManifestRevision());
        $this->assertNotSame($after->member()['password_binding'], $after->acceptedMember()['password_binding']);
        $this->authority()->assertScope($after);
        $this->assertSame($beforeLegacy, $this->legacy());
    }

    public function test_replay_uses_only_the_intersection_of_accepted_and_current_branch_and_permission_grants(): void
    {
        $enrolled = $this->enroll(); $root = $this->root($enrolled); $old = $enrolled['manifest'];
        $accepted = $this->member($old, 11);
        $this->assertSame(['f:101'], $accepted['branches']);
        $this->assertSame(['order-create'], $accepted['permissions']['names']);
        DB::table('resturants')->where('id', 100)->update(['user_id' => 11]);
        DB::table('model_has_roles')->insert(['model_type' => User::class, 'model_id' => 11, 'role_id' => 1]);
        $now = $this->authority()->manifest($root); $member = $this->member($now, 11);
        $this->assertTrue($member['permissions']['super_admin']);
        $this->assertSame(['f:100', 'f:101'], $member['branches']);
        $replay = $this->authority()->scopeForReplay($root, 11, $accepted['grant'], $old['revision']);
        $this->assertSame(['f:101'], $replay->branchGrants());
        $this->assertSame(['f:101'], json_decode($replay->branches, true, 512, JSON_THROW_ON_ERROR));
        $this->assertFalse($replay->permissionFacts()['super_admin']);
        $this->assertSame(['order-create'], $replay->permissionFacts()['names']);
        $this->assertSame(['Branch Operator'], $replay->permissionFacts()['roles']);
        $this->authority()->assertScope($replay);
        DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', 11)->where('role_id', 1)->delete();
        DB::table('role_has_permissions')->where('role_id', 3)->where('permission_id', 2)->delete();
        DB::table('model_has_permissions')->insert(['model_type' => User::class, 'model_id' => 11, 'permission_id' => 3]);
        DB::table('resturants')->where('id', 101)->update(['user_id' => 10]);
        $restricted = $this->authority()->scopeForReplay($root, 11, $accepted['grant'], $old['revision']);
        $this->assertSame([], $restricted->branchGrants());
        $this->assertSame([], $restricted->permissionFacts()['names']);
        $this->assertFalse($restricted->permissionFacts()['super_admin']);
        $this->assertSame(['f:100'], $restricted->member()['branches']);
        $this->assertContains('order-edit', $restricted->member()['permissions']['names']);
        $this->authority()->assertScope($restricted);
    }

    public function test_revoked_or_changed_role_members_keep_historical_audit_evidence_without_current_replay_authority(): void
    {
        $enrolled = $this->enroll(); $root = $this->root($enrolled); $old = $enrolled['manifest'];
        $accepted = $this->member($old, 11);
        DB::table('users')->where('id', 11)->update(['status' => 'disabled']);
        $this->authority()->manifest($root);
        $this->assertCanonical($accepted, $this->authority()->validateHistoricalMember($root, 11, $accepted['grant'], $old['revision']));
        $this->denied(fn () => $this->authority()->scopeForReplay($root, 11, $accepted['grant'], $old['revision']), [403]);
        DB::table('users')->where('id', 11)->update(['status' => 'accepted', 'account_type' => 'admin', 'owner_resturant_id' => 101]);
        $now = $this->authority()->manifest($root);
        $this->assertSame('admin', $this->member($now, 11)['role']);
        $this->assertCanonical($accepted, $this->authority()->validateHistoricalMember($root, 11, $accepted['grant'], $old['revision']));
        $this->denied(fn () => $this->authority()->scopeForReplay($root, 11, $accepted['grant'], $old['revision']), [403]);
        $member = $this->member($now, 11);
        $current = $this->authority()->scope($root, 11, $member['grant'], $now['revision']);
        $this->assertSame('admin', $current->actor()->account_type);
        $this->assertNull($current->acceptedMember());
        $this->authority()->assertScope($current);
    }

    public function test_php_canonicalization_matches_the_cross_language_membership_fixture(): void
    {
        $fixture = json_decode(file_get_contents(base_path('desktop-pos/test/fixtures/workspace-members-v2.json')), true, 64, JSON_THROW_ON_ERROR);
        $manifest = $fixture['manifest'];
        $this->assertSame($fixture['members_sha256'], hash('sha256', Authority::canonical($manifest['members'])));
        unset($manifest['signature']);
        $this->assertSame($fixture['manifest_body_sha256'], hash('sha256', Authority::canonical($manifest)));
    }

    public function test_ordinary_member_checks_and_replay_do_not_rebuild_the_global_roster_or_write_unchanged_authority(): void
    {
        $enrolled = $this->enroll(); $root = $this->root($enrolled); $manifest = $enrolled['manifest'];
        $member = $this->member($manifest, 11);
        $lightweight = new class extends Authority {
            public function eligibleMembers(): array
            { throw new \RuntimeException('An ordinary account check must not rebuild the global roster.'); }
        };
        $writes = $this->writes(function () use ($lightweight, $root, $manifest, $member) {
            $this->assertCanonical($member, $lightweight->validateMember($root, 11, $member['grant'], $manifest['revision']));
            $scope = $lightweight->scopeForReplay($root, 11, $member['grant'], $manifest['revision']);
            $this->assertSame(11, $scope->actor_id);
            $this->assertCanonical($member, $scope->member());
            $this->assertCanonical($member, $scope->acceptedMember());
            $lightweight->assertScope($scope);
        });
        $this->assertSame([], $writes);
    }
    public function test_retired_actor_print_outcome_keeps_original_command_proof_without_new_execution_authority(): void
    {
        require_once database_path('migrations/2026_10_27_030010_create_workspace_command_authorities.php');
        (new \CreateWorkspaceCommandAuthorities)->up();
        require_once database_path('migrations/2026_10_20_000001_create_desktop_dashboard_print_ownership.php');
        (new \CreateDesktopDashboardPrintOwnership)->up();
        $enrolled=$this->enroll();$root=$this->root($enrolled);$manifest=$enrolled['manifest'];$member=$this->member($manifest,11);
        $scope=$this->authority()->scope($root,11,$member['grant'],$manifest['revision']);
        $command='cccccccc-cccc-4ccc-8ccc-cccccccccccc';$effect=str_repeat('d',64);$fingerprint=$scope->rootFingerprint();
        $payload=['enrollment_binding'=>['format'=>1,'fingerprint'=>$fingerprint],
            'envelope'=>['command_id'=>$command,'actor_id'=>11]];
        \App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::run($scope,
            fn()=>app(\App\Services\Dashboard\DesktopDashboardJournal::class)->execute(self::DEVICE,$command,11,'fixture.print-work',
                $payload,[],fn()=>['committed'=>true]));
        DB::table('desktop_dashboard_print_ownership')->insert(['effect_id'=>$effect,'device_id'=>self::DEVICE,'command_id'=>$command,
            'enrollment_id'=>$fingerprint,'actor_id'=>11,'branch'=>'f:101','kind'=>'sale_receipt','revision'=>1,'descriptor_hash'=>str_repeat('e',64),
            'local_references'=>'{}','server_references'=>'{}','outcome'=>null,'outcome_hash'=>null,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('users')->where('id',11)->update(['status'=>'disabled']);$this->authority()->manifest($root);
        $this->denied(fn()=>$this->authority()->scopeForReplay($root,11,$member['grant'],$manifest['revision']),[403]);
        $receipt=['effect_id'=>$effect,'enrollment_id'=>$fingerprint,'origin_command_id'=>$command,'actor_id'=>11,'branch'=>'f:101','revision'=>1,
            'claim_token'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd','native_session_id'=>'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'invocation_id'=>'ffffffff-ffff-4fff-8fff-ffffffffffff','permit_hash'=>str_repeat('f',64),'state'=>'review','reason'=>'previous-native-session'];
        $audit=app(\App\Services\Dashboard\DesktopDashboardWorkspacePrintOwnership::class);
        $result=$audit->outcome($root,$receipt);$this->assertSame($result,$audit->outcome($root,$receipt));
        $this->assertSame('review',$result['state']);$this->assertSame(11,json_decode(DB::table('desktop_dashboard_print_ownership')->value('outcome'),true)['actor_id']);
        $changed=$receipt;$changed['actor_id']=10;$this->denied(fn()=>$audit->outcome($root,$changed),[403]);
        $changed=$receipt;$changed['state']='invoked';$this->denied(fn()=>$audit->outcome($root,$changed),[409]);
        DB::table('desktop_dashboard_ws_command_auth')->where('command_id',$command)->update(['proof_mac'=>str_repeat('0',64)]);
        $this->denied(fn()=>$audit->outcome($root,$receipt),[503]);
        $this->assertSame(2,DB::table('desktop_dashboard_commands')->count());
    }
}
