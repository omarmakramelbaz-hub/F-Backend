#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(id -u)" -eq 0
test "$(hostname -s)" = srv1836524
activity_rev=${1:?Usage: run_root.sh COMMIT [check|apply|rollback BACKUP_PATH]}
shift
[[ "$activity_rev" =~ ^[a-f0-9]{40}$ ]]
activity_run=$(mktemp -d /var/tmp/order-live-activity.XXXXXXXX)
trap 'rm -rf -- "$activity_run"' EXIT
activity_base="https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$activity_rev/deployment/order_live_activity_20261112"
for activity_file in publish.py manifest.json; do
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --max-time 60 \
    "$activity_base/$activity_file" -o "$activity_run/$activity_file"
done
printf '%s  %s\n' \
  db40d90d4c2c0bfb78118344654076fecbda9de181e2e381a7135836222c0ad6 "$activity_run/publish.py" \
  480966ea0109f110d7297f0554489c6b10167d1acd3c7bf8b6a358f749b02d94 "$activity_run/manifest.json" | sha256sum --check
python3 - "$activity_run" "$activity_base" <<'PYLOAD'
import hashlib,json,re,sys,urllib.request
from pathlib import Path
root=Path(sys.argv[1])
manifest=json.loads((root/'manifest.json').read_text())
for name,spec in manifest.items():
    if not re.fullmatch(r'(app|database)/[A-Za-z0-9_/.-]+\.php', name) or '..' in Path(name).parts:
        raise SystemExit('INVALID_RELEASE_PATH')
    with urllib.request.urlopen(sys.argv[2]+'/payload/'+name, timeout=60) as response:
        data=response.read()
    if hashlib.sha256(data).hexdigest()!=spec['after']:
        raise SystemExit('PAYLOAD_HASH_MISMATCH')
    path=root/'payload'/name
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)
PYLOAD
python3 "$activity_run/publish.py" "${@:-apply}"
