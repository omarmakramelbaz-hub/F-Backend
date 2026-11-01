#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_hologram_revision="$1"
[[ "$go_hologram_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
APPLY_SHA256=efb5eb0bd9b3cae806e6a9ce19f151ed3f13bceb2c01e3d28b8045ef18c59228
MANIFEST_SHA256=cddae74fe11ecc4a11c3ba836da1c04ea3579857ef0d04edf26f0428feeb4947
[[ "$APPLY_SHA256" =~ ^[0-9a-f]{64}$ ]]
[[ "$MANIFEST_SHA256" =~ ^[0-9a-f]{64}$ ]]
go_hologram_stage=$(mktemp -d /var/tmp/go-hologram-entry.XXXXXXXX)
trap 'rm -rf -- "$go_hologram_stage"' EXIT
chmod 0755 "$go_hologram_stage"
for go_hologram_file in apply_root.py manifest.json; do
    curl --fail --silent --show-error --location \
        --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_hologram_revision/deployment/hologram/$go_hologram_file" \
        -o "$go_hologram_stage/$go_hologram_file"
done
printf '%s  %s\n' \
    "$APPLY_SHA256" "$go_hologram_stage/apply_root.py" \
    "$MANIFEST_SHA256" "$go_hologram_stage/manifest.json" | sha256sum --check --status
python3 -I -B "$go_hologram_stage/apply_root.py" "$go_hologram_stage/manifest.json" "$2"
