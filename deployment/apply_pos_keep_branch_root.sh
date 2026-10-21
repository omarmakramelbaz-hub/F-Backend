#!/usr/bin/env bash
set -euo pipefail
umask 077

pos_branch_revision=${1:?Full revision required}
[[ "$pos_branch_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# == 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]]
pos_branch_root=/home/fasakha/public_html
[[ -d "$pos_branch_root" && ! -L "$pos_branch_root" ]]
[[ $(realpath -- "$pos_branch_root") == "$pos_branch_root" ]]
[[ $(stat -c '%u' -- "$pos_branch_root") == $(id -u fasakha) ]]
command -v python3 >/dev/null
pos_branch_script=deployment/apply_pos_keep_branch.py
pos_branch_blob=$(runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 \
  git -C "$pos_branch_root" rev-parse "$pos_branch_revision:$pos_branch_script")
[[ "$pos_branch_blob" =~ ^[0-9a-f]{40}$ ]]
pos_branch_tmp=$(mktemp -d /tmp/pos-keep-branch.XXXXXXXX)
trap 'rm -rf -- "$pos_branch_tmp"' EXIT
chmod 0755 "$pos_branch_tmp"
runuser -u fasakha -- env GIT_NO_REPLACE_OBJECTS=1 \
  git -C "$pos_branch_root" show "$pos_branch_revision:$pos_branch_script" > "$pos_branch_tmp/apply.py"
[[ $(git hash-object --no-filters "$pos_branch_tmp/apply.py") == "$pos_branch_blob" ]]
chmod 0644 "$pos_branch_tmp/apply.py"
runuser -u fasakha -- python3 "$pos_branch_tmp/apply.py" \
  --root "$pos_branch_root" --revision "$pos_branch_revision" --check
if [[ $# == 2 ]]; then
  printf '%s\n' 'POS_KEEP_BRANCH_CHECK_COMPLETE'
  exit 0
fi
runuser -u fasakha -- python3 "$pos_branch_tmp/apply.py" \
  --root "$pos_branch_root" --revision "$pos_branch_revision"
printf '%s\n' 'POS_KEEP_BRANCH_RELEASE_COMPLETE'
