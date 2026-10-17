#!/usr/bin/env bash
# Read deployed metadata only. No checkout, migration, environment edit, or business-data export.
set -euo pipefail
project=/home/fasakha/public_html
release=${1:?Pass the reviewed commit containing the inspector}
[[ $(id -u) -eq 0 && $release =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid inspection invocation' >&2; exit 1; }
[[ -f "$project/vendor/autoload.php" ]] || { echo 'Application dependencies are unavailable' >&2; exit 1; }
inspection_directory=$(mktemp -d /tmp/fasakhansta-desktop-inspection.XXXXXXXX)
trap 'rm -rf -- "$inspection_directory"' EXIT
runuser -u fasakha -- git -C "$project" show "$release:deployment/desktop_dashboard_inspect.php" > "$inspection_directory/inspect.php"
chown -R fasakha:fasakha "$inspection_directory"
chmod 700 "$inspection_directory"
chmod 600 "$inspection_directory/inspect.php"
echo 'DESKTOP READ-ONLY INSPECTION'
runuser -u fasakha -- git -C "$project" rev-parse HEAD
runuser -u fasakha -- php "$inspection_directory/inspect.php" "$project" --compact
echo 'DESKTOP READ-ONLY INSPECTION COMPLETE'
