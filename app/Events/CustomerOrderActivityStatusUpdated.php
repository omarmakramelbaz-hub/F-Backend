<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class CustomerOrderActivityStatusUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order->load('resturant');
    }

    public function broadcastOn()
    {
        // Private channel for order user
        return new PrivateChannel('user.' . $this->order->user_id);
    }

    public function broadcastAs()
    {
        return 'status-updated';
    }

    public function broadcastWith()
    {
        return [
            'order_id' => $this->order->id,
            'order_status' => $this->order->status,
            'updated_at' => $this->order->updated_at?->timestamp ?? 0,
            'restaurant_name' => $this->order->resturant?->name ?? 'Fasakhansta',
        ];
    }
    
}
