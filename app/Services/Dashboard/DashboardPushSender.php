<?php

namespace App\Services\Dashboard;

use Google\Client;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Promise\RejectedPromise;

/** Manual dashboard notices: actual selected device tokens, never a universal topic. */
class DashboardPushSender
{
    // One bounded parallel wave per durable claim; keep the existing request deadline.
    public const BATCH_SIZE = 50;
    protected function clock(): float
    {
        return hrtime(true) / 1000000000;
    }

    protected function accessToken(float $timeout = 12): string
    {
        $path = config('firebase.credentials', storage_path('app/firebase_credentials.json'));
        if (!is_string($path) || !is_file($path)) throw new RuntimeException('credentials_missing');
        $key = 'dashboard-manual-push-token-'.hash('sha256', $this->projectId().'|'.$path.'|'.filemtime($path));
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

    public function projectId(): string
    {
        $configured = trim((string) config('services.fcm.project_id'));
        if ($configured !== '') return $configured;
        $path = config('firebase.credentials');
        if (!is_string($path) || !is_readable($path)) return (string) config('services.fcm.project_id');
        $credentials = json_decode(file_get_contents($path), true);
        return (string) ($credentials['project_id'] ?? '');
    }

    public static function validToken($token): bool
    {
        return is_string($token) && preg_match('/^[a-zA-Z0-9:_-]{20,4096}$/D', $token) === 1;
    }

    public function send(array $tokens, string $title, string $text, string $accountType): array
    {
        $deadline = $this->clock() + 24;
        $accepted = []; $invalid = 0;
        foreach ($tokens as $token) {
            $token = is_array($token) ? ($token['token'] ?? null) : $token;
            if (!self::validToken($token)) { $invalid++; continue; }
            $accepted[$token] = $token;
        }
        $tokens = array_values($accepted);
        $result = ['accepted' => 0, 'failed' => 0, 'attempted' => 0, 'not_sent' => count($tokens), 'invalid' => $invalid, 'reason' => null, 'outcomes' => []];
        if (!$tokens) return $result + ['empty' => true];
        $project = $this->projectId();
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
        foreach (array_chunk($tokens, self::BATCH_SIZE) as $chunk) {
            $remaining = $deadline - $this->clock();
            if ($remaining <= 0.1) return $this->unavailable($result, 'time_budget');
            $timeout = min(12, $remaining);
            $pool = new Pool(Http::getFacadeRoot()); $promises = [];
            foreach ($chunk as $key => $token) {
                $result['attempted']++; $result['not_sent']--;
                try {
                    $promises[$key] = $pool->as((string) $key)->withToken($bearer)->acceptJson()
                        ->withOptions(['connect_timeout' => min(4, $timeout)])->timeout($timeout)
                        ->post($url, ['message' => ['token' => $token] + $body]);
                } catch (\Throwable $error) { $promises[$key] = new RejectedPromise($error); }
            }
            // Settle independently: one dead connection must not discard other FCM acknowledgements.
            $responses = Utils::settle($promises)->wait(); $permanent = false;
            foreach ($chunk as $key => $token) {
                $response = ($responses[$key]['state'] ?? '') === 'fulfilled' ? $responses[$key]['value'] : null;
                $outcome = $this->outcome($response);
                $result['outcomes'][hash('sha256', $token)] = $outcome;
                if ($outcome['status'] === 'accepted') { $result['accepted']++; continue; }
                $result['failed']++;
                if ($outcome['status'] === 'blocked') { $permanent = true; $result['reason'] = $outcome['reason']; }
                elseif (!$result['reason']) $result['reason'] = $outcome['reason'];
            }
            if ($permanent) break;
        }
        return $result;
    }

    private function outcome($response): array
    {
        if (!$response instanceof Response) return ['status' => 'uncertain', 'reason' => 'connection'];
        if ($response->successful() && is_string($response->json('name')) && $response->json('name') !== '') {
            return ['status' => 'accepted', 'reason' => null];
        }
        $code = $response->json('error.status');
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (($detail['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') $code = $detail['errorCode'] ?? $code;
        }
        // These errors belong to a device, even when Firebase responds with HTTP 403.
        if (in_array($code, ['SENDER_ID_MISMATCH', 'UNREGISTERED'], true)) {
            return ['status' => 'failed', 'reason' => strtolower($code)];
        }
        if ($code === 'THIRD_PARTY_AUTH_ERROR') return ['status' => 'failed', 'reason' => 'apple_credentials'];
        if (in_array($response->status(), [401, 403], true)) return ['status' => 'blocked', 'reason' => 'permission'];
        if ($response->status() === 404) return ['status' => 'blocked', 'reason' => 'configuration'];
        if ($response->successful()) return ['status' => 'uncertain', 'reason' => 'missing_ack'];
        return ['status' => 'failed', 'reason' => $response->status() === 400 ? 'invalid_device' : ($response->status() === 429 ? 'quota' : 'provider')];
    }

    private function unavailable(array $result, string $reason): array
    {
        $result['reason'] = $reason;
        return $result;
    }
}
