<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Dashboard\CustomerDirectorySync;

class CustomerDirectoryOrderObserver
{
    public function created(Order $order): void
    {
        app(CustomerDirectorySync::class)->captureOrder($order);
    }

    public function updated(Order $order): void
    {
        app(CustomerDirectorySync::class)->captureOrder($order, $order->wasChanged('status') && $order->status === 'completed');
    }
}
