<?php
// Regenerates test/fixtures/quote-vectors.json from the dashboard's own pricing code:
//   php go-erp-desktop/test/quote-vectors.php > go-erp-desktop/test/fixtures/quote-vectors.json
// The desktop app must produce exactly these totals, or the server rejects its offline sales.
putenv('APP_KEY=base64:'.base64_encode(str_repeat('q', 32)));
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
mt_srand(20261030);
$vectors = [];
for ($case = 0; $case < 400; $case++) {
    $products = [];
    for ($p = 1; $p <= 6; $p++) {
        $variants = [['option_id' => '', 'label' => 'الأساسي', 'unit_price_cents' => mt_rand(1, 250000), 'quantity_mode' => ['piece', 'weight', 'select'][mt_rand(0, 2)], 'inventory' => []]];
        if (mt_rand(0, 1)) $variants[] = ['option_id' => 'f:'.$p.':extra_clean', 'label' => 'تنظيف', 'unit_price_cents' => mt_rand(1, 250000), 'quantity_mode' => 'select', 'inventory' => []];
        $products[] = ['id' => $p, 'name' => 'صنف '.$p, 'unit' => '', 'variants' => $variants];
    }
    $snapshot = ['branch' => ['value' => 'f:1'], 'tax_bps' => [0, 1400, 1250, 999][mt_rand(0, 3)], 'service_bps' => [0, 1200, 1000][mt_rand(0, 2)],
        'can_discount' => true, 'products' => $products];
    $channel = ['takeaway', 'dine', 'phone'][mt_rand(0, 2)];
    $items = [];
    for ($i = 0, $n = mt_rand(1, 7); $i < $n; $i++) {
        $product = $products[mt_rand(0, 5)];
        $variant = $product['variants'][mt_rand(0, count($product['variants']) - 1)];
        $mode = $variant['quantity_mode'] === 'select' ? ['piece', 'weight'][mt_rand(0, 1)] : $variant['quantity_mode'];
        $quantity = $mode === 'piece' ? (string) mt_rand(1, 12) : mt_rand(0, 9).'.'.str_pad((string) mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        if ($quantity === '0.000') $quantity = '0.001';
        $items[] = ['product_id' => $product['id'], 'option_id' => $variant['option_id'], 'quantity_mode' => $mode, 'quantity' => $quantity];
    }
    $delivery = $channel === 'phone' ? mt_rand(0, 5000) : 0;
    try {
        $cart = app(App\Services\Dashboard\TakeawayService::class)->canonicalCart(['branch' => 'f:1', 'items' => $items, 'discount' => '0.00']);
        $sub = app(App\Services\Dashboard\DesktopPosQuote::class)->build($snapshot, $cart, $channel, $delivery)['subtotal_cents'];
        $discount = mt_rand(0, 3) === 0 ? mt_rand(0, $sub) : 0;
        $cart = app(App\Services\Dashboard\TakeawayService::class)->canonicalCart(['branch' => 'f:1', 'items' => $items,
            'discount' => App\Services\GoServices\Money::decimal($discount), 'discount_reason' => $discount ? 'عميل دائم' : '']);
        $quote = app(App\Services\Dashboard\DesktopPosQuote::class)->build($snapshot, $cart, $channel, $delivery);
        $expected = ['ok' => true];
        foreach (['subtotal_cents', 'discount_cents', 'service_cents', 'delivery_cents', 'tax_cents', 'total_cents'] as $key) $expected[$key] = $quote[$key];
    } catch (Throwable $error) {
        $discount = 0;
        $expected = ['ok' => false];
    }
    $vectors[] = ['catalog' => $snapshot, 'cart' => ['channel' => $channel, 'items' => $items, 'discount_cents' => $discount, 'delivery_cents' => $delivery], 'expected' => $expected];
}
echo json_encode($vectors, JSON_UNESCAPED_UNICODE), "\n";
