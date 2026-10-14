#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
meta_oauth_root=/home/fasakha/public_html
meta_oauth_php=/opt/cpanel/ea-php82/root/usr/bin/php
meta_oauth_release="${1:?Full release SHA required}"
[[ "$meta_oauth_release" =~ ^[a-f0-9]{40}$ && -x "$meta_oauth_php" ]] || exit 1
meta_oauth_tmp="$(mktemp -d /tmp/meta-oauth-install.XXXXXX)"
trap 'rm -rf -- "$meta_oauth_tmp"' EXIT
chmod 0755 "$meta_oauth_tmp"
meta_oauth_file=update_meta_oauth.py
runuser -u fasakha -- git -C "$meta_oauth_root" show \
    "$meta_oauth_release:deployment/$meta_oauth_file" > "$meta_oauth_tmp/$meta_oauth_file"
meta_oauth_blob="$(runuser -u fasakha -- git -C "$meta_oauth_root" rev-parse \
    "$meta_oauth_release:deployment/$meta_oauth_file")"
[[ "$(git hash-object --no-filters "$meta_oauth_tmp/$meta_oauth_file")" == "$meta_oauth_blob" ]] || exit 1
chmod 0644 "$meta_oauth_tmp/$meta_oauth_file"
runuser -u fasakha -- python3 "$meta_oauth_tmp/$meta_oauth_file" \
    --root "$meta_oauth_root" --release "$meta_oauth_release" --php "$meta_oauth_php"
