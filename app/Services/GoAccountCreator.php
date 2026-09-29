<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/** Creates an account and its one-time opening credit in the same transaction. */
class GoAccountCreator
{
    public const OPENING_BALANCE = '50.00';

    public static function openingReference(int $userId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://fasakhaninja.com/go/opening-balance/'.$userId)->toString();
    }

    public function create(array $attributes): User
    {
        $scope = $attributes['app_scope'] ?? null;
        $type = $attributes['account_type'] ?? null;
        $eligible = ($scope === 'go' && $type === 'user')
            || ($scope === 'go_partner' && in_array($type, ['delegate', 'vendor'], true));
        if (!$eligible) return User::create($attributes);

        // Only called for a new account. Login, activation of an existing
        // account, profile edits and password recovery never re-credit it.
        return DB::transaction(function () use ($attributes) {
            $attributes['balance'] = self::OPENING_BALANCE;
            $user = User::create($attributes);
            $entry = [
                'from_user' => $user->id, 'to_user' => $user->id,
                'type' => 'charging', 'payment' => 'wallet', 'status' => 'completed',
                'amount' => self::OPENING_BALANCE,
            ];
            // Older deployments can still create a complete, atomic ledger
            // entry before the optional transfer-reference migration is run.
            if (Schema::hasColumn('wallets', 'transfer_reference')) {
                $entry['transfer_reference'] = self::openingReference((int) $user->id);
            }
            Wallet::create($entry);
            return $user;
        });
    }
}
