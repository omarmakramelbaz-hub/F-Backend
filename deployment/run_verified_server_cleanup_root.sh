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
  go_erp_release_retention_root.py
do
  curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$cleanup_revision/deployment/$cleanup_file" \
    -o "$cleanup_inputs/$cleanup_file"
done
printf '%s  %s\n' \
  '4d6393aa47a8c5bf64d260b8e5850597c170864b5de4efe2559ec329e1567423' "$cleanup_inputs/clean_verified_server_files_root.py" \
  'f5c36a7c76dce6ad8327b66aa6500a25f937277170749dc20dfaed53255af394' "$cleanup_inputs/prune_previous_full_backup_root.py" \
  'dbd55cd5ce59af2018ffd27443a92632b25d82b9e811410d45d7da82530ba9a5' "$cleanup_inputs/clean_archived_point_backups_root.py" \
  'c238946e36e95b8037c0a678f340ce6c9121de9f5259e1a11cbaac7fa0382293' "$cleanup_inputs/go_erp_release_retention_root.py" \
  | sha256sum --check --status
/usr/bin/python3 "$cleanup_inputs/clean_verified_server_files_root.py" --apply
