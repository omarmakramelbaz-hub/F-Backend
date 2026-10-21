#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
if [ "$#" -ne 2 ] || [ "$1" != --release ]; then
  printf '%s\n' GO_ERP_STATIC_SITE_PINNED_RELEASE_REQUIRED
  exit 1
fi
go_rev="$2"
[[ "$go_rev" =~ ^[a-f0-9]{40}$ ]]
go_root=/home/fasakha/public_html
go_mode_blob=60ca248f9f5f4ad16e694ff53fca2cf1aec45628
go_tmp="$(mktemp -d /tmp/go-erp-static-site.XXXXXXXX)"
go_restore_timer=0

cleanup_go_erp() {
  local go_exit_status=$?
  trap - EXIT
  if [ "$go_restore_timer" -eq 1 ]; then
    if ! systemctl start go-erp-live-updates.timer; then
      printf '%s\n' GO_ERP_TIMER_RESTORE_FAILED >&2
      go_exit_status=1
    fi
  fi
  rm -rf -- "$go_tmp"
  exit "$go_exit_status"
}
trap cleanup_go_erp EXIT

test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/repair_go_erp_static_source_modes_root.py")" = "$go_mode_blob"

stage_go_blob() {
  local go_expected="$1" go_file="$2"
  runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" cat-file blob "$go_expected" | dd of="$go_file" status=none
  test "$(git hash-object --no-filters "$go_file")" = "$go_expected"
}

stage_go_blob "$go_mode_blob" "$go_tmp/modes.py"
stage_go_blob 1842302a87b7e93253ad5be616c3ab3e56bdd4a1 "$go_tmp/check.py"
stage_go_blob c09b74e27e968e66caf36a65822f10d5cb0ea21a "$go_tmp/archive.py"
stage_go_blob 795cf1ea467143e8a8ba0d4b6213a0c5ab36e741 "$go_tmp/ops.py"

if systemctl is-active --quiet go-erp-live-updates.timer; then
  go_restore_timer=1
  systemctl stop go-erp-live-updates.timer
fi

for go_wait_count in {1..15}; do
  go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
  case "$go_state" in
    active|activating|reloading|deactivating) sleep 1 ;;
    *) break ;;
  esac
done

go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
case "$go_state" in
  active|activating|reloading|deactivating)
    printf '%s\n' GO_ERP_REPAIR_WAITING_FOR_EXISTING_SERVICE
    exit 1
    ;;
esac

/usr/bin/python3 -I -B "$go_tmp/modes.py" "$go_tmp/check.py" "$go_tmp/archive.py" "$go_tmp/ops.py"
/usr/bin/python3 -I -B "$go_tmp/archive.py" --release "$go_rev" --archive

go_post_status=0
systemctl start go-erp-live-updates.service || go_post_status=1
/usr/bin/python3 -I -B "$go_tmp/check.py" --release "$go_rev" || go_post_status=1

if [ "$go_restore_timer" -eq 1 ]; then
  systemctl start go-erp-live-updates.timer
  go_restore_timer=0
fi
systemctl is-active go-erp-live-updates.timer || true
journalctl -u go-erp-live-updates.service -n 20 --no-pager -o cat
exit "$go_post_status"
