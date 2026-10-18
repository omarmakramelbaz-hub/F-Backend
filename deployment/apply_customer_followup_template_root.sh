#!/usr/bin/env bash
set -euo pipefail
umask 077

followup_revision=${1:?Full revision is required}
[[ "$followup_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'RUN_AS_ROOT_REQUIRED'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'TARGET_HOST_REQUIRED'; exit 1; }
followup_root=/home/fasakha/public_html
followup_php=/opt/cpanel/ea-php82/root/usr/bin/php
followup_base=a1c50bd9871aa88a1bf37a840358a6185b1fe580
followup_source=deployment/apply_customer_followup_template.py
[[ -x "$followup_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$followup_root" cat-file -e "$followup_revision^{commit}"
if ! runuser -u fasakha -- git -C "$followup_root" cat-file -e "$followup_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$followup_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$followup_base"
fi
runuser -u fasakha -- git -C "$followup_root" merge-base --is-ancestor "$followup_base" "$followup_revision"
followup_blob=$(runuser -u fasakha -- git -C "$followup_root" rev-parse "$followup_revision:$followup_source")
[[ "$followup_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
followup_tmp=$(mktemp -d /tmp/customer-followup-template.XXXXXXXX)
trap 'rm -rf -- "$followup_tmp"' EXIT
chmod 0755 "$followup_tmp"
runuser -u fasakha -- git -C "$followup_root" show "$followup_revision:$followup_source" > "$followup_tmp/apply.py"
[[ $(git hash-object --no-filters "$followup_tmp/apply.py") == "$followup_blob" ]] || {
  printf '%s\n' 'CUSTOMER_FOLLOWUP_TEMPLATE_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$followup_tmp/apply.py"
followup_options=()
if [[ $# == 2 ]]; then followup_options+=(--check); fi
runuser -u fasakha -- python3 "$followup_tmp/apply.py" \
  --root "$followup_root" --revision "$followup_revision" --php "$followup_php" "${followup_options[@]}"
