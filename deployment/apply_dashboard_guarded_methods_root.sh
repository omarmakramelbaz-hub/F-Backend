#!/usr/bin/env bash
set -euo pipefail
umask 077

dashboard_revision=${1:?Full release revision is required}
[[ "$dashboard_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'ROOT_REQUIRED'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'EXPECTED_SERVER_SRV1836524_REQUIRED'; exit 1; }
dashboard_root=/home/fasakha/public_html
dashboard_php=/opt/cpanel/ea-php82/root/usr/bin/php
dashboard_uid=$(id -u fasakha)
[[ -d "$dashboard_root" && ! -L "$dashboard_root" && $(stat -c %u "$dashboard_root") == "$dashboard_uid" ]] || {
  printf '%s\n' 'EXPECTED_FASAKHA_APPLICATION_ROOT_REQUIRED'; exit 1;
}
[[ -x "$dashboard_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
runuser -u fasakha -- git -C "$dashboard_root" cat-file -e "$dashboard_revision^{commit}"

dashboard_payload=$(mktemp -d /tmp/dashboard-guarded-methods.XXXXXXXX)
trap 'rm -rf -- "$dashboard_payload"' EXIT
chmod 0755 "$dashboard_payload"
while read -r dashboard_file dashboard_blob; do
  install -d -m 0755 "$dashboard_payload/$(dirname "$dashboard_file")"
  runuser -u fasakha -- git -C "$dashboard_root" show "$dashboard_revision:$dashboard_file" > "$dashboard_payload/$dashboard_file"
  [[ $(git hash-object --no-filters "$dashboard_payload/$dashboard_file") == "$dashboard_blob" ]] || {
    printf '%s\n' 'RELEASE_PAYLOAD_BLOB_MISMATCH'; exit 1;
  }
  chmod 0644 "$dashboard_payload/$dashboard_file"
done <<'DASHBOARD_PINNED_FILES'
deployment/apply_dashboard_guarded_methods.py 65f3c5314402f263dbd6ae810f93a35a88be9ff0
deployment/dashboard_method_tokens.php b16e14d4773374ca1a4b3ad776249427440364a5
deployment/dashboard_guarded_methods.json 288e9a70d0264d88422fa3feeb603247757c9aa6
app/Services/Dashboard/PosServiceTicket.php 68fc423a763e83587cdfbf8be328d8ada81ab9da
app/Services/Dashboard/PhoneDeliveryBoard.php 43d791ca86ddc046a28ab3158bee2f6322c6891b
app/Http/Controllers/Api/V1/Auth/AddressController.php 3a38f4031f90d649155c8fae4e5ed6ba5c7b2a2a
app/Http/Controllers/Api/V1/Auth/UserController.php 74f755c8ad3523f847094447b15f45e26e0b0100
app/Http/Traits/SendNotification.php abfdd1d7174b85ef01947c44acf99aba60fa121e
DASHBOARD_PINNED_FILES

dashboard_options=()
if [[ $# == 2 ]]; then dashboard_options+=(--check); fi
runuser -u fasakha -- python3 "$dashboard_payload/deployment/apply_dashboard_guarded_methods.py" \
  --root "$dashboard_root" --release-dir "$dashboard_payload" --php "$dashboard_php" \
  --release "$dashboard_revision" "${dashboard_options[@]}"
