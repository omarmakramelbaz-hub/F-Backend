#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $# = 1 || ( $# = 2 && $2 = --check ) ]] || exit 1
enter_flow_root=/home/fasakha/public_html
enter_flow_php=/opt/cpanel/ea-php82/root/usr/bin/php
enter_flow_base=642dbfe4be0dc1097435bdb81e2606804b8b8955
enter_flow_source=deployment/apply_phone_customer_enter_flow.py
[[ -x "$enter_flow_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
enter_flow_tmp=$(mktemp -d /tmp/phone-customer-enter-flow.XXXXXXXX)
trap 'rm -rf -- "$enter_flow_tmp"' EXIT
chmod 0755 "$enter_flow_tmp"

runuser -u fasakha -- git -C "$enter_flow_root" cat-file -e "$revision^{commit}"
runuser -u fasakha -- git -C "$enter_flow_root" merge-base --is-ancestor "$enter_flow_base" "$revision"
enter_flow_blob=$(runuser -u fasakha -- git -C "$enter_flow_root" rev-parse "$revision:$enter_flow_source")
[[ "$enter_flow_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$enter_flow_root" show "$revision:$enter_flow_source" > "$enter_flow_tmp/apply.py"
[[ $(git hash-object --no-filters "$enter_flow_tmp/apply.py") = "$enter_flow_blob" ]] || {
  printf '%s\n' 'PHONE_CUSTOMER_ENTER_FLOW_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$enter_flow_tmp/apply.py"
enter_flow_options=()
if [[ ${2:-} = --check ]]; then
  enter_flow_options+=(--check)
fi
runuser -u fasakha -- python3 "$enter_flow_tmp/apply.py" \
  --root "$enter_flow_root" --revision "$revision" --php "$enter_flow_php" "${enter_flow_options[@]}"
