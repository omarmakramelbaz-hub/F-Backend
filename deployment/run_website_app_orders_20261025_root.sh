#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
website_fix_revision=$1
test "$2" = --apply
[[ "$website_fix_revision" =~ ^[a-f0-9]{40}$ ]]

website_fix_inputs=$(mktemp -d /var/tmp/website-app-orders-inputs.XXXXXXXX)
trap 'rm -rf -- "$website_fix_inputs"' EXIT
chmod 0700 "$website_fix_inputs"

for website_fix_file in \
  apply_website_app_orders_20261025_root.py \
  go_erp_release_retention_root.py
do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$website_fix_revision/deployment/$website_fix_file" \
    -o "$website_fix_inputs/$website_fix_file"
done

printf '%s  %s\n' \
  'bf857c9fd14ec4fd16c5b0a6250b1223983925f61541c7ee5c2d3d8d41109e76' "$website_fix_inputs/apply_website_app_orders_20261025_root.py" \
  'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293' "$website_fix_inputs/go_erp_release_retention_root.py" \
  | sha256sum --check --status

/usr/bin/python3 "$website_fix_inputs/apply_website_app_orders_20261025_root.py" --apply
