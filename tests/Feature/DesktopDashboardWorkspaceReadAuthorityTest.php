<?php
namespace Tests\Feature;

use App\Models\{GeneralSettings,Resturant,User};
use App\Services\Dashboard\DesktopDashboardWorkspaceAuthority as Authority;
use App\Services\Dashboard\DesktopDashboardWorkspaceLocalSchema as LocalSchema;
use App\Services\Dashboard\DesktopDashboardWorkspaceReadAuthority as Reads;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

/** Real registered routes, binding, permissions, controllers and page Blade on a multi-account union. */
class DesktopDashboardWorkspaceReadAuthorityTest extends TestCase
{
    private const DEVICE='11111111-1111-4111-8111-111111111111';
    private const SNAPSHOT='22222222-2222-4222-8222-222222222222';
    private string $connection='sqlite';
    private $oldNative;
    private array $manifest;
    private array $record;

    protected function setUp(): void
    {
        parent::setUp(); $this->oldNative=getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');
        $this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported test database');
        if($this->connection==='mysql'&&config('database.connections.mysql.database')!=='takeaway_test')throw new \RuntimeException('Requires isolated takeaway_test');
        config(['database.default'=>$this->connection,'cache.default'=>'array','session.driver'=>'array',
            'desktop_dashboard.enabled'=>true,'desktop_dashboard.workspace_enabled'=>true,'desktop_dashboard.local'=>false,
            'desktop_dashboard.device_id'=>self::DEVICE,'app.key'=>'base64:'.base64_encode(str_repeat('s',32))]);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        DB::purge($this->connection); Schema::clearResolvedInstance('db.schema');
        if($this->connection==='mysql')$this->dropFixtures();
        Schema::create('users',function(Blueprint $t){
            $t->id();$t->string('name');$t->string('email')->nullable();$t->string('mobile')->nullable();$t->string('password');
            $t->string('account_type');$t->string('status')->default('accepted');$t->string('app_scope')->nullable();$t->string('remember_token')->nullable();
            $t->unsignedBigInteger('owner_resturant_id')->nullable();$t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->unsignedBigInteger('added_by')->nullable();$t->decimal('balance',15,2)->default(0);$t->string('lat')->nullable();$t->string('lng')->nullable();$t->timestamps();
        });
        Schema::create('resturants',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');
            $t->string('status')->default('opened');$t->string('lat')->nullable();$t->string('lng')->nullable();$t->timestamps();
        });
        Schema::create('go_stores',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id')->unique();$t->string('name');$t->timestamps();});
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('product_id')->nullable();});
        Schema::create('resturant_areas',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->unsignedBigInteger('area_id')->nullable();});
        Schema::create('pending_vendors',function(Blueprint $t){$t->id();$t->string('full_name');$t->string('mobile');$t->string('email');$t->string('type');$t->string('status');$t->timestamps();});
        Schema::create('media',function(Blueprint $t){$t->id();$t->string('model_type');$t->unsignedBigInteger('model_id');$t->string('collection_name');$t->unsignedInteger('order_column')->nullable();});
        Schema::create('advertisings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('name')->nullable();$t->timestamps();});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->unsignedBigInteger('from_user')->nullable();$t->unsignedBigInteger('to_user')->nullable();$t->string('type');$t->string('payment');$t->string('status');$t->decimal('amount',15,2);$t->timestamps();});
        Schema::create('crm_customers',function(Blueprint $t){$t->id();$t->string('name');$t->string('phone_key');$t->boolean('service_consent')->default(false);$t->boolean('marketing_consent')->default(false);$t->timestamp('opted_out_at')->nullable();$t->timestamps();});
        Schema::create('crm_customer_sources',function(Blueprint $t){$t->id();$t->unsignedBigInteger('customer_id');$t->string('branch');$t->string('channel');});
        foreach(['2022_08_05_174522_create_permission_tables.php'=>'CreatePermissionTables',
            '2026_10_08_130000_create_desktop_dashboard_journal.php'=>'CreateDesktopDashboardJournal',
            '2026_10_08_160000_create_desktop_dashboard_local_state.php'=>'CreateDesktopDashboardLocalState',
            '2026_10_27_030000_create_desktop_dashboard_workspaces.php'=>'CreateDesktopDashboardWorkspaces']as$file=>$class){require_once database_path('migrations/'.$file);(new $class)->up();}
        foreach([[1,'admin',null],[2,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[50,'vendor',null]]as[$id,$type,$owner])DB::table('users')->insert([
            'id'=>$id,'name'=>$id===1?'OWNER_PRIVATE_SENTINEL':($id===11?'FOREIGN_ACCOUNT_SENTINEL':'Actor '.$id),
            'email'=>$id===1?'owner-secret@example.test':'actor'.$id.'@example.test','mobile'=>(string)(1000000000+$id),
            'password'=>password_hash('fixture-'.$id,PASSWORD_BCRYPT,['cost'=>4]),'account_type'=>$type,'owner_resturant_id'=>$owner,
            'app_scope'=>$id===50?'go_partner':'fasakhansta','status'=>'accepted','balance'=>$id===1?'987654.32':'0.00',
            'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('resturants')->insert([
            ['id'=>100,'user_id'=>10,'parent_id'=>null,'name'=>'OWN_BRANCH_SENTINEL','lat'=>'30.1','lng'=>'31.1'],
            ['id'=>101,'user_id'=>11,'parent_id'=>null,'name'=>'FOREIGN_BRANCH_SENTINEL','lat'=>'77.777','lng'=>'88.888'],
            ['id'=>102,'user_id'=>11,'parent_id'=>100,'name'=>'CHILD_BRANCH_SENTINEL','lat'=>'30.2','lng'=>'31.2'],
        ]);
        DB::table('go_stores')->insert(['id'=>1,'user_id'=>50,'name'=>'FOREIGN_GO_SENTINEL']);
        DB::table('resturant_products')->insert([['id'=>1000,'resturant_id'=>100],['id'=>1001,'resturant_id'=>101]]);
        DB::table('pending_vendors')->insert(['id'=>1,'full_name'=>'FOREIGN_APPLICATION_SENTINEL','mobile'=>'PRIVATE_MOBILE','email'=>'application-secret@example.test','type'=>'vendor','status'=>'pending']);
        DB::table('advertisings')->insert([['id'=>1,'resturant_id'=>100,'name'=>'OWN_AD_SENTINEL'],['id'=>2,'resturant_id'=>101,'name'=>'FOREIGN_AD_SENTINEL']]);
        DB::table('wallets')->insert(['id'=>1,'from_user'=>null,'to_user'=>11,'type'=>'transfer','payment'=>'wallet','status'=>'completed','amount'=>'876543.21','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('crm_customers')->insert([
            ['id'=>1,'name'=>'GLOBAL_DIRECTORY_SENTINEL','phone_key'=>'01000000001'],
            ['id'=>2,'name'=>'OWN_DIRECTORY_SENTINEL','phone_key'=>'01000000002'],
        ]);
        DB::table('crm_customer_sources')->insert([
            ['customer_id'=>1,'branch'=>'f:101','channel'=>'delivery'],['customer_id'=>2,'branch'=>'f:100','channel'=>'delivery'],
        ]);
        $names=['order-list','vendor-list','vendor-edit','resturant-list','resturant-edit','wallet-list','report-list',
            'pending_vendor-list','pending_vendor-edit','delegate-list','contract-list','contact-list','slidear-list','slidear-edit','product-list','role-list'];
        foreach($names as$i=>$name){
            DB::table('permissions')->insert(['id'=>$i+1,'name'=>$name,'guard_name'=>'admin']);
            foreach([2,4,10,11,12]as$id)DB::table('model_has_permissions')->insert(['model_type'=>User::class,'model_id'=>$id,'permission_id'=>$i+1]);
        }
        DB::table('roles')->insert([
            ['id'=>70,'name'=>'GLOBAL_ROLE_CATALOG_ONE','guard_name'=>'admin','created_at'=>now('UTC'),'updated_at'=>now('UTC')],
            ['id'=>71,'name'=>'GLOBAL_ROLE_CATALOG_TWO','guard_name'=>'admin','created_at'=>now('UTC'),'updated_at'=>now('UTC')],
        ]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $enrolled=app(Authority::class)->enroll(['device_id'=>self::DEVICE,'name'=>'Shared PC','nonce'=>str_repeat('a',64)],$this->actor(10));
        $root=app(Authority::class)->authenticate($enrolled['token']);$this->manifest=$enrolled['manifest'];
        config(['desktop_dashboard.local'=>true,'app.key'=>'base64:'.base64_encode(str_repeat('l',32))]);
        app()->forgetInstance('encrypter');putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL=true');LocalSchema::ensure();
        $snapshot=['workspace_id'=>self::DEVICE,'custodian_actor_id'=>10,'snapshot_id'=>self::SNAPSHOT,'schema_hash'=>str_repeat('b',64),
            'branches'=>['f:100','f:101','f:102','gs:50'],'coverage'=>['workspace_protocol'=>2],
            'source'=>['format'=>1,'files'=>10,'sha256'=>str_repeat('c',64),'framework'=>'fixture']];
        $this->record=['format'=>2,'kind'=>'desktop-dashboard-workspace-local-authority','workspace_id'=>self::DEVICE,
            'root_fingerprint'=>$this->manifest['root_fingerprint'],'root'=>['id'=>$root->id,'custodian_actor_id'=>10,'name'=>'Shared workspace',
                'enabled'=>true,'enrollment_hash'=>$root->enrollment_hash,'token_hash'=>$root->token_hash,'enrolled_at'=>$root->enrolled_at],
            'manifest_revision'=>$this->manifest['revision'],'roster_sha256'=>$this->manifest['roster_sha256'],
            'manifest_sha256'=>hash('sha256',Authority::canonical($this->manifest)),'manifest_signature'=>$this->manifest['signature'],
            'member_count'=>count($this->manifest['members']),'snapshot'=>$snapshot,'database'=>DB::connection()->getDatabaseName(),'expected_binding_digest'=>null];
        DB::table('desktop_dashboard_local_state')->insert(['device_id'=>self::DEVICE,'actor_id'=>10,'snapshot_id'=>self::SNAPSHOT,
            'schema_hash'=>str_repeat('b',64),'branches'=>'["f:100","f:101","f:102","gs:50"]','coverage'=>'{"workspace_protocol":2}',
            'state'=>'ready','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('desktop_dashboard_workspace_local_authority')->insert(['workspace_id'=>self::DEVICE,
            'record_json'=>Authority::canonical($this->record),'manifest_json'=>Authority::canonical($this->manifest),
            'local_mac'=>$this->mac('record',$this->record),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        foreach($this->manifest['members']as$member)DB::table('desktop_dashboard_workspace_local_members')->insert([
            'workspace_id'=>self::DEVICE,'actor_id'=>$member['actor_id'],'manifest_revision'=>$this->manifest['revision'],
            'member_json'=>Authority::canonical($member),'local_mac'=>$this->mac('member',[
                'record_sha256'=>hash('sha256',Authority::canonical($this->record)),'member'=>$member]),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        // SQLite has no SET TRANSACTION READ ONLY. All other actual HTTP middleware remains active.
        if($this->connection==='sqlite')$this->withoutMiddleware(\App\Http\Middleware\DesktopDashboardJournal::class);
        app('view')->getFinder()->prependLocation(base_path('tests/fixtures/workspace-read-views'));
        $this->app->instance(GeneralSettings::class,(new \ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor());
    }

    protected function tearDown(): void
    {
        putenv($this->oldNative===false?'DESKTOP_DASHBOARD_WORKSPACE_LOCAL':'DESKTOP_DASHBOARD_WORKSPACE_LOCAL='.$this->oldNative);
        if($this->connection==='mysql'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();
    }
    private function dropFixtures(): void
    {
        Schema::disableForeignKeyConstraints();foreach(['desktop_dashboard_workspace_command_authorities','desktop_dashboard_workspace_local_authority_history',
            'desktop_dashboard_workspace_local_members','desktop_dashboard_workspace_local_authority','desktop_dashboard_refreshes','desktop_dashboard_local_state',
            'desktop_dashboard_workspace_members','desktop_dashboard_workspace_manifests','desktop_dashboard_workspaces','desktop_dashboard_entities','desktop_dashboard_commands','desktop_dashboard_devices',
            'role_has_permissions','model_has_roles','model_has_permissions','roles','permissions','crm_customer_sources','crm_customers','wallets','advertisings','media','pending_vendors','resturant_areas','resturant_products','go_stores','resturants','users']as$table)Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }
    private function actor(int$id):User{return User::withoutGlobalScopes()->findOrFail($id);}
    private function mac(string$domain,array$value):string{return hash_hmac('sha256',"desktop-workspace-local-v2\0".$domain."\0".Authority::canonical($value),app('encrypter')->getKey());}
    private function signIn(int$id):void{$this->actingAs($this->actor($id),'admin');}
    private function forbidden(string$url,array$headers=[]):void
    {
        $response=$this->withHeaders($headers)->get($url);$this->assertContains($response->status(),[403,404]);
        foreach(['OWNER_PRIVATE_SENTINEL','owner-secret@example.test','987654.32','FOREIGN_APPLICATION_SENTINEL','876543.21']as$secret)$response->assertDontSee($secret,false);
    }

    public function test_foreign_user_binding_and_type_spoofing_are_denied_for_html_json_and_spa():void
    {
        $this->signIn(10);
        foreach([[],['Accept'=>'application/json'],['X-Dashboard-SPA'=>'1']]as$headers){
            foreach(['vendor','admin','delegate']as$type){
                $this->forbidden('/admin/users/1?account_type='.$type,$headers);
                $this->forbidden('/admin/users/1/edit?account_type='.$type,$headers);
            }
        }
        $this->assertSame(0,DB::table('desktop_dashboard_commands')->count());
    }
    public function test_user_search_is_grouped_and_keeps_self_scope_on_the_union():void
    {
        $this->signIn(10);
        foreach([[],['Accept'=>'application/json'],['X-Dashboard-SPA'=>'1']]as$headers){
            $this->withHeaders($headers)->get('/admin/users?account_type=vendor')->assertOk()->assertSee('Actor 10')->assertDontSee('FOREIGN_ACCOUNT_SENTINEL');
            $this->withHeaders($headers)->get('/admin/users?account_type=vendor&search=owner-secret')->assertOk()->assertDontSee('OWNER_PRIVATE_SENTINEL');
            $this->withHeaders($headers)->get('/admin/users?account_type=vendor&search=actor11')->assertOk()->assertDontSee('FOREIGN_ACCOUNT_SENTINEL');
        }
    }
    public function test_private_platform_wallet_reports_maps_and_application_reads_do_not_follow_custody_or_copied_permissions():void
    {
        $this->signIn(10);
        foreach(['/admin/admin_wallet','/admin/wallets','/admin/wallet/withdraw','/admin/wallet/transactions',
            '/admin/reports/customers','/admin/reports/delegates','/admin/reports/vendors','/admin/delegate_map',
            '/admin/pending_vendors/1','/admin/pending_vendors/1/edit','/admin/pending_vendors/1/addVendor',
            '/admin/contracts','/admin/contacts']as$url)$this->forbidden($url,['X-Dashboard-SPA'=>'1']);
        $this->assertSame(0,DB::table('desktop_dashboard_commands')->count());
    }
    public function test_restaurant_lists_bindings_and_maps_intersect_branch_admin_vendor_and_parent_child_grants():void
    {
        foreach([10,4]as$id){
            $this->signIn($id);
            foreach([[],['Accept'=>'application/json'],['X-Dashboard-SPA'=>'1']]as$headers){
                $this->withHeaders($headers)->get('/admin/resturants')->assertOk()->assertSee('OWN_BRANCH_SENTINEL')->assertDontSee('FOREIGN_BRANCH_SENTINEL')->assertDontSee('CHILD_BRANCH_SENTINEL');
                $this->forbidden('/admin/resturants/101',$headers);$this->forbidden('/admin/resturants/101/edit',$headers);
                $this->withHeaders($headers)->get('/admin/resturants?resturant_id=101')->assertOk()->assertDontSee('FOREIGN_BRANCH_SENTINEL');
                $this->withHeaders($headers)->get('/admin/resturant_map')->assertOk()->assertDontSee('77.777')->assertDontSee('FOREIGN_BRANCH_SENTINEL');
            }
        }
        $this->signIn(12);
        $this->get('/admin/resturants')->assertOk()->assertSee('OWN_BRANCH_SENTINEL')->assertSee('CHILD_BRANCH_SENTINEL')->assertDontSee('FOREIGN_BRANCH_SENTINEL');
        $this->signIn(1);$this->assertSame([100,101,102],Resturant::query()->orderBy('id')->pluck('id')->all());
    }
    public function test_foreign_restaurant_product_and_go_store_targets_are_not_public_catalog_reads():void
    {
        $this->signIn(4);
        $this->post('/admin/fetch-feature',['id'=>1001,'product_id'=>1],['X-Requested-With'=>'XMLHttpRequest'])->assertNotFound();
        $this->forbidden('/admin/go-stores/50');$this->forbidden('/admin/go-stores/50/products/create');
        $this->assertSame([100],app(Reads::class)->restaurantIds());
        $this->assertSame([],app(Reads::class)->storeIds());
    }
    public function test_central_wallet_view_keeps_real_rows_and_controller_owned_options():void
    {
        $this->signIn(2);
        $this->get('/admin/wallets')->assertOk()->assertSee('876543.21')->assertSee('FOREIGN_ACCOUNT_SENTINEL');
        $this->assertSame(0,DB::table('desktop_dashboard_commands')->count());
    }
    public function test_self_wallet_json_uses_the_dashboard_actor_and_returns_no_login_token_or_foreign_transfer():void
    {
        $this->signIn(10);
        DB::table('wallets')->insert(['id'=>2,'from_user'=>10,'to_user'=>null,'type'=>'withdraw','payment'=>'wallet',
            'status'=>'completed','amount'=>'123.45','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        $response=$this->getJson('/admin/get/charging/wallet')->assertOk()->assertJsonPath('data.profile.id',10);
        $this->assertSame([2],array_column($response->json('data.wallet'),'id'));
        $response->assertDontSee('876543.21')->assertDontSee('OWNER_PRIVATE_SENTINEL');
        foreach(['token','access_token','password','remember_token']as$key)$this->assertArrayNotHasKey($key,$response->json('data.profile'));
    }
    public function test_public_role_catalog_and_approved_shared_customer_directory_remain_available():void
    {
        $this->signIn(10);
        $this->get('/admin/roles')->assertOk()->assertSee('GLOBAL_ROLE_CATALOG_ONE')->assertSee('GLOBAL_ROLE_CATALOG_TWO');
        $response=$this->getJson('/admin/customers/data?branch=f:100')->assertOk();
        $items=collect($response->json('items'))->keyBy('id');
        $this->assertSame('GLOBAL_DIRECTORY_SENTINEL',$items[1]['name']);
        $this->assertFalse($items[1]['can_edit_profile']);
        $this->assertTrue($items[2]['can_edit_profile']);
        $response->assertDontSee('OWNER_PRIVATE_SENTINEL')->assertDontSee('owner-secret@example.test');
    }
    public function test_request_fields_cannot_select_protocol_two_when_the_native_flag_is_absent():void
    {
        putenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL');$this->signIn(10);
        $request=\Illuminate\Http\Request::create('/admin/users/1?workspace_protocol=2&actor_id=1','GET');
        $request->headers->set('X-Workspace-Actor','1');$request->headers->set('X-Dashboard-SPA','1');
        $this->assertFalse(app(Reads::class)->selected());
        $this->assertNull(app(Reads::class)->scope());
        $this->assertSame('legacy',app(Reads::class)->run($request,fn()=>'legacy'));
    }
    public function test_retired_remembered_actor_can_logout_without_rotating_the_business_token_or_retaining_session():void
    {
        DB::table('users')->where('id',11)->update(['remember_token'=>'EXISTING_BUSINESS_REMEMBER_TOKEN']);
        $guard=auth('admin');$guard->login($this->actor(11),true);
        $recaller=$guard->getRecallerName();$cookie=app('cookie')->queued($recaller);
        $this->assertNotNull($cookie);
        $loginKey=$guard->getName();
        $this->withCookie($recaller,$cookie->getValue())->withSession(['workspace_private'=>'must be removed','_token'=>'previous-csrf']);
        DB::table('users')->where('id',11)->update(['status'=>'disabled']);
        $before=DB::table('users')->where('id',11)->first();
        $response=$this->get('/admin/adminLogout')->assertRedirect('admin/login');
        $response->assertCookieExpired($recaller)->assertSessionMissing($loginKey)->assertSessionMissing('workspace_private');
        $this->assertGuest('admin');
        $this->assertNotSame('previous-csrf',app('session')->token());
        $this->assertSame((array)$before,(array)DB::table('users')->where('id',11)->first());
        $this->assertSame('EXISTING_BUSINESS_REMEMBER_TOKEN',DB::table('users')->where('id',11)->value('remember_token'));
        $this->assertSame(0,DB::table('desktop_dashboard_commands')->count());
    }
}
