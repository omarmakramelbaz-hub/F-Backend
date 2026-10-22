#!/usr/bin/env bash
# --check is read-only. Apply only after the operator approves the visible substitute.
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
mode=${1:---check}
[[ $# -le 1 && ( "$mode" == --check || "$mode" == --apply-missing-placeholders ) ]]
package=$(dirname -- "$(realpath -- "$0")")
project=/home/fasakha/public_html
php=/opt/cpanel/ea-php82/root/usr/bin/php
backup=/home/fasakha/go-erp-missing-image-backups
test -x "$php"
test -d "$project" && test ! -L "$project"
test "$(realpath -- "$project")" = "$project"
test "$(stat -c '%u' -- "$project")" = "$(id -u fasakha)"
for name in go_erp_media_file_repair.php fill_go_erp_missing_images.php assets/unavailable-image.jpg assets/unavailable-image.png; do
    test -f "$package/$name" && test ! -L "$package/$name"
done
if [[ "$mode" == --apply-missing-placeholders ]]; then
    if [[ ! -e "$backup" && ! -L "$backup" ]]; then
        install -d -m 0700 -o fasakha -g fasakha -- "$backup"
    fi
    test -d "$backup" && test ! -L "$backup"
    test "$(realpath -- "$backup")" = "$backup"
    test "$(stat -c '%u' -- "$backup")" = "$(id -u fasakha)"
    test "$(stat -c '%a' -- "$backup")" = 700
fi
runuser -u fasakha -- "$php" -d memory_limit=512M -d display_errors=0 -d log_errors=0 \
    "$package/fill_go_erp_missing_images.php" "$project" "$mode" "$backup"
