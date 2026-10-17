#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
crm_root=/home/fasakha/public_html
crm_php=/opt/cpanel/ea-php82/root/usr/bin/php
crm_tmp=$(mktemp -d /tmp/customer-crm-release.XXXXXXXX)
trap 'rm -rf -- "$crm_tmp"' EXIT
chmod 0755 "$crm_tmp"
runuser -u fasakha -- git -C "$crm_root" show "$revision:deployment/apply_customer_crm.py" > "$crm_tmp/apply.py"
chmod 0644 "$crm_tmp/apply.py"
runuser -u fasakha -- python3 "$crm_tmp/apply.py" --root "$crm_root" --revision "$revision" --php "$crm_php"
# Timers only run the fixed, installed commands under the application account.
cat > "$crm_tmp/customer-crm.service" <<'UNIT'
[Unit]
Description=Fasakhansta customer directory and WhatsApp outbox
After=network-online.target
[Service]
Type=oneshot
User=fasakha
Group=fasakha
WorkingDirectory=/home/fasakha/public_html
ExecStart=/opt/cpanel/ea-php82/root/usr/bin/php artisan crm:customers-sync
ExecStart=/opt/cpanel/ea-php82/root/usr/bin/php artisan crm:whatsapp-process
TimeoutStartSec=180
UMask=0077
UNIT
cat > "$crm_tmp/customer-crm.timer" <<'UNIT'
[Unit]
Description=Check customer followups every minute
[Timer]
OnBootSec=60s
OnUnitInactiveSec=60s
AccuracySec=5s
Persistent=true
[Install]
WantedBy=timers.target
UNIT
install -m 0644 "$crm_tmp/customer-crm.service" /etc/systemd/system/fasakhansta-customer-crm.service
install -m 0644 "$crm_tmp/customer-crm.timer" /etc/systemd/system/fasakhansta-customer-crm.timer
systemctl daemon-reload
systemctl enable --now fasakhansta-customer-crm.timer
systemctl is-active fasakhansta-customer-crm.timer
printf '%s\n' 'CUSTOMER_CRM_TIMER_ACTIVE'
