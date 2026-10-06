<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\BranchExpenses;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\Dashboard\PosServiceTicket;
use App\Services\Dashboard\PosServiceTable;
use App\Services\Dashboard\PosServicePhone;
use App\Services\Dashboard\TakeawayService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DashboardBranchExpensesTest extends TestCase
{
    private string $connection='sqlite';
    protected function setUp(): void
    {
        parent::setUp();$this->connection=env('TAKEAWAY_TEST_CONNECTION','sqlite');
        if(!in_array($this->connection,['sqlite','mysql'],true))throw new \RuntimeException('Unsupported POS test connection.');
        config(['app.key'=>'base64:'.base64_encode(str_repeat('p',32)),'app.timezone'=>'Africa/Cairo','database.default'=>$this->connection,'cache.default'=>'array']);
        if($this->connection==='sqlite')config(['database.connections.sqlite.database'=>':memory:']);
        elseif(config('database.connections.mysql.database')!=='takeaway_test')throw new \RuntimeException('POS MySQL tests require dedicated takeaway_test database.');
        DB::purge($this->connection);Schema::clearResolvedInstance('db.schema');if($this->connection==='mysql')$this->dropFixtures();
        Carbon::setTestNow(Carbon::parse('2026-10-03 16:00:00','Africa/Cairo'));
        Schema::create('users',function(Blueprint $t){$t->id();foreach(['name','account_type','app_scope','status'] as $f)$t->string($f);$t->unsignedBigInteger('owner_resturant_id')->nullable();$t->unsignedBigInteger('pending_vendor_id')->nullable();$t->decimal('balance',14,2)->default(500);$t->timestamps();});
        Schema::create('resturants',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('parent_id')->nullable();$t->string('name');$t->timestamps();});
        Schema::create('resturant_products',function(Blueprint $t){$t->id();$t->unsignedBigInteger('resturant_id');$t->string('product_name');$t->decimal('product_price',14,2);$t->text('price');$t->string('status');$t->timestamps();});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->decimal('amount',14,2);});
        Schema::create('orders',function(Blueprint $t){$t->id();$t->string('status');});
        Schema::create('order_board_clocks',function(Blueprint $t){$t->id();$t->unsignedBigInteger('order_id');});
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php');(new \CreateGoStoreCatalog)->up();
        require_once database_path('migrations/2026_10_03_140000_create_takeaway_pos.php');(new \CreateTakeawayPos)->up();
        require_once database_path('migrations/2026_10_03_150000_create_pos_service_tickets.php');(new \CreatePosServiceTickets)->up();
        require_once database_path('migrations/2026_10_04_060000_lock_pos_service_bills.php');(new \LockPosServiceBills)->up();
        require_once database_path('migrations/2026_10_04_000001_create_pos_branch_print_jobs.php');(new \CreatePosBranchPrintJobs)->up();
        require_once database_path('migrations/2026_10_04_030000_create_branch_expenses.php');(new \CreateBranchExpenses)->up();
        require_once database_path('migrations/2026_10_06_120000_create_branch_expenses_categories.php');(new \CreateBranchExpensesCategories)->up();
        require_once database_path('migrations/2026_10_06_200000_manage_expense_categories.php');(new \ManageExpenseCategories)->up();
        Storage::fake('local');
        foreach([[1,'admin',null],[4,'admin',100],[10,'vendor',null],[11,'vendor',null],[12,'resturant_owner',100],[20,'user',null],[30,'vendor',null]] as [$id,$type,$owner])DB::table('users')->insert(['id'=>$id,'name'=>'Actor '.$id,'account_type'=>$type,'app_scope'=>$id===30?'go_partner':'fasakhansta','status'=>'accepted','owner_resturant_id'=>$owner]);
        DB::table('resturants')->insert([['id'=>100,'user_id'=>10,'name'=>'Main'],['id'=>101,'user_id'=>11,'name'=>'Foreign']]);
        DB::table('resturant_products')->insert([['id'=>1,'resturant_id'=>100,'product_name'=>'Fish','product_price'=>'100.00','price'=>'{}','status'=>'show'],['id'=>2,'resturant_id'=>101,'product_name'=>'Foreign','product_price'=>'500.00','price'=>'{}','status'=>'show']]);
        DB::table('go_stores')->insert(['user_id'=>30,'name'=>'GO store','kind'=>'grocery','address'=>'Address','created_at'=>now(),'updated_at'=>now()]);
        DB::table('go_store_products')->insert(['id'=>70,'user_id'=>30,'request_key'=>$this->key(70),'name'=>'Rice','unit'=>'كيلو','price_cents'=>10000,'image_path'=>'rice.jpg','available'=>true,'options'=>'[]','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('wallets')->insert(['amount'=>'100.00']);DB::table('orders')->insert(['status'=>'accepted']);DB::table('order_board_clocks')->insert(['order_id'=>1]);
    }
    protected function tearDown(): void{Carbon::setTestNow();if($this->connection==='mysql'&&config('database.connections.mysql.database')==='takeaway_test'){$this->dropFixtures();DB::disconnect('mysql');}parent::tearDown();}
    private function dropFixtures(): void{foreach(['branch_expense_category_commands','branch_expense_category_settings','branch_expense_categories','branch_expense_commands','branch_expenses','pos_branch_print_jobs','pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings','takeaway_till_entries','takeaway_order_items','takeaway_orders','takeaway_tills','model_has_roles','model_has_permissions','role_has_permissions','permissions','roles','go_store_products','go_stores','order_board_clocks','carts','orders','wallets','settings','pending_vendors','product_features','resturant_products','categories','resturants','users'] as $table)Schema::dropIfExists($table);}
    private function actor(int $id=10): User{return User::withoutGlobalScopes()->findOrFail($id);}
    private function service(): BranchExpenses{return app(BranchExpenses::class);}
    private function key(int $n): string{return sprintf('00000000-0000-4000-8000-%012d',$n);}
    private function payload(int $key=1,array $extra=[]): array{return $extra+['branch'=>'f:100','idempotency_key'=>$this->key($key),'occurred_on'=>'2026-10-03','category'=>'purchases','description'=>'Vegetables','amount'=>'125.50','payment_method'=>'cash'];}
    private function create(int $key=1,array $extra=[],int $actor=10): array{return $this->service()->save($this->payload($key,$extra),$this->actor($actor))['expense'];}
    private function review(array $item,string $action='approve',int $key=20,int $actor=1): array{return $this->service()->review($item['id'],['branch'=>$item['branch'],'action'=>$action,'reason'=>'Reviewed','expected_revision'=>$item['revision'],'idempotency_key'=>$this->key($key)],$this->actor($actor));}
    private function cash(int $amount=100000): void{DB::table('takeaway_tills')->insert(['branch'=>'f:100','balance_cents'=>$amount,'tax_bps'=>0,'revision'=>1]);}
    private function denied(callable $call,int $status=409): void{try{$call();$this->fail('Expected '.$status);}catch(HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    private function invalid(callable $call): void{try{$call();$this->fail('Expected validation error');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}

    public function test_branch_records_pending_without_moving_cash_and_cannot_self_approve(): void
    {
        $this->cash();$item=$this->create();$this->assertSame('pending',$item['status']);$this->assertSame('125.50',$item['amount']);
        $this->assertSame(100000,(int)DB::table('takeaway_tills')->value('balance_cents'));$this->assertSame(0,DB::table('takeaway_till_entries')->count());
        $this->denied(fn()=>$this->review($item,'approve',20,10),403);
        $this->denied(fn()=>$this->create(2,['approve'=>true]),403);
        $this->assertSame(1,DB::table('branch_expenses')->count());
    }
    public function test_approval_and_lost_response_retry_debit_cash_exactly_once(): void
    {
        $this->cash();$item=$this->create();$first=$this->review($item);$retry=$this->review($item);
        $this->assertSame('approved',$first['expense']['status']);$this->assertTrue($retry['replayed']);
        $this->assertSame(87450,(int)DB::table('takeaway_tills')->value('balance_cents'));$this->assertSame(1,DB::table('takeaway_till_entries')->count());
        $this->assertSame(-12550,(int)DB::table('takeaway_till_entries')->value('amount_cents'));
        $this->denied(fn()=>$this->review($item,'approve',21));$this->assertCount(2,$retry['expense']['history']);
    }
    public function test_cancellation_reverses_cash_once_and_keeps_audit(): void
    {
        $this->cash();$item=$this->review($this->create())['expense'];$void=$this->review($item,'void',21);$retry=$this->review($item,'void',21);
        $this->assertSame('voided',$void['expense']['status']);$this->assertTrue($retry['replayed']);$this->assertSame(100000,(int)DB::table('takeaway_tills')->value('balance_cents'));
        $this->assertSame(2,DB::table('takeaway_till_entries')->count());$this->assertCount(3,$void['expense']['history']);
        $this->denied(fn()=>$this->review($void['expense'],'approve',22));
    }
    public function test_insufficient_funds_rolls_back_create_and_approval_without_command(): void
    {
        $this->cash(100);$this->denied(fn()=>$this->create(1,['approve'=>true],1));$this->assertSame(0,DB::table('branch_expenses')->count());$this->assertSame(0,DB::table('branch_expense_commands')->count());
        $item=$this->create();$this->denied(fn()=>$this->review($item));$this->assertSame('pending',$this->service()->show($item['id'],$this->actor())['expense']['status']);
        $this->assertSame(100,(int)DB::table('takeaway_tills')->value('balance_cents'));$this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }
    public function test_non_cash_approval_and_void_do_not_touch_till(): void
    {
        $item=$this->create(1,['payment_method'=>'bank','approve'=>true],1);$this->assertSame('approved',$item['status']);$this->review($item,'void');
        $this->assertSame(0,DB::table('takeaway_tills')->count());$this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }
    public function test_branch_bound_admin_is_isolated_for_all_read_and_write_paths(): void
    {
        $own=$this->create();$foreign=$this->create(2,['branch'=>'f:101'],11);$actor=$this->actor(4);
        $this->assertCount(1,$this->service()->listing(['branch'=>'f:100'],$actor)['items']);
        $this->denied(fn()=>$this->service()->listing(['branch'=>'all'],$actor),403);
        $this->denied(fn()=>$this->service()->listing(['branch'=>'f:101'],$actor),404);
        $this->denied(fn()=>$this->service()->show($foreign['id'],$actor),404);
        $this->denied(fn()=>$this->service()->save($this->payload(3,['branch'=>'f:101']),$actor),404);
        $this->denied(fn()=>$this->review($own,'approve',20,4),403);
        $this->assertCount(2,$this->service()->listing(['branch'=>'all'],$this->actor(1))['items']);
    }
    public function test_pending_edit_requires_creator_or_reviewer_and_latest_revision(): void
    {
        $item=$this->create();$v=$this->payload(2,['expense_id'=>$item['id'],'expected_revision'=>1,'amount'=>'200.00']);
        $this->denied(fn()=>$this->service()->save($v,$this->actor(4)),403);
        $edited=$this->service()->save($v,$this->actor())['expense'];$this->assertSame('200.00',$edited['amount']);$this->assertSame(2,$edited['revision']);
        $this->denied(fn()=>$this->service()->save(array_merge($v,['idempotency_key'=>$this->key(3)]),$this->actor()));
    }
    public function test_edit_of_reviewed_expense_is_forbidden_and_rejected_spend_has_no_cash_effect(): void
    {
        $item=$this->create();$rejected=$this->review($item,'reject')['expense'];$this->assertSame('rejected',$rejected['status']);
        $this->denied(fn()=>$this->service()->save($this->payload(2,['expense_id'=>$item['id'],'expected_revision'=>2]),$this->actor()));
        $this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }
    public function test_idempotency_conflict_never_reuses_key_for_different_amount(): void
    {
        $first=$this->create();$retry=$this->service()->save($this->payload(),$this->actor());$this->assertTrue($retry['replayed']);$this->assertSame($first['id'],$retry['expense']['id']);
        $this->denied(fn()=>$this->create(1,['amount'=>'1.00']));$this->assertSame(1,DB::table('branch_expenses')->count());
    }
    public function test_invalid_money_future_date_and_empty_rejection_reason_are_rejected(): void
    {
        foreach(['0.00','-1.00','1.001','abc','1000000.01'] as $amount)$this->invalid(fn()=>$this->create(1,['amount'=>$amount]));
        $this->invalid(fn()=>$this->create(1,['occurred_on'=>'2026-10-04']));$this->invalid(fn()=>$this->create(1,['description'=>' ']));
        $item=$this->create();$this->invalid(fn()=>$this->service()->review($item['id'],['branch'=>'f:100','idempotency_key'=>$this->key(20),'expected_revision'=>1,'action'=>'reject'],$this->actor(1)));
    }
    public function test_attachment_is_private_scoped_and_invalid_type_does_not_persist(): void
    {
        $file=UploadedFile::fake()->image('receipt.png');$item=$this->service()->save($this->payload(),$this->actor(),$file)['expense'];$path=DB::table('branch_expenses')->value('attachment_path');Storage::disk('local')->assertExists($path);
        $this->assertArrayNotHasKey('attachment_path',$item);$this->denied(fn()=>$this->service()->attachment($item['id'],$this->actor(11)),404);
        $this->actingAs($this->actor(),'admin')->get($item['attachment_url'])->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->invalid(fn()=>$this->service()->save($this->payload(2),$this->actor(),UploadedFile::fake()->create('shell.php',1,'text/plain')));
        $this->assertSame(1,DB::table('branch_expenses')->count());
    }
    public function test_metrics_count_only_approved_expenses_and_filters_and_branches_apply(): void
    {
        $this->create(1,['payment_method'=>'bank','approve'=>true],1);$this->create(2,['amount'=>'10.00']);$this->create(3,['payment_method'=>'bank','approve'=>true,'branch'=>'f:101','amount'=>'25.00','category'=>'gas'],1);
        $all=$this->service()->listing(['branch'=>'all'],$this->actor(1));$this->assertSame('150.50',$all['summary']['today']);$this->assertSame(2,$all['summary']['count']);$this->assertSame(1,$all['summary']['pending']);
        $own=$this->service()->listing(['branch'=>'f:100'],$this->actor());$this->assertSame('125.50',$own['summary']['period']);$this->assertCount(2,$own['items']);
        $gas=$this->service()->listing(['branch'=>'all','category'=>'gas'],$this->actor(1));$this->assertCount(1,$gas['items']);$this->assertSame('25.00',$gas['summary']['period']);
    }
    public function test_http_recovery_is_actor_scoped_and_export_escapes_spreadsheet_formulas(): void
    {
        $item=$this->create(1,['description'=>'=SUM(1,2)']);$this->actingAs($this->actor(),'admin');
        $this->getJson(route('branch-expenses.recover',['branch'=>'f:100','idempotency_key'=>$this->key(1)]))->assertOk()->assertJsonPath('found',true);
        $this->get($item['print_url'])->assertOk()->assertSee('data-dashboard-receipt=',false)->assertSee('EXP-000001');
        $csv=$this->get(route('branch-expenses.export',['branch'=>'f:100']))->assertOk()->streamedContent();$this->assertStringContainsString("'=SUM(1,2)",$csv);
        $this->get(route('branch-expenses.report',['branch'=>'f:100']))->assertOk()->assertSee('data-dashboard-receipt=',false);
        $this->actingAs($this->actor(4),'admin')->getJson(route('branch-expenses.recover',['branch'=>'f:100','idempotency_key'=>$this->key(1)]))->assertOk()->assertJsonPath('found',false);
        $this->actingAs($this->actor(20),'admin')->getJson(route('branch-expenses.data',['branch'=>'f:100']))->assertForbidden();
    }
    public function test_reassigned_branch_cannot_be_accessed_with_stale_actor(): void
    {
        $item=$this->create();$actor=$this->actor();DB::table('resturants')->where('id',100)->update(['user_id'=>11]);
        $this->denied(fn()=>$this->service()->show($item['id'],$actor),404);$this->denied(fn()=>$this->service()->save($this->payload(2),$actor),404);
    }
    public function test_print_test_is_receipt_scoped_to_authorized_staff(): void
    {
        $this->actingAs($this->actor(),'admin')->get(route('print-settings.test'))->assertOk()->assertSee('data-dashboard-receipt="print-test"',false);
        $this->actingAs($this->actor(20),'admin')->getJson(route('print-settings.test'))->assertForbidden();
    }
    public function test_only_persisted_owner_adds_categories_and_retry_does_not_duplicate(): void
    {
        $categories=app(\App\Services\Dashboard\ExpenseCategories::class);$v=['name'=>'أدوات نظافة','idempotency_key'=>$this->key(981)];
        foreach([4,10,12,30] as $id){$this->assertFalse($this->service()->permissions($this->actor($id))['can_manage_categories']);$this->denied(fn()=>$categories->save($v,$this->actor($id)),403);}
        $this->actingAs($this->actor(4),'admin');$this->postJson(route('branch-expenses.categorySave'),$v)->assertForbidden();
        $this->actingAs($this->actor(1),'admin');$r=$this->postJson(route('branch-expenses.categorySave'),$v)->assertOk()->json();$this->assertFalse($r['replayed']);$this->assertSame('أدوات نظافة',$r['category']['name']);
        $this->assertTrue($categories->save($v,$this->actor(1))['replayed']);$this->assertSame($r['category'],$categories->save(['name'=>'  أدوات   نظافة  ','idempotency_key'=>$this->key(982)],$this->actor(1))['category']);$this->assertSame(1,DB::table('branch_expense_categories')->count());
        $this->denied(fn()=>$categories->save(array_replace($v,['name'=>'بند مختلف']),$this->actor(1)),409);$this->invalid(fn()=>$categories->save(['name'=>'   ','idempotency_key'=>$this->key(983)],$this->actor(1)));
        $stale=$this->actor(1);DB::table('users')->where('id',1)->update(['owner_resturant_id'=>100]);$this->denied(fn()=>$categories->save($v,$stale),403);
    }
    public function test_new_categories_are_usable_in_scoped_expenses_filters_prints_and_exports(): void
    {
        $r=app(\App\Services\Dashboard\ExpenseCategories::class)->save(['name'=>'أدوات نظافة','idempotency_key'=>$this->key(984)],$this->actor(1));$category=$r['category']['key'];
        $item=$this->create(985,['category'=>$category]);$this->assertSame('أدوات نظافة',$item['category_name']);
        $listing=$this->service()->listing(['branch'=>'f:100','category'=>$category],$this->actor());$this->assertCount(1,$listing['items']);$this->assertSame('أدوات نظافة',$listing['categories'][$category]);
        $this->assertCount(0,$this->service()->listing(['branch'=>'f:101','category'=>$category],$this->actor(11))['items']);
        $this->actingAs($this->actor(),'admin');$this->get(route('branch-expenses.print',['id'=>$item['id']]))->assertOk()->assertSee('أدوات نظافة');$this->get(route('branch-expenses.report',['branch'=>'f:100','category'=>$category]))->assertOk()->assertSee('أدوات نظافة');
        $export=$this->get(route('branch-expenses.export',['branch'=>'f:100','category'=>$category]))->assertOk();$this->assertStringContainsString('أدوات نظافة',$export->streamedContent());
        $this->invalid(fn()=>$this->create(986,['category'=>'custom_999999']));
    }

    public function test_owner_can_edit_and_remove_builtin_and_custom_categories_without_losing_expenses(): void
    {
        $categories=app(\App\Services\Dashboard\ExpenseCategories::class);$owner=$this->actor(1);
        $custom=$categories->save(['name'=>'Cleaning','idempotency_key'=>$this->key(900)],$owner)['category'];
        $pending=$this->create(901,['category'=>$custom['key']]);$approved=$this->create(902,['category'=>'purchases','payment_method'=>'bank','approve'=>true],1);
        foreach([$custom,['key'=>'purchases','revision'=>0]] as $i=>$item){
            $edit=['action'=>'update','key'=>$item['key'],'expected_revision'=>0,'name'=>'Revised '.$i,'idempotency_key'=>$this->key(910+$i)];
            $changed=$categories->save($edit,$owner);$this->assertSame('Revised '.$i,$changed['categories'][$item['key']]);$this->assertSame(1,$changed['category']['revision']);
            $this->assertTrue($categories->save($edit,$owner)['replayed']);
            $this->denied(fn()=>$categories->save(array_replace($edit,['idempotency_key'=>$this->key(920+$i)]),$owner),409);
            $this->denied(fn()=>$categories->save(array_replace($edit,['name'=>'Other']),$owner),409);
            $delete=['action'=>'delete','key'=>$item['key'],'expected_revision'=>1,'idempotency_key'=>$this->key(930+$i)];
            foreach([4,10,12,30] as $actor){$this->denied(fn()=>$categories->save($edit,$this->actor($actor)),403);$this->denied(fn()=>$categories->save($delete,$this->actor($actor)),403);}
            $removed=$categories->save($delete,$owner);$this->assertFalse($removed['category']['active']);$this->assertSame('Revised '.$i,$removed['categories'][$item['key']]);$this->assertArrayNotHasKey($item['key'],$removed['active_categories']);
            $this->assertTrue($categories->save($delete,$owner)['replayed']);
            $this->invalid(fn()=>$this->create(940+$i,['category'=>$item['key']]));
            $r=$this->service()->listing(['branch'=>'f:100','category'=>$item['key']],$this->actor());$this->assertCount(1,$r['items']);$this->assertSame('Revised '.$i,$r['items'][0]['category_name']);$this->assertSame([],$r['category_items']);
            $this->actingAs($this->actor(),'admin');$this->get(route('branch-expenses.print',['id'=>$r['items'][0]['id']]))->assertOk()->assertSee('Revised '.$i);
        }
        $this->assertSame(2,DB::table('branch_expenses')->count());$this->assertSame(25100,(int)DB::table('branch_expenses')->sum('amount_cents'));
        // Existing pending records may retain their removed category while being corrected.
        $updated=$this->create(950,['expense_id'=>$pending['id'],'expected_revision'=>$pending['revision'],'category'=>$custom['key'],'description'=>'Corrected']);$this->assertSame($pending['id'],$updated['id']);
        $this->assertSame('Revised 0',$updated['category_name']);
        $restored=$categories->save(['name'=>'Revised 0','idempotency_key'=>$this->key(951)],$owner);$this->assertSame($custom['key'],$restored['category']['key']);$this->assertTrue($restored['category']['active']);$this->assertSame(3,$restored['category']['revision']);
        $this->create(952,['category'=>$custom['key']]);
    }
    public function test_category_name_collisions_and_removed_choices_are_validated_without_changing_cash(): void
    {
        $categories=app(\App\Services\Dashboard\ExpenseCategories::class);$owner=$this->actor(1);$this->cash();
        $category=$categories->save(['name'=>'Office supplies','idempotency_key'=>$this->key(960)],$owner)['category'];
        $this->invalid(fn()=>$categories->save(['action'=>'update','key'=>'gas','expected_revision'=>0,'name'=>'Office supplies','idempotency_key'=>$this->key(961)],$owner));
        $categories->save(['action'=>'update','key'=>$category['key'],'expected_revision'=>0,'name'=>'Stationery','idempotency_key'=>$this->key(962)],$owner);
        $another=$categories->save(['name'=>'Office supplies','idempotency_key'=>$this->key(963)],$owner);$this->assertNotSame($category['key'],$another['category']['key']);
        $delete=['action'=>'delete','key'=>'gas','expected_revision'=>0,'idempotency_key'=>$this->key(964)];
        $this->actingAs($this->actor(4),'admin');$this->postJson(route('branch-expenses.categorySave'),$delete)->assertForbidden();
        $this->actingAs($owner,'admin');$this->postJson(route('branch-expenses.categorySave'),$delete)->assertOk()->assertJsonPath('category.active',false);
        $this->assertSame(100000,(int)DB::table('takeaway_tills')->value('balance_cents'));$this->assertSame(0,DB::table('takeaway_till_entries')->count());
    }

}
