<?php
namespace App\Console\Commands;

use App\Services\Dashboard\DashboardPushCampaigns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDashboardPush extends Command
{
    protected $signature = 'dashboard-push:dispatch';
    protected $description = 'Continue authorized manual notification campaigns in small durable batches';
    public function handle(DashboardPushCampaigns $campaigns): int
    {
        if (!$campaigns->ready()) return 0;
        $deadline = microtime(true) + 40;
        do {
            $ids = DB::table('dashboard_push_campaigns')->whereIn('status', ['queued', 'sending'])
                ->where(function ($q) { $q->whereNull('claim')->orWhere('claimed_at', '<=', now()->subMinutes(2)); })
                ->orderBy('updated_at')->orderBy('id')->limit(10)->pluck('id');
            if ($ids->isEmpty()) break;
            foreach ($ids as $id) { $campaigns->step((int) $id); if (microtime(true) >= $deadline) break; }
        } while (microtime(true) < $deadline);
        return 0;
    }
}
