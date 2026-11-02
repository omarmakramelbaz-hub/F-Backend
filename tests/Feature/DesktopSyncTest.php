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
        require_once database_path('migrations/2026_10_04_060000_lock_pos_service_bills.php'); (new \LockPosServiceBills)->up();
        require_once database_path('migrations/2026_10_18_210000_create_shift_report_printing_and_numbers.php'); (new \CreateShiftReportPrintingAndNumbers)->up();
        require_once database_path('migrations/2022_08_05_174522_create_permission_tables.php'); (new \CreatePermissionTables)->up();
        (require database_path('migrations/2026_10_30_000001_create_desktop_sync_tables.php'))->up();
        require_once database_path('migrations/2026_10_04_030000_create_branch_expenses.php'); (new \CreateBranchExpenses)->up();
        require_once database_path('migrations/2026_10_06_120000_create_branch_expenses_categories.php'); (new \CreateBranchExpensesCategories)->up();
        require_once database_path('migrations/2026_10_06_200000_manage_expense_categories.php'); (new \ManageExpenseCategories)->up();
        require_once database_path('migrations/2026_10_18_200000_scope_expense_categories_by_branch.php'); (new \ScopeExpenseCategoriesByBranch)->up();
        require_once database_path('migrations/2026_10_04_080000_create_branch_operations.php'); (new \CreateBranchOperations)->up();
        require_once database_path('migrations/2026_10_06_130000_create_phone_delivery_batches.php'); (new \CreatePhoneDeliveryBatches)->up();
        require_once database_path('migrations/2026_10_04_000001_create_pos_branch_print_jobs.php'); (new \CreatePosBranchPrintJobs)->up();
        // The branch's delivery pin and tariff (resturants columns of the live site).
        Schema::table('resturants', function (Blueprint $t) {
            $t->decimal('lat', 10, 7)->nullable(); $t->decimal('lng', 10, 7)->nullable();
            foreach (['km_price', 'default_0_1', 'default_1_2', 'default_2_3'] as $column) $t->decimal($column, 10, 2)->nullable();
        });
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

    public function test_expenses_approved_rejected_or_cancelled_offline_follow_the_dashboard_review_once(): void
    {
        $device = $this->enroll('u1@example.test', 'root-pass', ['f:100'])->json();
        $cashier = $this->enroll('01000000004', 'cashier-pass')->json();
        // Two expenses waiting on the dashboard, and one the cashier's device saves offline.
        $saved = $this->sync($cashier, ['operations' => [
            $this->operation('expense', 4, ['category' => 'electricity', 'description' => 'فاتورة كهرباء', 'amount' => '350.50']),
            $this->operation('expense', 4, ['category' => 'gas', 'description' => 'غاز', 'amount' => '40.00']),
        ]])->json()['results'];
        [$power, $gas] = [$saved[0]['expense']['id'], $saved[1]['expense']['id']];
        $local = $this->operation('expense', 4, ['category' => 'packaging', 'description' => 'أكياس', 'amount' => '25.00']);
        // The owner's device, offline: approves the power bill, rejects the gas, then approves the device's expense by its operation.
        $approve = $this->operation('expense_review', 1, ['expense_id' => $power, 'expected_revision' => 1, 'action' => 'approve', 'reason' => '']);
        $reject = $this->operation('expense_review', 1, ['expense_id' => $gas, 'expected_revision' => 1, 'action' => 'reject', 'reason' => 'مكرر']);
        $byKey = $this->operation('expense_review', 1, ['expense_key' => $local['id'], 'action' => 'approve']);
        $early = $this->sync($device, ['operations' => [$byKey]])->json()['results'][0];
        $this->assertSame(['rejected', 'المصروف ده ما وصلش للداشبورد، فمراجعته ما اتطبقتش.'], [$early['status'], $early['error']]);
        $this->assertSame('applied', $this->sync($cashier, ['operations' => [$local]])->json()['results'][0]['status']);
        $byKey['id'] = (string) Str::uuid();
        $results = $this->sync($device, ['operations' => [$approve, $reject, $byKey]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'applied', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['approved', 'rejected', 'approved'], array_column(array_column($results, 'expense'), 'status'));
        $this->assertSame(-35050 - 2500, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'), 'Approved cash leaves the drawer');
        $this->assertSame('مكرر', DB::table('branch_expenses')->where('id', $gas)->value('review_reason'));
        // Sent again: the same review, never twice.
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$approve]])->json()['results'][0]);
        $this->assertSame(-35050 - 2500, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'));
        // Cancelled offline later: the cash goes back; a review of a changed expense is refused like on the page.
        $void = $this->operation('expense_review', 1, ['expense_id' => $power, 'expected_revision' => 2, 'action' => 'void', 'reason' => 'خطأ']);
        $stale = $this->operation('expense_review', 1, ['expense_id' => $gas, 'expected_revision' => 1, 'action' => 'approve']);
        $later = $this->sync($device, ['operations' => [$void, $stale]])->json()['results'];
        $this->assertSame(['applied', 'voided'], [$later[0]['status'], $later[0]['expense']['status']]);
        $this->assertSame(['rejected', 'حالة المصروف تغيرت؛ حدّث الصفحة.'], [$later[1]['status'], $later[1]['error']]);
        $this->assertSame(-2500, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'));
        // A cashier cannot review, and a reason is needed to reject, as on the page.
        $cannot = $this->sync($cashier, ['operations' => [$this->operation('expense_review', 4, ['expense_id' => $gas, 'action' => 'void', 'reason' => 'x'])]])->json()['results'][0];
        $this->assertSame('rejected', $cannot['status']);
        $noReason = $this->sync($device, ['operations' => [$this->operation('expense_review', 1, ['expense_id' => $power, 'action' => 'reject'])]])->json()['results'][0];
        $this->assertSame(['rejected', 'اكتب سبب الرفض أو الإلغاء.'], [$noReason['status'], $noReason['error']]);
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
        $this->assertSame(['sale', 'expense', 'till', 'attendance', 'ticket_settle', 'ticket_open', 'ticket_action', 'ticket_dispatch', 'ticket_batch', 'ticket_save', 'ticket_cancel', 'customer_save', 'attendance_status', 'stock_receive', 'shift_close', 'employee_entry', 'expense_review'], $response['kinds']);
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

    public function test_once_turned_on_the_server_records_an_unexcused_absence_after_the_deadline(): void
    {
        // What deployment/activate_employee_auto_absence_root.py sets up: the first day, and the lock the command takes.
        $this->attendanceFixture();
        $folder = sys_get_temp_dir().'/go-erp-auto-absence-'.Str::random(8);
        mkdir($folder, 0750);
        file_put_contents($folder.'/activation.json', json_encode(['starts_on' => '2026-11-02']));
        touch($folder.'/install.lock');
        chmod($folder.'/install.lock', 0600);
        $this->beforeApplicationDestroyed(function () use ($folder) {
            @unlink($folder.'/activation.json');
            @unlink($folder.'/install.lock');
            @rmdir($folder);
        });
        $absence = new \App\Services\Dashboard\EmployeeAutomaticAbsence(app(\App\Services\Dashboard\BranchOperations::class), $folder.'/activation.json');
        $this->assertTrue($absence->ready());
        // كريم recorded his attendance that day; سعيد and هاني recorded nothing.
        DB::table('branch_employee_days')->insert(['branch' => 'f:100', 'employee_id' => 51, 'day' => '2026-11-02', 'status' => 'morning',
            'check_in' => '10:05', 'checked_in_at' => '2026-11-02 08:05:00', 'attendance_rule_snapshot' => '{}', 'notes' => '', 'revision' => 1,
            'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 21:55:00', 'Africa/Cairo'));
        $this->assertSame(0, $absence->run()['recorded'], 'Nothing before 22:00');
        Carbon::setTestNow(Carbon::parse('2026-11-02 22:05:00', 'Africa/Cairo'));
        $counts = $absence->run();
        $this->assertSame([2, 0], [$counts['recorded'], $counts['errors']], json_encode($counts));
        $days = DB::table('branch_employee_days')->where('day', '2026-11-02')->orderBy('employee_id')->pluck('status', 'employee_id')->all();
        $this->assertSame([50 => 'unauthorized_absence', 51 => 'morning', 52 => 'unauthorized_absence'], $days);
        $this->assertSame([30000, 0, 30000], [$this->deductions(50), $this->deductions(51), $this->deductions(52)], 'The branch\'s absence deduction');
        $this->assertSame('غياب بدون إذن', DB::table('branch_employee_entries')->where('employee_id', 50)->value('reason'));
        // Once only, and never for a day before the first day (2026-11-01 here).
        $this->assertSame(0, $absence->run()['recorded']);
        $this->assertSame(0, DB::table('branch_employee_days')->where('day', '2026-11-01')->count());
        // Turned off (no activation file): nothing runs.
        unlink($folder.'/activation.json');
        $this->assertFalse($absence->ready());
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

    /** A dine-in order opened on the dashboard by the branch cashier, sent to the kitchen. */
    private function openTable(): array
    {
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 1', 'capacity' => 4, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $cashier = User::find(4);
        $items = [['product_id' => 1, 'quantity' => '1.000', 'quantity_mode' => 'weight'], ['product_id' => 2, 'quantity' => '2', 'quantity_mode' => 'piece']];
        $quote = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/quote', ['branch' => 'f:100', 'items' => $items])->assertOk()->json();
        $ticket = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/save', ['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(),
            'table_id' => $table, 'items' => $items, 'quote_hash' => $quote['quote_hash'], 'waiter_name' => 'حسن', 'guest_count' => 2])->assertOk()->json('ticket');
        $ticket = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/'.$ticket['id'].'/action', ['branch' => 'f:100',
            'idempotency_key' => (string) Str::uuid(), 'expected_revision' => $ticket['revision'], 'action' => 'send_kitchen'])->assertOk()->json('ticket');
        return ['table' => $table, 'ticket' => $ticket];
    }

    public function test_devices_receive_the_tables_and_open_orders_in_the_dining_pages_format(): void
    {
        $open = $this->openTable();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $service = $this->sync($device, ['want_service' => true])->assertOk()->json('service')[0];
        $this->assertSame('f:100', $service['value']);
        $this->assertSame([$open['ticket']['id']], array_column($service['tickets'], 'id'));
        $page = $this->actingAs(User::find(4), 'admin')->getJson('/admin/dining/tickets/'.$open['ticket']['id'])->assertOk()->json('ticket');
        $this->assertSame(array_keys($page), array_keys($service['tickets'][0]), 'The same order format the dining page reads');
        $this->assertSame($page['quote_hash'], $service['tickets'][0]['quote_hash']);
        $this->assertSame('in_service', $service['tables'][0]['status']);
        $again = $this->sync($device, ['want_service' => true, 'service_hashes' => ['f:100' => $service['hash']]])->json('service')[0];
        $this->assertArrayNotHasKey('tickets', $again, 'Unchanged orders are not resent');
    }

    public function test_an_order_open_on_the_dashboard_is_paid_offline_once_and_frees_its_table(): void
    {
        $open = $this->openTable();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $settle = $this->operation('ticket_settle', 4, ['channel' => 'dine', 'ticket_id' => $open['ticket']['id'], 'expected_revision' => $open['ticket']['revision'],
            'quote_hash' => $open['ticket']['quote_hash'], 'cash_received' => '250.00', 'customer_phone' => '01012345678', 'service_consent' => true]);
        $result = $this->sync($device, ['operations' => [$settle]])->assertOk()->json('results')[0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame('paid', $result['ticket']['status']);
        $order = DB::table('takeaway_orders')->first();
        // 1 kg × 120.00 + 2 × 45.50 = 211.00
        $this->assertSame(['dine', 21100, (int) $open['ticket']['id']], [$order->channel, (int) $order->total_cents, (int) $order->ticket_id]);
        $this->assertNull(DB::table('pos_service_tables')->value('active_ticket_id'), 'The table is free again');
        $this->assertSame($result, $this->sync($device, ['operations' => [$settle]])->json('results')[0], 'A resend returns the same result');
        $this->assertSame(1, DB::table('takeaway_orders')->count());
        // The order moved on after the device's copy without changing its price (sent to the kitchen): paid as it is now.
        $other = $this->openTable2();
        $later = $this->operation('ticket_settle', 4, ['channel' => 'dine', 'ticket_id' => $other['id'], 'expected_revision' => 1,
            'quote_hash' => $other['quote_hash'], 'cash_received' => '200.00', 'customer_phone' => '01012345678', 'service_consent' => true]);
        $this->assertSame('applied', $this->sync($device, ['operations' => [$later]])->json('results')[0]['status']);
        // Items added on the dashboard meanwhile: a different amount is refused, never collected silently.
        $cashier = User::find(4);
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 3', 'capacity' => 4, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $order = function (array $items, array $extra = []) use ($cashier, $table) {
            $quote = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/quote', ['branch' => 'f:100', 'items' => $items])->json();
            return $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/save', ['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(),
                'table_id' => $table, 'items' => $items, 'quote_hash' => $quote['quote_hash'], 'waiter_name' => 'حسن', 'guest_count' => 1] + $extra)->assertOk()->json('ticket');
        };
        $first = $order([['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece']]);
        $order([['product_id' => 2, 'quantity' => '3', 'quantity_mode' => 'piece']], ['ticket_id' => $first['id'], 'expected_revision' => $first['revision']]);
        $stale = $this->operation('ticket_settle', 4, ['channel' => 'dine', 'ticket_id' => $first['id'], 'expected_revision' => $first['revision'],
            'quote_hash' => $first['quote_hash'], 'cash_received' => '200.00', 'customer_phone' => '01012345678', 'service_consent' => true]);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$stale]])->json('results')[0]['status']);
        $this->assertSame('unpaid', DB::table('pos_service_tickets')->where('id', $first['id'])->value('payment_status'));
    }

    /** A delivery order open on the dashboard, priced like the delivery page (2 × 45.50 and 25.00 delivery). */
    private function phoneOrder(): object
    {
        $cashier = User::find(4);
        $cart = ['branch' => 'f:100', 'items' => [['product_id' => 2, 'quantity' => '2', 'quantity_mode' => 'piece', 'option_id' => '']], 'discount' => '0.00', 'discount_reason' => ''];
        $quote = app(\App\Services\Dashboard\TakeawayService::class)->quote($cart, $cashier, ['channel' => 'phone', 'service_bps' => 0, 'delivery_cents' => 2500]);
        $saved = ['branch' => 'f:100', 'items' => array_map(fn ($line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'],
            'quantity_mode' => $line['quantity_mode'], 'option_id' => $line['option_id']], $quote['items']), 'discount' => $quote['discount'], 'discount_reason' => ''];
        $id = DB::table('pos_service_tickets')->insertGetId(['branch' => 'f:100', 'channel' => 'phone', 'customer_name' => 'منى', 'customer_phone' => '+201112223334',
            'address' => 'شارع النصر', 'area' => 'المعادي', 'delivery_cents' => 2500, 'cart_snapshot' => json_encode($saved, JSON_UNESCAPED_UNICODE),
            'quote_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE), 'revision' => 1, 'actor_id' => 4, 'status' => 'preparing', 'payment_status' => 'unpaid',
            'business_date' => '2026-10-30', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        return DB::table('pos_service_tickets')->find($id);
    }

    public function test_a_delivery_order_is_handed_to_a_courier_and_collected_offline_once(): void
    {
        DB::table('branch_delivery_companies')->insert(['id' => 7, 'branch' => 'f:100', 'name' => 'شركة سريع', 'phone' => '01011112222',
            'active' => true, 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->phoneOrder();
        $hash = json_decode($order->quote_snapshot, true)['quote_hash'];
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $copy = $this->sync($device, ['want_service' => true])->json('service')[0]['tickets'][0];
        $this->assertSame([(int) $order->id, 'phone', null], [$copy['id'], $copy['channel'], $copy['courier_company']]);
        $dispatch = $this->operation('ticket_dispatch', 4, ['ticket_id' => $order->id, 'company_id' => 7]);
        $result = $this->sync($device, ['operations' => [$dispatch]])->assertOk()->json('results')[0];
        $this->assertSame(['applied', 'out_for_delivery', 2], [$result['status'], $result['ticket']['status'], $result['ticket']['revision']], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame($result, $this->sync($device, ['operations' => [$dispatch]])->json('results')[0], 'A resend repeats nothing');
        $this->assertSame(1, DB::table('phone_delivery_dispatches')->count());
        $copy = $this->sync($device, ['want_service' => true])->json('service')[0]['tickets'][0];
        $this->assertSame('شركة سريع', $copy['courier_company']['name'], 'The board shows who took it');
        $bill = $this->operation('ticket_action', 4, ['channel' => 'phone', 'ticket_id' => $order->id, 'action' => 'request_bill', 'quote_hash' => $hash]);
        $this->assertSame('applied', $this->sync($device, ['operations' => [$bill]])->json('results')[0]['status']);
        // Collected from the device's copy before the hand-off (revision 1): the same amount, so it applies.
        $settle = $this->operation('ticket_settle', 4, ['channel' => 'phone', 'ticket_id' => $order->id, 'expected_revision' => 1, 'quote_hash' => $hash,
            'cash_received' => '200.00']);
        $paid = $this->sync($device, ['operations' => [$settle]])->json('results')[0];
        $this->assertSame(['applied', 'finished'], [$paid['status'], $paid['ticket']['status']], json_encode($paid, JSON_UNESCAPED_UNICODE));
        $receipt = DB::table('takeaway_orders')->first();
        $this->assertSame(['phone', (int) $order->id], [$receipt->channel, (int) $receipt->ticket_id]);
        $this->assertSame([], $this->sync($device, ['want_service' => true])->json('service')[0]['tickets'], 'Collected: no longer open');
        // Handed over on the dashboard meanwhile: the device's hand-off changes nothing.
        $other = $this->phoneOrder();
        DB::table('pos_service_tickets')->where('id', $other->id)->update(['status' => 'out_for_delivery', 'revision' => 2]);
        $late = $this->sync($device, ['operations' => [$this->operation('ticket_dispatch', 4, ['ticket_id' => $other->id, 'company_id' => 7])]])->json('results')[0];
        $this->assertSame(['applied', true], [$late['status'], $late['already_saved'] ?? null]);
        $this->assertSame(1, DB::table('phone_delivery_dispatches')->count());
    }

    public function test_delivery_orders_a_courier_brought_back_are_collected_together_offline_once(): void
    {
        DB::table('branch_delivery_companies')->insert(['id' => 7, 'branch' => 'f:100', 'name' => 'شركة سريع', 'phone' => '01011112222',
            'active' => true, 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->phoneOrder();
        $second = $this->phoneOrder();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $handoffs = array_map(fn ($order) => $this->operation('ticket_dispatch', 4, ['ticket_id' => $order->id, 'company_id' => 7]), [$first, $second]);
        $hash = fn ($order) => json_decode($order->quote_snapshot, true)['quote_hash'];
        // 2 × (2 × 45.50 + 25.00) = 232.00
        $batch = $this->operation('ticket_batch', 4, ['items' => [['id' => $first->id, 'quote_hash' => $hash($first)], ['id' => $second->id, 'quote_hash' => $hash($second)]],
            'courier_name' => 'سيد', 'total' => '232.00', 'cash_received' => '250.00']);
        $results = $this->sync($device, ['operations' => [...$handoffs, $batch]])->assertOk()->json('results');
        $this->assertSame(['applied', 'applied', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('232.00', $results[2]['batch']['total']);
        $this->assertSame([(int) $first->id, (int) $second->id], array_column($results[2]['batch']['items'], 'ticket_id'));
        $this->assertSame(2, DB::table('takeaway_orders')->where('channel', 'phone')->count());
        $this->assertSame(['finished', 'finished'], DB::table('pos_service_tickets')->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, DB::table('phone_delivery_batches')->count());
        $this->assertSame($results[2], $this->sync($device, ['operations' => [$batch]])->json('results')[0], 'A resend repeats nothing');
        // An order whose amount changed meanwhile: the whole batch is refused, nothing is collected silently.
        $third = $this->phoneOrder();
        DB::table('pos_service_tickets')->where('id', $third->id)->update(['status' => 'out_for_delivery']);
        $stale = $this->operation('ticket_batch', 4, ['items' => [['id' => $third->id, 'quote_hash' => str_repeat('a', 64)]], 'total' => '116.00', 'cash_received' => '116.00']);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$stale]])->json('results')[0]['status']);
        $this->assertSame('unpaid', DB::table('pos_service_tickets')->where('id', $third->id)->value('payment_status'));
    }

    public function test_items_added_offline_to_a_dashboard_order_reach_it_once_and_its_bill_and_payment_follow(): void
    {
        $open = $this->openTable();
        $ticket = $open['ticket'];
        $id = (int) $ticket['id'];
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $line = fn ($item) => ['product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'quantity_mode' => $item['quantity_mode'], 'option_id' => $item['option_id']];
        $base = ['items' => array_map($line, $ticket['items']), 'discount' => '0.00', 'discount_reason' => ''];
        // One more بلطي مقلي (45.50): 211.00 + 45.50 = 256.50
        $more = array_merge($base['items'], [['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece', 'option_id' => '']]);
        $expect = ['items' => $more, 'discount' => '0.00', 'discount_reason' => '', 'total_cents' => 25650];
        $save = $this->operation('ticket_save', 4, ['ticket_id' => $id, 'base' => $base, 'items' => $more, 'discount' => '0.00', 'discount_reason' => '',
            'waiter_name' => 'حسن', 'guest_count' => 3, 'customer_name' => 'أسرة محمد', 'customer_phone' => '', 'notes' => 'زيادة']);
        $bill = $this->operation('ticket_action', 4, ['channel' => 'dine', 'ticket_id' => $id, 'action' => 'request_bill', 'quote_hash' => $ticket['quote_hash'],
            'expect' => $expect]);
        $settle = $this->operation('ticket_settle', 4, ['channel' => 'dine', 'ticket_id' => $id, 'expected_revision' => 2, 'quote_hash' => $ticket['quote_hash'],
            'cash_received' => '300.00', 'customer_phone' => '01012345678', 'service_consent' => true, 'expect' => $expect]);
        $results = $this->sync($device, ['operations' => [$save, $bill, $settle]])->assertOk()->json('results');
        $this->assertSame(['applied', 'applied', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('256.50', $results[0]['ticket']['total']);
        $row = DB::table('pos_service_tickets')->find($id);
        $this->assertSame([3, 'زيادة', 'paid'], [(int) $row->guest_count, $row->notes, $row->payment_status]);
        $this->assertNotNull($row->bill_issued_at);
        $this->assertSame(25650, (int) DB::table('takeaway_orders')->where('ticket_id', $id)->value('total_cents'));
        $this->assertSame($results, $this->sync($device, ['operations' => [$save, $bill, $settle]])->json('results'), 'A resend repeats nothing');
        // Changed on the dashboard meanwhile: the device's change is refused, and so is a payment for a different amount.
        $other = $this->openTable2();
        $stale = $this->operation('ticket_save', 4, ['ticket_id' => $other['id'], 'base' => ['items' => [['product_id' => 2, 'quantity' => '2', 'quantity_mode' => 'piece']]],
            'items' => [['product_id' => 2, 'quantity' => '3', 'quantity_mode' => 'piece']], 'waiter_name' => 'حسن', 'guest_count' => 1]);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$stale]])->json('results')[0]['status']);
        $wrong = $this->operation('ticket_settle', 4, ['channel' => 'dine', 'ticket_id' => $other['id'], 'expected_revision' => 1, 'quote_hash' => str_repeat('b', 64),
            'cash_received' => '300.00', 'customer_phone' => '01012345678', 'service_consent' => true,
            'expect' => ['items' => [['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece']], 'total_cents' => 9999]]);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$wrong]])->json('results')[0]['status']);
        $this->assertSame('unpaid', DB::table('pos_service_tickets')->where('id', $other['id'])->value('payment_status'));
    }

    public function test_orders_cancelled_offline_are_cancelled_on_the_dashboard_once_and_free_their_table(): void
    {
        $cashier = User::find(4);
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 9', 'capacity' => 4, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $items = [['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece']];
        $quote = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/quote', ['branch' => 'f:100', 'items' => $items])->json();
        $dine = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/save', ['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(),
            'table_id' => $table, 'items' => $items, 'quote_hash' => $quote['quote_hash'], 'waiter_name' => 'حسن', 'guest_count' => 1])->assertOk()->json('ticket');
        $phone = $this->phoneOrder();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $cancel = fn (string $channel, int $id, string $hash, string $reason = 'العميل مشي') => $this->operation('ticket_cancel', 4, ['channel' => $channel,
            'ticket_id' => $id, 'quote_hash' => $hash, 'reason' => $reason]);
        $operations = [$cancel('dine', (int) $dine['id'], $dine['quote_hash']), $cancel('phone', (int) $phone->id, json_decode($phone->quote_snapshot, true)['quote_hash'])];
        $results = $this->sync($device, ['operations' => $operations])->assertOk()->json('results');
        $this->assertSame(['applied', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['cancelled', 'cancelled'], DB::table('pos_service_tickets')->whereIn('id', [$dine['id'], $phone->id])->orderBy('id')->pluck('status')->all());
        $this->assertSame('العميل مشي', DB::table('pos_service_tickets')->where('id', $dine['id'])->value('cancel_reason'));
        $this->assertNull(DB::table('pos_service_tables')->where('id', $table)->value('active_ticket_id'), 'The table is free again');
        $this->assertSame($results, $this->sync($device, ['operations' => $operations])->json('results'), 'A resend repeats nothing');
        // Sent to the kitchen on the dashboard meanwhile: it is not cancelled from the device.
        $open = $this->openTable();
        $late = $this->sync($device, ['operations' => [$cancel('dine', (int) $open['ticket']['id'], $open['ticket']['quote_hash'])]])->json('results')[0];
        $this->assertSame('rejected', $late['status']);
        $this->assertSame('preparing', DB::table('pos_service_tickets')->where('id', $open['ticket']['id'])->value('status'));
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$cancel('dine', (int) $open['ticket']['id'], $open['ticket']['quote_hash'], ' ')]])
            ->json('results')[0]['status'], 'A reason is required');
    }

    public function test_devices_receive_the_branch_delivery_tariff_and_its_saved_customers_since_their_copy(): void
    {
        DB::table('resturants')->where('id', 100)->update(['lat' => 29.9602, 'lng' => 31.2569, 'km_price' => '10.00', 'default_0_1' => '15.00',
            'default_1_2' => '20.00', 'default_2_3' => '25.00']);
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $delivery = $this->sync($device, ['want_service' => true])->json('service')[0]['delivery'];
        $this->assertSame([true, 29.9602, 31.2569, '10.00', '20.00', false], [$delivery['ready'], $delivery['latitude'], $delivery['longitude'],
            $delivery['km_price'], $delivery['pricing_rates']['default_1_2'], $delivery['pricing_rates']['legacy']]);
        $customer = fn (string $branch, string $phone, string $name, string $at) => DB::table('branch_customers')->insertGetId(['branch' => $branch, 'name' => $name,
            'phone' => $phone, 'phone_key' => \App\Services\Dashboard\BranchCustomers::phoneKey($phone), 'address' => 'شارع ٩', 'area' => 'المعادي',
            'latitude' => 29.97, 'longitude' => 31.25, 'revision' => 1, 'actor_id' => 1, 'created_at' => $at, 'updated_at' => $at]);
        $first = $customer('f:100', '01011111111', 'منى', '2026-10-30 10:00:00');
        $customer('f:100', '01022222222', 'سيد', '2026-10-30 11:00:00');
        $customer('f:101', '01033333333', 'فرع تاني', '2026-10-30 12:00:00');
        $page = $this->sync($device, ['want_customers' => true])->assertOk()->json('customers');
        $this->assertSame(['منى', 'سيد'], array_column($page['items'], 'name'), 'Only this device\'s branches');
        $this->assertSame([false, '2026-10-30 11:00:00|'.($first + 1)], [$page['more'], $page['cursor']]);
        $this->assertSame([29.97, 31.25], [$page['items'][0]['latitude'], $page['items'][0]['longitude']]);
        $this->assertSame([], $this->sync($device, ['want_customers' => true, 'customers_cursor' => $page['cursor']])->json('customers.items'));
        DB::table('branch_customers')->where('id', $first)->update(['address' => 'شارع ١٠', 'revision' => 2, 'updated_at' => '2026-10-30 13:00:00']);
        $changed = $this->sync($device, ['want_customers' => true, 'customers_cursor' => $page['cursor']])->json('customers.items');
        $this->assertSame([[$first, 'شارع ١٠', 2]], array_map(fn ($item) => [$item['id'], $item['address'], $item['revision']], $changed));
    }

    public function test_a_delivery_customer_saved_offline_is_saved_for_the_branch_once(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $save = $this->operation('customer_save', 4, ['name' => 'منى', 'phone' => '01112223334', 'address' => 'شارع النصر', 'area' => 'المعادي',
            'delivery_notes' => 'الدور التالت', 'latitude' => 29.97, 'longitude' => 31.25]);
        $result = $this->sync($device, ['operations' => [$save]])->assertOk()->json('results')[0];
        $this->assertSame(['applied', 1], [$result['status'], $result['customer']['revision']], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSame($result, $this->sync($device, ['operations' => [$save]])->json('results')[0]);
        $again = $this->operation('customer_save', 4, ['name' => 'منى أحمد', 'phone' => '+201112223334', 'address' => 'شارع النصر ٥']);
        $updated = $this->sync($device, ['operations' => [$again]])->json('results')[0];
        $this->assertSame([$result['customer']['id'], 2], [$updated['customer']['id'], $updated['customer']['revision']], 'The same customer, by number');
        $row = DB::table('branch_customers')->first();
        $this->assertSame([1, 'منى أحمد', 'شارع النصر ٥'], [DB::table('branch_customers')->count(), $row->name, $row->address]);
    }

    public function test_a_delivery_order_placed_offline_moves_to_the_dashboard_with_what_the_device_did(): void
    {
        DB::table('resturants')->where('id', 100)->update(['lat' => 29.9602, 'lng' => 31.2569, 'km_price' => '10.00', 'default_0_1' => '15.00',
            'default_1_2' => '20.00', 'default_2_3' => '25.00']);
        DB::table('branch_delivery_companies')->insert(['id' => 7, 'branch' => 'f:100', 'name' => 'شركة سريع', 'phone' => '01011112222',
            'active' => true, 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        // About 1.1 km from the branch: the 1–2 km tier (20.00). 2 × 45.50 + 20.00 = 111.00
        $order = ['channel' => 'phone', 'items' => [['product_id' => 2, 'quantity' => '2', 'quantity_mode' => 'piece', 'option_id' => '']],
            'customer_name' => 'منى', 'customer_phone' => '01112223334', 'address' => 'شارع النصر', 'area' => 'المعادي', 'delivery_notes' => '',
            'notes' => 'حار', 'latitude' => 29.9702, 'longitude' => 31.2569, 'delivery_fee' => '20.00', 'delivery_company_id' => 7, 'kitchen_sent' => true,
            'kitchen_printed' => true, 'courier_company_id' => 7, 'bill_requested' => true];
        $open = $this->operation('ticket_open', 4, $order);
        $result = $this->sync($device, ['operations' => [$open]])->assertOk()->json('results')[0];
        $this->assertSame(['applied', 'out_for_delivery'], [$result['status'], $result['ticket']['status']], json_encode($result, JSON_UNESCAPED_UNICODE));
        $row = DB::table('pos_service_tickets')->find($result['ticket']['id']);
        $quote = json_decode($row->quote_snapshot, true);
        $this->assertSame(['phone', 2000, '111.00', 'حار'], [$row->channel, (int) $row->delivery_cents, $quote['total'], $row->notes]);
        $this->assertNotNull($row->bill_issued_at);
        $this->assertSame(['invoked'], DB::table('pos_branch_print_jobs')->pluck('status')->all(), 'Printed on the device: not printed again');
        $this->assertSame(1, DB::table('phone_delivery_dispatches')->where('ticket_id', $row->id)->count());
        $this->assertSame($result, $this->sync($device, ['operations' => [$open]])->json('results')[0], 'Moved once');
        // The device charged another delivery fee (its tariff copy was older): that fee is kept.
        $other = $this->operation('ticket_open', 4, ['delivery_fee' => '30.00', 'kitchen_printed' => false, 'courier_company_id' => null,
            'bill_requested' => false] + $order);
        $moved = $this->sync($device, ['operations' => [$other]])->json('results')[0];
        // Like the delivery page: sent to the kitchen on save, still new until it leaves with the courier.
        $this->assertSame(['applied', 'new'], [$moved['status'], $moved['ticket']['status']], json_encode($moved, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull(DB::table('pos_service_tickets')->where('id', $moved['ticket']['id'])->value('last_kitchen_id'));
        $this->assertSame(3000, (int) DB::table('pos_service_tickets')->where('id', $moved['ticket']['id'])->value('delivery_cents'));
        $this->assertSame(['invoked', 'pending'], DB::table('pos_branch_print_jobs')->orderBy('id')->pluck('status')->all(), 'Not printed there yet: the branch printer prints it');
    }

    public function test_a_bill_printed_or_a_kitchen_ticket_sent_offline_reaches_the_dashboard_order_once(): void
    {
        $open = $this->openTable();
        $id = (int) $open['ticket']['id'];
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $bill = $this->operation('ticket_action', 4, ['channel' => 'dine', 'ticket_id' => $id, 'action' => 'request_bill', 'quote_hash' => $open['ticket']['quote_hash']]);
        $result = $this->sync($device, ['operations' => [$bill]])->assertOk()->json('results')[0];
        $this->assertSame(['applied', 'awaiting_bill', 3], [$result['status'], $result['ticket']['status'], $result['ticket']['revision']], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertNotNull(DB::table('pos_service_tickets')->where('id', $id)->value('bill_issued_at'));
        $this->assertSame($result, $this->sync($device, ['operations' => [$bill]])->json('results')[0], 'A resend repeats nothing');
        // Already sent to the kitchen on the dashboard: nothing is printed twice.
        $kitchen = $this->operation('ticket_action', 4, ['channel' => 'dine', 'ticket_id' => $id, 'action' => 'send_kitchen', 'quote_hash' => $open['ticket']['quote_hash']]);
        $done = $this->sync($device, ['operations' => [$kitchen]])->json('results')[0];
        $this->assertSame(['applied', true], [$done['status'], $done['already_saved'] ?? null]);
        $this->assertSame(1, DB::table('pos_service_kitchen_tickets')->where('ticket_id', $id)->count());
        // Paid on the device from its copy before the bill (revision 2): the same price, so the payment applies.
        $settle = $this->operation('ticket_settle', 4, ['channel' => 'dine', 'ticket_id' => $id, 'expected_revision' => 2,
            'quote_hash' => $open['ticket']['quote_hash'], 'cash_received' => '250.00', 'customer_phone' => '01012345678', 'service_consent' => true]);
        $this->assertSame('applied', $this->sync($device, ['operations' => [$settle]])->json('results')[0]['status']);
        $this->assertSame('paid', DB::table('pos_service_tickets')->where('id', $id)->value('payment_status'));
        // An order saved on the dashboard but not sent to the kitchen yet: sent from the device.
        $cashier = User::find(4);
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 5', 'capacity' => 4, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $items = [['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece']];
        $quote = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/quote', ['branch' => 'f:100', 'items' => $items])->json();
        $saved = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/save', ['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(),
            'table_id' => $table, 'items' => $items, 'quote_hash' => $quote['quote_hash'], 'waiter_name' => 'حسن', 'guest_count' => 1])->assertOk()->json('ticket');
        $send = $this->operation('ticket_action', 4, ['channel' => 'dine', 'ticket_id' => $saved['id'], 'action' => 'send_kitchen', 'quote_hash' => $saved['quote_hash']]);
        $sent = $this->sync($device, ['operations' => [$send]])->json('results')[0];
        $this->assertSame(['applied', 'preparing'], [$sent['status'], $sent['ticket']['status']]);
        $this->assertSame(1, DB::table('pos_service_kitchen_tickets')->where('ticket_id', $saved['id'])->count());
        // A delivery order is not sent to the kitchen from the device.
        $phone = $this->operation('ticket_action', 4, ['channel' => 'phone', 'ticket_id' => $saved['id'], 'action' => 'send_kitchen', 'quote_hash' => $saved['quote_hash']]);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$phone]])->json('results')[0]['status']);
    }

    /** A second table's order, sent to the kitchen (revision 2). */
    private function openTable2(): array
    {
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 2', 'capacity' => 4, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $cashier = User::find(4);
        $items = [['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece']];
        $quote = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/quote', ['branch' => 'f:100', 'items' => $items])->json();
        $ticket = $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/save', ['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(),
            'table_id' => $table, 'items' => $items, 'quote_hash' => $quote['quote_hash'], 'waiter_name' => 'حسن', 'guest_count' => 1])->json('ticket');
        return $this->actingAs($cashier, 'admin')->postJson('/admin/dining/tickets/'.$ticket['id'].'/action', ['branch' => 'f:100',
            'idempotency_key' => (string) Str::uuid(), 'expected_revision' => $ticket['revision'], 'action' => 'send_kitchen'])->json('ticket');
    }

    public function test_an_order_opened_offline_moves_to_the_dashboard_when_still_unpaid(): void
    {
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 3', 'capacity' => 6, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $open = $this->operation('ticket_open', 4, ['table_id' => $table, 'waiter_name' => 'سيد', 'guest_count' => 3, 'customer_name' => 'أسرة علي',
            'items' => [['product_id' => 1, 'quantity' => '0.500', 'quantity_mode' => 'weight', 'option_id' => '']], 'notes' => 'حار', 'kitchen_sent' => true,
            'bill_requested' => true]);
        $result = $this->sync($device, ['operations' => [$open]])->assertOk()->json('results')[0];
        $this->assertSame('applied', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $ticket = DB::table('pos_service_tickets')->first();
        $this->assertSame([(int) $ticket->id, 'awaiting_bill', 'unpaid', $table], [$result['ticket']['id'], $ticket->status, $ticket->payment_status, (int) $ticket->table_id]);
        $this->assertNotNull($ticket->last_kitchen_id, 'Sent to the kitchen, as the device did');
        $this->assertSame((int) $ticket->id, (int) DB::table('pos_service_tables')->where('id', $table)->value('active_ticket_id'));
        $this->assertSame($result, $this->sync($device, ['operations' => [$open]])->json('results')[0]);
        $this->assertSame(1, DB::table('pos_service_tickets')->count(), 'Moved once');
        // A table another device seated meanwhile is refused; the device keeps its order.
        $busy = $this->operation('ticket_open', 4, ['table_id' => $table, 'waiter_name' => 'سيد', 'guest_count' => 1,
            'items' => [['product_id' => 2, 'quantity' => '1', 'quantity_mode' => 'piece', 'option_id' => '']]]);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$busy]])->json('results')[0]['status']);
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

    private function salary(int $employee, int $cents): void
    {
        DB::table('branch_employee_salaries')->insert(['employee_id' => $employee, 'branch' => 'f:100', 'effective_month' => '2026-10', 'amount_cents' => $cents,
            'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_leave_and_absence_recorded_on_the_device_follow_the_dashboard(): void
    {
        $this->attendanceFixture();
        $this->salary(50, 620000);
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $employees = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0]['catalog']['employees'];
        $this->assertSame([true, false, false], array_column($employees, 'has_salary'), 'A leave is valued from the salary: only سعيد has one');
        $leave = $this->operation('attendance_status', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'preapproved_leave', 'action' => 'set_status',
            'notes' => 'إجازة متفق عليها']);
        $absent = $this->operation('attendance_status', 4, ['employee_id' => 51, 'day' => '2026-10-30', 'status' => 'unauthorized_absence', 'action' => 'set_status']);
        $unpaid = $this->operation('attendance_status', 4, ['employee_id' => 52, 'day' => '2026-10-30', 'status' => 'preapproved_leave', 'action' => 'set_status']);
        $results = $this->sync($device, ['operations' => [$leave, $absent, $unpaid]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'applied', 'rejected'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['preapproved_leave', null, null], [$results[0]['attendance']['status'], $results[0]['attendance']['checked_in_at'],
            $results[0]['attendance']['checked_out_at']]);
        $this->assertSame('سجّل راتب الموظف لحساب قيمة يوم الإجازة.', $results[2]['error']);
        $this->assertSame(20000, $this->deductions(50), 'A leave day: the monthly salary ÷ the days of the month (31)');
        $this->assertSame('إجازة متفق عليها', DB::table('branch_employee_days')->where('employee_id', 50)->value('notes'));
        $this->assertSame(30000, $this->deductions(51), "The branch's absence deduction");
        // The day's first status stays: a check-in after the absence is refused, as on the dashboard; a resend is the same record.
        $late = $this->operation('attendance', 4, ['employee_id' => 51, 'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in']);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$late]])->json()['results'][0]['status']);
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$leave]])->json()['results'][0]);
        $this->assertSame(2, DB::table('branch_employee_days')->count());
    }

    public function test_deductions_bonuses_and_advances_recorded_on_the_device_follow_the_dashboard(): void
    {
        $this->attendanceFixture();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $entry = fn (int $actor, int $employee, string $kind, string $amount, string $reason, string $notes = '') => $this->operation('employee_entry', $actor,
            ['employee_id' => $employee, 'day' => '2026-10-30', 'kind' => $kind, 'amount' => $amount, 'reason' => $reason, 'notes' => $notes]);
        $advance = $entry(4, 50, 'advance', '150', 'سلفة شخصية', 'سيتم الخصم من الراتب الشهري');
        $deduction = $entry(4, 51, 'deduction', '25.50', 'تأخير');
        $cashiersBonus = $entry(4, 52, 'bonus', '100', 'مكافأة أداء');
        $ownersBonus = $entry(1, 52, 'bonus', '100', 'مكافأة أداء');
        $results = $this->sync($device, ['operations' => [$advance, $deduction, $cashiersBonus, $ownersBonus]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'applied', 'rejected', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('إضافة المكافآت متاحة للأونر فقط.', $results[2]['error'], 'A bonus stays the owner\'s, as on the dashboard');
        $this->assertSame(['employee_id' => 50, 'day' => '2026-10-30', 'kind' => 'advance', 'amount_cents' => 15000], array_diff_key($results[0]['entry'], ['id' => 0]));
        $rows = DB::table('branch_employee_entries')->orderBy('id')->get(['employee_id', 'kind', 'amount_cents', 'reason', 'notes', 'actor_id'])
            ->map(fn ($row) => array_values((array) $row))->all();
        $this->assertEquals([[50, 'advance', 15000, 'سلفة شخصية', 'سيتم الخصم من الراتب الشهري', 4], [51, 'deduction', 2550, 'تأخير', '', 4],
            [52, 'bonus', 10000, 'مكافأة أداء', '', 1]], $rows);
        // A resend, or an entry the page saved online under the same number before the connection dropped: never twice.
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$advance]])->json()['results'][0]);
        $this->assertSame(3, DB::table('branch_employee_entries')->count());
        $statement = app(\App\Services\Dashboard\BranchPayroll::class)->statement(['branch' => 'f:100', 'employee_id' => 50, 'month' => '2026-10'], User::find(1));
        $this->assertSame('150.00', $statement['statement']['advance'], 'The advance comes off the month\'s pay');
    }

    public function test_an_automatic_absence_gives_way_to_a_leave_recorded_on_the_device_before_the_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 23:30:00', 'Africa/Cairo'));
        $this->attendanceFixture();
        $this->salary(50, 620000);
        $this->salary(51, 620000);
        $this->automaticAbsence(50);
        $this->automaticAbsence(51);
        $this->automaticAbsence(52, false); // recorded by a person
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $results = $this->sync($device, ['operations' => [
            $this->operation('attendance_status', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'preapproved_leave', 'action' => 'set_status'],
                ['occurred_at' => '2026-10-30T08:00:00Z']),
            $this->operation('attendance_status', 4, ['employee_id' => 51, 'day' => '2026-10-30', 'status' => 'preapproved_leave', 'action' => 'set_status'],
                ['occurred_at' => '2026-10-30T20:30:00Z']),
            $this->operation('attendance_status', 4, ['employee_id' => 52, 'day' => '2026-10-30', 'status' => 'unauthorized_absence', 'action' => 'set_status'],
                ['occurred_at' => '2026-10-30T08:00:00Z']),
        ]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'rejected', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('preapproved_leave', DB::table('branch_employee_days')->where('employee_id', 50)->value('status'));
        $this->assertSame(20000, $this->deductions(50), 'The automatic absence deduction gives way to the leave day');
        $this->assertSame(30000, $this->deductions(51), 'A leave recorded after the deadline does not undo the absence');
        $this->assertSame(30000, $this->deductions(52), 'An absence recorded by a person stays as it was');
        $this->assertSame(1, (int) DB::table('branch_employee_days')->where('employee_id', 52)->value('revision'));
    }

    public function test_a_punch_operation_cannot_set_a_status_and_the_page_cannot_send_device_times(): void
    {
        $this->attendanceFixture();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $leave = $this->operation('attendance', 4, ['employee_id' => 50, 'day' => '2026-10-30', 'status' => 'preapproved_leave', 'action' => 'set_status']);
        $this->assertSame('rejected', $this->sync($device, ['operations' => [$leave]])->json()['results'][0]['status']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Dashboard\BranchPayroll::class)->attendance(['branch' => 'f:100', 'idempotency_key' => (string) Str::uuid(), 'employee_id' => 50,
            'day' => '2026-10-30', 'status' => 'morning', 'action' => 'check_in', 'checked_in_at' => '2026-10-30 06:00:00'], User::find(4));
    }

    /** The shift page's tables, and the app orders and carts the shift report reads. */
    private function shiftTables(): void
    {
        require_once database_path('migrations/2026_10_04_100000_create_branch_shift_closings.php'); (new \CreateBranchShiftClosings)->up();
        Schema::create('orders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('resturant_id'); $t->string('type')->default('current'); $t->string('status')->nullable();
            $t->string('payment_type')->nullable(); $t->string('transfer_price_by')->nullable(); $t->decimal('total_price', 14, 2)->default(0);
            $t->decimal('delivery_price', 14, 2)->default(0); $t->decimal('user_tax', 14, 2)->default(0); $t->timestamps();
        });
        Schema::create('carts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->decimal('price', 14, 2)->default(0); $t->string('qty')->default('1');
            $t->decimal('updated_total', 14, 2)->nullable();
        });
    }

    public function test_a_shift_closed_on_the_device_counts_what_it_recorded_before_and_is_dated_then(): void
    {
        $this->shiftTables();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $state = $this->sync($device, ['want_catalogs' => true, 'want_service' => true])->assertOk()->json();
        $snapshot = $state['branches'][0]['snapshot_id'];
        $this->assertSame(['previous_closing_id' => 0, 'pending_expenses' => [], 'started_at' => '2026-10-30 06:00:00', 'history' => []],
            $state['service'][0]['shift']);
        // Offline: a sale, the owner closes the shift at 12:00 Cairo, then a sale of the next shift; all sent at 14:00.
        $before = $this->sale($snapshot, ['occurred_at' => '2026-10-30T09:00:00Z']);
        $close = $this->operation('shift_close', 1, ['previous_closing_id' => 0, 'counted_cash' => '271.00', 'notes' => 'قفلة من غير نت'],
            ['occurred_at' => '2026-10-30T10:00:00Z']);
        $after = $this->sale($snapshot, ['occurred_at' => '2026-10-30T10:30:00Z', 'data' => ['local_number' => 'D1-0002']]);
        $results = $this->sync($device, ['operations' => [$before]])->json()['results'];
        $results = array_merge($results, $this->sync($device, ['operations' => [$close]])->json()['results']);
        $results = array_merge($results, $this->sync($device, ['operations' => [$after]])->json()['results']);
        $this->assertSame(['applied', 'applied', 'applied'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $closing = DB::table('branch_shift_closings')->first();
        $this->assertSame([27100, 27100, 0, '2026-10-30 10:00:00'], [(int) $closing->counted_cents, (int) $closing->expected_cents,
            (int) $closing->variance_cents, Carbon::parse($closing->closed_at)->format('Y-m-d H:i:s')]);
        $report = json_decode($closing->snapshot, true);
        $this->assertSame(['2026-10-30 12:00:00', '271.00', 'قفلة من غير نت'], [$report['closed_at'], $report['counted_cash'], $report['notes']]);
        $first = (int) DB::table('takeaway_orders')->orderBy('id')->value('id');
        $this->assertSame([['source' => 'pos', 'source_id' => $first]], DB::table('branch_shift_sources')->get(['source', 'source_id'])
            ->map(fn ($row) => ['source' => $row->source, 'source_id' => (int) $row->source_id])->all(), 'Only the sale before the closing');
        $this->assertSame('SHIFT-'.str_pad((string) $closing->id, 6, '0', STR_PAD_LEFT), $results[1]['closing']['number']);
        $this->assertSame(1, DB::table('branch_shift_print_jobs')->where('closing_id', $closing->id)->count(), 'The branch printer prints its report');
        // The next shift starts when the device closed it; the device learns the new closing.
        $shift = $this->sync($device, ['want_service' => true])->json('service')[0]['shift'];
        $this->assertSame([(int) $closing->id, '2026-10-30 12:00:00'], [$shift['previous_closing_id'], $shift['started_at']]);
        // Sent again: the same closing.
        $this->assertSame($results[1], $this->sync($device, ['operations' => [$close]])->json()['results'][0]);
        // A second closing made on another device first: this one is refused, as on the page.
        $stale = $this->operation('shift_close', 1, ['previous_closing_id' => 0, 'counted_cash' => '0.00']);
        $refused = $this->sync($device, ['operations' => [$stale]])->json()['results'][0];
        $this->assertSame(['rejected', 'تم إغلاق وردية أخرى قبل وصول قفلة الجهاز. راجع الوردية من الداشبورد.'], [$refused['status'], $refused['error']]);
        $this->assertSame(1, DB::table('branch_shift_closings')->count());
    }

    public function test_devices_asking_for_it_receive_the_open_shift_figures_of_its_closing_report_when_they_change(): void
    {
        $this->shiftTables();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $snapshot = $this->sync($device, ['want_catalogs' => true])->json('branches')[0]['snapshot_id'];
        $this->assertArrayNotHasKey('running', $this->sync($device, [])->json(), 'Only when asked');
        $first = $this->sync($device, ['want_running' => true])->assertOk()->json('running');
        $this->assertSame(['f:100'], array_column($first, 'value'));
        $this->assertSame(['previous_closing_id' => 0, 'started_at' => '2026-10-30 06:00:00', 'sales_total' => '0.00', 'expected_cash' => '0.00'],
            array_intersect_key($first[0]['report'], array_flip(['previous_closing_id', 'started_at', 'sales_total', 'expected_cash'])));
        // Unchanged: the hash alone.
        $same = $this->sync($device, ['want_running' => true, 'running_hashes' => ['f:100' => $first[0]['hash']]])->json('running')[0];
        $this->assertSame(['value' => 'f:100', 'hash' => $first[0]['hash']], $same);
        // A sale sent in the same sync is in the figures that sync returns, exactly as the closing would report it.
        $sale = $this->sale($snapshot, ['occurred_at' => '2026-10-30T09:00:00Z']);
        $after = $this->sync($device, ['operations' => [$sale], 'want_running' => true, 'running_hashes' => ['f:100' => $first[0]['hash']]])->json();
        $this->assertSame('applied', $after['results'][0]['status']);
        $report = $after['running'][0]['report'];
        $this->assertSame(['count' => 1, 'gross' => '271.00', 'delivery' => '0.00', 'net' => '271.00'], $report['channels']['takeaway']);
        $this->assertSame(['271.00', '271.00', '271.00', '271.00'], [$report['sales_total'], $report['net_sales'], $report['cash_sales'], $report['expected_cash']]);
        $user = User::query()->where('mobile', '1000000004')->first();
        $built = app(\App\Services\Dashboard\BranchShiftClosing::class)->desktopReview(['branch' => 'f:100'], $user)['snapshot'];
        foreach (array_keys($report) as $key) if ($key !== 'previous_closing_id') $this->assertSame($built[$key], $report[$key], $key);
    }

    public function test_a_shift_closed_on_the_device_waits_for_open_invoices_like_the_page(): void
    {
        $this->shiftTables();
        $this->openTable();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $close = $this->operation('shift_close', 1, ['previous_closing_id' => 0, 'counted_cash' => '0.00']);
        $result = $this->sync($device, ['operations' => [$close]])->json()['results'][0];
        $this->assertSame(['rejected', 'برجاء إغلاق الفواتير أولًا.'], [$result['status'], $result['error']]);
        $this->assertSame(0, DB::table('branch_shift_closings')->count());
    }

    public function test_the_trial_operations_are_cleared_with_a_backup_that_puts_them_back(): void
    {
        // A trial period: sales before and after a shift closing, an expense, goods received; plus an app order (kept).
        $this->shiftTables();
        require_once database_path('migrations/2026_10_04_210000_create_branch_inventory_recipes.php'); (new \CreateBranchInventoryRecipes)->up();
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $snapshot = $this->sync($device, ['want_catalogs' => true])->json()['branches'][0]['snapshot_id'];
        $onion = (int) DB::table('stock_ingredients')->where('name', 'بصل')->value('id');
        $operations = [$this->sale($snapshot, ['occurred_at' => '2026-10-30T09:00:00Z']),
            $this->operation('shift_close', 1, ['previous_closing_id' => 0, 'counted_cash' => '271.00'], ['occurred_at' => '2026-10-30T10:00:00Z']),
            $this->sale($snapshot, ['occurred_at' => '2026-10-30T10:30:00Z', 'data' => ['local_number' => 'D1-0002']]),
            $this->operation('expense', 1, ['category' => 'packaging', 'amount' => '75', 'approve' => true]),
            $this->operation('stock_receive', 4, ['ingredient_id' => $onion, 'unit' => 'kg', 'quantity' => '12.5'])];
        foreach ($operations as $operation) $this->assertSame('applied', $this->sync($device, ['operations' => [$operation]])->json()['results'][0]['status']);
        DB::table('orders')->insert(['resturant_id' => 100, 'status' => 'completed', 'total_price' => 50, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branch_order_sequences')->insert([['branch' => 'f:100', 'channel' => 'takeaway', 'shift_sequence' => 2, 'last_number' => 1],
            ['branch' => 'f:100', 'channel' => 'app', 'shift_sequence' => 2, 'last_number' => 7]]);
        $table = DB::table('pos_service_tables')->insertGetId(['branch' => 'f:100', 'name' => 'ترابيزة 1', 'capacity' => 4, 'active' => true, 'revision' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        $ticket = DB::table('pos_service_tickets')->insertGetId(['branch' => 'f:100', 'channel' => 'dine', 'actor_id' => 4, 'table_id' => $table,
            'status' => 'open', 'payment_status' => 'unpaid', 'revision' => 1, 'business_date' => '2026-10-30', 'cart_snapshot' => '{}', 'quote_snapshot' => '{}',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_service_tables')->where('id', $table)->update(['active_ticket_id' => $ticket]);
        $drawer = (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents');
        $this->assertGreaterThan(0, $drawer);
        $stock = (int) DB::table('branch_inventory')->where('ingredient_id', $onion)->value('quantity_units');
        $this->assertGreaterThan(0, $stock);
        $count = fn (string $table) => DB::table($table)->count();
        $before = array_map($count, ['takeaway_orders', 'takeaway_till_entries', 'branch_shift_closings', 'branch_expenses', 'branch_inventory_movements', 'pos_service_tickets']);

        require_once base_path('deployment/reset_branch_operations.php');
        $reset = new \GoErpBranchReset();
        $plan = $reset->plan();
        $this->assertSame(2, $plan['cleared']['takeaway_orders']['rows']);
        $this->assertSame(['f:100' => 2], $plan['cleared']['takeaway_orders']['branches']);
        $this->assertSame('75.00', $plan['cleared']['branch_expenses']['total']);
        $this->assertSame(1, $plan['zeroed']['takeaway_tills']['to_change']);
        $this->assertSame(1, $plan['order_numbers_restarting'], 'The cashier\'s numbering only; the app keeps counting');
        $this->assertSame(1, $plan['kept']['customer app orders']);
        $this->assertSame($before, array_map($count, ['takeaway_orders', 'takeaway_till_entries', 'branch_shift_closings', 'branch_expenses', 'branch_inventory_movements', 'pos_service_tickets']), 'The plan changes nothing');

        // Anything recorded between the backup and the clearing stops it, with nothing changed.
        $backup = json_decode(json_encode($reset->export()), true);
        $this->assertSame('applied', $this->sync($device, ['operations' => [$this->sale($snapshot, ['data' => ['local_number' => 'D1-0003']])]])->json()['results'][0]['status']);
        try {
            $reset->apply($backup['fingerprint']);
            $this->fail('A sale after the backup must stop the clearing');
        } catch (\GoErpResetStop $stop) {
            $this->assertSame('CHANGED_SINCE_BACKUP', $stop->getMessage());
        }
        $this->assertSame(3, $count('takeaway_orders'));

        $backup = json_decode(json_encode($reset->export()), true);
        $done = $reset->apply($backup['fingerprint']);
        $this->assertSame(3, $done['deleted']['takeaway_orders']);
        foreach (['takeaway_orders', 'takeaway_order_items', 'takeaway_till_entries', 'branch_shift_closings', 'branch_shift_sources', 'branch_shift_print_jobs',
            'branch_expenses', 'branch_expense_commands', 'branch_inventory_movements', 'pos_service_tickets'] as $cleared) {
            $this->assertSame(0, $count($cleared), $cleared.' is cleared');
        }
        $this->assertSame(0, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'), 'The drawer starts at zero');
        $this->assertSame(0, (int) DB::table('branch_inventory')->where('ingredient_id', $onion)->value('quantity_units'), 'The stock starts at zero');
        $this->assertNull(DB::table('pos_service_tables')->where('id', $table)->value('active_ticket_id'), 'Every table is free');
        $this->assertSame(['app'], DB::table('branch_order_sequences')->pluck('channel')->all());
        $this->assertSame(1, $count('orders'), 'The customer app\'s orders stay');
        $this->assertSame(1, $count('users') > 0 ? 1 : 0);
        // The device learns that the shift starts again from the beginning of the day.
        $this->assertSame(0, $this->sync($device, ['want_service' => true])->json('service')[0]['shift']['previous_closing_id']);

        // The backup puts everything back, exactly, while nothing new was recorded.
        $back = $reset->restore($backup);
        $this->assertSame(3, $back['restored']['takeaway_orders']);
        $this->assertSame([3, $before[1] + 1, 1, 1, 1, 1], array_map($count, ['takeaway_orders', 'takeaway_till_entries', 'branch_shift_closings', 'branch_expenses', 'branch_inventory_movements', 'pos_service_tickets']));
        $this->assertGreaterThan($drawer, (int) DB::table('takeaway_tills')->where('branch', 'f:100')->value('balance_cents'), 'With the third sale');
        $this->assertSame($stock, (int) DB::table('branch_inventory')->where('ingredient_id', $onion)->value('quantity_units'));
        $this->assertSame($ticket, (int) DB::table('pos_service_tables')->where('id', $table)->value('active_ticket_id'));
        $this->assertSame(['app', 'takeaway'], DB::table('branch_order_sequences')->orderBy('channel')->pluck('channel')->all());
        // Once real work has recorded anything, a backup is not put back over it.
        try {
            $reset->restore($backup);
            $this->fail('A restore over recorded operations must stop');
        } catch (\GoErpResetStop $stop) {
            $this->assertStringStartsWith('RECORDED_SINCE_RESET', $stop->getMessage());
        }
    }

    public function test_the_dashboard_sign_in_page_takes_the_go_opening_look_and_keeps_its_form(): void
    {
        $this->markTestSkipped('The GO opening sign-in page (commit 2eeaeed) is not installed on the live server yet; the live sign-in page is kept as it is.');
        $page = $this->get('/admin/login')->assertOk()->getContent();
        foreach (['hud-login', 'data-login-hologram', 'data-login-lines', 'THE INTELLIGENT SYSTEM FOR SALES, INVENTORY AND HR'] as $part) {
            $this->assertStringContainsString($part, $page);
        }
        // The form the app fills in to sign the dashboard in stays exactly as it was.
        foreach (['data-dashboard-login', 'name="email"', 'name="password"', 'name="_token"', 'data-password-toggle', 'name="remember"'] as $part) {
            $this->assertStringContainsString($part, $page);
        }
        $this->assertStringNotContainsString('go-hologram.js', $page, 'Without the hologram on the server, the GO logo stands in');
        // With the opening's hologram and GO mark on the server, the page uses them.
        $script = public_path('dashboard/js/go-hologram.js');
        $mark = resource_path('views/admin/partials/go_hud_mark.blade.php');
        @mkdir(dirname($script), 0777, true);
        file_put_contents($script, '/* GO hologram */');
        file_put_contents($mark, '<svg data-test-go-mark></svg>');
        try {
            app('view')->getFinder()->flush();
            $page = $this->get('/admin/login')->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#dashboard/js/go-hologram\.js\?v=\d+#', $page);
            $this->assertSame(2, substr_count($page, 'data-test-go-mark'), 'The GO mark in the corner, and in place of a hologram that cannot run');
        } finally {
            @unlink($script);
            @unlink($mark);
        }
    }

    public function test_goods_received_offline_reach_the_stock_once_dated_when_the_device_recorded_them(): void
    {
        require_once database_path('migrations/2026_10_04_210000_create_branch_inventory_recipes.php'); (new \CreateBranchInventoryRecipes)->up();
        $onion = (int) DB::table('stock_ingredients')->where('name', 'بصل')->value('id');
        $bread = (int) DB::table('stock_ingredients')->where('name', 'خبز بلدي')->value('id');
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $received = $this->operation('stock_receive', 4, ['ingredient_id' => $onion, 'unit' => 'kg', 'quantity' => '12.5', 'supplier' => 'سوق العبور',
            'notes' => ''], ['occurred_at' => '2026-10-30T07:15:00Z']); // 09:15 in Cairo, synced at 14:00
        $halfBread = $this->operation('stock_receive', 4, ['ingredient_id' => $bread, 'unit' => 'piece', 'quantity' => '2.5']);
        $wrongUnit = $this->operation('stock_receive', 4, ['ingredient_id' => $bread, 'unit' => 'kg', 'quantity' => '3']);
        $results = $this->sync($device, ['operations' => [$received, $halfBread, $wrongUnit]])->assertOk()->json()['results'];
        $this->assertSame(['applied', 'rejected', 'rejected'], array_column($results, 'status'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['توريد القطع يجب أن يكون عددًا صحيحًا.', 'وحدة البضاعة مختلفة.'], [$results[1]['error'], $results[2]['error']]);
        $this->assertSame(['12.5', $received['id']], [$results[0]['stock']['quantity'], $results[0]['receipt']['idempotency_key']]);
        $movement = DB::table('branch_inventory_movements')->first();
        $this->assertSame(['2026-10-30 07:15:00', 'سوق العبور', 12500000, 'receipt'],
            [Carbon::parse($movement->created_at)->format('Y-m-d H:i:s'), $movement->supplier, (int) $movement->quantity_units, $movement->source_type]);
        // Sent again: the same receipt, never twice.
        $this->assertSame($results[0], $this->sync($device, ['operations' => [$received]])->json()['results'][0]);
        $this->assertSame(1, DB::table('branch_inventory_movements')->count());
        // The page's own receipt reached the dashboard before the connection dropped; the device saved it under the same number.
        $key = (string) Str::uuid();
        $this->actingAs(User::find(4), 'admin')->postJson('/admin/branch-stock/receive', ['branch' => 'f:100', 'idempotency_key' => $key,
            'ingredient_id' => $onion, 'unit' => 'kg', 'quantity' => '1', 'supplier' => '', 'notes' => ''])->assertOk();
        $same = $this->operation('stock_receive', 4, ['ingredient_id' => $onion, 'unit' => 'kg', 'quantity' => '1', 'supplier' => '', 'notes' => ''], ['id' => $key]);
        $this->assertSame('applied', $this->sync($device, ['operations' => [$same]])->json()['results'][0]['status']);
        $this->assertSame(2, DB::table('branch_inventory_movements')->count());
        $this->assertSame(13500000, (int) DB::table('branch_inventory')->where('ingredient_id', $onion)->value('quantity_units'));
    }

    /** The dashboard page "Desktop program", rendered inside a bare layout (the full dashboard layout needs every table). */
    private function programPage(array $data): string
    {
        return view('admin.desktop-dashboard', $data)->render();
    }

    public function test_the_desktop_program_page_offers_the_new_app_and_no_longer_builds_the_old_one(): void
    {
        $layouts = sys_get_temp_dir().'/goerp-layout-'.Str::random(8);
        mkdir($layouts.'/admin', 0700, true);
        file_put_contents($layouts.'/admin/index.blade.php', "@yield('content')");
        app('view')->getFinder()->prependLocation($layouts);
        app('view')->getFinder()->flush();
        $this->beforeApplicationDestroyed(function () use ($layouts) {
            @unlink($layouts.'/admin/index.blade.php');
            @rmdir($layouts.'/admin');
            @rmdir($layouts);
        });
        $url = fn ($sha) => 'https://fasakhaninja.com/admin/desktop-dashboard/published/'.str_repeat($sha, 64);
        $published = ['current' => ['ready' => true, 'version' => '1.10.0', 'url' => $url('a')],
            'previous' => ['ready' => true, 'version' => '1.9.0', 'url' => $url('b')]];
        $page = $this->programPage(['local' => false, 'release' => null, 'manualOwner' => true, 'publishedDownloads' => $published]);
        $this->assertStringContainsString('تحميل التطبيق <bdi>1.10.0</bdi>', $page);
        $this->assertStringContainsString('href="'.$url('a').'"', $page);
        $this->assertStringContainsString('تحميل النسخة السابقة <bdi>1.9.0</bdi>', $page);
        $this->assertStringContainsString('«إعادة تجهيز الجهاز بحساب آخر»', $page, 'How to set the device up again with another account');
        $this->assertStringContainsString('<div data-go-erp-device></div>', $page, 'Where the app draws this device\'s part of the page');
        $this->assertStringNotContainsString('شاشات الجهاز', $page, 'The app has no separate screens to send people to');
        foreach (['تحديث التطبيق', 'go-erp-releases.js', 'data-go-erp-release', 'data-desktop-program'] as $old) {
            $this->assertStringNotContainsString($old, $page, 'The old program\'s build and device setup are not offered');
        }
        $none = ['ready' => false, 'version' => null, 'url' => null];
        $empty = $this->programPage(['local' => false, 'release' => ['version' => '0.4.4-preview.63'], 'manualOwner' => false,
            'publishedDownloads' => ['current' => $none, 'previous' => $none]]);
        $this->assertStringContainsString('ملف التثبيت غير متاح هنا حاليًا', $empty);
        $this->assertStringNotContainsString('0.4.4-preview.63', $empty, 'The old program\'s installer is not offered for new devices');
        // The old program's own copy of the page keeps its device setup.
        $local = $this->programPage(['local' => true, 'release' => null, 'manualOwner' => false]);
        $this->assertStringContainsString('data-desktop-program', $local);
        $this->assertStringNotContainsString('data-go-erp-downloads', $local);
    }

    public function test_a_device_released_to_be_set_up_again_stops_and_any_account_sets_it_up_again(): void
    {
        $device = $this->enroll('01000000004', 'cashier-pass')->json();
        $headers = ['X-GO-ERP-Device' => $device['device']['id'], 'Authorization' => 'Bearer '.$device['token']];
        $this->withHeaders($headers)->postJson('/api/desktop-sync/v1/release', [])->assertOk()->assertExactJson(['released' => true]);
        $this->assertNotNull(DB::table('desktop_sync_devices')->where('id', $device['device']['id'])->value('revoked_at'));
        $this->sync($device, [])->assertStatus(401);
        $this->withHeaders($headers)->postJson('/api/desktop-sync/v1/release', [])->assertStatus(401);
        // The same computer set up again, with the owner's account this time.
        $again = $this->enroll('u1@example.test', 'root-pass', ['f:100'])->assertOk()->json();
        $this->assertNotSame($device['device']['id'], $again['device']['id']);
        $this->sync($again, ['want_users' => true])->assertOk();
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
