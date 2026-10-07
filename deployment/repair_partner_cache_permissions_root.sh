#!/usr/bin/env bash
set -euo pipefail
# Repair runtime cache permissions without flushing active email challenges.
project="${1:-/home/fasakha/public_html}"
owner=fasakha
test "$(id -u)" -eq 0
id "$owner" >/dev/null
test -f "$project/artisan"
test -f "$project/vendor/autoload.php"
cd "$project"
for relative in storage storage/framework storage/framework/cache storage/framework/cache/data; do
    test ! -L "$relative"
done
if test -d storage/framework/cache && test -n "$(find -P storage/framework/cache -type l -print -quit)"; then
    echo 'Cache contains symlinks; inspect them before repairing permissions.' >&2
    exit 1
fi
mkdir -p storage/framework/cache/data
for relative in storage storage/framework storage/framework/cache storage/framework/cache/data; do
    chown --no-dereference "$owner:$owner" "$relative"
    chmod u+rwx "$relative"
done
find -P storage/framework/cache -type d -exec chown --no-dereference "$owner:$owner" {} + -exec chmod u+rwx {} +
find -P storage/framework/cache -type f -exec chown --no-dereference "$owner:$owner" {} + -exec chmod u+rw {} +
runuser -u "$owner" -- php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = 'partner-cache-permissions-check:'.bin2hex(random_bytes(12));
$cache = Illuminate\Support\Facades\Cache::store();
$lock = $cache->lock($key.':lock', 10);
if (!$lock->get()) {
    throw new RuntimeException('Cache lock check failed.');
}
try {
    if (!$cache->put($key, 'ok', 20) || $cache->get($key) !== 'ok') {
        throw new RuntimeException('Cache write/read check failed.');
    }
    echo "PARTNER CACHE WRITE AND LOCK OK\n";
} finally {
    $cache->forget($key);
    $lock->release();
}
PHP
