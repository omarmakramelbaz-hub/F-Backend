<?php
// Records the dashboard's own answers for the dine-in and delivery pages (as a branch cashier, on a fresh test database), so the tests
// can check that the device answers in the same shape: php go-erp-desktop/test/dining-capture.php
// Written to the fixture, or to the file given (CI compares the shapes with the fixture's).
$target = $argv[1] ?? (__DIR__ . '/fixtures/dining.json');
putenv('GOERP_E2E_DIR=' . sys_get_temp_dir() . '/goerp-dining-capture');
$_SERVER['argv'] = ['seed'];
ob_start(); require __DIR__ . '/e2e/seed.php'; ob_end_clean();
use Illuminate\Support\Facades\DB;
DB::table('pos_service_tables')->insert(['branch' => 'f:100', 'name' => 'ترابيزة 1', 'capacity' => 4, 'active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); app()->instance('middleware.disable', true);
$user = App\Models\User::find(4);
$out = [];
$call = function ($method, $path, $body = null) use ($kernel, $user, &$out) {
    $request = Illuminate\Http\Request::create('http://127.0.0.1:8099' . $path, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $body ? json_encode($body) : null);
    app('auth')->guard('admin')->setUser($user);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    $response = $kernel->handle($request);
    $content = $response->getContent();
    $json = json_decode($content, true);
    $out[] = ['method' => $method, 'path' => $path, 'body' => $body, 'status' => $response->getStatusCode(), 'json' => $json, 'html' => $json === null ? substr($content, 0, 400) : null];
    return $json;
};
$tables = $call('GET', '/admin/dining/tables?branch=f:100');
$table = $tables['tables'][0]['id'] ?? null;
$items = [['product_id' => 1, 'quantity' => '1.000', 'quantity_mode' => 'weight'], ['product_id' => 7, 'quantity' => '2', 'quantity_mode' => 'piece']];
$quote = $call('POST', '/admin/dining/quote', ['branch' => 'f:100', 'items' => $items]);
$key = fn () => (string) Illuminate\Support\Str::uuid();
$saved = $call('POST', '/admin/dining/tickets/save', ['branch' => 'f:100', 'idempotency_key' => $key(), 'table_id' => $table, 'items' => $items, 'quote_hash' => $quote['quote_hash'] ?? '',
    'waiter_name' => 'حسن', 'guest_count' => 2, 'customer_name' => 'أسرة محمد', 'notes' => 'بدون بصل']);
$id = $saved['ticket']['id'] ?? 0; $rev = $saved['ticket']['revision'] ?? 1;
$kitchen = $call('POST', "/admin/dining/tickets/$id/action", ['branch' => 'f:100', 'idempotency_key' => $key(), 'expected_revision' => $rev, 'action' => 'send_kitchen']);
$rev = $kitchen['ticket']['revision'] ?? $rev;
$call('GET', "/admin/dining/tickets?branch=f:100");
$call('GET', "/admin/dining/tables?branch=f:100");
$bill = $call('POST', "/admin/dining/tickets/$id/action", ['branch' => 'f:100', 'idempotency_key' => $key(), 'expected_revision' => $rev, 'action' => 'request_bill']);
$rev = $bill['ticket']['revision'] ?? $rev;
$show = $call('GET', "/admin/dining/tickets/$id");
$settle = $call('POST', "/admin/dining/tickets/$id/settle", ['branch' => 'f:100', 'idempotency_key' => $key(), 'expected_revision' => $rev, 'quote_hash' => $show['ticket']['quote_hash'] ?? '',
    'payment_method' => 'cash', 'cash_received' => '300.00', 'customer_phone' => '01012345678', 'service_consent' => true]);
$call('GET', "/admin/dining/tickets/$id");
$call('GET', "/admin/dining/tables?branch=f:100");
// The delivery page: an order as the delivery page saves it (placing it needs the map and the internet), handed to a
// courier, its bill printed and collected.
DB::table('branch_delivery_companies')->insert(['id' => 7, 'branch' => 'f:100', 'name' => 'شركة سريع', 'phone' => '01011112222',
    'active' => true, 'revision' => 1, 'actor_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
$cart = ['branch' => 'f:100', 'items' => [['product_id' => 7, 'quantity' => '2', 'quantity_mode' => 'piece', 'option_id' => '']], 'discount' => '0.00', 'discount_reason' => ''];
$q = app(App\Services\Dashboard\TakeawayService::class)->quote($cart, $user, ['channel' => 'phone', 'service_bps' => 0, 'delivery_cents' => 2500]);
$saved = ['branch' => 'f:100', 'items' => array_map(fn ($line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity'],
    'quantity_mode' => $line['quantity_mode'], 'option_id' => $line['option_id']], $q['items']), 'discount' => $q['discount'], 'discount_reason' => ''];
$phone = DB::table('pos_service_tickets')->insertGetId(['branch' => 'f:100', 'channel' => 'phone', 'customer_name' => 'منى', 'customer_phone' => '+201112223334',
    'address' => 'شارع النصر، عمارة ٥', 'area' => 'المعادي', 'delivery_cents' => 2500, 'cart_snapshot' => json_encode($saved, JSON_UNESCAPED_UNICODE),
    'quote_snapshot' => json_encode($q, JSON_UNESCAPED_UNICODE), 'revision' => 1, 'actor_id' => 4, 'status' => 'preparing', 'payment_status' => 'unpaid',
    'business_date' => \App\Services\Dashboard\OperatingDay::date(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
$call('GET', '/admin/phone-orders/board?branch=f:100');
$call('GET', "/admin/phone-orders/tickets/$phone");
$quote = $call('POST', '/admin/phone-orders/quote', ['branch' => 'f:100', 'ticket_id' => $phone]);
$call('POST', '/admin/phone-orders/dispatch-company', ['branch' => 'f:100', 'ticket_id' => $phone, 'company_id' => 7, 'expected_revision' => 1, 'idempotency_key' => $key()]);
$call('POST', "/admin/phone-orders/tickets/$phone/action", ['branch' => 'f:100', 'idempotency_key' => $key(), 'expected_revision' => 2, 'action' => 'request_bill', 'reason' => '']);
$call('GET', '/admin/phone-orders/board?branch=f:100');
$call('POST', "/admin/phone-orders/tickets/$phone/settle", ['branch' => 'f:100', 'expected_revision' => 3, 'idempotency_key' => $key(), 'quote_hash' => $quote['quote_hash'] ?? '',
    'payment_method' => 'cash', 'cash_received' => '200.00', 'payment_confirmed' => true, 'payment_reference' => '']);
$call('GET', '/admin/phone-orders/board?branch=f:100');
// Two delivery orders a courier brought the money for, collected together.
$batch = [];
foreach ([1, 2] as $n) {
    $order = DB::table('pos_service_tickets')->insertGetId(['branch' => 'f:100', 'channel' => 'phone', 'customer_name' => 'عميل '.$n, 'customer_phone' => '+20111222333'.$n,
        'address' => 'المعادي', 'area' => 'المعادي', 'delivery_cents' => 2500, 'cart_snapshot' => json_encode($saved, JSON_UNESCAPED_UNICODE),
        'quote_snapshot' => json_encode($q, JSON_UNESCAPED_UNICODE), 'revision' => 1, 'actor_id' => 4, 'status' => 'preparing', 'payment_status' => 'unpaid',
        'business_date' => \App\Services\Dashboard\OperatingDay::date(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    $call('POST', '/admin/phone-orders/dispatch-company', ['branch' => 'f:100', 'ticket_id' => $order, 'company_id' => 7, 'expected_revision' => 1, 'idempotency_key' => $key()]);
    $batch[] = ['id' => $order, 'revision' => 2, 'quote_hash' => $q['quote_hash']];
}
$call('POST', '/admin/phone-orders/finish-batch', ['branch' => 'f:100', 'idempotency_key' => $key(), 'items' => $batch, 'courier_name' => 'سيد', 'total' => '150.00',
    'payment_method' => 'cash', 'cash_received' => '200.00', 'payment_reference' => '', 'payment_confirmed' => true]);
file_put_contents($target, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "captured " . count($out) . "\n";
foreach ($out as $o) echo $o['status'] . ' ' . $o['method'] . ' ' . $o['path'] . "\n";
