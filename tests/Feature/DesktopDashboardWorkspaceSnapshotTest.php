<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority as Authority,DesktopDashboardWorkspaceData as Data,
    DesktopDashboardWorkspaceBootstrap as Bootstrap,DesktopDashboardSource as Source};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

/** Persisted union policy; MySQL additionally runs the original schema/redaction serializer. */
class DesktopDashboardWorkspaceSnapshotTest extends TestCase
{
    private string $connection='sqlite';
    private const ID='31111111-1111-4111-8111-111111111111';
    private const TABLES=['desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests','desktop_dashboard_workspaces',
        'notifications','branch_expense_category_commands','takeaway_orders','contracts','settings',
        'role_has_permissions','model_has_roles','model_has_permissions','roles','permissions','resturants','users'];
    protected function setUp(): void
    {
        parent::setUp();$this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true)
            ||($this->connection==='mysql'&&config('database.connections.mysql.database')!=='takeaway_test'))throw new \RuntimeException('Requires isolated test database.');
        config(['database.default'=>$this->connection,'cache.default'=>'array','app.key'=>'base64:'.base64_encode(str_repeat('s',32)),
            'desktop_dashboard.enabled'=>true,'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false]);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');if($this->connection==='mysql')$this->drop();
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->nullable();$t->string('mobile')->nullable();
            $t->string('password');$t->string('remember_token')->nullable();$t->string('account_type');$t->string('app_scope')->nullable();
            $t->string('status');$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->timestamps();});
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');});
        Schema::create('takeaway_orders',function(Blueprint $t){$t->id();$t->string('branch');$t->string('note');});
        Schema::create('branch_expense_category_commands',function(Blueprint $t){$t->id();$t->unsignedBigInteger('actor_id');});
        Schema::create('notifications',function(Blueprint $t){$t->id();$t->string('notifiable_type');$t->unsignedBigInteger('notifiable_id');});
        Schema::create('contracts',function(Blueprint $t){$t->id();$t->string('name');});
        Schema::create('settings',function(Blueprint $t){$t->id();$t->string('group');$t->string('name');$t->longText('payload');});
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');(new \CreatePermissionTables)->up();
        require_once database_path('migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php');(new \CreateDesktopDashboardWorkspaces)->up();
        foreach([[1,'admin','disabled'],[10,'vendor','accepted'],[11,'vendor','accepted'],[20,'user','accepted'],[30,'admin','accepted'],[40,'vendor','disabled']] as [$id,$role,$status]){
            DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'email'=>'actor'.$id.'@example.test','mobile'=>(string)(1000000000+$id),
                'password'=>password_hash('synthetic-password-'.$id,PASSWORD_BCRYPT,['cost'=>4]),'remember_token'=>'PRIVATE-'.$id,
                'account_type'=>$role,'app_scope'=>'fasakhansta','status'=>$status,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            DB::table('notifications')->insert(['id'=>$id,'notifiable_type'=>User::class,'notifiable_id'=>$id]);
            DB::table('branch_expense_category_commands')->insert(['id'=>$id,'actor_id'=>$id]);
        }
        foreach([[100,10],[101,11],[999,40]] as [$id,$actor]){
            DB::table('resturants')->insert(['id'=>$id,'user_id'=>$actor,'name'=>'Branch '.$id]);
            DB::table('takeaway_orders')->insert(['id'=>$id,'branch'=>'f:'.$id,'note'=>'Order '.$id]);
        }
        DB::table('permissions')->insert(['id'=>1,'name'=>'contract-list','guard_name'=>'admin']);
        DB::table('model_has_permissions')->insert(['permission_id'=>1,'model_type'=>User::class,'model_id'=>30]);
        DB::table('contracts')->insert(['id'=>1,'name'=>'Shared template']);
        DB::table('settings')->insert(['id'=>1,'group'=>'general','name'=>'app_balance','payload'=>'123.45']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
    protected function tearDown(): void {if($this->connection==='mysql'){$this->drop();DB::disconnect('mysql');}parent::tearDown();}
    private function drop(): void {Schema::disableForeignKeyConstraints();foreach(self::TABLES as $table)Schema::dropIfExists($table);Schema::enableForeignKeyConstraints();}
    private function root(int $actor=10): object
    {
        $authority=app(Authority::class);$enrolled=$authority->enroll(['device_id'=>self::ID,'name'=>'Shared snapshot fixture','nonce'=>str_repeat('a',64)],User::withoutGlobalScopes()->findOrFail($actor));
        return $authority->authenticate($enrolled['token']);
    }
    private function selections(object $root): array
    {
        return DB::transaction(function()use($root){$audience=app(Authority::class)->snapshotAudience($root);$queries=app(Data::class)->queries($audience);$out=[];
            foreach(['users','notifications','branch_expense_category_commands','takeaway_orders','contracts','model_has_permissions'] as $table)
                $out[$table]=$queries[$table]->orderBy($table==='model_has_permissions'?'model_id':'id')->get()->map(fn($row)=>(array)$row)->all();
            return [$audience->manifest(),$out];});
    }
    public function test_vendor_preparation_unions_member_rows_notifications_and_permissions_once(): void
    {
        [$manifest,$rows]=$this->selections($this->root());
        $this->assertSame([10,11,30],array_column($manifest['members'],'actor_id'));
        $this->assertSame([10,11,30],array_column($rows['users'],'id'));
        $this->assertSame([10,11,30],array_column($rows['notifications'],'notifiable_id'));
        $this->assertSame([10,11,30],array_column($rows['branch_expense_category_commands'],'actor_id'));
        $this->assertSame([100,101],array_column($rows['takeaway_orders'],'id'));
        $this->assertSame([30],array_column($rows['model_has_permissions'],'model_id'));
        $this->assertSame([1],array_column($rows['contracts'],'id'));
    }
    public function test_disabled_preparer_is_retained_only_as_audit_parent_without_its_old_branch_scope(): void
    {
        $root=$this->root();DB::table('users')->where('id',10)->update(['status'=>'disabled']);
        [$manifest,$rows]=$this->selections($root);
        $this->assertSame([11,30],array_column($manifest['members'],'actor_id'));
        $this->assertSame([11,30],array_column($rows['users'],'id'));
        $this->assertSame([11,30],array_column($rows['notifications'],'notifiable_id'));
        $this->assertSame([101],array_column($rows['takeaway_orders'],'id'));
        $this->assertSame(10,(int)$root->custodian_actor_id);
    }
    public function test_nonfinancial_admin_can_prepare_a_valid_empty_branch_union(): void
    {
        DB::table('users')->whereIn('id',[10,11])->update(['status'=>'disabled']);
        [$manifest,$rows]=$this->selections($this->root(30));
        $this->assertSame([30],array_column($manifest['members'],'actor_id'));$this->assertSame([],$manifest['members'][0]['branches']);
        $this->assertSame([],$rows['takeaway_orders']);$this->assertSame([30],array_column($rows['users'],'id'));
        $this->assertSame([30],array_column($rows['notifications'],'notifiable_id'));
    }
    public function test_owner_membership_does_not_reduce_other_members_notifications(): void
    {
        DB::table('users')->where('id',1)->update(['status'=>'accepted']);
        [$manifest,$rows]=$this->selections($this->root());
        $this->assertSame([1,10,11,30],array_column($manifest['members'],'actor_id'));
        $this->assertSame([1,10,11,30],array_column($rows['notifications'],'notifiable_id'));
        $this->assertContains(20,array_column($rows['users'],'id'));
    }
    public function test_vendor_super_admin_role_does_not_replace_the_original_global_administration_criteria(): void
    {
        DB::table('roles')->insert(['id'=>9,'name'=>'Super Admin','guard_name'=>'admin']);
        DB::table('model_has_roles')->insert(['model_type'=>User::class,'model_id'=>10,'role_id'=>9]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        [$manifest,$rows]=$this->selections($this->root());$members=array_column($manifest['members'],null,'actor_id');
        $this->assertTrue($members[10]['permissions']['super_admin']);
        $this->assertSame('vendor',$members[10]['role']);
        $this->assertSame([10,11,30],array_column($rows['users'],'id'),'A vendor role shortcut suppressed the other current members.');
        $this->assertSame([30],array_column($rows['model_has_permissions'],'model_id'));
        $this->assertSame([1],array_column($rows['contracts'],'id'));
    }
    public function test_audience_batches_actual_member_models_and_refreshes_status_and_password_facts(): void
    {
        $root=$this->root();$armed=false;$hydrations=[];
        DB::listen(function($query)use(&$armed,&$hydrations){
            if(!$armed)return;$sql=strtolower(str_replace(['`','"'],['',''],$query->sql));
            if(preg_match('/^select\s+\*\s+from\s+users\s+where\s+(?:users\.)?id\s*(?:in\s*\(|=)/',$sql))$hydrations[]=$sql;
        });
        $authority=app(Authority::class);$armed=true;
        try{$audience=DB::transaction(fn()=>$authority->snapshotAudience($root));}finally{$armed=false;}
        $this->assertCount(1,$hydrations,'A union audience must hydrate its actual member models in one query.');
        $this->assertStringContainsString('id in (',$hydrations[0]);
        $this->assertSame([10,11,30],array_keys($audience->actors()));
        $old=$audience->actors()[10]->getAuthPassword();
        $rotated=password_hash('rotated-fixture-password',PASSWORD_BCRYPT,['cost'=>4]);
        DB::table('users')->where('id',10)->update(['password'=>$rotated]);
        DB::table('users')->where('id',11)->update(['status'=>'disabled']);
        $fresh=DB::transaction(fn()=>$authority->snapshotAudience($root));
        $this->assertSame([10,30],array_keys($fresh->actors()));
        $this->assertSame($rotated,$fresh->actors()[10]->getAuthPassword());
        $this->assertSame($old,$audience->actors()[10]->getAuthPassword(),'An issued audience must not mutate when a later roster is built.');
        $member=array_column($fresh->manifest()['members'],null,'actor_id')[10];
        $this->assertSame(hash('sha256',$rotated),$member['password_binding']);
        $this->assertGreaterThan($audience->manifest()['revision'],$fresh->manifest()['revision']);
    }
    public function test_mysql_snapshot_keeps_all_member_verifiers_but_no_foreign_hash_or_recaller_secret(): void
    {
        if($this->connection!=='mysql')$this->markTestSkipped('The production SHOW CREATE/FK serializer requires MySQL.');
        DB::table('users')->where('id',1)->update(['status'=>'accepted']);$root=$this->root();
        $source=['format'=>1,'files'=>1,'framework'=>'8.83.29','sha256'=>str_repeat('f',64)];
        app()->instance(Source::class,new class($source) extends Source {public function __construct(private array $value){}public function fingerprint():array{return $this->value;}public function matches($value):bool{return $value===$this->value;}});
        $snapshot=app(Bootstrap::class)->export($root);$this->assertSame(2,$snapshot['format']);$this->assertSame('initial-workspace-data',$snapshot['kind']);
        $this->assertSame(10,$snapshot['actor_id']);$this->assertSame(self::ID,$snapshot['device_id']);
        $users=array_column($snapshot['tables']['users']['rows'],null,'id');
        foreach([1,10,11,30] as $id)$this->assertSame(DB::table('users')->where('id',$id)->value('password'),$users[$id]['password']);
        foreach([20,40] as $id)$this->assertStringStartsWith('!desktop-disabled',$users[$id]['password']);
        foreach($users as $row)$this->assertNull($row['remember_token']);
        $this->assertStringNotContainsString('PRIVATE-',json_encode($snapshot,JSON_THROW_ON_ERROR));
        $this->assertSame('123.45',$snapshot['tables']['settings']['rows'][0]['payload']);
        foreach($snapshot['tables'] as $part)$this->assertSame(hash('sha256',\App\Services\Dashboard\DesktopDashboardBootstrap::json($part['rows'])),$part['sha256']);
        $this->assertSame(false,$snapshot['coverage']['full_dashboard']);
    }
}
