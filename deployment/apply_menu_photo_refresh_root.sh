#!/usr/bin/env bash
# Publish the reviewed product photographs to every Fasakhansta branch.
set -euo pipefail
umask 077
photo_release=''
photo_mode=--check
while (($#)); do
    case "$1" in
        --release) photo_release="${2:?Missing release}"; shift 2 ;;
        --apply|--check) photo_mode="$1"; shift ;;
        *) echo 'Usage: apply_menu_photo_refresh_root.sh --release SHA [--check|--apply]' >&2; exit 2 ;;
    esac
done
[[ "$photo_release" =~ ^[0-9a-f]{40}$ ]]
test "$(id -u)" = 0
test "$(hostname -s)" = srv1836524
photo_root=/home/fasakha/public_html
photo_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$photo_php"
test ! -L "$photo_root"
test "$(realpath -- "$photo_root")" = "$photo_root"
test "$(stat -c '%u' -- "$photo_root")" = "$(id -u fasakha)"
test -f "$photo_root/vendor/autoload.php"
runuser -u fasakha -- git -C "$photo_root" cat-file -e "$photo_release^{commit}"

photo_stage=$(mktemp -d /tmp/menu-photo-refresh.XXXXXXXX)
trap 'rm -rf -- "$photo_stage"' EXIT
chmod 0755 "$photo_stage"
runuser -u fasakha -- git -C "$photo_root" archive "$photo_release" \
    deployment/refresh_menu_photos_20261021.php deployment/menu_photo_framing_20261021.php deployment/menu-photo-refresh-20261021 \
    | tar --no-same-owner -x -C "$photo_stage"
find "$photo_stage" -type d -exec chmod 0755 {} +
find "$photo_stage" -type f -exec chmod 0644 {} +
photo_runner="$photo_stage/deployment/refresh_menu_photos_20261021.php"
photo_framing="$photo_stage/deployment/menu_photo_framing_20261021.php"
runuser -u fasakha -- "$photo_php" -l "$photo_runner"
"$photo_php" -l "$photo_framing"
"$photo_php" "$photo_framing" --check --root="$photo_root"
runuser -u fasakha -- "$photo_php" -d display_errors=0 -d log_errors=0 \
    "$photo_runner" --check --root="$photo_root"
if [[ "$photo_mode" = --check ]]; then
    echo 'MENU_PHOTOS_CHECK_ONLY_NO_DATA_CHANGED'
    exit 0
fi

photo_backup_root=/home/fasakha/menu-photo-backups
test ! -L "$photo_backup_root"
install -d -m 0700 -o fasakha -g fasakha "$photo_backup_root"
photo_backup=$(mktemp -d "$photo_backup_root/20261021-XXXXXXXX")
chown fasakha:fasakha "$photo_backup"
runuser -u fasakha -- "$photo_php" -d display_errors=0 -d log_errors=0 \
    "$photo_runner" --apply --root="$photo_root" --backup="$photo_backup"
"$photo_php" "$photo_framing" --apply --root="$photo_root" --backup="$photo_backup"
echo "Private photo backup: $photo_backup"
