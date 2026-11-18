<?php

namespace App\Observers;

use App\Jobs\SyncOrderLiveActivity;
use App\Models\Order;

class OrderLiveActivityObserver
{
    public function updated(Order $order): void
    {
        if ($order->user_id && $order->wasChanged('status')) {
            // Read the committed order after the response; never delay checkout.
            SyncOrderLiveActivity::dispatchAfterResponse($order->id);
        }
    }
}
