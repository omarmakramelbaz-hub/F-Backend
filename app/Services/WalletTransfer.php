<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Notifications\NotifyTransferWallet;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletTransfer
{
    public const TARGETS = ['go_customer', 'go_partner', 'fasakhansta_customer'];

    private function fail(string $key): void
    {
        throw ValidationException::withMessages(['target_wallet' => __('wallet_transfer.'.$key)]);
    }

    private function recipient(string $mobile, string $target): User
    {
        if (!in_array($target, self::TARGETS, true)) $this->fail('select_wallet');
        $mobile = PartnerEmailVerification::mobile($mobile);
        // Old rows sometimes retain the local leading zero. Never select the
        // first match when multiple accounts in the chosen wallet group exist.
        $query = User::withoutGlobalScopes()->whereIn('mobile', [$mobile, '0'.$mobile]);
        if ($target === 'go_customer') {
            $query->where('account_type', 'user')->where('app_scope', 'go');
        } elseif ($target === 'fasakhansta_customer') {
            $query->where('account_type', 'user')->where(function ($q) {
                $q->where('app_scope', 'fasakhansta')->orWhereNull('app_scope');
            });
        } else {
            $query->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('app_scope', 'go_partner')->whereIn('account_type', ['delegate', 'vendor']);
                })->orWhere(function ($q) {
                    // Match the existing GO Partner login compatibility rule.
                    $q->where('account_type', 'delegate')->where(function ($q) {
                        $q->where('app_scope', 'fasakhansta')->orWhereNull('app_scope');
                    });
                });
            });
        }
        $users = $query->limit(2)->get();
        if ($users->isEmpty()) $this->fail('not_found');
        if ($users->count() !== 1) $this->fail('ambiguous');
        $user = $users->first();
        if (!in_array($user->status, ['accepted', 'disabled'], true)) $this->fail('unavailable');
        return $user;
    }

    public function preview(User $sender, array $data): array
    {
        $recipient = $this->recipient($data['mobile'], $data['target_wallet']);
        if ((int) $sender->id === (int) $recipient->id) $this->fail('self');
        $sender = User::withoutGlobalScopes()->findOrFail($sender->id);
        if ($sender->balance < $data['amount']) $this->fail('insufficient');
        $payload = [
            'sender_id' => (int) $sender->id,
            'recipient_id' => (int) $recipient->id,
            'target_wallet' => $data['target_wallet'],
            'mobile' => PartnerEmailVerification::mobile($data['mobile']),
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'reference' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ];
        return [
            'user_id' => $recipient->id,
            'username' => $recipient->name,
            'mobile' => $payload['mobile'],
            'target_wallet' => $data['target_wallet'],
            'transfer_token' => Crypt::encryptString(json_encode($payload)),
        ];
    }

    public function transfer(User $sender, array $data): Wallet
    {
        try {
            $claim = json_decode(Crypt::decryptString($data['transfer_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->fail('confirm_again');
        }
        if (!is_array($claim)
            || ($claim['sender_id'] ?? null) !== (int) $sender->id
            || ($claim['recipient_id'] ?? null) !== (int) $data['recipient_id']
            || ($claim['target_wallet'] ?? null) !== $data['target_wallet']
            || ($claim['mobile'] ?? null) !== PartnerEmailVerification::mobile($data['mobile'])
            || ($claim['amount'] ?? null) !== number_format((float) $data['amount'], 2, '.', '')
            || empty($claim['reference'])) $this->fail('confirm_again');

        $created = false;
        $wallet = DB::transaction(function () use ($sender, $data, $claim, &$created) {
            // Consistent lock order prevents opposing transfers from deadlocking.
            $users = User::withoutGlobalScopes()->whereIn('id', [$sender->id, $data['recipient_id']])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = Wallet::where('transfer_reference', $claim['reference'])->first();
            if ($existing) return $existing; // Retrying the same confirmation never pays twice.
            if (($claim['expires_at'] ?? 0) < now()->timestamp) $this->fail('confirm_again');
            $recipient = $this->recipient($data['mobile'], $data['target_wallet']);
            if ((int) $recipient->id !== (int) $data['recipient_id']) $this->fail('confirm_again');
            if ((int) $sender->id === (int) $recipient->id) $this->fail('self');
            $lockedSender = $users->get($sender->id);
            $lockedRecipient = $users->get($recipient->id);
            if (!$lockedSender || !$lockedRecipient) $this->fail('unavailable');
            if (!DB::table('users')->where('id', $sender->id)->where('balance', '>=', $claim['amount'])
                ->decrement('balance', $claim['amount'])) $this->fail('insufficient');
            DB::table('users')->where('id', $recipient->id)->increment('balance', $claim['amount']);
            $wallet = Wallet::create([
                'from_user' => $sender->id, 'to_user' => $recipient->id,
                'type' => 'transfer', 'payment' => 'wallet', 'status' => 'completed',
                'amount' => $claim['amount'], 'transfer_reference' => $claim['reference'],
            ]);
            // Preserve the existing reactivation rule for a funded account.
            if ($lockedRecipient->status === 'disabled'
                && $lockedRecipient->balance + (float) $claim['amount'] > $lockedRecipient->min_wallet / 2) {
                $lockedRecipient->update(['status' => 'accepted']);
            }
            $created = true;
            return $wallet;
        }, 3);

        // A notification outage must not turn a committed transfer into a
        // reported failure that encourages the sender to pay again.
        if ($created) {
            try {
                Notification::send(User::withoutGlobalScopes()->findOrFail($wallet->to_user),
                    new NotifyTransferWallet($sender, $wallet->amount));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return $wallet;
    }
}
