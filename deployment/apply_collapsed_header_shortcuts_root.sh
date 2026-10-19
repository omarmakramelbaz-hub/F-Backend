#!/usr/bin/env bash
set -euo pipefail
umask 077

sidebar_revision=${1:?Full revision is required}
[[ "$sidebar_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
shift
sidebar_options=()
if [[ ${1:-} = --inspect ]]; then
  sidebar_options+=(--inspect)
  shift
fi
[[ $# = 0 ]] || { printf '%s\n' 'Usage: bash apply_collapsed_header_shortcuts_root.sh FULL_SHA [--inspect]'; exit 1; }
[[ $(id -u) = 0 ]] || { printf '%s\n' 'ROOT_REQUIRED'; exit 1; }
[[ $(hostname -s) = srv1836524 ]] || { printf '%s\n' 'EXPECTED_SERVER_REQUIRED'; exit 1; }
command -v python3 >/dev/null
command -v runuser >/dev/null

sidebar_root=/home/fasakha/public_html
sidebar_source=deployment/apply_collapsed_header_shortcuts.py
python3 - "$sidebar_root" <<'SIDEBAR_ROOT_CHECK'
import os
from pathlib import Path
import pwd
import stat
import sys
root = Path(sys.argv[1])
for path in (root, *root.parents):
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        raise SystemExit('CANONICAL_APPLICATION_DIRECTORY_REQUIRED')
if root.resolve() != root or root.stat().st_uid != pwd.getpwnam('fasakha').pw_uid:
    raise SystemExit('APPLICATION_ROOT_OWNER_NEEDS_REVIEW')
SIDEBAR_ROOT_CHECK
sidebar_tmp=$(mktemp -d /tmp/collapsed-header-shortcuts.XXXXXXXX)
trap 'rm -rf -- "$sidebar_tmp"' EXIT
chmod 0755 "$sidebar_tmp"

runuser -u fasakha -- git -C "$sidebar_root" cat-file -e "$sidebar_revision^{commit}"
[[ $(runuser -u fasakha -- git -C "$sidebar_root" rev-parse "$sidebar_revision^{commit}") = "$sidebar_revision" ]] || exit 1
sidebar_blob=$(runuser -u fasakha -- git -C "$sidebar_root" rev-parse "$sidebar_revision:$sidebar_source")
[[ "$sidebar_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$sidebar_root" show "$sidebar_revision:$sidebar_source" > "$sidebar_tmp/apply.py"
[[ $(git hash-object --no-filters "$sidebar_tmp/apply.py") = "$sidebar_blob" ]] || {
  printf '%s\n' 'COLLAPSED_SHORTCUTS_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$sidebar_tmp/apply.py"
runuser -u fasakha -- python3 "$sidebar_tmp/apply.py" \
  --root "$sidebar_root" --revision "$sidebar_revision" "${sidebar_options[@]}"
