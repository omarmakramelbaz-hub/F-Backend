#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
receipt_rev=$1
receipt_mode=$2
[[ "$receipt_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$receipt_mode" == --apply || "$receipt_mode" == --check ]]
receipt_stage=$(mktemp -d /var/tmp/fasakhansta-receipts.XXXXXXXX)
trap 'rm -rf -- "$receipt_stage"' EXIT
chmod 0755 "$receipt_stage"
while read -r receipt_hash receipt_path receipt_file; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$receipt_rev/$receipt_path" \
        -o "$receipt_stage/$receipt_file"
    printf '%s  %s\n' "$receipt_hash" "$receipt_stage/$receipt_file" | sha256sum --check --status
    chmod 0444 "$receipt_stage/$receipt_file"
done <<'RECEIPT_FILES'
03d602933a7c34389561252af157c363b2d4a153f1bcdfe371bba8e678298703 deployment/professional-receipts-20261106/apply_root.py apply_root.py
b49ce4f1461cfe336fde54bf042b6fb90cfb62d2d1375851c335f792cd1cee79 deployment/professional-receipts-20261106/manifest.json manifest.json
69d3b40876b04987dd0809d30b887d9467463e8e3ea9be945f860d4557732952 tests/receipt_runtime/render.php render.php
cb92090a12358b863ccbe8d6b3f8295e68a70ccd1b798c7176fa34be8659cfc5 tests/receipt_runtime/fixtures.php fixtures.php
RECEIPT_FILES
python3 -I -B "$receipt_stage/apply_root.py" "$receipt_stage/manifest.json" "$receipt_mode"
