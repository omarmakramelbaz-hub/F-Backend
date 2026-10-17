<?php

namespace App\Services\Dashboard;

use App\Support\WhatsAppInboxProtocol;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // Capture a bounded batch. Every row is re-read under a transaction lock.
        $batch = DB::table('whatsapp_webhook_events')->whereNull('processed_at');
        if (!$retryQuarantined) {
            $batch->whereNotExists(function ($query) {
                $query->select('event_id')->from('whatsapp_inbox_ingestion_failures')
                    ->whereColumn('whatsapp_inbox_ingestion_failures.event_id', 'whatsapp_webhook_events.id');
            });
        }
        $ids = $batch->orderBy('id')->limit($limit)->pluck('id');

        return $this->consumeIds($ids, $key, $retryQuarantined, false);
    }

    /** Explicit bounded metadata recovery, never a global retry or an order/AI action. */
    public function recoverUnavailableMessages(array $eventIds): array
    {
        if (!array_is_list($eventIds) || $eventIds === [] || count($eventIds) > 20
            || count(array_unique($eventIds, SORT_REGULAR)) !== count($eventIds)) throw new InvalidArgumentException('Invalid recovery event IDs.');
        foreach ($eventIds as $id) if (!is_int($id) || $id < 1) throw new InvalidArgumentException('Invalid recovery event IDs.');
        return $this->consumeIds($eventIds, $this->hashKey(), true, true);
    }

    /** Resolve only the caller's privately audited same-marker event expectations. */
    public function recoverUnavailableReplays(array $eventIds, array $expectedProofs): array
    {
        $keys=['proof_version','event_id','message_id','conversation_id','payload_hash','ciphertext_sha256','original_sent_at','incoming_sent_at','failure'];
        $sorted=$eventIds;sort($sorted,SORT_NUMERIC);
        if(!array_is_list($eventIds)||$eventIds===[]||count($eventIds)>20||$sorted!==$eventIds
            ||count(array_unique($eventIds,SORT_REGULAR))!==count($eventIds)||array_keys($expectedProofs)!==$eventIds)
            throw new InvalidArgumentException('UNAVAILABLE_REPLAY_EXPECTATION_INVALID');
        foreach($eventIds as$id){
            $proof=$expectedProofs[$id]??null;
            if(!is_int($id)||$id<1||!is_array($proof)||count($proof)!==count($keys)||array_diff(array_keys($proof),$keys)
                ||($proof['proof_version']??null)!==WhatsAppNativeUnavailableReplay::VERSION||($proof['event_id']??null)!==$id
                ||!is_int($proof['message_id']??null)||$proof['message_id']<1||!is_int($proof['conversation_id']??null)||$proof['conversation_id']<1)
                throw new InvalidArgumentException('UNAVAILABLE_REPLAY_EXPECTATION_INVALID');
            foreach(['payload_hash','ciphertext_sha256']as$field)if(!is_string($proof[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$proof[$field]))
                throw new InvalidArgumentException('UNAVAILABLE_REPLAY_EXPECTATION_INVALID');
            $failure=$proof['failure'];
            if(!is_array($failure)||count($failure)!==3||array_diff(array_keys($failure),['reason','attempts','last_attempted_at'])
                ||($failure['reason']??null)!=='CONFLICTING_MESSAGE'||!is_int($failure['attempts']??null)||$failure['attempts']<1||$failure['attempts']>4294967295)
                throw new InvalidArgumentException('UNAVAILABLE_REPLAY_EXPECTATION_INVALID');
            foreach([$proof['original_sent_at']??null,$proof['incoming_sent_at']??null,$failure['last_attempted_at']??null]as$value){
                $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC')):false;
                if(!$date||$date->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException('UNAVAILABLE_REPLAY_EXPECTATION_INVALID');
            }
        }
        $key=$this->hashKey();
        return $this->withUniqueRetry(function()use($eventIds,$expectedProofs,$key){
            $prepared=[];
            foreach($eventIds as$id){
                $event=DB::table('whatsapp_webhook_events')->where('id',$id)->lockForUpdate()
                    ->select('id','payload_hash','received_at','processed_at')->selectRaw('CASE WHEN LENGTH(payload)<=8388608 THEN payload ELSE NULL END AS payload')->first();
                $failure=DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$id)->lockForUpdate()->first();
                if(!$event||!is_string($event->payload))throw new RuntimeException('UNAVAILABLE_REPLAY_RAW_CHANGED');
                $json=Crypt::decryptString($event->payload);
                if(strlen($json)>self::MAX_EVENT_BYTES)throw new RuntimeException('UNAVAILABLE_REPLAY_PROOF_REQUIRED');
                $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
                if(!is_array($payload))throw new RuntimeException('UNAVAILABLE_REPLAY_PROOF_REQUIRED');
                $report=WhatsAppInboxProtocol::report($payload,self::WABA_ID,self::PHONE_ID);
                if(($report['quarantined_count']??null)!==0||($report['ignored_count']??null)!==0||($report['status_count']??null)!==0
                    ||!is_array($report['messages']??null)||count($report['messages'])!==1)throw new RuntimeException('UNAVAILABLE_REPLAY_PROOF_REQUIRED');
                $incoming=$report['messages'][0];$this->assertMessage($incoming);
                $messageKey=hash('sha256','whatsapp-message-v1:'.self::WABA_ID.':'.self::PHONE_ID.':'.$incoming['message_id']);
                $existing=DB::table('whatsapp_inbox_messages')->where('message_key',$messageKey)->lockForUpdate()
                    ->select('id','conversation_id','message_key','direction','source','type','sent_at')
                    ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
                if(!$existing)throw new RuntimeException('UNAVAILABLE_REPLAY_PROOF_REQUIRED');
                $thread=DB::table('whatsapp_inbox_conversations')->where('id',$existing->conversation_id)->lockForUpdate()->first();
                if(!$thread)throw new RuntimeException('UNAVAILABLE_REPLAY_PROOF_REQUIRED');
                $proof=WhatsAppNativeUnavailableReplay::inspect($thread,$existing,$event);$expected=$expectedProofs[$id];
                if($proof===null)throw new RuntimeException('UNAVAILABLE_REPLAY_PROOF_REQUIRED');
                foreach($proof as$field=>$value)if(!array_key_exists($field,$expected)||$expected[$field]!==$value)
                    throw new RuntimeException('UNAVAILABLE_REPLAY_EXPECTATION_CHANGED');
                if($event->processed_at!==null){
                    if($failure!==null)throw new RuntimeException('UNAVAILABLE_REPLAY_FAILURE_CHANGED');
                    $date=is_string($event->processed_at)?\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$event->processed_at,new \DateTimeZone('UTC')):false;
                    if(!$date||$date->format('Y-m-d H:i:s')!==$event->processed_at)throw new RuntimeException('UNAVAILABLE_REPLAY_RAW_CHANGED');
                    $state='ALREADY_PROCESSED';
                }else{
                    $before=$expected['failure'];
                    if(!$failure||$failure->reason!==$before['reason']||(int)$failure->attempts!==$before['attempts']
                        ||$failure->last_attempted_at!==$before['last_attempted_at'])throw new RuntimeException('UNAVAILABLE_REPLAY_FAILURE_CHANGED');
                    $state='PROCESSED';
                }
                $prepared[]=[$event,$expected,$state];
            }
            // All native, raw and audit expectations have been proved under locks
            // before any event is settled. Raw payloads and inbox facts stay exact.
            $receipts=[];$processed=0;
            foreach($prepared as[$event,$expected,$state]){
                $stamp=$event->processed_at;
                if($state==='PROCESSED'){
                    $stamp=now('UTC')->format('Y-m-d H:i:s');
                    $changed=DB::table('whatsapp_webhook_events')->where('id',$event->id)->where('payload_hash',$expected['payload_hash'])
                        ->whereNull('processed_at')->update(['processed_at'=>$stamp]);
                    $failure=$expected['failure'];
                    $removed=DB::table('whatsapp_inbox_ingestion_failures')->where('event_id',$event->id)->where('reason',$failure['reason'])
                        ->where('attempts',$failure['attempts'])->where('last_attempted_at',$failure['last_attempted_at'])->delete();
                    if($changed!==1||$removed!==1)throw new RuntimeException('UNAVAILABLE_REPLAY_REVISION_CHANGED');
                    $processed++;
                }
                $receipts[]=array_merge($expected,['state'=>$state,'processed_at'=>$stamp]);
            }
            return['success'=>true,'writes'=>$processed*2,'events_processed'=>$processed,'events_skipped'=>count($eventIds)-$processed,
                'messages_replayed'=>count($eventIds),'receipts'=>$receipts,'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0];
        });
    }

    private function consumeIds($ids, string $key, bool $retryQuarantined, bool $unavailableOnly): array
    {
        $metrics = [
            'events_seen' => 0, 'events_processed' => 0, 'events_skipped' => 0,
            'messages_inserted' => 0, 'messages_replayed' => 0, 'messages_hydrated' => 0,
            'quarantined_events' => 0, 'ignored_records' => 0,
            'status_records' => 0, 'errors' => 0,
        ];
        foreach ($ids as $id) {
            $metrics['events_seen']++;
            try {
                $result = $this->withUniqueRetry(function () use ($id, $key, $retryQuarantined, $unavailableOnly) {
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
                    if ($unavailableOnly) {
                        $failure = DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $id)->lockForUpdate()->first();
                        if (!$failure || $failure->reason !== 'CONFLICTING_MESSAGE') throw new RuntimeException('RECOVERY_NOT_ELIGIBLE');
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
                    $rawHash = hash('sha256', $json);
                    $rawVerified = is_string($event->payload_hash ?? null)
                        && preg_match('/\A[a-f0-9]{64}\z/', $event->payload_hash) && hash_equals($event->payload_hash, $rawHash);
                    if ($unavailableOnly && (!$rawVerified || count($report['messages']) !== 1 || $report['ignored_count'] !== 0
                        || $report['status_count'] !== 0)) throw new RuntimeException('RECOVERY_NOT_ELIGIBLE');
                    $counts = [
                        'events_processed' => 1, 'messages_inserted' => 0,
                        'messages_replayed' => 0, 'messages_hydrated' => 0,
                        'ignored_records' => $report['ignored_count'],
                        'status_records' => $report['status_count'],
                    ];
                    foreach ($report['messages'] as $message) {
                        $this->assertMessage($message);
                        $kind = $this->persist($message, $key, (int) $id, $rawVerified ? $rawHash : null, $unavailableOnly);
                        $counts[$kind === 'inserted' ? 'messages_inserted' : ($kind === 'hydrated' ? 'messages_hydrated' : 'messages_replayed')]++;
                        // The CRM projection shares the raw-event transaction. A replay
                        // must not reapply an old opt-out after a newer consent decision.
                        if ($kind !== 'replayed' && class_exists(CustomerMessageEvents::class)) {
                            (new CustomerMessageEvents())->inbound($message);
                        }
                    }
                    if (class_exists(CustomerMessageEvents::class)) {
                        foreach ($report['statuses'] ?? [] as $status) {
                            (new CustomerMessageEvents())->status($status);
                        }
                    }
                    DB::table('whatsapp_webhook_events')->where('id', $id)->whereNull('processed_at')
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

    private function persist(array $message, string $key, int $eventId = 0, ?string $rawHash = null, bool $unavailableOnly = false): string
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
                || $existing->direction !== $message['direction']
                || ($existing->type !== $message['type'] && !$this->hydrationShape($existing, $message))) {
                throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
            }
            if ($existing->source === 'dashboard_api') {
                // API acceptance and a later genuine echo share the native ID, not a timestamp.
                // Validate both facts without rewriting immutable receipt evidence or its cursor.
                if (!(new WhatsAppAcceptedReplyEvidence())->matchesNativeEcho($conversation, $existing, $message, true))
                    throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
                return 'replayed';
            }
            try {
                $saved = json_decode(Crypt::decryptString($existing->content), true, 512, JSON_THROW_ON_ERROR);
            } catch (DecryptException | \JsonException $error) {
                throw new WhatsAppInboxQuarantinedEvent();
            }
            $this->assertMessage($saved);
            if ($existing->type !== $message['type']) {
                return $this->hydrateUnavailable($conversation, $existing, $saved, $message, $key, $eventId, $rawHash);
            }
            if ($unavailableOnly) throw new RuntimeException('RECOVERY_NOT_ELIGIBLE');
            if ($existing->type === 'unsupported' && $message['type'] === 'unsupported'
                && WhatsAppNativeUnavailableReplay::prove($conversation,$existing,$saved,$message,$rawHash)) {
                return 'replayed';
            }
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
            if (array_key_exists('hydration', $saved['content'])) {
                $marker = $saved['content']['hydration'];
                $proofEvent = is_array($marker) && is_int($marker['event_id'] ?? null)
                    ? DB::table('whatsapp_webhook_events')->where('id', $marker['event_id'])->first() : null;
                $proofFailure = is_array($marker) && is_int($marker['event_id'] ?? null)
                    && DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $marker['event_id'])->exists();
                if (!$proofEvent || !WhatsAppNativePlaceholderHydration::prove($conversation, $existing, $saved, $proofEvent, $proofFailure))
                    throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
                // Preserve the raw-proven hydration evidence; new genuine source copies remain
                // in their own raw events and must not rewrite a sealed transcript identity.
                return 'replayed';
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

        if ($unavailableOnly) throw new RuntimeException('RECOVERY_NOT_ELIGIBLE');
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

    private function hydrationShape(object $existing, array $incoming): bool
    {
        return $existing->source === 'messages' && $existing->direction === 'inbound' && $existing->type === 'unsupported'
            && ($incoming['source'] ?? null) === 'messages' && ($incoming['direction'] ?? null) === 'inbound'
            && ($incoming['type'] ?? null) === 'text';
    }

    /** Replace only a proven unavailable-content marker with its authentic same-ID native text. */
    private function hydrateUnavailable(object $thread, object $existing, array $saved, array $incoming,
        string $key, int $eventId, ?string $rawHash): string
    {
        if ($eventId < 1 || $rawHash === null || !WhatsAppNativePlaceholderHydration::transition($thread, $existing, $saved, $incoming))
            throw new WhatsAppInboxQuarantinedEvent('CONFLICTING_MESSAGE');
        if (Schema::hasTable('whatsapp_order_drafts')) {
            $used = DB::table('whatsapp_order_drafts')->where('conversation_id', $thread->id)
                ->where('evidence_floor', '<', $existing->id)->where('evidence_ceiling', '>=', $existing->id)
                ->lockForUpdate()->get(['status', 'sealed_payload']);
            foreach ($used as $draft) if ($draft->status === 'DISPATCHED' || $draft->sealed_payload !== null)
                throw new RuntimeException('HYDRATION_ORDER_EVIDENCE_PROTECTED');
        }
        $event = DB::table('whatsapp_webhook_events')->where('id', $eventId)->lockForUpdate()->first();
        if (!$event || !is_string($event->payload_hash ?? null) || !hash_equals($event->payload_hash, $rawHash))
            throw new RuntimeException('HYDRATION_RAW_REVISION_CHANGED');
        $stamp = is_string($event->processed_at) ? $event->processed_at : now('UTC')->format('Y-m-d H:i:s');
        $sources = array_values(array_unique(array_merge($saved['content']['sources'], $incoming['content']['sources']))); sort($sources);
        $incoming['content']['sources'] = $sources;
        $incoming['content']['hydration'] = ['version' => WhatsAppNativePlaceholderHydration::VERSION, 'code' => 131060,
            'event_id' => $eventId, 'at_utc' => $stamp, 'before' => $saved, 'before_ciphertext' => $existing->content,
            'before_ciphertext_sha256' => hash('sha256', $existing->content), 'raw_payload_hash' => $rawHash];
        $changed = DB::table('whatsapp_inbox_messages')->where('id', $existing->id)->where('conversation_id', $thread->id)
            ->where('message_key', $existing->message_key)->where('type', 'unsupported')->where('source', 'messages')
            ->where('content', $existing->content)->update(['type' => 'text',
                'content' => Crypt::encryptString(json_encode($incoming, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'sent_at' => $incoming['sent_at'], 'updated_at' => $stamp]);
        if ($changed !== 1) throw new RuntimeException('HYDRATION_REVISION_CHANGED');
        // Current raw/failure metadata is visible to the proof in this same transaction only.
        // Any later failure rolls all facts and metadata back; callers never bypass quarantine.
        DB::table('whatsapp_webhook_events')->where('id', $eventId)->update(['processed_at' => $stamp]);
        DB::table('whatsapp_inbox_ingestion_failures')->where('event_id', $eventId)->delete();
        if (!app(WhatsAppOrderWorkflow::class)->noteProjectionRevision((int) $thread->id, (int) $existing->id))
            throw new RuntimeException('TRANSCRIPT_REVISION_BUSY');
        DB::table('whatsapp_inbox_conversations')->where('id', $thread->id)->update([
            'last_message_at' => $thread->last_message_at === null || $incoming['sent_at'] > $thread->last_message_at
                ? $incoming['sent_at'] : $thread->last_message_at, 'updated_at' => $stamp]);
        return 'hydrated';
    }

    private function canonical($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value, SORT_STRING);
        return $value;
    }
}

