#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
courier_rev=$1
courier_mode=$2
[[ "$courier_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$courier_mode" == --apply || "$courier_mode" == --check ]]
courier_stage=$(mktemp -d /var/tmp/fasakhansta-courier-receipt.XXXXXXXX)
trap 'rm -rf -- "$courier_stage"' EXIT
chmod 0755 "$courier_stage"
while read -r courier_hash courier_path courier_file; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$courier_rev/$courier_path" \
        -o "$courier_stage/$courier_file"
    printf '%s  %s\n' "$courier_hash" "$courier_stage/$courier_file" | sha256sum --check --status
    chmod 0444 "$courier_stage/$courier_file"
done <<'COURIER_FILES'
942d89047b2e124cc51a86a63fd5954f5069003636c5304b671d83c988c07951 deployment/courier-delivery-breakdown-20261106/apply_root.py apply_root.py
dfc0d4ae757615c032531083ef76c305aa360fec6ce523bb595db543be4fc20e deployment/courier-delivery-breakdown-20261106/manifest.json manifest.json
aa59004ed8b24cb2f99c7b875db19e552d3cddc9e08b9f54e6d4cd5dde7ff83e tests/phone_batch_runtime/render.php render.php
COURIER_FILES
python3 -I -B "$courier_stage/apply_root.py" "$courier_stage/manifest.json" "$courier_mode"
