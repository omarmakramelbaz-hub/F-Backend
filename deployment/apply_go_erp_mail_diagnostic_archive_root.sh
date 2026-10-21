#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
if [ "$#" -ne 2 ] || [ "$1" != --release ]; then
  printf '%s\n' GO_ERP_MAIL_ARCHIVE_PINNED_RELEASE_REQUIRED
  exit 1
fi
go_rev="$2"
[[ "$go_rev" =~ ^[a-f0-9]{40}$ ]]
go_root=/home/fasakha/public_html
go_archive_blob=e5c5ddfd7648c1f396f7c2c6d60d70f6002b9af6
go_tmp="$(mktemp -d /tmp/go-erp-mail-archive.XXXXXXXX)"
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
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/archive_go_erp_mail_diagnostics_root.py")" = "$go_archive_blob"
stage_go_blob() {
  local go_expected="$1" go_file="$2"
  runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" cat-file blob "$go_expected" | dd of="$go_file" status=none
  test "$(git hash-object --no-filters "$go_file")" = "$go_expected"
}
stage_go_blob "$go_archive_blob" "$go_tmp/archive.py"
stage_go_blob 8da3f1f89a2ab6ecbf5979942c97c7705510f2c1 "$go_tmp/retry.py"
stage_go_blob 1842302a87b7e93253ad5be616c3ab3e56bdd4a1 "$go_tmp/check.py"
stage_go_blob 795cf1ea467143e8a8ba0d4b6213a0c5ab36e741 "$go_tmp/ops.py"
stage_go_blob 822a91a76a773b2e8dccb49707cf484f9dd122f1 "$go_tmp/protection.py"

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
    printf '%s\n' GO_ERP_ARCHIVE_WAITING_FOR_EXISTING_SERVICE
    exit 1
    ;;
esac

go_post_status=0
if /usr/bin/python3 -I -B "$go_tmp/archive.py" "$go_tmp/retry.py" "$go_tmp/check.py" "$go_tmp/ops.py" --archive; then
  go_build_deadline=$((SECONDS+1800))
  go_waiting=1
  for go_build_tick in {1..31}; do
    if [ "$SECONDS" -ge "$go_build_deadline" ]; then
      break
    fi
    go_tick_status=0
    go_tick_report="$(/usr/bin/python3 -I -B "$go_tmp/protection.py" "$go_tmp/retry.py" "$go_tmp/check.py")" || go_tick_status=$?
    printf '%s\n' "$go_tick_report"
    if [ "$go_tick_status" -ne 0 ]; then
      go_post_status=1
      go_waiting=0
      break
    fi
    go_tick_outcome="$(printf '%s' "$go_tick_report" | /usr/bin/python3 -I -B -c '
import json,sys
try:
    value=json.load(sys.stdin)
    outcome=value.get("outcome")
    allowed={"GO_ERP_LIVE_UPDATE_CURRENT","GO_ERP_LIVE_UPDATE_RETRY_DEFERRED","GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED","GO_ERP_LIVE_UPDATE_WAITING_CI","GO_ERP_LIVE_UPDATE_SUPERSEDED","GO_ERP_LIVE_UPDATE_PUBLISHED","GO_ERP_LIVE_UPDATE_PUBLISHED_STORAGE_REVIEW_REQUIRED"}
    if value.get("ok") is not True or outcome not in allowed:
        raise ValueError()
except Exception:
    print("GO_ERP_MAIL_ARCHIVE_UNRECOGNIZED_TICK")
    sys.exit(1)
print(outcome)
')" || {
      printf '%s\n' GO_ERP_MAIL_ARCHIVE_UNRECOGNIZED_TICK
      go_post_status=1
      go_waiting=0
      break
    }
    case "$go_tick_outcome" in
      GO_ERP_LIVE_UPDATE_CURRENT|GO_ERP_LIVE_UPDATE_PUBLISHED)
        go_waiting=0
        break
        ;;
      GO_ERP_LIVE_UPDATE_CI_REVIEW_REQUIRED|GO_ERP_LIVE_UPDATE_PUBLISHED_STORAGE_REVIEW_REQUIRED)
        go_post_status=1
        go_waiting=0
        break
        ;;
      GO_ERP_LIVE_UPDATE_WAITING_CI|GO_ERP_LIVE_UPDATE_RETRY_DEFERRED|GO_ERP_LIVE_UPDATE_SUPERSEDED)
        if [ "$go_build_tick" -eq 31 ] || [ "$SECONDS" -ge "$go_build_deadline" ]; then
          break
        fi
        printf '%s\n' GO_ERP_WAITING_FOR_ACCEPTED_WINDOWS_BUILD
        sleep 60
        ;;
    esac
  done
  if [ "$go_waiting" -eq 1 ]; then
    printf '%s\n' GO_ERP_BUILD_PENDING_TIMER_CONTINUES
    go_post_status=1
  fi
else
  go_post_status=1
fi
/usr/bin/python3 -I -B "$go_tmp/check.py" --release "$go_rev" || go_post_status=1
if [ "$go_restore_timer" -eq 1 ]; then
  systemctl start go-erp-live-updates.timer
  go_restore_timer=0
fi
systemctl is-active go-erp-live-updates.timer || true
exit "$go_post_status"
