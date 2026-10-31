#!/usr/bin/env bash
set -euo pipefail
umask 077

test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
release="$1"
test "$2" = --apply
[[ "$release" =~ ^[0-9a-f]{40}$ ]]

project=/home/fasakha/public_html
backup=/home/fasakha/go-erp-account-backups
php=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$php"
test ! -L "$project"
test "$(realpath "$project")" = "$project"
test -f "$project/vendor/autoload.php"
test -f "$project/bootstrap/app.php"
test -f "$project/.env"
test ! -L "$backup"
install -d -m 0700 -o fasakha -g fasakha "$backup"
test "$(realpath "$backup")" = "$backup"

stage=$(mktemp -d /var/tmp/go-remove-smartvision.XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT
chmod 0755 "$stage"
script="$stage/remove_smartvision_account.php"
curl --fail --silent --show-error --location \
    --proto '=https' --proto-redir '=https' --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$release/deployment/remove_smartvision_account.php" \
    -o "$script"
printf '%s  %s\n' \
    fa0a4112dd4f58d00d6329ff49fdadb39d845da49fcd76d1a883f3fd7e8891ed \
    "$script" | sha256sum --check --status
chmod 0644 "$script"
"$php" -l "$script" >/dev/null
runuser -u fasakha -- "$php" \
    -d display_errors=0 -d log_errors=0 \
    "$script" "$project" "$backup" --apply
