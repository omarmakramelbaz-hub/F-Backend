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
            'print_invoice', 'all_branches', 'quantity']),
        'pos_service' => $pick('pos_service', ['details', 'takeaway', 'dine', 'phone', 'unpaid_bill', 'cancelled', 'customer', 'phone_number',
            'delivery', 'mixed', 'unpaid', 'channel', 'table', 'waiter', 'guests', 'area', 'address', 'delivery_notes',
            'kitchen_ticket', 'ticket_number', 'sent_at', 'revision', 'kitchen_copy']),
        'dining' => $pick('dining', ['title']),
        'phone_orders' => $pick('phone_orders', ['title', 'app_delivery_distance', 'branch_tariff_fixed', 'branch_tariff_free', 'branch_tariff_per_km', 'currency',
            'batch_receipt', 'courier_company', 'courier_name', 'cashier', 'saved_number', 'total', 'payment_method', 'cash', 'cash_received', 'change',
            'payment_reference', 'batch_paid_note']),
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

// Dine-in and delivery orders before payment: the bill (admin.pos_service.ticket) and the kitchen ticket (admin.pos_service.kitchen).
$ticket = fn (array $values) => array_merge([
    'id' => 12, 'channel' => 'dine', 'branch' => $branch, 'revision' => 3, 'status' => 'awaiting_bill', 'payment_status' => 'unpaid',
    'table' => ['id' => 4, 'name' => 'ترابيزة ٤', 'capacity' => 6], 'waiter_name' => 'حسن', 'guest_count' => 3, 'customer_name' => 'أسرة محمد',
    'customer_phone' => '', 'address' => '', 'area' => '', 'delivery_notes' => '', 'customer_id' => null, 'customer_revision' => null,
    'delivery_company' => null, 'delivery_location' => null, 'delivery_fee' => '0.00', 'notes' => "بدون بصل\nسريع", 'cancel_reason' => '',
    'created_at' => '2026-10-31T13:19:44+02:00', 'updated_at' => '2026-10-31T13:25:02+02:00', 'cashier' => ['id' => 4, 'name' => 'كاشير المعادي'],
    'kitchen_sent' => true, 'bill_locked' => true, 'bill_issued_at' => '2026-10-31T13:25:02+02:00', 'bill_issued_by' => 4, 'bill_issued_revision' => 3,
    'details_url' => 'https://fasakhaninja.com/admin/dining/tickets/12/details', 'quote_hash' => str_repeat('a', 64), 'discount_reason' => '',
    'bill_print_url' => 'https://fasakhaninja.com/admin/dining/tickets/12/print', 'kitchen_print_url' => 'https://fasakhaninja.com/admin/dining/kitchen/5/print',
    'can_reprint' => false, 'receipt_url' => null, 'subtotal' => '320.00', 'discount' => '0.00', 'tax' => '50.18', 'tax_rate' => '14.00',
    'service' => '38.40', 'service_rate' => '12.00', 'delivery' => '0.00', 'total' => '408.58',
    'items' => [$line(1, 'بوري مشوي', 'f:0:extra_clean', 'تنظيف', 'weight', '1.500', '180.00', '270.00') + ['unit_price_cents' => 18000, 'total_cents' => 27000, 'quantity_millis' => 1500],
        $line(7, 'سلطة <طحينة>', '', '', 'piece', '2.000', '25.00', '50.00') + ['unit_price_cents' => 2500, 'total_cents' => 5000, 'quantity_millis' => 2000]],
], $values);
$phone = $ticket(['id' => 31, 'channel' => 'phone', 'status' => 'out_for_delivery', 'table' => null, 'waiter_name' => '', 'guest_count' => null,
    'customer_name' => 'منى', 'customer_phone' => '+201112223334', 'address' => 'شارع النصر، عمارة ٥', 'area' => 'المعادي', 'delivery_notes' => 'الدور الثالث',
    'delivery_company' => ['id' => 1, 'name' => 'شركة سريع', 'phone' => '01011112222'], 'delivery_fee' => '25.00', 'delivery' => '25.00', 'service' => '0.00',
    'service_rate' => '0.00', 'tax' => '48.30', 'total' => '393.30', 'number' => 'PH100-S7-0003', 'notes' => '',
    'delivery_location' => ['method' => 'app_direct', 'pricing_rule' => 'app_branch_tier', 'distance_km' => '4.20', 'km_price' => '6.00', 'latitude' => 29.96, 'longitude' => 31.25]]);
$cases[] = ['name' => 'dine-in bill', 'kind' => 'ticket', 'preview' => false, 'dashboard_print' => true, 'ticket' => $ticket([])];
$cases[] = ['name' => 'dine-in bill details', 'kind' => 'ticket', 'preview' => true, 'dashboard_print' => false, 'ticket' => $ticket(['status' => 'preparing', 'bill_locked' => false])];
$cases[] = ['name' => 'delivery bill with the app distance', 'kind' => 'ticket', 'preview' => false, 'dashboard_print' => false, 'ticket' => $phone];
$cases[] = ['name' => 'delivery bill priced per kilometre', 'kind' => 'ticket', 'preview' => false, 'dashboard_print' => true,
    'ticket' => array_merge($phone, ['delivery_location' => ['method' => 'road', 'pricing_rule' => 'per_km', 'distance_km' => '3.10', 'km_price' => '7.50', 'latitude' => 30.01, 'longitude' => 31.2]])];
$cases[] = ['name' => 'dine-in kitchen ticket', 'kind' => 'kitchen', 'dashboard_print' => true,
    'kitchen' => ['id' => 5, 'ticket_id' => 12, 'revision' => 2, 'created_at' => '2026-10-31T13:20:10+02:00', 'ticket' => $ticket(['revision' => 2, 'status' => 'preparing'])]];
$cases[] = ['name' => 'delivery kitchen ticket', 'kind' => 'kitchen', 'dashboard_print' => false,
    'kitchen' => ['id' => 9, 'ticket_id' => 31, 'revision' => 1, 'created_at' => '2026-10-31T22:05:00+02:00', 'ticket' => array_merge($phone, ['notes' => 'حار'])]];

// The courier's batch receipt (admin.phone_orders.batch_receipt): several delivery orders collected together.
$batch = ['id' => 5000000000003, 'branch' => $branch, 'cashier' => ['id' => 4, 'name' => 'كاشير المعادي'], 'company' => ['id' => 1, 'name' => 'شركة سريع', 'phone' => '01011112222', 'contact_name' => ''],
    'courier_name' => 'سيد', 'created_at' => '2026-10-31T22:40:00+02:00', 'items' => [['ticket_id' => 31, 'order_id' => 9000000000012, 'total' => '105.00'], ['ticket_id' => 7000000000004, 'order_id' => 9000000000013, 'total' => '75.50']],
    'total' => '180.50', 'payment_method' => 'cash', 'cash_received' => '200.00', 'change' => '19.50', 'payment_reference' => '', 'idempotency_key' => '00000000-0000-4000-9000-000000000003'];
$cases[] = ['name' => 'courier batch receipt', 'kind' => 'batch', 'dashboard_print' => true, 'batch' => $batch];
$cases[] = ['name' => 'courier batch receipt without a courier name', 'kind' => 'batch', 'dashboard_print' => false, 'batch' => array_merge($batch, ['courier_name' => '', 'payment_reference' => 'وصل ٥'])];

// The branch's closing invoice (admin.branch_shifts.receipt): a closing made offline shows and prints it from the device.
$shift = ['number' => 'SHIFT-000006', 'sequence' => 6, 'branch' => $branch, 'cashier' => ['id' => 1, 'name' => 'المالك'],
    'started_at' => '2026-10-30 06:00:00', 'closed_at' => '2026-10-30 23:15:00',
    'channels' => ['dine' => ['count' => 2, 'gross' => '400.00'], 'takeaway' => ['count' => 4, 'gross' => '530.00'], 'phone' => ['count' => 2, 'gross' => '360.00'], 'app' => ['count' => 1, 'gross' => '120.00']],
    'sales_total' => '1410.00', 'delivery_total' => '80.00', 'expenses_total' => '125.00', 'net_sales' => '1205.00', 'opening_cash' => '500.00', 'cash_sales' => '1110.00',
    'app_branch_cash' => '0.00', 'cash_delivery' => '60.00', 'cash_expenses' => '125.00', 'other_cash_movements' => '-50.00', 'counted_cash' => '1350.00',
    'expected_cash' => '1375.00', 'variance_label' => 'عجز', 'variance' => '-25.00', 'noncash_sales' => '180.00', 'app_outside_drawer' => '100.00',
    'pending_expenses' => 0, 'unsettled_app_cash' => 2, 'notes' => 'قفلة الليل & <العد> "٢" \'ب\'', 'drawer_preserved' => true];
$cases[] = ['name' => 'closing invoice preview', 'kind' => 'shift', 'preview' => true, 'dashboard_print' => false, 'report' => $shift];
$cases[] = ['name' => 'closing invoice print page', 'kind' => 'shift', 'preview' => false, 'dashboard_print' => false,
    'report' => array_merge($shift, ['variance_label' => 'مطابق', 'variance' => '0.00', 'pending_expenses' => 1, 'unsettled_app_cash' => 0, 'notes' => '', 'drawer_preserved' => false])];

foreach ($cases as &$case) {
    $kind = $case['kind'] ?? 'receipt';
    $path = $kind === 'kitchen' ? 'dining/kitchen/5/print' : ($kind === 'ticket' ? 'dining/tickets/12/'.($case['preview'] ? 'details' : 'print')
        : ($kind === 'batch' ? 'phone-orders/batches/5000000000003/print' : ($kind === 'shift' ? 'branch-shifts/6/'.($case['preview'] ? 'details' : 'print')
        : 'takeaway/receipts/9000000000001/'.($case['preview'] ? 'details' : 'print'))));
    $request = Illuminate\Http\Request::create('https://fasakhaninja.com/admin/'.$path, 'GET', $case['dashboard_print'] ? ['dashboard_print' => '1'] : []);
    app()->instance('request', $request);
    app('url')->setRequest($request);
    $case['html'] = match ($kind) {
        'kitchen' => view('admin.pos_service.kitchen', ['kitchen' => $case['kitchen']])->render(),
        'batch' => view('admin.phone_orders.batch_receipt', ['batch' => $case['batch']])->render(),
        'shift' => view('admin.branch_shifts.receipt', ['report' => $case['report'], 'preview' => $case['preview']])->render(),
        'ticket' => view('admin.pos_service.ticket', ['ticket' => $case['ticket'], 'preview' => $case['preview']])->render(),
        default => view('admin.takeaway.receipt', ['receipt' => $case['receipt'], 'preview' => $case['preview']])->render(),
    };
}
unset($case);
echo json_encode(['cases' => $cases], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
