#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_premium_revision="$1"
[[ "$go_premium_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
INSTALL_SHA256=56de0984d1bf4cbd1aacbf091431783d4055442d2830f7880133cf1854f04d75
MANIFEST_SHA256=27ddb82d200f87c112054910e01b9cf5e78c454dc19c8b929ee4262124fec6b9
go_premium_stage=$(mktemp -d /var/tmp/go-hologram-premium.XXXXXXXX)
trap 'rm -rf -- "$go_premium_stage"' EXIT
chmod 0755 "$go_premium_stage"
for go_premium_file in install_root.py manifest.json; do
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_premium_revision/scripts/hologram_premium/$go_premium_file" \
        -o "$go_premium_stage/$go_premium_file"
done
printf '%s  %s\n' "$INSTALL_SHA256" "$go_premium_stage/install_root.py" \
    "$MANIFEST_SHA256" "$go_premium_stage/manifest.json" | sha256sum --check --status
python3 -I -B "$go_premium_stage/install_root.py" "$go_premium_stage/manifest.json" "$2"
