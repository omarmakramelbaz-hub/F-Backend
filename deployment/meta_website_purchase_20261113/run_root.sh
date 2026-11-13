#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
test "$#" -ge 1
purchase_rev=$1
shift
[[ "$purchase_rev" =~ ^[a-f0-9]{40}$ ]]
if [[ "$#" -eq 0 ]]; then set -- check; fi
case "$1" in
  check|apply) test "$#" -eq 1 ;;
  rollback) test "$#" -eq 2 ;;
  *) exit 2 ;;
esac
purchase_stage=$(mktemp -d /var/tmp/meta-website-purchase.XXXXXXXX)
trap 'rm -rf -- "$purchase_stage"' EXIT
purchase_base="https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$purchase_rev/deployment/meta_website_purchase_20261113"
mkdir -p "$purchase_stage/tests"
for purchase_file in publish.py manifest.json tests/website_purchase_unit.php tests/website_checkout_hook_unit.php; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 60 \
    "$purchase_base/$purchase_file" -o "$purchase_stage/$purchase_file"
done
printf '%s  %s\n' \
  1dee7ebee2b814fcb3b656e1599b10c39fcc4decff632b32c3108f9e84569744 "$purchase_stage/publish.py" \
  697f323afe840de245dc617dccc8a67ea5abc09e38c029e37f25e9b8a88acd66 "$purchase_stage/manifest.json" \
  35315bb9ac5f726ee6ab6d850970217fa6c876dc28af1b93737310bca9d2ac4f "$purchase_stage/tests/website_purchase_unit.php" \
  1799d5026be6dcee798859ac6d27b4bd2b9bf1c46dcb7185f2073740fd890bd2 "$purchase_stage/tests/website_checkout_hook_unit.php" | sha256sum --check
python3 - "$purchase_stage" "$purchase_base" <<'PYLOAD'
import hashlib, json, sys, urllib.request
from pathlib import Path
root = Path(sys.argv[1])
allowed = {
    'app/Console/Commands/SendWebsiteMetaPurchases.php',
    'app/Services/Site/WebStorefront.php',
    'app/Services/Site/WebsiteMetaPurchase.php',
    'database/migrations/2026_11_13_200000_create_meta_website_purchases_table.php',
}
manifest = json.loads((root / 'manifest.json').read_text())
if not isinstance(manifest, dict) or set(manifest) != allowed:
    raise SystemExit('INVALID_RELEASE_PATHS')
for name, spec in manifest.items():
    with urllib.request.urlopen(sys.argv[2] + '/payload/' + name, timeout=60) as response:
        data = response.read()
    if hashlib.sha256(data).hexdigest() != spec['after']:
        raise SystemExit('PAYLOAD_HASH_MISMATCH')
    target = root / 'payload' / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(data)
# These files are public reviewed source, never production secrets.
root.chmod(0o755)
for path in root.rglob('*'):
    path.chmod(0o755 if path.is_dir() else 0o644)
PYLOAD
# Standalone tests use synthetic records and fake HTTP; they do not boot Laravel.
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php "$purchase_stage/tests/website_purchase_unit.php"
runuser -u fasakha -- /opt/cpanel/ea-php82/root/usr/bin/php "$purchase_stage/tests/website_checkout_hook_unit.php"
python3 "$purchase_stage/publish.py" "$@"
