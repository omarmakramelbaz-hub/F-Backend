#!/usr/bin/env bash
# Add only the missing payment amount column; keep live application files intact.
set -euo pipefail
umask 077

payment_revision=${1:?Full revision is required}
payment_mode=${2:---apply}
[[ $# -le 2 ]]
[[ "$payment_revision" =~ ^[0-9a-f]{40}$ ]]
[[ "$payment_mode" = --check || "$payment_mode" = --apply ]]
[[ $(id -u) = 0 ]] || { printf '%s\n' 'ROOT_REQUIRED'; exit 1; }
[[ $(hostname -s) = srv1836524 ]] || { printf '%s\n' 'EXPECTED_SERVER_REQUIRED'; exit 1; }

payment_root=/home/fasakha/public_html
payment_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$payment_php" && -d "$payment_root" && ! -L "$payment_root" ]]
[[ $(realpath -- "$payment_root") = "$payment_root" ]]
[[ $(stat -c '%u' -- "$payment_root") = $(id -u fasakha) ]]
runuser -u fasakha -- git -C "$payment_root" cat-file -e "$payment_revision^{commit}"

payment_tmp=$(mktemp -d /tmp/app-payment-schema.XXXXXXXX)
trap 'rm -rf -- "$payment_tmp"' EXIT
chmod 0755 "$payment_tmp"
mkdir -p "$payment_tmp/payment_schema"
chmod 0755 "$payment_tmp/payment_schema"

for payment_path in repair_app_payment_schema.php payment_schema/PaymentSchemaRepair.php; do
  payment_blob=$(runuser -u fasakha -- git -C "$payment_root" rev-parse "$payment_revision:deployment/$payment_path")
  [[ "$payment_blob" =~ ^[0-9a-f]{40}$ ]]
  runuser -u fasakha -- git -C "$payment_root" show "$payment_revision:deployment/$payment_path" > "$payment_tmp/$payment_path"
  [[ $(git hash-object --no-filters "$payment_tmp/$payment_path") = "$payment_blob" ]] || {
    printf '%s\n' 'PAYMENT_SCHEMA_PAYLOAD_MISMATCH'; exit 1;
  }
  chmod 0644 "$payment_tmp/$payment_path"
  "$payment_php" -l "$payment_tmp/$payment_path" >/dev/null
done

# The helper has no gateway calls or business-row writes. It checks the live
# connection before an additive, idempotent schema change, under a database lock.
runuser -u fasakha -- "$payment_php" "$payment_tmp/repair_app_payment_schema.php" \
  --root "$payment_root" "$payment_mode"
