<?php
declare(strict_types=1);

/** Read-only payroll facts for the owner's 25 October launch review. */
final class LaunchPayrollInspection
{
    private const MONTH = '2026-10';
    private const FROM = '2026-10-01';
    private const THROUGH = '2026-10-25';
    private const DAYS = 31;
    private const ELIGIBLE = 25;
    private const LIMIT = 1000;
    private const ROW_LIMIT = 100000;
    private const MAX_MONEY = 100000000000000;

    public static function inspect(PDO $pdo, array $schema, ?DateTimeImmutable $clock = null): array
    {
        $clock = ($clock ?? new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->setTimezone(new DateTimeZone('Africa/Cairo'));
        $operating = (int)$clock->format('H') < 6 ? $clock->modify('-1 day') : $clock;
        $result = [
            'ready' => false,
            'scope' => 'all_registered_f_branch_employees',
            'month' => self::MONTH,
            'requested_from' => self::FROM,
            'requested_through' => self::THROUGH,
            'requested_inclusive_days' => self::ELIGIBLE,
            'calendar_month_days' => self::DAYS,
            'salary_divisor' => 'actual_calendar_days_in_month',
            'recorded_deductions_policy' => 'preserve',
            'contract_hours_policy' => 'preserve_existing_terms',
            'clock' => [
                'cairo_now' => $clock->format(DateTimeInterface::ATOM),
                'calendar_date' => $clock->format('Y-m-d'),
                'operating_date' => $operating->format('Y-m-d'),
                'operating_start_hour' => 6,
                'operating_timezone' => 'Africa/Cairo',
                'operating_day_before_requested_through' => $operating->format('Y-m-d') < self::THROUGH,
            ],
            'missing_schema' => [],
            'warnings' => [],
            'files_changed' => false,
            'database_changed' => false,
        ];
        $required = [
            'branch_employees' => ['id', 'branch', 'active', 'hired_on', 'left_on'],
            'branch_employee_salaries' => ['id', 'employee_id', 'branch', 'effective_month', 'amount_cents'],
            'branch_employee_entries' => ['id', 'employee_id', 'branch', 'day', 'kind', 'source_key', 'amount_cents', 'voided_at'],
            'branch_employee_days' => ['id', 'employee_id', 'branch', 'day', 'status'],
            'branch_payrolls' => ['id', 'employee_id', 'branch', 'month', 'status', 'net_cents'],
        ];
        foreach ([
            'branch_employee_pay_hours' => ['id', 'employee_id', 'branch', 'effective_month', 'scheduled_minutes'],
            'branch_work_hours' => ['id', 'branch', 'effective_month', 'full_time_minutes'],
        ] as $table => $columns) if (isset($schema[$table])) $required[$table] = $columns;
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (!isset($schema[$table]['columns'][$column])) $result['missing_schema'][] = $table.'.'.$column;
            }
        }
        if ($result['missing_schema']) return $result;
        $hasEmployeeSchedule = isset($schema['branch_employees']['columns']['schedule_type']);
        $hasPayHours = self::has($schema, 'branch_employee_pay_hours', ['id', 'employee_id', 'branch', 'effective_month', 'scheduled_minutes']);
        $hasWorkHours = self::has($schema, 'branch_work_hours', ['id', 'branch', 'effective_month', 'full_time_minutes']);
        $employeeSql = 'SELECT id,branch,active,hired_on,left_on,'.($hasEmployeeSchedule ? 'schedule_type' : 'NULL AS schedule_type')
            .' FROM branch_employees WHERE branch LIKE ? ORDER BY id LIMIT '.(self::LIMIT + 1);
        $employees = self::rows($pdo, $employeeSql, ['f:%'], self::LIMIT);
        $ids = []; $employeeById = []; $branches = [];
        $flags = ['active_count' => 0, 'inactive_count' => 0, 'part_time_count' => 0,
            'hired_after_requested_start_count' => 0, 'left_before_requested_through_count' => 0,
            'invalid_employment_date_count' => 0, 'missing_october_salary_count' => 0,
            'future_salary_history_rows_count' => 0, 'invalid_salary_history_rows_count' => 0,
            'salary_branch_mismatch_rows_count' => 0, 'missing_employee_hours_record_count' => 0,
            'part_time_without_employee_hours_record_count' => 0, 'employees_using_default_branch_hours_count' => 0,
            'invalid_hours_terms_count' => 0, 'cross_branch_ledger_rows_count' => 0,
            'invalid_ledger_rows_count' => 0];
        foreach ($employees as $employee) {
            $id = self::integer($employee['id'], 1, PHP_INT_MAX);
            $ids[] = $id; $employeeById[$id] = $employee; $branches[(string)$employee['branch']] = true;
            if (self::integer($employee['active'], 0, 1) === 1) $flags['active_count']++; else $flags['inactive_count']++;
            if (($employee['schedule_type'] ?? null) === 'part_time') $flags['part_time_count']++;
            if (!self::date($employee['hired_on']) || ($employee['left_on'] !== null && !self::date($employee['left_on']))) {
                $flags['invalid_employment_date_count']++;
            } else {
                if ($employee['hired_on'] > self::FROM) $flags['hired_after_requested_start_count']++;
                if ($employee['left_on'] !== null && $employee['left_on'] < self::THROUGH) $flags['left_before_requested_through_count']++;
            }
        }
        $cohortJoin = ' JOIN branch_employees e ON e.id=x.employee_id WHERE e.branch LIKE ?';
        $salaryRows = self::rows($pdo, 'SELECT x.id,x.employee_id,x.branch,x.effective_month,x.amount_cents'
            .' FROM branch_employee_salaries x'.$cohortJoin.' ORDER BY x.employee_id,x.effective_month DESC,x.id DESC LIMIT '.(self::ROW_LIMIT + 1), ['f:%']);
        $salaryById = []; $salarySeen = []; $duplicateSalaryMonths = [];
        foreach ($salaryRows as $row) {
            $id = self::integer($row['employee_id'], 1, PHP_INT_MAX);
            if ($row['branch'] !== $employeeById[$id]['branch']) $flags['salary_branch_mismatch_rows_count']++;
            if (!self::month($row['effective_month'])) { $flags['invalid_salary_history_rows_count']++; continue; }
            if ($row['effective_month'] > self::MONTH) { $flags['future_salary_history_rows_count']++; continue; }
            $key = $id.':'.$row['effective_month'];
            if (isset($duplicateSalaryMonths[$key])) throw new RuntimeException('PAYROLL_INSPECTION_AMBIGUOUS_SALARY_HISTORY');
            $duplicateSalaryMonths[$key] = true;
            if (!isset($salarySeen[$id])) {
                $salarySeen[$id] = true;
                try { $salaryById[$id] = self::integer($row['amount_cents'], 0, self::MAX_MONEY); }
                catch (RuntimeException $error) { $flags['invalid_salary_history_rows_count']++; }
            }
        }
        $payHours = [];
        if ($hasPayHours) {
            $rows = self::rows($pdo, 'SELECT x.id,x.employee_id,x.effective_month,x.scheduled_minutes FROM branch_employee_pay_hours x'
                .$cohortJoin.' AND x.effective_month<=? ORDER BY x.employee_id,x.effective_month DESC,x.id DESC LIMIT '.(self::ROW_LIMIT + 1), ['f:%', self::MONTH]);
            foreach ($rows as $row) {
                $id = self::integer($row['employee_id'], 1, PHP_INT_MAX);
                if (!array_key_exists($id, $payHours)) $payHours[$id] = $row['scheduled_minutes'];
            }
        }
        $branchHours = [];
        if ($hasWorkHours) {
            $rows = self::rows($pdo, 'SELECT id,branch,effective_month,full_time_minutes FROM branch_work_hours'
                .' WHERE branch LIKE ? AND effective_month<=? ORDER BY branch,effective_month DESC,id DESC LIMIT '.(self::ROW_LIMIT + 1), ['f:%', self::MONTH]);
            foreach ($rows as $row) if (!array_key_exists($row['branch'], $branchHours)) $branchHours[$row['branch']] = $row['full_time_minutes'];
        }
        $entries = self::rows($pdo, 'SELECT x.employee_id,x.branch,x.day,x.kind,x.source_key,x.amount_cents,x.voided_at'
            .' FROM branch_employee_entries x'.$cohortJoin.' AND x.day>=? AND x.day<=? ORDER BY x.employee_id,x.day,x.id LIMIT '.(self::ROW_LIMIT + 1), ['f:%', self::FROM, self::THROUGH]);
        $entryGroups = []; $deductions = []; $leaveDays = []; $entryTotals = ['bonus' => 0, 'deduction' => 0, 'advance' => 0];
        foreach ($entries as $row) {
            $id = self::integer($row['employee_id'], 1, PHP_INT_MAX);
            if ($row['branch'] !== $employeeById[$id]['branch']) $flags['cross_branch_ledger_rows_count']++;
            $kind = in_array($row['kind'], ['bonus', 'deduction', 'advance'], true) ? $row['kind'] : 'unknown';
            $source = self::sourceKey($row['source_key']); $voided = $row['voided_at'] !== null;
            $key = $kind.'|'.$source.'|'.($voided ? 'voided' : 'active');
            if (!isset($entryGroups[$key])) $entryGroups[$key] = ['kind' => $kind, 'source_key' => $source, 'voided' => $voided, 'count' => 0, 'amount_cents' => 0];
            $entryGroups[$key]['count']++;
            try { $amount = self::integer($row['amount_cents'], 0, self::MAX_MONEY); }
            catch (RuntimeException $error) { $flags['invalid_ledger_rows_count']++; continue; }
            $entryGroups[$key]['amount_cents'] = self::add($entryGroups[$key]['amount_cents'], $amount);
            if ($kind === 'unknown') { $flags['invalid_ledger_rows_count']++; continue; }
            if (!$voided) {
                $entryTotals[$kind] = self::add($entryTotals[$kind], $amount);
                if ($kind === 'deduction') $deductions[$id] = self::add($deductions[$id] ?? 0, $amount);
                if ($kind === 'deduction' && $row['source_key'] === 'attendance.leave') $leaveDays[$id][$row['day']] = true;
            }
        }
        ksort($entryGroups);
        $attendance = self::rows($pdo, 'SELECT x.status,COUNT(*) AS records FROM branch_employee_days x'.$cohortJoin
            .' AND x.day>=? AND x.day<=? GROUP BY x.status ORDER BY x.status LIMIT 101', ['f:%', self::FROM, self::THROUGH], 100);
        $attendanceCounts = [];
        foreach ($attendance as $row) {
            $status = in_array($row['status'], ['unrecorded', 'present', 'morning', 'evening', 'part_time', 'preapproved_leave', 'unauthorized_absence', 'absent', 'paid_leave', 'unpaid_leave'], true) ? $row['status'] : 'unknown';
            $attendanceCounts[$status] = self::add($attendanceCounts[$status] ?? 0, self::integer($row['records'], 0, self::ROW_LIMIT));
        }
        $payrollRows = self::rows($pdo, 'SELECT x.employee_id,x.status,x.net_cents FROM branch_payrolls x'.$cohortJoin
            .' AND x.month=? ORDER BY x.employee_id,x.id LIMIT '.(self::ROW_LIMIT + 1), ['f:%', self::MONTH]);
        $payrollCounts = []; $payrollEmployeeIds = [];
        foreach ($payrollRows as $row) {
            $status = in_array($row['status'], ['closed', 'paid', 'draft'], true) ? $row['status'] : 'unknown';
            $payrollCounts[$status] = ($payrollCounts[$status] ?? 0) + 1;
            $payrollEmployeeIds[] = self::integer($row['employee_id'], 1, PHP_INT_MAX);
        }
        $totals = ['employees_with_known_salary_and_hours' => 0, 'monthly_base_cents' => 0,
            'full_period_gross_cents' => 0, 'contract_hours_deduction_cents' => 0,
            'retained_recorded_deductions_cents' => 0, 'post_requested_bonus_advance_reset_net_cents' => 0];
        foreach ($employees as $employee) {
            $id = (int)$employee['id'];
            if (!array_key_exists($id, $salaryById)) { $flags['missing_october_salary_count']++; continue; }
            if (!array_key_exists($id, $payHours)) {
                $flags['missing_employee_hours_record_count']++;
                if (($employee['schedule_type'] ?? null) === 'part_time') $flags['part_time_without_employee_hours_record_count']++;
            }
            if (!array_key_exists($employee['branch'], $branchHours)) $flags['employees_using_default_branch_hours_count']++;
            try {
                $full = array_key_exists($employee['branch'], $branchHours) ? self::integer($branchHours[$employee['branch']], 60, 1440) : 600;
                $scheduled = array_key_exists($id, $payHours) && $payHours[$id] !== null ? self::integer($payHours[$id], 1, 1439) : null;
                $terms = self::calculateTerms($salaryById[$id], self::ELIGIBLE, self::DAYS, $full, $scheduled, count($leaveDays[$id] ?? []));
            } catch (RuntimeException $error) { $flags['invalid_hours_terms_count']++; continue; }
            $deduction = $deductions[$id] ?? 0;
            $totals['employees_with_known_salary_and_hours']++;
            $totals['monthly_base_cents'] = self::add($totals['monthly_base_cents'], $salaryById[$id]);
            $totals['full_period_gross_cents'] = self::add($totals['full_period_gross_cents'], $terms['earned_salary_cents']);
            $totals['contract_hours_deduction_cents'] = self::add($totals['contract_hours_deduction_cents'], $terms['hours_deduction_cents']);
            $totals['retained_recorded_deductions_cents'] = self::add($totals['retained_recorded_deductions_cents'], $deduction);
            $totals['post_requested_bonus_advance_reset_net_cents'] = self::add($totals['post_requested_bonus_advance_reset_net_cents'], $terms['earned_salary_cents'] - $terms['hours_deduction_cents'] - $deduction);
        }
        foreach ($flags as $key => $count) if ($count > 0 && in_array($key, ['left_before_requested_through_count', 'invalid_employment_date_count', 'missing_october_salary_count', 'invalid_salary_history_rows_count', 'part_time_without_employee_hours_record_count', 'invalid_hours_terms_count', 'invalid_ledger_rows_count'], true)) $result['warnings'][] = $key;
        if ($payrollRows) $result['warnings'][] = 'october_saved_payroll_snapshots_override_recalculation';
        if (!$hasEmployeeSchedule) $result['warnings'][] = 'employee_schedule_type_column_missing';
        $complete = $totals['employees_with_known_salary_and_hours'] === count($employees)
            && $flags['invalid_salary_history_rows_count'] === 0 && $flags['invalid_ledger_rows_count'] === 0;
        $result['ready'] = true;
        return $result + [
            'employee_ids' => $ids,
            'employee_count' => count($employees),
            'employee_branch_count' => count($branches),
            'cohort_flags' => $flags,
            'salary_history_rows_count' => count($salaryRows),
            'contract_tables' => ['employee_pay_hours_present' => $hasPayHours, 'branch_work_hours_present' => $hasWorkHours],
            'october_entries' => ['groups' => array_values($entryGroups), 'active_totals_cents' => $entryTotals],
            'october_attendance_counts' => $attendanceCounts,
            'october_saved_payrolls' => ['rows_count' => count($payrollRows), 'counts_by_status' => $payrollCounts, 'employee_count' => count(array_unique($payrollEmployeeIds)), 'blocks_recalculation' => count($payrollRows) > 0],
            'projection' => [
                'complete' => $complete,
                'assumes_each_registered_employee_eligible_for_all_25_days' => true,
                'existing_leavers_require_decision' => $flags['left_before_requested_through_count'] > 0,
                'excludes_bonus_and_advance_after_requested_reset' => true,
                'retains_active_recorded_deductions_and_contract_hours' => true,
                'does_not_represent_a_closed_or_paid_payroll' => true,
                'totals' => $totals,
            ],
        ];
    }

    /** Exact integer formulas used by BranchPayroll / EmployeePayHours, with cent rounding. */
    public static function calculateTerms(int $base, int $worked, int $days, int $fullMinutes, ?int $scheduledMinutes, int $leaveDays): array
    {
        if ($base < 0 || $base > self::MAX_MONEY || $days < 1 || $days > 31 || $worked < 0 || $worked > $days
            || $fullMinutes < 60 || $fullMinutes > 1440 || ($scheduledMinutes !== null && ($scheduledMinutes < 1 || $scheduledMinutes > 1439))) throw new RuntimeException('PAYROLL_INSPECTION_INVALID_TERMS');
        $missing = max(0, $fullMinutes - ($scheduledMinutes ?? $fullMinutes));
        $denominator = $days * $fullMinutes;
        $charged = max(0, $worked - min($worked, max(0, $leaveDays)));
        return ['earned_salary_cents' => intdiv($base * $worked + intdiv($days, 2), $days),
            'hours_deduction_cents' => intdiv($base * $missing * $charged + intdiv($denominator, 2), $denominator)];
    }

    private static function has(array $schema, string $table, array $columns): bool
    {
        foreach ($columns as $column) if (!isset($schema[$table]['columns'][$column])) return false;
        return true;
    }
    private static function rows(PDO $pdo, string $sql, array $bindings, int $limit = self::ROW_LIMIT): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($bindings); $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $limit) throw new RuntimeException('PAYROLL_INSPECTION_ROW_LIMIT');
        return $rows;
    }
    private static function integer($value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$value)) throw new RuntimeException('PAYROLL_INSPECTION_INTEGER_INVALID');
        $number = (int)$value;
        if ((string)$number !== (string)$value || $number < $min || $number > $max) throw new RuntimeException('PAYROLL_INSPECTION_INTEGER_RANGE');
        return $number;
    }
    private static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) throw new RuntimeException('PAYROLL_INSPECTION_TOTAL_RANGE');
        return $left + $right;
    }
    private static function date($value): bool
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Africa/Cairo'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    private static function month($value): bool
    {
        return is_string($value) && (bool)preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $value);
    }
    private static function sourceKey($value): string
    {
        if ($value === null) return 'manual';
        return in_array($value, ['attendance.late', 'attendance.early', 'attendance.absence', 'attendance.leave'], true) ? $value : 'other';
    }
}
