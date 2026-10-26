<?php
namespace Tests\Feature;

use App\Models\{GeneralSettings,User};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\{DB,Http,Schema};
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/DashboardHomeOverviewTest.php';

/** Real original HTTP controllers, authorization and Blade, on disposable data.
 * Run with --filter test_navigation_ to select only these focused regressions.
 */
class DashboardNavigationFragmentTest extends DashboardHomeOverviewTest
{
    private ?array $observedQueries=null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['desktop_dashboard.local'=>false,'desktop_dashboard.enabled'=>true,
            'session.driver'=>'array','services.fcm.project_id'=>'fasakhaninjatest']);
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up();
        require_once database_path('migrations/2022_08_17_095029_create_notifications_table.php');
        (new \CreateNotificationsTable())->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission=Permission::create(['name'=>'order-list','guard_name'=>'admin']);
        DB::table('model_has_permissions')->insert(['permission_id'=>$permission->id,
            'model_type'=>User::class,'model_id'=>30]);
        foreach([1,30,10] as $actor){
            for($index=1;$index<=3;$index++)DB::table('notifications')->insert([
                'id'=>sprintf('00000000-0000-4000-8000-%012d',$actor*10+$index),
                'type'=>'SyntheticNavigationNotification','notifiable_type'=>User::class,
                'notifiable_id'=>$actor,'data'=>json_encode(['title'=>'Synthetic notice','text'=>'Disposable test data']),
                'read_at'=>null,'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('branch_expenses')->insert(['branch'=>'f:100','actor_id'=>10,
            'occurred_on'=>'2026-10-05','category'=>'purchases','description'=>'Synthetic pending expense',
            'amount_cents'=>1000,'payment_method'=>'cash','status'=>'pending']);
        // Supply only the unrelated site's fixed display settings. Original
        // auth, role/scope services, controllers, queries and views stay real.
        $reflection=new \ReflectionClass(GeneralSettings::class);
        $settings=$reflection->newInstanceWithoutConstructor();
        foreach($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property){
            if($property->isStatic()||$property->getDeclaringClass()->getName()!==GeneralSettings::class)continue;
            $property->setValue($settings,$property->getType()?->getName()==='bool'?false:'');
        }
        $settings->site_name='Synthetic GO ERP';$settings->site_active=true;
        $settings->logo='synthetic-logo.png';$settings->favicon='synthetic-icon.png';
        $this->app->instance(GeneralSettings::class,$settings);
        Http::fake();
        DB::listen(function(QueryExecuted $query){
            if($this->observedQueries!==null)$this->observedQueries[]=[
                'sql'=>strtolower(preg_replace('/\s+/',' ',trim($query->sql))),
                'bindings'=>$query->bindings];
        });
    }

    private function navigationActor(int $id): User
    {
        return User::withoutGlobalScopes()->findOrFail($id);
    }

    private function document(string $html): \DOMXPath
    {
        $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{$document->loadHTML($html);libxml_clear_errors();}
        finally{libxml_use_internal_errors($previous);}
        return new \DOMXPath($document);
    }

    private function boot(\DOMXPath $xpath): array
    {
        $nodes=$xpath->query('//script[@id="home-overview-bootstrap" or @id="branch-home-bootstrap"]');
        $this->assertSame(1,$nodes->length);
        return json_decode($nodes->item(0)->textContent,true,512,JSON_THROW_ON_ERROR);
    }

    private function nodeValues(\DOMXPath $xpath,string $query,?string $attribute=null): array
    {
        $values=[];
        foreach($xpath->query($query) as $node)$values[]=$attribute===null?trim($node->textContent):$node->getAttribute($attribute);
        return $values;
    }

    private function measuredHome(array $headers=[]): array
    {
        $this->observedQueries=[];
        try{$response=$this->get('/admin/dashboard?branch=f:100',$headers)->assertOk()
            ->assertHeader('Cache-Control','no-store, private');}
        finally{$queries=$this->observedQueries;$this->observedQueries=null;}
        $sqls=array_column($queries,'sql');
        $metrics=['queries'=>count($sqls),'bytes'=>strlen($response->getContent()),
            'notification_queries'=>count(array_filter($sqls,fn($sql)=>preg_match('/\bfrom ["`]?notifications["`]?\b/',$sql))),
            'pending_expense_queries'=>count(array_filter($queries,fn($entry)=>str_contains($entry['sql'],'branch_expenses')
                &&in_array('pending',$entry['bindings'],true))),
            'writes'=>count(array_filter($sqls,fn($sql)=>preg_match('/^(?:insert|update|delete|replace|create|alter|drop)\b/',$sql)))];
        return [$response,$this->document($response->getContent()),$metrics];
    }

    public function test_navigation_fragment_preserves_original_actor_data_css_scripts_and_private_response_for_all_roles(): void
    {
        $measurements=[];
        foreach([1=>'owner',30=>'admin',10=>'branch'] as $id=>$role){
            $actor=$this->navigationActor($id);$this->actingAs($actor,'admin');
            $this->get('/admin/dashboard?branch=f:100')->assertOk(); // Warm fixed framework/permission metadata equally.
            [$full,$fullDom,$fullCost]=$this->measuredHome();
            [$fragment,$fragmentDom,$fragmentCost]=$this->measuredHome(['X-Dashboard-SPA'=>'1',
                'X-Dashboard-Actor'=>'999:admin']);
            foreach([$fullDom,$fragmentDom] as $dom){
                $this->assertSame([$id.':'.$actor->account_type],$this->nodeValues($dom,'//body','data-dashboard-actor'));
                $this->assertSame(1,$dom->query('//*[@data-dashboard-page]')->length);
                $this->assertSame(1,$dom->query('//meta[@name="csrf-token"]')->length);
                $this->assertNotSame('',$dom->query('//meta[@name="csrf-token"]')->item(0)->getAttribute('content'));
            }
            $this->assertSame(1,$fullDom->query('//*[@id="dashboard-sidebar"]')->length);
            $this->assertSame(1,$fullDom->query('//*[@data-dashboard-inbox]')->length);
            $this->assertSame(0,$fragmentDom->query('//*[@id="dashboard-sidebar"]')->length);
            $this->assertSame(0,$fragmentDom->query('//*[@data-dashboard-inbox]')->length);
            $placeholder=$fragmentDom->query('//nav[contains(concat(" ",normalize-space(@class)," ")," main-header ")]');
            $this->assertSame(1,$placeholder->length);$this->assertTrue($placeholder->item(0)->hasAttribute('hidden'));
            $this->assertSame('true',$placeholder->item(0)->getAttribute('aria-hidden'));
            $this->assertSame($this->boot($fullDom)['initial'],$this->boot($fragmentDom)['initial']);
            $this->assertSame($this->nodeValues($fullDom,'//head/link','href'),$this->nodeValues($fragmentDom,'//head/link','href'));
            $this->assertSame($this->nodeValues($fullDom,'//script[@src]','src'),$this->nodeValues($fragmentDom,'//script[@src]','src'));
            $initializers=$this->nodeValues($fullDom,'//script[@data-dashboard-page-init]');
            $this->assertNotEmpty($initializers);
            $this->assertSame($initializers,$this->nodeValues($fragmentDom,'//script[@data-dashboard-page-init]'));
            $this->assertSame(1,$fragmentDom->query('//*[@data-dashboard-page-scripts]')->length);
            $this->assertGreaterThanOrEqual(2,$fullCost['notification_queries']);
            $this->assertSame(0,$fragmentCost['notification_queries']);
            $this->assertGreaterThan($fragmentCost['queries'],$fullCost['queries']);
            $this->assertGreaterThan($fragmentCost['bytes'],$fullCost['bytes']);
            $this->assertSame(0,$fullCost['writes']);$this->assertSame(0,$fragmentCost['writes']);
            if($role!=='branch')$this->assertGreaterThan($fragmentCost['pending_expense_queries'],$fullCost['pending_expense_queries']);
            $measurements[$role]=['full'=>$fullCost,'fragment'=>$fragmentCost];
        }
        Http::assertNothingSent();
        echo 'GO_ERP_NAVIGATION_FRAGMENT_MEASUREMENT '.json_encode(['actualLaravel'=>true,
            'actualBlade'=>true,'syntheticDatabase'=>true,'actualNativeRuntime'=>false,'hardware'=>false,
            'index_sha256'=>hash_file('sha256',resource_path('views/admin/index.blade.php')),
            'roles'=>$measurements],JSON_THROW_ON_ERROR).PHP_EOL;
    }

    public function test_navigation_fragment_keeps_live_financial_data_instead_of_caching_prior_html(): void
    {
        foreach([1,30,10] as $id){
            $this->actingAs($this->navigationActor($id),'admin');
            [$before,$beforeDom]=$this->measuredHome(['X-Dashboard-SPA'=>'1']);
            $beforeData=$this->boot($beforeDom)['initial'];
            $beforeAmount=$beforeData['branch_home']?$beforeData['today_expenses']['total_cents']:$beforeData['expenses_cents'];
            $expense=DB::table('branch_expenses')->insertGetId(['branch'=>'f:100','actor_id'=>10,
                'occurred_on'=>'2026-10-05','category'=>'purchases','description'=>'Synthetic approved expense',
                'amount_cents'=>2500,'payment_method'=>'cash','status'=>'approved']);
            [$after,$afterDom]=$this->measuredHome(['X-Dashboard-SPA'=>'1']);
            $afterData=$this->boot($afterDom)['initial'];
            $afterAmount=$afterData['branch_home']?$afterData['today_expenses']['total_cents']:$afterData['expenses_cents'];
            $this->assertSame($beforeAmount+2500,$afterAmount);
            $this->assertNotSame($before->getContent(),$after->getContent());
            DB::table('branch_expenses')->where('id',$expense)->delete();
        }
    }

    public function test_navigation_fragment_header_does_not_bypass_original_guest_role_or_branch_authority(): void
    {
        foreach([[],['X-Dashboard-SPA'=>'1','X-Dashboard-Actor'=>'1:admin']] as $headers)
            $this->get('/admin/dashboard?branch=f:100',$headers)->assertRedirect('/admin/login');
        $this->actingAs($this->navigationActor(20),'admin');
        foreach([[],['X-Dashboard-SPA'=>'1','X-Dashboard-Actor'=>'1:admin']] as $headers)
            $this->get('/admin/dashboard?actor_id=1&can_view_financials=1',$headers)->assertForbidden();
        $this->actingAs($this->navigationActor(10),'admin');
        foreach([[],['X-Dashboard-SPA'=>'1','X-Dashboard-Actor'=>'1:admin']] as $headers)
            $this->get('/admin/dashboard?branch=f:101&actor_id=1',$headers)->assertNotFound();
        [, $dom]=$this->measuredHome(['X-Dashboard-SPA'=>'1','X-Dashboard-Actor'=>'1:admin']);
        $data=$this->boot($dom)['initial'];
        $this->assertFalse($data['can_view_financials']);$this->assertArrayNotHasKey('owner_drawer',$data);
        $this->assertSame(['10:vendor'],$this->nodeValues($dom,'//body','data-dashboard-actor'));
    }

    public function test_navigation_only_exact_header_selects_fragment_and_normal_choose_type_keeps_its_original_shell(): void
    {
        $this->actingAs($this->navigationActor(1),'admin');
        foreach(['01','true','0','1, 1'] as $value){
            [, $dom]=$this->measuredHome(['X-Dashboard-SPA'=>$value]);
            $this->assertSame(1,$dom->query('//*[@id="dashboard-sidebar"]')->length);
            $this->assertSame(1,$dom->query('//*[@data-dashboard-inbox]')->length);
        }
        $full=$this->get('/admin/choose_type')->assertOk();
        $fullDom=$this->document($full->getContent());
        $this->assertSame(0,$fullDom->query('//*[@id="dashboard-sidebar"]')->length);
        $this->assertSame(1,$fullDom->query('//*[@data-dashboard-inbox]')->length);
        $this->assertSame(['1:admin'],$this->nodeValues($fullDom,'//body','data-dashboard-actor'));
    }
}
