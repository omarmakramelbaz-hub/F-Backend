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
3efc88cf5554a7b4ac54ee31dbe4f998695187675eb99f9a8aef247d2fc83ee0 deployment/professional-receipts-20261106/manifest.json manifest.json
c27ff97468f727c0af9cd85b07970133c537a3e4bee1c21f743ea8a500208c4a tests/receipt_runtime/render.php render.php
b79dd3dd9f7be3efa47b19782d7db934fe381d1a3e09b1d181a16fb91ef5c8e9 tests/receipt_runtime/fixtures.php fixtures.php
RECEIPT_FILES
python3 -I -B "$receipt_stage/apply_root.py" "$receipt_stage/manifest.json" "$receipt_mode"
