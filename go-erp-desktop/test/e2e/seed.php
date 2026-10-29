<?php
// Builds the test database: the tables the cashier needs, two branches, accounts and a small fish menu.
require __DIR__.'/env.php';
@unlink(getenv('DB_DATABASE'));
touch(getenv('DB_DATABASE'));
$app = require GOERP_ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
goerp_settings($app);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

Schema::create('users', function (Blueprint $t) {
    $t->id();
    foreach (['name', 'account_type', 'app_scope', 'status'] as $field) $t->string($field);
    $t->string('mobile')->nullable(); $t->string('email')->nullable(); $t->string('password')->nullable();
    $t->unsignedBigInteger('owner_resturant_id')->nullable(); $t->unsignedBigInteger('pending_vendor_id')->nullable();
    $t->decimal('balance', 14, 2)->default(0); $t->timestamps();
});
Schema::create('resturants', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('parent_id')->nullable();
    $t->string('name'); $t->string('address')->default('شارع ٩ - المعادي'); $t->string('phone')->default('01000000000');
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
    [4, 'كاشير المعادي', 'admin', 100, '1000000004', 'cashier-pass'],
    [5, 'كاشير مدينة نصر', 'admin', 101, '1000000005', 'other-pass'],
    [10, 'صاحب المطعم', 'vendor', null, '1000000010', 'vendor-pass'],
    [20, 'عميل', 'user', null, '1000000020', 'customer-pass'],
] as [$id, $name, $type, $branch, $mobile, $password]) {
    DB::table('users')->insert(['id' => $id, 'name' => $name, 'account_type' => $type, 'app_scope' => 'fasakhansta',
        'status' => 'accepted', 'owner_resturant_id' => $branch, 'mobile' => $mobile, 'email' => 'u'.$id.'@example.test',
        'password' => Hash::make($password)]);
}
foreach ([[100, 'فرع المعادي'], [101, 'فرع مدينة نصر']] as [$id, $name]) DB::table('resturants')->insert(['id' => $id, 'user_id' => 10, 'name' => $name]);
foreach ([[1, 'أسماك'], [2, 'جمبري وكابوريا'], [3, 'أطباق جانبية']] as [$id, $name]) DB::table('categories')->insert(['id' => $id, 'name_ar' => $name, 'name_en' => 'C'.$id]);
foreach ([
    [1, 100, 1, 'بوري مشوي', '180.00'], [2, 100, 1, 'بلطي مقلي', '120.00'], [3, 100, 1, 'دنيس سنجاري', '320.00'],
    [4, 100, 2, 'جمبري جامبو', '650.00'], [5, 100, 2, 'كابوريا', '240.00'],
    [6, 100, 3, 'أرز صيادية', '40.00'], [7, 100, 3, 'سلطة طحينة', '25.00'], [8, 100, 3, 'شوربة سي فود', '95.50'],
    [9, 101, 1, 'بوري مشوي', '185.00'],
] as [$id, $branch, $category, $name, $price]) {
    DB::table('resturant_products')->insert(['id' => $id, 'resturant_id' => $branch, 'product_id' => 500 + $id, 'category_id' => $category,
        'product_name' => $name, 'product_price' => $price, 'status' => 'show', 'price' => '{}', 'created_at' => now(), 'updated_at' => now()]);
}
echo "seeded\n";
