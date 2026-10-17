<?php

namespace App\Console\Commands;

use App\Services\Dashboard\EmployeeAttendanceDeductionCap;
use Illuminate\Console\Command;

class CapEmployeeAttendanceDeductions extends Command
{
    protected $signature='employees:cap-attendance-deductions {--dry-run : Preview reductions without changing entries}';
    protected $description='Cap existing automatic attendance deductions in open payroll months at the saved absence amount.';

    public function handle(EmployeeAttendanceDeductionCap $caps): int
    {
        $counts=$caps->repair((bool)$this->option('dry-run'));
        $this->info(($this->option('dry-run')?'Attendance cap preview: ':'Attendance caps corrected: ').json_encode($counts));
        return 0;
    }
}
