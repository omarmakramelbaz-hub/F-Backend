#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $(hostname -s) = srv1836524 ]] || { printf '%s\n' 'EXPECTED_SERVER_REQUIRED'; exit 1; }
followup_root=/home/fasakha/public_html
followup_source=deployment/apply_dining_cash_collection.py
command -v python3 >/dev/null
followup_tmp=$(mktemp -d /tmp/dining-cash-collection.XXXXXXXX)
trap 'rm -rf -- "$followup_tmp"' EXIT
chmod 0755 "$followup_tmp"

runuser -u fasakha -- git -C "$followup_root" cat-file -e "$revision^{commit}"
for followup_base in \
  e1f65ec1b1066ddaf4bc228595c61b1b74749006 \
  6582750c2ef925500504a83d89141e49f0bd62a3; do
  if ! runuser -u fasakha -- git -C "$followup_root" cat-file -e "$followup_base^{commit}" 2>/dev/null; then
    runuser -u fasakha -- git -C "$followup_root" fetch \
      --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$followup_base"
    [[ $(runuser -u fasakha -- git -C "$followup_root" rev-parse FETCH_HEAD) = "$followup_base" ]] || exit 1
  fi
  [[ $(runuser -u fasakha -- git -C "$followup_root" rev-parse "$followup_base^{commit}") = "$followup_base" ]] || exit 1
done
followup_blob=$(runuser -u fasakha -- git -C "$followup_root" rev-parse "$revision:$followup_source")
[[ "$followup_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$followup_root" show "$revision:$followup_source" > "$followup_tmp/apply.py"
[[ $(git hash-object --no-filters "$followup_tmp/apply.py") = "$followup_blob" ]] || {
  printf '%s\n' 'DINING_CASH_COLLECTION_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$followup_tmp/apply.py"
runuser -u fasakha -- python3 "$followup_tmp/apply.py" \
  --root "$followup_root" --revision "$revision"
