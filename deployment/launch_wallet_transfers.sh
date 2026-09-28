#!/usr/bin/env bash
# Run in the authorized hosting console. The deploy-only SSH key cannot migrate.
set -euo pipefail
umask 022
WALLET_APP_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$WALLET_APP_ROOT"
test -f artisan && test -f .env
php -l app/Services/WalletTransfer.php
# Add the nullable unique retry reference only; preserve existing wallet entries.
php artisan migrate --force --path=database/migrations/2026_09_28_190000_add_transfer_reference_to_wallets.php
php artisan optimize:clear
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!Illuminate\Support\Facades\Schema::hasColumn("wallets", "transfer_reference")) { fwrite(STDERR, "Wallet transfer schema incomplete.\n"); exit(1); } if (App\Services\WalletTransfer::TARGETS !== ["go_customer", "go_partner", "fasakhansta_customer"]) { fwrite(STDERR, "Wallet destination code mismatch.\n"); exit(1); } echo "WALLET TRANSFERS READY\n";'
