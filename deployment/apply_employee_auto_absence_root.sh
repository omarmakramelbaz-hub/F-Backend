#!/usr/bin/env bash
set -euo pipefail
umask 077

auto_absence_revision=${1:?Full revision is required}
[[ "$auto_absence_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
auto_absence_root=/home/fasakha/public_html
auto_absence_php=/opt/cpanel/ea-php82/root/usr/bin/php
auto_absence_base=9bf6ddc80d3bbb1a23042f29f9cea338ce7e4816
auto_absence_source=deployment/apply_employee_auto_absence.py
[[ -x "$auto_absence_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$auto_absence_root" cat-file -e "$auto_absence_revision^{commit}"
if ! runuser -u fasakha -- git -C "$auto_absence_root" cat-file -e "$auto_absence_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$auto_absence_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$auto_absence_base"
fi
runuser -u fasakha -- git -C "$auto_absence_root" merge-base --is-ancestor "$auto_absence_base" "$auto_absence_revision"
auto_absence_blob=$(runuser -u fasakha -- git -C "$auto_absence_root" rev-parse "$auto_absence_revision:$auto_absence_source")
[[ "$auto_absence_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
auto_absence_tmp=$(mktemp -d /tmp/employee-auto-absence.XXXXXXXX)
trap 'rm -rf -- "$auto_absence_tmp"' EXIT
chmod 0755 "$auto_absence_tmp"
runuser -u fasakha -- git -C "$auto_absence_root" show "$auto_absence_revision:$auto_absence_source" > "$auto_absence_tmp/apply.py"
[[ $(git hash-object --no-filters "$auto_absence_tmp/apply.py") == "$auto_absence_blob" ]] || {
  printf '%s\n' 'EMPLOYEE_AUTO_ABSENCE_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$auto_absence_tmp/apply.py"
auto_absence_options=()
if [[ $# == 2 ]]; then auto_absence_options+=(--check); fi
runuser -u fasakha -- python3 "$auto_absence_tmp/apply.py" \
  --root "$auto_absence_root" --revision "$auto_absence_revision" --php "$auto_absence_php" "${auto_absence_options[@]}"
