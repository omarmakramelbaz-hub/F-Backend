#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_release="$1"
test "$2" = --inspect
[[ "$go_release" =~ ^[0-9a-f]{40}$ ]]

go_project=/home/fasakha/public_html
go_php=/opt/cpanel/ea-php82/root/usr/bin/php
test ! -L "$go_project"
test "$(realpath "$go_project")" = "$go_project"
test -f "$go_project/vendor/autoload.php"
test -f "$go_project/bootstrap/app.php"
test -x "$go_php"

go_stage=$(mktemp -d /var/tmp/go-owner-dashboard-inspect.XXXXXXXX)
trap 'rm -rf -- "$go_stage"' EXIT
chmod 0755 "$go_stage"
for go_file in inspect_owner_dashboards.php inspect_owner_dashboards_host.py; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_release/deployment/$go_file" \
        -o "$go_stage/$go_file"
done
printf '%s  %s\n' \
    564bc100d3b6b4d63664a619ae7b6a8659c50fe29672580491cf7e2298eada53 "$go_stage/inspect_owner_dashboards.php" \
    4eddf8c5a062e61496a6a08518c47c650146c4c17ab7e1487269d925b699e436 "$go_stage/inspect_owner_dashboards_host.py" \
    | sha256sum --check --status
chmod 0644 "$go_stage/inspect_owner_dashboards.php"
"$go_php" -l "$go_stage/inspect_owner_dashboards.php" >/dev/null
python3 -I -B "$go_stage/inspect_owner_dashboards_host.py" \
    "$go_stage/inspect_owner_dashboards.php" --inspect
