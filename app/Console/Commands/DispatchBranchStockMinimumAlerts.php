<?php

namespace App\Console\Commands;

use App\Services\Dashboard\BranchStockMinimums;
use Illuminate\Console\Command;

class DispatchBranchStockMinimumAlerts extends Command
{
    protected $signature = 'branch-stock:notify-minimum {--limit=100 : Maximum pending emails per run}';
    protected $description = 'Reconcile branch stock minimums and deliver pending Owner email alerts';

    public function handle(BranchStockMinimums $minimums): int
    {
        if (!$minimums->installed() || config('desktop_dashboard.local', false)) {
            $this->line('STOCK_MINIMUM_ALERTS_NOT_ENABLED');
            return 0;
        }
        $checked = $minimums->reconcile();
        $result = $minimums->dispatch((int)$this->option('limit'));
        $this->line('STOCK_MINIMUM_ALERTS checked='.$checked.' sent='.$result['sent'].' pending_retry='.$result['failed']);
        return $result['failed'] ? 1 : 0;
    }
}
