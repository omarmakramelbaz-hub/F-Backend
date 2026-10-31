#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_release="$1"
test "$2" = --apply
[[ "$go_release" =~ ^[0-9a-f]{40}$ ]]

go_stage=$(mktemp -d /var/tmp/go-owner-account-entry.XXXXXXXX)
trap 'rm -rf -- "$go_stage"' EXIT
for go_file in apply_owner_mobile_fix_root.py owner_account_fix_manifest.json; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_release/deployment/$go_file" \
        -o "$go_stage/$go_file"
done
printf '%s  %s\n' \
    ee26b0962a603174272cd5eb5ed30cf27bb42967872bf077f58e98167bd4c15c "$go_stage/apply_owner_mobile_fix_root.py" \
    ebdf4c06abe2fa8817157c2305c0c782cfcd64c62f3a00b9c3df0adf7adbdd71 "$go_stage/owner_account_fix_manifest.json" \
    | sha256sum --check --status
python3 -I -B "$go_stage/apply_owner_mobile_fix_root.py" \
    "$go_stage/owner_account_fix_manifest.json" --apply
