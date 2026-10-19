#!/usr/bin/env bash
# Restore branch-scoped employee wallet editing through the existing wallet endpoint.
set -euo pipefail
umask 077

wallet_revision=${1:?Full pinned revision required}
[[ $# = 1 && "$wallet_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) = 0 && $(hostname -s) = srv1836524 ]]
wallet_root=/home/fasakha/public_html
wallet_php=/opt/cpanel/ea-php82/root/usr/bin/php
wallet_parent=/home/fasakha/employee-wallet-branch-backups
[[ -x "$wallet_php" && -d "$wallet_root" && ! -L "$wallet_root" ]]
[[ $(realpath -- "$wallet_root") = "$wallet_root" ]]
[[ $(stat -c '%u' -- "$wallet_root") = "$(id -u fasakha)" ]]
[[ ! -e "$wallet_root/storage/framework/down" ]]
command -v python3 >/dev/null
command -v flock >/dev/null

if [[ ! -e "$wallet_parent" ]]; then install -d -o fasakha -g fasakha -m 0700 "$wallet_parent"; fi
[[ -d "$wallet_parent" && ! -L "$wallet_parent" && $(realpath -- "$wallet_parent") = "$wallet_parent" ]]
[[ $(stat -c '%u:%a' -- "$wallet_parent") = "$(id -u fasakha):700" ]]
[[ ! -L "$wallet_parent/install.lock" ]]
[[ ! -e "$wallet_parent/install.lock" || -f "$wallet_parent/install.lock" ]]
exec 9>"$wallet_parent/install.lock"
flock -n 9 || { printf '%s\n' 'ANOTHER_EMPLOYEE_WALLET_INSTALL_IS_RUNNING'; exit 1; }

wallet_tmp=$(mktemp -d /tmp/employee-wallet-branch.XXXXXXXX)
chmod 0755 "$wallet_tmp"
wallet_backup=
wallet_cleanup() {
  local wallet_exit=$?
  trap - EXIT
  if [[ -n "$wallet_backup" ]]; then printf 'BACKUP_DIRECTORY=%s\n' "$wallet_backup"; fi
  rm -rf -- "$wallet_tmp"
  exit "$wallet_exit"
}
trap wallet_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

for wallet_source in deployment/employee_wallet_branch_patch.py deployment/launch_reset/bonus_owner_patch.py; do
  wallet_name=${wallet_source##*/}
  wallet_blob=$(runuser -u fasakha -- git -C "$wallet_root" rev-parse "$wallet_revision:$wallet_source")
  [[ "$wallet_blob" =~ ^[0-9a-f]{40}$ ]]
  runuser -u fasakha -- git -C "$wallet_root" show "$wallet_revision:$wallet_source" > "$wallet_tmp/$wallet_name"
  [[ $(git hash-object --no-filters "$wallet_tmp/$wallet_name") = "$wallet_blob" ]]
  chmod 0644 "$wallet_tmp/$wallet_name"
done

runuser -u fasakha -- python3 "$wallet_tmp/employee_wallet_branch_patch.py" \
  --root "$wallet_root" --php "$wallet_php" --check
wallet_backup=$(mktemp -d "$wallet_parent/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
chown fasakha:fasakha "$wallet_backup"
runuser -u fasakha -- python3 "$wallet_tmp/employee_wallet_branch_patch.py" \
  --root "$wallet_root" --backup "$wallet_backup/source" --php "$wallet_php"
printf '%s\n' 'EMPLOYEE_BRANCH_WALLET_EDIT_INSTALLED'
