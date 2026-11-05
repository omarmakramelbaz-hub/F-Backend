#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
revision="${1:?Pass the pinned release SHA}"
[[ "$revision" =~ ^[0-9a-f]{40}$ ]]
test "$#" -eq 1
project=/home/fasakha/public_html
test "$(realpath "$project")" = "$project"
test -f "$project/vendor/autoload.php"
test -f "$project/bootstrap/app.php"
php_bin=/opt/cpanel/ea-php82/root/usr/bin/php
test -x "$php_bin"
stage="$(mktemp -d /var/tmp/go-hr-nov05.XXXXXXXX)"
trap 'rm -rf -- "$stage"' EXIT
chmod 0755 "$stage"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$revision/deployment/employee_attendance_nov05/repair.php" \
  -o "$stage/repair.php"
printf '%s  %s\n' \
  b2ad5a2a42a0749666086d3d49cd5f0e72a084c489913bfebd3538eb4cb5fdc9 \
  "$stage/repair.php" | sha256sum --check --status
chmod 0644 "$stage/repair.php"
backup=/home/fasakha/private-hr-nov05
test ! -L "$backup"
install -d -o fasakha -g fasakha -m 0700 "$backup"
test "$(realpath "$backup")" = "$backup"
cd "$project"
runuser -u fasakha -- "$php_bin" -l "$stage/repair.php"
flock -n /var/lock/go-hr-nov05.lock \
  runuser -u fasakha -- "$php_bin" "$stage/repair.php" --apply
