#!/usr/bin/env bash
set -euo pipefail
# Run as the application owner in its existing checkout with the reviewed release SHA.
release="${1:?Pass the reviewed 40-character attendance release SHA}"
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA'; exit 1; }
cd "$(git rev-parse --show-toplevel)"
test -f artisan
test -f vendor/autoload.php
test -f .env
source_status="$(git status --porcelain --untracked-files=no)"
test -z "$source_status" || { echo 'Save local tracked changes before applying this update.'; exit 1; }
git fetch --no-tags --no-prune --no-recurse-submodules --refmap= origin refs/heads/codex/employee-attendance-live-20261008
test "$(git rev-parse FETCH_HEAD)" = "$release" || { echo 'Release changed; review its new SHA.'; exit 1; }
previous="$(git rev-parse HEAD)"
git merge-base --is-ancestor "$previous" "$release" || { echo 'Server version diverged; no code changed. Reconcile this release with the server branch first.'; exit 1; }
test "$previous" = cedd72355a853d99ea0152f0e4091e3b59716126 || test "$previous" = 845f3b73fd0263d7af034e764287fdb6e30f11da || test "$previous" = "$release" || { echo 'Server checkpoint changed; no code changed.'; exit 1; }
while IFS= read -r -d '' file; do
    test ! -L "$file" || { echo "Source symlink requires review: $file"; exit 1; }
    if test -e "$file"; then
        git ls-files --error-unmatch -- "$file" >/dev/null 2>&1 || { echo "Untracked source collision: $file"; exit 1; }
        test -w "$file" || { echo "Source is not writable: $file"; exit 1; }
    fi
    parent="$(dirname "$file")"
    while ! test -e "$parent"; do parent="$(dirname "$parent")"; done
    test -d "$parent" && test -w "$parent" || { echo "Source directory is not writable: $parent"; exit 1; }
done < <(git diff --name-only -z "$previous" "$release")
php artisan tinker --execute='foreach (["branch_employees", "branch_employee_days", "branch_employee_entries", "branch_employee_salaries", "branch_payrolls", "branch_operation_commands"] as $table) { if (!\Illuminate\Support\Facades\Schema::hasTable($table)) { throw new \RuntimeException("Existing payroll schema missing: ".$table); } }'
backup="backup/before-attendance-$(date -u +%Y%m%dT%H%M%S%NZ)-${previous:0:8}"
git branch "$backup" "$previous"
echo "Saved code checkpoint: $backup ($previous)"
# Apply the additive migration while the original employee page is still deployed.
migration_directory="$(mktemp -d)"
trap 'rm -rf -- "$migration_directory"' EXIT
migration=2026_10_08_190000_add_employee_attendance_rules.php
git show "$release:database/migrations/$migration" > "$migration_directory/$migration"
php -l "$migration_directory/$migration"
php artisan migrate --force --realpath --path="$migration_directory/$migration"
git merge --ff-only "$release"
php -l app/Services/Dashboard/BranchPayroll.php
php -l app/Services/Dashboard/EmployeeAttendanceRules.php
php -l app/Http/Controllers/Dashboard/BranchOperationsController.php
php -l database/migrations/2026_10_08_190000_add_employee_attendance_rules.php
php artisan route:clear
php artisan view:clear
php artisan tinker --execute='if (!\Illuminate\Support\Facades\Schema::hasTable("branch_attendance_rules") || !\Illuminate\Support\Facades\Schema::hasColumn("branch_employee_entries", "source_key") || !\Illuminate\Support\Facades\Schema::hasColumn("branch_employee_days", "checked_in_at") || !\Illuminate\Support\Facades\Schema::hasColumn("branch_employee_days", "checked_out_at") || !\Illuminate\Support\Facades\Schema::hasColumn("branch_employee_days", "attendance_rule_snapshot") || !\Illuminate\Support\Facades\Route::has("employees.attendance-rules")) { throw new \RuntimeException("Attendance schema or route missing"); } echo "EMPLOYEE ATTENDANCE READY".PHP_EOL;'
echo 'Open Employees as Owner, choose the branch, and save morning/evening times and deduction rates.'
