#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$(hostname -s)" == srv1836524 ]] || { echo HOST_OR_USER_REJECTED; exit 1; }
comments_root=/home/fasakha/public_html
comments_php=/opt/cpanel/ea-php82/root/usr/bin/php
comments_revision="${1:?Full release SHA required}"
[[ "$#" == 1 && "$comments_revision" =~ ^[a-f0-9]{40}$ && -x "$comments_php" ]] || exit 1
[[ "$(runuser -u fasakha -- git -C "$comments_root" rev-parse FETCH_HEAD)" == "$comments_revision" ]] || { echo FETCH_HEAD_REJECTED; exit 1; }
comments_tmp="$(mktemp -d /tmp/social-comments-original.XXXXXX)"
trap 'rm -rf -- "$comments_tmp"' EXIT
chmod 0755 "$comments_tmp"
for comments_path in deployment/apply_social_comments_original.py resources/views/admin/meta/comments.blade.php public/js/dashboard-meta-comments.js public/css/dashboard-meta-comments-original.css; do
    comments_name="${comments_path##*/}"
    runuser -u fasakha -- git -C "$comments_root" show "$comments_revision:$comments_path" > "$comments_tmp/$comments_name"
    comments_blob="$(runuser -u fasakha -- git -C "$comments_root" rev-parse "$comments_revision:$comments_path")"
    [[ "$(git hash-object --no-filters "$comments_tmp/$comments_name")" == "$comments_blob" ]] || exit 1
    chmod 0644 "$comments_tmp/$comments_name"
done
comments_reference_sha="$(sha256sum "$comments_tmp/comments.blade.php")"
comments_reference_sha="${comments_reference_sha%% *}"
comments_css_sha="$(sha256sum "$comments_tmp/dashboard-meta-comments-original.css")"
comments_css_sha="${comments_css_sha%% *}"
comments_js_sha="$(sha256sum "$comments_tmp/dashboard-meta-comments.js")"
comments_js_sha="${comments_js_sha%% *}"
runuser -u fasakha -- python3 "$comments_tmp/apply_social_comments_original.py" \
    --root "$comments_root" --php "$comments_php" \
    --reference "$comments_tmp/comments.blade.php" --css "$comments_tmp/dashboard-meta-comments-original.css" \
    --javascript "$comments_tmp/dashboard-meta-comments.js" --javascript-sha "$comments_js_sha" \
    --reference-sha "$comments_reference_sha" --css-sha "$comments_css_sha"
