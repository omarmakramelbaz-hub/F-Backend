#!/usr/bin/env bash
# Apply the blue HUD and complete its native rollout through the original Windows-proof gate.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --complete
go_prior=2d9e23a5157782882382c797a44ad15c317ae845
go_work=/var/lib/go-erp-live-updates
test ! -L "$go_work"
test "$(stat -c '%a:%u:%g' -- "$go_work")" = 700:0:0
go_stage=$(mktemp -d "$go_work/blue-sound-completion.XXXXXXXX")
go_done=0
go_timer_paused=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_BLUE_SOUND_COMPLETION_PACKAGE_RETAINED %s\n' "$go_stage" >&2
    fi
    if ((go_timer_paused)); then
        printf '%s\n' GO_ERP_BLUE_SOUND_TIMER_PAUSED_PUBLICATION_NOT_CONFIRMED >&2
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
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision" "$go_prior"
test "$(go_git rev-parse "$go_revision^{commit}")" = "$go_revision"
test "$(go_git rev-parse "$go_prior^{commit}")" = "$go_prior"
mkdir "$go_stage/package"
go_export() {
    local go_ref=$1 go_source=$2 go_target=$3
    mkdir -p -- "$(dirname -- "$go_stage/package/$go_target")"
    go_git show "$go_ref:$go_source" > "$go_stage/package/$go_target"
    chmod 0644 "$go_stage/package/$go_target"
}
for go_file in complete_go_erp_blue_sound_root.py \
    upgrade_go_erp_blue_sound_root.py upgrade_go_erp_fullscreen_watcher_root.py; do
    go_export "$go_revision" "deployment/$go_file" "$go_file"
done
go_export "$go_prior" deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py upgrade_go_erp_program_watcher_root.py
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "$go_prior" "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in go_erp_program_application_patch.py refresh_desktop_dashboard_server_manifest.py; do
    go_export "$go_prior" "deployment/$go_file" "$go_file"
done
go_git show "$go_revision:deployment/apply_hud_blue_root.py" > "$go_stage/server-blue.py"
go_timer=go-erp-live-updates.timer
go_service=go-erp-live-updates.service
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
    if /usr/bin/python3 -I -B "$go_stage/package/complete_go_erp_blue_sound_root.py" \
        "${go_arguments[@]}" > "$go_stage/result.json"; then
        cat "$go_stage/result.json"
    else
        go_status=$?
        cat "$go_stage/result.json"
        return "$go_status"
    fi
    go_action=$(/usr/bin/python3 -I -B -c '
import json,sys
v=json.load(open(sys.argv[1],encoding="utf-8"));mode=sys.argv[2]
if not(v.get("ok") is True and v.get("kind")=="go-erp-blue-sound-completion"
    and v.get("application_contents_changed") is False and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False):raise SystemExit(78)
action=v.get("worker_request")
allowed={"lease":{"prepare","status"},"status":{"candidate","collect","authorize","publish","none"},"authorize":{"publish"},"verify":{"none"}}
if (action not in allowed[mode] or v.get("published") is not (action=="none")
    or v.get("publication_held") is not (action!="none")):raise SystemExit(78)
print(action)
' "$go_stage/result.json" "$go_mode")
}
go_worker() {
    local go_phase=$1 go_before go_elapsed go_values go_key go_value
    local go_state go_started go_result go_status go_seen=0
    go_before=$(systemctl show "$go_service" -p ExecMainStartTimestampMonotonic --value)
    [[ "$go_before" =~ ^[0-9]+$ ]]
    systemctl start --no-block "$go_service"
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
                    journalctl -u "$go_service" -n 25 --no-pager -o cat |
                        /usr/bin/python3 -I -B -c 'import re,sys;sys.stdout.writelines(x for x in sys.stdin if re.fullmatch(r"GO_ERP_[A-Z0-9_]+\n?",x))' >&2 || true
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
# The server asset is idempotent and commits before the native source capture.
/usr/bin/python3 -I -B "$go_stage/server-blue.py" \
    --git-dir "$go_stage/release.git" --release "$go_revision" --apply
go_action=
go_entry lease
if [[ "$go_action" = prepare ]]; then
    if /usr/bin/python3 -I -B "$go_stage/package/upgrade_go_erp_blue_sound_root.py" \
        --package "$go_stage/package" --apply > "$go_stage/prepared.json"; then
        cat "$go_stage/prepared.json"
    else
        go_status=$?
        cat "$go_stage/prepared.json"
        exit "$go_status"
    fi
    /usr/bin/python3 -I -B -c '
import json,sys
v=json.load(open(sys.argv[1],encoding="utf-8"))
if not(v.get("ok") is True and v.get("kind")=="go-erp-fullscreen-prepared"
    and v.get("worker_request")=="candidate" and v.get("published") is False
    and v.get("publication_held") is True and v.get("application_contents_changed") is False
    and v.get("database_changed") is False):raise SystemExit(78)
' "$go_stage/prepared.json"
else
    test "$go_action" = status
fi
go_entry status
for ((go_round=0; go_round<=40; go_round++)); do
    case "$go_action" in
        none) break ;;
        candidate) go_worker candidate ;;
        authorize)
            go_entry authorize
            test "$go_action" = publish
            go_worker publish ;;
        publish) go_worker publish ;;
        collect)
            if ((go_round==40)); then
                printf '%s\n' GO_ERP_CI_WAIT_TIMED_OUT_RETRY_SAME_COMMAND >&2
                exit 75
            fi
            printf 'GO_ERP_WAITING_REQUIRED_CI round=%s\n' "$go_round"
            sleep 60
            go_worker collect ;;
        *) printf '%s\n' GO_ERP_COMPLETION_ACTION_UNREVIEWED >&2; exit 1 ;;
    esac
    go_entry status
done
go_entry verify
test "$go_action" = none
systemctl start "$go_timer"
systemctl is-active --quiet "$go_timer"
go_timer_paused=0
go_done=1
printf '%s\n' GO_ERP_BLUE_SOUND_PUBLISHED_AUTOMATIC_UPDATES_ACTIVE
