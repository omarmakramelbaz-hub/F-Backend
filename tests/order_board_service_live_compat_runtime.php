<?php
/** Real service + persisted 6df7fec behavior, combined with inline quantity/print changes. */
require __DIR__.'/order_board_items_runtime.php';

use App\Services\Dashboard\OrderBoardService;
use App\Services\Dashboard\OrderBoardPrinting;
use App\Services\Dashboard\LegacyOrderCancellation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$priorChecks = $checks;
Schema::create('products', function (Blueprint $table) { $table->id(); $table->boolean('has_clean')->default(false); });
Schema::table('carts', function (Blueprint $table) { $table->string('option_label')->nullable(); });
DB::table('products')->insert([['id'=>1000,'has_clean'=>true],['id'=>1001,'has_clean'=>false]]);

// Global, actor-scoped new-order alerts retain their independent query.
seedLegacy(20); DB::table('orders')->where('id',20)->update(['status'=>'pending','accepted_notify'=>'no']);
seedLegacy(21); DB::table('orders')->where('id',21)->update(['resturant_id'=>101,'status'=>'pending','accepted_notify'=>'no']);
seedLegacy(22); DB::table('orders')->where('id',22)->update(['status'=>'pending','accepted_notify'=>'yes']);
$service=app(OrderBoardService::class);
same(['legacy:20'],$service->pendingAlertKeys(actor(10)),'new-order alerts remain complete for the persisted cashier branch');
same(['legacy:21'],$service->pendingAlertKeys(actor(11)),'alerts never leak another branch orders');
same(['legacy:20'],$service->pendingAlertKeys(actor(41)),'branch-linked administrator alerts use persisted restaurant identity');
blocked(fn()=>$service->pendingAlertKeys(actor(43)),403,'customer cannot obtain dashboard alert keys');

// Visible preparation labels preserve saved selections even after the catalog changes.
seedLegacy(24);
$card=app(OrderBoardService::class)->detail('legacy',24,actor(10));
same('half / تنظيف',$card['items'][0]['option_label'],'fraction and cleaning remain visible alongside real line identity');
same(240,$card['items'][0]['line_id'],'label preservation retains new inline editor cart identity');
same('1.000',$card['items'][0]['display_quantity'],'known kilogram saved half portion retains actual weight display');
same('', $card['items'][1]['option_label'],'ordinary side has no invented preparation label');
DB::table('carts')->where('id',240)->update(['product_feature'=>null,'product_clean'=>null]);
$card=app(OrderBoardService::class)->detail('legacy',24,actor(10));
same('كامل',$card['items'][0]['option_label'],'base fish preparation retains customer-app whole label from saved product definition');
DB::table('carts')->where('id',240)->update(['product_clean'=>'extra_clear']);
same('مخلي',app(OrderBoardService::class)->detail('legacy',24,actor(10))['items'][0]['option_label'],'filleted preparation is retained');
DB::table('carts')->where('id',240)->update(['product_clean'=>'extra_vacuim']);
same('فاكيوم',app(OrderBoardService::class)->detail('legacy',24,actor(10))['items'][0]['option_label'],'legacy vacuum selection is retained');
DB::table('carts')->where('id',240)->update(['product_clean'=>'extra_clean','option_label'=>'اختيار محفوظ']);
same('اختيار محفوظ / تنظيف',app(OrderBoardService::class)->detail('legacy',24,actor(10))['items'][0]['option_label'],'saved option text survives current catalog configuration');
DB::table('carts')->where('id',240)->update(['product_feature'=>52,'product_clean'=>null,'option_label'=>null]);
same('كامل',app(OrderBoardService::class)->detail('legacy',24,actor(10))['items'][0]['option_label'],'unrelated product feature cannot invent an option label');

// Accepted preparation cancellation remains available independently of invoice issuance.
$row=DB::table('orders')->where('id',24)->first();
same(['prepare','dispatch','cancel'],app(OrderBoardService::class)->legacyActions($row),'accepted preparing order retains cancellation action');
$row->delegate_from_out='in_resturant';
same(['complete'],app(OrderBoardService::class)->legacyActions($row),'handoff with branch courier allows completion only');
$row->delegate_from_out='out_resturant'; $row->delegate_id=999;
same(['cancel'],app(OrderBoardService::class)->legacyActions($row),'selected external courier does not remove pre-handoff cancellation');
$row->transfer_price_by=10;
same([],app(OrderBoardService::class)->legacyActions($row),'collected order cannot be cancelled');
same(false,app(LegacyOrderCancellation::class)->available($row),'restored lifecycle helper uses original collected-order guard');

// New first-print enforcement coexists with old lifecycle controls and labels.
$service=app(OrderBoardService::class);
app(OrderBoardPrinting::class)->issue('legacy',24,actor(10),$service);
$card=app(OrderBoardService::class)->detail('legacy',24,actor(10));
same(false,$card['can_print'],'issued invoice still loses branch reprint permission');
same(false,isset($card['urls']['items']),'issued invoice still locks quantity edits');
same(['prepare','dispatch','cancel'],$card['actions'],'invoice issuance does not erase lifecycle cancellation controls');
same('كامل',$card['items'][0]['option_label'],'printed invoice retains live preparation label');
echo 'Order board live compatibility runtime: '.($checks-$priorChecks)." focused assertions passed.\n";
