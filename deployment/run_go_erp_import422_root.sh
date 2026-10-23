#!/usr/bin/env bash
# Fetch one immutable reviewed package; never check out or execute a remote hook.
set -euo pipefail
umask 077
test "$#" -eq 1
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
go_work=/var/lib/go-erp-live-updates
test ! -L "$go_work"
test "$(stat -c '%a:%u:%g' -- "$go_work")" = 700:0:0
go_stage=$(mktemp -d "$go_work/import422-download.XXXXXXXX")
go_timer=go-erp-live-updates.timer
go_service=go-erp-live-updates.service
go_restore_timer=0
go_safe_restore=1
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if (( go_restore_timer )); then
        if (( go_safe_restore )); then
            if systemctl start "$go_timer" && systemctl is-active --quiet "$go_timer"; then
                printf '%s\n' GO_ERP_TIMER_RESTORED
            else
                printf '%s\n' GO_ERP_TIMER_RESTORE_FAILED >&2
                go_status=1
            fi
        else
            printf '%s\n' GO_ERP_TIMER_LEFT_PAUSED_PENDING_RECOVERY >&2
            go_status=78
        fi
    fi
    if ((go_safe_restore)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_PACKAGE_RETAINED_FOR_RECOVERY %s\n' "$go_stage" >&2
    fi
    exit "$go_status"
}
trap go_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
go_git() {
    env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_REPLACE_OBJECTS=1 GIT_TERMINAL_PROMPT=0 \
        git --git-dir="$go_stage/release.git" -c credential.helper= \
        -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null \
        -c submodule.recurse=false "$@"
}
git -c core.hooksPath=/dev/null init --bare --quiet "$go_stage/release.git"
go_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision"
test "$(go_git rev-parse FETCH_HEAD)" = "$go_revision"
mkdir "$go_stage/package"
go_export() {
    local go_source=$1 go_target=$2
    mkdir -p -- "$(dirname -- "$go_stage/package/$go_target")"
    go_git show "$go_revision:$go_source" > "$go_stage/package/$go_target"
    chmod 0644 "$go_stage/package/$go_target"
}
for go_file in upgrade_go_erp_import422_watcher_root.py go_erp_import422_application_patch.py \
    refresh_desktop_dashboard_server_manifest.py; do
    go_export "deployment/$go_file" "$go_file"
done
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in \
    go_erp_live_source.py go_erp_live_updates.py publish_go_erp_update.py \
    go_erp_current_candidate_ci.yml verify_go_erp_current_candidate_ci.py \
    desktop_dashboard_server_preflight.py diagnose_desktop_dashboard_current_source.py; do
    go_export "deployment/import422-prior/payload/$go_file" "payload/$go_file"
    chmod 0600 "$go_stage/package/payload/$go_file"
done
for go_file in \
    app/Services/Dashboard/DesktopDashboardBootstrap.php \
    app/Services/Dashboard/DesktopDashboardImport.php \
    desktop/import.php desktop/import-diagnostic.php; do
    go_export "$go_file" "application/$go_file"
done
go_timer_before=$(systemctl show "$go_timer" -p ActiveState --value)
case "$go_timer_before" in
    active|activating|reloading) go_restore_timer=1 ;;
    inactive|failed) ;;
    *) printf '%s\n' GO_ERP_TIMER_STATE_UNREVIEWED >&2; exit 1 ;;
esac
systemctl stop "$go_timer"
test "$(systemctl show "$go_timer" -p ActiveState --value)" = inactive
for ((go_elapsed=0; go_elapsed<=60; go_elapsed+=5)); do
    go_state=$(systemctl show "$go_service" -p ActiveState --value)
    case "$go_state" in
        inactive|failed) break ;;
        active|activating|reloading|deactivating)
            if ((go_elapsed == 60)); then
                printf '%s\n' GO_ERP_EXISTING_WORKER_STILL_RUNNING_RETRY_LATER
                exit 75
            fi
            printf 'GO_ERP_WAITING_EXISTING_WORKER seconds=%s\n' "$go_elapsed"
            sleep 5
            ;;
        *) printf '%s\n' GO_ERP_SERVICE_STATE_UNREVIEWED >&2; exit 1 ;;
    esac
done
go_safe_restore=0
if /usr/bin/python3 -I -B "$go_stage/package/upgrade_go_erp_import422_watcher_root.py" \
    --package "$go_stage/package" --apply; then
    go_safe_restore=1
    systemctl start --no-block "$go_service"
    printf '%s\n' GO_ERP_DIAGNOSTIC_BUILD_REQUESTED
else
    go_status=$?
    case "$go_status" in
        1|75) go_safe_restore=1 ;;
        *) go_safe_restore=0 ;;
    esac
    exit "$go_status"
fi
