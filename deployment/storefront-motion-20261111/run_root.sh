#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -d /home/fasakha/public_html
test "$#" -eq 3
motion_revision=$1
motion_installer_sha=$2
motion_manifest_sha=$3
[[ "$motion_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$motion_installer_sha" =~ ^[a-f0-9]{64}$ ]]
[[ "$motion_manifest_sha" =~ ^[a-f0-9]{64}$ ]]

motion_inputs=$(mktemp -d /var/tmp/fasakhansta-storefront-motion-inputs.XXXXXXXX)
trap 'rm -f -- "$motion_inputs/install.py"; rmdir -- "$motion_inputs"' EXIT
chmod 0700 "$motion_inputs"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$motion_revision/deployment/storefront-motion-20261111/install.py" \
  -o "$motion_inputs/install.py"
printf '%s  %s\n' "$motion_installer_sha" "$motion_inputs/install.py" | sha256sum --check --status
/usr/bin/python3 "$motion_inputs/install.py" --revision "$motion_revision" --manifest-sha256 "$motion_manifest_sha"
