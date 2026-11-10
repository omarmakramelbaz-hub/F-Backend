#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -eq 2
go_arabic_revision="$1"
[[ "$go_arabic_revision" =~ ^[0-9a-f]{40}$ ]]
test "$2" = --apply || test "$2" = --check
INSTALL_SHA256=05d0a14e3a845551704de72932a990787872c21915240334ccc9d57e4b93daee
MANIFEST_SHA256=e65ef49245f6a10a99d84fc06c320555d83cf1403b5be2c48e1e40e0d7503e52
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
