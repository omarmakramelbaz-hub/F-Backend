<?php

namespace App\Console\Commands;

use App\Services\Dashboard\OrderLiveActivityService;
use Illuminate\Console\Command;

class RetryOrderLiveActivities extends Command
{
    protected $signature = 'orders:retry-live-activities';
    protected $description = 'Synchronize registered customer Live Activities and retry provider failures.';

    public function handle(OrderLiveActivityService $activities)
    {
        $this->info('Live Activity registrations checked: '.$activities->retryPending());
        return 0;
    }
}
