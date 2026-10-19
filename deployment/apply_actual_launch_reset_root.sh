#!/usr/bin/env bash
# Owner launch reset, owner-only bonuses, admin employee profiles and shared customers.
set -euo pipefail
umask 077
launch_revision=${1:?Full pinned revision required}
[[ $# = 1 && "$launch_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $(id -u) = 0 && $(hostname -s) = srv1836524 ]]
launch_root=/home/fasakha/public_html
launch_php=/opt/cpanel/ea-php82/root/usr/bin/php
launch_parent=/home/fasakha/actual-launch-reset-backups
[[ -x "$launch_php" && -d "$launch_root" && ! -L "$launch_root" ]]
[[ $(realpath -- "$launch_root") = "$launch_root" ]]
[[ $(stat -c '%u' -- "$launch_root") = "$(id -u fasakha)" ]]
command -v python3 >/dev/null
command -v flock >/dev/null
if [[ ! -e "$launch_parent" ]]; then install -d -o fasakha -g fasakha -m 0700 "$launch_parent"; fi
[[ -d "$launch_parent" && ! -L "$launch_parent" && $(realpath -- "$launch_parent") = "$launch_parent" ]]
[[ $(stat -c '%u:%a' -- "$launch_parent") = "$(id -u fasakha):700" ]]
[[ ! -L "$launch_parent/install.lock" ]]
exec 9>"$launch_parent/install.lock"
flock -n 9 || { printf '%s\n' 'ANOTHER_LAUNCH_RESET_IS_RUNNING'; exit 1; }
[[ ! -e "$launch_parent/committed.json" ]] || { printf '%s\n' 'RESET_ALREADY_COMMITTED_NO_SECOND_RESET'; exit 1; }
[[ ! -e "$launch_root/storage/framework/down" ]] || { printf '%s\n' 'PREEXISTING_MAINTENANCE_PRESERVED'; exit 1; }
launch_backup=$(mktemp -d "$launch_parent/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
chown fasakha:fasakha "$launch_backup"
launch_run=$(basename -- "$launch_backup")
launch_tmp=$(mktemp -d /tmp/actual-launch-reset.XXXXXXXX)
chmod 0755 "$launch_tmp"
launch_down=0
launch_reset_started=0
launch_worker_state="$launch_tmp/worker-state.json"
launch_cleanup() {
  local launch_exit=$?
  local launch_retain=0
  trap - EXIT
  trap '' INT TERM HUP
  if [[ "$launch_down" = 1 ]]; then
    if [[ "$launch_reset_started" = 0 || -f "$launch_backup/safe-to-reopen" ]]; then
      if runuser -u fasakha -- "$launch_php" "$launch_root/artisan" up --no-interaction; then
        printf '%s\n' 'APPLICATION_REOPENED'
        if [[ -f "$launch_worker_state" ]]; then
          if ! python3 "$launch_tmp/worker_control.py" --root "$launch_root" --php "$launch_php" \
            --state "$launch_worker_state" --restore; then
            printf '%s\n' 'WORKER_RESTORE_REVIEW_REQUIRED'
            launch_retain=1
            launch_exit=1
          fi
        fi
      else
        printf '%s\n' 'APPLICATION_UP_FAILED_MAINTENANCE_MAY_REMAIN'
        launch_retain=1
        launch_exit=1
      fi
    else
      printf '%s\n' 'MAINTENANCE_RETAINED_REVIEW_REQUIRED'
      launch_retain=1
      launch_exit=1
    fi
  fi
  printf 'BACKUP_DIRECTORY=%s\n' "$launch_backup"
  if [[ "$launch_retain" = 1 && -f "$launch_worker_state" ]]; then
    printf 'WORKER_RECOVERY_DIRECTORY=%s\n' "$launch_tmp"
  else
    rm -rf -- "$launch_tmp"
  fi
  exit "$launch_exit"
}
trap launch_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
for launch_name in reset.php inspected_schema.json bonus_owner_patch.py customer_global_patch.py employee_profile_patch.py worker_control.py quiesce_workers.py queue_manager.py systemd_queue.py check_schema.php; do
  launch_source="deployment/launch_reset/$launch_name"
  launch_blob=$(runuser -u fasakha -- git -C "$launch_root" rev-parse "$launch_revision:$launch_source")
  [[ "$launch_blob" =~ ^[0-9a-f]{40}$ ]]
  runuser -u fasakha -- git -C "$launch_root" show "$launch_revision:$launch_source" > "$launch_tmp/$launch_name"
  [[ $(git hash-object --no-filters "$launch_tmp/$launch_name") = "$launch_blob" ]]
  chmod 0644 "$launch_tmp/$launch_name"
done
runuser -u fasakha -- "$launch_php" -l "$launch_tmp/reset.php"
runuser -u fasakha -- "$launch_php" -l "$launch_tmp/check_schema.php"
# Confirm the complete reviewed schema while the site and its workers stay live.
runuser -u fasakha -- "$launch_php" "$launch_tmp/check_schema.php" --strict "$launch_root"
# Validate every live source transform before taking the application offline.
runuser -u fasakha -- python3 "$launch_tmp/bonus_owner_patch.py" \
  --root "$launch_root" --php "$launch_php" --check
runuser -u fasakha -- python3 "$launch_tmp/customer_global_patch.py" \
  --root "$launch_root" --php "$launch_php" --check
runuser -u fasakha -- python3 "$launch_tmp/employee_profile_patch.py" \
  --root "$launch_root" --php "$launch_php" --check
python3 "$launch_tmp/worker_control.py" --root "$launch_root" --php "$launch_php" --check
printf '%s\n' 'ALL_LIVE_SOURCE_PREFLIGHTS_PASSED'
# The attendance worker honors this existing shared/exclusive installer lock.
launch_absence_lock=/home/fasakha/employee-auto-absence-backups/install.lock
if [[ -e "$launch_absence_lock" ]]; then
  [[ -f "$launch_absence_lock" && ! -L "$launch_absence_lock" ]]
  exec 8<"$launch_absence_lock"
  flock -x -w 15 8 || { printf '%s\n' 'ATTENDANCE_WORKER_BUSY_NO_RESET'; exit 1; }
fi
printf '%s\n' 'ENTERING_MAINTENANCE_FOR_OWNER_LAUNCH_RESET'
runuser -u fasakha -- "$launch_php" "$launch_root/artisan" down --retry=60 --no-interaction
launch_down=1
python3 "$launch_tmp/worker_control.py" --root "$launch_root" --php "$launch_php" \
  --state "$launch_worker_state" --pause
runuser -u fasakha -- python3 "$launch_tmp/bonus_owner_patch.py" \
  --root "$launch_root" --backup "$launch_backup/bonus-source" --php "$launch_php"
runuser -u fasakha -- python3 "$launch_tmp/customer_global_patch.py" \
  --root "$launch_root" --backup "$launch_backup/customer-source" --php "$launch_php"
runuser -u fasakha -- python3 "$launch_tmp/employee_profile_patch.py" \
  --root "$launch_root" --backup "$launch_backup/employee-source" --php "$launch_php"
runuser -u fasakha -- "$launch_php" "$launch_root/artisan" view:clear --no-interaction
launch_reset_started=1
runuser -u fasakha -- "$launch_php" "$launch_tmp/reset.php" --apply "$launch_root" "$launch_backup" "$launch_run"
printf '%s\n' 'OWNER_BONUSES_ADMIN_EMPLOYEE_PROFILES_GLOBAL_CUSTOMERS_INSTALLED'
