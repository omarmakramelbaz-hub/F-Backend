<?php

namespace App\Services\OrderLiveActivity;

use App\Models\Order;
use App\Models\OrderActivitySubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Sync
{
    private $push;

    public function __construct(Push $push) { $this->push = $push; }

    public static function terminal(string $status): bool
    {
        return in_array(strtolower($status), ['completed', 'delivered', 'cancelled', 'canceled', 'declined', 'rejected', 'ended', 'failed', 'payment_failed'], true);
    }

    public static function state(Order $order): array
    {
        return [
            'orderId' => (string) $order->id,
            'orderStatus' => (string) $order->status,
            'restaurantName' => (string) ($order->resturant?->name ?? 'Fasakhansta'),
            'updatedAt' => $order->updated_at?->timestamp ?? 0,
        ];
    }

    public function order(Order $order): void
    {
        if (!$order->user_id || !Schema::hasTable('order_activity_subscriptions')) return;
        // Serialize the response job and scheduler for the same order.
        $lock = Cache::lock('order_activity_subscription_'.$order->id, 60);
        if (!$lock->get()) return;
        try {
            // Read committed state inside the lock, never a queued historical stage.
            $current = Order::withoutGlobalScopes()->find($order->id);
            if (!$current) return;
            $activities = OrderActivitySubscription::where('order_id', $current->id)
                ->where('user_id', $current->user_id)->whereNull('ended_at')
                ->where('expires_at', '>', now())->get();
            foreach ($activities as $activity) {
                if ($activity->last_status === $current->status) continue;
                try {
                    $terminal = self::terminal($current->status);
                    $this->push->send($activity, self::state($current), $terminal);
                    $activity->last_status = $current->status;
                    $activity->last_sent_at = now();
                    if ($terminal) $activity->ended_at = now();
                    $activity->save();
                } catch (\Throwable $e) {
                    Log::warning('ORDER_LIVE_ACTIVITY_DELIVERY_PENDING', [
                        'order_id' => $current->id, 'registration_id' => $activity->id,
                    ]);
                    // Keep last_status unchanged so the scheduler retries.
                }
            }
        } finally { $lock->release(); }
    }
}
