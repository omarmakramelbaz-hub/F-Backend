#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
order_items_root=/home/fasakha/public_html
order_items_php=/opt/cpanel/ea-php82/root/usr/bin/php
order_items_revision="${1:?Full reviewed release SHA required}"
[[ "$order_items_revision" =~ ^[a-f0-9]{40}$ && -x "$order_items_php" ]] || exit 1
shift
order_items_flags=()
for order_items_flag in "$@"; do
  case "$order_items_flag" in
    --check|--repair-permissions)
      for order_items_existing_flag in "${order_items_flags[@]}"; do
        [[ "$order_items_existing_flag" != "$order_items_flag" ]] || { echo DUPLICATE_OPTION; exit 1; }
      done
      order_items_flags+=("$order_items_flag")
      ;;
    *) echo INVALID_OPTION; exit 1 ;;
  esac
done

order_items_tmp="$(mktemp -d /tmp/app-order-items.XXXXXX)"
trap 'rm -rf -- "$order_items_tmp"' EXIT
chmod 0755 "$order_items_tmp"
order_items_helper=install_app_order_items.py
runuser -u fasakha -- git -C "$order_items_root" show \
  "$order_items_revision:deployment/$order_items_helper" > "$order_items_tmp/$order_items_helper"
order_items_blob="$(runuser -u fasakha -- git -C "$order_items_root" rev-parse \
  "$order_items_revision:deployment/$order_items_helper")"
[[ "$(git hash-object --no-filters "$order_items_tmp/$order_items_helper")" == "$order_items_blob" ]] || exit 1
chmod 0644 "$order_items_tmp/$order_items_helper"
python3 "$order_items_tmp/$order_items_helper" --root "$order_items_root" \
  --release "$order_items_revision" --php "$order_items_php" "${order_items_flags[@]}"
