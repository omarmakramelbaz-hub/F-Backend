<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Api\AuthRepository;
use App\Services\GoStores\Catalog;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GoStoreAdminCreationTest extends TestCase
{
    use \Tests\Support\CreatesOpeningWalletLedger;
    private $root;
    private $views;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:', 'cache.default'=>'array']);
        DB::purge('sqlite');
        Cache::flush();
        $this->createOpeningWalletLedger();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name','account_type','app_scope','status','mobile','email','password','partner_auth_email'] as $field) $t->string($field)->nullable();
            $t->unsignedBigInteger('added_by')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->decimal('balance', 12, 2)->default(0);
            $t->decimal('delegate_fees', 6, 2)->nullable();
            $t->timestamps();
            $t->unique(['mobile','account_type','app_scope']);
        });
        Schema::create('pending_vendors', function (Blueprint $t) {
            $t->id(); foreach (['mobile','status','application_kind','profession_key'] as $key) $t->string($key)->nullable();
        });
        require_once base_path('database/migrations/2026_09_27_180000_create_go_store_catalog.php');
        (new \CreateGoStoreCatalog())->up();
        require_once base_path('vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub');
        (new \CreateMediaTable())->up();
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['resturant-list','resturant-create','resturant-edit'] as $name) Permission::create(['name'=>$name, 'guard_name'=>'admin']);
        $this->root = User::create(['id'=>1,'name'=>'Root','account_type'=>'admin','app_scope'=>'fasakhansta','status'=>'accepted','balance'=>300]);
        $this->actingAs($this->root, 'admin');
        // Render the actual feature views with an isolated parent layout; the
        // global dashboard chrome depends on unrelated production settings.
        $this->views = sys_get_temp_dir().'/go-store-admin-'.bin2hex(random_bytes(6));
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

    private function payload(array $overrides = []): array
    {
        return array_replace(['owner_name'=>'صاحب المتجر', 'mobile'=>'01012345678', 'email'=>'owner@example.test',
            'password'=>'store-password-123', 'password_confirmation'=>'store-password-123',
            'name'=>'متجر المدينة', 'kind'=>'supermarket', 'address'=>'شارع النيل، القاهرة', 'commission_rate'=>'12.50'], $overrides);
    }

    public function test_add_button_create_route_and_form_are_available_to_authorized_admin(): void
    {
        $this->get('/admin/go-stores')->assertOk()->assertSee(route('go-stores.create'), false)->assertSee('إضافة متجر');
        $this->get('/admin/go-stores/create')->assertOk()->assertSee('name="password_confirmation"', false)
            ->assertSee('name="kind"', false)->assertSee('name="commission_rate"', false)->assertSee('حفظ وإضافة منتجات');
    }

    public function test_all_store_types_get_independent_login_opening_credit_and_saved_catalog_profile(): void
    {
        foreach (array_keys(Catalog::KINDS) as $index => $kind) {
            $mobile = '101234567'.$index;
            $response = $this->post('/admin/go-stores', $this->payload(['mobile'=>'+20 '.$mobile,'email'=>'OWNER'.$index.'@example.test','kind'=>$kind,
                'after_save'=>$index === 0 ? 'products' : null,
                'balance'=>5000,'account_type'=>'admin','app_scope'=>'fasakhansta','status'=>'pending','added_by'=>999,'partner_auth_email'=>'fake@example.test']))
                ->assertSessionHasNoErrors()->assertRedirect();
            $owner = User::withoutGlobalScopes()->where('mobile',$mobile)->firstOrFail();
            $response->assertRedirect(route($index === 0 ? 'go-stores.products.create' : 'go-stores.show', $owner->id));
            if ($index === 0) {
                $this->get(route('go-stores.products.create', $owner->id))->assertOk()
                    ->assertSee('تم إنشاء المتجر وحساب صاحبه.')->assertSee('name="image"', false)
                    ->assertSee(route('go-stores.products.store', $owner->id), false);
            }
            $this->assertSame('vendor', $owner->account_type);
            $this->assertSame('go_partner', $owner->app_scope);
            $this->assertSame('accepted', $owner->status);
            $this->assertSame(1, (int) $owner->added_by);
            $this->assertSame(50.0, $owner->balance);
            $this->assertSame(1, DB::table('wallets')->where('to_user', $owner->id)->count());
            $this->assertSame(12.5, (float) $owner->delegate_fees);
            $this->assertSame('owner'.$index.'@example.test', $owner->email);
            $this->assertNull($owner->email_verified_at);
            $this->assertNull($owner->partner_auth_email);
            $this->assertTrue(Hash::check('store-password-123', $owner->password));
            $this->assertSame($kind, app(Catalog::class)->store($owner->id)['kind']);
            $this->assertTrue(Catalog::isStore($owner));
            app()->instance('request', Request::create('/api/login','POST',[],[],[],['HTTP_X_APP_SCOPE'=>'go_partner']));
            $signedIn = app(AuthRepository::class)->login(['mobile'=>'0'.$mobile,'password'=>'store-password-123','account_type'=>'delegate']);
            $this->assertSame($owner->id, $signedIn->id);
        }
        $this->assertSame(300.0, $this->root->fresh()->balance);
        $this->assertSame(0, DB::table('model_has_roles')->count());
    }

    public function test_creation_requires_create_permission_and_never_grants_admin_access(): void
    {
        $manager = User::create(['name'=>'Manager','account_type'=>'admin','app_scope'=>'fasakhansta']);
        $manager->givePermissionTo(['resturant-list','resturant-edit']);
        $this->actingAs($manager, 'admin');
        $this->get('/admin/go-stores')->assertOk()->assertDontSee(route('go-stores.create'), false);
        $this->get('/admin/go-stores/create')->assertForbidden();
        $this->post('/admin/go-stores', $this->payload())->assertForbidden();
        $manager->givePermissionTo('resturant-create');
        $manager->revokePermissionTo('resturant-edit');
        $this->actingAs($manager->fresh(), 'admin');
        $this->get('/admin/go-stores/create')->assertOk()->assertDontSee('حفظ وإضافة منتجات');
        $response = $this->post('/admin/go-stores', $this->payload(['after_save'=>'products']))->assertRedirect()->assertSessionHasNoErrors();
        $owner = User::withoutGlobalScopes()->where('app_scope','go_partner')->firstOrFail();
        $response->assertRedirect(route('go-stores.show', $owner->id));
        $this->assertSame($manager->id, (int) $owner->added_by);
        $owner->givePermissionTo(['resturant-list','resturant-create']);
        $this->actingAs($owner, 'admin');
        $this->get('/admin/go-stores/create')->assertForbidden();
        $this->post('/admin/go-stores', $this->payload(['mobile'=>'01112345678']))->assertForbidden();
    }

    public function test_normalized_duplicate_partner_numbers_and_open_applications_are_rejected(): void
    {
        $this->post('/admin/go-stores', $this->payload(['mobile'=>'٠١٠١٢٣٤٥٦٧٨']))->assertRedirect()->assertSessionHasNoErrors();
        foreach (['01012345678','+20 (10) 1234-5678','00201012345678','1012345678'] as $mobile) {
            $this->post('/admin/go-stores', $this->payload(['mobile'=>$mobile]))->assertSessionHasErrors('mobile');
        }
        User::create(['name'=>'Professional','account_type'=>'delegate','app_scope'=>'go_partner','mobile'=>'1112345678']);
        $this->post('/admin/go-stores', $this->payload(['mobile'=>'01112345678']))->assertSessionHasErrors('mobile');
        foreach (['pending','accepted'] as $status) {
            DB::table('pending_vendors')->delete();
            DB::table('pending_vendors')->insert(['mobile'=>'1212345678','application_kind'=>'partner','status'=>$status]);
            $this->post('/admin/go-stores', $this->payload(['mobile'=>'01212345678']))->assertSessionHasErrors('mobile');
        }
        $this->assertSame(1, DB::table('go_stores')->count());
    }

    public function test_existing_f_account_is_preserved_and_email_collision_with_go_is_rejected(): void
    {
        $legacy = User::create(['name'=>'F store','account_type'=>'vendor','app_scope'=>'fasakhansta','mobile'=>'1012345678',
            'email'=>'owner@example.test','password'=>'legacy-password','balance'=>125]);
        $this->post('/admin/go-stores', $this->payload())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('legacy-password', $legacy->fresh()->password));
        $this->assertSame(125.0, $legacy->fresh()->balance);
        $this->post('/admin/go-stores', $this->payload(['mobile'=>'01512345678']))->assertSessionHasErrors('email');
        $this->assertSame(1, DB::table('go_stores')->count());
    }

    public function test_invalid_details_do_not_create_partial_accounts_or_flash_passwords(): void
    {
        foreach ([['commission_rate'=>101], ['commission_rate'=>-1], ['commission_rate'=>'1.234'], ['mobile'=>'broken01012345678'],
            ['kind'=>'unsupported'], ['owner_name'=>'  '], ['address'=>' '], ['password_confirmation'=>'different']] as $invalid) {
            $this->post('/admin/go-stores', $this->payload($invalid))->assertSessionHasErrors()
                ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        }
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('go_stores')->count());
    }

    public function test_store_failure_rolls_back_owner_account(): void
    {
        DB::statement("CREATE TRIGGER fail_catalog BEFORE INSERT ON go_stores BEGIN SELECT RAISE(ABORT, 'catalog fixture failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/go-stores', $this->payload());
            $this->fail('Expected catalog insert failure');
        } catch (QueryException $error) {
            $this->assertSame(1, DB::table('users')->count());
            $this->assertSame(0, DB::table('go_stores')->count());
            $this->assertSame(0, DB::table('wallets')->count());
        }
    }
}
