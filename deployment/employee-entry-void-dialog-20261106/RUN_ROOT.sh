#!/usr/bin/env bash
set -euo pipefail
umask 077
expense_revision=${1:?Full revision is required}
[[ "$expense_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run as root.'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'UNEXPECTED_HOST'; exit 1; }
expense_root=/home/fasakha/public_html
[[ -d "$expense_root" && ! -L "$expense_root" ]]
[[ $(realpath -- "$expense_root") == "$expense_root" ]]
[[ $(stat -c '%u' -- "$expense_root") == $(id -u fasakha) ]]
command -v python3 >/dev/null
expense_tmp=$(mktemp -d /tmp/employee-entry-void-dialog.XXXXXXXX)
trap 'rm -rf -- "$expense_tmp"' EXIT
chmod 0755 "$expense_tmp"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$expense_revision/deployment/employee-entry-void-dialog-20261106/apply.py" \
  -o "$expense_tmp/apply.py"
printf '%s  %s\n' 'c944b234d9509479a3f891bca6c490d5e15991a96a53b4f015657d50221ebbdf' "$expense_tmp/apply.py" | sha256sum --check --status
chmod 0644 "$expense_tmp/apply.py"
expense_options=()
if [[ $# == 2 ]]; then expense_options+=(--check); fi
runuser -u fasakha -- python3 -I -B "$expense_tmp/apply.py" \
  --root "$expense_root" --revision "$expense_revision" "${expense_options[@]}"
