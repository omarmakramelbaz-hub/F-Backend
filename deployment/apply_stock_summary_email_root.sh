#!/usr/bin/env bash
set -euo pipefail
umask 077

summary_revision=${1:?Full revision required}
[[ "$summary_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]]
summary_root=/home/fasakha/public_html
summary_php=/opt/cpanel/ea-php82/root/usr/bin/php
summary_script=deployment/apply_stock_summary_email.py
[[ -x "$summary_php" ]]
command -v python3 >/dev/null
command -v systemctl >/dev/null
command -v flock >/dev/null
# One root wrapper owns preflight, scheduler state changes, publication and EXIT
# cleanup. The descriptor stays open through the timer restart in the trap.
summary_lock=/run/fasakhansta-stock-summary-email.lock
python3 - "$summary_lock" <<'PY_LOCK'
import os
import stat
import sys
fd = os.open(sys.argv[1], os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
try:
    info = os.fstat(fd)
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
            or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o077):
        raise SystemExit('STOCK_SUMMARY_ROOT_LOCK_INVALID')
finally:
    os.close(fd)
PY_LOCK
exec {summary_lock_fd}<>"$summary_lock"
if ! flock --nonblock "$summary_lock_fd"; then
  printf '%s\n' 'STOCK_SUMMARY_INSTALLER_ALREADY_RUNNING'
  exit 1
fi
runuser -u fasakha -- git -C "$summary_root" cat-file -e "$summary_revision^{commit}"
summary_blob=$(runuser -u fasakha -- git -C "$summary_root" rev-parse "$summary_revision:$summary_script")
[[ "$summary_blob" =~ ^[0-9a-f]{40}$ ]]
summary_tmp=$(mktemp -d /tmp/stock-summary-email.XXXXXXXX)
chmod 0755 "$summary_tmp"
summary_timer_was_active=0
summary_timer_paused=0
summary_cleanup() {
  summary_exit=$?
  trap - EXIT
  if [[ "$summary_timer_paused" == 1 && "$summary_timer_was_active" == 1 ]]; then
    if ! systemctl start fasakhansta-stock-minimums.timer; then
      printf '%s\n' 'STOCK_SUMMARY_TIMER_RESTART_FAILED code_installation_may_be_complete=1'
      summary_exit=1
    fi
  fi
  rm -rf -- "$summary_tmp"
  exit "$summary_exit"
}
trap summary_cleanup EXIT
runuser -u fasakha -- git -C "$summary_root" show "$summary_revision:$summary_script" > "$summary_tmp/apply.py"
[[ $(git hash-object --no-filters "$summary_tmp/apply.py") == "$summary_blob" ]]
chmod 0644 "$summary_tmp/apply.py"
# Preflight before changing the scheduler or any application source.
runuser -u fasakha -- python3 "$summary_tmp/apply.py" \
  --root "$summary_root" --revision "$summary_revision" --php "$summary_php" --check
if [[ $# == 2 ]]; then
  printf '%s\n' 'STOCK_SUMMARY_CHECK_COMPLETE no_mail_sent=1'
  exit 0
fi
[[ $(systemctl show --property=LoadState --value fasakhansta-stock-minimums.timer) == loaded ]]
[[ $(systemctl show --property=LoadState --value fasakhansta-stock-minimums.service) == loaded ]]
summary_timer_state=$(systemctl show --property=ActiveState --value fasakhansta-stock-minimums.timer)
[[ "$summary_timer_state" == active || "$summary_timer_state" == inactive ]]
if [[ "$summary_timer_state" == active ]]; then
  summary_timer_was_active=1
  # Set this before stop so the trap restores the timer even if stop fails.
  summary_timer_paused=1
  systemctl stop fasakhansta-stock-minimums.timer
fi
# Do not interrupt an SMTP send or terminate an active outbox delivery.
summary_wait_deadline=$((SECONDS + 190))
while true; do
  summary_service_state=$(systemctl show --property=ActiveState --value fasakhansta-stock-minimums.service)
  case "$summary_service_state" in
    inactive|failed) break ;;
    active|activating|deactivating|reloading)
      [[ $SECONDS -lt $summary_wait_deadline ]] || { printf '%s\n' 'STOCK_SUMMARY_ACTIVE_MAIL_WORKER_RETRY_LATER'; exit 1; }
      sleep 1
      ;;
    *) printf '%s\n' 'STOCK_SUMMARY_SERVICE_STATE_NEEDS_REVIEW'; exit 1 ;;
  esac
done
runuser -u fasakha -- python3 "$summary_tmp/apply.py" \
  --root "$summary_root" --revision "$summary_revision" --php "$summary_php"
printf '%s\n' 'STOCK_SUMMARY_RELEASE_COMPLETE no_mail_sent_by_installer=1'
