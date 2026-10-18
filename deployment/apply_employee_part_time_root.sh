#!/usr/bin/env bash
set -euo pipefail
umask 077

part_time_revision=${1:?Full revision is required}
[[ "$part_time_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
part_time_root=/home/fasakha/public_html
part_time_php=/opt/cpanel/ea-php82/root/usr/bin/php
part_time_base=a1a2764cab407a8bc2a871403dfcdee304bba220
part_time_source=deployment/apply_employee_part_time.py
[[ -x "$part_time_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$part_time_root" cat-file -e "$part_time_revision^{commit}"
if ! runuser -u fasakha -- git -C "$part_time_root" cat-file -e "$part_time_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$part_time_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$part_time_base"
fi
runuser -u fasakha -- git -C "$part_time_root" merge-base --is-ancestor "$part_time_base" "$part_time_revision"
part_time_blob=$(runuser -u fasakha -- git -C "$part_time_root" rev-parse "$part_time_revision:$part_time_source")
[[ "$part_time_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
part_time_tmp=$(mktemp -d /tmp/employee-part-time.XXXXXXXX)
trap 'rm -rf -- "$part_time_tmp"' EXIT
chmod 0755 "$part_time_tmp"
runuser -u fasakha -- git -C "$part_time_root" show "$part_time_revision:$part_time_source" > "$part_time_tmp/apply.py"
[[ $(git hash-object --no-filters "$part_time_tmp/apply.py") == "$part_time_blob" ]] || {
  printf '%s\n' 'EMPLOYEE_PART_TIME_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$part_time_tmp/apply.py"
part_time_options=()
if [[ $# == 2 ]]; then part_time_options+=(--check); fi
runuser -u fasakha -- python3 "$part_time_tmp/apply.py" \
  --root "$part_time_root" --revision "$part_time_revision" --php "$part_time_php" "${part_time_options[@]}"
