#!/usr/bin/env bash
# One guarded shared-workspace update. Release pins are filled only after CI.
set -euo pipefail
umask 077
test "$#" -eq 2
go_workspace_operator=$1
[[ "$go_workspace_operator" =~ ^[a-f0-9]{40}$ && "$2" = --complete ]]
go_workspace_core='02ecf87ef2de331aab662b4754686107461da72b'
go_workspace_seed='01efd6648f191cb1e3c461de81b753528a840e98'
go_workspace_web='02ecf87ef2de331aab662b4754686107461da72b'
for go_workspace_pin in "$go_workspace_core" "$go_workspace_seed" "$go_workspace_web"; do
    [[ "$go_workspace_pin" =~ ^[a-f0-9]{40}$ && "$go_workspace_pin" != 0000000000000000000000000000000000000000 ]] || {
        printf '%s\n' GO_ERP_SHARED_WORKSPACE_FINAL_PINS_UNAVAILABLE >&2
        exit 78
    }
done
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_workspace_stage=$(mktemp -d /var/tmp/go-erp-shared-workspace.XXXXXXXX)
go_workspace_done=0
go_workspace_cleanup() {
    local go_workspace_status=$?
    trap - EXIT INT TERM HUP
    if ((go_workspace_done)); then
        rm -rf -- "$go_workspace_stage"
    else
        printf '%s\n' GO_ERP_SHARED_WORKSPACE_PACKAGE_RETAINED_RETRY_SAME_COMMAND >&2
    fi
    exit "$go_workspace_status"
}
trap go_workspace_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
go_workspace_git() {
    env GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
        GIT_NO_REPLACE_OBJECTS=1 GIT_NO_LAZY_FETCH=1 GIT_TERMINAL_PROMPT=0 \
        /usr/bin/git --git-dir="$go_workspace_stage/release.git" \
        -c credential.helper= -c core.hooksPath=/dev/null -c core.attributesFile=/dev/null \
        -c submodule.recurse=false "$@"
}
/usr/bin/git -c core.hooksPath=/dev/null init --bare --quiet "$go_workspace_stage/release.git"
go_workspace_refs=(
    "$go_workspace_operator" "$go_workspace_core" "$go_workspace_seed" "$go_workspace_web"
    18e13894c1afcc9f3daa61df2446b02cf806cb7a
    2d9e23a5157782882382c797a44ad15c317ae845
    12d74b4f35737a2b1e271657b159137538c3edaf
    33e08c9fec93501d2376eb88d9fd402043f8d792
    8db5adb538834a2152f74f6e55336223a4c95908
    5628a33525e2af63b221f044eb0e650cb6a2b790
    13fd8b038bd1a853a30ba9b4140a053088efa73d
    0885a3b550f92df2b74bcd2c16d27954d8b44b7b
    9867dc29357e16290b3a1eeda996d916af701915
    f3a8b4af4ec27042a93328c1fde200cfd8fe1da3
    be797004831cbd8ccfb7c011aac855d6c2964829
    5becb9bab38a91998d1b1341670b2b398e4ba70f
)
go_workspace_git fetch --quiet --depth=1 --no-tags --no-recurse-submodules \
    https://github.com/omarmakramelbaz-hub/F-Backend.git "${go_workspace_refs[@]}"
for go_workspace_pin in "${go_workspace_refs[@]}"; do
    test "$(go_workspace_git rev-parse "$go_workspace_pin^{commit}")" = "$go_workspace_pin"
done
go_workspace_git show "$go_workspace_operator:deployment/go_erp_shared_workspace_root.py" > "$go_workspace_stage/root.py"
chmod 0600 "$go_workspace_stage/root.py"
if /usr/bin/python3 -I -B "$go_workspace_stage/root.py" --git-dir "$go_workspace_stage/release.git" \
    --release "$go_workspace_operator" --complete | tee "$go_workspace_stage/result.txt" | /usr/bin/python3 -u -c '
import re,sys
for line in sys.stdin:
    if re.fullmatch(r"GO_ERP_[A-Z0-9_]+(?: seconds=[0-9]+)?\n",line) and "PUBLISHED_MANUAL_UPDATES_ONLY" not in line:
        sys.stdout.write(line);sys.stdout.flush()
'; then
    :
else
    go_workspace_status=$?
    printf '%s\n' GO_ERP_SHARED_WORKSPACE_NOT_COMPLETE_RETRY_SAME_COMMAND >&2
    exit "$go_workspace_status"
fi
/usr/bin/python3 -I -B -c '
import json,re,sys
raw=open(sys.argv[1],encoding="utf-8").read()
if len(raw)>1048576:raise SystemExit(78)
lines=raw.splitlines()
if len(lines)<2 or lines[-1]!="GO_ERP_SHARED_WORKSPACE_PUBLISHED_MANUAL_UPDATES_ONLY":raise SystemExit(78)
v=json.loads(lines[-2])
if not(type(v.get("format")) is int and v["format"]==1 and v.get("kind")=="go-erp-shared-workspace-rollout"
    and v.get("ok") is True and v.get("published") is True and v.get("database_changed") is True
    and type(v.get("database_new_tables")) is int and v["database_new_tables"]==4
    and v.get("financial_data_changed") is False and v.get("workspace_enabled") is True
    and v.get("credentials_disclosed") is False and v.get("automatic_updates_enabled") is False
    and isinstance(v.get("candidate_revision"),str) and re.fullmatch(r"[a-f0-9]{40}",v["candidate_revision"])
    and isinstance(v.get("version"),str) and re.fullmatch(r"0\.4\.4-preview\.[1-9][0-9]{0,14}",v["version"])):raise SystemExit(78)
' "$go_workspace_stage/result.txt"
tail -n 2 "$go_workspace_stage/result.txt"
go_workspace_done=1
