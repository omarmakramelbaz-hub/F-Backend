<?php

namespace App\Console\Commands;

use App\Services\Dashboard\EmployeeAutomaticAbsence;
use Illuminate\Console\Command;

class RecordEmployeeAutomaticAbsence extends Command
{
    protected $signature='employees:auto-absence {--dry-run : Report proposed absence records without saving}';
    protected $description='Record automatic unauthorized absence after the Cairo attendance deadline.';

    public function handle(EmployeeAutomaticAbsence $absence): int
    {
        $counts=$absence->run((bool)$this->option('dry-run'));
        if(!$counts['ready']){
            $this->warn('Employee automatic absence is not activated or its attendance schema is not ready; no records changed.');
            return 1;
        }
        $this->info('Employee automatic absence: '.json_encode($counts));
        return $counts['errors']>0?1:0;
    }
}
