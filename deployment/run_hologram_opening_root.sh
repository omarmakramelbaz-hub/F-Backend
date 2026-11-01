#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_hologram_revision="$1"
[[ "$go_hologram_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
APPLY_SHA256=13546d8889941b2548b5bec1a63082a4caa35539cf150e808cc64359aa439a94
MANIFEST_SHA256=aa585aac0566c643d19e2b2f292b91af2405248c6bef7e41271379d905eae966
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
