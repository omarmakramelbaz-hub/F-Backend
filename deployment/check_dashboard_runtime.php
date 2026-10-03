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
