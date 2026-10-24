#!/usr/bin/env bash
# Resume only the reviewed successful Preview 42 through the original publication gate.
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
go_stage=$(mktemp -d "$go_work/preview42-resume.XXXXXXXX")
go_done=0
go_timer_paused=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_PREVIEW42_RESUME_PACKAGE_RETAINED %s\n' "$go_stage" >&2
    fi
    if ((go_timer_paused)); then
        printf '%s\n' GO_ERP_PREVIEW42_TIMER_PAUSED_PUBLICATION_NOT_CONFIRMED >&2
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
for go_file in rearm_go_erp_preview42_root.py complete_go_erp_windows_fixtures_root.py \
    upgrade_go_erp_windows_fixtures_root.py upgrade_go_erp_fullscreen_watcher_root.py; do
    go_export "$go_revision" "deployment/$go_file" "$go_file"
done
go_export "$go_prior" deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py upgrade_go_erp_program_watcher_root.py
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "$go_prior" "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in go_erp_program_application_patch.py refresh_desktop_dashboard_server_manifest.py; do
    go_export "$go_prior" "deployment/$go_file" "$go_file"
done
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
    if /usr/bin/python3 -I -B "$go_stage/package/complete_go_erp_windows_fixtures_root.py" \
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
if not(v.get("ok") is True and v.get("kind")=="go-erp-windows-fixtures-completion"
    and v.get("candidate_revision")=="26c6f8b6d80ab8da05b88a00789483a51a4c9a2a"
    and v.get("version")=="0.4.4-preview.42"
    and v.get("application_contents_changed") is False and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False):raise SystemExit(78)
action=v.get("worker_request")
allowed={"status":{"collect","authorize","publish","none"},"authorize":{"publish"},"verify":{"none"}}
if (action not in allowed[mode] or v.get("published") is not (action=="none")
    or v.get("publication_held") is not (action!="none")):raise SystemExit(78)
print(action)
' "$go_stage/result.json" "$go_mode")
}
go_worker() {
    local go_phase=$1 go_before go_elapsed go_values go_key go_value
    local go_state go_started go_result go_status go_seen=0 go_since
    go_failure_code=
    go_since=$(date +%s) || return 1
    go_before=$(systemctl show "$go_service" -p ExecMainStartTimestampMonotonic --value) || return 1
    [[ "$go_before" =~ ^[0-9]+$ ]] || return 1
    systemctl start --no-block "$go_service" || return 1
    for ((go_elapsed=0; go_elapsed<=360; go_elapsed+=10)); do
        go_values=$(systemctl show "$go_service" -p ActiveState \
            -p ExecMainStartTimestampMonotonic -p Result -p ExecMainStatus) || return 1
        go_state= go_started= go_result= go_status=
        while IFS='=' read -r go_key go_value; do
            case "$go_key" in
                ActiveState) go_state=$go_value ;;
                ExecMainStartTimestampMonotonic) go_started=$go_value ;;
                Result) go_result=$go_value ;;
                ExecMainStatus) go_status=$go_value ;;
            esac
        done <<< "$go_values"
        [[ "$go_started" =~ ^[0-9]+$ ]] || return 1
        if ((go_started>go_before)); then go_seen=1; fi
        case "$go_state" in
            inactive|failed)
                if ((go_seen)); then
                    if [[ "$go_state" = inactive && "$go_result" = success && "$go_status" = 0 ]]; then
                        return 0
                    fi
                    printf 'GO_ERP_WORKER_FAILED phase=%s\n' "$go_phase" >&2
                    if journalctl -u "$go_service" --since "@$go_since" -n 25 --no-pager -o cat |
                        /usr/bin/python3 -I -B -c 'import re,sys;sys.stdout.writelines(x for x in sys.stdin if re.fullmatch(r"GO_ERP_[A-Z0-9_]+\n?",x))' > "$go_stage/worker-codes.txt"; then
                        cat "$go_stage/worker-codes.txt" >&2
                        go_failure_code=$(tail -n 1 "$go_stage/worker-codes.txt")
                    fi
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
        sleep 10 || return 1
    done
}
# Backoff remains owned by the installed worker; never clear retry metadata.
go_wait_backoff() {
    local go_delay go_waited=0
    while :; do
        go_delay=$(/usr/bin/python3 -I -B -c '
import json,math,sys,time
v=json.load(open(sys.argv[1],encoding="utf-8"));r=v.get("retry_at",0)
if type(r) not in (int,float) or not math.isfinite(r) or r<0:raise SystemExit(78)
print(max(0,math.ceil(r-time.time())))
' "$go_stage/result.json") || return 78
        [[ "$go_delay" =~ ^[0-9]+$ ]] || return 78
        if ((go_delay==0)); then return 0; fi
        if ((go_delay>3600 || go_waited>=3600)); then
            printf '%s\n' GO_ERP_BACKOFF_WAIT_LIMIT_RETRY_SAME_COMMAND >&2
            return 75
        fi
        if ((go_delay>60)); then go_delay=60; fi
        printf 'GO_ERP_RESPECTING_WORKER_BACKOFF seconds=%s\n' "$go_delay"
        sleep "$go_delay" || return 1
        go_waited=$((go_waited+go_delay))
    done
}
go_transient_failures=0
go_collect() {
    local go_worker_status
    go_wait_backoff || return "$?"
    if go_worker collect; then return 0; else go_worker_status=$?; fi
    if [[ "$go_failure_code" != GO_ERP_LIVE_TICK_FAILED_WITHOUT_PRIVATE_OUTPUT ]] ||
        ((go_transient_failures>=3)); then
        return "$go_worker_status"
    fi
    # Status rechecks the source, exact candidate, hold and canonical publication
    # under the watcher lock. A failed CI or altered state cannot reach a retry.
    go_entry status || return "$?"
    test "$go_action" = collect || return 78
    go_transient_failures=$((go_transient_failures+1))
    printf 'GO_ERP_TRANSIENT_COLLECT_RETRY attempt=%s limit=3\n' "$go_transient_failures"
}
# The fixed-candidate rearm cannot prepare or replace a native candidate.
/usr/bin/python3 -I -B -c '
import hashlib,sys
from pathlib import Path
p=Path(sys.argv[1])
if hashlib.sha256(p.read_bytes()).hexdigest()!="006fc75f28f1a070f0ab4cf90a66550c8ab71d150f3983b6da680d94e5af2b2f":raise SystemExit(78)
' "$go_stage/package/rearm_go_erp_preview42_root.py"
if /usr/bin/python3 -I -B "$go_stage/package/rearm_go_erp_preview42_root.py" \
    --package "$go_stage/package" --apply > "$go_stage/rearmed.json"; then
    cat "$go_stage/rearmed.json"
else
    go_status=$?
    cat "$go_stage/rearmed.json"
    exit "$go_status"
fi
/usr/bin/python3 -I -B -c '
import json,sys
v=json.load(open(sys.argv[1],encoding="utf-8"))
action=v.get("worker_request")
if not(type(v.get("format")) is int and v.get("format")==1
    and v.get("kind")=="go-erp-preview42-rearm" and v.get("ok") is True
    and type(v.get("rearmed")) is bool
    and v.get("candidate_revision")=="26c6f8b6d80ab8da05b88a00789483a51a4c9a2a"
    and v.get("version")=="0.4.4-preview.42"
    and v.get("application_contents_changed") is False and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False
    and action in ("collect","authorize","publish","none")
    and (v.get("rearmed") is False or action=="collect")
    and v.get("published") is (action=="none")
    and v.get("publication_held") is (action!="none")):raise SystemExit(78)
' "$go_stage/rearmed.json"
go_action=
go_entry status
test "$go_action" != candidate
for ((go_round=0; go_round<=40; go_round++)); do
    case "$go_action" in
        none) break ;;
        candidate) printf '%s\n' GO_ERP_PREVIEW42_ALLOCATION_FORBIDDEN >&2; exit 78 ;;
        authorize)
            go_entry authorize
            test "$go_action" = publish
            go_wait_backoff
            go_worker publish ;;
        publish) go_wait_backoff; go_worker publish ;;
        collect)
            if ((go_round==40)); then
                printf '%s\n' GO_ERP_CI_WAIT_TIMED_OUT_RETRY_SAME_COMMAND >&2
                exit 75
            fi
            printf 'GO_ERP_WAITING_REQUIRED_CI round=%s\n' "$go_round"
            sleep 60
            go_collect ;;
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
printf '%s\n' GO_ERP_PREVIEW42_PUBLISHED_AUTOMATIC_UPDATES_ACTIVE
