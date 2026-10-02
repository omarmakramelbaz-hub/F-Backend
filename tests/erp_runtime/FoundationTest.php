<?php

namespace ErpTests;

use App\Models\Erp\StaffUser;
use App\Services\Erp\Actor;
use App\Services\Erp\Decimal;
use App\Services\Erp\People;
use App\Services\Erp\Stock;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FoundationTest extends ErpTestCase
{
    public function test_owner_can_render_every_screen_and_the_login_form(): void
    {
        $this->get('/erp/login')->assertOk()->assertSee('البريد الإلكتروني');
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        (new Stock)->post($this->actor(), $this->stock());
        $this->staff();
        foreach (['','branches','inventory','employees','payroll','orders','accounts','audit'] as $page) {
            $response = $this->get('/erp/'.$page);
            $response->assertOk()->assertSee('فسخانستا');
            if ($folder = getenv('ERP_RENDER_DIR')) {
                if (!is_dir($folder)) { mkdir($folder, 0700, true); }
                file_put_contents($folder.'/'.($page ?: 'home').'.html', $response->getContent());
            }
        }
        $this->get('/erp/orders')->assertSee('EXTERNAL-SECRET')->assertSee('FS-100');
        $this->get('/erp/payroll?month=2026-02')->assertOk();
    }

    public function test_feature_gate_guest_and_legacy_restaurant_owner_are_isolated(): void
    {
        $this->get('/erp/inventory')->assertRedirect('/erp/login');
        $this->actingAs(LegacyOwner::findOrFail(2),'admin')->get('/erp/accounts')->assertRedirect('/erp/login');
        config(['erp.enabled'=>false]);
        $this->get('/erp')->assertNotFound();
        $this->get('/erp/login')->assertNotFound();
        $this->post('/erp/stock', $this->stock())->assertNotFound();
        $this->assertSame(0, DB::table('erp_stock_documents')->count());
    }

    public function test_dashboard_admin_reuses_current_login_for_erp_without_second_account(): void
    {
        config([
            'erp.standalone_auth'=>false,
            'erp.administrative_admin_emails'=>['orders-admin@example.test'],
            'erp.legacy_order_admin_emails'=>['orders-admin@example.test'],
        ]);

        DB::table('users')->insert([
            'id'=>3,
            'name'=>'Orders Admin',
            'email'=>'orders-admin@example.test',
            'account_type'=>'admin',
        ]);

        $admin=LegacyOwner::findOrFail(3);
        $this->actingAs($admin,'admin');

        $this->get('/erp/orders')
            ->assertOk()
            ->assertSee('طلبات التطبيق')
            ->assertSee('مدينة نصر')
            ->assertSee('المعادي')
            ->assertSee('FS-100')
            ->assertSee('FS-101');

        $this->get('/erp/login')->assertRedirect('/erp/orders');
        $this->get('/erp/inventory')->assertOk();
        $this->get('/erp/accounts')->assertNotFound();
    }

    public function test_dashboard_branch_manager_is_mapped_to_its_enrolled_erp_branch(): void
    {
        config(['erp.standalone_auth'=>false]);

        DB::table('users')->insert([
            'id'=>4,
            'name'=>'Branch Manager',
            'email'=>'branch@example.test',
            'account_type'=>'vendor',
            'owner_resturant_id'=>1,
        ]);
        DB::table('resturants')->where('id',1)->update(['user_id'=>4]);

        $manager=LegacyOwner::findOrFail(4);
        $this->actingAs($manager,'admin');

        $this->get('/erp/orders')
            ->assertOk()
            ->assertSee('FS-100')
            ->assertDontSee('FS-101')
            ->assertDontSee('EXTERNAL-SECRET');

        $this->get('/erp/orders?branch=2')->assertForbidden();
        $this->get('/erp/branches')->assertForbidden();
        $this->get('/erp/login')->assertRedirect('/erp/orders');
    }

    public function test_embedded_admin_app_orders_view_compiles_and_review_link_stays_in_dashboard(): void
    {
        $root = dirname(__DIR__, 2);
        $blade = file_get_contents($root.'/resources/views/admin/orders/app_orders.blade.php');
        $compiled = app('blade.compiler')->compileString($blade);

        $this->assertStringContainsString('app-orders-dashboard', $compiled);
        $this->assertStringContainsString("route('orders.applies')", $blade);
        $this->assertStringContainsString("route('orders.applies.menu.status'", $blade);

        $menu = file_get_contents($root.'/resources/views/admin/layouts/menu.blade.php');
        $this->assertStringContainsString("url('/admin/applies-orders')", $menu);
        $this->assertStringNotContainsString("route('erp.orders') : url('/admin/applies-orders')", $menu);

        $this->assertFileExists($root.'/public/erp-assets/admin-app-orders.css');
        $this->assertFileExists($root.'/public/erp-assets/admin-app-orders.js');
    }

    public function test_dashboard_branch_registry_links_fasakhansta_restaurants_and_orders(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('added_by')->nullable();
            $t->string('app_scope')->nullable();
        });
        Schema::table('resturants', function (Blueprint $t) {
            $t->unsignedBigInteger('added_by')->nullable();
        });

        DB::table('users')->insert([
            ['id'=>10,'name'=>'مدير فرع جديد','email'=>'branch10@example.test','account_type'=>'vendor','owner_resturant_id'=>10,'added_by'=>1,'app_scope'=>null],
            ['id'=>11,'name'=>'شريك GO','email'=>'go11@example.test','account_type'=>'vendor','owner_resturant_id'=>11,'added_by'=>1,'app_scope'=>'go_partner'],
        ]);

        DB::table('resturants')->insert([
            ['id'=>10,'name'=>'فسخانستا فرع جديد','user_id'=>10,'added_by'=>1],
            ['id'=>11,'name'=>'متجر GO تجريبي','user_id'=>11,'added_by'=>1],
            ['id'=>12,'name'=>'مطعم خارجي','user_id'=>null,'added_by'=>2],
        ]);

        DB::table('orders')->insert([
            'resturant_id'=>10,'order_no'=>'FS-LINK-10','status'=>'pending',
            'type'=>'current','payment_type'=>'cash','created_at'=>now(),
        ]);

        $created = app(\App\Services\Erp\BranchRegistry::class)->syncDashboardBranches();
        $this->assertGreaterThanOrEqual(1, $created);

        $branch = DB::table('erp_branches')->where('restaurant_id',10)->first();
        $this->assertNotNull($branch);
        $this->assertSame('فسخانستا فرع جديد',$branch->name);
        $this->assertFalse(DB::table('erp_branches')->where('restaurant_id',11)->exists());
        $this->assertFalse(DB::table('erp_branches')->where('restaurant_id',12)->exists());
        $this->assertTrue(DB::table('erp_warehouses')->where('branch_id',$branch->id)->exists());

        $dashboard = app(\App\Services\Erp\UnifiedOrders::class)->dashboard(
            $this->actor('deputy_manager'),
            (int) $branch->id,
            now(config('erp.timezone'))->format('Y-m-d'),
            ['app'=>'fasakhansta','kind'=>'branch']
        );

        $numbers = collect($dashboard['columns'])
            ->flatMap(fn ($column) => collect($column['rows'])->pluck('number'))
            ->all();

        $this->assertContains('FS-LINK-10',$numbers);
    }

    public function test_dashboard_v3_builds_account_scoped_kpis_and_assets_compile(): void
    {
        $overview = app(\App\Services\Dashboard\DashboardOverview::class)->build(
            $this->actor('deputy_manager'),
            null,
            now(config('erp.timezone'))->format('Y-m-d')
        );

        $this->assertCount(6, $overview['kpis']);
        $this->assertArrayHasKey('new', $overview['counts']);
        $this->assertArrayHasKey('preparing', $overview['counts']);
        $this->assertArrayHasKey('delivery', $overview['counts']);
        $this->assertArrayHasKey('done', $overview['counts']);
        $this->assertCount(7, $overview['chart']['labels']);
        $this->assertCount(7, $overview['chart']['sales']);
        $this->assertCount(7, $overview['chart']['orders']);

        $root = dirname(__DIR__, 2);
        $home = file_get_contents($root.'/resources/views/admin/home_v3.blade.php');
        $compiled = app('blade.compiler')->compileString($home);
        $this->assertStringContainsString('fas-home-v3', $compiled);
        $this->assertStringContainsString('نوع الحساب الحالي', $home);
        $this->assertStringContainsString('جميع الفروع', $home);

        $menu = file_get_contents($root.'/resources/views/admin/layouts/menu.blade.php');
        $navbar = file_get_contents($root.'/resources/views/admin/layouts/navbar.blade.php');
        $this->assertStringContainsString('fas-modern-brand', $menu);
        $this->assertStringContainsString('طلبات التطبيق', $menu);
        $this->assertStringContainsString('fas-topbar-search', $navbar);
        $this->assertFileExists($root.'/public/dashboard-v3/dashboard-v3.css');
        $this->assertFileExists($root.'/public/dashboard-v3/dashboard-v3.js');
    }

    public function test_dashboard_v3_actor_is_defined_in_parent_layout_before_includes(): void
    {
        $root = dirname(__DIR__, 2);
        $index = file_get_contents($root.'/resources/views/admin/index.blade.php');
        $header = file_get_contents($root.'/resources/views/admin/layouts/header.blade.php');
        $menu = file_get_contents($root.'/resources/views/admin/layouts/menu.blade.php');
        $navbar = file_get_contents($root.'/resources/views/admin/layouts/navbar.blade.php');

        $this->assertStringStartsWith('@php($fasV3Actor = \\App\\Services\\Erp\\Access::actor())', $index);
        $this->assertStringNotContainsString('@php($fasV3Actor = \\App\\Services\\Erp\\Access::actor())', $header);
        $this->assertStringContainsString('$fasV3Actor', $menu);
        $this->assertStringContainsString('$fasV3Actor', $navbar);
    }

    public function test_deputy_controls_all_branches_inventory_and_employees_but_not_accounts(): void
    {
        $this->actingAs($this->staff(),'erp');
        $this->get('/erp/branches')->assertOk()->assertSee('مدينة نصر')->assertSee('المعادي');
        $this->get('/erp/inventory')->assertOk()->assertSee('المخزن المركزي');
        $this->get('/erp/employees')->assertOk()->assertSee('أحمد محمد')->assertSee('محمود علي');
        $this->get('/erp/orders')->assertOk()->assertSee('EXTERNAL-SECRET');
        $this->get('/erp/accounts')->assertForbidden();
        $this->post('/erp/accounts', [])->assertForbidden();
        $this->post('/erp/stock',$this->stock())->assertRedirect()->assertSessionHas('success');
        $this->post('/erp/employees/2/attendance',['day'=>'2026-09-30','status'=>'present'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1,DB::table('erp_attendance')->where('employee_id',2)->count());
        $this->assertSame(3,DB::table('resturants')->count());
        $this->assertSame(7,DB::table('orders')->count());
    }

    public function test_central_order_center_includes_go_stores_and_services_but_branch_manager_cannot_see_them(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('mobile')->nullable();
            $t->string('app_scope')->nullable();
        });
        DB::table('users')->insert([
            ['id'=>3,'name'=>'عميل GO','account_type'=>'user','mobile'=>'01000000003','app_scope'=>'go'],
            ['id'=>4,'name'=>'متجر GO','account_type'=>'vendor','mobile'=>'01000000004','app_scope'=>'go_partner'],
            ['id'=>5,'name'=>'صنايعي GO','account_type'=>'delegate','mobile'=>'01000000005','app_scope'=>'go_partner'],
        ]);

        Schema::create('go_store_orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('store_id'); $t->json('snapshot');
            $t->string('fulfillment'); $t->string('status'); $t->string('payment_status');
            $t->unsignedBigInteger('total_cents'); $t->string('payment_method');
            $t->timestamps();
        });
        DB::table('go_store_orders')->insert([
            'id'=>10,'store_id'=>4,
            'snapshot'=>json_encode(['store_name'=>'متجر اختبار GO','customer_name'=>'عميل GO','customer_mobile'=>'01000000003','items'=>[]], JSON_UNESCAPED_UNICODE),
            'fulfillment'=>'delivery','status'=>'pending','payment_status'=>'cash_due',
            'total_cents'=>15000,'payment_method'=>'cash','created_at'=>now(),'updated_at'=>now(),
        ]);

        Schema::create('go_service_jobs', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('customer_id'); $t->unsignedBigInteger('partner_id')->nullable();
            $t->string('profession_key'); $t->text('description'); $t->string('address')->nullable();
            $t->string('status'); $t->unsignedBigInteger('price_cents')->default(0);
            $t->string('payment_method')->nullable(); $t->string('payment_status')->nullable();
            $t->timestamps();
        });
        DB::table('go_service_jobs')->insert([
            'id'=>20,'customer_id'=>3,'partner_id'=>5,'profession_key'=>'plumber',
            'description'=>'إصلاح تسريب','address'=>'المنصورة','status'=>'searching',
            'price_cents'=>0,'payment_method'=>null,'payment_status'=>null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $this->get('/erp/orders')
            ->assertOk()
            ->assertSee('GS-10')
            ->assertSee('متجر اختبار GO')
            ->assertSee('GJ-20');

        $manager=$this->staff('branch_manager',1);
        $this->actingAs($manager,'erp');
        $this->get('/erp/orders')
            ->assertOk()
            ->assertDontSee('GS-10')
            ->assertDontSee('GJ-20')
            ->assertSee('FS-100');
    }

    public function test_branch_scope_is_enforced_on_reads_and_forged_writes(): void
    {
        $user = $this->staff('branch_manager',1);
        $user->permissions = array_diff(Actor::CAPABILITIES,['access.manage']); $user->save();
        $this->actingAs($user,'erp');
        $this->get('/erp/employees')->assertOk()->assertSee('أحمد محمد')->assertDontSee('محمود علي');
        $this->get('/erp/employees?branch=2')->assertForbidden();
        $this->get('/erp/payroll')->assertForbidden();
        $this->get('/erp/branches')->assertForbidden();
        $this->get('/erp/inventory')->assertOk()->assertDontSee('المخزن المركزي')->assertDontSee('مخزن المعادي');
        $this->get('/erp/orders')->assertOk()->assertSee('FS-100')->assertDontSee('FS-101')->assertDontSee('EXTERNAL-SECRET');
        $this->get('/erp/orders?branch=2')->assertForbidden();
        $this->post('/erp/employees/2/attendance',['day'=>'2026-09-30','status'=>'absent'])->assertForbidden();
        $this->post('/erp/stock',$this->stock(['warehouse_id'=>3]))->assertForbidden();
        $this->post('/erp/stock',$this->stock(['warehouse_id'=>2,'type'=>'transfer','destination_id'=>3]))->assertForbidden();
        $this->assertSame(0,DB::table('erp_stock_documents')->count());
        $this->assertSame(0,DB::table('erp_attendance')->count());
    }

    public function test_disabling_a_staff_account_or_removing_permission_applies_to_current_session(): void
    {
        $staff=$this->staff(); $this->actingAs($staff,'erp')->get('/erp/inventory')->assertOk();
        DB::table('erp_users')->where('id',$staff->id)->update(['permissions'=>'[]']);
        $this->get('/erp/inventory')->assertForbidden();
        DB::table('erp_users')->where('id',$staff->id)->update(['active'=>0]);
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $this->get('/erp')->assertRedirect('/erp/login');
    }

    public function test_owner_creates_real_hashed_deputy_account_and_validates_branch_role(): void
    {
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $data=['name'=>'أدمن إداري','email'=>'DEPUTY@example.test','role'=>'deputy_manager','permissions'=>Actor::defaults('deputy_manager'),'active'=>'1','password'=>'New-password-long-123','password_confirmation'=>'New-password-long-123'];
        $this->post('/erp/accounts',$data)->assertRedirect()->assertSessionHasNoErrors();
        $account=StaffUser::firstOrFail();
        $this->assertSame('deputy@example.test',$account->email);
        $this->assertTrue(Hash::check($data['password'],$account->password));
        $this->assertNull($account->branch_id);
        $this->assertStringNotContainsString('password',DB::table('erp_audit')->where('action','access.save')->value('details'));
        $this->from('/erp/accounts')->post('/erp/accounts',array_replace($data,['email'=>'manager@example.test','role'=>'branch_manager']))->assertSessionHasErrors('operation');
        $this->assertSame(1,StaffUser::count());
    }

    public function test_only_administrative_admin_and_branch_manager_are_staff_roles(): void
    {
        $this->assertSame(['deputy_manager','branch_manager'],Actor::ROLES);
        foreach (['inventory_manager','hr_manager','unknown_role'] as $role) {
            $actor=new Actor('staff:1','حساب قديم',$role,1,Actor::CAPABILITIES);
            $this->assertSame([],Actor::defaults($role));
            $this->assertFalse($actor->allBranches(),$role);
            $this->rejects(403,fn()=>$actor->branch(null));
            $this->rejects(403,fn()=>$actor->branch(1));
            $this->assertSame(0,$actor->scope(DB::table('erp_employees'))->count());
            foreach (Actor::CAPABILITIES as $capability) {
                $this->assertFalse($actor->can($capability),$role.' must not grant '.$capability);
                $this->rejects(403,fn()=>$actor->require($capability));
            }
        }
    }

    /** @dataProvider unsupportedStaffRoles */
    public function test_owner_cannot_create_a_removed_or_forged_staff_role(string $role): void
    {
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $data=['name'=>'حساب غير مسموح','email'=>'invalid@example.test','role'=>$role,'branch_id'=>1,'permissions'=>Actor::defaults('deputy_manager'),'active'=>'1','password'=>'New-password-long-123','password_confirmation'=>'New-password-long-123'];
        $this->from('/erp/accounts')->post('/erp/accounts',$data)->assertRedirect('/erp/accounts')->assertSessionHasErrors('role');
        $this->assertSame(0,StaffUser::count());
        $this->assertSame(0,DB::table('erp_audit')->where('action','access.save')->count());
    }

    /** @dataProvider unsupportedStaffRoles */
    public function test_owner_cannot_update_a_staff_account_to_a_removed_or_forged_role(string $role): void
    {
        $staff=$this->staff('branch_manager',1);
        $original=$staff->fresh()->getAttributes();
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $data=['id'=>$staff->id,'name'=>'تغيير مرفوض','email'=>'changed@example.test','role'=>$role,'branch_id'=>2,'permissions'=>Actor::defaults('deputy_manager'),'active'=>'0','password'=>'Changed-password-123','password_confirmation'=>'Changed-password-123'];
        $this->from('/erp/accounts')->post('/erp/accounts',$data)->assertRedirect('/erp/accounts')->assertSessionHasErrors('role');
        $this->assertSame($original,$staff->fresh()->getAttributes());
        $this->assertSame(1,StaffUser::count());
        $this->assertSame(0,DB::table('erp_audit')->where('action','access.save')->count());
    }

    /** @dataProvider unsupportedStaffRoles */
    public function test_existing_unsupported_staff_accounts_cannot_log_in_and_are_preserved(string $role): void
    {
        $staff=$this->staff($role,1);
        $staff->permissions=Actor::CAPABILITIES; $staff->save();
        $original=$staff->fresh()->getAttributes();
        $this->from('/erp/login')->post('/erp/login',['email'=>$staff->email,'password'=>'Test-only-password-123'])
            ->assertRedirect('/erp/login')->assertSessionHasErrors('email');
        $this->assertGuest('erp');
        $this->get('/erp/inventory')->assertRedirect('/erp/login');
        $this->assertSame($original,$staff->fresh()->getAttributes());
        $this->assertSame(1,StaffUser::count());
    }

    /** @dataProvider unsupportedStaffRoles */
    public function test_current_staff_session_loses_access_after_role_removal_even_with_owner_session(string $role): void
    {
        $staff=$this->staff();
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $this->actingAs($staff,'erp')->get('/erp/inventory')->assertOk();
        $this->get('/erp/accounts')->assertForbidden();
        $this->assertAuthenticated('admin');
        $this->assertAuthenticated('erp');
        DB::table('erp_users')->where('id',$staff->id)->update(['role'=>$role]);
        $original=$staff->fresh()->getAttributes();
        foreach (['','inventory','employees','accounts'] as $page) {
            $this->get('/erp/'.$page)->assertRedirect('/erp/login');
        }
        $this->post('/erp/stock',$this->stock())->assertRedirect('/erp/login');
        $this->get('/erp/login')->assertOk();
        $this->assertSame(0,DB::table('erp_stock_documents')->count());
        $this->assertSame($original,$staff->fresh()->getAttributes());
    }

    /** @dataProvider legacyStaffReclassifications */
    public function test_owner_must_explicitly_reclassify_a_legacy_account_and_can_keep_its_identity(string $oldRole, string $newRole, ?int $branch): void
    {
        $staff=$this->staff($oldRole,2);
        $staff->permissions=['inventory.manage','audit.view']; $staff->save();
        $original=$staff->fresh()->getAttributes();
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $response=$this->get('/erp/accounts')->assertOk()->assertSee($staff->email)->assertSee('أدمن إداري')->assertSee('هذا الحساب محفوظ بدور قديم غير مدعوم');
        if ($folder = getenv('ERP_RENDER_DIR')) {
            if (!is_dir($folder)) { mkdir($folder, 0700, true); }
            file_put_contents($folder.'/accounts-legacy.html', $response->getContent());
        }
        $dom=new \DOMDocument;
        $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent()); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath=new \DOMXPath($dom);
        $selects=$xpath->query('//form[.//input[@name="id" and @value="'.$staff->id.'"]]//select[@name="role"]');
        $this->assertSame(1,$selects->length);
        $select=$selects->item(0);
        $this->assertTrue($select->hasAttribute('required'));
        $this->assertSame(1,$xpath->query('./option[@value="" and @disabled and @selected]',$select)->length);
        $this->assertSame(0,$xpath->query('./option[@value!="" and @selected]',$select)->length);
        $this->assertSame(0,$xpath->query('//form[.//input[@name="id" and @value="'.$staff->id.'"]]//select[@name="branch_id"]/option[@value!="" and @selected]')->length);
        foreach ($xpath->query('//select[@name="role"]') as $roleSelect) {
            $options=[];
            foreach ($xpath->query('./option[@value!=""]',$roleSelect) as $option) { $options[]=$option->getAttribute('value'); }
            $this->assertSame(['deputy_manager','branch_manager'],$options);
        }
        $this->assertSame($original,$staff->fresh()->getAttributes());
        $data=['id'=>$staff->id,'name'=>$staff->name,'email'=>$staff->email,'role'=>'','branch_id'=>$branch,'permissions'=>Actor::defaults($newRole),'active'=>'1'];
        $this->from('/erp/accounts')->post('/erp/accounts',$data)->assertSessionHasErrors('role');
        $this->assertSame($original,$staff->fresh()->getAttributes());
        $this->assertSame(0,DB::table('erp_audit')->where('action','access.save')->count());
        $this->post('/erp/accounts',array_replace($data,['role'=>$newRole]))->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        $updated=$staff->fresh();
        $this->assertSame($newRole,$updated->role);
        $this->assertSame($branch,$updated->branch_id === null ? null : (int)$updated->branch_id);
        $this->assertSame($original['email'],$updated->email);
        $this->assertSame($original['password'],$updated->password);
        $this->assertSame(Actor::defaults($newRole),$updated->permissions);
        $this->assertSame(1,StaffUser::count());
        $this->assertSame(1,DB::table('erp_audit')->where('action','access.save')->where('entity_id',$staff->id)->count());
        Auth::guard('admin')->logout();
        $this->post('/erp/login',['email'=>$updated->email,'password'=>'Test-only-password-123'])->assertRedirect('/erp');
        $this->assertAuthenticatedAs($updated,'erp');
        $this->get('/erp/inventory')->assertOk();
    }

    public static function unsupportedStaffRoles(): array
    {
        return [['inventory_manager'],['hr_manager'],['owner'],['unknown_role']];
    }

    public static function legacyStaffReclassifications(): array
    {
        return [['inventory_manager','deputy_manager',null],['hr_manager','branch_manager',1]];
    }

    public function test_login_logout_and_failed_login_throttling(): void
    {
        $this->staff();
        $this->post('/erp/login',['email'=>'deputy@example.test','password'=>'Test-only-password-123'])->assertRedirect('/erp');
        $this->assertAuthenticated('erp');
        $this->post('/erp/logout')->assertRedirect('/erp/login');
        $this->assertGuest('erp');
        for($i=0;$i<6;$i++) { $response=$this->from('/erp/login')->post('/erp/login',['email'=>'deputy@example.test','password'=>'wrong']); }
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('محاولات كثيرة',session('errors')->first('email'));
        $this->assertGuest('erp');
    }

    public function test_stock_transfers_conserve_quantity_and_value_and_retries_do_not_duplicate(): void
    {
        $stock=new Stock; $actor=$this->actor(); $opening=$this->stock();
        $id=$stock->post($actor,$opening);
        $this->assertSame($id,$stock->post($actor,$opening));
        $transfer=$this->stock(['type'=>'transfer','destination_id'=>2,'quantity'=>'2.500']);
        $transferId=$stock->post($actor,$transfer);
        $this->assertSame($transferId,$stock->post($actor,$transfer));
        $balances=DB::table('erp_stock_balances')->get()->keyBy('warehouse_id');
        $this->assertSame(7500,(int)$balances[1]->quantity_milli);
        $this->assertSame(2500,(int)$balances[2]->quantity_milli);
        $this->assertSame(200000,(int)$balances->sum('value_minor'));
        $entries=DB::table('erp_stock_entries')->where('document_id',$transferId);
        $this->assertSame(0,(int)(clone $entries)->sum('quantity_milli'));
        $this->assertSame(0,(int)(clone $entries)->sum('value_minor'));
        $this->assertSame(2,DB::table('erp_stock_documents')->count());
        $this->assertSame(2,DB::table('erp_audit')->where('action','like','stock.%')->count());
        $this->rejects(409,fn()=>$stock->post($actor,array_replace($transfer,['quantity'=>'1.000'])));
    }

    public function test_weighted_cost_fractional_weights_and_full_depletion_have_no_rounding_residue(): void
    {
        $stock=new Stock;$actor=$this->actor();
        $stock->post($actor,$this->stock(['quantity'=>'0.125','unit_cost'=>'123.45']));
        $stock->post($actor,$this->stock(['type'=>'receipt','quantity'=>'0.375','unit_cost'=>'200.00']));
        $this->assertSame(9043,(int)DB::table('erp_stock_balances')->value('value_minor'));
        $stock->post($actor,$this->stock(['type'=>'waste','quantity'=>'0.100']));
        $this->assertSame(7234,(int)DB::table('erp_stock_balances')->value('value_minor'));
        $stock->post($actor,$this->stock(['type'=>'waste','quantity'=>'0.400']));
        $this->assertSame(0,(int)DB::table('erp_stock_balances')->value('quantity_milli'));
        $this->assertSame(0,(int)DB::table('erp_stock_balances')->value('value_minor'));
    }

    public function test_invalid_and_stale_stock_movements_leave_no_partial_changes(): void
    {
        $stock=new Stock;$actor=$this->actor();
        $stock->post($actor,$this->stock());
        $this->rejects(422,fn()=>$stock->post($actor,$this->stock(['type'=>'transfer','destination_id'=>2,'quantity'=>'11'])));
        $this->rejects(422,fn()=>$stock->post($actor,$this->stock(['item_id'=>2,'quantity'=>'1.5'])));
        $this->rejects(409,fn()=>$stock->post($actor,$this->stock()));
        $this->rejects(409,fn()=>$stock->post($actor,$this->stock(['type'=>'count','quantity'=>'8','expected_quantity'=>'9'])));
        $this->assertSame(1,DB::table('erp_stock_balances')->count());
        $this->assertSame(1,DB::table('erp_stock_documents')->count());
        $this->assertSame(10000,(int)DB::table('erp_stock_balances')->value('quantity_milli'));
        $stock->post($actor,$this->stock(['type'=>'count','quantity'=>'8.125','expected_quantity'=>'10']));
        $this->assertSame(8125,(int)DB::table('erp_stock_balances')->value('quantity_milli'));
        $this->assertSame(162500,(int)DB::table('erp_stock_balances')->value('value_minor'));
        $this->assertSame(-1875,(int)DB::table('erp_stock_entries')->orderByDesc('id')->value('quantity_milli'));
    }

    public function test_decimal_validation_rejects_silent_truncation_negative_values_and_exponents(): void
    {
        foreach(['1.2345','-1','1e3','NaN','1000001',''] as $value) { $this->rejects(422,fn()=>Decimal::quantity($value,'kg')); }
        $this->assertSame(125,Decimal::quantity('0.125','kg'));
        $this->assertSame(1000,Decimal::quantity('1.000','piece'));
        $this->assertSame(12345,Decimal::money('123.45'));
    }

    public function test_salary_effective_dates_and_closed_payroll_remain_unchanged(): void
    {
        $people=new People;$actor=$this->actor();
        $people->salary($actor,1,'2026-09','6500');
        $this->assertSame(600000,$people->statement($actor,1,'2026-08')['base_minor']);
        $adjustment=['month'=>'2026-09','type'=>'bonus','amount'=>'500','reason'=>'مكافأة أداء','request_key'=>'adjustment-september-001'];
        $id=$people->adjustment($actor,1,$adjustment);
        $this->assertSame($id,$people->adjustment($actor,1,$adjustment));
        $people->adjustment($actor,1,array_replace($adjustment,['type'=>'advance_repayment','amount'=>'200','request_key'=>'adjustment-september-002']));
        $people->attendance($actor,1,['day'=>'2026-09-30','status'=>'absent']);
        $this->assertSame(680000,$people->statement($actor,1,'2026-09')['net_minor']);
        $closed=$people->close($actor,1,'2026-09');
        $this->assertSame($closed,$people->close($actor,1,'2026-09'));
        $this->rejects(409,fn()=>$people->salary($actor,1,'2026-09','7000'));
        $this->rejects(409,fn()=>$people->attendance($actor,1,['day'=>'2026-09-30','status'=>'present']));
        $this->rejects(409,fn()=>$people->adjustment($actor,1,array_replace($adjustment,['request_key'=>'after-close-rejected-001'])));
        $people->salary($actor,1,'2026-10','7000');
        $this->assertSame(680000,(int)$people->statement($actor,1,'2026-09')['net_minor']);
        $this->assertSame(700000,$people->statement($actor,1,'2026-10')['base_minor']);
        $this->assertSame(1,DB::table('erp_payrolls')->count());
    }

    public function test_invalid_payroll_months_negative_net_and_future_attendance_are_rejected(): void
    {
        $people=new People;$actor=$this->actor();
        $this->rejects(422,fn()=>$people->month('2026-13'));
        $this->rejects(422,fn()=>$people->close($actor,1,'2026-10'));
        $this->rejects(422,fn()=>$people->attendance($actor,1,['day'=>'2026-10-01','status'=>'present']));
        $this->rejects(422,fn()=>$people->attendance($actor,1,['day'=>'2026-02-30','status'=>'present']));
        $people->adjustment($actor,1,['month'=>'2026-09','type'=>'deduction','amount'=>'7000','reason'=>'اختبار','request_key'=>'deduction-negative-net-001']);
        $this->rejects(422,fn()=>$people->close($actor,1,'2026-09'));
        $this->assertSame(0,DB::table('erp_payrolls')->count());
    }

    public function test_audit_does_not_leak_salary_or_accounts_to_inventory_staff(): void
    {
        (new People)->salary($this->actor(),1,'2026-09','7777.77');
        (new Actor('legacy:1','المالك','owner',null,Actor::CAPABILITIES))->audit('access.save','staff_account',1,['email'=>'private@example.test']);
        $staff=$this->staff(); $staff->permissions=['inventory.manage','audit.view']; $staff->save();
        $this->actingAs($staff,'erp');
        $this->get('/erp/audit')->assertOk()->assertDontSee('777777')->assertDontSee('private@example.test');
        $this->get('/erp/payroll')->assertForbidden();
        $this->get('/erp/employees')->assertForbidden();
    }

    public function test_branch_enrollment_and_new_employee_create_complete_related_records(): void
    {
        $this->actingAs($this->staff(),'erp');
        DB::table('resturants')->insert(['id'=>3,'name'=>'فرع جديد']);
        $this->post('/erp/branches',['restaurant_id'=>3,'name'=>'الفرع الثالث','active'=>'1'])->assertSessionHasNoErrors();
        $branch=DB::table('erp_branches')->where('restaurant_id',3)->first();
        $this->assertNotNull($branch);
        $this->assertSame(1,DB::table('erp_warehouses')->where('branch_id',$branch->id)->count());
        $this->post('/erp/employees',['name'=>'موظف جديد','job_title'=>'كاشير','branch_id'=>$branch->id,'hired_on'=>'2026-09-01','active'=>'1','salary'=>'4500'])->assertSessionHasNoErrors();
        $employee=DB::table('erp_employees')->where('name','موظف جديد')->first();
        $this->assertSame(450000,(int)DB::table('erp_salary_rates')->where('employee_id',$employee->id)->value('salary_minor'));
    }

    public function test_rollback_refuses_to_erase_business_history(): void
    {
        (new Stock)->post($this->actor(),$this->stock());
        try { (new \CreateErpFoundation)->down(); $this->fail('Rollback must preserve history'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('business history',$e->getMessage()); }
        $this->assertSame(1,DB::table('erp_stock_documents')->count());
    }

    public function test_post_requests_require_csrf_tokens_outside_the_test_bypass(): void
    {
        $this->app['env'] = 'local';
        $this->post('/erp/login',['email'=>'deputy@example.test','password'=>'wrong'])->assertStatus(419);
        $this->actingAs(LegacyOwner::findOrFail(1),'admin');
        $this->post('/erp/stock',$this->stock())->assertStatus(419);
        $this->assertSame(0,DB::table('erp_stock_documents')->count());
    }

    public function test_mysql_serializes_duplicate_receipts_and_competing_withdrawals(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Real row-lock contention requires MySQL and pcntl; covered in the MySQL CI job.');
        }
        $receipt=$this->stock();
        $results=$this->raceStock([$receipt,$receipt]);
        $this->assertSame(['ok','ok'],array_column($results,'status'));
        $this->assertSame($results[0]['id'],$results[1]['id']);
        $this->assertSame(1,DB::table('erp_stock_documents')->count());
        $this->assertSame(10000,(int)DB::table('erp_stock_balances')->value('quantity_milli'));
        $results=$this->raceStock([$this->stock(['type'=>'waste','quantity'=>'7']),$this->stock(['type'=>'waste','quantity'=>'7'])]);
        $statuses=array_column($results,'status');sort($statuses);
        $this->assertSame(['422','ok'],$statuses,json_encode($results));
        $this->assertSame(3000,(int)DB::table('erp_stock_balances')->value('quantity_milli'));
        $this->assertSame(60000,(int)DB::table('erp_stock_balances')->value('value_minor'));
        $this->assertSame(2,DB::table('erp_stock_documents')->count());
    }

    private function raceStock(array $requests): array
    {
        return $this->race(fn($request)=>(new Stock)->post($this->actor(),$request),$requests);
    }

}
