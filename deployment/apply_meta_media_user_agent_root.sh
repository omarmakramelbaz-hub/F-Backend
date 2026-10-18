#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$(hostname -s)" == srv1836524 ]] || { echo HOST_OR_USER_REJECTED; exit 1; }
media_root=/home/fasakha/public_html
media_php=/opt/cpanel/ea-php82/root/usr/bin/php
media_revision="${1:?Full release SHA required}"
[[ "$#" == 1 && "$media_revision" =~ ^[a-f0-9]{40}$ && -x "$media_php" ]] || exit 1
media_tmp="$(mktemp -d /tmp/meta-media-agent.XXXXXX)"
trap 'rm -rf -- "$media_tmp"' EXIT
chmod 0755 "$media_tmp"
for media_path in deployment/apply_meta_media_user_agent.py deployment/meta_media_diagnostic.php app/Services/Dashboard/MetaMediaService.php; do
    media_name="${media_path##*/}"
    runuser -u fasakha -- git -C "$media_root" show "$media_revision:$media_path" > "$media_tmp/$media_name"
    media_blob="$(runuser -u fasakha -- git -C "$media_root" rev-parse "$media_revision:$media_path")"
    [[ "$(git hash-object --no-filters "$media_tmp/$media_name")" == "$media_blob" ]] || exit 1
    chmod 0644 "$media_tmp/$media_name"
done
runuser -u fasakha -- python3 "$media_tmp/apply_meta_media_user_agent.py" \
    --root "$media_root" --php "$media_php" \
    --source "$media_tmp/MetaMediaService.php" --diagnostic "$media_tmp/meta_media_diagnostic.php"
