#!/usr/bin/env bash
# Publish one reviewed native upgrade from the exact accepted .58 cohort; automatic timer remains disabled.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$2" = --complete || "$2" = --apply ]]
go_mode=$2
go_bootstrap=18e13894c1afcc9f3daa61df2446b02cf806cb7a
go_prior=2d9e23a5157782882382c797a44ad15c317ae845
go_previous=56c8680ac8d3d5cb68eaebd623daeba897cc3a48
go_template=12d74b4f35737a2b1e271657b159137538c3edaf
go_old_seed=6c867ad6c79b27ace92ab3a256e01b13e41137d8
go_seed=0885a3b550f92df2b74bcd2c16d27954d8b44b7b
go_old_core=df1860734942b5fcb95cbc634e95c4c0b3cca03c
go_input=02411715ca112f3d4393f1ca1b83d377aa253786
go_core=13fd8b038bd1a853a30ba9b4140a053088efa73d
go_web=b199706b24146c85760b673448cb2e8bcd1f7812
go_archive=5628a33525e2af63b221f044eb0e650cb6a2b790
[[ "$go_core" =~ ^[a-f0-9]{40}$ && "$go_seed" =~ ^[a-f0-9]{40}$ && "$go_web" =~ ^[a-f0-9]{40}$ ]]
go_stage=$(mktemp -d /var/tmp/go-erp-navigation.XXXXXXXX)
go_done=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_NAVIGATION_PACKAGE_RETAINED %s\n' "$go_stage" >&2
        printf '%s\n' GO_ERP_NAVIGATION_MANUAL_PATH_MAY_BE_PAUSED_RETRY_SAME_COMMAND >&2
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
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision" "$go_bootstrap" "$go_prior" "$go_previous" "$go_template" "$go_old_seed" "$go_seed" "$go_old_core" "$go_input" "$go_core" "$go_web" "$go_archive"
for go_commit in "$go_revision" "$go_bootstrap" "$go_prior" "$go_previous" "$go_template" "$go_old_seed" "$go_seed" "$go_old_core" "$go_input" "$go_core" "$go_web" "$go_archive"; do
    test "$(go_git rev-parse "$go_commit^{commit}")" = "$go_commit"
done
go_git show "$go_revision:deployment/upgrade_go_erp_navigation_root.py" > "$go_stage/apply.py"
chmod 0600 "$go_stage/apply.py"
# Keep progress visible; the final marker is emitted only after proof+archive.
if /usr/bin/python3 -I -B "$go_stage/apply.py" --git-dir "$go_stage/release.git" \
    --release "$go_revision" "$go_mode" | tee "$go_stage/result.txt" | /usr/bin/python3 -u -c '
import re,sys
held={"GO_ERP_NAVIGATION_PUBLISHED_MANUAL_UPDATES_ONLY","GO_ERP_NAVIGATION_PREPARED_PUBLICATION_HELD"}
for line in sys.stdin:
    if line.rstrip("\n") not in held and re.fullmatch(r"GO_ERP_[A-Z0-9_]+(?: seconds=[0-9]+)?\n",line):
        sys.stdout.write(line);sys.stdout.flush()
'; then
    :
else
    go_status=$?
    tail -n 1 "$go_stage/result.txt"
    exit "$go_status"
fi
/usr/bin/python3 -I -B -c '
import json,re,sys
raw=open(sys.argv[1],encoding="utf-8").read()
if len(raw)>1048576:raise SystemExit(78)
lines=raw.splitlines();complete=sys.argv[2]=="--complete"
if len(lines)<2:raise SystemExit(78)
marker="GO_ERP_NAVIGATION_PUBLISHED_MANUAL_UPDATES_ONLY" if complete else "GO_ERP_NAVIGATION_PREPARED_PUBLICATION_HELD"
if lines[-1]!=marker:raise SystemExit(78)
v=json.loads(lines[-2])
if not(type(v.get("format")) is int and v["format"]==1 and v.get("kind")=="go-erp-navigation-rollout"
    and v.get("ok") is True and v.get("published") is complete and v.get("prepared") is True
    and v.get("manual_path_paused") is (not complete) and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False and v.get("automatic_updates_enabled") is False):raise SystemExit(78)
if complete and not(v.get("version")=="0.4.4-preview.59"
    and isinstance(v.get("candidate_revision"),str) and re.fullmatch(r"[a-f0-9]{40}",v["candidate_revision"])
    and isinstance(v.get("archive"),dict) and v["archive"].get("available") is True
    and v["archive"].get("current_version")==v["version"]):raise SystemExit(78)
' "$go_stage/result.txt" "$go_mode"
tail -n 2 "$go_stage/result.txt"
go_done=1
