#!/usr/bin/env bash
set -euo pipefail
umask 077

digest_revision=${1:?Full revision required}
digest_recipient=${2:?Recipient email required}
digest_mode=${3:---apply}
[[ $# -le 3 && "$digest_revision" =~ ^[0-9a-f]{40}$ ]]
[[ "$digest_mode" == --apply || "$digest_mode" == --check ]]
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]]
digest_root=/home/fasakha/public_html
digest_php=/opt/cpanel/ea-php82/root/usr/bin/php
digest_base=/opt/fasakhansta-daily-report
digest_state=/home/fasakha/.local/state/fasakhansta-daily-report
digest_service=fasakhansta-daily-report.service
digest_timer=fasakhansta-daily-report.timer
[[ -x "$digest_php" && -f "$digest_root/vendor/autoload.php" && -f "$digest_root/bootstrap/app.php" ]]
[[ -f /usr/share/zoneinfo/Africa/Cairo ]]
id fasakha >/dev/null
for digest_command in curl sha256sum python3 runuser flock systemctl systemd-analyze; do
  command -v "$digest_command" >/dev/null
done

python3 - "$digest_recipient" "$digest_base" <<'PY_VALIDATE'
import os, re, stat, sys
email, base = sys.argv[1:]
if not re.fullmatch(r'[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,63}', email):
    raise SystemExit('DAILY_REPORT_RECIPIENT_INVALID')
for path in (base, base+'/releases', '/etc/fasakhansta-daily-report.json',
             '/etc/systemd/system/fasakhansta-daily-report.service', '/etc/systemd/system/fasakhansta-daily-report.timer',
             '/run/fasakhansta-daily-report-install.lock'):
    if os.path.lexists(path):
        st = os.lstat(path)
        if stat.S_ISLNK(st.st_mode) or st.st_uid != 0 or st.st_mode & 0o022:
            raise SystemExit('DAILY_REPORT_INSTALL_PATH_INVALID')
for path in ('/home/fasakha', '/home/fasakha/.local', '/home/fasakha/.local/state', '/home/fasakha/.local/state/fasakhansta-daily-report'):
    if os.path.lexists(path) and (os.path.islink(path) or not os.path.isdir(path)):
        raise SystemExit('DAILY_REPORT_STATE_PATH_INVALID')
PY_VALIDATE

exec {digest_install_fd}<>/run/fasakhansta-daily-report-install.lock
chmod 0600 /run/fasakhansta-daily-report-install.lock
flock --nonblock "$digest_install_fd" || { printf '%s\n' 'DAILY_REPORT_INSTALLER_ALREADY_RUNNING'; exit 1; }
if systemctl is-active --quiet "$digest_service"; then
  printf '%s\n' 'DAILY_REPORT_BUSY_RETRY_AFTER_COMPLETION'
  exit 1
fi
install -d -o root -g fasakha -m 0750 "$digest_base" "$digest_base/releases"
digest_stage=$(mktemp -d "$digest_base/.stage.XXXXXXXX")
chown root:fasakha "$digest_stage"
chmod 0750 "$digest_stage"
digest_cleanup() {
  digest_result=$?
  trap - EXIT
  if [[ -n ${digest_stage:-} && -d "$digest_stage" ]]; then rm -rf -- "$digest_stage"; fi
  exit "$digest_result"
}
trap digest_cleanup EXIT

declare -A digest_hashes=(
  [report.php]=4bd0df72ba22563043109ed113bc62cee3453d0638460fb5f3264b4c1daa9644
  [template.php]=65b98df03f43a3582e02b7e3430fb7e4c7953e12b99d837f3dac6848f39ce317
  [test.php]=6ab15f49f25c7b2cc747287878d8ace1ffe9641d24e27267ea74d3ed4df82488
  [fasakhansta-daily-report.service]=46eae90e108ad213080ebec55778b6ac6ee1b3a6253c5315f6b5c532e1a4f3bb
  [fasakhansta-daily-report.timer]=1303c45d1402a61d267e043b26cc30cf29a1c784a38bb739f220bdaf0b7777b7
)
digest_files=(report.php template.php test.php fasakhansta-daily-report.service fasakhansta-daily-report.timer)
for digest_file in "${digest_files[@]}"; do
  curl --fail --silent --show-error --location --retry 2 \
    --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$digest_revision/deployment/branch_daily_digest/$digest_file" \
    -o "$digest_stage/$digest_file"
  printf '%s  %s\n' "${digest_hashes[$digest_file]}" "$digest_stage/$digest_file" | sha256sum --check --status
  chown root:fasakha "$digest_stage/$digest_file"
  chmod 0640 "$digest_stage/$digest_file"
done
python3 - "$digest_stage/config.json" "$digest_recipient" <<'PY_CONFIG'
import json, os, sys
with open(sys.argv[1], 'x', encoding='utf-8') as handle:
    json.dump({'format':1, 'recipient':sys.argv[2]}, handle, ensure_ascii=False)
    handle.write('\n')
os.chmod(sys.argv[1], 0o640)
PY_CONFIG
chown root:fasakha "$digest_stage/config.json"

for digest_file in report.php template.php test.php; do
  runuser -u fasakha -- "$digest_php" -l "$digest_stage/$digest_file"
done
runuser -u fasakha -- "$digest_php" "$digest_stage/test.php"
systemd-analyze calendar '*-*-* 00:00:00 Africa/Cairo' >/dev/null
printf '%s\n' 'DAILY_REPORT_CHECKING_LIVE_BRANCHES_AND_DRAWERS'
runuser -u fasakha -- "$digest_php" "$digest_stage/report.php" --check "$digest_stage/config.json"
if [[ "$digest_mode" == --check ]]; then
  printf '%s\n' 'DAILY_REPORT_PREFLIGHT_COMPLETE no_mail_sent=1 timer_changed=0'
  exit 0
fi

for digest_parent in /home/fasakha/.local /home/fasakha/.local/state; do
  if [[ ! -d "$digest_parent" ]]; then install -d -o fasakha -g fasakha -m 0700 "$digest_parent"; fi
done
install -d -o fasakha -g fasakha -m 0700 "$digest_state"

# Retain only this feature's previous configuration and unit files for recovery.
digest_backup=$(mktemp -d "$digest_base/previous.XXXXXXXX")
chmod 0700 "$digest_backup"
for digest_path in /etc/fasakhansta-daily-report.json \
  /etc/systemd/system/fasakhansta-daily-report.service /etc/systemd/system/fasakhansta-daily-report.timer; do
  if [[ -f "$digest_path" ]]; then cp -p -- "$digest_path" "$digest_backup/"; fi
done
if [[ -L "$digest_base/current" ]]; then readlink "$digest_base/current" > "$digest_backup/previous-release"; fi

digest_release="$digest_base/releases/$digest_revision"
if [[ -e "$digest_release" || -L "$digest_release" ]]; then
  [[ -d "$digest_release" && ! -L "$digest_release" ]]
  for digest_file in "${digest_files[@]}"; do
    [[ ! -L "$digest_release/$digest_file" ]]
    printf '%s  %s\n' "${digest_hashes[$digest_file]}" "$digest_release/$digest_file" | sha256sum --check --status
  done
else
  install -d -o root -g fasakha -m 0750 "$digest_release"
  for digest_file in "${digest_files[@]}"; do
    install -o root -g fasakha -m 0640 "$digest_stage/$digest_file" "$digest_release/$digest_file"
  done
fi

digest_config_temp=$(mktemp /etc/.fasakhansta-daily-report.XXXXXXXX)
install -o root -g fasakha -m 0640 "$digest_stage/config.json" "$digest_config_temp"
mv -fT -- "$digest_config_temp" /etc/fasakhansta-daily-report.json
for digest_file in "$digest_service" "$digest_timer"; do
  install -o root -g root -m 0644 "$digest_release/$digest_file" "/etc/systemd/system/$digest_file"
done
ln -s "releases/$digest_revision" "$digest_stage/current"
mv -fT -- "$digest_stage/current" "$digest_base/current"
systemctl daemon-reload
systemctl enable --now "$digest_timer"
printf '%s\n' 'DAILY_REPORT_SENDING_FIRST_REPORT'
if ! systemctl start "$digest_service"; then
  journalctl -u "$digest_service" -n 8 --no-pager -o cat
  printf '%s\n' 'DAILY_REPORT_INSTALLED_DELIVERY_PENDING_RETRY every_minutes=5' >&2
  exit 1
fi
systemctl is-enabled --quiet "$digest_timer"
systemctl is-active --quiet "$digest_timer"
journalctl -u "$digest_service" -n 5 --no-pager -o cat
systemctl list-timers --all --no-pager "$digest_timer"
printf '%s\n' 'DAILY_REPORT_INSTALLED timezone=Africa/Cairo schedule=00:00 branches_only=1'
