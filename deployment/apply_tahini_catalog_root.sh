#!/usr/bin/env bash
# Add the shared tahini expense choice and inventory ingredient.
set -euo pipefail
umask 077
tahini_release=''
tahini_unit=''
tahini_mode=--check
while (($#)); do
    case "$1" in
        --release) tahini_release="${2:?Missing release}"; shift 2 ;;
        --ingredient-unit) tahini_unit="${2:?Missing ingredient unit}"; shift 2 ;;
        --apply|--check) tahini_mode="$1"; shift ;;
        *) echo 'Usage: apply_tahini_catalog_root.sh --release SHA --ingredient-unit kg|piece [--check|--apply]' >&2; exit 2 ;;
    esac
done
[[ "$tahini_release" =~ ^[0-9a-f]{40}$ ]]
[[ "$tahini_unit" = kg || "$tahini_unit" = piece ]]
test "$(id -u)" = 0
test "$(hostname -s)" = srv1836524
tahini_root=/home/fasakha/public_html
tahini_php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$tahini_php"
test ! -L "$tahini_root"
test "$(realpath -- "$tahini_root")" = "$tahini_root"
test "$(stat -c '%u' -- "$tahini_root")" = "$(id -u fasakha)"
test -f "$tahini_root/vendor/autoload.php"
runuser -u fasakha -- git -C "$tahini_root" cat-file -e "$tahini_release^{commit}"

tahini_stage=$(mktemp -d /tmp/tahini-catalog.XXXXXXXX)
trap 'rm -rf -- "$tahini_stage"' EXIT
chmod 0755 "$tahini_stage"
runuser -u fasakha -- git -C "$tahini_root" archive "$tahini_release" \
    deployment/add_tahini_catalog_20261021.php \
    | tar --no-same-owner -x -C "$tahini_stage"
find "$tahini_stage" -type d -exec chmod 0755 {} +
find "$tahini_stage" -type f -exec chmod 0644 {} +
tahini_runner="$tahini_stage/deployment/add_tahini_catalog_20261021.php"
runuser -u fasakha -- "$tahini_php" -l "$tahini_runner"
runuser -u fasakha -- "$tahini_php" -d display_errors=0 -d log_errors=0 \
    "$tahini_runner" --check --root="$tahini_root" --ingredient-unit="$tahini_unit"
if [[ "$tahini_mode" = --check ]]; then
    echo 'TAHINI_CATALOG_CHECK_ONLY_NO_DATA_CHANGED'
    exit 0
fi

tahini_backup_root=/home/fasakha/tahini-catalog-backups
test ! -L "$tahini_backup_root"
install -d -m 0700 -o fasakha -g fasakha "$tahini_backup_root"
tahini_backup=$(mktemp -d "$tahini_backup_root/20261021-XXXXXXXX")
chown fasakha:fasakha "$tahini_backup"
runuser -u fasakha -- "$tahini_php" -d display_errors=0 -d log_errors=0 \
    "$tahini_runner" --apply --root="$tahini_root" --ingredient-unit="$tahini_unit" --backup="$tahini_backup"
echo "Private tahini catalog backup: $tahini_backup"
