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
        require_once database_path('migrations/2026_10_04_030000_create_branch_expenses.php'); (new \CreateBranchExpenses)->up();
        require_once database_path('migrations/2026_10_06_120000_create_branch_expenses_categories.php'); (new \CreateBranchExpensesCategories)->up();
        require_once database_path('migrations/2026_10_06_200000_manage_expense_categories.php'); (new \ManageExpenseCategories)->up();
        require_once database_path('migrations/2026_10_18_200000_scope_expense_categories_by_branch.php'); (new \ScopeExpenseCategoriesByBranch)->up();
        require_once database_path('migrations/2026_10_04_080000_create_branch_operations.php'); (new \CreateBranchOperations)->up();
        require_once database_path('migrations/2026_10_06_140000_add_employee_wallet_phone.php'); (new \AddEmployeeWalletPhone)->up();
        require_once database_path('migrations/2026_10_08_190000_add_employee_attendance_rules.php'); (new \AddEmployeeAttendanceRules)->up();
        require_once database_path('migrations/2026_10_18_160000_add_employee_part_time_schedule.php'); (new \AddEmployeePartTimeSchedule)->up();
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

    public function test_the_menu_carries_the_cashier_pages_own_product_cards_and_todays_totals(): void
    {
        DB::table('resturant_products')->insert(['id' => 4, 'resturant_id' => 100, 'product_id' => 504, 'category_id' => 1,
            'product_name' => 'صنف مخفي', 'product_price' => '10.00', 'status' => 'hide', 'price' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $branch = $this->sync($device, ['want_catalogs' => true])->assertOk()->json()['branches'][0];
        $page = $this->actingAs(User::find(4), 'admin')->getJson('/admin/takeaway/catalog?branch=f:100&per_page=100')->assertOk()->json();
        $cards = array_map(function ($card) { unset($card['stock']); return $card; }, $page['items']);
        $this->assertSame($cards, $branch['catalog']['listing'], 'The same cards the cashier page shows, in the same order');
        $this->assertFalse(collect($branch['catalog']['listing'])->firstWhere('id', 4)['available'], 'Hidden items stay visible as unavailable');
        $this->assertSame([1, 2], array_column($branch['catalog']['products'], 'id'), 'Only sellable items are priced');
        $this->assertSame(['date' => '2026-10-30', 'count' => 0, 'total' => '0.00'],
            array_intersect_key($branch['today'], array_flip(['date', 'count', 'total'])));

        $snapshot = $branch['snapshot_id'];
        $this->sync($device, ['operations' => [$this->sale($snapshot)]])->assertOk();
        $again = $this->sync($device, ['want_catalogs' => true, 'catalog_hashes' => ['f:100' => $branch['hash']]])->assertOk()->json()['branches'][0];
        $this->assertSame(1, $again['today']['count']);
        $this->assertSame('271.00', $again['today']['total']);
        $this->assertArrayNotHasKey('catalog', $again, 'A sale does not change the stored menu version');
    }

    public function test_a_sale_the_cashier_page_already_saved_online_is_not_sold_again(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $snapshot = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0]['snapshot_id'];
        $cashier = User::find(4);
        $items = [['product_id' => 1, 'quantity' => '1.500', 'quantity_mode' => 'weight']];
        $quote = $this->actingAs($cashier, 'admin')->postJson('/admin/takeaway/quote', ['branch' => 'f:100', 'items' => $items])->assertOk()->json();
        $key = (string) Str::uuid();
        // The page's checkout reached the dashboard, then the connection dropped before the answer came back.
        $this->actingAs($cashier, 'admin')->postJson('/admin/takeaway/checkout', ['branch' => 'f:100', 'items' => $items,
            'quote_hash' => $quote['quote_hash'], 'idempotency_key' => $key, 'payment_method' => 'cash', 'cash_received' => '200.00',
            'customer_phone' => '01012345678', 'service_consent' => true])->assertOk();
        $this->assertSame(1, DB::table('takeaway_orders')->count());
        // The device then saved the same invoice offline under the page's operation number.
        $operation = $this->sale($snapshot, ['id' => $key, 'data' => ['items' => [['product_id' => 1, 'option_id' => '', 'quantity_mode' => 'weight', 'quantity' => '1.500']],
            'cash_received' => '200.00', 'total_cents' => 18000]]);
        $operation['data']['items'] = [['product_id' => 1, 'option_id' => '', 'quantity_mode' => 'weight', 'quantity' => '1.500']];
        $result = $this->sync($device, ['operations' => [$operation]])->assertOk()->json()['results'][0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($result['receipt']['already_saved']);
        $this->assertSame((int) DB::table('takeaway_orders')->value('id'), $result['receipt']['id']);
        $this->assertSame(1, DB::table('takeaway_orders')->count(), 'Sold once');
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

    private function operation(string $kind, int $actor, array $data, array $overrides = []): array
    {
        return array_replace(['id' => (string) Str::uuid(), 'kind' => $kind, 'actor_id' => $actor, 'branch' => 'f:100',
            'occurred_at' => '2026-10-30T09:30:00Z', 'data' => $data], $overrides);
    }

    public function test_devices_receive_expense_categories_and_who_may_approve_expenses(): void
    {
        $device = $this->enroll('u1@example.test', 'root-pass', ['f:100'])->json();
        $state = $this->sync($device, ['want_users' => true, 'want_catalogs' => true])->assertOk()->json();
        $users = collect($state['users'])->keyBy('id');
        $this->assertTrue($users[1]['can_approve_expenses']);
        $this->assertFalse($users[4]['can_approve_expenses']);
        $this->assertTrue($users[1]['can_close_shift']);
        $this->assertFalse($users[4]['can_close_shift'], 'Only the owner and the central administration close shifts');
        $categories = collect($state['branches'][0]['catalog']['expense_categories'])->keyBy('key');
        $this->assertTrue($categories->has('electricity'));
        $this->assertNull($categories['electricity']['stock']);
        $this->assertSame('purchase_tomato', $categories['purchase_tomato']['stock']);
    }

    public function test_an_offline_expense_is_recorded_once_on_the_day_it_was_paid(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $pending = $this->operation('expense', 4, ['category' => 'electricity', 'description' => 'فاتورة كهرباء', 'amount' => '350.50']);
        $denied = $this->operation('expense', 4, ['category' => 'gas', 'amount' => '20', 'approve' => true]);
        $results = $this->sync($device, ['operations' => [$pending, $denied]])->assertOk()->json()['results'];
        $this->assertSame('applied', $results[0]['status'], json_encode($results[0], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pending', $results[0]['expense']['status']);
        $this->assertSame('rejected', $results[1]['status'], 'A cashier cannot approve its own expense');
        $row = DB::table('branch_expenses')->first();
        $this->assertSame(35050, (int) $row->amount_cents);
        $this->assertSame('2026-10-30', $row->occurred_on);
        $this->assertSame(4, (int) $row->actor_id);
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$pending]])->json()['results'][0], 'A resend returns the same result');
        $this->assertSame(1, DB::table('branch_expenses')->count());
    }

    public function test_an_expense_the_expenses_page_already_saved_online_is_not_recorded_again(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $key = (string) Str::uuid();
        // The page's save reached the dashboard, then the connection dropped before the answer came back.
        $this->actingAs(User::find(4), 'admin')->postJson('/admin/branch-expenses/save', ['branch' => 'f:100', 'idempotency_key' => $key,
            'occurred_on' => '2026-10-30', 'category' => 'electricity', 'description' => 'فاتورة كهرباء', 'amount' => '350.50',
            'payment_method' => 'cash'])->assertOk();
        $this->assertSame(1, DB::table('branch_expenses')->count());
        // The device then saved the same expense offline under the page's operation number.
        $operation = $this->operation('expense', 4, ['category' => 'electricity', 'description' => 'فاتورة كهرباء', 'amount' => '350.50'], ['id' => $key]);
        $result = $this->sync($device, ['operations' => [$operation]])->assertOk()->json()['results'][0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($result['expense']['already_saved']);
        $this->assertSame((int) DB::table('branch_expenses')->value('id'), $result['expense']['id']);
        $this->assertSame(1, DB::table('branch_expenses')->count(), 'Recorded once');
    }

    public function test_an_owner_expense_can_be_approved_and_leaves_the_drawer(): void
    {
        $device = $this->enroll('u1@example.test', 'root-pass', ['f:100'])->json();
        $approved = $this->operation('expense', 1, ['category' => 'packaging', 'amount' => '75', 'approve' => true, 'supplier' => 'مورد']);
        $stock = $this->operation('expense', 1, ['category' => 'purchase_tomato', 'amount' => '60']);
        $results = $this->sync($device, ['operations' => [$approved, $stock]])->assertOk()->json()['results'];
        $this->assertSame('approved', $results[0]['expense']['status'], json_encode($results[0], JSON_UNESCAPED_UNICODE));
        $this->assertSame(-7500, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'));
        $this->assertSame('rejected', $results[1]['status'], 'A stock purchase needs its quantity in kilograms');
    }

    public function test_offline_cash_in_and_out_apply_on_top_of_the_current_drawer(): void
    {
        $device = $this->enroll('u1@example.test', 'root-pass', ['f:100'])->json();
        DB::table('takeaway_tills')->insert(['branch' => 'f:100', 'balance_cents' => 10000, 'tax_bps' => 0, 'revision' => 7,
            'created_at' => now(), 'updated_at' => now()]);
        $in = $this->operation('till', 1, ['direction' => 'in', 'amount' => '500', 'note' => 'فكة']);
        $out = $this->operation('till', 1, ['direction' => 'out', 'amount' => '200', 'note' => 'توريد للمالك']);
        $tooMuch = $this->operation('till', 1, ['direction' => 'out', 'amount' => '10000', 'note' => 'سحب']);
        $cashier = $this->operation('till', 4, ['direction' => 'in', 'amount' => '5', 'note' => 'x']);
        $noNote = $this->operation('till', 1, ['direction' => 'in', 'amount' => '5', 'note' => '']);
        $results = $this->sync($device, ['operations' => [$in, $out, $tooMuch, $cashier, $noNote]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'applied', 'rejected', 'rejected', 'rejected'], array_column($results, 'status'),
            json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(40000, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'));
        $this->assertSame(['cash_in', 'cash_out'], DB::table('takeaway_till_entries')->orderBy('id')->pluck('kind')->all());
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$in]])->json()['results'][0]);
        $this->assertSame(2, DB::table('takeaway_till_entries')->count());
    }

    public function test_operations_this_server_does_not_know_yet_wait_on_the_device(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $response = $this->sync($device, ['operations' => [$this->operation('stock_count', 4, ['items' => []])]])->assertOk()->json();
        $this->assertSame('retry', $response['results'][0]['status']);
        $this->assertSame(['sale', 'expense', 'till', 'attendance'], $response['kinds']);
        $this->assertSame(0, DB::table('desktop_sync_operations')->count(), 'Nothing is stored, so it is accepted once the server knows it');
    }

    public function test_a_delivery_order_keeps_the_delivery_company_and_driver(): void
    {
        DB::table('branch_delivery_companies')->insert(['id' => 7, 'branch' => 'f:100', 'name' => 'شركة سريع', 'phone' => '01011112222',
            'active' => true, 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $branch = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0];
        $this->assertSame([['id' => 7, 'name' => 'شركة سريع', 'phone' => '01011112222']], $branch['catalog']['delivery_companies']);
        DB::table('branch_delivery_companies')->update(['active' => false]); // stopped after the order left
        $sale = $this->sale($branch['snapshot_id'], ['data' => ['channel' => 'phone', 'address' => 'المعادي', 'delivery_company_id' => 7,
            'driver' => 'حسن', 'delivery_cents' => 2000, 'total_cents' => 29100, 'cash_received' => '300.00']]);
        $result = $this->sync($device, ['operations' => [$sale]])->assertOk()->json()['results'][0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $context = json_decode(DB::table('takeaway_orders')->value('context_snapshot'), true);
        $this->assertSame(['id' => 7, 'name' => 'شركة سريع', 'phone' => '01011112222'], $context['delivery_company']);
        $this->assertSame('حسن', $context['driver']);
        // The dashboard's own invoice page shows it (it needs the company's phone).
        $id = (int) DB::table('takeaway_orders')->value('id');
        $page = $this->actingAs(User::find(1), 'admin')->get('/admin/takeaway/receipts/'.$id.'/details')->assertOk()->getContent();
        $this->assertStringContainsString('شركة سريع', $page);
        $this->assertStringContainsString('01011112222', $page);
    }

    private function attendanceFixture(): void
    {
        foreach ([['morning', '10:00', '18:00'], ['evening', '20:00', '04:00']] as [$shift, $start, $end]) {
            DB::table('branch_attendance_rules')->insert(['branch' => 'f:100', 'shift' => $shift, 'starts_at' => $start, 'ends_at' => $end,
                'late_half_hour_cents' => 1000, 'early_half_hour_cents' => 500, 'absence_cents' => 30000, 'revision' => 1, 'actor_id' => 1,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[50, 'سعيد'], [51, 'كريم'], [52, 'هاني']] as [$id, $name]) {
            DB::table('branch_employees')->insert(['id' => $id, 'branch' => 'f:100', 'name' => $name, 'job_title' => 'كاشير', 'shift' => 'صباحي',
                'hired_on' => '2026-01-01', 'active' => true, 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function automaticAbsence(int $employee, bool $automatic = true): void
    {
        DB::table('branch_employee_days')->insert(['branch' => 'f:100', 'employee_id' => $employee, 'day' => '2026-10-30', 'status' => 'unauthorized_absence',
            'attendance_rule_snapshot' => json_encode($automatic ? ['automatically_recorded' => true, 'automatic_absence_deadline' => '2026-10-30 20:00:00'] : []),
            'notes' => '', 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_employee_entries')->insert(['branch' => 'f:100', 'employee_id' => $employee, 'day' => '2026-10-30', 'kind' => 'deduction',
            'source_key' => 'attendance.absence', 'amount_cents' => 30000, 'reason' => 'غياب بدون إذن', 'notes' => '', 'revision' => 1, 'actor_id' => 1,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function deductions(int $employee): int
    {
        return (int) DB::table('branch_employee_entries')->where('employee_id', $employee)->whereNull('voided_at')->sum('amount_cents');
    }

    public function test_devices_receive_employees_shift_hours_and_todays_attendance(): void
    {
        $this->attendanceFixture();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $branch = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0];
        $this->assertSame(['سعيد', 'كريم', 'هاني'], array_column($branch['catalog']['employees'], 'name'));
        $this->assertSame([['shift' => 'evening', 'starts_at' => '20:00', 'ends_at' => '04:00'], ['shift' => 'morning', 'starts_at' => '10:00', 'ends_at' => '18:00']],
            $branch['catalog']['attendance_shifts']);
        $this->assertSame([], $branch['attendance']);
    }

    public function test_punches_use_the_time_the_device_recorded_with_the_dashboard_deductions(): void
    {
        $this->attendanceFixture();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $in = $this->operation('attendance', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in'],
            ['occurred_at' => '2026-10-30T08:40:00Z']); // 10:40 in Cairo: 40 minutes late, one completed half hour
        $out = $this->operation('attendance', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_out'],
            ['occurred_at' => '2026-10-30T11:50:00Z']); // 13:50: four hours and ten minutes early, eight half hours
        $results = $this->sync($device, ['operations' => [$in, $out]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('2026-10-30T08:40:00+00:00', $results[0]['attendance']['checked_in_at']);
        $day = DB::table('branch_employee_days')->where('employee_id', 50)->first();
        $this->assertSame(['10:40:00', '13:50:00'], [$day->check_in, $day->check_out]);
        $this->assertSame(1000 + 4000, $this->deductions(50));
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$in]])->json()['results'][0]);
        $this->assertSame(1, DB::table('branch_employee_days')->count());
    }

    public function test_a_punch_the_employees_page_already_saved_online_is_not_recorded_again_and_notes_go_up(): void
    {
        $this->attendanceFixture();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $key = (string) Str::uuid();
        // The page's punch reached the dashboard, then the connection dropped before the answer came back.
        $this->actingAs(User::find(4), 'admin')->postJson('/admin/employees/attendance', ['branch' => 'f:100', 'idempotency_key' => $key,
            'employee_id' => 50, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in'])->assertOk();
        $before = (array) DB::table('branch_employee_days')->where('employee_id', 50)->first();
        // The device then saved the same punch offline under the page's operation number.
        $same = $this->operation('attendance', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in'], ['id' => $key]);
        $result = $this->sync($device, ['operations' => [$same]])->assertOk()->json()['results'][0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertEquals($before, (array) DB::table('branch_employee_days')->where('employee_id', 50)->first(), 'Recorded once, unchanged');
        // A punch made on the dashboard's page offline keeps the day's notes it was saved with.
        $noted = $this->operation('attendance', 4, ['employee_id' => 51, 'day' => '2026-10-30', 'status' => 'evening', 'action' => 'check_in',
            'notes' => 'جه بدري يساعد في الجرد']);
        $this->assertSame('applied', $this->sync($device, ['operations' => [$noted]])->json()['results'][0]['status']);
        $this->assertSame('جه بدري يساعد في الجرد', DB::table('branch_employee_days')->where('employee_id', 51)->value('notes'));
    }

    public function test_an_automatic_absence_gives_way_to_a_punch_recorded_before_the_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 23:30:00', 'Africa/Cairo'));
        $this->attendanceFixture();
        $this->automaticAbsence(50);
        $this->automaticAbsence(51);
        $this->automaticAbsence(52, false); // recorded by a person
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $results = $this->sync($device, ['operations' => [
            $this->operation('attendance', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in'], ['occurred_at' => '2026-10-30T08:00:00Z']),
            $this->operation('attendance', 4, ['employee_id' => 51, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in'], ['occurred_at' => '2026-10-30T20:30:00Z']),
            $this->operation('attendance', 4, ['employee_id' => 52, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in'], ['occurred_at' => '2026-10-30T08:00:00Z']),
        ]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'rejected', 'rejected'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('morning', DB::table('branch_employee_days')->where('employee_id', 50)->value('status'));
        $this->assertSame(0, $this->deductions(50), 'The automatic absence deduction is cancelled');
        $this->assertSame(30000, $this->deductions(51), 'A punch after the deadline does not undo the absence');
        $this->assertSame(30000, $this->deductions(52), 'An absence recorded by a person stays');
    }

    public function test_the_dashboard_itself_still_refuses_device_time_and_leave_from_the_program(): void
    {
        $this->attendanceFixture();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $leave = $this->operation('attendance', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'preapproved_leave', 'action' => 'set_status']);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$leave]])->json()['results'][0]['status']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Dashboard\BranchPayroll::class)->attendance(['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(), 'employee_id' => 50,
            'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in', 'checked_in_at' => '2026-10-30 06:00:00'], User::find(4));
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
