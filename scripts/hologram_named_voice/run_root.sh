#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_account_revision="$1"
[[ "$go_account_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
INSTALL_SHA256=d12caae98107ff05e70bddb0efbeb81b15f598dd7fed1f9d72316711a450dd2f
MANIFEST_SHA256=746168fba126f6946d73d784799929de9a9f7780237fa14a911865e92882bf00
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
