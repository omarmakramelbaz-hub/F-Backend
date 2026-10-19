<?php

namespace App\Events;

use App\Models\Order;
use App\Services\OrderTrackingState;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order->loadMissing('resturant');
    }

    public function broadcastOn()
    {
        // Private channel for order user
        return new Channel('order.' . $this->order->id);
    }

    public function broadcastAs()
    {
        return 'status-updated';
    }

    public function broadcastWith()
    {
        $state = OrderTrackingState::forOrder($this->order);
        return $state + [
            // Keep the existing display-status contract for installed customer apps.
            'order_status' => $state['live_activity_status'],
            'restaurant_name' => $this->order->resturant?->name ?? '',
        ];
    }
}
