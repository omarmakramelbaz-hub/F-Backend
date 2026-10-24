#!/usr/bin/env bash
# Verify/repair published42 and install server buttons; complete builds only by explicit mode.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$2" = --apply || "$2" = --complete ]]
go_mode=$2
go_native_sha=11982bda14275d1f5fc7cd23b3a7f523a97e7e5651d7c9c537bd326a686656cb
go_stage=$(mktemp -d /var/tmp/go-erp-button-flow.XXXXXXXX)
go_done=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_BUTTON_FLOW_PACKAGE_RETAINED %s\n' "$go_stage" >&2
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
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision" \
    18e13894c1afcc9f3daa61df2446b02cf806cb7a 2d9e23a5157782882382c797a44ad15c317ae845
test "$(go_git rev-parse "$go_revision^{commit}")" = "$go_revision"
go_git show "$go_revision:deployment/apply_go_erp_button_flow_root.py" > "$go_stage/apply.py"
chmod 0600 "$go_stage/apply.py"
if /usr/bin/python3 -I -B "$go_stage/apply.py" --git-dir "$go_stage/release.git" \
    --release "$go_revision" --apply > "$go_stage/buttons.txt"; then
    :
else
    go_status=$?
    cat "$go_stage/buttons.txt"
    exit "$go_status"
fi
/usr/bin/python3 -I -B -c '
import json,sys
raw=open(sys.argv[1],encoding="utf-8").read()
if len(raw)>65536:raise SystemExit(78)
lines=raw.splitlines()
if len(lines)!=2 or lines[1]!="GO_ERP_BUTTON_FLOW_READY_DOWNLOADS_CHECKED":raise SystemExit(78)
v=json.loads(lines[0]);p=v.get("verified42")
if not(type(v.get("format")) is int and v["format"]==1 and v.get("kind")=="go-erp-button-flow-installed"
    and v.get("ok") is True and type(v.get("source_changed")) is bool
    and all(v.get(k) is False for k in ("database_changed","native_state_changed","timer_changed","automatic_updates_enabled"))
    and isinstance(p,dict) and p.get("version")=="0.4.4-preview.42"
    and type(p.get("verified")) is bool
    and all(type(p.get(k)) is bool for k in ("in_download_slots","binary_repaired","manifest_repaired"))):raise SystemExit(78)
if p["in_download_slots"]:
    if not(p["verified"] is True and p.get("source_revision")=="26c6f8b6d80ab8da05b88a00789483a51a4c9a2a"
        and p.get("installer_sha256")=="f918e811ca501612d75b196de56cfe18fad9fac547ad79dc791b38928aac5b9f"
        and type(p.get("installer_bytes")) is int and p["installer_bytes"]==415949308):raise SystemExit(78)
else:
    if not(p["verified"] is False and p["binary_repaired"] is False and p["manifest_repaired"] is False
        and all(p.get(k) is None for k in ("source_revision","installer_sha256","installer_bytes"))
        and p.get("source_kind")=="not-referenced"):raise SystemExit(78)
' "$go_stage/buttons.txt"
cat "$go_stage/buttons.txt"
if [[ "$go_mode" = --complete ]]; then
    go_git show "$go_revision:deployment/upgrade_go_erp_close_reopen_root.py" > "$go_stage/complete.py"
    chmod 0600 "$go_stage/complete.py"
    printf '%s  %s\n' "$go_native_sha" "$go_stage/complete.py" | sha256sum --check --status
    if /usr/bin/python3 -I -B "$go_stage/complete.py" --git-dir "$go_stage/release.git" \
        --release "$go_revision" --complete | tee "$go_stage/program.txt" | /usr/bin/python3 -u -c '
import re,sys
held="GO_ERP_CLOSE_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY"
for line in sys.stdin:
    if line.rstrip("\n")!=held and re.fullmatch(r"GO_ERP_[A-Z0-9_]+(?: seconds=[0-9]+)?\n",line):
        sys.stdout.write(line);sys.stdout.flush()
'; then
        :
    else
        go_status=$?
        tail -n 1 "$go_stage/program.txt"
        exit "$go_status"
    fi
    /usr/bin/python3 -I -B -c '
import json,re,sys
raw=open(sys.argv[1],encoding="utf-8").read()
if len(raw)>1048576:raise SystemExit(78)
lines=raw.splitlines()
if len(lines)<2 or lines[-1]!="GO_ERP_CLOSE_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY":raise SystemExit(78)
v=json.loads(lines[-2]);a=v.get("archive")
if not(type(v.get("format")) is int and v["format"]==1 and v.get("kind")=="go-erp-close-reopen-rollout"
    and v.get("ok") is True and v.get("published") is True and v.get("prepared") is True
    and v.get("manual_path_paused") is False and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False and v.get("automatic_updates_enabled") is False
    and isinstance(v.get("version"),str) and re.fullmatch(r"0\.4\.4-preview\.[1-9][0-9]{0,14}",v["version"])
    and isinstance(v.get("candidate_revision"),str) and re.fullmatch(r"[a-f0-9]{40}",v["candidate_revision"])
    and isinstance(a,dict) and a.get("available") is True and a.get("current_version")==v["version"]):raise SystemExit(78)
' "$go_stage/program.txt"
    tail -n 2 "$go_stage/program.txt"
    printf '%s\n' GO_ERP_BUTTON_FLOW_AND_CLOSE_REOPEN_PUBLISHED
fi
go_done=1
