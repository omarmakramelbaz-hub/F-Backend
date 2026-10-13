#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo RUN_AS_ROOT; exit 1; }
meta_root=/home/fasakha/public_html
meta_php=/opt/cpanel/ea-php82/root/usr/bin/php
meta_release="${1:?Full release SHA required}"
[[ "$meta_release" =~ ^[a-f0-9]{40}$ && -x "$meta_php" ]] || exit 1
meta_app_uid="$(id -u fasakha)"
meta_units=(fasakhansta-meta-messaging.timer fasakhansta-meta-messaging.service)
meta_managed_units=()
declare -A meta_was_active=()
meta_owned_unit() {
    local meta_unit_file="/etc/systemd/system/$1"
    [[ ! -L "$meta_unit_file" && -f "$meta_unit_file" ]] \
        && grep -q 'Fasakhansta native Meta messaging' "$meta_unit_file"
}
for meta_unit in "${meta_units[@]}"; do
    meta_unit_file="/etc/systemd/system/$meta_unit"
    if [[ -e "$meta_unit_file" || -L "$meta_unit_file" ]]; then
        meta_owned_unit "$meta_unit" || { echo EXISTING_META_UNIT_NOT_OWNED; exit 1; }
        meta_managed_units+=("$meta_unit")
    fi
done
meta_tmp="$(mktemp -d /tmp/meta-admin-notices.XXXXXX)"
chmod 755 "$meta_tmp"
meta_stopped_units=()
meta_completed=0
meta_finish() {
    meta_exit=$?
    trap - EXIT
    # Restore independently: the service may have been active with an inactive timer.
    for ((meta_restore_index=${#meta_stopped_units[@]}-1; meta_restore_index>=0; meta_restore_index--)); do
        meta_restore_unit="${meta_stopped_units[$meta_restore_index]}"
        if [[ "${meta_was_active[$meta_restore_unit]:-0}" == 1 ]]; then
            if ! systemctl start "$meta_restore_unit"; then
                printf '%s\n' "META_UNIT_RESTORE_FAILED=$meta_restore_unit"
                meta_exit=1
            fi
        fi
    done
    rm -rf -- "$meta_tmp"
    if [[ "$meta_exit" == 0 && "$meta_completed" == 1 ]]; then
        printf '%s\n' META_ADMIN_NOTICE_UPDATE_COMPLETE
    fi
    exit "$meta_exit"
}
trap meta_finish EXIT
for meta_file in update_meta_admin_notices.py resolve_meta_admin_notices.php meta_inbound_diagnostic.php; do
    runuser -u fasakha -- git -C "$meta_root" show "$meta_release:deployment/$meta_file" > "$meta_tmp/$meta_file"
    meta_blob="$(runuser -u fasakha -- git -C "$meta_root" rev-parse "$meta_release:deployment/$meta_file")"
    [[ "$meta_blob" =~ ^[a-f0-9]{40}$ && "$(git hash-object "$meta_tmp/$meta_file")" == "$meta_blob" ]] || exit 1
    chmod 644 "$meta_tmp/$meta_file"
done
# Recovery needs optional PHP POSIX functions and durable audit writes. Check the
# exact application runtime before stopping workers or publishing the parser.
if ! runuser -u fasakha -- "$meta_php" -r '
ini_set("display_errors", "0"); error_reporting(0);
if (!function_exists("posix_geteuid") || !function_exists("posix_getpwnam") || !function_exists("fsync")) exit(1);
$account = posix_getpwnam("fasakha");
if (!is_array($account) || ($account["uid"] ?? 0) <= 0 || posix_geteuid() !== $account["uid"]) exit(1);
'; then
    echo META_ADMIN_NOTICE_RUNTIME_PREFLIGHT_FAILED
    exit 1
fi
echo META_ADMIN_NOTICE_RUNTIME_PREFLIGHT_OK
for meta_unit in "${meta_managed_units[@]}"; do
    meta_owned_unit "$meta_unit" || { echo EXISTING_META_UNIT_NOT_OWNED; exit 1; }
    meta_was_active[$meta_unit]=0
    if systemctl is-active --quiet "$meta_unit"; then meta_was_active[$meta_unit]=1; fi
done
for meta_unit in "${meta_managed_units[@]}"; do
    # Record before stop so a partially successful stop still receives its restore guard.
    meta_stopped_units+=("$meta_unit")
    systemctl stop "$meta_unit"
done
if ! meta_update_output="$(runuser -u fasakha -- python3 "$meta_tmp/update_meta_admin_notices.py" \
    --root "$meta_root" --release "$meta_release" --php "$meta_php")"; then
    printf '%s\n' "$meta_update_output"
    exit 1
fi
printf '%s\n' "$meta_update_output"
mapfile -t meta_backup_lines < <(printf '%s\n' "$meta_update_output" | sed -n 's/^BACKUP=//p')
[[ "${#meta_backup_lines[@]}" == 1 ]] || { echo PRIVATE_META_AUDIT_DIRECTORY_REQUIRED; exit 1; }
meta_audit_dir="${meta_backup_lines[0]}"
[[ "$meta_audit_dir" =~ ^/home/fasakha/meta-release-backups/[0-9]{8}-[0-9]{6}-admin-notices-[a-f0-9]{8}-[A-Za-z0-9_-]+$ \
    && ! -L "$meta_audit_dir" && -d "$meta_audit_dir" \
    && "$(stat -c %u "$meta_audit_dir")" == "$meta_app_uid" \
    && "$(stat -c %a "$meta_audit_dir")" == 700 ]] || { echo PRIVATE_META_AUDIT_DIRECTORY_REQUIRED; exit 1; }
cd "$meta_root"
# This separate reviewed recovery runs even when the protocol was already current.
runuser -u fasakha -- "$meta_php" "$meta_tmp/resolve_meta_admin_notices.php" --audit-dir="$meta_audit_dir"
runuser -u fasakha -- "$meta_php" "$meta_tmp/meta_inbound_diagnostic.php" --limit=200
meta_completed=1
