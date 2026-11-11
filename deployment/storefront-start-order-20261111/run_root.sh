#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test -d /home/fasakha/public_html
test "$#" -eq 3
start_order_revision=$1
start_order_installer_sha=$2
start_order_manifest_sha=$3
[[ "$start_order_revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$start_order_installer_sha" =~ ^[a-f0-9]{64}$ ]]
[[ "$start_order_manifest_sha" =~ ^[a-f0-9]{64}$ ]]

start_order_inputs=$(mktemp -d /var/tmp/fasakhansta-storefront-start-order-inputs.XXXXXXXX)
trap 'rm -f -- "$start_order_inputs/install.py"; rmdir -- "$start_order_inputs"' EXIT
chmod 0700 "$start_order_inputs"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$start_order_revision/deployment/storefront-start-order-20261111/install.py" \
  -o "$start_order_inputs/install.py"
printf '%s  %s\n' "$start_order_installer_sha" "$start_order_inputs/install.py" | sha256sum --check --status
/usr/bin/python3 "$start_order_inputs/install.py" --revision "$start_order_revision" --manifest-sha256 "$start_order_manifest_sha"
