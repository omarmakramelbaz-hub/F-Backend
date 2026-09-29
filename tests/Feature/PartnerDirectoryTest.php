<?php

namespace Tests\Feature;

use App\Models\PendingVendor;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartnerDirectoryTest extends TestCase
{
    private $admin;
    private $views;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            foreach (['name', 'email', 'mobile', 'account_type', 'app_scope', 'status'] as $field) $table->string($field)->nullable();
            $table->unsignedBigInteger('pending_vendor_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pending_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('profession_key')->nullable();
            $table->string('source_app')->nullable();
        });
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up();
        require_once base_path('database/migrations/2026_09_27_180000_create_go_store_catalog.php');
        (new \CreateGoStoreCatalog())->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['delegate-list', 'delegate-edit', 'delegate-delete', 'vendor-list', 'vendor-edit', 'vendor-delete', 'resturant-list'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'admin']);
        }
        $this->admin = User::create(['id' => 1, 'name' => 'Admin', 'account_type' => 'admin', 'app_scope' => 'fasakhansta']);
        $this->admin->givePermissionTo(['delegate-list', 'delegate-edit', 'delegate-delete', 'vendor-list', 'vendor-edit', 'vendor-delete']);
        $this->actingAs($this->admin, 'admin');
        // Exercise the actual directory and store views without unrelated dashboard chrome.
        $this->views = sys_get_temp_dir().'/partner-directory-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->views.'/admin', 0755, true);
        File::put($this->views.'/admin/index.blade.php', '<main>@yield("content")</main>');
        $finder = app('view')->getFinder();
        $finder->setPaths([$this->views, resource_path('views')]);
        $finder->flush();
    }

    protected function tearDown(): void
    {
        if ($this->views) File::deleteDirectory($this->views);
        parent::tearDown();
    }

    private function account(array $values = []): User
    {
        return User::create(array_replace(['name' => 'GO Store Owner', 'account_type' => 'vendor',
            'app_scope' => 'go_partner', 'status' => 'accepted', 'created_at' => '2026-09-29 07:00:00'], $values));
    }

    public function test_directory_includes_approved_pending_and_direct_go_store_accounts(): void
    {
        $application = PendingVendor::create(['profession_key' => 'store_owner', 'source_app' => 'go']);
        $pending = $this->account(['name' => 'Approved Store Applicant', 'status' => 'pending', 'pending_vendor_id' => $application->id]);
        $direct = $this->account();
        $courier = $this->account(['name' => 'GO Courier', 'account_type' => 'delegate']);
        $legacy = $this->account(['name' => 'Legacy Courier', 'account_type' => 'delegate', 'app_scope' => 'fasakhansta']);
        $this->account(['name' => 'Legacy Vendor', 'app_scope' => 'fasakhansta']);
        $this->account(['name' => 'GO Customer', 'account_type' => 'user', 'app_scope' => 'go']);

        $response = $this->get('/admin/users?account_type=delegate')->assertOk()
            ->assertSee('Approved Store Applicant')->assertSee('GO Store Owner')->assertSee('GO Courier')
            ->assertSee('Legacy Courier')->assertDontSee('Legacy Vendor')->assertDontSee('GO Customer')
            ->assertSee('في انتظار تفعيل الحساب')->assertSee('متجر')->assertSee('إضافة شريك')
            ->assertSee(route('users.show', ['account_type' => 'vendor', $pending->id]), false)
            ->assertSee(url('admin/users/'.$pending->id.'/edit?account_type=vendor'), false)
            ->assertSee(route('go-stores.show', $pending->id), false)
            ->assertDontSee(url('admin/users/'.$pending->id.'/edit?account_type=delegate'), false)
            ->assertDontSee('data-id="'.$pending->id.'"', false)
            ->assertSee('data-id="'.$courier->id.'"', false);
        $this->assertSame([$legacy->id, $courier->id, $direct->id, $pending->id], $response->viewData('users')->pluck('id')->all());
        $this->assertSame('vendor', $pending->fresh()->account_type);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_search_and_dates_apply_to_both_account_types_without_leaking_other_accounts(): void
    {
        $expected = $this->account(['name' => 'Matching Store', 'mobile' => '1012345678', 'email' => 'store@example.test']);
        $this->account(['name' => 'Other Store']);
        $this->account(['name' => 'Matching Legacy Vendor', 'app_scope' => 'fasakhansta', 'email' => 'store@example.test']);
        $this->account(['name' => 'Matching Customer', 'account_type' => 'user', 'email' => 'store@example.test']);
        $this->account(['name' => 'Matching Old Courier', 'account_type' => 'delegate', 'created_at' => '2026-09-01 07:00:00']);
        $this->account(['name' => 'Matching Future Store', 'created_at' => '2026-10-01 07:00:00']);
        foreach (['Matching', 'store@example.test', '1012345678'] as $search) {
            $response = $this->get('/admin/users?'.http_build_query(['account_type' => 'delegate', 'search' => $search,
                'from_date' => '2026-09-29', 'to_date' => '2026-09-30']))->assertOk();
            $this->assertSame([$expected->id], $response->viewData('users')->pluck('id')->all());
        }
    }

    public function test_store_visibility_and_actions_require_the_existing_store_permissions(): void
    {
        $store = $this->account();
        $courier = $this->account(['name' => 'GO Courier', 'account_type' => 'delegate']);
        $staff = $this->account(['name' => 'Directory Staff', 'account_type' => 'admin', 'app_scope' => 'fasakhansta']);
        $staff->givePermissionTo(['delegate-list', 'delegate-edit', 'delegate-delete']);
        $this->actingAs($staff, 'admin');
        $this->get('/admin/users?account_type=delegate')->assertOk()->assertSee($courier->name)->assertDontSee($store->name);
        $staff->givePermissionTo('resturant-list');
        $this->get('/admin/users?account_type=delegate')->assertOk()->assertSee($store->name)
            ->assertSee(route('go-stores.show', $store->id), false)
            ->assertDontSee(route('users.show', ['account_type' => 'vendor', $store->id]), false)
            ->assertDontSee(url('admin/users/'.$store->id.'/edit?account_type=vendor'), false)
            ->assertDontSee('data-id="'.$store->id.'"', false)
            ->assertDontSee(route('users.destroy', ['account_type' => 'vendor', $store->id]), false);
        $staff->revokePermissionTo('resturant-list');
        $staff->givePermissionTo('vendor-list');
        $this->get('/admin/users?account_type=delegate')->assertOk()->assertSee($store->name)
            ->assertSee(route('users.show', ['account_type' => 'vendor', $store->id]), false)
            ->assertDontSee(route('go-stores.show', $store->id), false);
    }

    public function test_vendor_directory_keeps_its_existing_account_filter(): void
    {
        $go = $this->account();
        $legacy = $this->account(['name' => 'Legacy Vendor', 'app_scope' => 'fasakhansta']);
        $this->account(['name' => 'Courier', 'account_type' => 'delegate']);
        // Only the repository query is needed here; legacy vendor rows require restaurant fixtures.
        $request = \Illuminate\Http\Request::create('/admin/users', 'GET', ['account_type' => 'vendor']);
        $users = app(\App\Repositories\UserRepository::class)->getAllUsers($request);
        $this->assertSame([$legacy->id, $go->id], $users->pluck('id')->all());
    }

    public function test_store_list_explains_why_an_approved_account_is_not_public_yet(): void
    {
        $store = $this->account(['status' => 'pending']);
        $this->get('/admin/go-stores')->assertOk()->assertSee($store->name)
            ->assertSee('في انتظار تفعيل الحساب')->assertSee('أكمل تفعيل الحساب من جو بارتنر ليظهر المتجر للعملاء.');
    }
}
