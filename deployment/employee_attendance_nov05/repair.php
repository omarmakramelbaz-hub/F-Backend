<?php
declare(strict_types=1);

use App\Services\Dashboard\EmployeeAttendanceRules;
use Carbon\Carbon;

/** One-off administrative correction. It never changes salaries, cash, or paid payrolls. */
function goHrNov05Repair($db, array $branches, string $backupDirectory): array
{
    $from = '2026-11-01';
    $through = '2026-11-05';
    $reason = 'تصحيح إداري بطلب الأونر: إلغاء الخصومات واعتماد الحضور في موعد الوردية من 1 إلى 5 نوفمبر 2026 شاملًا.';
    if (!$branches || count(array_unique($branches)) !== count($branches)) {
        throw new RuntimeException('GO_HR_BRANCH_SCOPE_INVALID');
    }
    foreach ($branches as $branch) {
        if (!preg_match('/^f:([1-9][0-9]*)$/D', $branch)) {
            throw new RuntimeException('GO_HR_PRIMARY_BRANCHES_ONLY');
        }
    }
    if (!is_dir($backupDirectory) || is_link($backupDirectory) || !is_writable($backupDirectory)) {
        throw new RuntimeException('GO_HR_BACKUP_DIRECTORY_INVALID');
    }
    sort($branches, SORT_STRING);
    return $db->transaction(function () use ($db, $branches, $backupDirectory, $from, $through, $reason) {
        // Follow the branch -> employee -> attendance/ledger lock order of ordinary writes.
        foreach ($branches as $branch) {
            if (!$db->table('resturants')->where('id', substr($branch, 2))->lockForUpdate()->first()) {
                throw new RuntimeException('GO_HR_BRANCH_MISSING');
            }
        }
        $employees = $db->table('branch_employees')->whereIn('branch', $branches)->orderBy('id')->lockForUpdate()->get();
        if ($employees->isEmpty()) {
            throw new RuntimeException('GO_HR_NO_EMPLOYEES');
        }
        $ids = $employees->pluck('id')->all();
        // Closed snapshots are immutable and would hide corrections from payroll reports.
        if ($db->table('branch_payrolls')->whereIn('employee_id', $ids)->where('month', '2026-11')->lockForUpdate()->exists()) {
            throw new RuntimeException('GO_HR_NOVEMBER_PAYROLL_ALREADY_CLOSED_NO_CHANGES');
        }
        $rules = $db->table('branch_attendance_rules')->whereIn('branch', $branches)->orderBy('id')->lockForUpdate()->get();
        $ruleMap = [];
        foreach ($rules as $rule) {
            $ruleMap[$rule->branch][$rule->shift] = $rule;
        }
        $days = $db->table('branch_employee_days')->whereIn('employee_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $entries = $db->table('branch_employee_entries')->whereIn('employee_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $oldDays = [];
        foreach ($days as $row) {
            $oldDays[$row->employee_id][$row->day] = $row;
        }
        $plan = [];
        foreach ($employees as $employee) {
            foreach (['hired_on', 'left_on'] as $key) {
                if ($employee->$key !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $employee->$key)) {
                    throw new RuntimeException('GO_HR_EMPLOYMENT_DATES_INVALID');
                }
            }
            if (!$employee->hired_on || ($employee->left_on && $employee->left_on < $employee->hired_on)) {
                throw new RuntimeException('GO_HR_EMPLOYMENT_DATES_INVALID');
            }
            for ($day = $from; $day <= $through; $day = Carbon::parse($day, 'Africa/Cairo')->addDay()->toDateString()) {
                if ($day < $employee->hired_on || ($employee->left_on && $day > $employee->left_on)) {
                    continue;
                }
                // Include former employees only for days inside their documented employment.
                if (!(bool)$employee->active && !$employee->left_on) {
                    continue;
                }
                $old = $oldDays[$employee->id][$day] ?? null;
                if ($old && $old->branch !== $employee->branch) {
                    throw new RuntimeException('GO_HR_ATTENDANCE_BRANCH_MISMATCH');
                }
                $partTime = ($employee->schedule_type ?? 'branch') === 'part_time';
                $shift = $partTime ? ($employee->attendance_shift ?? '')
                    : (in_array($employee->shift, ['evening', 'مسائي', 'مساء'], true) ? 'evening' : 'morning');
                $status = $partTime ? 'part_time' : $shift;
                $snapshot = $old ? json_decode($old->attendance_rule_snapshot ?? '', true) : null;
                // Historical scheduled times take precedence over later changes in branch settings.
                if ($old && in_array($old->status, ['morning', 'evening', 'part_time'], true)
                    && (($old->status === 'part_time') === $partTime)
                    && is_array($snapshot) && ($snapshot['shift'] ?? null) === $old->status
                    && isset($snapshot['scheduled_start'], $snapshot['scheduled_end'], $snapshot['absence_cents'],
                        $snapshot['late_half_hour_cents'], $snapshot['early_half_hour_cents'])) {
                    $status = $old->status;
                } else {
                    if (!in_array($shift, ['morning', 'evening'], true) || !isset($ruleMap[$employee->branch][$shift])) {
                        throw new RuntimeException('GO_HR_MISSING_SCHEDULE_EMPLOYEE_' . $employee->id);
                    }
                    if ($partTime && (empty($employee->attendance_start) || empty($employee->attendance_end))) {
                        throw new RuntimeException('GO_HR_MISSING_PART_TIME_SCHEDULE_EMPLOYEE_' . $employee->id);
                    }
                    $rule = $ruleMap[$employee->branch][$shift];
                    $starts = $partTime ? $employee->attendance_start : $rule->starts_at;
                    $ends = $partTime ? $employee->attendance_end : $rule->ends_at;
                    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $starts)
                        || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $ends)
                        || substr($starts, 0, 5) === substr($ends, 0, 5)) {
                        throw new RuntimeException('GO_HR_INVALID_SCHEDULE_EMPLOYEE_' . $employee->id);
                    }
                    $snapshot = EmployeeAttendanceRules::forEmployee($ruleMap[$employee->branch][$shift], $employee, $day);
                }
                foreach (['scheduled_start', 'scheduled_end'] as $key) {
                    $value = $snapshot[$key];
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)
                        || Carbon::parse($value, 'UTC')->toDateTimeString() !== $value) {
                        throw new RuntimeException('GO_HR_INVALID_SCHEDULE_EMPLOYEE_' . $employee->id);
                    }
                }
                $in = Carbon::parse($snapshot['scheduled_start'], 'UTC');
                $out = Carbon::parse($snapshot['scheduled_end'], 'UTC');
                $localIn = $in->copy()->setTimezone('Africa/Cairo');
                $operatingDate = $localIn->copy();
                if ($operatingDate->hour < 6) $operatingDate->subDay();
                if ($out->lessThanOrEqualTo($in) || $in->diffInSeconds($out) > 86400 || $operatingDate->toDateString() !== $day) {
                    throw new RuntimeException('GO_HR_INVALID_SCHEDULE_EMPLOYEE_' . $employee->id);
                }
                foreach (EmployeeAttendanceRules::deductions($status, $snapshot, $snapshot['scheduled_start'], $snapshot['scheduled_end'], 0, 30) as $deduction) {
                    if ($deduction['amount'] !== 0) throw new RuntimeException('GO_HR_SCHEDULE_STILL_HAS_PENALTY');
                }
                $plan[] = ['old' => $old, 'employee_id' => $employee->id, 'day' => $day, 'data' => [
                    'branch' => $employee->branch, 'status' => $status,
                    'checked_in_at' => $snapshot['scheduled_start'], 'checked_out_at' => $snapshot['scheduled_end'],
                    'check_in' => $localIn->format('H:i:s'), 'check_out' => $out->copy()->setTimezone('Africa/Cairo')->format('H:i:s'),
                    'attendance_rule_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]];
            }
        }
        if (!$plan) throw new RuntimeException('GO_HR_NO_ELIGIBLE_ATTENDANCE');
        $targetEntries = $entries->filter(static fn($row) => $row->kind === 'deduction' && $row->day >= $from && $row->day <= $through && !$row->voided_at);
        foreach ($targetEntries as $row) {
            $employee = $employees->firstWhere('id', $row->employee_id);
            if ($row->branch !== $employee->branch) throw new RuntimeException('GO_HR_LEDGER_BRANCH_MISMATCH');
        }
        $planKeys = [];
        foreach ($plan as $item) $planKeys[$item['employee_id'].'/'.$item['day']] = true;
        $untouchedDays = $days->filter(static fn($row) => !isset($planKeys[$row->employee_id.'/'.$row->day]))->values()->all();
        $targetIds = $targetEntries->pluck('id')->all();
        $untouchedEntries = $entries->filter(static fn($row) => !in_array($row->id, $targetIds, true))->values()->all();
        $backup = $backupDirectory.'/before-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.json';
        $payload = json_encode(['version' => 1, 'from' => $from, 'through' => $through, 'branches' => $branches,
            'database' => $db->getDatabaseName(), 'reason' => $reason, 'employees' => $employees->all(),
            'attendance_before' => $days->filter(static fn($row) => isset($planKeys[$row->employee_id.'/'.$row->day]))->values()->all(),
            'deductions_before' => $targetEntries->values()->all(),
            'attendance_keys' => array_keys($planKeys)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $handle = fopen($backup, 'xb');
        if ($handle === false) throw new RuntimeException('GO_HR_BACKUP_OPEN_FAILED');
        try {
            if (!chmod($backup, 0600) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('GO_HR_BACKUP_WRITE_FAILED');
        } finally { fclose($handle); }
        if (!hash_equals(hash('sha256', $payload), hash_file('sha256', $backup))) throw new RuntimeException('GO_HR_BACKUP_VERIFY_FAILED');
        $now = gmdate('Y-m-d H:i:s');
        $changed = 0;
        foreach ($plan as $item) {
            $old = $item['old']; $data = $item['data'];
            $same = $old !== null;
            foreach ($data as $key => $value) if (!$old || $old->$key !== $value) $same = false;
            if ($same) continue;
            $notes = (string)($old->notes ?? '');
            if (!str_contains($notes, $reason)) $notes = trim($notes."\n".$reason);
            $data += ['notes' => $notes, 'revision' => $old ? (int)$old->revision + 1 : 1, 'actor_id' => 1, 'updated_at' => $now];
            if ($old) $db->table('branch_employee_days')->where('id', $old->id)->update($data);
            else $db->table('branch_employee_days')->insert($data + ['employee_id' => $item['employee_id'], 'day' => $item['day'], 'created_at' => $now]);
            $changed++;
        }
        foreach ($targetEntries as $entry) {
            // Explicit cancellation retains the audit record and cannot be revived by another punch.
            $db->table('branch_employee_entries')->where('id', $entry->id)->update([
                'voided_at' => $now, 'voided_by' => 1, 'void_reason' => $reason,
                'revision' => (int)$entry->revision + 1, 'updated_at' => $now,
            ]);
        }
        $freshDays = $db->table('branch_employee_days')->whereIn('employee_id', $ids)->orderBy('id')->get();
        $freshEntries = $db->table('branch_employee_entries')->whereIn('employee_id', $ids)->orderBy('id')->get();
        foreach ($plan as $item) {
            $row = $freshDays->first(static fn($row) => $row->employee_id == $item['employee_id'] && $row->day === $item['day']);
            foreach ($item['data'] as $key => $value) if (!$row || $row->$key !== $value) throw new RuntimeException('GO_HR_ATTENDANCE_VERIFY_FAILED');
        }
        if ($freshEntries->contains(static fn($row) => $row->kind === 'deduction' && $row->day >= $from && $row->day <= $through && !$row->voided_at)) {
            throw new RuntimeException('GO_HR_DEDUCTIONS_VERIFY_FAILED');
        }
        if (serialize($untouchedDays) !== serialize($freshDays->filter(static fn($row) => !isset($planKeys[$row->employee_id.'/'.$row->day]))->values()->all())
            || serialize($untouchedEntries) !== serialize($freshEntries->filter(static fn($row) => !in_array($row->id, $targetIds, true))->values()->all())) {
            throw new RuntimeException('GO_HR_UNRELATED_DATA_CHANGED');
        }
        return ['status' => 'GO_HR_NOV05_APPLIED', 'from' => $from, 'through' => $through,
            'employees' => $employees->count(), 'attendance_verified' => count($plan), 'attendance_changed' => $changed,
            'deductions_cancelled' => count($targetIds), 'remaining_deductions' => 0, 'backup' => $backup];
    });
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || $argc !== 2 || $argv[1] !== '--apply') throw new RuntimeException('GO_HR_ARGUMENTS');
    $root = '/home/fasakha/public_html';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('desktop_dashboard.local') === true
        || (class_exists(\App\Services\OwnerDashboards\Runtime::class) && \App\Services\OwnerDashboards\Runtime::tenant())) {
        throw new RuntimeException('GO_HR_PRIMARY_SERVER_ONLY');
    }
    $actor = \App\Models\User::withoutGlobalScopes()->find(1);
    if (!$actor || $actor->account_type !== 'admin' || $actor->status !== 'accepted' || !empty($actor->owner_resturant_id)) throw new RuntimeException('GO_HR_OWNER_INVALID');
    $db = $app->make('db')->connection();
    if ($db->getDriverName() !== 'mysql') throw new RuntimeException('GO_HR_MYSQL_REQUIRED');
    foreach (['resturants', 'branch_employees', 'branch_employee_days', 'branch_employee_entries', 'branch_attendance_rules', 'branch_payrolls'] as $table) {
        $meta = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$db->getDatabaseName(), $db->getTablePrefix().$table]);
        if (!$meta || strcasecmp($meta->engine, 'InnoDB') !== 0) throw new RuntimeException('GO_HR_TRANSACTIONAL_TABLES_REQUIRED');
    }
    $branches = array_values(array_filter(array_column($app->make(\App\Services\Dashboard\TakeawayAccess::class)->branches($actor), 'value'), static fn($key) => str_starts_with($key, 'f:')));
    echo json_encode(goHrNov05Repair($db, $branches, '/home/fasakha/private-hr-nov05'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, preg_match('/^GO_HR_[A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage().PHP_EOL : "GO_HR_FAILED_NO_PARTIAL_DATABASE_CHANGES\n");
    exit(1);
}
