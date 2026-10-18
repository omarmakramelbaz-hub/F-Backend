<?php

namespace App\Services\Dashboard;

use App\Support\WhatsAppInboxProtocol;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Projects verified, encrypted webhook events without calling Meta or creating orders. */
class WhatsAppInboxConsumer
{
    public const WABA_ID = '468336579702269';
    public const PHONE_ID = '515388018324075';
    public const QUARANTINE_BATCH_RECHECK = true;
    private const MAX_EVENT_BYTES = 4194304;

    public function consume(int $limit = 100, bool $retryQuarantined = false): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Inbox limit must be between 1 and 500.');
        }
        $key = $this->hashKey();
        $metrics = [
            'events_seen' => 0, 'events_processed' => 0, 'events_skipped' => 0,
            'messages_inserted' => 0, 'messages_replayed' => 0,
            'quarantined_events' => 0, 'ignored_records' => 0,
            'status_records' => 0, 'errors' => 0,
        ];
        // Capture a bounded batch. Every row is re-read under a transaction lock.
        $batch = DB::table('whatsapp_webhook_events')->whereNull('processed_at');
        if (!$retryQuarantined) {
            $batch->whereNotExists(function ($query) {
                $query->select('event_id')->from('whatsapp_inbox_ingestion_failures')
                    ->whereColumn('whatsapp_inbox_ingestion_failures.event_id', 'whatsapp_webhook_events.id');
            });
        }
        $ids = $batch->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $metrics['events_seen']++;
            try {
                $result = $this->withUniqueRetry(function () use ($id, $key, $retryQuarantined) {
                    $event = DB::table('whatsapp_webhook_events')->where('id', $id)
                        ->lockForUpdate()->first();
                    if (!$event || $event->processed_at !== null) {
                        return ['events_skipped' => 1];
                    }
                    // A quarantine can be recorded after batch selection while this worker waits
                    // for the raw-event lock. Automatic projection must preserve that decision.
                    if (!$retryQuarantined && DB::table('whatsapp_inbox_ingestion_failures')
                        ->where('event_id', $id)->lockForUpdate()->first()) {
                        return ['events_skipped' => 1];
                    }
                    try {
                        $json = Crypt::decryptString($event->payload);
                        if (strlen($json) > self::MAX_EVENT_BYTES) {
                            throw new WhatsAppInboxQuarantinedEvent();
                        }
                        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($payload)) {
                            throw new WhatsAppInboxQuarantinedEvent();
                        }
                        $report = WhatsAppInboxProtocol::report($payload, self::WABA_ID, self::PHONE_ID);
                    } catch (DecryptException | \JsonException | InvalidArgumentException $error) {
                        throw new WhatsAppInboxQuarantinedEvent();
                    }
                    if (!is_array($report['messages'] ?? null)
                        || !is_int($report['quarantined_count'] ?? null)
                        || !is_int($report['ignored_count'] ?? null)
                        || !is_int($report['status_count'] ?? null)
                        || $report['quarantined_count'] < 0
                        || $report['ignored_count'] < 0 || $report['status_count'] < 0
                        || $report['quarantined_count'] > 0) {
                        throw new WhatsAppInboxQuarantinedEvent();
                    }
                    $counts = [
                        'events_processed' => 1, 'messages_inserted' => 0,
                        'messages_replayed' => 0,
                        'ignored_records' => $report['ignored_count'],
                        'status_records' => $report['status_count'],
                    ];
                    foreach ($report['messages'] as $message) {
                        $this->assertMessage($message);
                        $kind = $this->persist($message, $key);
                        $counts[$kind === 'inserted' ? 'messages_inserted' : 'messages_replayed']++;
                    }
                    DB::table('whatsapp_webhook_events')->where('id', $id)
                        ->update(['processed_at' => now('UTC')]);
                    DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $id)->delete();
                    return $counts;
                });
                foreach ($result as $name => $count) {
                    $metrics[$name] += $count;
                }
            } catch (WhatsAppInboxQuarantinedEvent $error) {
                // The transaction rolled back. Retain the encrypted raw event unprocessed.
                $metrics['quarantined_events']++;
                try {
                    $this->recordQuarantine((int) $id, $error->reasonCode());
                } catch (Throwable $recordError) {
                    $metrics['errors']++;
                }
            } catch (Throwable $error) {
                // Never include an exception message, customer data, or ciphertext in output.
                $metrics['errors']++;
            }
        }
        return $metrics;
    }

    private function withUniqueRetry(callable $callback): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback, 3);
            } catch (QueryException $error) {
                // Retry the whole transaction. A failed statement cannot be recovered
                // by issuing more SQL within a PostgreSQL transaction.
                if ($attempt >= 3 || !$this->knownUniqueConflict($error)) throw $error;
            }
        }
    }

    private function knownUniqueConflict(QueryException $error): bool
    {
        $info = $error->errorInfo ?? [];
        $state = $info[0] ?? null;
        $code = $info[1] ?? null;
        $detail = $info[2] ?? '';
        if (!is_string($detail) || !in_array($state, ['23000', '23505'], true)) return false;
        if ($state === '23000' && !in_array((int) $code, [1062, 19], true)) return false;
        if (preg_match('/\b(?:wa_inbox_peer_unique|wa_inbox_message_unique)\b/', $detail)) return true;
        return (int) $code === 19 && in_array($detail, [
            'UNIQUE constraint failed: whatsapp_inbox_messages.message_key',
            'UNIQUE constraint failed: whatsapp_inbox_conversations.waba_id, whatsapp_inbox_conversations.phone_number_id, whatsapp_inbox_conversations.peer_hash',
        ], true);
    }

    private function recordQuarantine(int $eventId, string $reason): void
    {
        DB::transaction(function () use ($eventId, $reason) {
            // Serialize quarantine metadata against a concurrent successful retry.
            $event = DB::table('whatsapp_webhook_events')->where('id', $eventId)->lockForUpdate()->first();
            if (!$event || $event->processed_at !== null) return;
            $failure = DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $eventId)->lockForUpdate()->first();
            $values = [
                'reason' => $reason, 'attempts' => $failure ? min(4294967295, (int) $failure->attempts + 1) : 1,
                'last_attempted_at' => now('UTC'),
            ];
            if ($failure) {
                DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $eventId)->update($values);
            } else {
                DB::table('whatsapp_inbox_ingestion_failures')->insert(array_merge(['event_id' => $eventId], $values));
            }
        }, 3);
    }

    private function hashKey(): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Inbox encryption is not configured.');
        }
        if (strpos($key, 'base64:') === 0) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (!is_string($key) || strlen($key) < 16) {
            throw new RuntimeException('Inbox encryption is not configured.');
        }
        return $key;
    }

    private function assertMessage($message): void
    {
        if (!is_array($message)) throw new WhatsAppInboxQuarantinedEvent();
        foreach (['message_id', 'peer_identity', 'direction', 'type', 'sent_at', 'source'] as $field) {
            if (!is_string($message[$field] ?? null) || $message[$field] === '') {
                throw new WhatsAppInboxQuarantinedEvent();
            }
        }
        foreach (['peer_user_id', 'peer_phone', 'customer_name', 'text'] as $field) {
            if (!array_key_exists($field, $message)
                || ($message[$field] !== null && !is_string($message[$field]))) {
                throw new WhatsAppInboxQuarantinedEvent();
            }
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $message['sent_at'], new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d H:i:s') !== $message['sent_at']
            || !in_array($message['direction'], ['inbound', 'outbound'], true)
            || !in_array($message['source'], ['messages', 'standby', 'smb_message_echoes'], true)
            || strlen($message['type']) > 40 || strlen($message['message_id']) > 512
            || strlen($message['peer_identity']) > 512
            || !is_array($message['content'] ?? null)
            || !is_array($message['content']['sources'] ?? null)
            || !is_array($message['content']['message'] ?? null)) {
            throw new WhatsAppInboxQuarantinedEvent();
        }
        $identity = $message['peer_identity'];
        $expected = $message['peer_user_id'] !== null
            ? 'user:' . $message['peer_user_id']
            : ($message['peer_phone'] !== null ? 'phone:' . $message['peer_phone'] : null);
        if ($expected === null || $identity !== $expected) throw new WhatsAppInboxQuarantinedEvent();
        foreach ($message['content']['sources'] as $source) {
            if (!in_array($source, ['messages', 'standby', 'smb_message_echoes'], true)) {
                throw new WhatsAppInboxQuarantinedEvent();
            }
        }
    }

    private function persist(array $message, string $key): string
    {
        $scope = self::WABA_ID . ':' . self::PHONE_ID . ':';
        $peerHash = hash_hmac('sha256', 'whatsapp-peer-v1:' . $scope . $message['peer_identity'], $key);
        $messageKey = hash('sha256', 'whatsapp-message-v1:' . $scope . $message['message_id']);
        $existing = DB::table('whatsapp_inbox_messages')->where('message_key', $messageKey)
            ->lockForUpdate()->first();
        if ($existing) {
            $conversation = DB::table('whatsapp_inbox_conversations')->where('id', $existing->conversation_id)
                ->lockForUpdate()->first();
            if (!$conversation || $conversation->waba_id !== self::WABA_ID
                || $conversation->phone_number_id !== self::PHONE_ID
                || !hash_equals($conversation->peer_hash, $peerHash)
                || $existing->direction !== $message['direction'] || $existing->type !== $message['type']) {
                throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
            }
            try {
                $saved = json_decode(Crypt::decryptString($existing->content), true, 512, JSON_THROW_ON_ERROR);
            } catch (DecryptException | \JsonException $error) {
                throw new WhatsAppInboxQuarantinedEvent();
            }
            $this->assertMessage($saved);
            foreach (['message_id', 'peer_identity', 'direction', 'type', 'text', 'sent_at'] as $field) {
                if (!array_key_exists($field, $saved) || $saved[$field] !== $message[$field]) {
                    throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
                }
            }
            // Copies can contain different routing fields. Message content must agree.
            $fields = array_unique(array_merge([$message['type']], ['errors', 'text', 'image', 'video', 'document',
                'audio', 'sticker', 'location', 'contacts', 'interactive', 'button', 'reaction', 'order', 'system']));
            foreach ($fields as $field) {
                if ($this->canonical($saved['content']['message'][$field] ?? null)
                    !== $this->canonical($message['content']['message'][$field] ?? null)) {
                    throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
                }
            }
            $sources = $saved['content']['sources'] ?? null;
            if (!is_array($sources)) throw new WhatsAppInboxQuarantinedEvent();
            $merged = array_values(array_unique(array_merge($sources, $message['content']['sources'])));
            sort($merged);
            $oldSources = $sources;
            sort($oldSources);
            if ($merged !== $oldSources) {
                $saved['content']['sources'] = $merged;
                DB::table('whatsapp_inbox_messages')->where('id', $existing->id)->update([
                    'content' => Crypt::encryptString(json_encode($saved, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'updated_at' => now('UTC'),
                ]);
            }
            return 'replayed';
        }

        $conversation = DB::table('whatsapp_inbox_conversations')
            ->where('waba_id', self::WABA_ID)->where('phone_number_id', self::PHONE_ID)
            ->where('peer_hash', $peerHash)->lockForUpdate()->first();
        $now = now('UTC');
        if (!$conversation) {
            $customer = [
                'name' => $message['customer_name'], 'phone' => $message['peer_phone'],
                'user_id' => $message['peer_user_id'],
            ];
            $row = [
                'waba_id' => self::WABA_ID, 'phone_number_id' => self::PHONE_ID,
                'peer_hash' => $peerHash,
                'customer' => Crypt::encryptString(json_encode($customer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'first_message_at' => $message['sent_at'], 'last_message_at' => $message['sent_at'],
                'created_at' => $now, 'updated_at' => $now,
            ];
            $id = DB::table('whatsapp_inbox_conversations')->insertGetId($row);
            $conversation = DB::table('whatsapp_inbox_conversations')->where('id', $id)
                ->lockForUpdate()->first();
        }
        if (!$conversation) throw new RuntimeException('Inbox persistence failed.');
        // Do not replace a known customer's identity with information from another copy.
        try {
            $customer = json_decode(Crypt::decryptString($conversation->customer), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException $error) {
            throw new WhatsAppInboxQuarantinedEvent();
        }
        if (!is_array($customer)) throw new WhatsAppInboxQuarantinedEvent();
        $incoming = ['name' => $message['customer_name'], 'phone' => $message['peer_phone'], 'user_id' => $message['peer_user_id']];
        foreach (['phone', 'user_id'] as $field) {
            if (($customer[$field] ?? null) !== null && $incoming[$field] !== null
                && $customer[$field] !== $incoming[$field]) throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
        }
        foreach ($incoming as $field => $value) {
            if (($customer[$field] ?? null) === null && $value !== null) $customer[$field] = $value;
        }
        DB::table('whatsapp_inbox_messages')->insert([
            'conversation_id' => $conversation->id, 'message_key' => $messageKey,
            'type' => $message['type'], 'direction' => $message['direction'], 'source' => $message['source'],
            'content' => Crypt::encryptString(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'sent_at' => $message['sent_at'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('whatsapp_inbox_conversations')->where('id', $conversation->id)->update([
            'customer' => Crypt::encryptString(json_encode($customer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'first_message_at' => $conversation->first_message_at === null || $message['sent_at'] < $conversation->first_message_at
                ? $message['sent_at'] : $conversation->first_message_at,
            'last_message_at' => $conversation->last_message_at === null || $message['sent_at'] > $conversation->last_message_at
                ? $message['sent_at'] : $conversation->last_message_at,
            'updated_at' => $now,
        ]);
        return 'inserted';
    }

    private function canonical($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
        return $value;
    }
}

