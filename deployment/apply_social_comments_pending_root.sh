#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$(hostname -s)" == srv1836524 ]] || exit 1
comments_root=/home/fasakha/public_html
comments_php=/opt/cpanel/ea-php82/root/usr/bin/php
comments_revision="${1:?Full release SHA required}"
[[ "$#" == 1 && "$comments_revision" =~ ^[a-f0-9]{40}$ && -x "$comments_php" ]] || exit 1
[[ "$(runuser -u fasakha -- git -C "$comments_root" rev-parse FETCH_HEAD)" == "$comments_revision" ]] || exit 1
comments_tmp="$(mktemp -d /tmp/social-comments-pending.XXXXXX)"
trap 'rm -rf -- "$comments_tmp"' EXIT
chmod 0755 "$comments_tmp"
for comments_path in deployment/apply_social_comments_pending.py public/css/dashboard-meta-comments-pending.css app/Services/Dashboard/MetaCommentsService.php app/Http/Controllers/Dashboard/MetaCommentsController.php public/js/dashboard-meta-comments.js resources/views/admin/meta/comments.blade.php; do
    comments_name="${comments_path##*/}"
    runuser -u fasakha -- git -C "$comments_root" show "$comments_revision:$comments_path" > "$comments_tmp/$comments_name"
    comments_blob="$(runuser -u fasakha -- git -C "$comments_root" rev-parse "$comments_revision:$comments_path")"
    [[ "$(git hash-object --no-filters "$comments_tmp/$comments_name")" == "$comments_blob" ]] || exit 1
    chmod 0644 "$comments_tmp/$comments_name"
done
comments_hashes="$(python3 - "$comments_tmp" <<'PY'
import hashlib,json,sys
from pathlib import Path
root=Path(sys.argv[1])
print(json.dumps([hashlib.sha256((root/name).read_bytes()).hexdigest() for name in ['dashboard-meta-comments-pending.css','MetaCommentsService.php','MetaCommentsController.php','dashboard-meta-comments.js','comments.blade.php']]))
PY
)"
runuser -u fasakha -- python3 "$comments_tmp/apply_social_comments_pending.py" \
    --root "$comments_root" --php "$comments_php" --reference "$comments_tmp/comments.blade.php" \
    --javascript "$comments_tmp/dashboard-meta-comments.js" --css "$comments_tmp/dashboard-meta-comments-pending.css" \
    --service "$comments_tmp/MetaCommentsService.php" --controller "$comments_tmp/MetaCommentsController.php" --hashes "$comments_hashes"
