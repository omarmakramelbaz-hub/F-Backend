<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class People
{
    public function employee(Actor $actor, int $id, bool $lock = false)
    {
        $query = DB::table('erp_employees')->where('id', $id);
        if ($lock) { $query->lockForUpdate(); }
        $employee = $query->first();
        abort_unless($employee, 404, 'الموظف غير موجود.');
        $actor->branch((int) $employee->branch_id);
        return $employee;
    }

    public function attendance(Actor $actor, int $employeeId, array $data): void
    {
        $actor->require('employees.manage');
        abort_unless(in_array($data['status'], ['present','absent','paid_leave','unpaid_leave'], true), 422, 'حالة حضور غير صالحة.');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $data['day']);
        abort_unless($day && $day->format('Y-m-d') === $data['day'] && $data['day'] <= now('Africa/Cairo')->format('Y-m-d'), 422, 'تاريخ الحضور غير صالح.');
        DB::transaction(function () use ($actor, $employeeId, $data) {
            $employee = $this->employee($actor, $employeeId, true);
            abort_if($data['day'] < $employee->hired_on, 422, 'التاريخ يسبق تعيين الموظف.');
            $this->openMonth($employeeId, substr($data['day'], 0, 7));
            DB::table('erp_attendance')->updateOrInsert(['employee_id' => $employeeId, 'day' => $data['day']], ['status' => $data['status'], 'notes' => $data['notes'] ?? null, 'updated_at' => now(), 'created_at' => now()]);
            $actor->audit('employee.attendance', 'employee', $employeeId, $data, (int) $employee->branch_id);
        });
    }

    public function salary(Actor $actor, int $employeeId, string $month, $amount): void
    {
        $actor->require('payroll.manage');
        $this->month($month);
        $minor = Decimal::money($amount);
        DB::transaction(function () use ($actor, $employeeId, $month, $minor) {
            $employee = $this->employee($actor, $employeeId, true);
            abort_if($month < substr($employee->hired_on, 0, 7), 422, 'الشهر يسبق التعيين.');
            abort_if(DB::table('erp_payrolls')->where('employee_id', $employeeId)->where('month', '>=', $month)->exists(), 409, 'التعديل يؤثر على كشف معتمد؛ اختر شهرًا لاحقًا.');
            DB::table('erp_salary_rates')->updateOrInsert(['employee_id' => $employeeId, 'effective_month' => $month], ['salary_minor' => $minor]);
            $actor->audit('employee.salary', 'employee', $employeeId, ['effective_month' => $month, 'salary_minor' => $minor], (int) $employee->branch_id);
        });
    }

    public function adjustment(Actor $actor, int $employeeId, array $data): int
    {
        $actor->require('payroll.manage');
        $this->month($data['month']);
        abort_unless(in_array($data['type'], ['bonus','deduction','advance_repayment'], true), 422, 'نوع تسوية غير صالح.');
        abort_unless(preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $data['request_key'] ?? ''), 422, 'مرجع العملية غير صالح.');
        $amount = Decimal::money($data['amount']);
        abort_unless($amount > 0 && trim($data['reason'] ?? '') !== '', 422, 'القيمة والسبب مطلوبان.');
        return DB::transaction(function () use ($actor, $employeeId, $data, $amount) {
            $employee = $this->employee($actor, $employeeId, true);
            abort_if($data['month'] < substr($employee->hired_on, 0, 7), 422, 'الشهر يسبق تعيين الموظف.');
            $prior = DB::table('erp_payroll_adjustments')->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless((int) $prior->employee_id === $employeeId && $prior->actor_key === $actor->key && $prior->month === $data['month'] && $prior->type === $data['type'] && (int) $prior->amount_minor === $amount && $prior->reason === trim($data['reason']), 409, 'مرجع العملية مستخدم لبيانات أخرى.');
                return (int) $prior->id;
            }
            $this->openMonth($employeeId, $data['month']);
            $id = DB::table('erp_payroll_adjustments')->insertGetId(['employee_id' => $employeeId, 'month' => $data['month'], 'type' => $data['type'], 'amount_minor' => $amount, 'reason' => trim($data['reason']), 'request_key' => $data['request_key'], 'actor_key' => $actor->key, 'created_at' => now()]);
            $actor->audit('payroll.adjustment', 'payroll_adjustment', $id, ['employee_id' => $employeeId, 'type' => $data['type'], 'amount_minor' => $amount, 'month' => $data['month']], (int) $employee->branch_id);
            return (int) $id;
        }, 3);
    }

    public function statement(Actor $actor, int $employeeId, string $month): array
    {
        $actor->require('payroll.manage');
        $this->month($month);
        $employee = $this->employee($actor, $employeeId);
        $closed = DB::table('erp_payrolls')->where('employee_id', $employeeId)->where('month', $month)->first();
        if ($closed) { return (array) $closed + ['closed' => true]; }
        $rate = DB::table('erp_salary_rates')->where('employee_id', $employeeId)->where('effective_month', '<=', $month)->orderByDesc('effective_month')->value('salary_minor');
        abort_if($rate === null, 422, 'لا يوجد راتب مسجل لهذا الشهر.');
        $sums = DB::table('erp_payroll_adjustments')->where('employee_id', $employeeId)->where('month', $month)->selectRaw('type, SUM(amount_minor) AS amount')->groupBy('type')->pluck('amount', 'type');
        $base = (int) $rate;
        $bonus = (int) ($sums['bonus'] ?? 0);
        $deduction = (int) ($sums['deduction'] ?? 0);
        $advance = (int) ($sums['advance_repayment'] ?? 0);
        return ['employee_id' => $employeeId, 'branch_id' => (int) $employee->branch_id, 'month' => $month, 'base_minor' => $base, 'bonus_minor' => $bonus, 'deduction_minor' => $deduction, 'advance_minor' => $advance, 'net_minor' => $base + $bonus - $deduction - $advance, 'closed' => false];
    }

    public function close(Actor $actor, int $employeeId, string $month): int
    {
        $actor->require('payroll.manage');
        $this->month($month);
        abort_if($month > now('Africa/Cairo')->format('Y-m'), 422, 'لا يمكن اعتماد شهر مستقبلي.');
        return DB::transaction(function () use ($actor, $employeeId, $month) {
            $employee = $this->employee($actor, $employeeId, true);
            $statement = $this->statement($actor, $employeeId, $month);
            if ($statement['closed']) { return (int) $statement['id']; }
            abort_if($statement['net_minor'] < 0, 422, 'الخصومات تتجاوز المستحق؛ راجع التسويات قبل الاعتماد.');
            unset($statement['closed']);
            $id = DB::table('erp_payrolls')->insertGetId($statement + ['actor_key' => $actor->key, 'created_at' => now()]);
            $actor->audit('payroll.close', 'payroll', $id, $statement, (int) $employee->branch_id);
            return (int) $id;
        }, 3);
    }

    private function openMonth(int $employeeId, string $month): void
    {
        abort_if(DB::table('erp_payrolls')->where('employee_id', $employeeId)->where('month', $month)->exists(), 409, 'كشف هذا الشهر معتمد ولا يقبل التعديل.');
    }

    public function month(string $month): void
    {
        abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month), 422, 'شهر غير صالح.');
    }
}
