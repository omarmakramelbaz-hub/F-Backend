<?php
namespace App\Observers;

use Illuminate\Notifications\DatabaseNotification;
use App\Events\NotificationUpdated;
use App\Models\User;

class NotificationObserver
{
    /**
     * Handle the "created" event.
     */
    public function created(DatabaseNotification $notification)
    {
        if ($notification->notifiable_type === 'App\Models\User') {
            // $user = User::findOrFail($notification->notifiable_id);
            app(\App\Services\Dashboard\OrderProviderDelivery::class)->event(
                new NotificationUpdated($notification->data, $notification->notifiable_id),
                isset($notification->data['data']['order_id']) ? (int) $notification->data['data']['order_id'] : null
            );
        }
    }
}
