#!/usr/bin/env bash
# Show the filtered monthly employee net total and retain branch wallet entry.
set -euo pipefail
umask 077

net_revision=${1:?Full pinned revision required}
[[ $# = 1 && "$net_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) = 0 && $(hostname -s) = srv1836524 ]]
net_root=/home/fasakha/public_html
net_php=/opt/cpanel/ea-php82/root/usr/bin/php
net_parent=/home/fasakha/employee-net-total-backups
[[ -x "$net_php" && -d "$net_root" && ! -L "$net_root" ]]
[[ $(realpath -- "$net_root") = "$net_root" ]]
[[ $(stat -c '%u' -- "$net_root") = "$(id -u fasakha)" ]]
[[ ! -e "$net_root/storage/framework/down" ]]
command -v python3 >/dev/null
command -v flock >/dev/null
if [[ ! -e "$net_parent" ]]; then install -d -o fasakha -g fasakha -m 0700 "$net_parent"; fi
[[ -d "$net_parent" && ! -L "$net_parent" && $(realpath -- "$net_parent") = "$net_parent" ]]
[[ $(stat -c '%u:%a' -- "$net_parent") = "$(id -u fasakha):700" ]]
[[ ! -L "$net_parent/install.lock" ]]
[[ ! -e "$net_parent/install.lock" || -f "$net_parent/install.lock" ]]
exec 9>"$net_parent/install.lock"
flock -n 9 || { printf '%s\n' 'ANOTHER_EMPLOYEE_NET_TOTAL_INSTALL_IS_RUNNING'; exit 1; }
# Serialize the composed wallet step with its independent installer as well.
net_wallet_parent=/home/fasakha/employee-wallet-branch-backups
if [[ ! -e "$net_wallet_parent" ]]; then install -d -o fasakha -g fasakha -m 0700 "$net_wallet_parent"; fi
[[ -d "$net_wallet_parent" && ! -L "$net_wallet_parent" && $(realpath -- "$net_wallet_parent") = "$net_wallet_parent" ]]
[[ $(stat -c '%u:%a' -- "$net_wallet_parent") = "$(id -u fasakha):700" ]]
[[ ! -L "$net_wallet_parent/install.lock" ]]
[[ ! -e "$net_wallet_parent/install.lock" || -f "$net_wallet_parent/install.lock" ]]
exec 8>"$net_wallet_parent/install.lock"
flock -n 8 || { printf '%s\n' 'ANOTHER_EMPLOYEE_WALLET_INSTALL_IS_RUNNING'; exit 1; }

net_tmp=$(mktemp -d /tmp/employee-net-total.XXXXXXXX)
chmod 0755 "$net_tmp"
net_backup=
net_cleanup() {
  local net_exit=$?
  trap - EXIT
  if [[ -n "$net_backup" ]]; then printf 'BACKUP_DIRECTORY=%s\n' "$net_backup"; fi
  rm -rf -- "$net_tmp"
  exit "$net_exit"
}
trap net_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

for net_source in deployment/employee_net_total_patch.py deployment/employee_wallet_branch_patch.py deployment/launch_reset/bonus_owner_patch.py; do
  net_name=${net_source##*/}
  net_blob=$(runuser -u fasakha -- git -C "$net_root" rev-parse "$net_revision:$net_source")
  [[ "$net_blob" =~ ^[0-9a-f]{40}$ ]]
  runuser -u fasakha -- git -C "$net_root" show "$net_revision:$net_source" > "$net_tmp/$net_name"
  [[ $(git hash-object --no-filters "$net_tmp/$net_name") = "$net_blob" ]]
  chmod 0644 "$net_tmp/$net_name"
done

for net_patch in employee_wallet_branch_patch.py employee_net_total_patch.py; do
  runuser -u fasakha -- python3 "$net_tmp/$net_patch" --root "$net_root" --php "$net_php" --check
done
net_backup=$(mktemp -d "$net_parent/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
chown fasakha:fasakha "$net_backup"
runuser -u fasakha -- python3 "$net_tmp/employee_wallet_branch_patch.py" \
  --root "$net_root" --php "$net_php" --backup "$net_backup/wallet-source"
runuser -u fasakha -- python3 "$net_tmp/employee_net_total_patch.py" \
  --root "$net_root" --php "$net_php" --backup "$net_backup/net-source"
printf '%s\n' 'EMPLOYEE_NET_TOTAL_INSTALLED'
