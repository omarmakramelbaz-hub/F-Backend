<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The GO ERP desktop app's server side: device setup, offline logins data, menu versions and offline sales. */
class DesktopSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32)), 'app.timezone' => 'Africa/Cairo',
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::clearResolvedInstance('db.schema');
        $settings = [];
        foreach ((new \ReflectionClass(\App\Models\GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $settings[$property->getName()] = (string) $property->getType() === 'bool' ? false : '';
        }
        $settings['site_name'] = 'فسخانستا';
        $settings['service_fees'] = '0';
        $this->app->instance(\App\Models\GeneralSettings::class, new \App\Models\GeneralSettings($settings));
        Carbon::setTestNow(Carbon::parse('2026-10-30 14:00:00', 'Africa/Cairo'));
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'account_type', 'app_scope', 'status'] as $field) $t->string($field);
            $t->string('mobile')->nullable(); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->unsignedBigInteger('pending_vendor_id')->nullable();
            $t->decimal('balance', 14, 2)->default(0); $t->timestamps();
        });
        Schema::create('resturants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('name'); $t->string('address')->default('عنوان الفرع'); $t->string('phone')->default('01000000000');
            $t->decimal('service_fees', 6, 2)->default(0); $t->timestamps();
        });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name_ar'); $t->string('name_en'); });
        Schema::create('resturant_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id'); $t->string('product_name'); $t->decimal('product_price', 14, 2);
            $t->text('price'); $t->string('status'); $t->timestamps();
        });
        Schema::create('product_features', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->string('name'); });
        Schema::create('pending_vendors', function (Blueprint $t) { $t->id(); $t->string('profession_key'); $t->string('status'); });
        Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('group'); $t->string('name'); $t->text('payload'); });
        require_once database_path('migrations/2026_09_27_180000_create_go_store_catalog.php'); (new \CreateGoStoreCatalog)->up();
        require_once database_path('migrations/2026_10_03_140000_create_takeaway_pos.php'); (new \CreateTakeawayPos)->up();
        require_once database_path('migrations/2026_10_03_150000_create_pos_service_tickets.php'); (new \CreatePosServiceTickets)->up();
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php'); (new \CreatePermissionTables)->up();
        (require database_path('migrations/2026_10_30_000001_create_desktop_sync_tables.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['order-list', 'order-create', 'order-edit'] as $name) Permission::create(['name' => $name, 'guard_name' => 'admin']);
        foreach ([
            [1, 'المالك', 'admin', null, '1000000001', 'root-pass'],
            [4, 'كاشير الفرع', 'admin', 100, '1000000004', 'cashier-pass'],
            [5, 'كاشير فرع آخر', 'admin', 101, '1000000005', 'other-pass'],
            [10, 'صاحب المطعم', 'vendor', null, '1000000010', 'vendor-pass'],
            [20, 'عميل', 'user', null, '1000000020', 'customer-pass'],
        ] as [$id, $name, $type, $branch, $mobile, $password]) {
            DB::table('users')->insert(['id' => $id, 'name' => $name, 'account_type' => $type, 'app_scope' => 'fasakhansta',
                'status' => 'accepted', 'owner_resturant_id' => $branch, 'mobile' => $mobile, 'email' => 'u'.$id.'@example.test',
                'password' => Hash::make($password)]);
        }
        foreach ([[100, 10], [101, 10]] as [$id, $owner]) DB::table('resturants')->insert(['id' => $id, 'user_id' => $owner, 'name' => 'فرع '.$id]);
        DB::table('categories')->insert(['id' => 1, 'name_ar' => 'أسماك', 'name_en' => 'Fish']);
        foreach ([[1, 100, '120.00'], [2, 100, '45.50'], [3, 101, '90.00']] as [$id, $branch, $price]) {
            DB::table('resturant_products')->insert(['id' => $id, 'resturant_id' => $branch, 'product_id' => 500 + $id, 'category_id' => 1,
                'product_name' => 'صنف '.$id, 'product_price' => $price, 'status' => 'show', 'price' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enroll(string $login, string $password, ?array $branches = null)
    {
        return $this->postJson('/api/desktop-sync/v1/enroll', ['login' => $login, 'password' => $password,
            'device_name' => 'كاشير 1'] + ($branches === null ? [] : ['branches' => $branches]));
    }

    private function sync(array $device, array $body)
    {
        return $this->withHeaders(['X-GO-ERP-Device' => $device['device']['id'], 'Authorization' => 'Bearer '.$device['token']])
            ->postJson('/api/desktop-sync/v1/sync', $body);
    }

    private function sale(string $snapshot, array $overrides = []): array
    {
        return array_replace_recursive(['id' => (string) Str::uuid(), 'kind' => 'sale', 'actor_id' => 4, 'branch' => 'f:100',
            'occurred_at' => '2026-10-30T09:30:00Z', 'data' => [
                'snapshot_id' => $snapshot, 'channel' => 'takeaway', 'customer_phone' => '01012345678', 'customer_name' => 'أحمد',
                'items' => [['product_id' => 1, 'option_id' => '', 'quantity_mode' => 'weight', 'quantity' => '1.500'],
                    ['product_id' => 2, 'option_id' => '', 'quantity_mode' => 'piece', 'quantity' => '2']],
                'delivery_cents' => 0, 'cash_received' => '300.00', 'total_cents' => 27100, 'local_number' => 'D1-0001',
            ]], $overrides);
    }

    public function test_wrong_password_and_customer_accounts_cannot_set_up_a_device(): void
    {
        $this->enroll('01000000004', 'wrong')->assertStatus(401);
        $this->enroll('01000000020', 'customer-pass')->assertStatus(401);
        $this->assertSame(0, DB::table('desktop_sync_devices')->count());
    }

    public function test_a_branch_cashier_sets_up_its_own_branch_and_the_owner_chooses_branches(): void
    {
        $cashier = $this->enroll('01000000004', 'cashier-pass')->assertOk()->json();
        $this->assertFalse($cashier['choose_branches']);
        $this->assertSame(['f:100'], array_column($cashier['device']['branches'], 'value'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cashier['token']);
        $this->assertNotSame($cashier['token'], DB::table('desktop_sync_devices')->value('token_hash'), 'Only the token hash is stored');

        $choice = $this->enroll('u1@example.test', 'root-pass')->assertOk()->json();
        $this->assertTrue($choice['choose_branches']);
        $this->assertSame(['f:100', 'f:101'], array_column($choice['branches'], 'value'));
        $owner = $this->enroll('u1@example.test', 'root-pass', ['f:101'])->assertOk()->json();
        $this->assertSame(['f:101'], array_column($owner['device']['branches'], 'value'));
        $this->enroll('u1@example.test', 'root-pass', ['f:999'])->assertStatus(422);
    }

    public function test_the_device_receives_branch_accounts_with_password_hashes_and_a_menu_version(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $state = $this->sync($device, ['want_users' => true, 'want_catalogs' => true])->assertOk()->json();
        $users = collect($state['users'])->keyBy('id');
        $this->assertEqualsCanonicalizing([1, 4, 10], $users->keys()->all(), 'Owner, branch cashier and branch vendor; not the other branch cashier or customers');
        $this->assertTrue(Hash::check('cashier-pass', $users[4]['password_hash']));
        $this->assertSame(['f:100'], $users[4]['branches']);
        $this->assertTrue($users[4]['can_checkout']);
        $branch = $state['branches'][0];
        $this->assertSame('f:100', $branch['value']);
        $this->assertSame([1, 2], array_column($branch['catalog']['products'], 'id'));
        $this->assertSame(12000, $branch['catalog']['products'][0]['variants'][0]['unit_price_cents']);

        $again = $this->sync($device, ['want_users' => true, 'users_hash' => $state['users_hash'], 'want_catalogs' => true,
            'catalog_hashes' => ['f:100' => $branch['hash']]])->assertOk()->json();
        $this->assertArrayNotHasKey('users', $again, 'Unchanged accounts are not resent');
        $this->assertArrayNotHasKey('catalog', $again['branches'][0], 'An unchanged menu is not resent');
        $this->assertSame($branch['snapshot_id'], $again['branches'][0]['snapshot_id']);
    }

    public function test_an_offline_sale_is_recorded_once_with_the_menu_prices_it_was_sold_at(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $snapshot = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0]['snapshot_id'];
        // The price changes on the server after the device downloaded its menu.
        DB::table('resturant_products')->where('id', 1)->update(['product_price' => '150.00']);
        $operation = $this->sale($snapshot);
        $result = $this->sync($device, ['operations' => [$operation]])->assertOk()->json()['results'][0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $order = DB::table('takeaway_orders')->first();
        $this->assertSame(27100, (int) $order->total_cents);
        $this->assertSame($operation['id'], $order->request_key);
        $this->assertSame('2026-10-30', $order->business_date);
        $this->assertSame(4, (int) $order->actor_id);
        $this->assertSame(2, DB::table('takeaway_order_items')->count());

        $resent = $this->sync($device, ['operations' => [$operation]])->assertOk()->json()['results'][0];
        $this->assertSame($result, $resent, 'A resend returns the same result');
        $this->assertSame(1, DB::table('takeaway_orders')->count());
    }

    public function test_tampered_totals_foreign_branches_and_unknown_menus_are_rejected(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $snapshot = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0]['snapshot_id'];
        $results = $this->sync($device, ['operations' => [
            $this->sale($snapshot, ['data' => ['total_cents' => 100]]),
            $this->sale($snapshot, ['branch' => 'f:101']),
            $this->sale((string) Str::uuid()),
            $this->sale($snapshot, ['actor_id' => 5]),
            $this->sale($snapshot, ['data' => ['customer_phone' => '123']]),
        ]])->assertOk()->json()['results'];
        $this->assertSame(['rejected', 'rejected', 'rejected', 'rejected', 'rejected'], array_column($results, 'status'));
        $this->assertStringContainsString('لا يطابق', $results[0]['error']);
        $this->assertSame(0, DB::table('takeaway_orders')->count());
    }

    public function test_a_revoked_device_and_a_wrong_token_are_refused(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $this->withHeaders(['X-GO-ERP-Device' => $device['device']['id'], 'Authorization' => 'Bearer '.str_repeat('a', 64)])
            ->postJson('/api/desktop-sync/v1/sync', [])->assertStatus(401);
        DB::table('desktop_sync_devices')->update(['revoked_at' => now()]);
        $this->sync($device, [])->assertStatus(401);
    }
}
