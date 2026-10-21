#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
if [ "$#" -ne 2 ] || [ "$1" != --release ]; then
  printf '%s\n' GO_ERP_PUSH_PROTECTION_PINNED_RELEASE_REQUIRED
  exit 1
fi
go_rev="$2"
[[ "$go_rev" =~ ^[a-f0-9]{40}$ ]]
go_root=/home/fasakha/public_html
go_probe_blob=822a91a76a773b2e8dccb49707cf484f9dd122f1
go_tmp="$(mktemp -d /tmp/go-erp-push-protection.XXXXXXXX)"
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

test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/diagnose_go_erp_push_protection_root.py")" = "$go_probe_blob"
stage_go_blob() {
  local go_expected="$1" go_file="$2"
  runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" cat-file blob "$go_expected" | dd of="$go_file" status=none
  test "$(git hash-object --no-filters "$go_file")" = "$go_expected"
}
stage_go_blob "$go_probe_blob" "$go_tmp/protection.py"
stage_go_blob 8da3f1f89a2ab6ecbf5979942c97c7705510f2c1 "$go_tmp/retry.py"
stage_go_blob 1842302a87b7e93253ad5be616c3ab3e56bdd4a1 "$go_tmp/check.py"

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
    printf '%s\n' GO_ERP_RETRY_WAITING_FOR_EXISTING_SERVICE
    exit 1
    ;;
esac

go_post_status=0
/usr/bin/python3 -I -B "$go_tmp/protection.py" "$go_tmp/retry.py" "$go_tmp/check.py" || go_post_status=1
/usr/bin/python3 -I -B "$go_tmp/check.py" --release "$go_rev" || go_post_status=1
if [ "$go_restore_timer" -eq 1 ]; then
  systemctl start go-erp-live-updates.timer
  go_restore_timer=0
fi
systemctl is-active go-erp-live-updates.timer || true
exit "$go_post_status"
