<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardCreatedScopes,DesktopDashboardDevices,DesktopDashboardJournal,
    DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceLocalAuthority as LocalAuthority,
    DesktopDashboardWorkspaceLocalSchema,DesktopDashboardWorkspaceCreatedScopes as Catalog,
    DesktopDashboardWorkspaceExecutionContext as Context,DesktopDashboardWorkspaceScope};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Real shared SQL, original Journal and immutable sidecars; no mocked actor authorization. */
class DesktopDashboardWorkspaceCreatedScopesTest extends TestCase
{
    private const DEVICE='11111111-1111-4111-8111-111111111111';
    private const SNAPSHOT='22222222-2222-4222-8222-222222222222';
    private const COMMAND='33333333-3333-4333-8333-333333333333';
    private const OWNER=['id','added_by','name','mobile','email','account_type','app_scope','status','balance','delegate_fees','created_at','updated_at','email_verified_at','pending_vendor_id','owner_resturant_id'];
    private const PROFILE=['user_id','name','kind','address','revision','created_at','updated_at'];
    private string $connection;
    private array $manifest;
    private array $record;
    private $oldNative;

    protected function setUp(): void
    {
        parent::setUp();$this->oldNative=getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');
        $this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported database');
        if($this->connection==='mysql'&&config('database.connections.mysql.database')!=='takeaway_test')
            throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default'=>$this->connection,'cache.default'=>'array','desktop_dashboard.enabled'=>true,
            'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false,'desktop_dashboard.device_id'=>self::DEVICE,
            'app.key'=>'base64:'.base64_encode(str_repeat('s',32))]);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');
        if($this->connection==='mysql')$this->dropFixtures();
        Schema::create('users',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('added_by')->nullable();$t->string('name');$t->string('email')->nullable();$t->string('mobile')->nullable();
            $t->string('password');$t->string('remember_token')->nullable();$t->string('account_type');$t->string('status')->default('accepted');
            $t->string('app_scope')->nullable();$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->decimal('balance',12,2)->default(0);$t->decimal('delegate_fees',12,2)->nullable();$t->timestamp('email_verified_at')->nullable();$t->timestamps();
        });
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->timestamps();});
        Schema::create('go_stores',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id')->unique();$t->string('name');$t->string('kind')->default('supermarket');$t->string('address')->nullable();$t->unsignedInteger('revision')->default(1);$t->timestamps();});
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');(new \CreatePermissionTables)->up();
        require_once database_path('migrations/2026_10_08_130000_create_desktop_dashboard_journal.php');(new \CreateDesktopDashboardJournal)->up();
        require_once database_path('migrations/2026_10_08_160000_create_desktop_dashboard_local_state.php');(new \CreateDesktopDashboardLocalState)->up();
        require_once database_path('migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php');(new \CreateDesktopDashboardWorkspaces)->up();
        require_once database_path('migrations/2026_10_20_000002_create_desktop_dashboard_created_scopes.php');(new \CreateDesktopDashboardCreatedScopes)->up();
        foreach([1,10,30,31,32]as$id)DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'email'=>'actor'.$id.'@example.test',
            'mobile'=>(string)(1000000000+$id),'password'=>password_hash('fixture-'.$id,PASSWORD_BCRYPT,['cost'=>4]),
            'account_type'=>$id===10?'vendor':'admin','status'=>'accepted','app_scope'=>'fasakhansta',
            'created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-01 00:00:00']);
        DB::table('resturants')->insert(['id'=>100,'user_id'=>10,'name'=>'Original branch']);
        foreach(['resturant-list','resturant-create','resturant-edit','order-list']as$i=>$name)
            DB::table('permissions')->insert(['id'=>$i+1,'name'=>$name,'guard_name'=>'admin']);
        foreach([[30,1],[30,2],[30,3],[31,1],[32,4]]as[$actor,$permission])DB::table('model_has_permissions')->insert([
            'model_type'=>User::class,'model_id'=>$actor,'permission_id'=>$permission]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $enrolled=app(Authority::class)->enroll(['device_id'=>self::DEVICE,'name'=>'Shared PC','nonce'=>str_repeat('a',64)],$this->actor(10));
        $root=app(Authority::class)->authenticate($enrolled['token']);$this->manifest=json_decode(Authority::canonical($enrolled['manifest']),true,64,JSON_THROW_ON_ERROR);
        config(['desktop_dashboard.local'=>true,'app.key'=>'base64:'.base64_encode(str_repeat('l',32))]);
        app()->forgetInstance('encrypter');Crypt::clearResolvedInstance('encrypter');putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');
        DesktopDashboardWorkspaceLocalSchema::ensure();$coverage=app(Catalog::class)->coverage(['f:100'],[])+['workspace_protocol'=>2,'print_protocol'=>0];
        $snapshot=['workspace_id'=>self::DEVICE,'custodian_actor_id'=>10,'snapshot_id'=>self::SNAPSHOT,'schema_hash'=>str_repeat('b',64),
            'source'=>['format'=>1,'files'=>10,'sha256'=>str_repeat('c',64),'framework'=>'fixture'],'branches'=>['f:100'],'coverage'=>$coverage];
        $this->record=['format'=>2,'kind'=>'desktop-dashboard-workspace-local-authority','workspace_id'=>self::DEVICE,
            'root_fingerprint'=>$this->manifest['root_fingerprint'],'root'=>['id'=>$root->id,'custodian_actor_id'=>10,'name'=>'Shared PC',
                'enabled'=>true,'enrollment_hash'=>$root->enrollment_hash,'token_hash'=>$root->token_hash,'enrolled_at'=>$root->enrolled_at],
            'manifest_revision'=>$this->manifest['revision'],'roster_sha256'=>$this->manifest['roster_sha256'],
            'manifest_sha256'=>hash('sha256',Authority::canonical($this->manifest)),'manifest_signature'=>$this->manifest['signature'],
            'member_count'=>count($this->manifest['members']),'snapshot'=>$snapshot,'database'=>DB::connection()->getDatabaseName(),'expected_binding_digest'=>null];
        DB::table('desktop_dashboard_local_state')->insert(['device_id'=>self::DEVICE,'actor_id'=>10,'snapshot_id'=>self::SNAPSHOT,
            'schema_hash'=>str_repeat('b',64),'branches'=>'["f:100"]','coverage'=>Authority::canonical($coverage),'state'=>'ready',
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('desktop_dashboard_workspace_local_authority')->insert(['workspace_id'=>self::DEVICE,
            'record_json'=>Authority::canonical($this->record),'manifest_json'=>Authority::canonical($this->manifest),
            'local_mac'=>$this->mac('record',$this->record),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        foreach($this->manifest['members']as$member)DB::table('desktop_dashboard_workspace_local_members')->insert([
            'workspace_id'=>self::DEVICE,'actor_id'=>$member['actor_id'],'manifest_revision'=>$this->manifest['revision'],
            'member_json'=>Authority::canonical($member),'local_mac'=>$this->mac('member',[
                'record_sha256'=>hash('sha256',Authority::canonical($this->record)),'member'=>$member]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    protected function tearDown(): void
    {
        putenv($this->oldNative===false?'DESKTOP_DASHBOARD_WORKSPACE_LOCAL':'DESKTOP_DASHBOARD_WORKSPACE_LOCAL='.$this->oldNative);
        if($this->connection==='mysql'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();
    }
    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();foreach(['desktop_dashboard_created_scopes','desktop_dashboard_workspace_command_authorities',
            'desktop_dashboard_ws_authority_history','desktop_dashboard_workspace_local_members','desktop_dashboard_workspace_local_authority',
            'desktop_dashboard_refreshes','desktop_dashboard_local_state','desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests',
            'desktop_dashboard_workspaces','desktop_dashboard_entities','desktop_dashboard_commands','desktop_dashboard_devices',
            'role_has_permissions','model_has_roles','model_has_permissions','roles','permissions','go_stores','resturants','users']as$table)Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }
    private function actor(int $id):User{return User::withoutGlobalScopes()->findOrFail($id);}
    private function scope(int $id):DesktopDashboardWorkspaceScope{return app(LocalAuthority::class)->scopeForActor($id);}
    private function mac(string $domain,array $value):string{return hash_hmac('sha256',"desktop-workspace-local-v2\0".$domain."\0".Authority::canonical($value),app('encrypter')->getKey());}
    private function denied(callable $work,array $codes=[403,409,422,503]):void
    {
        $error=null;try{$work();}catch(HttpExceptionInterface $caught){$error=$caught;}
        $this->assertInstanceOf(HttpExceptionInterface::class,$error);$this->assertContains($error->getStatusCode(),$codes);
    }
    private function create(int $id=1000,string $command=self::COMMAND):array
    {
        $scope=$this->scope(1);$payload=['parameters'=>[],'values'=>['idempotency_key'=>$command],'files'=>[],'facts'=>[]];
        return Context::run($scope,fn()=>app(DesktopDashboardJournal::class)->execute(self::DEVICE,$command,1,'go-stores.store',$payload,[],function()use($id,$command){
            DB::table('users')->insert(['id'=>$id,'added_by'=>1,'name'=>'Created store','email'=>'store'.$id.'@example.test','mobile'=>(string)(1100000000+$id),
                'password'=>password_hash('new-store',PASSWORD_BCRYPT,['cost'=>4]),'account_type'=>'vendor','app_scope'=>'go_partner','status'=>'accepted',
                'balance'=>'0.00','created_at'=>'2026-10-27 09:00:00','updated_at'=>'2026-10-27 09:00:00']);
            DB::table('go_stores')->insert(['user_id'=>$id,'name'=>'New catalog','kind'=>'supermarket','address'=>'Fixture address','revision'=>1,
                'created_at'=>'2026-10-27 09:00:00','updated_at'=>'2026-10-27 09:00:00']);
            return ['http'=>['status'=>302,'content'=>'','type'=>'text/html','location'=>'/admin/go-stores/'.$id],'success'=>'created',
                'after'=>['owner'=>(array)DB::table('users')->where('id',$id)->first(self::OWNER),
                    'profile'=>(array)DB::table('go_stores')->where('user_id',$id)->first(self::PROFILE)],
                'references'=>['menu_store_owner'=>$id,'wallet_account'=>$id,'dashboard_user'=>$id],
                'created_scope'=>['kind'=>'gs','entity'=>'menu_store_owner','id'=>$id,'command_id'=>$command]];
        }));
    }
    public function test_new_catalog_is_shared_with_authorized_b_without_widening_financial_base():void
    {
        $before=DB::table('desktop_dashboard_local_state')->first();$this->create();$catalog=app(Catalog::class);
        $editor=$this->scope(30);$financial=$this->scope(32);
        DB::transaction(function()use($catalog,$editor,$financial){
            $this->assertSame(['gs:1000'],$catalog->effective($editor,$this->actor(30)));
            $catalog->catalog($editor,'gs:1000',$this->actor(30));
            $this->assertSame(['f:100'],$financial->branchGrants());
            $this->assertSame(['f:100','gs:1000'],$catalog->structural($financial,$this->actor(32)));
            $this->assertSame(['f:100'],$catalog->effective($financial,$this->actor(32)));
            $this->denied(fn()=>app(DesktopDashboardDevices::class)->branch($financial,'gs:1000',$this->actor(32)),[403]);
            $this->assertSame(10,$editor->root()->custodian_actor_id);
        });
        $after=DB::table('desktop_dashboard_local_state')->first();
        $this->assertSame($before->branches,$after->branches);$this->assertSame($before->coverage,$after->coverage);
        $this->assertSame(['f:100'],$this->scope(1)->branchGrants());
        $this->assertSame(1,DB::table('desktop_dashboard_created_scopes')->count());
    }
    public function test_list_only_b_can_read_the_shared_catalog_but_cannot_edit_or_inherit_pos():void
    {
        $this->create();$catalog=app(Catalog::class);$reader=$this->scope(31);$branch=$this->scope(10);
        DB::transaction(function()use($catalog,$reader,$branch){
            $this->assertSame(['gs:1000'],$catalog->readable($reader,$this->actor(31)));
            $this->assertSame([],$catalog->effective($reader,$this->actor(31)));
            $this->denied(fn()=>$catalog->catalog($reader,'gs:1000',$this->actor(31)),[403]);
            $this->assertSame(['f:100'],$catalog->readable($branch,$this->actor(10)));
            $this->denied(fn()=>$catalog->catalog($branch,'gs:1000',$this->actor(10)),[403]);
        });
        $this->assertSame([],$reader->branchGrants());
    }
    public function test_retiring_original_creator_preserves_b_catalog_and_original_receipt_actor():void
    {
        $this->create();DB::table('users')->where('id',1)->update(['status'=>'disabled']);
        $editor=$this->scope(30);$catalog=app(Catalog::class);
        $items=DB::transaction(fn()=>$catalog->export($editor,$this->actor(30)));
        $this->assertSame(1,$items[0]['descriptor']['actor_id']);$this->assertSame(1,$items[0]['creation']['actor_id']);
        $this->assertSame(1,$items[0]['descriptor']['workspace_authority']['actor_id']);
        DB::transaction(fn()=>$catalog->catalog($editor,'gs:1000',$this->actor(30)));
        $this->assertSame(['f:100'],$this->scope(32)->branchGrants());$this->denied(fn()=>$this->scope(1),[403]);
    }
    public function test_only_authenticated_original_acceptance_can_explain_additional_local_branches():void
    {
        DB::table('users')->insert(['id'=>1000,'added_by'=>1,'name'=>'Unproven owner','email'=>'unproven@example.test','mobile'=>'1199999999',
            'password'=>'!desktop-disabled','account_type'=>'vendor','status'=>'accepted','app_scope'=>'go_partner']);
        DB::table('go_stores')->insert(['user_id'=>1000,'name'=>'Unproven store']);
        $this->denied(fn()=>$this->scope(32),[403]);
        DB::table('go_stores')->delete();DB::table('users')->where('id',1000)->delete();$this->create();
        DB::table('desktop_dashboard_workspace_command_authorities')->update(['proof_mac'=>str_repeat('0',64)]);
        $this->denied(fn()=>$this->scope(32),[503]);
    }
    public function test_catalog_tampering_and_same_id_recreation_fail_closed():void
    {
        $this->create();$editor=$this->scope(30);$row=DB::table('desktop_dashboard_created_scopes')->first();
        DB::table('desktop_dashboard_created_scopes')->update(['branch'=>'gs:9999']);
        $this->denied(fn()=>DB::transaction(fn()=>app(Catalog::class)->structural($editor,$this->actor(30))),[503]);
        DB::table('desktop_dashboard_created_scopes')->update(['branch'=>$row->branch]);
        DB::table('users')->where('id',1000)->update(['created_at'=>'2021-01-01 00:00:00']);
        $this->denied(fn()=>DB::transaction(fn()=>app(Catalog::class)->catalog($editor,'gs:1000',$this->actor(30))),[403]);
    }
    public function test_missing_catalog_ledger_rolls_back_store_journal_and_actor_proof():void
    {
        Schema::drop('desktop_dashboard_created_scopes');$this->denied(fn()=>$this->create(),[503]);
        $this->assertFalse(DB::table('users')->where('id',1000)->exists());$this->assertSame(0,DB::table('go_stores')->count());
        $this->assertSame(0,DB::table('desktop_dashboard_commands')->count());
        $this->assertSame(0,DB::table('desktop_dashboard_workspace_command_authorities')->count());
    }
    public function test_readonly_b_navigation_uses_no_for_update_and_stays_on_signed_financial_base():void
    {
        $this->create();$scope=$this->scope(32);
        if($this->connection==='mysql')DB::statement('SET TRANSACTION READ ONLY');
        DB::transaction(function()use($scope){Context::run($scope,fn()=>Context::readOnly(function()use($scope){
            DB::enableQueryLog();$fresh=$scope->revalidated();$queries=DB::getQueryLog();DB::disableQueryLog();
            $this->assertSame(['f:100'],$fresh->branchGrants());
            foreach($queries as$query)$this->assertStringNotContainsString('for update',strtolower($query['query']));
        }));});
    }
    public function test_unchanged_branches_take_no_catalog_scan_and_v1_selection_is_unchanged():void
    {
        $catalog=app(Catalog::class);DB::enableQueryLog();
        $this->assertSame(['f:100'],$catalog->normalizeFreshBranches(['branches'=>['f:100']],['f:100'],self::DEVICE,$this->manifest['root_fingerprint']));
        $this->assertSame([],DB::getQueryLog());DB::disableQueryLog();
        putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');$this->assertFalse($catalog->active());
        $this->assertFalse(app(LocalAuthority::class)->selected());
    }
}
