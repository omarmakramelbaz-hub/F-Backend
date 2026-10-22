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
go_helper=86e6e8f720f9532e4dacb98358ede4555cae58e5
go_tmp="$(mktemp -d /tmp/go-erp-bootstrap-memory.XXXXXXXX)"
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
test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/apply_go_erp_bootstrap_memory_root.py")" = "$go_helper"
stage_go_blob "$go_helper" "$go_tmp/apply.py"
stage_go_blob 1842302a87b7e93253ad5be616c3ab3e56bdd4a1 "$go_tmp/check.py"
stage_go_blob 795cf1ea467143e8a8ba0d4b6213a0c5ab36e741 "$go_tmp/ops.py"
for go_name in DesktopDashboardBootstrap DesktopDashboardData; do
  go_source_pin="$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:app/Services/Dashboard/$go_name.php")"
  [[ "$go_source_pin" =~ ^[a-f0-9]{40}$ ]]
  stage_go_blob "$go_source_pin" "$go_tmp/$go_name.php"
done
if systemctl is-active --quiet go-erp-live-updates.timer; then
  go_restore_timer=1
  systemctl stop go-erp-live-updates.timer
fi
for go_wait in {1..15}; do
  go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
  case "$go_state" in
    active|activating|reloading|deactivating) sleep 1 ;;
    *) break ;;
  esac
done
go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
case "$go_state" in
  active|activating|reloading|deactivating)
    printf '%s\n' GO_ERP_MEMORY_PATCH_WAITING_FOR_EXISTING_SERVICE
    exit 1
    ;;
esac
/usr/bin/python3 -I -B "$go_tmp/apply.py" "$go_tmp/check.py" "$go_tmp/ops.py" \
  "$go_tmp/DesktopDashboardBootstrap.php" "$go_tmp/DesktopDashboardData.php" --apply
/usr/bin/python3 -I -B "$go_tmp/check.py" --release "$go_rev"
if [ "$go_restore_timer" -eq 1 ]; then
  systemctl start go-erp-live-updates.timer
  go_restore_timer=0
fi
printf '%s\n' GO_ERP_BOOTSTRAP_MEMORY_PATCH_READY
if systemctl is-active --quiet go-erp-live-updates.timer; then
  printf '%s\n' GO_ERP_AUTOMATIC_SOURCE_BUILD_CONTINUES
else
  printf '%s\n' GO_ERP_LIVE_TIMER_INACTIVE
fi
