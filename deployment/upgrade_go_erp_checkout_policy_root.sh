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
go_helper=aead20517a863bf767d0a59975f7669d8bbd7aa7
go_tmp="$(mktemp -d /tmp/go-erp-checkout-policy-upgrade.XXXXXXXX)"
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
test "$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/upgrade_go_erp_checkout_policy_root.py")" = "$go_helper"
stage_go_blob "$go_helper" "$go_tmp/upgrade.py"
mkdir -m 0700 "$go_tmp/payload"
for go_name in go_erp_live_source.py go_erp_live_updates.py publish_go_erp_update.py \
  go_erp_current_candidate_ci.yml verify_go_erp_current_candidate_ci.py; do
  go_payload_pin="$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 git -C "$go_root" rev-parse "$go_rev:deployment/$go_name")"
  [[ "$go_payload_pin" =~ ^[a-f0-9]{40}$ ]]
  stage_go_blob "$go_payload_pin" "$go_tmp/payload/$go_name"
done
if systemctl is-active --quiet go-erp-live-updates.timer; then
  go_restore_timer=1
  systemctl stop go-erp-live-updates.timer
fi
for go_wait in {1..9}; do
  go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
  case "$go_state" in
    active|activating|reloading|deactivating)
      printf 'GO_ERP_CHECKOUT_POLICY_WAITING_SERVICE waited_seconds=%s max_wait_seconds=45\n' "$(( (go_wait - 1) * 5 ))"
      sleep 5
      ;;
    *) break ;;
  esac
done
go_state="$(systemctl show go-erp-live-updates.service -p ActiveState --value)"
case "$go_state" in
  active|activating|reloading|deactivating)
    printf '%s\n' GO_ERP_CHECKOUT_POLICY_WAITING_FOR_EXISTING_SERVICE
    exit 1
    ;;
esac
/usr/bin/python3 -I -B "$go_tmp/upgrade.py" "$go_tmp/payload" --upgrade
if [ "$go_restore_timer" -eq 1 ]; then
  systemctl start go-erp-live-updates.timer
  go_restore_timer=0
fi
printf '%s\n' GO_ERP_CHECKOUT_POLICY_UPGRADE_READY
if systemctl is-active --quiet go-erp-live-updates.timer; then
  printf '%s\n' GO_ERP_AUTOMATIC_SOURCE_BUILD_CONTINUES
else
  printf '%s\n' GO_ERP_LIVE_TIMER_INACTIVE
fi
