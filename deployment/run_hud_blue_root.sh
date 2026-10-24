#!/usr/bin/env bash
# Change the server HUD asset after quiescing native updates; leave them paused.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
server_revision=$1
[[ "$server_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --apply
server_stage=$(mktemp -d /tmp/server-hud-blue.XXXXXXXX)
server_timer_paused=0
server_cleanup() {
    local server_status=$?
    trap - EXIT INT TERM HUP
    rm -rf -- "$server_stage"
    if ((server_timer_paused)); then
        printf '%s\n' GO_ERP_HUD_BLUE_TIMER_PAUSED >&2
    fi
    exit "$server_status"
}
trap server_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
server_git() {
    env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_REPLACE_OBJECTS=1 GIT_TERMINAL_PROMPT=0 \
        git --git-dir="$server_stage/release.git" -c credential.helper= \
        -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null \
        -c submodule.recurse=false "$@"
}
git -c core.hooksPath=/dev/null init --bare --quiet "$server_stage/release.git"
server_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$server_revision"
test "$(server_git rev-parse "$server_revision^{commit}")" = "$server_revision"
server_git show "$server_revision:deployment/apply_hud_blue_root.py" > "$server_stage/apply.py"
server_git show "$server_revision:deployment/inspect_hud_blue_native_root.py" > "$server_stage/inspect.py"

systemctl stop go-erp-live-updates.timer
test "$(systemctl show go-erp-live-updates.timer -p ActiveState --value)" = inactive
server_timer_paused=1
for ((server_elapsed=0; server_elapsed<=180; server_elapsed+=10)); do
    server_state=$(systemctl show go-erp-live-updates.service -p ActiveState --value)
    case "$server_state" in
        inactive|failed) break ;;
        active|activating|reloading|deactivating)
            if ((server_elapsed==180)); then
                printf '%s\n' GO_ERP_HUD_BLUE_EXISTING_WORKER_RUNNING_RETRY_LATER >&2
                exit 75
            fi
            printf 'GO_ERP_HUD_BLUE_WAITING_WORKER seconds=%s\n' "$server_elapsed"
            sleep 10 ;;
        *) printf '%s\n' GO_ERP_HUD_BLUE_SERVICE_STATE_UNREVIEWED >&2; exit 1 ;;
    esac
done
python3 -I -B "$server_stage/apply.py" \
    --git-dir "$server_stage/release.git" --release "$server_revision" --apply
systemctl stop go-erp-live-updates.timer
test "$(systemctl show go-erp-live-updates.timer -p ActiveState --value)" = inactive
printf '%s\n' SERVER_HUD_BLUE_READY
python3 -I -B "$server_stage/inspect.py"
