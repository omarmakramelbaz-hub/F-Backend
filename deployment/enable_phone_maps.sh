#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
test -f .env && test ! -L .env
test "$(stat -c %u .env)" = "$(id -u)" || { echo 'Run as the application owner.'; exit 1; }
test -f vendor/autoload.php
test ! -L bootstrap/cache/config.php

# Consent was given by the owner on 2026-10-04. Probe only public example locations.
php deployment/check_phone_maps.php --preflight
backup="$(mktemp -d "$(dirname "$PWD")/phone-maps-backup-XXXXXXXX")"
chmod 700 "$backup"
cp -p .env "$backup/env"
cached=0
if test -f bootstrap/cache/config.php; then
    cached=1
    cp -p bootstrap/cache/config.php "$backup/config.php"
fi
env_tmp=''
rollback() {
    local status=$?
    trap - ERR
    cp -p "$backup/env" .env
    if test "$cached" = 1; then cp -p "$backup/config.php" bootstrap/cache/config.php; else rm -f bootstrap/cache/config.php; fi
    if test -n "$env_tmp"; then rm -f "$env_tmp"; fi
    echo 'Map activation failed; the previous environment and configuration were restored.' >&2
    exit "$status"
}
trap rollback ERR
env_tmp="$(mktemp .env.maps.XXXXXXXX)"
sed -E '/^[[:blank:]]*(export[[:blank:]]+)?PHONE_OPEN_MAPS_ENABLED[[:blank:]]*=/d' .env > "$env_tmp"
printf '\nPHONE_OPEN_MAPS_ENABLED=true\n' >> "$env_tmp"
chmod --reference=.env "$env_tmp"
mv -f "$env_tmp" .env
env_tmp=''
if test "$cached" = 1; then php artisan config:cache; else php artisan config:clear; fi
php deployment/check_phone_maps.php --enabled
trap - ERR
echo 'PHONE MAPS ENABLED: address choices, customer pin, road route and distance-based fee.'
echo "Previous environment/configuration saved at: $backup"
