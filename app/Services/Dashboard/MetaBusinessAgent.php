<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Explicit handoff from Meta's external agent; this service can only pause it. */
class MetaBusinessAgent
{
    private const CHANNELS = ['whatsapp', 'messenger', 'instagram'];
    private const ENDPOINT = 'https://api.facebook.com/'.WhatsAppInboxAccess::PHONE_ID.'/agent_config/settings';
    private $transport;
    private ?array $configuration;

    public function __construct(?callable $transport = null, ?array $configuration = null)
    { $this->transport = $transport; $this->configuration = $configuration; }

    public static function fingerprint(array $channels): string
    {
        $channels = self::channels($channels);
        $scope = [];
        if (in_array('whatsapp', $channels, true))
            $scope[] = 'whatsapp:'.WhatsAppInboxAccess::WABA_ID.':'.WhatsAppInboxAccess::PHONE_ID;
        foreach (MetaInboxAccess::accounts() as $account)
            if (in_array($account['channel'], $channels, true))
                $scope[] = $account['channel'].':'.$account['account_id'];
        $scope = array_values(array_unique($scope)); sort($scope, SORT_STRING);
        return hash('sha256', json_encode([$channels, $scope], JSON_THROW_ON_ERROR));
    }

    /** The optional owner statement covers channels whose external settings are not API-readable. */
    public function prepare($actor, array $channels, bool $attested): array
    {
        $owner = app(MetaInboxAccess::class)->owner($actor);
        $channels = self::channels($channels);
        if ($attested) $method = 'OWNER_ATTESTED';
        else {
            if ($channels !== ['whatsapp'] || !$this->pauseWhatsApp())
                throw new RuntimeException('EXTERNAL_AGENT_PAUSE_REQUIRED');
            $method = 'VERIFIED_WHATSAPP_API';
        }
        // A long network request must not retain an actor who has since lost ownership.
        $owner = app(MetaInboxAccess::class)->owner($owner);
        return ['version' => 1, 'account_fingerprint' => self::fingerprint($channels),
            'channels' => $channels, 'method' => $method, 'actor_id' => (int)$owner->getKey(),
            'prepared_at_utc' => gmdate('Y-m-d H:i:s')];
    }

    public static function validReceipt($receipt, array $channels): bool
    {
        try {
            $channels = self::channels($channels);
            if (!is_array($receipt) || count($receipt) !== 6
                || array_diff(array_keys($receipt), ['version', 'account_fingerprint', 'channels', 'method', 'actor_id', 'prepared_at_utc'])
                || ($receipt['version'] ?? null) !== 1 || ($receipt['channels'] ?? null) !== $channels
                || !is_string($receipt['account_fingerprint'] ?? null)
                || !hash_equals(self::fingerprint($channels), $receipt['account_fingerprint'])
                || !in_array($receipt['method'] ?? null, ['OWNER_ATTESTED', 'VERIFIED_WHATSAPP_API'], true)
                || ($receipt['method'] === 'VERIFIED_WHATSAPP_API' && $channels !== ['whatsapp'])
                || !is_int($receipt['actor_id'] ?? null) || $receipt['actor_id'] < 1
                || !is_string($receipt['prepared_at_utc'] ?? null)) return false;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $receipt['prepared_at_utc'], new DateTimeZone('UTC'));
            return $date !== false && $date->format('Y-m-d H:i:s') === $receipt['prepared_at_utc']
                && $date <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } catch (\Throwable $error) { return false; }
    }

    /** Read-only, sanitized probe. No raw settings, response text or credentials leave this class. */
    public function inspectWhatsApp(): array
    {
        $response = $this->call('GET', null);
        $enabled = $this->enabled($response);
        return ['readable' => $enabled !== null, 'enabled' => $enabled,
            'status' => $enabled === null ? 'UNVERIFIED' : ($enabled ? 'ACTIVE' : 'PAUSED'),
            'http_status' => $response['status']];
    }

    private function pauseWhatsApp(): bool
    {
        $before = $this->enabled($this->call('GET', null));
        if ($before === false) return true;
        if ($before !== true) return false;
        // PUT is a documented partial update. Never replace other agent settings or enable rollout.
        $this->call('PUT', ['rollout' => ['enabled' => false]]);
        return $this->enabled($this->call('GET', null)) === false;
    }

    private static function channels(array $channels): array
    {
        if (!array_is_list($channels) || !$channels || count(array_unique($channels, SORT_REGULAR)) !== count($channels))
            throw new RuntimeException('INVALID_CHANNELS');
        foreach ($channels as $channel) if (!is_string($channel) || !in_array($channel, self::CHANNELS, true))
            throw new RuntimeException('INVALID_CHANNELS');
        return array_values(array_intersect(self::CHANNELS, $channels));
    }

    private function enabled(array $response): ?bool
    {
        if ($response['status'] !== 200 || !is_string($response['body']) || strlen($response['body']) > 131072) return null;
        try { $body = json_decode($response['body'], true, 24, JSON_THROW_ON_ERROR); }
        catch (\Throwable $error) { return null; }
        if (!is_array($body) || isset($body['error'])) return null;
        // Accept only an explicit documented rollout boolean, including a single data envelope.
        if (array_key_exists('rollout', $body) && array_key_exists('data', $body)) return null;
        $settings = $body['data'] ?? $body;
        return is_array($settings) && is_array($settings['rollout'] ?? null)
            && is_bool($settings['rollout']['enabled'] ?? null) ? $settings['rollout']['enabled'] : null;
    }

    private function call(string $method, ?array $payload): array
    {
        $failed = ['status' => 0, 'body' => ''];
        try {
            $configuration = $this->configuration ?? config('whatsapp_replies', []);
            $token = $configuration['access_token'] ?? null;
            if (!is_string($token) || strlen($token) < 16 || strlen($token) > 4096 || preg_match('/[\s\x00-\x1f\x7f]/', $token)) return $failed;
            if ($this->transport !== null) {
                $result = ($this->transport)($method, self::ENDPOINT, $payload, $token, '2.0.0');
                return is_array($result) && is_int($result['status'] ?? null) && is_string($result['body'] ?? null)
                    && strlen($result['body']) <= 131072 ? ['status' => $result['status'], 'body' => $result['body']] : $failed;
            }
            if (!function_exists('curl_init')) return $failed;
            $curl = curl_init(self::ENDPOINT); $body = ''; $limited = false;
            $headers = ['Authorization: Bearer '.$token, 'X-API-Version: 2.0.0', 'Accept: application/json'];
            if ($payload !== null) $headers[] = 'Content-Type: application/json';
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$limited): int {
                    if (strlen($body) + strlen($chunk) > 131072) { $limited = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                }]);
            if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_errno($curl); curl_close($curl);
            return $ok !== false && $error === 0 && !$limited ? ['status' => $status, 'body' => $body] : $failed;
        } catch (\Throwable $error) { return $failed; }
    }
}
