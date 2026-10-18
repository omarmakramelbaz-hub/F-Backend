#!/usr/bin/env bash
set -euo pipefail
umask 077

marketing_revision=${1:?Full revision is required}
[[ "$marketing_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'RUN_AS_ROOT_REQUIRED'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'TARGET_HOST_REQUIRED'; exit 1; }
marketing_root=/home/fasakha/public_html
marketing_php=/opt/cpanel/ea-php82/root/usr/bin/php
marketing_base=cba1f9feda124573bc961aaa4d2dce63d2dd5a59
marketing_source=deployment/apply_customer_marketing_template.py
[[ -x "$marketing_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$marketing_root" cat-file -e "$marketing_revision^{commit}"
if ! runuser -u fasakha -- git -C "$marketing_root" cat-file -e "$marketing_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$marketing_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$marketing_base"
fi
runuser -u fasakha -- git -C "$marketing_root" merge-base --is-ancestor "$marketing_base" "$marketing_revision"
marketing_blob=$(runuser -u fasakha -- git -C "$marketing_root" rev-parse "$marketing_revision:$marketing_source")
[[ "$marketing_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
marketing_tmp=$(mktemp -d /tmp/customer-marketing-template.XXXXXXXX)
trap 'rm -rf -- "$marketing_tmp"' EXIT
chmod 0755 "$marketing_tmp"
runuser -u fasakha -- git -C "$marketing_root" show "$marketing_revision:$marketing_source" > "$marketing_tmp/apply.py"
[[ $(git hash-object --no-filters "$marketing_tmp/apply.py") == "$marketing_blob" ]] || {
  printf '%s\n' 'CUSTOMER_MARKETING_TEMPLATE_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$marketing_tmp/apply.py"
marketing_options=()
if [[ $# == 2 ]]; then marketing_options+=(--check); fi
runuser -u fasakha -- python3 "$marketing_tmp/apply.py" \
  --root "$marketing_root" --revision "$marketing_revision" --php "$marketing_php" "${marketing_options[@]}"
