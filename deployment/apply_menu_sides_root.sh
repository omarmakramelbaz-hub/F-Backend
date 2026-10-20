#!/usr/bin/env bash
# Install only the two owner-requested menu plates and their media; no source checkout.
set -euo pipefail
umask 077

menu_release=''
menu_mode=--check
while (($#)); do
    case "$1" in
        --release) menu_release="${2:?Missing release}"; shift 2 ;;
        --apply|--check) menu_mode="$1"; shift ;;
        *) echo 'Usage: apply_menu_sides_root.sh --release SHA [--check|--apply]' >&2; exit 2 ;;
    esac
done
[[ "$menu_release" =~ ^[0-9a-f]{40}$ ]]
test "$(id -u)" = 0
test "$(hostname -s)" = srv1836524
menu_root=/home/fasakha/public_html
menu_owner=fasakha
menu_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$menu_php"
test ! -L "$menu_root"
test "$(realpath -- "$menu_root")" = "$menu_root"
test "$(stat -c '%u' -- "$menu_root")" = "$(id -u "$menu_owner")"
test -f "$menu_root/vendor/autoload.php"
test -f "$menu_root/bootstrap/app.php"
runuser -u "$menu_owner" -- git -C "$menu_root" cat-file -e "$menu_release^{commit}"

menu_stage=$(mktemp -d /tmp/menu-sides-release.XXXXXXXX)
trap 'rm -rf -- "$menu_stage"' EXIT
chmod 0755 "$menu_stage"
runuser -u "$menu_owner" -- git -C "$menu_root" archive "$menu_release" \
    deployment/menu_sides_20261021.php deployment/menu-sides-20261021/assets \
    | tar --no-same-owner -x -C "$menu_stage"
find "$menu_stage" -type d -exec chmod 0755 {} +
find "$menu_stage" -type f -exec chmod 0644 {} +
menu_runner="$menu_stage/deployment/menu_sides_20261021.php"
runuser -u "$menu_owner" -- "$menu_php" -l "$menu_runner"
runuser -u "$menu_owner" -- "$menu_php" -d display_errors=0 -d log_errors=0 \
    "$menu_runner" --check --root="$menu_root"
if [[ "$menu_mode" = --check ]]; then
    echo 'MENU_SIDES_CHECK_ONLY_NO_DATA_CHANGED'
    exit 0
fi

menu_backups=/home/fasakha/menu-sides-backups
test ! -L "$menu_backups"
install -d -m 0700 -o "$menu_owner" -g "$menu_owner" "$menu_backups"
menu_backup=$(mktemp -d "$menu_backups/20261021-XXXXXXXX")
chown "$menu_owner:$menu_owner" "$menu_backup"
runuser -u "$menu_owner" -- "$menu_php" -d display_errors=0 -d log_errors=0 \
    "$menu_runner" --apply --root="$menu_root" --backup="$menu_backup"
echo "Private menu receipt: $menu_backup"
