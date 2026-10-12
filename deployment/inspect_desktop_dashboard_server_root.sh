#!/usr/bin/env bash
# Source identities and schema metadata only. No publication, environment edits,
# migrations, backups, enrollment, or business-data export.
set -euo pipefail
umask 077
release=${1:?Pass the reviewed preflight release SHA}
[[ $(id -u) -eq 0 && $release =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid preflight invocation' >&2; exit 1; }
project=/home/fasakha/public_html
php_binary=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -d "$project/.git" && ! -L "$project" && ! -L "$project/.git" && $(realpath "$project") == "$project" ]] || { echo 'Application path needs review' >&2; exit 1; }
[[ -x "$php_binary" && -x /usr/bin/python3 && -x /usr/bin/timeout ]] || { echo 'Preflight runtime is unavailable' >&2; exit 1; }
id fasakha >/dev/null
directory=$(mktemp -d /tmp/fasakhansta-dashboard-preflight.XXXXXXXX)
trap 'rm -rf -- "$directory"' EXIT
declare -A pins=(
  [desktop_dashboard_server_preflight.py]=4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4
  [desktop_dashboard_server_metadata.php]=346b933e177506a66fb79baf4fa4e336ec805ac7
  [desktop_dashboard_inspect.php]=5cd78c2dcd70e1df5e7fa7f610eb3723f52ca1a6
)
for name in desktop_dashboard_server_preflight.py desktop_dashboard_server_metadata.php desktop_dashboard_inspect.php; do
  runuser -u fasakha -- git -C "$project" show "$release:deployment/$name" > "$directory/$name"
  [[ $(git hash-object --no-filters "$directory/$name") == "${pins[$name]}" ]] || { echo 'Pinned preflight reader does not match' >&2; exit 1; }
  chmod 600 "$directory/$name"
done
chown -R fasakha:fasakha "$directory"
chmod 700 "$directory"
echo 'DESKTOP_SERVER_PREFLIGHT_BEGIN'
timeout --signal=TERM --kill-after=5s 300s runuser -u fasakha -- /usr/bin/python3 -B \
  "$directory/desktop_dashboard_server_preflight.py" --project "$project" \
  --schema-inspector "$directory/desktop_dashboard_server_metadata.php" --php "$php_binary"
echo 'DESKTOP_SERVER_PREFLIGHT_COMPLETE; DEPLOYMENT_PERFORMED=false'
