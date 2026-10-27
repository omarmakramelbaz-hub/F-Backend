#!/usr/bin/env bash
# Exact twelve-file metadata repair, followed by the unchanged accepted operator.
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
[[ "$1" =~ ^[a-f0-9]{40}$ && "$2" = --complete ]]
go_modes_release=$1
go_modes_stage=$(mktemp -d /var/tmp/go-erp-source-modes.XXXXXXXX)
trap 'rm -rf -- "$go_modes_stage"' EXIT
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_modes_release/deployment/repair_go_erp_shared_workspace_modes_root.py" \
  -o "$go_modes_stage/repair.py"
printf '%s  %s\n' c3f7c95c1844869fb92a8adc180b2d6b0d653e44bcd3dfba4b654f73f2d6af38 "$go_modes_stage/repair.py" | sha256sum --check --status
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  'https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/29b245a5634e9fda747138cb0c0dbb103f1bc76a/deployment/run_go_erp_shared_workspace_root.sh' \
  -o "$go_modes_stage/resume.sh"
printf '%s  %s\n' \
  2c99bfa48e10ab9f222b61ca38e75103f5491c904ed9683814219fb0c36a65f8 \
  "$go_modes_stage/resume.sh" | sha256sum --check --status
python3 -I -B "$go_modes_stage/repair.py"
bash "$go_modes_stage/resume.sh" 29b245a5634e9fda747138cb0c0dbb103f1bc76a --complete
