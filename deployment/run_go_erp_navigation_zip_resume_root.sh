#!/usr/bin/env bash
# Resume the same reviewed .59 artifact; never allocate another version.
set -euo pipefail
umask 077
test "$#" -eq 2
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_revision=$1
[[ "$go_revision" =~ ^[a-f0-9]{40}$ ]]
test "$2" = --complete
go_stage=$(mktemp -d /var/tmp/go-erp-navigation-zip-resume.XXXXXXXX)
go_done=0
go_cleanup() {
    local go_status=$?
    trap - EXIT INT TERM HUP
    if ((go_done)); then
        rm -rf -- "$go_stage"
    else
        printf 'GO_ERP_NAVIGATION_ZIP_RESUME_PACKAGE_RETAINED %s\n' "$go_stage" >&2
        printf '%s\n' GO_ERP_NAVIGATION_ZIP_RESUME_RETRY_SAME_COMMAND >&2
    fi
    exit "$go_status"
}
trap go_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
go_fetch() {
    local go_name=$1 go_sha=$2
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_revision/deployment/$go_name" \
        -o "$go_stage/$go_name"
    printf '%s  %s\n' "$go_sha" "$go_stage/$go_name" | sha256sum --check --status
    chmod 0600 "$go_stage/$go_name"
}
go_fetch go_erp_navigation_zip_compat_root.py ab2267fb8ec29188a39b9d1a44b643a95c7e483b1ef50ffc2b6724961268bad2
go_fetch resume_go_erp_navigation_zip_root.py 9bdb9f645d63bcf69793a36e70f444d310aaa96d87dab6879983ef60a790e1b9
if /usr/bin/python3 -I -B "$go_stage/resume_go_erp_navigation_zip_root.py" \
    --compat-file "$go_stage/go_erp_navigation_zip_compat_root.py" --complete \
    | tee "$go_stage/result.txt" | /usr/bin/python3 -u -c '
import re,sys
for line in sys.stdin:
    if line.rstrip("\n")!="GO_ERP_NAVIGATION_PUBLISHED_MANUAL_UPDATES_ONLY" and re.fullmatch(r"GO_ERP_[A-Z0-9_]+(?: seconds=[0-9]+)?\n",line):
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
raw=open(sys.argv[1],encoding="utf8").read()
if len(raw)>1048576:raise SystemExit(78)
lines=raw.splitlines()
if len(lines)<2 or lines[-1]!="GO_ERP_NAVIGATION_PUBLISHED_MANUAL_UPDATES_ONLY":raise SystemExit(78)
value=json.loads(lines[-2])
if not(type(value.get("format")) is int and value["format"]==1 and value.get("kind")=="go-erp-navigation-rollout"
    and value.get("ok") is True and value.get("published") is True and value.get("prepared") is True
    and value.get("manual_path_paused") is False and value.get("database_changed") is False
    and value.get("credentials_disclosed") is False and value.get("automatic_updates_enabled") is False
    and value.get("version")=="0.4.4-preview.59"
    and value.get("candidate_revision")=="5becb9bab38a91998d1b1341670b2b398e4ba70f"
    and isinstance(value.get("archive"),dict) and value["archive"].get("available") is True
    and value["archive"].get("current_version")==value["version"]):raise SystemExit(78)
' "$go_stage/result.txt"
tail -n 2 "$go_stage/result.txt"
go_done=1
