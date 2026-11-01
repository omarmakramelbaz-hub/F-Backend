<?php
/** Focused Owner-only card checks reuse the isolated actual-service runtime. */
require __DIR__.'/run.php';

use Illuminate\Support\Facades\{DB, Route};
use App\Services\Dashboard\{HomeHologramMetrics, HomeHudLinks};

$before = $checks;
Route::get('/admin/dashboard', fn()=>null)->name('admin_dash');
Route::get('/admin/branch-shifts', fn()=>null)->name('branch-shifts.index');
app('router')->getRoutes()->refreshNameLookups();
row('users', ['id'=>2, 'name'=>'Other admin', 'account_type'=>'admin']);
table('permissions', ['id'=>'integer', 'name'=>'string', 'guard_name'=>'string']);
row('permissions', ['id'=>1, 'name'=>'order-list', 'guard_name'=>'admin']);
fresh();
$owner = array_column(app(HomeHologramMetrics::class)->data(HologramFixtureUser::findOrFail(1), ['drawer'=>'/admin/branch-shifts'])['cards'], null, 'key')['drawer'];
same('رصيد الفروع', $owner['label'], 'Only primary Owner gets branch-balance label');
same('currency', $owner['value_type'], 'Owner balance is a currency card');
same('EGP', $owner['currency'], 'Owner balance uses Egyptian pounds');
same(null, $owner['count'], 'Owner balance never displays number of branches as a money amount');
same(null, $owner['amount_cents'], 'Initial Owner balance is unknown, never a fake zero');
same(true, $owner['loading'], 'Owner balance is explicitly loading while canonical read is pending');
same(false, $owner['available'], 'Money becomes available only after a successful balance read');
same('owner-balances', $owner['action'], 'Owner click requests existing branch-balance details');
same('all', $owner['balance_scope'], 'Money total always uses all authorized branches');
same(['f:100', 'f:101', 'f:102'], $owner['balance_branches'], 'Currency descriptor binds exact persisted branch grants');
same(route('dashboard.overview', ['section'=>'drawers']), $owner['balance_url'], 'Money uses existing canonical drawer read without selected branch');
same(route('admin_dash').'#ho-drawers-title', $owner['url'], 'Owner click targets existing detailed balance panel');
$admin = array_column(app(HomeHologramMetrics::class)->data(HologramFixtureUser::findOrFail(2), ['drawer'=>'/admin/branch-shifts'])['cards'], null, 'key')['drawer'];
same('تقفيل الوردية', $admin['label'], 'Other central admin keeps original shift-closing label');
same(3, $admin['count'], 'Other central admin keeps original branch count');
same('/admin/branch-shifts?branch=all', $admin['url'], 'Other admin keeps original shift-closing destination');
foreach (['value_type', 'amount_cents', 'currency', 'loading', 'action', 'balance_url', 'balance_scope', 'balance_branches'] as $key) {
    same(false, array_key_exists($key, $admin), 'Other admin never receives Owner monetary metadata: '.$key);
}
$spoof = HologramFixtureUser::findOrFail(10); $spoof->account_type = 'admin';
$branch = app(HomeHologramMetrics::class)->data($spoof, ['drawer'=>'/admin/branch-shifts'])['cards'][0];
same(false, array_key_exists('amount_cents', $branch), 'Spoofed branch account cannot acquire Owner currency fields');
app()->instance('owner.dashboard.runtime', ['prefix'=>'fixture_owner']);
$tenant = app(HomeHologramMetrics::class)->data(HologramFixtureUser::findOrFail(1), ['drawer'=>'/admin/branch-shifts'])['cards'][0];
same('رصيد الفروع', $tenant['label'], 'Tenant-local id-1 Owner remains eligible');
same('currency', $tenant['value_type'], 'Tenant-local Owner uses same currency contract');
app()->forgetInstance('owner.dashboard.runtime');

// No reconciliation tables are installed by this fixture. Reconstructing a
// shift would fail; producing the Owner descriptor remains a cheap read.
same(route('admin_dash').'#ho-drawers-title', HomeHudLinks::for(HologramFixtureUser::findOrFail(1))['drawer'], 'Owner navigation also points to branch-balance details');
same(route('branch-shifts.index'), HomeHudLinks::for(HologramFixtureUser::findOrFail(2))['drawer'], 'Other admin navigation remains unchanged');
echo 'PASS Owner-only balance card: '.($checks-$before)." assertions\n";
