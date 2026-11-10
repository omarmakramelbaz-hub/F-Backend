#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_arabic_revision="$1"
[[ "$go_arabic_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
INSTALL_SHA256=3b6e5c42c53d150a784ef5d32526eda2ce5946a128655b4f4bfb71bb812c41ba
MANIFEST_SHA256=496f96daaef1cd2c9cc9f3415ac8138a2349bdff010ea8899f854b300cc1f214
go_arabic_stage=$(mktemp -d /var/tmp/go-hologram-arabic.XXXXXXXX)
trap 'rm -rf -- "$go_arabic_stage"' EXIT
for go_arabic_file in install_root.py manifest.json; do
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 60 \
        "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$go_arabic_revision/scripts/hologram_arabic/$go_arabic_file" \
        -o "$go_arabic_stage/$go_arabic_file"
done
printf '%s  %s\n' "$INSTALL_SHA256" "$go_arabic_stage/install_root.py" \
    "$MANIFEST_SHA256" "$go_arabic_stage/manifest.json" | sha256sum --check --status
python3 -I -B "$go_arabic_stage/install_root.py" "$go_arabic_stage/manifest.json" "$2"
