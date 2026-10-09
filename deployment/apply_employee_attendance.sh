#!/usr/bin/env bash
set -euo pipefail
# Run as the application owner in its existing checkout with the reviewed release SHA.
release="${1:?Pass the reviewed 40-character attendance release SHA}"
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA'; exit 1; }
cd "$(git rev-parse --show-toplevel)"
test -f artisan
test -f vendor/autoload.php
test -f .env
test -z "$(git status --porcelain --untracked-files=no)" || { echo 'Save local tracked changes before applying this update.'; exit 1; }
git fetch --no-tags origin refs/heads/codex/employee-attendance-rules-20261008
test "$(git rev-parse FETCH_HEAD)" = "$release" || { echo 'Release changed; review its new SHA.'; exit 1; }
previous="$(git rev-parse HEAD)"
git merge-base --is-ancestor "$previous" "$release" || { echo 'Server version diverged; no code changed. Reconcile this release with the server branch first.'; exit 1; }
backup="backup/before-attendance-$(date -u +%Y%m%dT%H%M%SZ)-${previous:0:8}"
git branch "$backup" "$previous"
echo "Saved code checkpoint: $backup ($previous)"
git merge --ff-only "$release"
php -l app/Services/Dashboard/BranchPayroll.php
php -l app/Services/Dashboard/EmployeeAttendanceRules.php
php -l app/Http/Controllers/Dashboard/BranchOperationsController.php
php -l database/migrations/2026_10_08_190000_add_employee_attendance_rules.php
php artisan migrate --force --path=database/migrations/2026_10_08_190000_add_employee_attendance_rules.php
php artisan route:clear
php artisan view:clear
php artisan tinker --execute='if (!\Illuminate\Support\Facades\Schema::hasTable("branch_attendance_rules") || !\Illuminate\Support\Facades\Schema::hasColumn("branch_employee_entries", "source_key")) { throw new \RuntimeException("Attendance schema missing"); } echo "EMPLOYEE ATTENDANCE READY".PHP_EOL;'
echo 'Open Employees as Owner, choose the branch, and save morning/evening times and deduction rates.'
