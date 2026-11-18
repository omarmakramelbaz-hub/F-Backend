<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderActivitySubscription;
use App\Services\OrderLiveActivity\Sync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncOrderLiveActivities extends Command
{
    protected $signature = 'orders:sync-live-activities';
    protected $description = 'Retry order activity delivery and reconcile bulk status updates';
    public function handle(Sync $sync)
    {
        if (!Schema::hasTable('order_activity_subscriptions')) return 0;
        $ids = OrderActivitySubscription::whereNull('ended_at')->where('expires_at', '>', now())
            ->select('order_id')->distinct()->pluck('order_id');
        foreach ($ids->chunk(100) as $chunk) {
            foreach (Order::withoutGlobalScopes()->whereIn('id', $chunk)->get() as $order) $sync->order($order);
        }
        OrderActivitySubscription::where('expires_at', '<', now()->subDay())->delete();
        return 0;
    }
}
