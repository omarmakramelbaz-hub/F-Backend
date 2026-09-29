#!/usr/bin/env bash
# Run once in the hosting console; the deploy-only identity cannot migrate.
set -euo pipefail
GO_STORE_ORDERS_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$GO_STORE_ORDERS_ROOT"
php artisan migrate --force --path=database/migrations/2026_09_30_000001_create_go_store_orders.php
php artisan optimize:clear
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!App\Services\GoStores\Orders::ready()) {fwrite(STDERR,"GO store order schema incomplete\n");exit(1);} echo "GO STORE ORDERS READY\n";'
