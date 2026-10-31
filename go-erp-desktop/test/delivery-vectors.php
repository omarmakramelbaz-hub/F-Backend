<?php
// The dashboard's own delivery fee (BranchDeliveryTariff, as PhoneDelivery::quote builds it) for sample branches and
// customer locations. The app prices delivery orders offline the same way and the tests compare both:
//   php go-erp-desktop/test/delivery-vectors.php > go-erp-desktop/test/fixtures/delivery-vectors.json
putenv('APP_KEY=base64:'.base64_encode(str_repeat('r', 32)));
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Dashboard\BranchDeliveryTariff;
use App\Services\Dashboard\PosServiceTicket;

$branches = [
    'tiers' => ['latitude' => 29.9602, 'longitude' => 31.2569, 'rates' => BranchDeliveryTariff::rates(['km_price' => '10.00', 'default_0_1' => '15.00', 'default_1_2' => '20.00', 'default_2_3' => '25.00'])],
    'legacy' => ['latitude' => 30.0444, 'longitude' => 31.2357, 'rates' => BranchDeliveryTariff::rates(['km_price' => '7.50'])],
    'free' => ['latitude' => 31.2001, 'longitude' => 29.9187, 'rates' => BranchDeliveryTariff::rates(['km_price' => '0', 'default_0_1' => '5.00', 'default_1_2' => '5.00', 'default_2_3' => '5.00'])],
];
$points = [[29.9602, 31.2569], [29.9641, 31.2601], [29.9702, 31.2569], [29.9781, 31.2399], [29.9901, 31.2811], [30.0102, 31.3017], [30.1201, 31.4004],
    [30.0444, 31.2357], [30.0512345, 31.2401234], [30.0619876, 31.2001234], [31.2101, 29.9287], [29.95, 31.25]];
$cases = [];
foreach ($branches as $name => $branch) {
    foreach ($points as [$latitude, $longitude]) {
        $lat = round($latitude, 7); $lng = round($longitude, 7);
        $distance = BranchDeliveryTariff::distance($branch['latitude'], $branch['longitude'], $lat, $lng);
        $data = ['branch' => 'f:100', 'branch_latitude' => $branch['latitude'], 'branch_longitude' => $branch['longitude'], 'latitude' => $lat, 'longitude' => $lng,
            'distance_meters' => (int) round($distance), 'distance_km' => number_format($distance / 1000, 3, '.', ''), 'km_price' => $branch['rates']['km_price'],
            'method' => 'app_direct'] + BranchDeliveryTariff::price($distance, $branch['rates']);
        $data['delivery_quote_hash'] = PosServiceTicket::fingerprint($data);
        $cases[] = ['name' => $name, 'settings' => ['latitude' => $branch['latitude'], 'longitude' => $branch['longitude'], 'km_price' => $branch['rates']['km_price'],
            'pricing_rates' => $branch['rates'], 'ready' => true], 'point' => [$latitude, $longitude], 'delivery' => $data];
    }
}
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)."\n";
