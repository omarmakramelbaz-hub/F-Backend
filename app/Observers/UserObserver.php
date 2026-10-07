<?php

namespace App\Observers;

use App\Events\BalanceUpdated;
use App\Models\User;

class UserObserver
{
    /**
     * Listen to the updated event.
     */
    public function updated(User $user)
    {
        if ($user->isDirty('balance')) {
            // Broadcast the event
            app(\App\Services\Dashboard\OrderProviderDelivery::class)->event(new BalanceUpdated($user, $user->id));
        }
    }
}
