#!/usr/bin/env bash
set -euo pipefail
umask 077

stock_revision=${1:?Full revision is required}
[[ "$stock_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'EXPECTED_HOST_REQUIRED'; exit 1; }
stock_root=/home/fasakha/public_html
stock_php=/opt/cpanel/ea-php82/root/usr/bin/php
stock_base=d99f094b6c23fd198757e5e4bb663b44f688d0f8
stock_source=deployment/apply_branch_stock_minimum.py
[[ -x "$stock_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$stock_root" cat-file -e "$stock_revision^{commit}"
if ! runuser -u fasakha -- git -C "$stock_root" cat-file -e "$stock_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$stock_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$stock_base"
fi
runuser -u fasakha -- git -C "$stock_root" merge-base --is-ancestor "$stock_base" "$stock_revision"
stock_blob=$(runuser -u fasakha -- git -C "$stock_root" rev-parse "$stock_revision:$stock_source")
[[ "$stock_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
stock_tmp=$(mktemp -d /tmp/branch-stock-minimum.XXXXXXXX)
trap 'rm -rf -- "$stock_tmp"' EXIT
chmod 0755 "$stock_tmp"
runuser -u fasakha -- git -C "$stock_root" show "$stock_revision:$stock_source" > "$stock_tmp/apply.py"
[[ $(git hash-object --no-filters "$stock_tmp/apply.py") == "$stock_blob" ]] || {
  printf '%s\n' 'STOCK_MINIMUM_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$stock_tmp/apply.py"
cat > "$stock_tmp/fasakhansta-stock-minimums.service" <<'UNIT'
[Unit]
Description=Fasakhansta branch stock minimum email outbox
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=fasakha
Group=fasakha
WorkingDirectory=/home/fasakha/public_html
ExecStart=/opt/cpanel/ea-php82/root/usr/bin/php artisan branch-stock:notify-minimum --no-interaction
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
TimeoutStartSec=180
UNIT
cat > "$stock_tmp/fasakhansta-stock-minimums.timer" <<'UNIT'
[Unit]
Description=Check Fasakhansta branch stock minimums every minute

[Timer]
OnActiveSec=60s
OnUnitInactiveSec=60s
AccuracySec=5s
Unit=fasakhansta-stock-minimums.service

[Install]
WantedBy=timers.target
UNIT
# Validate the exact dedicated unit names before changing application code.
# Existing, independently edited units are left for review.
python3 - "$stock_tmp" <<'PY'
import os
from pathlib import Path
import stat
import sys
temporary = Path(sys.argv[1])
if not Path('/run/systemd/system').is_dir():
    raise SystemExit('STOCK_MINIMUM_SYSTEMD_REQUIRED')
for suffix in ('service', 'timer'):
    name = 'fasakhansta-stock-minimums.' + suffix
    for location in ('/etc/systemd/system', '/run/systemd/system', '/usr/lib/systemd/system', '/lib/systemd/system'):
        if (Path(location) / (name + '.d')).exists():
            raise SystemExit('STOCK_MINIMUM_SYSTEMD_OVERRIDES_NEED_REVIEW: ' + name)
    if (Path('/run/systemd/system') / name).exists():
        raise SystemExit('STOCK_MINIMUM_SYSTEMD_RUNTIME_UNIT_NEEDS_REVIEW: ' + name)
    target = Path('/etc/systemd/system') / name
    for ancestor in (target, *target.parents):
        if ancestor.is_symlink():
            raise SystemExit('STOCK_MINIMUM_SYSTEMD_SYMLINK_REJECTED')
    try:
        info = target.stat()
    except FileNotFoundError:
        continue
    if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0
            or stat.S_IMODE(info.st_mode) & 0o022
            or target.read_bytes() != (temporary / name).read_bytes()):
        raise SystemExit('STOCK_MINIMUM_SYSTEMD_UNIT_NEEDS_REVIEW: ' + name)
PY
command -v systemctl >/dev/null
systemctl show --property=Version --value >/dev/null
stock_options=()
if [[ $# == 2 ]]; then stock_options+=(--check); fi
runuser -u fasakha -- python3 "$stock_tmp/apply.py" \
  --root "$stock_root" --revision "$stock_revision" --php "$stock_php" "${stock_options[@]}"
if [[ $# == 2 ]]; then
  printf '%s\n' 'STOCK_MINIMUM_SCHEDULER_PREFLIGHT_OK no_mail_sent=1'
  exit 0
fi
# Publish exact owned unit bytes atomically, then start only the timer.
# OnActiveSec gives installation a full minute and never invokes the mail job here.
python3 - "$stock_tmp" <<'PY'
import datetime
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
temporary = Path(sys.argv[1])
unit_root = Path('/etc/systemd/system')
created = []
receipt = {'status': 'prepared', 'new_units': [], 'mail_command_run_by_installer': False}
backup = Path(tempfile.mkdtemp(prefix='fasakhansta-stock-minimums-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-'), dir='/root'))
def record():
    (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
    (backup / 'receipt.json').chmod(0o600)
try:
    for suffix in ('service', 'timer'):
        name = 'fasakhansta-stock-minimums.' + suffix
        for location in ('/etc/systemd/system', '/run/systemd/system', '/usr/lib/systemd/system', '/lib/systemd/system'):
            if (Path(location) / (name + '.d')).exists():
                raise RuntimeError('SYSTEMD_OVERRIDES_CHANGED_DURING_INSTALL')
        if (Path('/run/systemd/system') / name).exists():
            raise RuntimeError('SYSTEMD_RUNTIME_UNIT_CHANGED_DURING_INSTALL')
        source = temporary / name
        target = unit_root / name
        data = source.read_bytes()
        for ancestor in (target, *target.parents):
            if ancestor.is_symlink():
                raise RuntimeError('SYSTEMD_SYMLINK_REJECTED')
        try:
            info = target.stat()
        except FileNotFoundError:
            info = None
        if info:
            if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0
                    or stat.S_IMODE(info.st_mode) & 0o022 or target.read_bytes() != data):
                raise RuntimeError('SYSTEMD_UNIT_CHANGED_DURING_INSTALL')
            continue
        descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), 0o644)
        created.append((target, data))
        receipt['new_units'].append(name)
    record()
    for command in (['systemctl', 'daemon-reload'],
                    ['systemctl', 'enable', '--now', 'fasakhansta-stock-minimums.timer'],
                    ['systemctl', 'is-active', '--quiet', 'fasakhansta-stock-minimums.timer'],
                    ['systemctl', 'is-enabled', '--quiet', 'fasakhansta-stock-minimums.timer']):
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=45)
        if result.returncode:
            raise RuntimeError('SYSTEMD_TIMER_ACTIVATION_FAILED')
    receipt['status'] = 'installed'
    record()
except BaseException:
    # Keep installed application code and reviewed units; receipts make a retry safe.
    receipt['status'] = 'scheduler_setup_failed_code_installed'
    record()
    print('STOCK_MINIMUM_SCHEDULER_SETUP_FAILED code_installed=1; RECEIPT=' + str(backup / 'receipt.json'), flush=True)
    raise SystemExit(1)
print('STOCK_MINIMUM_SCHEDULER_OK active=1 no_mail_sent_by_installer=1', flush=True)
PY
