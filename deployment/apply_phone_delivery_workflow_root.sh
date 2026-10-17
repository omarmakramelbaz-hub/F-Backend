#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $# = 1 || ( $# = 2 && $2 = --check ) ]] || exit 1
workflow_root=/home/fasakha/public_html
workflow_php=/opt/cpanel/ea-php82/root/usr/bin/php
workflow_base=9f4cce8792467d1899927066f2c951cb2fa10cb5
workflow_source=deployment/apply_phone_delivery_workflow.py
[[ -x "$workflow_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
workflow_tmp=$(mktemp -d /tmp/phone-delivery-workflow.XXXXXXXX)
trap 'rm -rf -- "$workflow_tmp"' EXIT
chmod 0755 "$workflow_tmp"

runuser -u fasakha -- git -C "$workflow_root" cat-file -e "$revision^{commit}"
runuser -u fasakha -- git -C "$workflow_root" merge-base --is-ancestor "$workflow_base" "$revision"
workflow_blob=$(runuser -u fasakha -- git -C "$workflow_root" rev-parse "$revision:$workflow_source")
[[ "$workflow_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$workflow_root" show "$revision:$workflow_source" > "$workflow_tmp/apply.py"
[[ $(git hash-object --no-filters "$workflow_tmp/apply.py") = "$workflow_blob" ]] || {
  printf '%s\n' 'PHONE_DELIVERY_WORKFLOW_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$workflow_tmp/apply.py"
workflow_options=()
if [[ ${2:-} = --check ]]; then
  workflow_options+=(--check)
fi
runuser -u fasakha -- python3 "$workflow_tmp/apply.py" \
  --root "$workflow_root" --revision "$revision" --php "$workflow_php" "${workflow_options[@]}"
