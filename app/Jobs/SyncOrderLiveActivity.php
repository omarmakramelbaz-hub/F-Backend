<?php

namespace App\Jobs;

use App\Events\CustomerOrderActivityStatusUpdated;
use App\Models\Order;
use App\Services\OrderLiveActivity\Sync;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SyncOrderLiveActivity
{
    use Dispatchable, Queueable;
    public $orderId;
    public function __construct(int $orderId) { $this->orderId = $orderId; }
    public function handle(Sync $sync): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (!$order || !$order->user_id) return;
        try { event(new CustomerOrderActivityStatusUpdated($order)); }
        catch (\Throwable $e) { Log::warning('ORDER_LIVE_ACTIVITY_BROADCAST_PENDING', ['order_id' => $order->id]); }
        $sync->order($order);
    }
}
