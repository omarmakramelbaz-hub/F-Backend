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
b3695c4a40878b7112a1fde1fd723f10633e94704da7c57bf1467ea6905ac162 apply_root.py
4017920e9d60ec831f31b796b8c2f5779e887bbf0a99224c3a79c8ce88dab779 patch_spec.json
INVOICE_DATE_FILES
python3 -I -B "$invoice_stage/apply_root.py" "$invoice_stage/patch_spec.json" "$invoice_mode"
