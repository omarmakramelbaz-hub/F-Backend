#!/usr/bin/env bash
# Fetch one immutable reviewed package; never check out or execute a remote hook.
set -euo pipefail
umask 077
test "$#" -eq 2 || test "$#" -eq 3
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
go_mode=$2
go_receipt=
case "$go_mode" in
    --prepare) test "$#" -eq 2 ;;
    --publish) test "$#" -eq 3; go_receipt=$3; [[ "$go_receipt" = /* ]] ;;
    *) printf '%s\n' GO_ERP_PROGRAM_MODE_REQUIRED >&2; exit 1 ;;
esac
go_work=/var/lib/go-erp-live-updates
test ! -L "$go_work"
test "$(stat -c '%a:%u:%g' -- "$go_work")" = 700:0:0
go_stage=$(mktemp -d "$go_work/program-download.XXXXXXXX")
go_timer=go-erp-live-updates.timer
go_service=go-erp-live-updates.service
go_safe_cleanup=0
go_timer_paused=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_safe_cleanup)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_PACKAGE_RETAINED_FOR_RECOVERY %s\n' "$go_stage" >&2
    fi
    if ((go_timer_paused)); then
        printf '%s\n' GO_ERP_TIMER_LEFT_PAUSED_PUBLICATION_HELD >&2
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
for go_file in go_erp_payment_nav_application_patch.py go_erp_program_rollout_gate.py \
    refresh_desktop_dashboard_server_manifest.py upgrade_go_erp_payment_nav_successor_root.py; do
    go_export "deployment/$go_file" "$go_file"
done
go_export deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py upgrade_go_erp_program_watcher_root.py
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in \
    go_erp_live_source.py go_erp_live_updates.py publish_go_erp_update.py \
    go_erp_current_candidate_ci.yml verify_go_erp_current_candidate_ci.py \
    desktop_dashboard_server_preflight.py diagnose_desktop_dashboard_current_source.py; do
    go_export "deployment/wire-hash-prior/payload/$go_file" "payload/$go_file"
    chmod 0600 "$go_stage/package/payload/$go_file"
done
go_export deployment/program-navigation/dashboard-navigation.js application/public/dashboard/js/dashboard-navigation.js

go_timer_before=$(systemctl show "$go_timer" -p ActiveState --value)
case "$go_timer_before" in
    active|activating|reloading|inactive|failed) ;;
    *) printf '%s\n' GO_ERP_TIMER_STATE_UNREVIEWED >&2; exit 1 ;;
esac
systemctl stop "$go_timer"
test "$(systemctl show "$go_timer" -p ActiveState --value)" = inactive
go_timer_paused=1
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
go_arguments=(--package "$go_stage/package" "$go_mode")
if [[ "$go_mode" = --publish ]]; then
    go_arguments+=(--acceptance-receipt "$go_receipt")
fi
if /usr/bin/python3 -I -B "$go_stage/package/upgrade_go_erp_payment_nav_successor_root.py" "${go_arguments[@]}" > "$go_stage/result.json"; then
    cat "$go_stage/result.json"
    go_action=$(/usr/bin/python3 -I -B -c '
import json,sys
value=json.load(open(sys.argv[1],encoding="utf-8"));mode=sys.argv[2]
if value.get("ok") is not True:raise SystemExit(78)
action=value.get("worker_request")
if action=="none":
    if value.get("kind")!="go-erp-program-already-completed" or value.get("resume_previous_timer") is not True or value.get("publication_held") is not False:raise SystemExit(78)
elif (mode,action) not in (("--prepare","candidate"),("--publish","authorized-publication")):raise SystemExit(78)
print(action)
' "$go_stage/result.json" "$go_mode")
    # Prepare may request one candidate/CI tick only under the durable hold.
    # It never restarts the timer or permits publication. Publish authorizes
    # exactly the tested desired candidate; the watcher reuses that candidate.
    if [[ "$go_action" = none ]]; then
        if [[ "$go_mode" = --publish ]]; then
            systemctl start "$go_timer"
            systemctl is-active --quiet "$go_timer"
        else
          case "$go_timer_before" in
            active|activating|reloading)
                systemctl start "$go_timer"
                systemctl is-active --quiet "$go_timer"
                go_timer_paused=0
                ;;
          esac
        fi
        go_timer_paused=0
        printf '%s\n' GO_ERP_PROGRAM_ALREADY_COMPLETED_NO_WORKER
    elif [[ "$go_action" = authorized-publication ]]; then
        systemctl start --no-block "$go_service"
        systemctl start "$go_timer"
        systemctl is-active --quiet "$go_timer"
        go_timer_paused=0
        printf '%s\n' GO_ERP_PROGRAM_TESTED_CANDIDATE_RESUMED
    else
        systemctl start --no-block "$go_service"
        printf '%s\n' GO_ERP_PROGRAM_CANDIDATE_REQUESTED_PUBLICATION_HELD
    fi
    go_safe_cleanup=1
else
    go_status=$?
    cat "$go_stage/result.json"
    exit "$go_status"
fi
