#!/usr/bin/env bash
set -euo pipefail
umask 077

digest_revision=${1:?Full revision required}
[[ $# == 1 && "$digest_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]]
digest_base=/opt/fasakhansta-daily-report
digest_state=/home/fasakha/.local/state/fasakhansta-daily-report
digest_php=/opt/cpanel/ea-php82/root/usr/bin/php
digest_service=fasakhansta-daily-report.service
[[ -x "$digest_php" && -L "$digest_base/current" && -f /etc/fasakhansta-daily-report.json ]]
for digest_command in curl sha256sum python3 runuser flock systemctl getent; do command -v "$digest_command" >/dev/null; done
python3 - "$digest_base" "$digest_state" <<'PY_PATHS'
import os, stat, sys
base, state = sys.argv[1:]
for path in (base, base+'/releases', '/etc/fasakhansta-daily-report.json',
             '/etc/systemd/system/fasakhansta-daily-report.service.d',
             '/etc/systemd/system/fasakhansta-daily-report.service.d/smtp-egress.conf',
             '/run/fasakhansta-daily-report-install.lock'):
    if os.path.lexists(path):
        info = os.lstat(path)
        if stat.S_ISLNK(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise SystemExit('DAILY_REPORT_REPAIR_PATH_INVALID')
if os.path.islink(state) or not os.path.isdir(state) or os.path.islink(state+'/run.lock'):
    raise SystemExit('DAILY_REPORT_REPAIR_STATE_INVALID')
if not os.path.realpath(base+'/current').startswith(base+'/releases/'):
    raise SystemExit('DAILY_REPORT_REPAIR_RELEASE_INVALID')
PY_PATHS
exec {digest_install_fd}<>/run/fasakhansta-daily-report-install.lock
flock --nonblock "$digest_install_fd" || { printf '%s\n' 'DAILY_REPORT_INSTALLER_ALREADY_RUNNING'; exit 1; }
digest_stage=$(mktemp -d "$digest_base/.repair.XXXXXXXX")
chown root:fasakha "$digest_stage"
chmod 0750 "$digest_stage"
digest_resume=0
digest_cleanup() {
  digest_result=$?
  trap - EXIT
  if [[ ${digest_resume:-0} == 1 ]]; then
    if [[ -n ${digest_send_fd:-} ]]; then flock --unlock "$digest_send_fd" || true; exec {digest_send_fd}>&-; fi
    systemctl start --no-block "$digest_service" >/dev/null 2>&1 || true
  fi
  if [[ -n ${digest_stage:-} && -d "$digest_stage" ]]; then rm -rf -- "$digest_stage"; fi
  exit "$digest_result"
}
trap digest_cleanup EXIT

declare -A digest_hashes=(
  [report.php]=c0b87d41ba7290be0ea8152ab4125e49497ad84f5b9b4c761dc70076aa659491
  [template.php]=65b98df03f43a3582e02b7e3430fb7e4c7953e12b99d837f3dac6848f39ce317
  [test.php]=c939df64b4ed0a2e8dcc5753fe261f7d66730f635a898708cc649c35c6fdb977
  [transport_probe.php]=7a9b60f91dc592ecde2156793f9a2eae110ad867b6a881c110005ecdde1ac793
  [fasakhansta-daily-report.service]=46eae90e108ad213080ebec55778b6ac6ee1b3a6253c5315f6b5c532e1a4f3bb
  [fasakhansta-daily-report.timer]=1303c45d1402a61d267e043b26cc30cf29a1c784a38bb739f220bdaf0b7777b7
)
digest_files=(report.php template.php test.php transport_probe.php fasakhansta-daily-report.service fasakhansta-daily-report.timer)
for digest_file in "${digest_files[@]}"; do
  curl --fail --silent --show-error --location --retry 2 \
    --proto '=https' --proto-redir '=https' --connect-timeout 15 --max-time 60 \
    "https://raw.githubusercontent.com/omarmakramelbaz-hub/F-Backend/$digest_revision/deployment/branch_daily_digest/$digest_file" \
    -o "$digest_stage/$digest_file"
  printf '%s  %s\n' "${digest_hashes[$digest_file]}" "$digest_stage/$digest_file" | sha256sum --check --status
  chown root:fasakha "$digest_stage/$digest_file"
  chmod 0640 "$digest_stage/$digest_file"
done
for digest_file in report.php template.php test.php transport_probe.php; do
  runuser -u fasakha -- "$digest_php" -l "$digest_stage/$digest_file"
done
runuser -u fasakha -- "$digest_php" "$digest_stage/test.php"

# Wait for a real send to finish before pausing its retry. Pending snapshots and receipts stay intact.
[[ -f "$digest_state/run.lock" && ! -L "$digest_state/run.lock" ]]
exec {digest_send_fd}<>"$digest_state/run.lock"
flock --wait 60 "$digest_send_fd" || { printf '%s\n' 'DAILY_REPORT_BUSY_RETRY_AFTER_COMPLETION'; exit 1; }
digest_resume=1
systemctl stop "$digest_service"
digest_group=$(systemctl show "$digest_service" -p Group --value)
digest_group=${digest_group:-fasakha}
[[ "$digest_group" =~ ^[a-zA-Z0-9_-]+$ ]]
digest_selected_group=$digest_group
digest_ready=0

digest_probe() {
  local digest_probe_group=$1
  printf 'DAILY_REPORT_TESTING_SMTP group=%s no_mail_sent=1\n' "$digest_probe_group"
  local digest_probe_rc=0
  runuser -u fasakha -g "$digest_probe_group" -G fasakha -- \
    "$digest_php" -d display_errors=0 -d log_errors=0 "$digest_stage/transport_probe.php" \
    > "$digest_stage/probe.json" || digest_probe_rc=$?
  cat "$digest_stage/probe.json"
  return "$digest_probe_rc"
}

if digest_probe "$digest_group"; then
  digest_ready=1
elif [[ "$digest_group" != mail ]] && getent group mail >/dev/null; then
  # cPanel's SMTP restrictions permit the existing mail group. No firewall rule is changed.
  # Select it for this service only when verified TLS and SMTP authentication actually succeed.
  if digest_probe mail; then
    if digest_probe "$digest_group"; then
      digest_ready=1
    else
      digest_selected_group=mail
      digest_ready=1
    fi
  fi
fi

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
readlink "$digest_base/current" > "$digest_stage/previous-release"
install -o root -g root -m 0600 "$digest_stage/previous-release" "$digest_base/previous-release"
ln -s "releases/$digest_revision" "$digest_stage/current"
mv -fT -- "$digest_stage/current" "$digest_base/current"

if [[ "$digest_ready" == 1 && "$digest_selected_group" == mail && "$digest_group" != mail ]]; then
  digest_override=/etc/systemd/system/fasakhansta-daily-report.service.d
  install -d -o root -g root -m 0755 "$digest_override"
  if [[ -f "$digest_override/smtp-egress.conf" ]]; then
    cp -p -- "$digest_override/smtp-egress.conf" "$digest_base/previous-smtp-egress.conf"
  fi
  printf '%s\n' '[Service]' 'Group=mail' 'SupplementaryGroups=fasakha' > "$digest_stage/smtp-egress.conf"
  install -o root -g root -m 0644 "$digest_stage/smtp-egress.conf" "$digest_override/smtp-egress.conf"
  printf '%s\n' 'DAILY_REPORT_SMTP_ROUTE_REPAIRED service_only=1 firewall_changed=0'
fi
systemctl daemon-reload
systemctl reset-failed "$digest_service"
flock --unlock "$digest_send_fd"
exec {digest_send_fd}>&-
digest_resume=0

if [[ "$digest_ready" != 1 ]]; then
  systemctl start --no-block "$digest_service"
  printf '%s\n' 'DAILY_REPORT_SMTP_STILL_BLOCKED see_safe_probe_code_above pending_report_preserved=1'
  exit 2
fi
printf '%s\n' 'DAILY_REPORT_RESUMING_SAVED_REPORT'
if ! systemctl start "$digest_service"; then
  journalctl -u "$digest_service" -n 10 --no-pager -o cat
  printf '%s\n' 'DAILY_REPORT_DELIVERY_PENDING_RETRY'
  exit 1
fi
journalctl -u "$digest_service" -n 6 --no-pager -o cat
systemctl list-timers --all --no-pager fasakhansta-daily-report.timer
printf '%s\n' 'DAILY_REPORT_REPAIR_COMPLETE schedule_unchanged=1'
