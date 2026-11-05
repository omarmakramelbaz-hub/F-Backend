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
stage="$(mktemp -d /var/tmp/go-stock-zero.XXXXXXXX)"
trap 'rm -rf -- "$stage"' EXIT
chmod 0755 "$stage"
curl --fail --silent --show-error --location \
  --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
  "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$revision/deployment/stock_zero_all_branches/zero.php" \
  -o "$stage/zero.php"
printf '%s  %s\n' \
  1d04348dc3f8bde899a7e96c1e7c2468d3ece08b1c3d5e7f98dc16127385c967 \
  "$stage/zero.php" | sha256sum --check --status
chmod 0644 "$stage/zero.php"
backup=/home/fasakha/private-stock-zero-20261106
test ! -L "$backup"
install -d -o fasakha -g fasakha -m 0700 "$backup"
test "$(realpath "$backup")" = "$backup"
cd "$project"
runuser -u fasakha -- "$php_bin" -l "$stage/zero.php"
flock -n /var/lock/go-stock-zero-all-branches.lock \
  runuser -u fasakha -- "$php_bin" "$stage/zero.php" --apply
