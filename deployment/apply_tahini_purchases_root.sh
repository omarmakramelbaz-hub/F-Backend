#!/usr/bin/env bash
# Enable tahini as a protected purchase material for every restaurant branch.
set -euo pipefail
umask 077
tahini_release=''
tahini_mode=--check
while (($#)); do
    case "$1" in
        --release) tahini_release="${2:?Missing release}"; shift 2 ;;
        --check|--apply) tahini_mode="$1"; shift ;;
        *) echo 'Usage: apply_tahini_purchases_root.sh --release SHA [--check|--apply]' >&2; exit 2 ;;
    esac
done
[[ "$tahini_release" =~ ^[0-9a-f]{40}$ ]]
test "$(id -u)" = 0
test "$(hostname -s)" = srv1836524
tahini_root=/home/fasakha/public_html
tahini_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$tahini_php"
test ! -L "$tahini_root"
test "$(realpath -- "$tahini_root")" = "$tahini_root"
test "$(stat -c '%u' -- "$tahini_root")" = "$(id -u fasakha)"
test -f "$tahini_root/vendor/autoload.php"
runuser -u fasakha -- test -r "$tahini_root/app/Services/Dashboard/ExpenseStockPurchases.php"
runuser -u fasakha -- git -C "$tahini_root" cat-file -e "$tahini_release^{commit}"

tahini_stage=$(mktemp -d /tmp/tahini-purchase-release.XXXXXXXX)
tahini_candidate_dir=$(mktemp -d /tmp/tahini-purchase-candidate.XXXXXXXX)
trap 'rm -rf -- "$tahini_stage" "$tahini_candidate_dir"' EXIT
chmod 0755 "$tahini_stage"
chown fasakha:fasakha "$tahini_candidate_dir"
chmod 0700 "$tahini_candidate_dir"
runuser -u fasakha -- git -C "$tahini_root" archive "$tahini_release" \
    deployment/enable_tahini_purchases_20261021.php \
    deployment/patch_tahini_purchase_service_20261021.php \
    | tar --no-same-owner -x -C "$tahini_stage"
find "$tahini_stage" -type d -exec chmod 0755 {} +
find "$tahini_stage" -type f -exec chmod 0644 {} +
tahini_runner="$tahini_stage/deployment/enable_tahini_purchases_20261021.php"
tahini_patch="$tahini_stage/deployment/patch_tahini_purchase_service_20261021.php"
tahini_candidate="$tahini_candidate_dir/ExpenseStockPurchases.php"
runuser -u fasakha -- "$tahini_php" -l "$tahini_runner"
"$tahini_php" -l "$tahini_patch"
"$tahini_php" "$tahini_patch" --prepare --root="$tahini_root" --stage="$tahini_candidate"
runuser -u fasakha -- "$tahini_php" -l "$tahini_candidate"
runuser -u fasakha -- "$tahini_php" -d display_errors=0 -d log_errors=0 \
    "$tahini_runner" --check --root="$tahini_root" --purchase-service="$tahini_candidate"
if [[ "$tahini_mode" = --check ]]; then
    echo 'TAHINI_PURCHASES_CHECK_ONLY_NO_LIVE_DATA_CHANGED'
    exit 0
fi

tahini_backup_root=/home/fasakha/tahini-purchase-backups
test ! -L "$tahini_backup_root"
install -d -m 0700 -o fasakha -g fasakha "$tahini_backup_root"
tahini_backup=$(mktemp -d "$tahini_backup_root/20261021-XXXXXXXX")
chown fasakha:fasakha "$tahini_backup"

# Register the ingredient before the new purchase choice becomes visible.
runuser -u fasakha -- "$tahini_php" -d display_errors=0 -d log_errors=0 \
    "$tahini_runner" --prepare --root="$tahini_root" --backup="$tahini_backup" --purchase-service="$tahini_candidate"
"$tahini_php" "$tahini_patch" --apply --root="$tahini_root" --stage="$tahini_candidate" --backup="$tahini_backup"
# A fresh process now verifies the deployed service, without a staged override.
runuser -u fasakha -- "$tahini_php" -d display_errors=0 -d log_errors=0 \
    "$tahini_runner" --finalize --root="$tahini_root" --backup="$tahini_backup"
echo "Private tahini purchase backup: $tahini_backup"
