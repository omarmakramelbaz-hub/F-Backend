#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_account_revision="$1"
[[ "$go_account_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
INSTALL_SHA256=8c9c1893c84142f151fd4a92e47246877df724d88b0a635f91afc5d90b4612e0
MANIFEST_SHA256=96963221afb44475292dc0b06618be8399dea4cf70f940af2ada7582db80167e
PROBE_SHA256=9a98d66d77500d144f8313de8fd7d60f77211c265b22410557653761ebe0357d
go_account_stage=$(mktemp -d /var/tmp/go-account-entry.XXXXXXXX)
trap 'rm -rf -- "$go_account_stage"' EXIT
chmod 0755 "$go_account_stage"
for go_account_file in install_root.py manifest.json host_probe.py; do
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_account_revision/scripts/hologram_named_voice/$go_account_file" \
        -o "$go_account_stage/$go_account_file"
done
printf '%s  %s\n' "$INSTALL_SHA256" "$go_account_stage/install_root.py" \
    "$MANIFEST_SHA256" "$go_account_stage/manifest.json" "$PROBE_SHA256" "$go_account_stage/host_probe.py" \
    | sha256sum --check --status
python3 -I -B "$go_account_stage/install_root.py" "$go_account_stage/manifest.json" "$2"
