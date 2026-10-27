<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DesktopDashboardWorkspaceAuthority as Authority;
use App\Services\Dashboard\DesktopDashboardWorkspaceLocalAuthority as LocalAuthority;
use App\Services\Dashboard\DesktopDashboardWorkspaceLocalSchema as LocalSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Locally sealed fixture metadata is independent of the server's signing key. No HTTP setter exists. */
class DesktopDashboardWorkspaceLocalAuthorityTest extends TestCase
{
    private const DEVICE='11111111-1111-4111-8111-111111111111';
    private const SNAPSHOT='22222222-2222-4222-8222-222222222222';
    private string $connection;
    private array $manifest;
    private array $record;
    private array $expected;
    private string $token;
    private $oldNative;
    private $oldControl;

    protected function setUp(): void
    {
        parent::setUp();$this->oldNative=getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');$this->oldControl=getenv('DESKTOP_DASHBOARD_CONTROL_TOKEN');
        $this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported test database');
        if($this->connection==='mysql'&&config('database.connections.mysql.database')!=='takeaway_test')
            throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default'=>$this->connection,'cache.default'=>'array','desktop_dashboard.enabled'=>true,
            'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false,'desktop_dashboard.device_id'=>self::DEVICE,
            'app.key'=>'base64:'.base64_encode(str_repeat('s',32))]);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');
        if($this->connection==='mysql')$this->dropFixtures();
        Schema::create('users',function(Blueprint $t){
            $t->id();$t->string('name');$t->string('email')->nullable();$t->string('mobile')->nullable();
            $t->string('password');$t->string('account_type');$t->string('status')->default('accepted');
            $t->string('app_scope')->nullable();$t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable();$t->timestamps();
        });
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->timestamps();});
        Schema::create('go_stores',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id')->unique();$t->string('name');$t->timestamps();});
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');(new \CreatePermissionTables)->up();
        require_once database_path('migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php');(new \CreateDesktopDashboardWorkspaces)->up();
        require_once database_path('migrations/2026_10_08_160000_create_desktop_dashboard_local_state.php');(new \CreateDesktopDashboardLocalState)->up();
        foreach([10,11]as$id)DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'email'=>'actor'.$id.'@example.test',
            'mobile'=>(string)(1000000000+$id),'password'=>password_hash('fixture-'.$id,PASSWORD_BCRYPT,['cost'=>4]),
            'account_type'=>'vendor','app_scope'=>'fasakhansta','status'=>'accepted','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Custodian branch'],['id'=>101,'user_id'=>11,'name'=>'Actor B branch']]);
        $enrolled=app(Authority::class)->enroll(['device_id'=>self::DEVICE,'name'=>'Shared PC','nonce'=>str_repeat('a',64)],$this->actor(10));
        $root=app(Authority::class)->authenticate($enrolled['token']);$this->token=$enrolled['token'];
        $this->manifest=json_decode(Authority::canonical($enrolled['manifest']),true,64,JSON_THROW_ON_ERROR);
        config(['desktop_dashboard.local'=>true,'app.key'=>'base64:'.base64_encode(str_repeat('l',32))]);
        app()->forgetInstance('encrypter');putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');LocalSchema::ensure();
        $snapshot=['workspace_id'=>self::DEVICE,'custodian_actor_id'=>10,'snapshot_id'=>self::SNAPSHOT,
            'schema_hash'=>str_repeat('b',64),'source'=>['format'=>1,'files'=>10,'sha256'=>str_repeat('c',64),'framework'=>'fixture'],
            'branches'=>['f:100','f:101'],'coverage'=>['workspace_protocol'=>2]];
        $this->record=['format'=>2,'kind'=>'desktop-dashboard-workspace-local-authority','workspace_id'=>self::DEVICE,
            'root_fingerprint'=>$this->manifest['root_fingerprint'],'root'=>['id'=>$root->id,'custodian_actor_id'=>10,'name'=>'Shared workspace',
                'enabled'=>true,'enrollment_hash'=>$root->enrollment_hash,'token_hash'=>$root->token_hash,'enrolled_at'=>$root->enrolled_at],
            'manifest_revision'=>$this->manifest['revision'],'roster_sha256'=>$this->manifest['roster_sha256'],
            'manifest_sha256'=>hash('sha256',Authority::canonical($this->manifest)),'manifest_signature'=>$this->manifest['signature'],
            'member_count'=>2,'snapshot'=>$snapshot,'database'=>DB::connection()->getDatabaseName(),'expected_binding_digest'=>null];
        DB::table('desktop_dashboard_local_state')->insert(['device_id'=>self::DEVICE,'actor_id'=>10,'snapshot_id'=>self::SNAPSHOT,
            'schema_hash'=>str_repeat('b',64),'branches'=>'["f:100","f:101"]','coverage'=>'{"workspace_protocol":2}',
            'state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $this->writeRecord();
        foreach($this->manifest['members']as$member)DB::table('desktop_dashboard_workspace_local_members')->insert([
            'workspace_id'=>self::DEVICE,'actor_id'=>$member['actor_id'],'manifest_revision'=>$this->manifest['revision'],
            'member_json'=>Authority::canonical($member),'local_mac'=>$this->mac('member',[
                'record_sha256'=>hash('sha256',Authority::canonical($this->record)),'member'=>$member]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $this->expected=['workspace_id'=>self::DEVICE,'root_fingerprint'=>$this->record['root_fingerprint'],
            'revision'=>$this->record['manifest_revision'],'roster_sha256'=>$this->record['roster_sha256'],
            'manifest_sha256'=>$this->record['manifest_sha256'],'token_hash'=>$root->token_hash];
    }
    protected function tearDown(): void
    {
        putenv($this->oldNative===false?'DESKTOP_DASHBOARD_WORKSPACE_LOCAL':'DESKTOP_DASHBOARD_WORKSPACE_LOCAL='.$this->oldNative);
        putenv($this->oldControl===false?'DESKTOP_DASHBOARD_CONTROL_TOKEN':'DESKTOP_DASHBOARD_CONTROL_TOKEN='.$this->oldControl);
        if($this->connection==='mysql'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();
    }
    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();foreach(['desktop_dashboard_workspace_command_authorities','desktop_dashboard_workspace_local_authority_history',
            'desktop_dashboard_workspace_local_members','desktop_dashboard_workspace_local_authority','desktop_dashboard_refreshes','desktop_dashboard_local_state',
            'desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests','desktop_dashboard_workspaces',
            'role_has_permissions','model_has_roles','model_has_permissions','roles','permissions','go_stores','resturants','users']as$table)Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }
    private function actor(int$id):User{return User::withoutGlobalScopes()->findOrFail($id);}
    private function local():LocalAuthority{return app(LocalAuthority::class);}
    private function mac(string$domain,array$value):string{return hash_hmac('sha256',"desktop-workspace-local-v2\0".$domain."\0".Authority::canonical($value),app('encrypter')->getKey());}
    private function receipt():array{return json_decode(Authority::canonical(array_intersect_key($this->record,
        array_flip(['workspace_id','root_fingerprint','manifest_revision','roster_sha256','manifest_sha256']))),true,16,JSON_THROW_ON_ERROR);}
    private function writeRecord():void
    {
        DB::table('desktop_dashboard_workspace_local_authority')->updateOrInsert(['workspace_id'=>self::DEVICE],[
            'record_json'=>Authority::canonical($this->record),'manifest_json'=>Authority::canonical($this->manifest),
            'local_mac'=>$this->mac('record',$this->record),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    private function denied(callable$work,array$codes=[403,409,422,503]):void
    {
        $error=null;try{$work();}catch(HttpException$e){$error=$e;}$this->assertInstanceOf(HttpException::class,$error);$this->assertContains($error->getStatusCode(),$codes);
    }

    public function test_actor_b_uses_same_physical_snapshot_without_inheriting_custodian_a():void
    {
        $this->actingAs($this->actor(11),'admin');$scope=$this->local()->currentScope($this->actor(11));
        $this->assertSame(11,$scope->actor_id);$this->assertSame(10,$scope->root()->custodian_actor_id);
        $this->assertSame(['f:101'],$scope->branchGrants());$this->assertSame(self::SNAPSHOT,$scope->snapshot_id);
        $this->assertTrue($scope->isLocal());$scope->revalidated();
        $this->assertSame(10,(int)$this->local()->authenticateLocal($this->token)->actor_id);
        $this->denied(fn()=>$this->local()->currentScope($this->actor(10)),[403]);
        $this->denied(fn()=>$this->local()->authenticateLocal(str_repeat('e',64)),[401]);
    }
    public function test_normal_scope_never_reads_or_rechecks_the_whole_manifest():void
    {
        DB::table('desktop_dashboard_workspace_local_authority')->update(['manifest_json'=>'DO_NOT_READ_DURING_LOGIN']);
        DB::enableQueryLog();$scope=$this->local()->scopeForActor(11);$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertSame(11,$scope->actor_id);
        foreach($queries as$query)$this->assertStringNotContainsString('manifest_json',$query['query']);
        $this->denied(fn()=>$this->local()->verifyImport($this->receipt(),$this->expected),[503]);
    }
    public function test_member_and_summary_tampering_cannot_fall_back_to_legacy_authority():void
    {
        DB::table('desktop_dashboard_workspace_local_members')->where('actor_id',11)->update(['local_mac'=>str_repeat('0',64)]);
        $this->denied(fn()=>$this->local()->scopeForActor(11),[403]);
        DB::table('desktop_dashboard_workspace_local_authority')->update(['local_mac'=>str_repeat('0',64)]);
        $this->denied(fn()=>$this->local()->active(),[503]);
    }
    public function test_fresh_local_status_password_and_snapshot_must_match_the_sealed_grant():void
    {
        DB::table('users')->where('id',11)->update(['status'=>'disabled']);$this->denied(fn()=>$this->local()->scopeForActor(11),[403]);
        DB::table('users')->where('id',11)->update(['status'=>'accepted','password'=>password_hash('new-password',PASSWORD_BCRYPT,['cost'=>4])]);
        $this->denied(fn()=>$this->local()->scopeForActor(11),[403]);
        DB::table('desktop_dashboard_local_state')->update(['snapshot_id'=>'33333333-3333-4333-8333-333333333333']);
        $this->denied(fn()=>$this->local()->scopeForActor(10),[503]);
    }
    public function test_expected_revision_and_root_token_are_mandatory_for_import_and_cutover():void
    {
        $this->assertSame($this->manifest,$this->local()->verifyImport($this->receipt(),$this->expected));
        $bindings=$this->local()->handoffBindings($this->receipt(),$this->expected);
        $this->assertSame(['snapshot_id'=>self::SNAPSHOT,'schema_hash'=>str_repeat('b',64),'workspace'=>$this->receipt()],$bindings);
        $bad=$this->expected;$bad['revision']++;$this->denied(fn()=>$this->local()->verifyImport($this->receipt(),$bad),[422]);
        $bad=$this->expected;unset($bad['token_hash']);$this->denied(fn()=>$this->local()->handoffBindings($this->receipt(),$bad),[422]);
        $bad=$this->expected;$bad['token_hash']=str_repeat('0',64);$this->denied(fn()=>$this->local()->handoffBindings($this->receipt(),$bad),[422]);
    }
    public function test_source_capture_uses_its_authenticated_database_without_rebinding_target():void
    {
        $row=(array)DB::table('desktop_dashboard_workspace_local_authority')->first();
        $members=DB::table('desktop_dashboard_workspace_local_members')->get()->map(fn($r)=>(array)$r)->all();
        $state=(array)DB::table('desktop_dashboard_local_state')->first();$database=DB::connection()->getDatabaseName();
        $this->assertSame($this->manifest,$this->local()->verifyCapture($row,$members,$state,$database,$this->expected));
        $this->assertSame($database,DB::connection()->getDatabaseName());
        $row['manifest_json']='NO_FULL_SCAN_AT_CUTOVER';
        $this->assertSame($this->receipt(),$this->local()->verifyCaptureBindings($row,$state,$database,$this->expected)['workspace']);
        $this->denied(fn()=>$this->local()->verifyCapture($row,$members,$state,$database,$this->expected),[503]);
        $row['local_mac']=str_repeat('0',64);$this->denied(fn()=>$this->local()->verifyCaptureBindings($row,$state,$database,$this->expected),[503]);
    }
    public function test_remember_binding_resolves_b_before_guard_authentication_and_revokes_on_password_change():void
    {
        $user=$this->actor(11);$this->assertNull(auth('admin')->id());$binding=$this->local()->rememberBinding($user);
        $this->assertSame(['format','device','actor','password_binding'],array_keys($binding));$this->assertSame(2,$binding['format']);
        $this->assertSame(11,$binding['actor']);$this->assertNotSame($user->password,$binding['password_binding']);
        DB::table('users')->where('id',11)->update(['password'=>password_hash('changed',PASSWORD_BCRYPT,['cost'=>4])]);
        $this->denied(fn()=>$this->local()->rememberBinding($user),[403]);
    }
    public function test_explicit_native_selection_fails_closed_but_unselected_legacy_is_untouched():void
    {
        config(['desktop_dashboard.workspace_enabled'=>false]);$this->denied(fn()=>$this->local()->active(),[503]);
        putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');$this->assertFalse($this->local()->active());
        config(['desktop_dashboard.workspace_enabled'=>true]);$this->denied(fn()=>LocalSchema::ensure(),[403]);
    }
    public function test_physical_print_identity_is_stable_and_custodian_status_cannot_change_b_print_grants():void
    {
        $prints=app(\App\Services\Dashboard\DesktopDashboardWorkspacePrintAuthority::class);
        $identity=DB::transaction(fn()=>$prints->identity($prints->root(self::DEVICE)));
        $this->assertSame($this->record['root_fingerprint'],$identity);
        DB::table('users')->where('id',10)->update(['status'=>'disabled']);
        $scope=$this->local()->scopeForActor(11);
        \App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::run($scope,function()use($prints,$identity){
            DB::transaction(function()use($prints,$identity){
                $this->assertSame($identity,$prints->identity($prints->root(self::DEVICE)));
                $this->assertSame(11,$prints->actor(self::DEVICE,11,'f:101')->actor_id);
                $this->denied(fn()=>$prints->actor(self::DEVICE,11,'f:100'),[403]);
                $this->denied(fn()=>$prints->actor(self::DEVICE,10,'f:100'),[403]);
                $authority=app(\App\Services\Dashboard\DesktopDashboardPrintAuthority::class);$actor=$authority->actor(11);
                $authority->branch('f:101',$actor);$this->denied(fn()=>$authority->branch('f:100',$actor),[403,404]);
            });
        });
    }
    public function test_readonly_scope_refresh_uses_no_locking_reads_and_cannot_issue_a_print_write():void
    {
        $scope=$this->local()->scopeForActor(11);$context=\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::class;
        if($this->connection==='mysql')DB::statement('SET TRANSACTION READ ONLY');
        DB::transaction(function()use($scope,$context){
            $context::run($scope,fn()=>$context::readOnly(function()use($scope){
                DB::enableQueryLog();$fresh=$scope->revalidated();$queries=DB::getQueryLog();DB::disableQueryLog();
                $this->assertSame(11,$fresh->actor_id);
                foreach($queries as$query)$this->assertStringNotContainsString('for update',strtolower($query['query']));
                $this->denied(fn()=>app(\App\Services\Dashboard\DesktopDashboardWorkspacePrintAuthority::class)
                    ->actor(self::DEVICE,11,'f:101',true),[403]);
            }));
        });
    }
    public function test_native_session_requires_actual_login_then_preserves_b_and_restores_guard_after_failure():void
    {
        putenv('DESKTOP_DASHBOARD_CONTROL_TOKEN='.str_repeat('c',64));
        $sessions=app(\App\Services\Dashboard\DesktopDashboardWorkspaceNativeSessions::class);$scope=$this->local()->scopeForActor(11);
        $this->denied(fn()=>$sessions->issue($scope),[403]);
        $this->actingAs($this->actor(11),'admin');$capability=$sessions->issue($scope);auth('admin')->forgetUser();
        $this->assertNull(auth('admin')->id());
        $this->assertSame(11,$sessions->withSession($capability,function($current){
            $this->assertSame(11,(int)auth('admin')->id());
            $this->assertSame(11,\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::current()->actor_id);
            return $current->actor_id;
        }));
        $this->assertNull(auth('admin')->id());$this->assertNull(\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::current());
        try{$sessions->withSession($capability,function(){throw new \RuntimeException('Synthetic callback failure');});$this->fail('Expected callback failure');}
        catch(\RuntimeException$e){$this->assertSame('Synthetic callback failure',$e->getMessage());}
        $this->assertNull(auth('admin')->id());$this->assertNull(\App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::current());
        $this->actingAs($this->actor(10),'admin');$this->denied(fn()=>$sessions->withSession($capability,fn()=>null),[403]);
        $this->assertSame(10,(int)auth('admin')->id());
    }
    public function test_native_session_rejects_changed_actor_root_revision_password_and_control_credential():void
    {
        putenv('DESKTOP_DASHBOARD_CONTROL_TOKEN='.str_repeat('c',64));$this->actingAs($this->actor(11),'admin');
        $sessions=app(\App\Services\Dashboard\DesktopDashboardWorkspaceNativeSessions::class);
        $capability=$sessions->issue($this->local()->currentScope($this->actor(11)));auth('admin')->forgetUser();
        foreach(['actor_id'=>10,'root_fingerprint'=>str_repeat('0',64),'manifest_revision'=>2,'password_binding'=>str_repeat('0',64),
            'snapshot_id'=>'33333333-3333-4333-8333-333333333333','schema_hash'=>str_repeat('0',64)]as$key=>$value){
            $changed=$capability;$changed[$key]=$value;$this->denied(fn()=>$sessions->withSession($changed,fn()=>null),[403]);
        }
        putenv('DESKTOP_DASHBOARD_CONTROL_TOKEN='.str_repeat('d',64));$this->denied(fn()=>$sessions->withSession($capability,fn()=>null),[403]);
        putenv('DESKTOP_DASHBOARD_CONTROL_TOKEN='.str_repeat('c',64));
        DB::table('users')->where('id',11)->update(['password'=>password_hash('rotated',PASSWORD_BCRYPT,['cost'=>4])]);
        $this->denied(fn()=>$sessions->withSession($capability,fn()=>null),[403]);$this->assertNull(auth('admin')->id());
    }
    public function test_module_adapters_require_original_context_permissions_and_sealed_full_dataset():void
    {
        $scope=$this->local()->scopeForActor(11);$module=app(\App\Services\Dashboard\DesktopDashboardWorkspaceModuleAuthority::class);
        $this->denied(fn()=>$module->scope($scope,$this->actor(11)),[403]);
        \App\Services\Dashboard\DesktopDashboardWorkspaceExecutionContext::run($scope,function()use($scope,$module){
            $this->assertSame($scope,$module->scope($scope,$this->actor(11)));
            $this->denied(fn()=>$module->scope($scope,$this->actor(11),['setting-list']),[403]);
            $this->denied(fn()=>$module->scope($scope,$this->actor(11),[],true),[503]);
            $this->denied(fn()=>$module->scope((object)['id'=>self::DEVICE,'actor_id'=>11],$this->actor(11)),[403]);
        });
        DB::table('desktop_dashboard_local_state')->update(['coverage'=>'{"workspace_protocol":2,"full_dashboard":true}']);
        $this->denied(fn()=>$this->local()->scopeForActor(11),[503]);
    }
}
