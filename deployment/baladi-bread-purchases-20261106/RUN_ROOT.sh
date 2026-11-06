#!/usr/bin/env bash
set -euo pipefail
umask 077
bread_revision=${1:?Full revision is required}
[[ "$bread_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run as root.'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'UNEXPECTED_HOST'; exit 1; }
bread_root=/home/fasakha/public_html
[[ -d "$bread_root" && ! -L "$bread_root" ]]
[[ $(realpath -- "$bread_root") == "$bread_root" ]]
[[ $(stat -c '%u' -- "$bread_root") == $(id -u fasakha) ]]
command -v python3 >/dev/null
bread_tmp=$(mktemp -d /tmp/baladi-bread-purchases.XXXXXXXX)
trap 'rm -rf -- "$bread_tmp"' EXIT
chmod 0755 "$bread_tmp"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$bread_revision/deployment/baladi-bread-purchases-20261106/apply.py" \
  -o "$bread_tmp/apply.py"
printf '%s  %s\n' 'e7d5303fc63178264e4403df0cf042d5f06ca2c50cdc4f5da124c8173fc71d65' "$bread_tmp/apply.py" | sha256sum --check --status
chmod 0644 "$bread_tmp/apply.py"
bread_options=()
if [[ $# == 2 ]]; then bread_options+=(--check); fi
runuser -u fasakha -- python3 -I -B "$bread_tmp/apply.py" \
  --root "$bread_root" --php /opt/cpanel/ea-php82/root/usr/bin/php --revision "$bread_revision" "${bread_options[@]}"
