#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
meta_root=/home/fasakha/public_html
meta_php=/opt/cpanel/ea-php82/root/usr/bin/php
meta_release="${1:?Full release SHA required}"
[[ "$meta_release" =~ ^[a-f0-9]{40}$ && -x "$meta_php" ]] || exit 1
meta_timer=fasakhansta-meta-messaging.timer
meta_service=fasakhansta-meta-messaging.service
for meta_unit in "$meta_timer" "$meta_service"; do
    meta_unit_file="/etc/systemd/system/$meta_unit"
    if [[ -e "$meta_unit_file" ]] && ! grep -q 'Fasakhansta native Meta messaging' "$meta_unit_file"; then
        echo EXISTING_META_UNIT_NOT_OWNED; exit 1
    fi
done
meta_tmp="$(mktemp -d /tmp/meta-chat-update.XXXXXX)"
chmod 755 "$meta_tmp"
meta_was_active=0
meta_stopped=0
meta_finish() {
    meta_exit=$?
    trap - EXIT
    if [[ "$meta_stopped" == 1 && "$meta_was_active" == 1 ]]; then
        if ! systemctl start "$meta_timer"; then
            echo META_TIMER_RESTART_FAILED
            meta_exit=1
        fi
    fi
    rm -rf -- "$meta_tmp"
    exit "$meta_exit"
}
trap meta_finish EXIT
for meta_file in update_meta_chat.py meta_inbound_diagnostic.php; do
    runuser -u fasakha -- git -C "$meta_root" show "$meta_release:deployment/$meta_file" > "$meta_tmp/$meta_file"
    meta_blob="$(runuser -u fasakha -- git -C "$meta_root" rev-parse "$meta_release:deployment/$meta_file")"
    [[ "$(git hash-object "$meta_tmp/$meta_file")" == "$meta_blob" ]] || exit 1
    chmod 644 "$meta_tmp/$meta_file"
done
if systemctl is-active --quiet "$meta_timer"; then meta_was_active=1; fi
if [[ -e "/etc/systemd/system/$meta_timer" || -e "/etc/systemd/system/$meta_service" ]]; then
    systemctl stop "$meta_timer" "$meta_service"
    meta_stopped=1
fi
runuser -u fasakha -- python3 "$meta_tmp/update_meta_chat.py" --root "$meta_root" --release "$meta_release" --php "$meta_php"
cd "$meta_root"
if ! runuser -u fasakha -- "$meta_php" artisan meta:refresh-profiles --limit=10; then
    echo META_PROFILE_REFRESH_NEEDS_REVIEW
fi
runuser -u fasakha -- "$meta_php" "$meta_tmp/meta_inbound_diagnostic.php" --limit=200
printf '%s\n' META_CHAT_UPDATE_COMPLETE
