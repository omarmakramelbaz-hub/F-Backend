#!/usr/bin/env bash
set -euo pipefail
source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/bin" "$fixture/site/deployment" "$fixture/site/vendor" "$fixture/site/bootstrap/cache"
cp "$source_root/deployment/enable_phone_maps.sh" "$fixture/site/deployment/"
touch "$fixture/site/vendor/autoload.php"
cat > "$fixture/bin/php" <<'PHP_STUB'
#!/usr/bin/env bash
set -euo pipefail
if test "$1" = deployment/check_phone_maps.php; then
    if test "$2" = --preflight; then test "${FAIL_PREFLIGHT:-0}" = 0; exit; fi
    test "$(grep -c '^PHONE_OPEN_MAPS_ENABLED=true$' .env)" = 1
    exit
fi
test "$1" = artisan
if test "$2" = config:cache; then
    printf 'new configuration' > bootstrap/cache/config.php
    test "${FAIL_CONFIG:-0}" = 0
elif test "$2" = config:clear; then
    rm -f bootstrap/cache/config.php
else exit 9; fi
PHP_STUB
chmod +x "$fixture/bin/php"
export PATH="$fixture/bin:$PATH"
cd "$fixture/site"
original=$'# configuration\nAPP_NAME="Fixture only"\nAPP_KEY="preserve-$-and-spaces"\nPHONE_OPEN_MAPS_ENABLED=false\nexport PHONE_OPEN_MAPS_ENABLED = false\nOTHER_FLAG=true\n'
for cached in 0 1; do
    printf '%s' "$original" > .env
    chmod 640 .env
    if test "$cached" = 1; then printf 'old configuration' > bootstrap/cache/config.php; else rm -f bootstrap/cache/config.php; fi
    bash deployment/enable_phone_maps.sh > "$fixture/result"
    grep -q 'PHONE MAPS ENABLED' "$fixture/result"
    test "$(grep -c 'PHONE_OPEN_MAPS_ENABLED' .env)" = 1
    test "$(stat -c %a .env)" = 640
    grep -Fq 'APP_KEY="preserve-$-and-spaces"' .env
    if test "$cached" = 1; then grep -q 'new configuration' bootstrap/cache/config.php; else test ! -e bootstrap/cache/config.php; fi
    cp .env "$fixture/first"
    bash deployment/enable_phone_maps.sh > "$fixture/result"
    test "$(grep -c 'PHONE_OPEN_MAPS_ENABLED' .env)" = 1
done
printf '%s' "$original" > .env
printf 'old configuration' > bootstrap/cache/config.php
cp .env "$fixture/original.env"
if FAIL_CONFIG=1 bash deployment/enable_phone_maps.sh > "$fixture/result" 2>&1; then exit 1; fi
cmp .env "$fixture/original.env"
test "$(cat bootstrap/cache/config.php)" = 'old configuration'
grep -q 'were restored' "$fixture/result"
if FAIL_PREFLIGHT=1 bash deployment/enable_phone_maps.sh > "$fixture/result" 2>&1; then exit 1; fi
cmp .env "$fixture/original.env"
test "$(cat bootstrap/cache/config.php)" = 'old configuration'
for backup in "$fixture"/phone-maps-backup-*; do test "$(stat -c %a "$backup")" = 700; done
echo 'PHONE MAP ACTIVATION: cached/uncached success, repeated activation, preserved environment/mode, private backups, preflight failure and rollback passed.'
