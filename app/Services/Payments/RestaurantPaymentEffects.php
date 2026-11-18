<?php

namespace App\Services\Payments;

use App\Events\OrderUpdated;
use App\Events\VendorUpdated;
use App\Models\Order;
use App\Models\Resturant;
use App\Models\User;
use App\Notifications\NotifyResturantOrderCreatedNotification;
use App\Services\OrderBroadcastService;
use App\Services\Dashboard\BestEffortOrderMail;
use App\Services\Dashboard\OrderProviderDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Notify about an already committed activation; never change order or financial state. */
class RestaurantPaymentEffects
{
    public function afterActivation(array $result): void
    {
        if (($result['state'] ?? '') !== 'activated' || config('desktop_dashboard.local', false)) return;
        $orderId = (int) ($result['order_id'] ?? 0);
        if ($orderId < 1) return;
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->afterActivation($result));
            return;
        }
        $this->attempt(function () use ($orderId) {
            $order = Order::withoutGlobalScopes()->find($orderId);
            if (!$order || $order->type !== 'current' || $order->status !== 'pending') return;
            $restaurant = Resturant::withoutGlobalScopes()->find($order->resturant_id);
            if (!$restaurant) return;
            $vendor = User::withoutGlobalScopes()->find($restaurant->user_id);
            $customer = User::withoutGlobalScopes()->find($order->user_id);
            $restaurant->setRelation('user', $vendor);
            $order->setRelation('resturant', $restaurant);
            $order->setRelation('user', $customer);
            $this->attempt(fn () => OrderBroadcastService::newOrder($order));
            $count = Order::withoutGlobalScopes()->where('resturant_id', $order->resturant_id)
                ->where('type', 'current')->where('status', 'pending')->count();
            $delivery = app(OrderProviderDelivery::class);
            if ($customer) $this->attempt(fn () => $delivery->event(new OrderUpdated($order, $count, $customer->id), $orderId));
            if ($vendor) {
                $this->attempt(fn () => $delivery->event(new VendorUpdated($order, $count, $vendor->id), $orderId));
                $this->attempt(fn () => $delivery->event(new OrderUpdated($order, $count, $vendor->id), $orderId));
                $this->attempt(fn () => $delivery->defer(
                    fn () => Notification::send($vendor, new NotifyResturantOrderCreatedNotification(clone $order)), $orderId, 'notice'));
            }
            $email = $customer->email ?? null;
            if (is_string($email) && trim($email) !== '') {
                $this->attempt(fn () => app(BestEffortOrderMail::class)->send($orderId, 'emails.send_order_email',
                    ['email' => $email, 'cart' => $order], function ($message) use ($email) {
                        $message->to($email);
                        $message->subject('Your order has been received!');
                    }));
            }
        });
    }

    private function attempt(callable $work): void
    {
        try { $work(); }
        catch (\Throwable $error) {
            // These effects are best effort. Do not leak provider/SMTP account details.
        }
    }
}
