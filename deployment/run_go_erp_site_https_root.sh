#!/usr/bin/env bash
# Resume the exact preview-60 operator with the separately journaled site adapter.
set -euo pipefail
umask 077
test "$#" -eq 1
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
go_site_adapter=$1
[[ "$go_site_adapter" = /* ]]
test -f "$go_site_adapter"
test ! -L "$go_site_adapter"
printf '%s  %s\n' b98ff39b304f658c25b7882830c61dc86040c29fb5026dc65e3bb1b8be831401 "$go_site_adapter" | sha256sum --check --status

go_site_revision=1e651117deabb4c6935933ee424237d82bd316cb
go_site_candidate=d1976ce02c553939032241e7e724d26a9bbe7190
python3 -I -B - "$go_site_candidate" <<'PY'
import json, sys
with open('/var/lib/go-erp-live-updates/state/current.json') as stream:
    desired = json.load(stream).get('desired') or {}
if desired.get('commit') != sys.argv[1] or desired.get('version') != '0.4.4-preview.60':
    raise SystemExit('GO_ERP_SITE_HTTPS_CANDIDATE_NOT_PREVIEW_60')
PY

go_site_runner=$(mktemp /var/tmp/go-erp-site-https-runner.XXXXXXXX)
trap 'rm -f -- "$go_site_runner"' EXIT
curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_site_revision/deployment/run_go_erp_shared_workspace_schema_compat_root.sh" \
    -o "$go_site_runner"
printf '%s  %s\n' \
    818f8f29c409a79399c3910d545c238d943ba7f8d5491bcedb411ae5bb6fa53d \
    "$go_site_runner" | sha256sum --check --status

# These two exact edits were already used for preview 60. Verify their result.
sed -i "s/^    5becb9bab38a91998d1b1341670b2b398e4ba70f\$/&\n    $go_site_candidate/" "$go_site_runner"
sed -i 's|^if /usr/bin/python3 -I -B "\$go_workspace_stage/compat.py"|if /usr/bin/python3 -I -B "$GO_ERP_SITE_FIX" "$go_workspace_stage/compat.py"|' "$go_site_runner"
printf '%s  %s\n' \
    aeb61bd32caf58002c1fc4e83b40b62bcf580b9a3ddd5e0fc8d7b1c838987d15 \
    "$go_site_runner" | sha256sum --check --status
GO_ERP_SITE_FIX="$go_site_adapter" bash "$go_site_runner" "$go_site_revision" --complete
