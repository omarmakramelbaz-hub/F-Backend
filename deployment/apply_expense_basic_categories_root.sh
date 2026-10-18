#!/usr/bin/env bash
set -euo pipefail
umask 077

expense_revision=${1:?Full revision is required}
[[ "$expense_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
expense_root=/home/fasakha/public_html
expense_php=/opt/cpanel/ea-php82/root/usr/bin/php
expense_base=c9014fb6a050e8b469e0a8368f509116fc8eaec9
expense_source=deployment/apply_expense_basic_categories.py
[[ -x "$expense_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$expense_root" cat-file -e "$expense_revision^{commit}"
# The release was fetched by the calling command. Fetch only its pinned base
# object if absent; there are no other release or installer prerequisites.
if ! runuser -u fasakha -- git -C "$expense_root" cat-file -e "$expense_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$expense_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$expense_base"
fi
runuser -u fasakha -- git -C "$expense_root" merge-base --is-ancestor "$expense_base" "$expense_revision"
expense_blob=$(runuser -u fasakha -- git -C "$expense_root" rev-parse "$expense_revision:$expense_source")
[[ "$expense_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
expense_tmp=$(mktemp -d /tmp/expense-basic-categories.XXXXXXXX)
trap 'rm -rf -- "$expense_tmp"' EXIT
chmod 0755 "$expense_tmp"
runuser -u fasakha -- git -C "$expense_root" show "$expense_revision:$expense_source" > "$expense_tmp/apply.py"
[[ $(git hash-object --no-filters "$expense_tmp/apply.py") == "$expense_blob" ]] || {
  printf '%s\n' 'EXPENSE_BASIC_CATEGORIES_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$expense_tmp/apply.py"
expense_options=()
if [[ $# == 2 ]]; then expense_options+=(--check); fi
runuser -u fasakha -- python3 "$expense_tmp/apply.py" \
  --root "$expense_root" --revision "$expense_revision" --php "$expense_php" "${expense_options[@]}"
