#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $# = 1 || ( $# = 2 && $2 = --check ) ]] || exit 1
unified_root=/home/fasakha/public_html
unified_php=/opt/cpanel/ea-php82/root/usr/bin/php
unified_base=fe1d8499df06d72d3d482c18dc30792c043d7840
unified_source=deployment/apply_phone_delivery_unified.py
[[ -x "$unified_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
unified_tmp=$(mktemp -d /tmp/phone-delivery-unified.XXXXXXXX)
trap 'rm -rf -- "$unified_tmp"' EXIT
chmod 0755 "$unified_tmp"

runuser -u fasakha -- git -C "$unified_root" cat-file -e "$revision^{commit}"
runuser -u fasakha -- git -C "$unified_root" merge-base --is-ancestor "$unified_base" "$revision"
unified_blob=$(runuser -u fasakha -- git -C "$unified_root" rev-parse "$revision:$unified_source")
[[ "$unified_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$unified_root" show "$revision:$unified_source" > "$unified_tmp/apply.py"
[[ $(git hash-object --no-filters "$unified_tmp/apply.py") = "$unified_blob" ]] || {
  printf '%s\n' 'PHONE_DELIVERY_UNIFIED_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$unified_tmp/apply.py"

# The Python installer stages any missing fe1d preparation/consent hunks and
# the unified screen together, proves the full existing first-print policy,
# and publishes one transaction only after every file passes preflight.
unified_options=()
if [[ ${2:-} = --check ]]; then
  unified_options+=(--check)
fi
runuser -u fasakha -- python3 "$unified_tmp/apply.py" \
  --root "$unified_root" --revision "$revision" --php "$unified_php" "${unified_options[@]}"
