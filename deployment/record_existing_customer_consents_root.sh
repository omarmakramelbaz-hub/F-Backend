#!/usr/bin/env bash
# One approved import of existing customer permissions; no code installation or campaign dispatch.
set -euo pipefail
umask 077
crm_revision=${1:?Full revision is required}
[[ "$crm_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this helper as root.'; exit 1; }
crm_root=/home/fasakha/public_html
crm_php=/opt/cpanel/ea-php82/root/usr/bin/php
crm_import_tmp=$(mktemp -d /tmp/customer-crm-consent.XXXXXXXX)
trap 'rm -rf -- "$crm_import_tmp"' EXIT
chmod 0755 "$crm_import_tmp"
runuser -u fasakha -- git -C "$crm_root" show \
  "$crm_revision:deployment/record_existing_customer_consents.php" \
  > "$crm_import_tmp/record.php"
chmod 0644 "$crm_import_tmp/record.php"
runuser -u fasakha -- "$crm_php" -l "$crm_import_tmp/record.php"
runuser -u fasakha -- "$crm_php" -d display_errors=0 "$crm_import_tmp/record.php" \
  --root="$crm_root"
runuser -u fasakha -- "$crm_php" -d display_errors=0 "$crm_import_tmp/record.php" \
  --root="$crm_root" --apply --owner-confirmed
