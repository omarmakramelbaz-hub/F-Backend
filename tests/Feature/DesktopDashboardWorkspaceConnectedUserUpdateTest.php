<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\{DesktopDashboardRemoteAttempts,DesktopDashboardWorkspaceAuthority as Authority,
    DesktopDashboardWorkspaceExecutionContext as Context,DesktopDashboardWorkspaceRemoteAttempts as Attempts};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Actual original form/controller/repository inside the existing server result transaction. */
class DesktopDashboardWorkspaceConnectedUserUpdateTest extends TestCase
{
    private const DEVICE='11111111-1111-4111-8111-111111111111';
    private const ATTEMPT='22222222-2222-4222-8222-222222222222';
    private const COMMAND='33333333-3333-4333-8333-333333333333';
    private string $connection;
    private object $root;
    private array $manifest;
    private array $reservation;
    private int $callbacks=0;

    protected function setUp(): void
    {
        parent::setUp();$this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if($this->connection!=='mysql')$this->markTestSkipped('Original remote outcome protocol requires real InnoDB tables.');
        if(config('database.connections.mysql.database')!=='takeaway_test')throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default'=>'mysql','cache.default'=>'array','session.driver'=>'array','desktop_dashboard.enabled'=>true,
            'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false,'app.key'=>'base64:'.base64_encode(str_repeat('u',32))]);
        DB::purge('mysql');Schema::clearResolvedInstance('db.schema');$this->dropFixtures();
        Schema::create('users',function(Blueprint $t){$t->engine='InnoDB';$t->id();$t->string('name');$t->string('email')->nullable();
            $t->string('mobile')->nullable();$t->string('password');$t->string('remember_token')->nullable();$t->text('roles_name')->nullable();$t->string('account_type');
            $t->string('app_scope')->nullable();$t->string('status')->default('accepted');$t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable();$t->timestamps();});
        Schema::create('resturants',function(Blueprint $t){$t->engine='InnoDB';$t->id();$t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->timestamps();});
        Schema::create('pending_vendors',function(Blueprint $t){$t->engine='InnoDB';$t->id();$t->unsignedBigInteger('added_by')->nullable();
            foreach(['full_name','type','status','mobile','email','national_id','commercial_registration_no','driving_license_no','tax_no',
                'owner_name','branches_no','location','vodafone_cash_mobile'] as $name)$t->string($name)->nullable();$t->timestamps();});
        foreach(['categories','products','product_features','orders','user_address'] as $table)
            Schema::create($table,function(Blueprint $t){$t->engine='InnoDB';$t->id();});
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');(new \CreatePermissionTables)->up();
        require_once database_path('migrations/2026_10_08_130000_create_desktop_dashboard_journal.php');(new \CreateDesktopDashboardJournal)->up();
        require_once database_path('migrations/2026_10_08_210000_create_desktop_dashboard_remote_attempts.php');(new \CreateDesktopDashboardRemoteAttempts)->up();
        require_once database_path('migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php');(new \CreateDesktopDashboardWorkspaces)->up();
        foreach([10,11,30]as$id)DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'email'=>'actor'.$id.'@example.test',
            'mobile'=>(string)(1000000000+$id),'password'=>password_hash('fixture-'.$id,PASSWORD_BCRYPT,['cost'=>4]),
            'account_type'=>$id===30?'admin':'vendor','app_scope'=>'fasakhansta','status'=>'accepted','created_at'=>now(),'updated_at'=>now()]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'A'],['id'=>101,'user_id'=>11,'name'=>'B']]);
        DB::table('permissions')->insert(['id'=>1,'name'=>'vendor-edit','guard_name'=>'admin']);
        DB::table('roles')->insert([['id'=>1,'name'=>'Original vendor','guard_name'=>'admin'],['id'=>2,'name'=>'Replacement vendor','guard_name'=>'admin'],
            ['id'=>3,'name'=>'Account editor','guard_name'=>'admin']]);
        DB::table('model_has_roles')->insert([['model_type'=>User::class,'model_id'=>11,'role_id'=>1],['model_type'=>User::class,'model_id'=>30,'role_id'=>3]]);
        DB::table('role_has_permissions')->insert(['role_id'=>3,'permission_id'=>1]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $enrolled=app(Authority::class)->enroll(['device_id'=>self::DEVICE,'name'=>'Shared device','nonce'=>str_repeat('a',64)],$this->actor(10));
        $this->root=app(Authority::class)->authenticate($enrolled['token']);$this->manifest=$enrolled['manifest'];
        auth('admin')->setUser($this->actor(30));
    }
    protected function tearDown(): void
    {
        if(($this->connection??null)==='mysql'){$this->dropFixtures();DB::disconnect('mysql');}
        parent::tearDown();
    }
    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach(['desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests','desktop_dashboard_workspaces',
            'desktop_dashboard_remote_attempts','desktop_dashboard_entities','desktop_dashboard_commands','desktop_dashboard_devices',
            'model_has_permissions','model_has_roles','role_has_permissions','permissions','roles','pending_vendors','categories','products',
            'product_features','orders','user_address','resturants','users'] as $table)Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }
    private function actor(int $id): User {return User::withoutGlobalScopes()->whereKey($id)->firstOrFail();}
    private function scope(int $id=30)
    {
        $this->manifest=app(Authority::class)->manifest($this->root);
        $member=collect($this->manifest['members'])->firstWhere('actor_id',$id);
        return app(Authority::class)->scope($this->root,$id,$member['grant'],$this->manifest['revision']);
    }
    private function values(array $changes=[]): array
    {
        return array_replace(['name'=>'Updated actor','account_type'=>'vendor','email'=>'actor11@example.test',
            'mobile'=>'1000000011','roles_name'=>['Original vendor'],'password'=>'','_desktop_command'=>self::COMMAND],$changes);
    }
    private function request(array $values): Request
    {
        $request=Request::create('/admin/users/11','PUT',$values,[],[],['HTTP_REFERER'=>'http://localhost/admin/users/11/edit?account_type=vendor']);
        $request->setLaravelSession(app('session')->driver());app()->instance('request',$request);
        $route=app('router')->getRoutes()->match($request);$request->setRouteResolver(fn()=>$route);
        return $request;
    }
    private function execute(array $values,bool $rollback=false,int $actor=30)
    {
        auth('admin')->setUser($this->actor($actor));$scope=$this->scope($actor);$remote=app(Attempts::class);
        if(!isset($this->reservation))$this->reservation=$remote->decideWorkspace($scope,
            ['id'=>self::ATTEMPT,'method'=>'PUT','path'=>'/admin/users/11'],false);
        $request=$this->request($values);$request->headers->set('X-Fasakhansta-Workspace-Attempt',self::ATTEMPT);
        $request->headers->set('X-Fasakhansta-Workspace-Capability',$this->reservation['capability']);
        return Context::run($scope,fn()=>$remote->handle($request,function($request)use($rollback){
            $this->callbacks++;$router=app('router');$route=$request->route();$router->substituteBindings($route);$router->substituteImplicitBindings($route);
            $response=$router->prepareResponse($request,$route->run());
            if($rollback)throw new \RuntimeException('Synthetic failure after original business write');return $response;
        }));
    }
    private function saved(): array
    {
        $row=DB::table('desktop_dashboard_remote_attempts')->where('id',self::ATTEMPT)->first();
        return json_decode(Crypt::decryptString($row->response_cipher),true,512,JSON_THROW_ON_ERROR);
    }

    public function test_connected_password_roles_and_email_use_original_controller_without_storing_credentials(): void
    {
        $password='Changed-secret-73';$values=$this->values(['password'=>$password,'email'=>'new-alias@example.test','roles_name'=>['Replacement vendor']]);
        $response=$this->execute($values);$this->assertSame(302,$response->getStatusCode());$user=$this->actor(11);
        $this->assertTrue(password_verify($password,$user->password));$this->assertSame('new-alias@example.test',$user->email);
        $this->assertSame('["Replacement vendor"]',$user->roles_name);
        $this->assertSame([2],DB::table('model_has_roles')->where('model_id',11)->pluck('role_id')->map(fn($id)=>(int)$id)->all());
        $this->assertSame(1,DB::table('pending_vendors')->count());$saved=$this->saved();
        $this->assertSame(['format'=>2,'kind'=>'original-connected-user-update','target'=>11,'account_type'=>'vendor'],$saved['workspace_user_update']);
        $this->assertArrayNotHasKey('user_account_payload',$saved);$this->assertStringNotContainsString($password,json_encode($saved));
        $this->assertSame(0,DB::table('desktop_dashboard_commands')->count());
        $this->assertNotSame(collect($this->manifest['members'])->firstWhere('actor_id',11)['password_binding'],hash('sha256',$user->password));
        $fresh=app(Authority::class)->manifest($this->root);$this->assertGreaterThan($this->manifest['revision'],$fresh['revision']);
        $this->assertSame(hash('sha256',$user->password),collect($fresh['members'])->firstWhere('actor_id',11)['password_binding']);
    }
    public function test_role_email_only_lost_reply_stays_generic_after_the_live_values_now_match(): void
    {
        $values=$this->values(['email'=>'replacement@example.test','roles_name'=>['Replacement vendor']]);$this->execute($values);
        $cipher=DB::table('desktop_dashboard_remote_attempts')->where('id',self::ATTEMPT)->value('response_cipher');
        $this->assertSame(302,$this->execute($values)->getStatusCode());$this->assertSame(1,$this->callbacks);
        $this->assertSame(1,DB::table('pending_vendors')->count());
        $this->assertSame($cipher,DB::table('desktop_dashboard_remote_attempts')->where('id',self::ATTEMPT)->value('response_cipher'));
        $settled=app(Attempts::class)->decideWorkspace($this->scope(),['id'=>self::ATTEMPT,'method'=>'PUT','path'=>'/admin/users/11'],false);
        $this->assertSame('committed',$settled['status']);
    }
    public function test_original_validation_and_post_write_failure_roll_back_credentials_memberships_and_result(): void
    {
        $before=$this->actor(11)->password;
        try{$this->execute($this->values(['password'=>'bad']));$this->fail('Original password validation was skipped.');}
        catch(ValidationException $error){$this->assertArrayHasKey('password',$error->errors());}
        $this->assertSame($before,$this->actor(11)->password);$this->assertSame(0,DB::table('pending_vendors')->count());
        try{$this->execute($this->values(['password'=>'Allowed-secret','roles_name'=>['Replacement vendor']]),true);$this->fail('Post-write error escaped transaction.');}
        catch(\RuntimeException $error){$this->assertSame('Synthetic failure after original business write',$error->getMessage());}
        $this->assertSame($before,$this->actor(11)->password);$this->assertSame([1],DB::table('model_has_roles')->where('model_id',11)->pluck('role_id')->map(fn($id)=>(int)$id)->all());
        $this->assertSame(0,DB::table('pending_vendors')->count());$this->assertSame('ready',DB::table('desktop_dashboard_remote_attempts')->where('id',self::ATTEMPT)->value('status'));
        $this->assertArrayNotHasKey('workspace_user_update',$this->saved());
    }
    public function test_target_permission_cannot_be_selected_by_forging_account_type_or_another_actor(): void
    {
        try{$this->execute($this->values(['password'=>'Allowed-secret','account_type'=>'admin']));$this->fail('Forged target type accepted.');}
        catch(HttpException $error){$this->assertSame(409,$error->getStatusCode());}
        $this->assertSame(0,$this->callbacks);
        app(Attempts::class)->decideWorkspace($this->scope(),['id'=>self::ATTEMPT,'method'=>'PUT','path'=>'/admin/users/11'],true);
        unset($this->reservation);DB::table('desktop_dashboard_remote_attempts')->where('id',self::ATTEMPT)->delete();
        try{$this->execute($this->values(['password'=>'Allowed-secret']),false,10);$this->fail('Actor without target edit permission accepted.');}
        catch(HttpException $error){$this->assertSame(403,$error->getStatusCode());}
        $this->assertSame(0,$this->callbacks);$this->assertSame(0,DB::table('pending_vendors')->count());
    }
    public function test_name_only_keeps_adapter_and_legacy_hook_never_opens_original_write_mode(): void
    {
        $scope=$this->scope();$request=$this->request($this->values());$row=(object)['status'=>'ready'];
        $method=new \ReflectionMethod(Attempts::class,'originalUserUpdate');$method->setAccessible(true);
        $this->assertNull(Context::run($scope,fn()=>$method->invoke(app(Attempts::class),$request,$this->actor(30),$scope,$row)));
        $legacy=new \ReflectionMethod(DesktopDashboardRemoteAttempts::class,'originalUserUpdate');$legacy->setAccessible(true);
        $this->assertNull($legacy->invoke(app(DesktopDashboardRemoteAttempts::class),$this->request($this->values(['password'=>'Allowed-secret'])),
            $this->actor(30),(object)[],$row));
        $this->assertSame(0,DB::table('pending_vendors')->count());
    }
    public function test_original_role_update_also_works_when_the_legacy_display_column_is_absent(): void
    {
        Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('roles_name'));
        $this->assertSame(302,$this->execute($this->values(['roles_name'=>['Replacement vendor']]))->getStatusCode());
        $this->assertSame([2],DB::table('model_has_roles')->where('model_id',11)->pluck('role_id')->map(fn($id)=>(int)$id)->all());
        $this->assertSame('committed',DB::table('desktop_dashboard_remote_attempts')->where('id',self::ATTEMPT)->value('status'));
    }
    public function test_valid_password_matching_a_public_route_literal_is_not_mistaken_for_disclosure(): void
    {
        $response=$this->execute($this->values(['password'=>'vendor']));
        $this->assertSame(302,$response->getStatusCode());$this->assertTrue(password_verify('vendor',$this->actor(11)->password));
        $this->assertStringEndsWith('/admin/users/11/edit?account_type=vendor',$this->saved()['location']);
        $this->assertArrayNotHasKey('user_account_payload',$this->saved());
    }
}
