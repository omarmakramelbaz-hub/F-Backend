#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Full revision is required}
[[ $# -le 2 ]] || exit 1
customer_branch_options=()
if [[ $# -eq 2 ]]; then
  [[ $2 = --check ]] || exit 1
  customer_branch_options=(--check)
fi
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
customer_branch_root=/home/fasakha/public_html
customer_branch_php=/opt/cpanel/ea-php82/root/usr/bin/php
customer_branch_source=deployment/apply_phone_customer_auto_branch.py
[[ -x "$customer_branch_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
customer_branch_tmp=$(mktemp -d /tmp/phone-customer-auto-branch.XXXXXXXX)
trap 'rm -rf -- "$customer_branch_tmp"' EXIT
chmod 0755 "$customer_branch_tmp"

runuser -u fasakha -- git -C "$customer_branch_root" cat-file -e "$revision^{commit}"
for customer_branch_base in \
  c27278519f923a2fe351523ff3a149f14a38c04d \
  6582750c2ef925500504a83d89141e49f0bd62a3; do
  if ! runuser -u fasakha -- git -C "$customer_branch_root" cat-file -e "$customer_branch_base^{commit}" 2>/dev/null; then
    runuser -u fasakha -- git -C "$customer_branch_root" fetch \
      --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$customer_branch_base"
    [[ $(runuser -u fasakha -- git -C "$customer_branch_root" rev-parse FETCH_HEAD) = "$customer_branch_base" ]] || exit 1
  fi
  [[ $(runuser -u fasakha -- git -C "$customer_branch_root" rev-parse "$customer_branch_base^{commit}") = "$customer_branch_base" ]] || exit 1
done
customer_branch_blob=$(runuser -u fasakha -- git -C "$customer_branch_root" rev-parse "$revision:$customer_branch_source")
[[ "$customer_branch_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$customer_branch_root" show "$revision:$customer_branch_source" > "$customer_branch_tmp/apply.py"
[[ $(git hash-object --no-filters "$customer_branch_tmp/apply.py") = "$customer_branch_blob" ]] || {
  printf '%s\n' 'PHONE_CUSTOMER_AUTO_BRANCH_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$customer_branch_tmp/apply.py"
runuser -u fasakha -- python3 "$customer_branch_tmp/apply.py" \
  --root "$customer_branch_root" --revision "$revision" --php "$customer_branch_php" "${customer_branch_options[@]}"
