<?php

namespace App\Services\Dashboard;

use Google\Client;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Manual dashboard notices: actual selected device tokens, never a universal topic. */
class DashboardPushSender
{
    protected function clock(): float
    {
        return hrtime(true) / 1000000000;
    }

    protected function accessToken(float $timeout = 12): string
    {
        $path = config('firebase.credentials', storage_path('app/firebase_credentials.json'));
        if (!is_string($path) || !is_file($path)) throw new RuntimeException('credentials_missing');
        $key = 'dashboard-manual-push-token-'.hash('sha256', config('services.fcm.project_id').'|'.$path.'|'.filemtime($path));
        return Cache::remember($key, now()->addMinutes(45), function () use ($path, $timeout) {
            $client = new Client();
            $client->setHttpClient(new \GuzzleHttp\Client(['connect_timeout' => min(4, $timeout), 'timeout' => $timeout]));
            $client->setAuthConfig($path);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $result = $client->fetchAccessTokenWithAssertion();
            if (empty($result['access_token'])) throw new RuntimeException('authentication_failed');
            return $result['access_token'];
        });
    }

    public function send(array $tokens, string $title, string $text, string $accountType): array
    {
        $deadline = $this->clock() + 24;
        $accepted = []; $invalid = 0;
        foreach ($tokens as $token) {
            $token = is_array($token) ? ($token['token'] ?? null) : $token;
            if (!is_string($token) || !preg_match('/^[a-zA-Z0-9:_-]{20,4096}$/D', $token)) { $invalid++; continue; }
            $accepted[$token] = $token;
        }
        $tokens = array_values($accepted);
        $result = ['accepted' => 0, 'failed' => 0, 'attempted' => 0, 'not_sent' => count($tokens), 'invalid' => $invalid, 'reason' => null];
        if (!$tokens) return $result + ['empty' => true];
        $project = (string) config('services.fcm.project_id');
        if (!preg_match('/^[a-z][a-z0-9-]{3,62}$/D', $project)) return $this->unavailable($result, 'configuration');
        $body = ['notification' => ['title' => $title, 'body' => $text],
            'data' => ['notification_type' => '4', 'account_type' => $accountType, 'notification_sound' => 'default',
                'click_action' => url('/admin/dashboard'), 'icon' => asset('dashboard/branding/fasakhansta-logo-transparent.png')],
            'android' => ['notification' => ['sound' => 'default']], 'apns' => ['payload' => ['aps' => ['sound' => 'default']]]];
        if (strlen(json_encode($body, JSON_UNESCAPED_UNICODE)) > 3900) return $this->unavailable($result, 'payload_too_large');
        $remaining = $deadline - $this->clock();
        if ($remaining <= 0) return $this->unavailable($result, 'time_budget');
        try { $bearer = $this->accessToken(min(12, $remaining)); }
        catch (\Throwable $error) { return $this->unavailable($result, 'authentication'); }
        $url = 'https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send';
        foreach (array_chunk($tokens, 10) as $chunk) {
            $remaining = $deadline - $this->clock();
            if ($remaining <= 0.1) return $this->unavailable($result, 'time_budget');
            $timeout = min(12, $remaining);
            $attemptedBefore = $result['attempted'];
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $url, $bearer, $body, $timeout, &$result) {
                    foreach ($chunk as $key => $token) {
                        $result['attempted']++; $result['not_sent']--;
                        $pool->as((string) $key)->withToken($bearer)->acceptJson()
                            ->withOptions(['connect_timeout' => min(4, $timeout)])->timeout($timeout)->post($url, ['message' => ['token' => $token] + $body]);
                    }
                });
            } catch (\Throwable $error) {
                // A failed pooled request has no confirmed provider acknowledgement.
                // Do not claim its other in-flight requests were accepted.
                $result['failed'] += $result['attempted'] - $attemptedBefore;
                return $this->unavailable($result, 'provider');
            }
            $permanent = false;
            foreach ($responses as $response) {
                if ($response instanceof Response && $response->successful() && is_string($response->json('name')) && $response->json('name') !== '') {
                    $result['accepted']++; continue;
                }
                $result['failed']++;
                if ($response instanceof Response && in_array($response->status(), [401, 403], true)) {
                    $result['reason'] = 'permission'; $permanent = true;
                } elseif (!$result['reason']) {
                    $result['reason'] = $response instanceof Response && $response->status() === 400 ? 'invalid_device' : 'provider';
                }
            }
            $result['failed'] += max(0, $result['attempted'] - $attemptedBefore - count($responses));
            if ($permanent) {
                break;
            }
        }
        return $result;
    }

    private function unavailable(array $result, string $reason): array
    {
        $result['reason'] = $reason;
        return $result;
    }
}
