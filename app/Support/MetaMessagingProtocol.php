<?php

namespace App\Support;

use InvalidArgumentException;

final class MetaMessagingProtocol
{
    public static function scopedPayload(string $body, array $accounts): ?string
    {
        if (strlen($body) > WhatsAppWebhookProtocol::MAX_BODY_BYTES) throw new InvalidArgumentException('SIZE');
        try { $payload = json_decode($body, false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new InvalidArgumentException('JSON'); }
        if (!$payload instanceof \stdClass || !in_array($payload->object ?? null, ['page', 'instagram'], true)
            || !is_array($payload->entry ?? null) || count($payload->entry) > 100) throw new InvalidArgumentException('ENVELOPE');
        $channel = $payload->object === 'page' ? 'messenger' : 'instagram';
        $entries = [];
        foreach ($payload->entry as $entry) {
            if (!$entry instanceof \stdClass || !self::id($entry->id ?? null)) throw new InvalidArgumentException('ACCOUNT');
            foreach ($accounts as $a) if (($a['channel'] ?? null) === $channel && ($a['account_id'] ?? null) === $entry->id) {
                $entries[] = $entry; break;
            }
        }
        return $entries ? json_encode((object) ['object' => $payload->object, 'entry' => $entries], JSON_THROW_ON_ERROR) : null;
    }

    /** Inbound messages, native business echoes, standby events, text and attachments. */
    public static function report(array $payload, array $accounts): array
    {
        $channel = ($payload['object'] ?? null) === 'page' ? 'messenger'
            : (($payload['object'] ?? null) === 'instagram' ? 'instagram' : null);
        if ($channel === null || !is_array($payload['entry'] ?? null)) throw new InvalidArgumentException('ENVELOPE');
        $messages = []; $ignored = 0;
        foreach ($payload['entry'] as $entry) {
            $id = $entry['id'] ?? null; $allowed = false;
            foreach ($accounts as $a) if (($a['channel'] ?? null) === $channel && ($a['account_id'] ?? null) === $id) $allowed = true;
            if (!$allowed) { $ignored++; continue; }
            foreach (['messaging', 'standby'] as $source) {
                $events = $entry[$source] ?? [];
                if (!is_array($events) || !array_is_list($events) || count($events) > 1000) throw new InvalidArgumentException('EVENTS');
                foreach ($events as $event) {
                    if (!is_array($event)) throw new InvalidArgumentException('EVENT');
                    if (!isset($event['message'])) { $ignored++; continue; } // receipts, reads, reactions, postbacks
                    $raw = $event['message'];
                    $sender = $event['sender']['id'] ?? null; $recipient = $event['recipient']['id'] ?? null;
                    $mid = $raw['mid'] ?? null; $stamp = $event['timestamp'] ?? null;
                    if (!is_array($raw) || !self::id($sender) || !self::id($recipient) || !is_string($mid)
                        || $mid === '' || strlen($mid) > 512 || preg_match('/[\x00-\x1f\x7f]/', $mid)
                        || !(is_int($stamp) || (is_string($stamp) && ctype_digit($stamp)))) throw new InvalidArgumentException('MESSAGE');
                    $outbound = $sender === $id;
                    if (($outbound ? $sender : $recipient) !== $id || $sender === $recipient
                        || (($raw['is_echo'] ?? false) === true && !$outbound)) throw new InvalidArgumentException('ROUTING');
                    $seconds = (int) floor((float) $stamp / 1000);
                    if ($seconds < 946684800 || $seconds > time() + 86400) throw new InvalidArgumentException('TIME');
                    $text = $raw['text'] ?? null;
                    if ($text !== null && (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || strlen($text) > 100000)) throw new InvalidArgumentException('TEXT');
                    $type = $text !== null ? 'text' : 'other';
                    $normalized = ['id' => $mid, 'meta' => $raw];
                    $attachments = $raw['attachments'] ?? [];
                    if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 30) throw new InvalidArgumentException('ATTACHMENTS');
                    foreach ($attachments as $attachment) {
                        if (!is_array($attachment)) throw new InvalidArgumentException('ATTACHMENT');
                        $kind = $attachment['type'] ?? 'other';
                        if ($text === null && $type === 'other') $type = in_array($kind, ['image', 'video', 'audio', 'location'], true) ? $kind : 'document';
                        if ($kind === 'location') {
                            $point = $attachment['payload']['coordinates'] ?? null;
                            if (!is_array($point) || !is_numeric($point['lat'] ?? null) || !is_numeric($point['long'] ?? null)
                                || !is_finite((float)$point['lat']) || !is_finite((float)$point['long'])
                                || abs((float)$point['lat']) > 90 || abs((float)$point['long']) > 180) throw new InvalidArgumentException('LOCATION');
                            $normalized['location'] = ['latitude' => (float)$point['lat'], 'longitude' => (float)$point['long']];
                        }
                    }
                    // Deleted/unsupported/attachment-only messages remain visible but cannot supply order facts.
                    $peer = $outbound ? $recipient : $sender;
                    $messages[] = ['channel' => $channel, 'account_id' => $id,
                        'message_id' => $mid, 'peer_identity' => 'user:'.$peer, 'peer_user_id' => $peer,
                        'peer_phone' => null, 'customer_name' => null, 'direction' => $outbound ? 'outbound' : 'inbound',
                        'type' => $type, 'text' => $text, 'sent_at' => gmdate('Y-m-d H:i:s', $seconds),
                        'source' => $source === 'standby' ? 'standby' : ($outbound ? 'message_echoes' : 'messages'),
                        'content' => ['sources' => [$source], 'message' => $normalized]];
                }
            }
        }
        return ['messages' => $messages, 'ignored_count' => $ignored];
    }

    public static function id($id): bool { return is_string($id) && preg_match('/\A[1-9][0-9]{0,31}\z/', $id) === 1; }
}
