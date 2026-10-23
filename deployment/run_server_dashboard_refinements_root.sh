#!/usr/bin/env bash
# Server dashboard only: HUD layout and unpaid/failed order handling.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
server_revision=$1
[[ "$server_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --apply
server_stage=$(mktemp -d /tmp/server-dashboard-update.XXXXXXXX)
trap 'rm -rf -- "$server_stage"' EXIT
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
server_git show "$server_revision:deployment/apply_server_dashboard_refinements_root.py" > "$server_stage/apply.py"
python3 -I -B "$server_stage/apply.py" \
    --git-dir "$server_stage/release.git" --release "$server_revision" --apply
printf '%s\n' SERVER_DASHBOARD_REFINEMENTS_READY
