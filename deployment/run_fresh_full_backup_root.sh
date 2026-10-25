#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_backup_rev=$1
test "$2" = --complete
[[ "$go_backup_rev" =~ ^[a-f0-9]{40}$ ]]

go_backup_inputs=$(mktemp -d /var/tmp/fasakhansta-fresh-full-inputs.XXXXXXXX)
trap 'rm -rf -- "$go_backup_inputs"' EXIT
chmod 0700 "$go_backup_inputs"
mkdir -m 0700 "$go_backup_inputs/full_backup_20261025"

for go_backup_file in \
  create_fresh_full_backup_root.py \
  go_erp_release_retention_root.py \
  full_backup_20261025/create_all_apps_backup_root.py \
  full_backup_20261025/create_current_backup_and_prune_root.py \
  full_backup_20261025/backup_server_runtime.py
do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_backup_rev/deployment/$go_backup_file" \
    -o "$go_backup_inputs/$go_backup_file"
done

printf '%s  %s\n' \
  '75d53170b6dd642fc48a9ec4b96da136d20915678cc1fd4340c6258e5eee3bfc' "$go_backup_inputs/create_fresh_full_backup_root.py" \
  'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293' "$go_backup_inputs/go_erp_release_retention_root.py" \
  'c7f3c6a672f2c775164fc373ff4458de8d3765e59ca5bff0a3e6a1d28a3d50d7' "$go_backup_inputs/full_backup_20261025/create_all_apps_backup_root.py" \
  '5e984e95e079ee38f3a4a838160e555b446c3ba0a6898cdd26dd6360bf95059f' "$go_backup_inputs/full_backup_20261025/create_current_backup_and_prune_root.py" \
  'e6cf3bb40358fe461eb858d1ce34130c047b19089bf288cffaa5dbb87b1d4b9f' "$go_backup_inputs/full_backup_20261025/backup_server_runtime.py" \
  | sha256sum --check --status

if command -v ionice >/dev/null 2>&1; then
  ionice -c 3 nice -n 19 /usr/bin/python3 \
    "$go_backup_inputs/create_fresh_full_backup_root.py" --complete
else
  nice -n 19 /usr/bin/python3 \
    "$go_backup_inputs/create_fresh_full_backup_root.py" --complete
fi
