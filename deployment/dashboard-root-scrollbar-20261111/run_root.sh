#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -d /home/fasakha/public_html
test "$#" -eq 3
dashboard_scrollbar_revision=$1
dashboard_scrollbar_installer_sha=$2
dashboard_scrollbar_manifest_sha=$3
[[ "$dashboard_scrollbar_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$dashboard_scrollbar_installer_sha" =~ ^[a-f0-9]{64}$ ]]
[[ "$dashboard_scrollbar_manifest_sha" =~ ^[a-f0-9]{64}$ ]]

dashboard_scrollbar_inputs=$(mktemp -d /var/tmp/fasakhansta-dashboard-root-scrollbar-inputs.XXXXXXXX)
trap 'rm -f -- "$dashboard_scrollbar_inputs/install.py"; rmdir -- "$dashboard_scrollbar_inputs"' EXIT
chmod 0700 "$dashboard_scrollbar_inputs"
curl --fail --silent --show-error --proto '=https' \
  --connect-timeout 15 --max-time 60 --max-filesize 131072 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$dashboard_scrollbar_revision/deployment/dashboard-root-scrollbar-20261111/install.py" \
  -o "$dashboard_scrollbar_inputs/install.py"
printf '%s  %s\n' "$dashboard_scrollbar_installer_sha" "$dashboard_scrollbar_inputs/install.py" | sha256sum --check --status
/usr/bin/python3 "$dashboard_scrollbar_inputs/install.py" --revision "$dashboard_scrollbar_revision" --manifest-sha256 "$dashboard_scrollbar_manifest_sha"
