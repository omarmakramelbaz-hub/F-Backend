#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_release="$1"
test "$2" = --apply
[[ "$go_release" =~ ^[0-9a-f]{40}$ ]]

go_stage=$(mktemp -d /var/tmp/go-owner-mobile-entry.XXXXXXXX)
trap 'rm -rf -- "$go_stage"' EXIT
for go_file in apply_owner_mobile_fix_root.py owner_mobile_fix_manifest.json; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_release/deployment/$go_file" \
        -o "$go_stage/$go_file"
done
printf '%s  %s\n' \
    fc787aa36cbbfaffe3a34bc6a681e2c6aa972ee3deaf5018c3a0fd8442064c7e "$go_stage/apply_owner_mobile_fix_root.py" \
    d056d55f1e0a5c7b543dfb5569041ad40fdf46472aec83dad35915dce7e40ceb "$go_stage/owner_mobile_fix_manifest.json" \
    | sha256sum --check --status
python3 -I -B "$go_stage/apply_owner_mobile_fix_root.py" \
    "$go_stage/owner_mobile_fix_manifest.json" --apply
