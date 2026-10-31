<?php
// Renders the dashboard's own receipt page (resources/views/admin/takeaway/receipt.blade.php) for sample invoices.
// The desktop app prints offline invoices with the same page and must produce the same HTML:
//   php go-erp-desktop/test/receipt-fixtures.php > go-erp-desktop/test/fixtures/receipts.json
//   php go-erp-desktop/test/receipt-fixtures.php labels > go-erp-desktop/src/main/offline/labels.json
putenv('APP_KEY=base64:'.base64_encode(str_repeat('r', 32)));
putenv('APP_URL=https://fasakhaninja.com');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app()->setLocale('ar');
config(['app.timezone' => 'Africa/Cairo']);

if (($argv[1] ?? '') === 'labels') {
    $pick = function (string $file, array $keys): array {
        $all = trans($file);
        $result = [];
        foreach ($keys as $key) $result[$key] = $all[$key];
        return $result;
    };
    echo json_encode([
        'takeaway' => $pick('takeaway', ['current_invoice', 'title', 'invoice_number', 'date', 'cashier', 'payment_method', 'payment_reference',
            'item', 'quantity_short', 'price', 'line_total', 'kg', 'piece', 'subtotal', 'currency', 'discount', 'tax', 'service', 'total',
            'cash', 'card', 'wallet', 'other', 'cash_paid', 'change', 'discount_reason', 'order_notes', 'receipt_thanks', 'receipt_welcome',
            'print_invoice', 'all_branches']),
        'pos_service' => $pick('pos_service', ['details', 'takeaway', 'dine', 'phone', 'unpaid_bill', 'cancelled', 'customer', 'phone_number',
            'delivery', 'mixed', 'unpaid', 'channel', 'table', 'waiter', 'guests', 'area', 'address', 'delivery_notes']),
        'dining' => $pick('dining', ['title']),
        'phone_orders' => $pick('phone_orders', ['title']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    exit;
}

$branch = ['value' => 'f:100', 'id' => 100, 'kind' => 'f', 'name' => 'فرع المعادي', 'label' => 'فرع المعادي', 'address' => 'شارع ٩ - المعادي', 'phone' => '01000000000'];
$line = fn ($id, $name, $option, $label, $mode, $quantity, $price, $total) => ['product_id' => $id, 'name' => $name, 'option_id' => $option,
    'option_label' => $label, 'unit' => '', 'quantity_mode' => $mode, 'quantity' => $quantity, 'unit_price' => $price, 'total' => $total];
$receipt = fn (array $values) => array_merge([
    'id' => 9000000000001, 'number' => 'A1B-1030-0001', 'idempotency_key' => '6f1c2b8e-1d2a-4c3b-9e8f-0a1b2c3d4e5f', 'branch' => $branch,
    'cashier' => ['id' => 4, 'name' => 'كاشير المعادي'], 'business_date' => '2026-10-30', 'created_at' => '2026-10-30T21:45:09+02:00',
    'items' => [$line(1, 'بوري مشوي', null, null, 'weight', '1.500', '180.00', '270.00'), $line(7, 'سلطة طحينة', null, null, 'piece', '2.000', '25.00', '50.00')],
    'payment_method' => 'cash', 'payment_confirmed' => true, 'payment_reference' => '', 'notes' => '', 'tax_rate' => '14.00', 'discount_reason' => '',
    'currency' => 'EGP', 'service' => '0.00', 'service_rate' => '0.00', 'delivery' => '0.00', 'channel' => 'takeaway', 'ticket_id' => null,
    'context' => ['customer_name' => 'أحمد', 'customer_phone' => '+201012345678'],
    'payment_breakdown' => [['method' => 'cash', 'amount' => '364.80', 'confirmed' => true, 'reference' => '']],
    'status' => 'completed', 'details_url' => 'https://fasakhaninja.com/admin/takeaway/receipts/9000000000001/details', 'can_reprint' => true,
    'receipt_url' => 'https://fasakhaninja.com/admin/takeaway/receipts/9000000000001/print',
    'subtotal' => '320.00', 'discount' => '0.00', 'tax' => '44.80', 'total' => '364.80', 'cash_received' => '400.00', 'change' => '35.20',
], $values);

$cases = [
    ['name' => 'takeaway print', 'preview' => false, 'dashboard_print' => false, 'receipt' => $receipt([])],
    ['name' => 'takeaway from the dashboard print frame', 'preview' => false, 'dashboard_print' => true, 'receipt' => $receipt([
        'notes' => "بدون بصل\nسريع", 'discount' => '20.00', 'discount_reason' => 'عميل دائم & "مميز"', 'context' => [],
        'items' => [$line(1, 'بوري مشوي', 'f:0:extra_clean', 'تنظيف', 'weight', '0.750', '200.00', '150.00'), $line(9, "صنف <خاص>", null, 'كامل', 'piece', '3.000', '1250.50', '3751.50')],
        'subtotal' => '3901.50', 'tax' => '543.41', 'total' => '4424.91', 'cash_received' => '4500.00', 'change' => '75.09', 'can_reprint' => false, 'receipt_url' => null,
    ])],
    ['name' => 'takeaway details', 'preview' => true, 'dashboard_print' => false, 'receipt' => $receipt(['created_at' => '2026-10-31T03:05:00+02:00'])],
    ['name' => 'dine-in with service', 'preview' => false, 'dashboard_print' => true, 'receipt' => $receipt([
        'channel' => 'dine', 'service' => '38.40', 'service_rate' => '12.00', 'tax' => '50.18', 'total' => '408.58', 'cash_received' => '500.00', 'change' => '91.42',
        'context' => ['customer_name' => 'أسرة محمد', 'table' => ['name' => 'ترابيزة ٤'], 'customer_phone' => '+201012345678'],
    ])],
    ['name' => 'phone delivery', 'preview' => false, 'dashboard_print' => true, 'receipt' => $receipt([
        'channel' => 'phone', 'delivery' => '25.00', 'tax' => '48.30', 'total' => '393.30', 'cash_received' => '393.30', 'change' => '0.00',
        'context' => ['customer_name' => 'منى', 'customer_phone' => '+201112223334', 'address' => 'شارع النصر، عمارة ٥', 'delivery_company' => ['id' => 1, 'name' => 'شركة سريع', 'phone' => '01011112222'], 'driver' => 'سيد'],
    ])],
];

foreach ($cases as &$case) {
    $request = Illuminate\Http\Request::create('https://fasakhaninja.com/admin/takeaway/receipts/9000000000001/'.($case['preview'] ? 'details' : 'print'), 'GET',
        $case['dashboard_print'] ? ['dashboard_print' => '1'] : []);
    app()->instance('request', $request);
    app('url')->setRequest($request);
    $case['html'] = view('admin.takeaway.receipt', ['receipt' => $case['receipt'], 'preview' => $case['preview']])->render();
}
unset($case);
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
