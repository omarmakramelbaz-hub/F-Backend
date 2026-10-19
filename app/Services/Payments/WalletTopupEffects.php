<?php

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/** Existing account activation and balance broadcasts, after the credit commits. */
class WalletTopupEffects
{
    public function afterCredit(int $userId): void
    {
        $user = User::withoutGlobalScopes()->find($userId);
        if (!$user) return;
        try {
            if ($user->account_type === 'vendor' && $user->balance >= $user->min_wallet
                && in_array($user->base_resturant?->status, ['disabled', 'closed'], true)) {
                $user->base_resturant?->update(['status' => 'closed']);
                $user->update(['status' => 'accepted', 'decline_reason' => null, 'expiration_date' => null]);
                Notification::send($user, new \App\Notifications\NotifyResturantOpenAgainNotification($user));
            } elseif ($user->account_type === 'delegate' && $user->balance >= $user->min_wallet
                && $user->status === 'disabled') {
                $user->update(['status' => 'accepted', 'decline_reason' => null, 'expiration_date' => null]);
                Notification::send($user, new \App\Notifications\NotifyDelegateStatusNotification($user));
            }
        } catch (\Throwable $error) {
            // Notification availability cannot reverse an already committed credit.
        }
        try {
            app(\App\Services\Dashboard\OrderProviderDelivery::class)
                ->event(new \App\Events\BalanceUpdated($user, $user->id));
        } catch (\Throwable $error) {
            // The authenticated wallet endpoint still returns the committed balance.
        }
    }
}
