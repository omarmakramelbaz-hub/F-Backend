#!/usr/bin/env bash
# Run in the hosting console. The deploy-only SSH identity cannot run migrations.
set -euo pipefail
umask 022
GO_CATALOG_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$GO_CATALOG_ROOT"
test -f artisan
test -f .env
php -l app/Services/GoStores/Catalog.php
# Only two new GO catalog tables. No existing orders, menus, payments or balances.
php artisan migrate --force --path=database/migrations/2026_09_27_180000_create_go_store_catalog.php
php artisan optimize:clear
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach (["go_stores"=>["user_id","name","kind","address","revision"],"go_store_products"=>["user_id","request_key","price_cents","image_path","options","revision"]] as $table=>$columns) { foreach ($columns as $column) { if (!Illuminate\Support\Facades\Schema::hasColumn($table,$column)) { fwrite(STDERR,"GO store catalog schema incomplete.\n"); exit(1); } } } echo "GO STORE CATALOG READY\n";'
