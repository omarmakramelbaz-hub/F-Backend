#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
alert_menu_root=/home/fasakha/public_html
alert_menu_php=/opt/cpanel/ea-php82/root/usr/bin/php
alert_menu_revision="${1:?Full reviewed release SHA required}"
[[ "$alert_menu_revision" =~ ^[a-f0-9]{40}$ && -x "$alert_menu_php" ]] || exit 1
shift
alert_menu_flags=()
for alert_menu_flag in "$@"; do
  case "$alert_menu_flag" in
    --check|--repair-permissions)
      for alert_menu_existing_flag in "${alert_menu_flags[@]}"; do
        [[ "$alert_menu_existing_flag" != "$alert_menu_flag" ]] || { echo DUPLICATE_OPTION; exit 1; }
      done
      alert_menu_flags+=("$alert_menu_flag")
      ;;
    *) echo INVALID_OPTION; exit 1 ;;
  esac
done

alert_menu_tmp="$(mktemp -d /tmp/order-alert-menu.XXXXXX)"
trap 'rm -rf -- "$alert_menu_tmp"' EXIT
chmod 0755 "$alert_menu_tmp"
alert_menu_helper=order_alert_menu_installer.py
runuser -u fasakha -- git -C "$alert_menu_root" show \
  "$alert_menu_revision:deployment/$alert_menu_helper" > "$alert_menu_tmp/$alert_menu_helper"
alert_menu_blob="$(runuser -u fasakha -- git -C "$alert_menu_root" rev-parse \
  "$alert_menu_revision:deployment/$alert_menu_helper")"
[[ "$(git hash-object --no-filters "$alert_menu_tmp/$alert_menu_helper")" == "$alert_menu_blob" ]] || exit 1
chmod 0644 "$alert_menu_tmp/$alert_menu_helper"
python3 "$alert_menu_tmp/$alert_menu_helper" --root "$alert_menu_root" \
  --release "$alert_menu_revision" --php "$alert_menu_php" "${alert_menu_flags[@]}"
