#!/usr/bin/env bash
# Apply the reviewed native seed and navigation successor; keep publication held.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --apply
go_prior=2d9e23a5157782882382c797a44ad15c317ae845
go_work=/var/lib/go-erp-live-updates
test ! -L "$go_work"
test "$(stat -c '%a:%u:%g' -- "$go_work")" = 700:0:0
go_stage=$(mktemp -d "$go_work/fullscreen-download.XXXXXXXX")
go_done=0
go_timer_paused=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_FULLSCREEN_PACKAGE_RETAINED %s\n' "$go_stage" >&2
    fi
    if ((go_timer_paused)); then
        printf '%s\n' GO_ERP_FULLSCREEN_TIMER_PAUSED_PUBLICATION_HELD >&2
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
go_export "$go_revision" deployment/upgrade_go_erp_fullscreen_watcher_root.py upgrade_go_erp_fullscreen_watcher_root.py
go_export "$go_revision" public/dashboard/js/dashboard-navigation.js application/public/dashboard/js/dashboard-navigation.js
go_export "$go_prior" deployment/nav-successor-prior/upgrade_go_erp_program_watcher_root.py upgrade_go_erp_program_watcher_root.py
for go_file in upgrade_go_erp_preview31_watcher_root.py upgrade_go_erp_capacity_watcher_root.py; do
    go_export "$go_prior" "deployment/import422-prior/$go_file" "$go_file"
done
for go_file in go_erp_program_application_patch.py refresh_desktop_dashboard_server_manifest.py; do
    go_export "$go_prior" "deployment/$go_file" "$go_file"
done

systemctl stop go-erp-live-updates.timer
test "$(systemctl show go-erp-live-updates.timer -p ActiveState --value)" = inactive
go_timer_paused=1
for ((go_elapsed=0; go_elapsed<=60; go_elapsed+=5)); do
    go_state=$(systemctl show go-erp-live-updates.service -p ActiveState --value)
    case "$go_state" in
        inactive|failed) break ;;
        active|activating|reloading|deactivating)
            if ((go_elapsed==60)); then
                printf '%s\n' GO_ERP_FULLSCREEN_EXISTING_WORKER_RUNNING_RETRY_LATER
                exit 75
            fi
            printf 'GO_ERP_FULLSCREEN_WAITING_WORKER seconds=%s\n' "$go_elapsed"
            sleep 5
            ;;
        *) printf '%s\n' GO_ERP_FULLSCREEN_SERVICE_STATE_UNREVIEWED >&2; exit 1 ;;
    esac
done
if ! /usr/bin/python3 -I -B "$go_stage/package/upgrade_go_erp_fullscreen_watcher_root.py" \
    --package "$go_stage/package" --apply > "$go_stage/result.json"; then
    cat "$go_stage/result.json"
    exit 1
fi
cat "$go_stage/result.json"
/usr/bin/python3 -I -B -c '
import json,sys
value=json.load(open(sys.argv[1],encoding="utf-8"))
if not(value.get("ok") is True and value.get("kind")=="go-erp-fullscreen-prepared"
    and value.get("worker_request")=="candidate" and value.get("publication_held") is True
    and value.get("published") is False and value.get("database_changed") is False):raise SystemExit(78)
' "$go_stage/result.json"
# The coherent seed generates one new candidate. Its original Windows proofs
# and the unauthorised hold remain mandatory before any installed client update.
systemctl start --no-block go-erp-live-updates.service
go_done=1
printf '%s\n' GO_ERP_FULLSCREEN_NATIVE_PREPARED_WAITING_WINDOWS_CI
