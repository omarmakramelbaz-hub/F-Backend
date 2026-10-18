#!/usr/bin/env python3
"""Pin the exact source payload used by the root entry point."""
from pathlib import Path
import subprocess


ROOT = Path(__file__).resolve().parents[1]
FILES = [
    'deployment/apply_dashboard_guarded_methods.py',
    'deployment/dashboard_method_tokens.php',
    'deployment/dashboard_guarded_methods.json',
    'app/Services/Dashboard/PosServiceTicket.php',
    'app/Services/Dashboard/PhoneDeliveryBoard.php',
    'app/Http/Controllers/Api/V1/Auth/AddressController.php',
    'app/Http/Controllers/Api/V1/Auth/UserController.php',
    'app/Http/Traits/SendNotification.php',
]
rows = '\n'.join(relative + ' ' + subprocess.check_output(
    ['git', 'hash-object', '--no-filters', str(ROOT / relative)]).decode().strip() for relative in FILES)
script = r'''#!/usr/bin/env bash
set -euo pipefail
umask 077

dashboard_revision=${1:?Full release revision is required}
[[ "$dashboard_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'ROOT_REQUIRED'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'EXPECTED_SERVER_SRV1836524_REQUIRED'; exit 1; }
dashboard_root=/home/fasakha/public_html
dashboard_php=/opt/cpanel/ea-php82/root/usr/bin/php
dashboard_uid=$(id -u fasakha)
[[ -d "$dashboard_root" && ! -L "$dashboard_root" && $(stat -c %u "$dashboard_root") == "$dashboard_uid" ]] || {
  printf '%s\n' 'EXPECTED_FASAKHA_APPLICATION_ROOT_REQUIRED'; exit 1;
}
[[ -x "$dashboard_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
runuser -u fasakha -- git -C "$dashboard_root" cat-file -e "$dashboard_revision^{commit}"

dashboard_payload=$(mktemp -d /tmp/dashboard-guarded-methods.XXXXXXXX)
trap 'rm -rf -- "$dashboard_payload"' EXIT
chmod 0755 "$dashboard_payload"
while read -r dashboard_file dashboard_blob; do
  install -d -m 0755 "$dashboard_payload/$(dirname "$dashboard_file")"
  runuser -u fasakha -- git -C "$dashboard_root" show "$dashboard_revision:$dashboard_file" > "$dashboard_payload/$dashboard_file"
  [[ $(git hash-object --no-filters "$dashboard_payload/$dashboard_file") == "$dashboard_blob" ]] || {
    printf '%s\n' 'RELEASE_PAYLOAD_BLOB_MISMATCH'; exit 1;
  }
  chmod 0644 "$dashboard_payload/$dashboard_file"
done <<'DASHBOARD_PINNED_FILES'
__PINNED_ROWS__
DASHBOARD_PINNED_FILES

dashboard_options=()
if [[ $# == 2 ]]; then dashboard_options+=(--check); fi
runuser -u fasakha -- python3 "$dashboard_payload/deployment/apply_dashboard_guarded_methods.py" \
  --root "$dashboard_root" --release-dir "$dashboard_payload" --php "$dashboard_php" \
  --release "$dashboard_revision" "${dashboard_options[@]}"
'''
destination = ROOT / 'deployment/apply_dashboard_guarded_methods_root.sh'
destination.write_text(script.replace('__PINNED_ROWS__', rows))
