#!/usr/bin/env bash
# Publish one reviewed native upgrade from the exact accepted .56 cohort; automatic timer remains disabled.
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
go_previous=deaa588337a84521eb7a6201066df3156991af64
go_template=12d74b4f35737a2b1e271657b159137538c3edaf
go_old_seed=d064c097fcc7351ae78749d7390e27d74239525a
go_seed=a78fbbb4b394e676adfe3699c24aa0a362f4025a
go_old_core=617ff126b5c38bd1d70e5b2b78eb8e0b3e36b60a
go_input=cbef3c25085da15ce8e9d5884448ddece16c19ae
go_core=0c1cf5b6e2fb2030228f17771c506e3bac61c376
go_archive=5628a33525e2af63b221f044eb0e650cb6a2b790
[[ "$go_core" =~ ^[a-f0-9]{40}$ && "$go_seed" =~ ^[a-f0-9]{40}$ ]]
go_stage=$(mktemp -d /var/tmp/go-erp-login-reopen.XXXXXXXX)
go_done=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_LOGIN_REOPEN_PACKAGE_RETAINED %s\n' "$go_stage" >&2
        printf '%s\n' GO_ERP_LOGIN_REOPEN_MANUAL_PATH_MAY_BE_PAUSED_RETRY_SAME_COMMAND >&2
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
    https://github.com/omarmakramelbaz-hub/F-Backend.git "$go_revision" "$go_bootstrap" "$go_prior" "$go_previous" "$go_template" "$go_old_seed" "$go_seed" "$go_old_core" "$go_input" "$go_core" "$go_archive"
for go_commit in "$go_revision" "$go_bootstrap" "$go_prior" "$go_previous" "$go_template" "$go_old_seed" "$go_seed" "$go_old_core" "$go_input" "$go_core" "$go_archive"; do
    test "$(go_git rev-parse "$go_commit^{commit}")" = "$go_commit"
done
go_git show "$go_revision:deployment/upgrade_go_erp_login_reopen_root.py" > "$go_stage/apply.py"
chmod 0600 "$go_stage/apply.py"
# Keep progress visible; the final marker is emitted only after proof+archive.
if /usr/bin/python3 -I -B "$go_stage/apply.py" --git-dir "$go_stage/release.git" \
    --release "$go_revision" "$go_mode" | tee "$go_stage/result.txt" | /usr/bin/python3 -u -c '
import re,sys
held={"GO_ERP_LOGIN_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY","GO_ERP_LOGIN_REOPEN_PREPARED_PUBLICATION_HELD"}
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
marker="GO_ERP_LOGIN_REOPEN_PUBLISHED_MANUAL_UPDATES_ONLY" if complete else "GO_ERP_LOGIN_REOPEN_PREPARED_PUBLICATION_HELD"
if lines[-1]!=marker:raise SystemExit(78)
v=json.loads(lines[-2])
if not(type(v.get("format")) is int and v["format"]==1 and v.get("kind")=="go-erp-login-reopen-rollout"
    and v.get("ok") is True and v.get("published") is complete and v.get("prepared") is True
    and v.get("manual_path_paused") is (not complete) and v.get("database_changed") is False
    and v.get("credentials_disclosed") is False and v.get("automatic_updates_enabled") is False):raise SystemExit(78)
if complete and not(isinstance(v.get("version"),str) and re.fullmatch(r"0\.4\.4-preview\.[1-9][0-9]{0,14}",v["version"]) and int(v["version"].rsplit(".",1)[1]) >= 57
    and isinstance(v.get("candidate_revision"),str) and re.fullmatch(r"[a-f0-9]{40}",v["candidate_revision"])
    and isinstance(v.get("archive"),dict) and v["archive"].get("available") is True
    and v["archive"].get("current_version")==v["version"]):raise SystemExit(78)
' "$go_stage/result.txt" "$go_mode"
tail -n 2 "$go_stage/result.txt"
go_done=1
