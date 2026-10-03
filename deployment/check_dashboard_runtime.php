<?php

// Read-only rollout checks. Never print credentials, customer messages or payloads.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!Illuminate\Support\Facades\Schema::hasColumns('order_board_clocks', ['source', 'order_id', 'accepted_at', 'courier_at', 'closed_at'])) {
    fwrite(STDERR, "Order clock schema is not ready.\n");
    exit(1);
}
echo "ORDER CLOCK SCHEMA READY\n";

$takeawaySchema = [
    'takeaway_tills'=>['branch', 'balance_cents', 'tax_bps', 'revision'],
    'takeaway_orders'=>['branch', 'actor_id', 'request_key', 'request_hash', 'total_cents', 'discount_reason'],
    'takeaway_order_items'=>['order_id', 'product_id', 'quantity_millis', 'unit_price_cents', 'total_cents'],
    'takeaway_till_entries'=>['till_id', 'branch', 'request_key', 'amount_cents', 'balance_cents'],
];
foreach ($takeawaySchema as $table=>$columns) {
    if (!Illuminate\Support\Facades\Schema::hasColumns($table, $columns)) {
        fwrite(STDERR, "Takeaway POS schema is not ready.\n");
        exit(1);
    }
}
if (!Illuminate\Support\Facades\Route::has('takeaway.checkout') || !Illuminate\Support\Facades\Route::has('takeaway.print')) {
    fwrite(STDERR, "Takeaway POS routes are not ready.\n");
    exit(1);
}
echo "TAKEAWAY POS READY\n";

$firebasePath = config('firebase.credentials', storage_path('app/firebase_credentials.json'));
echo is_string($firebasePath) && is_file($firebasePath) && is_readable($firebasePath) && filled(config('services.fcm.project_id'))
    ? "MANUAL NOTIFICATION CONFIG READY\n"
    : "MANUAL NOTIFICATION CONFIG: existing Firebase credentials need checking.\n";

$proof = filled(config('dashboard_payments.hmac_secret'))
    && filled(config('dashboard_payments.integrations.online'))
    && filled(config('dashboard_payments.integrations.v_cash'));
echo $proof ? "PAYMENT FAILURE PROOF CONFIG READY\n"
    : "PAYMENT FAILURE PROOF CONFIG: existing HMAC/integration credentials need checking.\n";

try {
    $central = app(App\Services\Dashboard\SupportInbox::class)->centralId();
    app(App\Services\Dashboard\SupportFirestore::class)->rooms($central);
    echo "SUPPORT INBOX CONNECTION READY\n";
} catch (Throwable $error) {
    echo "SUPPORT INBOX CONNECTION: existing Firebase credentials/Firestore access need checking.\n";
}
