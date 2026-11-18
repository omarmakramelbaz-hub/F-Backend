<?php

namespace App\Services\Dashboard;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;

/** ActivityKit updates require the activity token; an ordinary notification cannot update a closed app's widget. */
class FcmLiveActivityTransport
{
    public function message(string $fcmToken, string $activityToken, array $state, int $timestamp, bool $terminal): array
    {
        $aps = ['timestamp' => $timestamp, 'event' => $terminal ? 'end' : 'update', 'content-state' => $state];
        if ($terminal) $aps['dismissal-date'] = $timestamp;
        return ['message' => [
            'token' => $fcmToken,
            'apns' => [
                'live_activity_token' => $activityToken,
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => $aps],
            ],
        ]];
    }

    /** Only an accepted FCM message receipt acknowledges delivery to the provider. */
    public function send(string $fcmToken, string $activityToken, array $state, int $timestamp, bool $terminal): void
    {
        $project = (string) config('services.fcm.project_id');
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $project)) throw new \RuntimeException('live_activity_project_unavailable');
        $response = Http::withToken($this->accessToken())->acceptJson()->asJson()
            ->withOptions(['connect_timeout' => 4])->timeout(12)
            ->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send',
                $this->message($fcmToken, $activityToken, $state, $timestamp, $terminal));
        if (!$response->successful() || !is_string($response->json('name')) || $response->json('name') === '' || $response->json('error')) {
            // Provider bodies can contain device tokens and must not escape into logs or API responses.
            throw new \RuntimeException('live_activity_provider_refusal');
        }
    }

    protected function accessToken(): string
    {
        $client = new GoogleClient();
        $client->setHttpClient(new \GuzzleHttp\Client(['connect_timeout' => 4, 'timeout' => 12]));
        $client->setAuthConfig(storage_path('app/firebase_credentials.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->refreshTokenWithAssertion();
        $token = $client->getAccessToken()['access_token'] ?? null;
        if (!is_string($token) || $token === '') throw new \RuntimeException('live_activity_auth_unavailable');
        return $token;
    }
}
