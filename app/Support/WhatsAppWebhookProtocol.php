<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class WhatsAppWebhookProtocol
{
    public const MAX_BODY_BYTES = 4194304;

    public static function challenge(array $query, string $token): ?string
    {
        $mode = $query['hub.mode'] ?? $query['hub_mode'] ?? null;
        $supplied = $query['hub.verify_token'] ?? $query['hub_verify_token'] ?? null;
        $challenge = $query['hub.challenge'] ?? $query['hub_challenge'] ?? null;

        if ($token === '' || $mode !== 'subscribe' || !is_string($supplied)
            || !is_string($challenge) || $challenge === '' || strlen($challenge) > 1024
            || !hash_equals($token, $supplied)) {
            return null;
        }

        return $challenge;
    }

    public static function authentic(string $body, ?string $signature, string $secret): bool
    {
        return $secret !== '' && strlen($body) <= self::MAX_BODY_BYTES
            && is_string($signature) && preg_match('/\Asha256=[a-f0-9]{64}\z/', $signature) === 1
            && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    /** Return only configured accounts, preserving nested JSON objects. */
    public static function scopedPayload(string $body, array $allowedAccounts): ?string
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Payload too large');
        }
        try {
            $payload = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid JSON');
        }
        if (!$payload instanceof stdClass || ($payload->object ?? null) !== 'whatsapp_business_account'
            || !isset($payload->entry) || !is_array($payload->entry)) {
            throw new InvalidArgumentException('Invalid WhatsApp envelope');
        }

        $entries = [];
        foreach ($payload->entry as $entry) {
            if (!$entry instanceof stdClass || !isset($entry->id) || !is_string($entry->id)
                || preg_match('/\A[0-9]+\z/', $entry->id) !== 1) {
                throw new InvalidArgumentException('Invalid account identifier');
            }
            if (in_array($entry->id, $allowedAccounts, true)) {
                $entries[] = $entry;
            }
        }
        if ($entries === []) {
            return null;
        }

        // Drop unneeded envelope fields; nested event data stays intact.
        return json_encode((object) ['object' => $payload->object, 'entry' => $entries], JSON_THROW_ON_ERROR);
    }
}
