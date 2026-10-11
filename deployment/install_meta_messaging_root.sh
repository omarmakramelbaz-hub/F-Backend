#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'RUN_AS_ROOT'; exit 1; }
meta_root=/home/fasakha/public_html
meta_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$meta_php" ]] || { echo 'APPLICATION_PHP_NOT_FOUND'; exit 1; }
meta_release="${1:?Full release SHA required}"
[[ "$meta_release" =~ ^[a-f0-9]{40}$ ]] || exit 1
# Check timer ownership before installing any application files.
meta_units=/etc/systemd/system
for meta_unit in fasakhansta-meta-messaging.service fasakhansta-meta-messaging.timer; do
    if [[ -e "$meta_units/$meta_unit" ]] && ! grep -q 'Fasakhansta native Meta messaging' "$meta_units/$meta_unit"; then
        echo 'EXISTING_UNIT_NOT_OWNED'; exit 1
    fi
done
meta_tmp="$(mktemp -d /tmp/meta-install.XXXXXX)"
trap 'rm -rf -- "$meta_tmp"' EXIT
chmod 755 "$meta_tmp"
runuser -u fasakha -- git -C "$meta_root" show "$meta_release:deployment/install_meta_messaging.py" > "$meta_tmp/install.py"
meta_blob="$(runuser -u fasakha -- git -C "$meta_root" rev-parse "$meta_release:deployment/install_meta_messaging.py")"
[[ "$(git hash-object "$meta_tmp/install.py")" == "$meta_blob" ]] || exit 1
runuser -u fasakha -- python3 "$meta_tmp/install.py" --root "$meta_root" --release "$meta_release" --php "$meta_php"
# Dedicated capture worker: never alters or restarts the existing WhatsApp worker.
cat > "$meta_units/fasakhansta-meta-messaging.service" <<EOF
[Unit]
Description=Fasakhansta native Meta messaging
After=network.target
[Service]
Type=oneshot
User=fasakha
Group=fasakha
WorkingDirectory=$meta_root
ExecStart=$meta_php artisan meta:process-messages --limit=10
TimeoutStartSec=600
UMask=0077
EOF
cat > "$meta_units/fasakhansta-meta-messaging.timer" <<'EOF'
[Unit]
Description=Fasakhansta native Meta messaging timer
[Timer]
OnBootSec=30
OnUnitInactiveSec=30
Unit=fasakhansta-meta-messaging.service
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now fasakhansta-meta-messaging.timer
printf '%s\n' 'META_CAPTURE_TIMER_READY' 'Next: configure account IDs and hidden access tokens on the server.'
