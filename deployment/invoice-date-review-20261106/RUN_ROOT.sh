#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
[[ $# -ge 1 && $# -le 2 ]]
invoice_rev=$1
invoice_mode=${2:---apply}
[[ "$invoice_rev" =~ ^[a-f0-9]{40}$ ]]
[[ "$invoice_mode" == --apply || "$invoice_mode" == --check ]]
invoice_stage=$(mktemp -d /var/tmp/fasakhansta-invoice-date.XXXXXXXX)
trap 'rm -rf -- "$invoice_stage"' EXIT
chmod 0755 "$invoice_stage"
while read -r invoice_hash invoice_file; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$invoice_rev/deployment/invoice-date-review-20261106/$invoice_file" \
        -o "$invoice_stage/$invoice_file"
    printf '%s  %s\n' "$invoice_hash" "$invoice_stage/$invoice_file" | sha256sum --check --status
    chmod 0444 "$invoice_stage/$invoice_file"
done <<'INVOICE_DATE_FILES'
4c52c2f01dd2f1e0c42fe41fb14cd9a0114ccaa114e4be9378c64f7f65b8fd16 apply_root.py
917685cf39b457cb2b56c27b92ecdaf35934ad204c7b1740452e01f27a1cf6af patch_spec.json
INVOICE_DATE_FILES
python3 -I -B "$invoice_stage/apply_root.py" "$invoice_stage/patch_spec.json" "$invoice_mode"
