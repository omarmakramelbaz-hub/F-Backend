<?php

namespace App\Services\Dashboard;

use App\Models\Order;
use App\Models\OrderLiveActivity;
use App\Services\OrderTrackingState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class OrderLiveActivityService
{
    public function __construct(private FcmLiveActivityTransport $transport) {}

    public function register(int $orderId, object $actor, array $input): array
    {
        $order = $this->customerOrder($orderId, $actor);
        $data = Validator::make($input, [
            'activity_id' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_.:\-]+\z/'],
            'activity_token' => ['required', 'string', 'regex:/\A(?:[a-fA-F0-9]{2}){16,256}\z/'],
            'fcm_token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/\A\S+\z/u'],
        ])->validate();
        abort_unless(Schema::hasTable('order_live_activities'), 503, 'Live Activity registration is temporarily unavailable.');
        $this->pruneExpired();
        $identity = ['order_id' => $orderId, 'user_id' => (int) $actor->id,
            'activity_id_hash' => hash('sha256', $data['activity_id'])];
        $tokenHash = hash('sha256', strtolower($data['activity_token']));
        $fcmHash = hash('sha256', $data['fcm_token']);
        DB::transaction(function () use ($identity, $data, $tokenHash, $fcmHash) {
            $time = now('UTC');
            DB::table('order_live_activities')->insertOrIgnore($identity + [
                'activity_token' => Crypt::encryptString(strtolower($data['activity_token'])),
                'fcm_token' => Crypt::encryptString($data['fcm_token']), 'token_hash' => $tokenHash,
                'fcm_token_hash' => $fcmHash, 'expires_at' => $time->copy()->addHours(8),
                'next_attempt_at' => $time, 'created_at' => $time, 'updated_at' => $time,
            ]);
            $registration = OrderLiveActivity::where($identity)->lockForUpdate()->firstOrFail();
            // An acknowledged end cannot be reopened by a delayed duplicate registration.
            if ($registration->ended_at) return;
            if ($registration->token_hash !== $tokenHash || $registration->fcm_token_hash !== $fcmHash) {
                $registration->activity_token = Crypt::encryptString(strtolower($data['activity_token']));
                $registration->fcm_token = Crypt::encryptString($data['fcm_token']);
                $registration->token_hash = $tokenHash;
                $registration->fcm_token_hash = $fcmHash;
                $registration->state_hash = null;
                $registration->attempts = 0;
            }
            $registration->next_attempt_at = $time;
            $registration->save();
        });
        // Catch up even if the activity's token arrived after completion. Runs after the API response.
        $this->scheduleOrder($orderId);
        $state = OrderTrackingState::forOrder($order);
        return ['order_id' => $state['order_id'], 'order_no' => $state['order_no'],
            'order_status' => $state['status'], 'is_terminal' => $state['is_terminal']];
    }

    public function unregister(int $orderId, object $actor, array $input): void
    {
        $this->customerOrder($orderId, $actor);
        $data = Validator::make($input, ['activity_id' => ['required', 'string', 'max:255']])->validate();
        // This device ended its own activity locally; registrations on other devices remain active.
        OrderLiveActivity::where('order_id', $orderId)->where('user_id', (int) $actor->id)
            ->where('activity_id_hash', hash('sha256', $data['activity_id']))->delete();
    }

    private function customerOrder(int $orderId, object $actor): Order
    {
        abort_unless(($actor->account_type ?? null) === 'user', 403);
        return Order::withoutGlobalScopes()->whereKey($orderId)->where('user_id', (int) $actor->id)->firstOrFail();
    }

    public function scheduleOrder(int $orderId): void
    {
        app(OrderProviderDelivery::class)->defer(fn () => $this->deliverOrder($orderId), $orderId, 'live_activity');
    }

    public function deliverOrder(int $orderId): void
    {
        if (!Schema::hasTable('order_live_activities')) return;
        $ids = OrderLiveActivity::where('order_id', $orderId)->whereNull('ended_at')
            ->where('expires_at', '>', now('UTC'))->pluck('id');
        foreach ($ids as $id) $this->deliverRegistration((int) $id);
    }

    /** Cron retries provider failures, including terminal events, until acknowledgement or ActivityKit's eight-hour lifetime. */
    public function retryPending(): int
    {
        if (!Schema::hasTable('order_live_activities')) return 0;
        $this->pruneExpired();
        $ids = OrderLiveActivity::whereNull('ended_at')->where('expires_at', '>', now('UTC'))
            ->where('next_attempt_at', '<=', now('UTC'))->orderBy('next_attempt_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) $this->deliverRegistration((int) $id);
        return $ids->count();
    }

    private function deliverRegistration(int $id): void
    {
        $attempt = DB::transaction(function () use ($id) {
            // Reserve a strictly increasing ActivityKit timestamp under a short row lock.
            // Provider I/O happens after this transaction, without holding a database lock.
            $registration = OrderLiveActivity::whereKey($id)->lockForUpdate()->first();
            if (!$registration || $registration->ended_at
                || \Carbon\Carbon::parse($registration->getRawOriginal('expires_at'), 'UTC')->lte(now('UTC'))) return null;
            $order = Order::withoutGlobalScopes()->whereKey($registration->order_id)
                ->where('user_id', $registration->user_id)->with('resturant')->first();
            if (!$order) { $registration->delete(); return null; }
            $state = OrderTrackingState::forOrder($order);
            $content = [
                'appGroupId' => 'group.faskhaninja.liveactivities',
                'order_id' => (string) $state['order_id'], 'order_no' => $state['order_no'],
                'order_status' => $state['live_activity_status'], 'status' => $state['status'],
                'restaurant_name' => (string) ($order->resturant?->name ?? ''),
                'updateId' => (float) $state['updated_at'],
            ];
            $stateHash = hash('sha256', json_encode([$content, $state['updated_at'], $state['is_terminal']]));
            if ($registration->state_hash === $stateHash) {
                $registration->next_attempt_at = now('UTC')->addMinute(); $registration->save(); return null;
            }
            $timestamp = max(now('UTC')->timestamp, (int) $registration->last_timestamp + 1, (int) $state['updated_at']);
            $registration->last_timestamp = $timestamp;
            $registration->next_attempt_at = now('UTC')->addMinute();
            $registration->save();
            return ['id' => $id, 'order_id' => (int) $registration->order_id, 'timestamp' => $timestamp,
                'token_hash' => $registration->token_hash, 'fcm_token_hash' => $registration->fcm_token_hash,
                'fcm_token' => $registration->fcm_token, 'activity_token' => $registration->activity_token,
                'state_hash' => $stateHash, 'content' => $content, 'terminal' => $state['is_terminal'],
                'attempts' => (int) $registration->attempts];
        });
        if (!$attempt) return;
        // Compare-and-set acknowledgement: a concurrent token rotation or newer event wins.
        $current = fn () => OrderLiveActivity::whereKey($id)->whereNull('ended_at')
            ->where('token_hash', $attempt['token_hash'])->where('fcm_token_hash', $attempt['fcm_token_hash'])
            ->where('last_timestamp', $attempt['timestamp']);
        try {
            $this->transport->send(Crypt::decryptString($attempt['fcm_token']),
                Crypt::decryptString($attempt['activity_token']), $attempt['content'], $attempt['timestamp'], $attempt['terminal']);
            $changes = ['state_hash' => $attempt['state_hash'], 'attempts' => 0,
                'next_attempt_at' => now('UTC')->addMinute(), 'updated_at' => now('UTC')];
            if ($attempt['terminal']) {
                $changes += ['ended_at' => now('UTC'), 'activity_token' => '', 'fcm_token' => ''];
                $changes['next_attempt_at'] = null;
            }
            $current()->update($changes);
        } catch (\Throwable $error) {
            $attempts = min(65535, $attempt['attempts'] + 1);
            $current()->update(['attempts' => $attempts, 'updated_at' => now('UTC'),
                'next_attempt_at' => now('UTC')->addSeconds(min(900, 60 * (2 ** min(4, $attempts - 1))))]);
            Log::warning('Order Live Activity delivery unavailable', ['order_id' => $attempt['order_id'],
                'exception' => get_class($error)]);
        }
    }

    private function pruneExpired(): void
    {
        $ids = OrderLiveActivity::where('expires_at', '<=', now('UTC'))->orderBy('id')->limit(100)->pluck('id');
        if ($ids->isNotEmpty()) OrderLiveActivity::whereIn('id', $ids)->delete();
    }
}
