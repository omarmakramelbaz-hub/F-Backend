#!/usr/bin/env bash
set -euo pipefail
umask 077

stock_branches_revision=${1:?Full revision required}
[[ "$stock_branches_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]]
stock_branches_root=/home/fasakha/public_html
stock_branches_php=/opt/cpanel/ea-php82/root/usr/bin/php
stock_branches_script=deployment/apply_stock_branches_only.py
[[ -x "$stock_branches_php" ]]
command -v python3 >/dev/null
command -v flock >/dev/null
# One root wrapper owns preflight, publication, and EXIT cleanup.
# Keep the descriptor open until cleanup has completed.
stock_branches_lock=/run/fasakhansta-stock-branches-only.lock
python3 - "$stock_branches_lock" <<'PY_LOCK'
import os
import stat
import sys
fd = os.open(sys.argv[1], os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
try:
    info = os.fstat(fd)
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o077):
        raise SystemExit('STOCK_BRANCHES_ONLY_ROOT_LOCK_INVALID')
finally:
    os.close(fd)
PY_LOCK
exec {stock_branches_lock_fd}<>"$stock_branches_lock"
if ! flock --nonblock "$stock_branches_lock_fd"; then
  printf '%s\n' 'STOCK_BRANCHES_ONLY_INSTALLER_ALREADY_RUNNING'
  exit 1
fi
runuser -u fasakha -- git -C "$stock_branches_root" cat-file -e "$stock_branches_revision^{commit}"
stock_branches_blob=$(runuser -u fasakha -- git -C "$stock_branches_root" rev-parse "$stock_branches_revision:$stock_branches_script")
[[ "$stock_branches_blob" =~ ^[0-9a-f]{40}$ ]]
stock_branches_tmp=$(mktemp -d /tmp/stock-branches-only.XXXXXXXX)
chmod 0755 "$stock_branches_tmp"
stock_branches_cleanup() {
  stock_branches_exit=$?
  trap - EXIT
  rm -rf -- "$stock_branches_tmp"
  exit "$stock_branches_exit"
}
trap stock_branches_cleanup EXIT
runuser -u fasakha -- git -C "$stock_branches_root" show "$stock_branches_revision:$stock_branches_script" > "$stock_branches_tmp/apply.py"
[[ $(git hash-object --no-filters "$stock_branches_tmp/apply.py") == "$stock_branches_blob" ]]
chmod 0644 "$stock_branches_tmp/apply.py"
# Parse and verify the single PHP source payload before changing application source.
runuser -u fasakha -- python3 "$stock_branches_tmp/apply.py" \
  --root "$stock_branches_root" --revision "$stock_branches_revision" --php "$stock_branches_php" --check
if [[ $# == 2 ]]; then
  printf '%s\n' 'STOCK_BRANCHES_ONLY_CHECK_COMPLETE no_mail_sent=1'
  exit 0
fi
runuser -u fasakha -- python3 "$stock_branches_tmp/apply.py" \
  --root "$stock_branches_root" --revision "$stock_branches_revision" --php "$stock_branches_php"
printf '%s\n' 'STOCK_BRANCHES_ONLY_RELEASE_COMPLETE no_mail_sent=1'
