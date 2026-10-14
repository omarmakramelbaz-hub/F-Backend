#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
meta_hub_root=/home/fasakha/public_html
meta_hub_php=/opt/cpanel/ea-php82/root/usr/bin/php
meta_hub_revision="${1:?Full release SHA required}"
[[ "$meta_hub_revision" =~ ^[a-f0-9]{40}$ && -x "$meta_hub_php" ]] || exit 1
meta_hub_tmp="$(mktemp -d /tmp/meta-message-hub.XXXXXX)"
trap 'rm -rf -- "$meta_hub_tmp"' EXIT
chmod 0755 "$meta_hub_tmp"
meta_hub_file=update_meta_message_hub.py
runuser -u fasakha -- git -C "$meta_hub_root" show "$meta_hub_revision:deployment/$meta_hub_file" > "$meta_hub_tmp/$meta_hub_file"
meta_hub_blob="$(runuser -u fasakha -- git -C "$meta_hub_root" rev-parse "$meta_hub_revision:deployment/$meta_hub_file")"
[[ "$(git hash-object --no-filters "$meta_hub_tmp/$meta_hub_file")" == "$meta_hub_blob" ]] || exit 1
chmod 0644 "$meta_hub_tmp/$meta_hub_file"
python3 "$meta_hub_tmp/$meta_hub_file" --root "$meta_hub_root" --release "$meta_hub_revision" --php "$meta_hub_php"
