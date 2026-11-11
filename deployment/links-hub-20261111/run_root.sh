#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -d /home/fasakha/public_html
test "$#" -eq 3
links_revision=$1
links_installer_sha=$2
links_manifest_sha=$3
[[ "$links_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$links_installer_sha" =~ ^[a-f0-9]{64}$ ]]
[[ "$links_manifest_sha" =~ ^[a-f0-9]{64}$ ]]

links_inputs=$(mktemp -d /var/tmp/fasakhansta-links-hub-inputs.XXXXXXXX)
trap 'rm -f -- "$links_inputs/install.py"; rmdir -- "$links_inputs"' EXIT
chmod 0700 "$links_inputs"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$links_revision/deployment/links-hub-20261111/install.py" \
  -o "$links_inputs/install.py"
printf '%s  %s\n' "$links_installer_sha" "$links_inputs/install.py" | sha256sum --check --status
/usr/bin/python3 "$links_inputs/install.py" --revision "$links_revision" --manifest-sha256 "$links_manifest_sha"
