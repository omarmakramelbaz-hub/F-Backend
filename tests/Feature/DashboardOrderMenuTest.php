<?php

namespace Tests\Feature;

use App\Models\ResturantProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardOrderMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'filesystems.disks.public.url' => 'https://assets.example.test/storage',
            'filesystems.disks.products.url' => 'https://assets.example.test/products',
        ]);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00:00', 'Africa/Cairo'));

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'account_type', 'app_scope', 'status'] as $field) $t->string($field);
            $t->unsignedBigInteger('owner_resturant_id')->nullable();
            $t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->unsignedBigInteger('added_by')->nullable();
            $t->decimal('balance', 14, 2)->default(100);
            $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name'); $t->timestamps();
        });
        Schema::create('pending_vendors', function (Blueprint $t) {
            $t->id(); $t->string('profession_key'); $t->string('status');
        });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id');
            $t->string('product_name'); $t->decimal('product_price', 14, 2); $t->text('price');
            $t->text('product_description')->nullable(); $t->string('status'); $t->timestamps();
        });
        require_once base_path('database/migrations/2026_09_27_180000_create_go_store_catalog.php');
        (new \CreateGoStoreCatalog())->up();
        require_once base_path('vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub');
        (new \CreateMediaTable())->up();
        require_once base_path('database/migrations/2022_08_05_174522_create_permission_tables.php');
        (new \CreatePermissionTables())->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $list = Permission::create(['name' => 'order-list', 'guard_name' => 'admin']);
        $edit = Permission::create(['name' => 'resturant-edit', 'guard_name' => 'admin']);
        foreach ([[2, $list->id], [3, $list->id], [3, $edit->id]] as [$user, $permission]) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => $permission, 'model_type' => User::class, 'model_id' => $user,
            ]);
        }
        foreach ([
            [1, 'Root', 'admin', 'fasakhansta', null],
            [2, 'Orders administrator', 'admin', 'fasakhansta', null],
            [3, 'Menu administrator', 'admin', 'fasakhansta', null],
            [10, 'First branch', 'vendor', 'fasakhansta', 100],
            [11, 'Other branch', 'vendor', 'fasakhansta', 101],
            [20, 'Customer', 'user', 'go', null],
            [30, 'First Go owner', 'vendor', 'go_partner', null],
            [31, 'Other Go owner', 'vendor', 'go_partner', null],
        ] as [$id, $name, $type, $scope, $restaurant]) {
            DB::table('users')->insert([
                'id' => $id, 'name' => $name, 'account_type' => $type, 'app_scope' => $scope,
                'status' => 'accepted', 'owner_resturant_id' => $restaurant,
            ]);
        }
        DB::table('resturants')->insert([
            ['id' => 100, 'user_id' => 10, 'name' => 'فرع المنصورة'],
            ['id' => 101, 'user_id' => 11, 'name' => 'فرع المحلة'],
        ]);
        DB::table('go_stores')->insert([
            ['user_id' => 30, 'name' => 'متجر المدينة', 'kind' => 'supermarket', 'address' => 'First store address'],
            ['user_id' => 31, 'name' => 'متجر آخر', 'kind' => 'pharmacy', 'address' => 'Other store address'],
        ]);
        $this->restaurantProduct(1);
        $this->restaurantProduct(2, ['resturant_id' => 101, 'product_name' => 'صنف الفرع الآخر']);
        $this->restaurantProduct(3, ['product_name' => 'رنجة', 'product_price' => '80.25', 'status' => 'hide']);
        $this->goProduct(200);
        $this->goProduct(201, ['user_id' => 31, 'name' => 'منتج المتجر الآخر']);
        $this->goProduct(202, ['name' => 'زيت', 'available' => false, 'revision' => 1]);
        DB::table('media')->insert([
            'id' => 900, 'model_type' => ResturantProduct::class, 'model_id' => 1,
            'collection_name' => 'product_image', 'name' => 'Menu photo', 'file_name' => 'fish.jpg',
            'mime_type' => 'image/jpeg', 'disk' => 'products', 'conversions_disk' => 'products', 'size' => 100,
            'manipulations' => '[]', 'custom_properties' => '{}', 'generated_conversions' => '{"thumb":true}',
            'responsive_images' => '[]', 'order_column' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actor(int $id): User
    {
        return User::withoutGlobalScopes()->findOrFail($id);
    }

    private function restaurantProduct(int $id, array $extra = []): void
    {
        DB::table('resturant_products')->insert(array_replace([
            'id' => $id, 'resturant_id' => 100, 'product_name' => 'فسيخ نبروه', 'product_price' => '180.50',
            'price' => '{"extra_clean":"20.00","extra_combo":"35.50","extra_vacuim":"12.25"}',
            'product_description' => 'Description retained on availability changes', 'status' => 'show',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ], $extra));
    }

    private function goProduct(int $id, array $extra = []): void
    {
        DB::table('go_store_products')->insert(array_replace([
            'id' => $id, 'user_id' => 30, 'request_key' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'name' => 'أرز مصري', 'description' => 'Retained description', 'unit' => 'كيلو',
            'price_cents' => 8050, 'image_path' => 'go-stores/30/rice.jpg', 'available' => true,
            'options' => '[{"id":"00000000-0000-4000-8000-000000000001","label":"نصف كيلو","price_cents":4275}]',
            'revision' => 3, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ], $extra));
    }

    private function menu(string $branch, array $extra = [])
    {
        return $this->getJson(route('order-board.menu', array_merge(['branch' => $branch], $extra)));
    }

    private function toggle(string $kind, int $branch, int $product, array $payload)
    {
        return $this->postJson(route('order-board.menu.availability', [$kind, $branch, $product]), $payload);
    }

    private function stableProduct(string $table, int $id, array $mutable): array
    {
        return array_diff_key((array) DB::table($table)->find($id), array_fill_keys($mutable, true));
    }

    public function test_restaurant_owner_can_read_exact_prices_images_and_hidden_items_from_own_branch(): void
    {
        $this->actingAs($this->actor(10), 'admin');
        $data = $this->menu('f:100')->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('ready', true)->assertJsonPath('can_toggle', true)
            ->assertJsonPath('branch.value', 'f:100')->json();
        $items = collect($data['items'])->keyBy('id');
        $this->assertEqualsCanonicalizing([1, 3], $items->keys()->all());
        $this->assertSame('180.50', $items[1]['price']);
        $this->assertTrue($items[1]['available']);
        $this->assertFalse($items[3]['available']);
        $this->assertStringContainsString('fish', $items[1]['image_url']);
        $this->assertSame(2, $data['pagination']['total']);
    }

    public function test_restaurant_stop_and_resume_only_change_availability_without_altering_prices_or_media(): void
    {
        $this->actingAs($this->actor(10), 'admin');
        $before = $this->stableProduct('resturant_products', 1, ['status', 'updated_at']);
        $media = (array) DB::table('media')->find(900);
        $payload = ['available' => false, 'expected_available' => true, 'product_price' => 1,
            'price' => '{"extra_clean":0}', 'product_name' => 'Forged name', 'resturant_id' => 101];
        $this->toggle('f', 100, 1, $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame('hide', DB::table('resturant_products')->where('id', 1)->value('status'));
        $this->toggle('f', 100, 1, $payload)->assertOk();
        $this->toggle('f', 100, 1, ['available' => true, 'expected_available' => false])->assertOk();
        $this->assertSame('show', DB::table('resturant_products')->where('id', 1)->value('status'));
        $this->assertSame($before, $this->stableProduct('resturant_products', 1, ['status', 'updated_at']));
        $this->assertSame($media, (array) DB::table('media')->find(900));
        $this->toggle('f', 100, 1, ['available' => false, 'expected_available' => false])->assertStatus(409);
        $this->assertSame('show', DB::table('resturant_products')->where('id', 1)->value('status'));
    }

    public function test_go_owner_can_read_and_toggle_own_product_with_revision_and_preserved_options(): void
    {
        $this->actingAs($this->actor(30), 'admin');
        $data = $this->menu('gs:30')->assertOk()->assertJsonPath('ready', true)
            ->assertJsonPath('can_toggle', true)->json();
        $items = collect($data['items'])->keyBy('id');
        $this->assertEqualsCanonicalizing([200, 202], $items->keys()->all());
        $this->assertSame('80.50', $items[200]['price']);
        $this->assertSame('42.75', $items[200]['options'][0]['price']);
        $this->assertStringContainsString('rice.jpg', $items[200]['image_url']);
        $this->assertSame(3, $items[200]['revision']);
        $before = $this->stableProduct('go_store_products', 200, ['available', 'revision', 'updated_at']);
        $payload = ['available' => false, 'expected_available' => true, 'expected_revision' => 3,
            'price_cents' => 1, 'options' => [], 'image_path' => 'forged.jpg', 'user_id' => 31, 'name' => 'Forged name'];
        $this->toggle('gs', 30, 200, $payload)->assertOk();
        $this->assertFalse((bool) DB::table('go_store_products')->where('id', 200)->value('available'));
        $this->assertSame(4, (int) DB::table('go_store_products')->where('id', 200)->value('revision'));
        $this->toggle('gs', 30, 200, $payload)->assertOk();
        $this->assertSame(4, (int) DB::table('go_store_products')->where('id', 200)->value('revision'));
        $this->toggle('gs', 30, 200, ['available' => true, 'expected_available' => false, 'expected_revision' => 3])->assertStatus(409);
        $this->toggle('gs', 30, 200, ['available' => true, 'expected_available' => false, 'expected_revision' => 4])->assertOk();
        $this->assertTrue((bool) DB::table('go_store_products')->where('id', 200)->value('available'));
        $this->assertSame(5, (int) DB::table('go_store_products')->where('id', 200)->value('revision'));
        $this->assertSame($before, $this->stableProduct('go_store_products', 200, ['available', 'revision', 'updated_at']));
    }

    public function test_foreign_branches_and_forged_product_ids_are_rejected(): void
    {
        $fBefore = (array) DB::table('resturant_products')->find(2);
        $goBefore = (array) DB::table('go_store_products')->find(201);
        $this->actingAs($this->actor(10), 'admin');
        $this->menu('f:101')->assertNotFound();
        $this->menu('gs:31')->assertNotFound();
        $this->toggle('f', 101, 2, ['available' => false, 'expected_available' => true])->assertNotFound();
        $this->toggle('f', 100, 2, ['available' => false, 'expected_available' => true])->assertNotFound();
        $this->actingAs($this->actor(30), 'admin');
        $this->menu('gs:31')->assertNotFound();
        $this->menu('f:100')->assertNotFound();
        $payload = ['available' => false, 'expected_available' => true, 'expected_revision' => 3];
        $this->toggle('gs', 31, 201, $payload)->assertNotFound();
        $this->toggle('gs', 30, 201, $payload)->assertNotFound();
        $this->assertSame($fBefore, (array) DB::table('resturant_products')->find(2));
        $this->assertSame($goBefore, (array) DB::table('go_store_products')->find(201));
    }

    public function test_approved_delegate_store_can_manage_menu_but_an_invalid_owner_profile_cannot(): void
    {
        DB::table('pending_vendors')->insert(['id' => 70, 'profession_key' => 'store_owner', 'status' => 'accepted']);
        DB::table('users')->where('id', 30)->update(['account_type' => 'delegate', 'pending_vendor_id' => 70]);
        $this->actingAs($this->actor(30), 'admin');
        $this->menu('gs:30')->assertOk()->assertJsonPath('ready', true)->assertJsonPath('can_toggle', true);
        $this->toggle('gs', 30, 200, ['available' => false, 'expected_available' => true, 'expected_revision' => 3])->assertOk();
        $committed = (array) DB::table('go_store_products')->find(200);
        DB::table('pending_vendors')->where('id', 70)->update(['profession_key' => 'plumber']);
        $this->menu('gs:30')->assertNotFound();
        $this->toggle('gs', 30, 200, ['available' => true, 'expected_available' => false, 'expected_revision' => 4])->assertNotFound();
        DB::table('pending_vendors')->where('id', 70)->update(['profession_key' => 'store_owner']);
        DB::table('users')->where('id', 30)->update(['account_type' => 'user']);
        $this->actingAs($this->actor(30), 'admin');
        $this->menu('gs:30')->assertNotFound();
        $this->toggle('gs', 30, 200, ['available' => true, 'expected_available' => false, 'expected_revision' => 4])->assertNotFound();
        $this->assertSame($committed, (array) DB::table('go_store_products')->find(200));
    }

    public function test_admin_read_permission_does_not_grant_menu_write_permission(): void
    {
        $this->actingAs($this->actor(2), 'admin');
        $this->menu('f:100')->assertOk()->assertJsonPath('can_toggle', false);
        $this->menu('gs:30')->assertOk()->assertJsonPath('can_toggle', false);
        $this->toggle('f', 100, 1, ['available' => false, 'expected_available' => true])->assertForbidden();
        $this->toggle('gs', 30, 200, ['available' => false, 'expected_available' => true, 'expected_revision' => 3])->assertForbidden();
        $this->assertSame('show', DB::table('resturant_products')->where('id', 1)->value('status'));
        $this->assertTrue((bool) DB::table('go_store_products')->where('id', 200)->value('available'));
        $this->actingAs($this->actor(3), 'admin');
        $this->toggle('f', 100, 1, ['available' => false, 'expected_available' => true])->assertOk();
    }

    public function test_root_can_read_and_toggle_any_authorized_branch_or_store(): void
    {
        $this->actingAs($this->actor(1), 'admin');
        foreach (['f:100', 'f:101', 'gs:30', 'gs:31'] as $branch) {
            $this->menu($branch)->assertOk()->assertJsonPath('can_toggle', true)->assertJsonPath('branch.value', $branch);
        }
        $this->toggle('f', 101, 2, ['available' => false, 'expected_available' => true])->assertOk();
        $this->toggle('gs', 31, 201, ['available' => false, 'expected_available' => true, 'expected_revision' => 3])->assertOk();
        $this->assertSame('hide', DB::table('resturant_products')->where('id', 2)->value('status'));
        $this->assertFalse((bool) DB::table('go_store_products')->where('id', 201)->value('available'));
    }

    public function test_search_never_leaks_a_matching_item_from_another_owner(): void
    {
        $this->restaurantProduct(4, ['product_name' => 'فسيخ متكرر']);
        $this->restaurantProduct(5, ['resturant_id' => 101, 'product_name' => 'فسيخ متكرر']);
        $this->goProduct(203, ['name' => 'قهوة متكررة']);
        $this->goProduct(204, ['user_id' => 31, 'name' => 'قهوة متكررة']);
        $this->actingAs($this->actor(10), 'admin');
        $f = $this->menu('f:100', ['search' => 'متكرر'])->assertOk()->assertJsonPath('pagination.total', 1)->json('items');
        $this->assertSame([4], array_column($f, 'id'));
        $this->actingAs($this->actor(30), 'admin');
        $go = $this->menu('gs:30', ['search' => 'قهوة'])->assertOk()->assertJsonPath('pagination.total', 1)->json('items');
        $this->assertSame([203], array_column($go, 'id'));
    }

    public function test_pagination_reaches_all_items_without_duplicates_or_foreign_products(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->restaurantProduct(1000 + $i);
            $this->restaurantProduct(2000 + $i, ['resturant_id' => 101]);
            $this->goProduct(3000 + $i);
            $this->goProduct(4000 + $i, ['user_id' => 31]);
        }
        foreach ([[10, 'f:100'], [30, 'gs:30']] as [$owner, $branch]) {
            $this->actingAs($this->actor($owner), 'admin');
            $first = $this->menu($branch)->assertOk()->assertJsonPath('pagination.total', 52)->json();
            $ids = array_column($first['items'], 'id');
            $this->assertLessThan(52, $first['pagination']['per_page']);
            $this->assertNotEmpty($first['pagination']['next_url']);
            for ($page = 2; $page <= $first['pagination']['last_page']; $page++) {
                $data = $this->menu($branch, ['page' => $page])->assertOk()->assertJsonPath('pagination.total', 52)->json();
                $this->assertSame($page, $data['pagination']['page']);
                $ids = array_merge($ids, array_column($data['items'], 'id'));
            }
            $this->assertCount(52, $ids);
            $this->assertCount(52, array_unique($ids));
            $this->assertSame([], array_values(array_intersect($ids, range($owner === 10 ? 2001 : 4001, $owner === 10 ? 2050 : 4050))));
        }
    }

    public function test_missing_optional_go_products_table_returns_a_friendly_empty_menu(): void
    {
        Schema::drop('go_store_products');
        $this->actingAs($this->actor(30), 'admin');
        $data = $this->menu('gs:30')->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('ready', false)->assertJsonPath('items', [])->json();
        $this->assertNotEmpty($data['message']);
        $this->assertSame(0, $data['pagination']['total']);
    }

    public function test_toggles_require_current_state_and_go_revision(): void
    {
        $this->actingAs($this->actor(10), 'admin');
        $this->toggle('f', 100, 1, ['available' => false])->assertStatus(422);
        $this->toggle('f', 100, 1, ['available' => 'invalid', 'expected_available' => true])->assertStatus(422);
        $this->actingAs($this->actor(30), 'admin');
        $this->toggle('gs', 30, 200, ['available' => false, 'expected_available' => true])->assertStatus(422);
        $this->assertSame('show', DB::table('resturant_products')->where('id', 1)->value('status'));
        $this->assertSame(3, (int) DB::table('go_store_products')->where('id', 200)->value('revision'));
    }

    public function test_menu_endpoints_require_a_dashboard_account(): void
    {
        $this->get(route('order-board.menu', ['branch' => 'f:100']))->assertRedirect(url('admin/login'));
        $this->postJson(route('order-board.menu.availability', ['f', 100, 1]), [
            'available' => false, 'expected_available' => true,
        ])->assertRedirect(url('admin/login'));
        $this->actingAs($this->actor(20), 'admin');
        $this->menu('f:100')->assertForbidden();
        $this->toggle('f', 100, 1, ['available' => false, 'expected_available' => true])->assertForbidden();
    }
}
