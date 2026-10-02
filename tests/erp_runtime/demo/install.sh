#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
composer install --working-dir=tests/erp_runtime --no-interaction --prefer-dist --no-progress
ERP_DEMO=1 php tests/erp_runtime/demo/setup.php
