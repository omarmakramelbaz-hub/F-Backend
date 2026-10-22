#!/usr/bin/env bash
# Explicit --apply repairs only existing image names and verified same-path backup images.
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
mode=${1:---check}
[[ $# -le 1 && ( "$mode" == --check || "$mode" == --apply ) ]]
package=$(dirname -- "$(realpath -- "$0")")
project=/home/fasakha/public_html
php=/opt/cpanel/ea-php82/root/usr/bin/php
backup=/home/fasakha/go-erp-media-repair-backups
test -x "$php"
test -d "$project" && test ! -L "$project"
test "$(realpath -- "$project")" = "$project"
test "$(stat -c '%u' -- "$project")" = "$(id -u fasakha)"
for name in go_erp_media_file_repair.php repair_go_erp_media.php stage_go_erp_missing_media.py; do
    test -f "$package/$name" && test ! -L "$package/$name"
done
if [[ ! -e "$backup" && ! -L "$backup" ]]; then
    install -d -m 0700 -o fasakha -g fasakha -- "$backup"
fi
test -d "$backup" && test ! -L "$backup"
test "$(realpath -- "$backup")" = "$backup"
test "$(stat -c '%u' -- "$backup")" = "$(id -u fasakha)"
test "$(stat -c '%a' -- "$backup")" = 700
stage=$(mktemp -d /var/tmp/go-erp-media-backup-stage.XXXXXXXX)
stage=$(realpath -- "$stage")
trap 'rm -rf -- "$stage"' EXIT
python3 -B "$package/stage_go_erp_missing_media.py" "$stage"
chown -R fasakha:fasakha -- "$stage"
chmod 0700 -- "$stage"
runuser -u fasakha -- "$php" -d memory_limit=512M -d display_errors=0 -d log_errors=0 \
    "$package/repair_go_erp_media.php" "$project" "$mode" "$stage" "$backup"
