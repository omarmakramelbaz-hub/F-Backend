#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
board_lifecycle_root=/home/fasakha/public_html
board_lifecycle_php=/opt/cpanel/ea-php82/root/usr/bin/php
board_lifecycle_revision="${1:?Full reviewed release SHA required}"
[[ "$board_lifecycle_revision" =~ ^[a-f0-9]{40}$ && -x "$board_lifecycle_php" ]] || exit 1
shift
board_lifecycle_flags=()
for board_lifecycle_flag in "$@"; do
  case "$board_lifecycle_flag" in
    --check|--repair-permissions)
      for board_lifecycle_existing_flag in "${board_lifecycle_flags[@]}"; do
        [[ "$board_lifecycle_existing_flag" != "$board_lifecycle_flag" ]] || { echo DUPLICATE_OPTION; exit 1; }
      done
      board_lifecycle_flags+=("$board_lifecycle_flag")
      ;;
    *) echo INVALID_OPTION; exit 1 ;;
  esac
done

board_lifecycle_tmp="$(mktemp -d /tmp/order-board-lifecycle.XXXXXX)"
trap 'rm -rf -- "$board_lifecycle_tmp"' EXIT
chmod 0755 "$board_lifecycle_tmp"
board_lifecycle_helper=order_board_lifecycle_installer.py
runuser -u fasakha -- git -C "$board_lifecycle_root" show \
  "$board_lifecycle_revision:deployment/$board_lifecycle_helper" > "$board_lifecycle_tmp/$board_lifecycle_helper"
board_lifecycle_blob="$(runuser -u fasakha -- git -C "$board_lifecycle_root" rev-parse \
  "$board_lifecycle_revision:deployment/$board_lifecycle_helper")"
[[ "$(git hash-object --no-filters "$board_lifecycle_tmp/$board_lifecycle_helper")" == "$board_lifecycle_blob" ]] || exit 1
chmod 0644 "$board_lifecycle_tmp/$board_lifecycle_helper"
python3 "$board_lifecycle_tmp/$board_lifecycle_helper" --root "$board_lifecycle_root" \
  --release "$board_lifecycle_revision" --php "$board_lifecycle_php" "${board_lifecycle_flags[@]}"
