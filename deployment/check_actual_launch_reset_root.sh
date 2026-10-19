#!/usr/bin/env bash
# Read-only live database inspection. Does not install application files or reset records.
set -euo pipefail
umask 077
launch_revision=${1:?A full revision is required}
[[ $# = 1 && "$launch_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) = 0 ]]
[[ $(hostname -s) = srv1836524 ]]
launch_root=/home/fasakha/public_html
launch_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$launch_php" && -d "$launch_root" && ! -L "$launch_root" ]]
[[ $(realpath -- "$launch_root") = "$launch_root" ]]
[[ $(stat -c '%u' -- "$launch_root") = "$(id -u fasakha)" ]]
launch_tmp=$(mktemp -d /tmp/actual-launch-inspection.XXXXXXXX)
trap 'rm -rf -- "$launch_tmp"' EXIT
chmod 0755 "$launch_tmp"
launch_source=deployment/inspect_actual_launch_reset.php
launch_blob=$(runuser -u fasakha -- git -C "$launch_root" rev-parse "$launch_revision:$launch_source")
[[ "$launch_blob" =~ ^[0-9a-f]{40}$ ]]
runuser -u fasakha -- git -C "$launch_root" show "$launch_revision:$launch_source" > "$launch_tmp/inspect.php"
[[ $(git hash-object --no-filters "$launch_tmp/inspect.php") = "$launch_blob" ]]
chmod 0644 "$launch_tmp/inspect.php"
runuser -u fasakha -- "$launch_php" -l "$launch_tmp/inspect.php"
runuser -u fasakha -- "$launch_php" "$launch_tmp/inspect.php" --check "$launch_root"
