#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
cleanup_revision=$1
test "$2" = --apply
[[ "$cleanup_revision" =~ ^[a-f0-9]{40}$ ]]
cleanup_inputs=$(mktemp -d /var/tmp/fasakhansta-verified-cleanup.XXXXXXXX)
trap 'rm -rf -- "$cleanup_inputs"' EXIT
chmod 0700 "$cleanup_inputs"
for cleanup_file in \
  clean_verified_server_files_root.py \
  prune_previous_full_backup_root.py \
  clean_archived_point_backups_root.py \
  clean_closed_upgrade_checkouts_root.py \
  go_erp_release_retention_root.py
do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$cleanup_revision/deployment/$cleanup_file" \
    -o "$cleanup_inputs/$cleanup_file"
done
printf '%s  %s\n' \
  'd560343789aa54c5a069d9de636457ae4795da6798d671fcc00047138b633898' "$cleanup_inputs/clean_verified_server_files_root.py" \
  'f5c36a7c76dce6ad8327b66aa6500a25f937277170749dc20dfaed53255af394' "$cleanup_inputs/prune_previous_full_backup_root.py" \
  '084fab0839178b02f5a53ec069b23e481395d10ac5f410e014c5f8b9d26e2e90' "$cleanup_inputs/clean_archived_point_backups_root.py" \
  '5e7ad1bc1bc8cad15b0240b1e9b19a9d7ec9e04a266cdb31ac3960cbfa65915a' "$cleanup_inputs/clean_closed_upgrade_checkouts_root.py" \
  'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293' "$cleanup_inputs/go_erp_release_retention_root.py" \
  | sha256sum --check --status
/usr/bin/python3 "$cleanup_inputs/clean_verified_server_files_root.py" --apply
