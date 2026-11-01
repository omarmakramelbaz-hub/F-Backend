#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_account_revision="$1"
[[ "$go_account_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
INSTALL_SHA256=e55ed7cf3ab03c1374062c15a81a8a154cf1b1dd87cfced9daf1e0b6e8081d37
MANIFEST_SHA256=86293f08fe17d6ccf3c6a234e51f21f3434dd1df8de4ada375a64f44a9f38c11
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
