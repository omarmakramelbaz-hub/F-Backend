#!/usr/bin/env bash
set -euo pipefail
umask 077

pos_shift_revision=${1:?Full revision required}
[[ "$pos_shift_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]]
pos_shift_root=/home/fasakha/public_html
pos_shift_php=/opt/cpanel/ea-php82/root/usr/bin/php
pos_shift_script=deployment/apply_pos_shift_safety.py
[[ -x "$pos_shift_php" ]]
command -v python3 >/dev/null
command -v flock >/dev/null
# One root wrapper owns preflight, publication, and EXIT cleanup.
# Keep the descriptor open until cleanup has completed.
pos_shift_lock=/run/fasakhansta-pos-shift-safety.lock
python3 - "$pos_shift_lock" <<'PY_LOCK'
import os
import stat
import sys
fd = os.open(sys.argv[1], os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
try:
    info = os.fstat(fd)
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o077):
        raise SystemExit('POS_SHIFT_SAFETY_ROOT_LOCK_INVALID')
finally:
    os.close(fd)
PY_LOCK
exec {pos_shift_lock_fd}<>"$pos_shift_lock"
if ! flock --nonblock "$pos_shift_lock_fd"; then
  printf '%s\n' 'POS_SHIFT_SAFETY_INSTALLER_ALREADY_RUNNING'
  exit 1
fi
runuser -u fasakha -- git -C "$pos_shift_root" cat-file -e "$pos_shift_revision^{commit}"
pos_shift_blob=$(runuser -u fasakha -- git -C "$pos_shift_root" rev-parse "$pos_shift_revision:$pos_shift_script")
[[ "$pos_shift_blob" =~ ^[0-9a-f]{40}$ ]]
pos_shift_tmp=$(mktemp -d /tmp/pos-shift-safety.XXXXXXXX)
chmod 0755 "$pos_shift_tmp"
pos_shift_cleanup() {
  pos_shift_exit=$?
  trap - EXIT
  rm -rf -- "$pos_shift_tmp"
  exit "$pos_shift_exit"
}
trap pos_shift_cleanup EXIT
runuser -u fasakha -- git -C "$pos_shift_root" show "$pos_shift_revision:$pos_shift_script" > "$pos_shift_tmp/apply.py"
[[ $(git hash-object --no-filters "$pos_shift_tmp/apply.py") == "$pos_shift_blob" ]]
chmod 0644 "$pos_shift_tmp/apply.py"
# Parse and verify the exact five-file payload before changing application source.
runuser -u fasakha -- python3 "$pos_shift_tmp/apply.py" \
  --root "$pos_shift_root" --revision "$pos_shift_revision" --php "$pos_shift_php" --check
if [[ $# == 2 ]]; then
  printf '%s\n' 'POS_SHIFT_SAFETY_CHECK_COMPLETE no_print_jobs_created=1'
  exit 0
fi
runuser -u fasakha -- python3 "$pos_shift_tmp/apply.py" \
  --root "$pos_shift_root" --revision "$pos_shift_revision" --php "$pos_shift_php"
printf '%s\n' 'POS_SHIFT_SAFETY_RELEASE_COMPLETE no_print_jobs_created=1'
