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
ee168bee40c375dc100ee93b44e4b542000d7d70c7df9772323ea5f995379a8d deployment/professional-receipts-20261106/manifest.json manifest.json
a98bbafab9462bf98271067dfc1e70e1c2f0fa2a44dd4e8ab4dbc2f4d8b07f74 tests/receipt_runtime/render.php render.php
a5b02d9a27025c3d3c5a5ed0c1fff555390472f6f33c21323ed04164cbc8e594 tests/receipt_runtime/fixtures.php fixtures.php
RECEIPT_FILES
python3 -I -B "$receipt_stage/apply_root.py" "$receipt_stage/manifest.json" "$receipt_mode"
