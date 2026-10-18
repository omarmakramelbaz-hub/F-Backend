#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$(hostname -s)" == srv1836524 ]] || { echo HOST_OR_USER_REJECTED; exit 1; }
layout_root=/home/fasakha/public_html
layout_php=/opt/cpanel/ea-php82/root/usr/bin/php
layout_revision="${1:?Full release SHA required}"
[[ "$#" == 1 && "$layout_revision" =~ ^[a-f0-9]{40}$ && -x "$layout_php" ]] || exit 1
layout_tmp="$(mktemp -d /tmp/meta-inbox-layout.XXXXXX)"
trap 'rm -rf -- "$layout_tmp"' EXIT
chmod 0755 "$layout_tmp"
for layout_path in deployment/apply_meta_inbox_layout.py resources/views/admin/meta/ai_controls.blade.php public/css/dashboard-meta-inbox-expanded.css; do
    layout_name="${layout_path##*/}"
    runuser -u fasakha -- git -C "$layout_root" show "$layout_revision:$layout_path" > "$layout_tmp/$layout_name"
    layout_blob="$(runuser -u fasakha -- git -C "$layout_root" rev-parse "$layout_revision:$layout_path")"
    [[ "$(git hash-object --no-filters "$layout_tmp/$layout_name")" == "$layout_blob" ]] || exit 1
    chmod 0644 "$layout_tmp/$layout_name"
done
layout_reference_sha="$(sha256sum "$layout_tmp/ai_controls.blade.php")"
layout_reference_sha="${layout_reference_sha%% *}"
layout_css_sha="$(sha256sum "$layout_tmp/dashboard-meta-inbox-expanded.css")"
layout_css_sha="${layout_css_sha%% *}"
runuser -u fasakha -- python3 "$layout_tmp/apply_meta_inbox_layout.py" \
    --root "$layout_root" --php "$layout_php" \
    --reference "$layout_tmp/ai_controls.blade.php" --css "$layout_tmp/dashboard-meta-inbox-expanded.css" \
    --reference-sha "$layout_reference_sha" --css-sha "$layout_css_sha"
