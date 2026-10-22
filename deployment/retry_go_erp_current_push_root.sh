#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
test "$1" = --release
go_rev="$2"
[[ "$go_rev" =~ ^[a-f0-9]{40}$ ]]
go_root=/home/fasakha/public_html
go_retry=418378891b6bb3af29d9da81f45c57af2a601c2e
go_helper=5e379867b27cc91b6494b7f5008802c5a8b0b936
go_tmp="$(mktemp -d /tmp/go-erp-current-push.XXXXXXXX)"
go_restore_timer=0
cleanup_go_erp() {
  local go_status=$?
  trap - EXIT
  if [ "$go_restore_timer" -eq 1 ]; then
    if ! systemctl start go-erp-live-updates.timer; then
      printf '%s\n' GO_ERP_TIMER_RESTORE_FAILED >&2
      go_status=1
    fi
  fi
  rm -rf -- "$go_tmp"
  exit "$go_status"
}
trap cleanup_go_erp EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
stage_go_blob() {
  local go_pin="$1" go_file="$2"
  runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" cat-file blob "$go_pin" | dd of="$go_file" status=none
  test "$(git hash-object --no-filters "$go_file")" = "$go_pin"
}
test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/diagnose_go_erp_current_push_root.py")" = "$go_retry"
test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/upgrade_go_erp_live_runtime_root.py")" = "$go_helper"
stage_go_blob "$go_retry" "$go_tmp/retry.py"
stage_go_blob "$go_helper" "$go_tmp/helper.py"
if systemctl is-active --quiet go-erp-live-updates.timer; then
  go_restore_timer=1
  systemctl stop go-erp-live-updates.timer
fi
for go_wait in {1..9}; do
  go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
  case "$go_state" in
    active|activating|reloading|deactivating)
      printf 'GO_ERP_CURRENT_PUSH_WAITING_SERVICE waited_seconds=%s max_wait_seconds=45\n' "$(( (go_wait - 1) * 5 ))"
      sleep 5
      ;;
    *) break ;;
  esac
done
go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
case "$go_state" in
  active|activating|reloading|deactivating)
    printf '%s\n' GO_ERP_CURRENT_PUSH_EXISTING_SERVICE_STILL_RUNNING
    exit 1
    ;;
esac
printf '%s\n' GO_ERP_CURRENT_PUSH_DIAGNOSIS_STARTED
go_result=0
/usr/bin/python3 -I -B "$go_tmp/retry.py" "$go_tmp/helper.py" --retry-prepared || go_result=$?
if [ "$go_restore_timer" -eq 1 ]; then
  systemctl start go-erp-live-updates.timer
  go_restore_timer=0
  printf '%s\n' GO_ERP_AUTOMATIC_SOURCE_BUILD_CONTINUES
else
  printf '%s\n' GO_ERP_LIVE_TIMER_REMAINS_INACTIVE
fi
exit "$go_result"
