<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Dashboard\OrderBoardClock;
use App\Services\Dashboard\OrderBoardService;

class OrderBoardClockObserver
{
    public function updated(Order $order): void
    {
        if ($order->type !== 'current' || !$order->resturant_id) return;
        if (!$this->trustedActor($order)) return;
        // Vendor acceptance uses accepted_notify; retained partner APIs can
        // first accept by setting status. Later courier assignments never reset
        // the original restaurant acceptance clock.
        $wasAccepted = $order->getOriginal('accepted_notify') === 'yes'
            || in_array($order->getOriginal('status'), ['accepted', 'shipped', 'completed'], true);
        $isAccepted = $order->accepted_notify === 'yes' || $order->status === 'accepted';
        if (!$wasAccepted && $isAccepted && ($order->wasChanged('accepted_notify') || $order->wasChanged('status'))) {
            app(OrderBoardClock::class)->accepted('legacy', (int) $order->id);
        }
    }

    private function trustedActor(Order $order): bool
    {
        $board = app(OrderBoardService::class);
        if ($admin = auth('admin')->user()) {
            return $board->canAccess($admin) && ($board->isAdmin($admin)
                || in_array((int) $order->resturant_id, array_map('intval', $board->restaurantIds($admin)), true));
        }
        if ($actor = auth('api')->user()) {
            if ($actor->status !== 'accepted') return false;
            if ($actor->account_type === 'delegate') return (int) $order->delegate_id === (int) $actor->id;
            return in_array($actor->account_type, ['vendor', 'resturant_owner'], true)
                && in_array((int) $order->resturant_id, array_map('intval', $board->restaurantIds($actor)), true);
        }
        // Trusted command/service writes have no browser/mobile principal.
        return app()->runningInConsole();
    }
}
