<?php

namespace App\Jobs;

use App\Events\VendorUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotifyResturantOrderCreatedNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class SendOrderCreatedAfterResponse
{
    use Dispatchable;

    private int $orderId;
    private ?int $restaurantOwnerId;
    private ?int $parentOwnerId;
    private int $orderCount;

    public function __construct(
        int $orderId,
        ?int $restaurantOwnerId,
        ?int $parentOwnerId,
        int $orderCount
    ) {
        $this->orderId = $orderId;
        $this->restaurantOwnerId = $restaurantOwnerId;
        $this->parentOwnerId = $parentOwnerId;
        $this->orderCount = $orderCount;
    }

    public function handle(): void
    {
        $order = Order::withoutGlobalScopes()
            ->with(['user', 'resturant'])
            ->find($this->orderId);

        if (! $order) {
            Log::warning('ORDER_AFTER_RESPONSE_MISSING', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        $ownerIds = array_values(array_unique(array_filter([
            $this->restaurantOwnerId,
            $this->parentOwnerId,
        ])));

        foreach ($ownerIds as $ownerId) {
            try {
                $owner = User::withoutGlobalScopes()->find($ownerId);

                if ($owner) {
                    Notification::send(
                        $owner,
                        new NotifyResturantOrderCreatedNotification($order)
                    );

                    // Keep the legacy vendor event, but move it out of the
                    // customer's checkout response path.
                    broadcast(new VendorUpdated(
                        $order,
                        $this->orderCount,
                        $owner->id
                    ));
                }
            } catch (\Throwable $e) {
                Log::error('ORDER_AFTER_RESPONSE_OWNER_SIDE_EFFECT_FAILED', [
                    'order_id' => $order->id,
                    'owner_id' => $ownerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $email = $order->user?->email;

        if ($email) {
            try {
                Mail::send(
                    'emails.send_order_email',
                    ['email' => $email, 'cart' => $order],
                    function ($message) use ($email) {
                        $message->to($email);
                        $message->subject('Your order has been received!');
                    }
                );
            } catch (\Throwable $e) {
                Log::error('ORDER_AFTER_RESPONSE_EMAIL_FAILED', [
                    'order_id' => $order->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
