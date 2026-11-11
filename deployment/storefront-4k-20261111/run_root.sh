#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -d /home/fasakha/public_html
test "$#" -eq 3
hero4k_revision=$1
hero4k_installer_sha=$2
hero4k_manifest_sha=$3
[[ "$hero4k_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$hero4k_installer_sha" =~ ^[a-f0-9]{64}$ ]]
[[ "$hero4k_manifest_sha" =~ ^[a-f0-9]{64}$ ]]

hero4k_inputs=$(mktemp -d /var/tmp/fasakhansta-storefront-4k-inputs.XXXXXXXX)
trap 'rm -f -- "$hero4k_inputs/install.py"; rmdir -- "$hero4k_inputs"' EXIT
chmod 0700 "$hero4k_inputs"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$hero4k_revision/deployment/storefront-4k-20261111/install.py" \
  -o "$hero4k_inputs/install.py"
printf '%s  %s\n' "$hero4k_installer_sha" "$hero4k_inputs/install.py" | sha256sum --check --status
/usr/bin/python3 "$hero4k_inputs/install.py" --revision "$hero4k_revision" --manifest-sha256 "$hero4k_manifest_sha"
