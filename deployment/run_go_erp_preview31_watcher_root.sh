#!/usr/bin/env bash
# Run only from the immutable, root-owned reviewed deployment package.
set -euo pipefail
umask 077
test "$#" -eq 0
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_deployment="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
go_helper=ede9af30ea42ba53a5376d5e1cf634d9b3032f2a
test ! -L "$go_deployment/upgrade_go_erp_preview31_watcher_root.py"
test "$(git hash-object --no-filters "$go_deployment/upgrade_go_erp_preview31_watcher_root.py")" = "$go_helper"

go_timer=go-erp-live-updates.timer
go_service=go-erp-live-updates.service
go_restore_timer=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if (( go_restore_timer )); then
        if systemctl start "$go_timer" && systemctl is-active --quiet "$go_timer"; then
            printf '%s\n' GO_ERP_TIMER_RESTORED
        else
            printf '%s\n' GO_ERP_TIMER_RESTORE_FAILED >&2
            go_status=1
        fi
    fi
    exit "$go_status"
}
trap go_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

go_timer_before="$(systemctl show "$go_timer" -p ActiveState --value)"
case "$go_timer_before" in
    active|activating|reloading) go_restore_timer=1 ;;
    inactive|failed) ;;
    *) printf '%s\n' GO_ERP_TIMER_STATE_UNREVIEWED >&2; exit 1 ;;
esac
systemctl stop "$go_timer"
test "$(systemctl show "$go_timer" -p ActiveState --value)" = inactive

for (( go_elapsed=0; go_elapsed<=90; go_elapsed+=5 )); do
    go_state="$(systemctl show "$go_service" -p ActiveState --value)"
    case "$go_state" in
        inactive|failed) break ;;
        active|activating|reloading|deactivating)
            if (( go_elapsed == 90 )); then
                printf '%s\n' GO_ERP_WAITING_FOR_EXISTING_SERVICE_WITHOUT_STOPPING_IT
                exit 75
            fi
            printf 'GO_ERP_PREVIEW31_WAITING_SERVICE waited_seconds=%s max_wait_seconds=90\n' "$go_elapsed"
            sleep 5
            ;;
        *) printf '%s\n' GO_ERP_SERVICE_STATE_UNREVIEWED >&2; exit 1 ;;
    esac
done

if /usr/bin/python3 -I -B "$go_deployment/upgrade_go_erp_preview31_watcher_root.py" \
    "$go_deployment/preview31-watcher" --upgrade; then
    if systemctl start --no-block "$go_service"; then
        printf '%s\n' GO_ERP_REVIEWED_BUILD_WORKER_REQUESTED
    else
        printf '%s\n' GO_ERP_REVIEWED_BUILD_WORKER_START_FAILED >&2
        exit 1
    fi
else
    go_status=$?
    exit "$go_status"
fi
