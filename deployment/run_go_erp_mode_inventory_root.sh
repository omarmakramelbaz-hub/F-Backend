#!/usr/bin/env bash
# Reconcile one held inventory, then collect and publish the exact tested 37.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --complete
go_work=/var/lib/go-erp-live-updates
test ! -L "$go_work"
test "$(stat -c '%a:%u:%g' -- "$go_work")" = 700:0:0
go_stage=$(mktemp -d "$go_work/mode-inventory-download.XXXXXXXX")
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
        printf '%s\n' GO_ERP_AUTOMATIC_UPDATES_NOT_CONFIRMED_RETRY_SAME_COMMAND >&2
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
for go_file in upgrade_go_erp_mode_inventory_root.py \
    upgrade_go_erp_confirmation_nav_successor_root.py \
    go_erp_confirmation_nav_application_patch.py go_erp_program_rollout_gate.py \
    refresh_desktop_dashboard_server_manifest.py; do
    go_export "deployment/$go_file" "$go_file"
done
go_export deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py upgrade_go_erp_program_watcher_root.py
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in go_erp_live_source.py go_erp_live_updates.py publish_go_erp_update.py \
    go_erp_current_candidate_ci.yml verify_go_erp_current_candidate_ci.py \
    desktop_dashboard_server_preflight.py diagnose_desktop_dashboard_current_source.py; do
    go_export "deployment/wire-hash-prior/payload/$go_file" "payload/$go_file"
    chmod 0600 "$go_stage/package/payload/$go_file"
done
# Fetch only public immutable proof objects into this disposable private cache.
# The installed watcher's checkout, references and state remain untouched.
go_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git \
    5f2b0fecafec1c3803499284b0abcca4ecfed7e4 \
    cd35ee36d77bc35593cac3627a33307b71309325
test "$(go_git rev-parse 5f2b0fecafec1c3803499284b0abcca4ecfed7e4^{commit})" = 5f2b0fecafec1c3803499284b0abcca4ecfed7e4
test "$(go_git rev-parse cd35ee36d77bc35593cac3627a33307b71309325^{commit})" = cd35ee36d77bc35593cac3627a33307b71309325
go_timer_before=$(systemctl show "$go_timer" -p ActiveState --value)
case "$go_timer_before" in
    active|activating|reloading|inactive|failed) ;;
    *) printf '%s\n' GO_ERP_TIMER_STATE_UNREVIEWED >&2; exit 1 ;;
esac
systemctl stop "$go_timer"
test "$(systemctl show "$go_timer" -p ActiveState --value)" = inactive
go_timer_paused=1
for ((go_elapsed=0; go_elapsed<=180; go_elapsed+=10)); do
    go_state=$(systemctl show "$go_service" -p ActiveState --value)
    case "$go_state" in
        inactive|failed) break ;;
        active|activating|reloading|deactivating)
            if ((go_elapsed==180)); then
                printf '%s\n' GO_ERP_EXISTING_WORKER_STILL_RUNNING_RETRY_LATER
                exit 75
            fi
            printf 'GO_ERP_WAITING_EXISTING_WORKER seconds=%s\n' "$go_elapsed"
            sleep 10 ;;
        *) printf '%s\n' GO_ERP_SERVICE_STATE_UNREVIEWED >&2; exit 1 ;;
    esac
done
go_entry() {
    local go_mode=$1 go_status
    local go_arguments=(--package "$go_stage/package" --mode "$go_mode")
    if [[ "$go_mode" = authorize ]]; then
        go_arguments+=(--receipt "$go_work/pending-program-acceptance.json")
    fi
    if /usr/bin/python3 -I -B "$go_stage/package/upgrade_go_erp_mode_inventory_root.py" \
        "${go_arguments[@]}" > "$go_stage/result.json"; then
        cat "$go_stage/result.json"
    else
        go_status=$?
        cat "$go_stage/result.json"
        return "$go_status"
    fi
    go_action=$(/usr/bin/python3 -I -B -c '
import json,sys
value=json.load(open(sys.argv[1],encoding="utf-8"));mode=sys.argv[2]
if value.get("ok") is not True or value.get("kind")!="go-erp-mode-inventory-reconciliation":raise SystemExit(78)
action=value.get("worker_request")
if action=="none":
    if value.get("published") is not True:raise SystemExit(78)
elif value.get("published") is not False or (mode,action) not in (("reconcile","collect"),("status","authorize"),("authorize","publish")):raise SystemExit(78)
print(action)
' "$go_stage/result.json" "$go_mode")
}
go_worker() {
    local go_phase=$1 go_before go_elapsed go_values go_key go_value
    local go_state go_started go_result go_status go_seen=0
    go_before=$(systemctl show "$go_service" -p ExecMainStartTimestampMonotonic --value)
    [[ "$go_before" =~ ^[0-9]+$ ]]
    systemctl start --no-block "$go_service"
    # A newly queued oneshot may still show its previous inactive result.
    # Accept completion only after observing this invocation's newer timestamp.
    for ((go_elapsed=0; go_elapsed<=360; go_elapsed+=10)); do
        go_values=$(systemctl show "$go_service" -p ActiveState \
            -p ExecMainStartTimestampMonotonic -p Result -p ExecMainStatus)
        go_state= go_started= go_result= go_status=
        while IFS='=' read -r go_key go_value; do
            case "$go_key" in
                ActiveState) go_state=$go_value ;;
                ExecMainStartTimestampMonotonic) go_started=$go_value ;;
                Result) go_result=$go_value ;;
                ExecMainStatus) go_status=$go_value ;;
            esac
        done <<< "$go_values"
        [[ "$go_started" =~ ^[0-9]+$ ]]
        if ((go_started>go_before)); then go_seen=1; fi
        case "$go_state" in
            inactive|failed)
                if ((go_seen)); then
                    if [[ "$go_state" = inactive && "$go_result" = success && "$go_status" = 0 ]]; then
                        return 0
                    fi
                    printf 'GO_ERP_WORKER_FAILED phase=%s\n' "$go_phase" >&2
                    return 1
                fi ;;
            active|activating|reloading|deactivating) ;;
            *) printf '%s\n' GO_ERP_SERVICE_STATE_UNREVIEWED >&2; return 1 ;;
        esac
        if ((go_elapsed==360)); then
            printf 'GO_ERP_WORKER_WAIT_TIMED_OUT_WITHOUT_STOPPING_SERVICE phase=%s\n' "$go_phase" >&2
            return 75
        fi
        printf 'GO_ERP_WAITING_WORKER phase=%s seconds=%s\n' "$go_phase" "$go_elapsed"
        sleep 10
    done
}
go_action=
go_entry reconcile
if [[ "$go_action" != none ]]; then
    go_worker collect
    go_entry status
    if [[ "$go_action" = authorize ]]; then
        go_entry authorize
        test "$go_action" = publish
        go_worker publish
    fi
fi
# This check requires exact canonical 37, authenticated retained receipts,
# completed state/history/floors and the retired hold before timer resumption.
go_entry verify
test "$go_action" = none
systemctl start "$go_timer"
systemctl is-active --quiet "$go_timer"
go_timer_paused=0
go_safe_cleanup=1
printf '%s\n' GO_ERP_PREVIEW37_PUBLISHED_AUTOMATIC_UPDATES_ACTIVE
