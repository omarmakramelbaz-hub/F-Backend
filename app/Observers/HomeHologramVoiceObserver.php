<?php
namespace App\Observers;

use App\Models\User;
use App\Services\Dashboard\DashboardLoginIdentity;
use App\Services\Dashboard\HomeHologramVoice;

class HomeHologramVoiceObserver
{
    public function created(User $user): void
    {
        if (in_array($user->account_type, DashboardLoginIdentity::ACCOUNT_TYPES, true)) app(HomeHologramVoice::class)->prewarm($user);
    }

    public function updated(User $user): void
    {
        // Balance/order activity never creates voice work.
        if (in_array($user->account_type, DashboardLoginIdentity::ACCOUNT_TYPES, true)
            && $user->wasChanged(['name', 'account_type'])) app(HomeHologramVoice::class)->prewarm($user);
    }
}
