#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
meta_comments_root=/home/fasakha/public_html
meta_comments_php=/opt/cpanel/ea-php82/root/usr/bin/php
meta_comments_release="${1:?Full release SHA required}"
[[ "$meta_comments_release" =~ ^[a-f0-9]{40}$ && -x "$meta_comments_php" ]] || exit 1
meta_comments_tmp="$(mktemp -d /tmp/meta-comments-install.XXXXXX)"
trap 'rm -rf -- "$meta_comments_tmp"' EXIT
chmod 0755 "$meta_comments_tmp"
for meta_comments_file in update_meta_comments.py meta_comments_manifest.json; do
    runuser -u fasakha -- git -C "$meta_comments_root" show \
        "$meta_comments_release:deployment/$meta_comments_file" > "$meta_comments_tmp/$meta_comments_file"
    meta_comments_blob="$(runuser -u fasakha -- git -C "$meta_comments_root" rev-parse \
        "$meta_comments_release:deployment/$meta_comments_file")"
    [[ "$(git hash-object --no-filters "$meta_comments_tmp/$meta_comments_file")" == "$meta_comments_blob" ]] || exit 1
    chmod 0644 "$meta_comments_tmp/$meta_comments_file"
done
python3 "$meta_comments_tmp/update_meta_comments.py" \
    --root "$meta_comments_root" --release "$meta_comments_release" \
    --php "$meta_comments_php" --manifest "$meta_comments_tmp/meta_comments_manifest.json"
# Capability failures are informational: keep the installed tab available for reconnection.
if ! runuser -u fasakha -- "$meta_comments_php" -d display_errors=0 -d log_errors=0 \
    "$meta_comments_root/deployment/meta_comments_diagnostic.php" "$meta_comments_root"; then
    echo META_COMMENTS_CAPABILITY_CHECK_UNAVAILABLE
fi
