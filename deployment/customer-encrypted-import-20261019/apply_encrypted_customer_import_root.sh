#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
customer_code="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
customer_root=/home/fasakha/public_html
customer_zip=/home/fasakha/Fasakhansta-Customer-Import-20261018.zip
customer_php="$(command -v php || true)"
test -n "$customer_php"
test -x "$customer_php"
test -d "$customer_root"
test ! -L "$customer_root"
test "$(realpath -- "$customer_root")" = "$customer_root"
test "$(stat -c '%u' -- "$customer_root")" = "$(id -u fasakha)"
runuser -u fasakha -- test -x "$customer_php"

# Stage in the sticky /tmp directory before handing the completed stage to fasakha.
# Customer data is decoded and written only with the application owner's rights.
customer_stage="$(mktemp -d /tmp/customer-private-transfer.XXXXXXXX)"
trap 'rm -rf -- "$customer_stage"' EXIT
cp -- "$customer_code/decrypt_customer_bundle.php" "$customer_stage/decrypt_customer_bundle.php"
cp -- "$customer_code/data/customers-20261018.zip.aesgcm" "$customer_stage/customers.aesgcm"
chmod 0700 -- "$customer_stage"
chmod 0600 -- "$customer_stage/decrypt_customer_bundle.php" "$customer_stage/customers.aesgcm"
chown -R --no-dereference fasakha:"$(id -gn fasakha)" "$customer_stage"
runuser -u fasakha -- "$customer_php" -d memory_limit=128M "$customer_stage/decrypt_customer_bundle.php" \
    --encrypted "$customer_stage/customers.aesgcm" \
    --output "$customer_zip" --root "$customer_root"
bash "$customer_code/../customer-directory-import-20261019/apply_customer_directory_import_root.sh" \
    --apply --input-zip "$customer_zip" --php "$customer_php"
