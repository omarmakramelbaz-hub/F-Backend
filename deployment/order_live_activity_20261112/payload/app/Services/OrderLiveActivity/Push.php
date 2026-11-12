<?php

namespace App\Services\OrderLiveActivity;

use App\Models\OrderLiveActivity;
use Google\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Push
{
    public static function payload(OrderLiveActivity $activity, array $state, bool $terminal, int $timestamp): array
    {
        $aps = ['timestamp' => $timestamp, 'event' => $terminal ? 'end' : 'update', 'content-state' => $state];
        if ($terminal) $aps['dismissal-date'] = $timestamp + 120;
        return ['message' => [
            'token' => $activity->fcm_token,
            'apns' => [
                'live_activity_token' => $activity->activity_token,
                'headers' => [
                    'apns-push-type' => 'liveactivity',
                    'apns-topic' => config('services.order_live_activity.bundle_id', 'com.faskhaninja.clients').'.push-type.liveactivity',
                    'apns-priority' => '10',
                ],
                'payload' => ['aps' => $aps],
            ],
        ]];
    }

    public function send(OrderLiveActivity $activity, array $state, bool $terminal): void
    {
        $project = config('services.fcm.project_id');
        if (!$project) throw new \RuntimeException('FCM project is not configured');
        // Reuse the server's existing FCM service account. Never ship keys in the client.
        $token = Cache::remember('order_live_activity_fcm_access_token', 3000, function () {
            $client = new Client();
            $client->setAuthConfig(storage_path('app/firebase_credentials.json'));
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $client->refreshTokenWithAssertion();
            $access = $client->getAccessToken();
            if (empty($access['access_token'])) throw new \RuntimeException('FCM authorization unavailable');
            return $access['access_token'];
        });
        $response = Http::timeout(12)->withToken($token)->post(
            'https://fcm.googleapis.com/v1/projects/'.rawurlencode($project).'/messages:send',
            self::payload($activity, $state, $terminal, time())
        );
        if ($response->successful()) return;
        $details = $response->json('error.details') ?? [];
        foreach ($details as $detail) {
            if (($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                $activity->update(['ended_at' => now()]);
                return;
            }
        }
        if ($response->status() === 401) Cache::forget('order_live_activity_fcm_access_token');
        // Do not include provider responses or device tokens in logs/exceptions.
        throw new \RuntimeException('ActivityKit delivery failed (HTTP '.$response->status().')');
    }
}
