<?php

namespace App\Console\Commands;

use App\Services\Dashboard\OrderBoardClock;
use Illuminate\Console\Command;

class AdvanceOrderBoard extends Command
{
    protected $signature = 'order-board:advance';
    protected $description = 'Advance newly accepted delivery orders at 15 and 90 minutes from acceptance.';

    public function handle(OrderBoardClock $clocks): int
    {
        if (!$clocks->ready()) {
            $this->warn('Order board clocks are not installed; no orders changed.');
            return 0;
        }
        $counts = $clocks->run();
        $this->info('Order board timers: '.json_encode($counts));
        return $counts['errors'] > 0 ? 1 : 0;
    }
}
