<?php
// The local dashboard's dine-in tables and one open delivery order (placing a delivery order needs the map and the
// internet, so it is written as the delivery page saves it). Runs after seed.php.
require __DIR__.'/env.php';
$app = require GOERP_ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
goerp_settings($app);

use Illuminate\Support\Facades\DB;

foreach ([['ترابيزة 1', 4], ['ترابيزة 2', 6]] as [$name, $capacity]) {
    DB::table('pos_service_tables')->insert(['branch' => 'f:100', 'name' => $name, 'capacity' => $capacity, 'active' => true, 'revision' => 1,
        'created_at' => now(), 'updated_at' => now()]);
}
// 2 × أرز صيادية (40.00) + 25.00 delivery = 105.00
$cart = ['branch' => 'f:100', 'items' => [['product_id' => 6, 'quantity' => '2', 'quantity_mode' => 'piece', 'option_id' => '']], 'discount' => '0.00', 'discount_reason' => ''];
$quote = app(App\Services\Dashboard\TakeawayService::class)->quote($cart, App\Models\User::find(4), ['channel' => 'phone', 'service_bps' => 0, 'delivery_cents' => 2500]);
$saved = ['branch' => 'f:100', 'items' => array_map(fn ($line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'],
    'quantity_mode' => $line['quantity_mode'], 'option_id' => $line['option_id']], $quote['items']), 'discount' => $quote['discount'], 'discount_reason' => ''];
DB::table('pos_service_tickets')->insert(['branch' => 'f:100', 'channel' => 'phone', 'customer_name' => 'منى', 'customer_phone' => '+201112223334',
    'address' => 'شارع النصر، عمارة ٥', 'area' => 'المعادي', 'delivery_cents' => 2500, 'cart_snapshot' => json_encode($saved, JSON_UNESCAPED_UNICODE),
    'quote_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE), 'revision' => 1, 'actor_id' => 4, 'status' => 'preparing', 'payment_status' => 'unpaid',
    'business_date' => App\Services\Dashboard\OperatingDay::date(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
// Two more orders already with the courier of شركة سريع: the courier brings the money for both together.
$company = json_encode(['id' => 1, 'name' => 'شركة سريع', 'phone' => '01011112222', 'contact_name' => ''], JSON_UNESCAPED_UNICODE);
foreach (['عميل التوصيل ١', 'عميل التوصيل ٢'] as $name) {
    DB::table('pos_service_tickets')->insert(['branch' => 'f:100', 'channel' => 'phone', 'customer_name' => $name, 'customer_phone' => '+201112223335',
        'address' => 'المعادي', 'area' => 'المعادي', 'delivery_cents' => 2500, 'cart_snapshot' => json_encode($saved, JSON_UNESCAPED_UNICODE),
        'quote_snapshot' => json_encode($quote, JSON_UNESCAPED_UNICODE), 'revision' => 2, 'actor_id' => 4, 'status' => 'out_for_delivery', 'payment_status' => 'unpaid',
        'delivery_company_id' => 1, 'delivery_company_snapshot' => $company, 'business_date' => App\Services\Dashboard\OperatingDay::date(),
        'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
}
// The branch's pin (المعادي) and delivery tariff, and a returning delivery customer with her saved location (about 1.1 km).
DB::table('resturants')->where('id', 100)->update(['lat' => 29.9602, 'lng' => 31.2569, 'km_price' => '10.00', 'default_0_1' => '15.00',
    'default_1_2' => '20.00', 'default_2_3' => '25.00']);
DB::table('branch_customers')->insert(['branch' => 'f:100', 'name' => 'هبة', 'phone' => '01055556666', 'phone_key' => '01055556666',
    'address' => 'شارع ٢٠٠، دجلة', 'area' => 'المعادي', 'delivery_notes' => 'جنب الصيدلية', 'latitude' => 29.9702, 'longitude' => 31.2569,
    'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
echo "service orders: ".$quote['total']."\n";
