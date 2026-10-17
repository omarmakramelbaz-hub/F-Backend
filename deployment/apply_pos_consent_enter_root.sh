#!/usr/bin/env bash
set -euo pipefail
umask 077

consent_revision=${1:?Full revision is required}
[[ "$consent_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
consent_root=/home/fasakha/public_html
consent_base=41fe5cb2252e708ff544c05e8d48eb0df3ad6b87
consent_source=deployment/apply_pos_consent_enter.py
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$consent_root" cat-file -e "$consent_revision^{commit}"
# The release was fetched by the calling command. Fetch only its pinned base
# object if absent; there are no other release or installer prerequisites.
if ! runuser -u fasakha -- git -C "$consent_root" cat-file -e "$consent_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$consent_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$consent_base"
fi
runuser -u fasakha -- git -C "$consent_root" merge-base --is-ancestor "$consent_base" "$consent_revision"
consent_blob=$(runuser -u fasakha -- git -C "$consent_root" rev-parse "$consent_revision:$consent_source")
[[ "$consent_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
consent_tmp=$(mktemp -d /tmp/pos-consent-enter.XXXXXXXX)
trap 'rm -rf -- "$consent_tmp"' EXIT
chmod 0755 "$consent_tmp"
runuser -u fasakha -- git -C "$consent_root" show "$consent_revision:$consent_source" > "$consent_tmp/apply.py"
[[ $(git hash-object --no-filters "$consent_tmp/apply.py") == "$consent_blob" ]] || {
  printf '%s\n' 'CONSENT_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$consent_tmp/apply.py"
consent_options=()
if [[ $# == 2 ]]; then consent_options+=(--check); fi
runuser -u fasakha -- python3 "$consent_tmp/apply.py" \
  --root "$consent_root" --revision "$consent_revision" "${consent_options[@]}"
