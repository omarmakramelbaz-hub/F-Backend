#!/usr/bin/env bash
# Preserve the last published installer; upgrade the manual server bridge only.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --apply
go_stage=$(mktemp -d /var/tmp/go-erp-published-downloads.XXXXXXXX)
go_done=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_PUBLISHED_DOWNLOADS_PACKAGE_RETAINED %s\n' "$go_stage" >&2
    fi
    exit "$go_status"
}
trap go_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
go_git() {
    env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_REPLACE_OBJECTS=1 GIT_TERMINAL_PROMPT=0 GIT_NO_LAZY_FETCH=1 \
        git --git-dir="$go_stage/release.git" -c credential.helper= \
        -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null \
        -c submodule.recurse=false "$@"
}
git -c core.hooksPath=/dev/null init --bare --quiet "$go_stage/release.git"
go_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision"
test "$(go_git rev-parse "$go_revision^{commit}")" = "$go_revision"
go_git show "$go_revision:deployment/apply_go_erp_published_downloads_root.py" > "$go_stage/apply.py"
chmod 0644 "$go_stage/apply.py"
if /usr/bin/python3 -I -B "$go_stage/apply.py" --git-dir "$go_stage/release.git" \
    --release "$go_revision" --apply > "$go_stage/result.txt"; then
    :
else
    go_status=$?
    cat "$go_stage/result.txt"
    exit "$go_status"
fi
/usr/bin/python3 -I -B -c '
import json,re,sys
s=open(sys.argv[1],encoding="utf-8").read()
if len(s)>65536:raise SystemExit(78)
lines=s.splitlines()
if len(lines)!=2 or lines[1]!="GO_ERP_PUBLISHED_DOWNLOADS_READY_MANUAL_UPDATES_ONLY":raise SystemExit(78)
v=json.loads(lines[0])
if not(type(v.get("format")) is int and v["format"]==1
    and v.get("kind")=="go-erp-published-downloads-installed" and v.get("ok") is True
    and v.get("automatic_updates_enabled") is False and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False and type(v.get("source_changed")) is bool
    and v.get("path_active") is True and isinstance(v.get("archive"),dict)
    and isinstance(v.get("runtime"),str)
    and re.fullmatch(r"/opt/go-erp-manual-updates/releases/[a-f0-9]{64}",v["runtime"])):raise SystemExit(78)
' "$go_stage/result.txt"
cat "$go_stage/result.txt"
go_done=1
