#!/usr/bin/env bash
set -euo pipefail
GO_IMAGE_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$GO_IMAGE_ROOT"
test -f artisan
test -f .env
php -l app/Services/GoStores/ProductImageSearch.php
php -l app/Services/GoStores/AutomaticProductImages.php
php -l app/Console/Commands/FindGoProductImages.php
# Read the configured environment before applying the new table.
php artisan config:clear
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!app(App\Services\GoStores\ProductImageSearch::class)->configured()) { fwrite(STDERR,"Configure GO_PRODUCT_IMAGES_ENABLED, GO_PRODUCT_IMAGES_OPENAI_KEY and GO_PRODUCT_IMAGES_BRAVE_KEY in the server environment.\n"); exit(1); } echo "GO PRODUCT IMAGE PROVIDERS CONFIGURED\n";'
php artisan go-stores:find-product-images --check="${1:-تفاح أحمر}"
php artisan migrate --force --path=database/migrations/2026_10_07_180000_create_go_product_image_requests.php
php artisan optimize:clear
echo 'The existing Laravel schedule:run cron processes further products automatically.'
