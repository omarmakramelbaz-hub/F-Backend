#!/usr/bin/env bash
# Repair only the images added by menu-sides-20261021; keep products, prices and IDs.
set -euo pipefail
umask 077
side_image_release=''
side_image_mode=--check
while (($#)); do
    case "$1" in
        --release) side_image_release="${2:?Missing release}"; shift 2 ;;
        --apply|--check) side_image_mode="$1"; shift ;;
        *) echo 'Usage: repair_menu_side_images_root.sh --release SHA [--check|--apply]' >&2; exit 2 ;;
    esac
done
[[ "$side_image_release" =~ ^[0-9a-f]{40}$ ]]
test "$(id -u)" = 0
test "$(hostname -s)" = srv1836524
side_image_root=/home/fasakha/public_html
side_image_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$side_image_php"
test ! -L "$side_image_root"
test "$(realpath -- "$side_image_root")" = "$side_image_root"
test "$(stat -c '%u' -- "$side_image_root")" = "$(id -u fasakha)"
test -f "$side_image_root/vendor/autoload.php"
test -f "$side_image_root/storage/app/menu-sides-20261021/receipt.json"
runuser -u fasakha -- git -C "$side_image_root" cat-file -e "$side_image_release^{commit}"

side_image_stage=$(mktemp -d /tmp/menu-side-image-repair.XXXXXXXX)
trap 'rm -rf -- "$side_image_stage"' EXIT
chmod 0755 "$side_image_stage"
runuser -u fasakha -- git -C "$side_image_root" archive "$side_image_release" \
    deployment/repair_menu_side_images_20261021.php | tar --no-same-owner -x -C "$side_image_stage"
find "$side_image_stage" -type d -exec chmod 0755 {} +
find "$side_image_stage" -type f -exec chmod 0644 {} +
side_image_runner="$side_image_stage/deployment/repair_menu_side_images_20261021.php"
runuser -u fasakha -- "$side_image_php" -l "$side_image_runner"
runuser -u fasakha -- "$side_image_php" -d display_errors=0 -d log_errors=0 \
    "$side_image_runner" --check --root="$side_image_root"
if [[ "$side_image_mode" = --check ]]; then
    echo 'MENU_SIDE_IMAGE_CHECK_ONLY_NO_DATA_CHANGED'
    exit 0
fi
side_image_backup_root=/home/fasakha/menu-sides-backups
test ! -L "$side_image_backup_root"
install -d -m 0700 -o fasakha -g fasakha "$side_image_backup_root"
side_image_backup=$(mktemp -d "$side_image_backup_root/image-repair-20261021-XXXXXXXX")
chown fasakha:fasakha "$side_image_backup"
runuser -u fasakha -- "$side_image_php" -d display_errors=0 -d log_errors=0 \
    "$side_image_runner" --apply --root="$side_image_root" --backup="$side_image_backup"
echo "Private image repair receipt: $side_image_backup"
