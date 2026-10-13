<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Verified API acceptance evidence. This is never a fabricated webhook or delivery receipt. */
class WhatsAppAcceptedReplyEvidence
{
    public const SOURCE = 'dashboard_api';
    private const ENVELOPE = 'whatsapp-accepted-reply-evidence-v1';

    /** Metadata projection only, in an independent transaction after durable acceptance commits. */
    public function projectAcceptedReply(object $reply, bool $lock = false): ?int
    {
        if (($reply->state ?? null) !== 'ACCEPTED' || ($reply->kind ?? null) !== 'text') return null;
        if (DB::connection()->transactionLevel() !== 0) throw new RuntimeException('ACCEPTED_EVIDENCE_REQUIRES_COMMITTED_RECEIPT');
        // Nonlocking discovery only. Every bound fact is re-read and validated under locks.
        $observed = DB::table('whatsapp_reply_requests')->where('id', $reply->id)->first();
        if (!$observed) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $native = $this->decode($observed->response_details, 65536)['message_id'] ?? null;
        if (!is_string($native) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $native))
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $key = hash('sha256', 'whatsapp-message-v1:' . WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':' . $native);
        return DB::transaction(function () use ($reply, $observed, $key) {
            // Match the native Consumer lock order: canonical message, thread, then receipt.
            $existing = DB::table('whatsapp_inbox_messages')->where('message_key', $key)->lockForUpdate()->first();
            $thread = $this->thread((int) $observed->conversation_id, true);
            $fresh = DB::table('whatsapp_reply_requests')->where('id', $reply->id)->lockForUpdate()->first();
            if (!$fresh) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
            $evidence = $this->receipt($thread, $fresh, true);
            if ($evidence['native_message_key'] !== $key) throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
            if ($existing) {
                if ((int) $existing->conversation_id !== (int) $thread->id) throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
                if ($existing->source === self::SOURCE) {
                    $saved = $this->unwrap($thread, $existing, true);
                    if ($saved['reply_id'] !== (int) $fresh->id) throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
                } else {
                    $native = (new WhatsAppInboxMessage())->projected($thread, $existing);
                    if ($native['native_id'] !== $evidence['native_id'] || $native['kind'] !== 'text'
                        || $native['direction'] !== 'outbound' || $native['text'] !== $evidence['text']
                        || ($native['peer_phone'] !== null && $native['peer_phone'] !== $evidence['peer_phone']))
                        throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
                }
                return (int) $existing->id;
            }
            $stamp = now('UTC')->format('Y-m-d H:i:s');
            // insertOrIgnore handles a native-event/receipt race on the canonical native key.
            DB::table('whatsapp_inbox_messages')->insertOrIgnore([
                'conversation_id' => (int) $thread->id, 'message_key' => $key, 'type' => 'text',
                'direction' => 'outbound', 'source' => self::SOURCE,
                'content' => Crypt::encryptString(json_encode($this->envelope($fresh, $evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'sent_at' => $evidence['sent_at'], 'created_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $saved = DB::table('whatsapp_inbox_messages')->where('message_key', $key)->lockForUpdate()->first();
            if (!$saved || (int) $saved->conversation_id !== (int) $thread->id) throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
            if ($saved->source === self::SOURCE) {
                if ($this->unwrap($thread, $saved, true)['reply_id'] !== (int) $fresh->id) throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
            } else {
                $native = (new WhatsAppInboxMessage())->projected($thread, $saved);
                if ($native['native_id'] !== $evidence['native_id'] || $native['kind'] !== 'text'
                    || $native['direction'] !== 'outbound' || $native['text'] !== $evidence['text']
                    || ($native['peer_phone'] !== null && $native['peer_phone'] !== $evidence['peer_phone']))
                    throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
            }
            DB::table('whatsapp_inbox_conversations')->where('id', $thread->id)->update([
                'last_message_at' => $thread->last_message_at === null || $evidence['sent_at'] > $thread->last_message_at
                    ? $evidence['sent_at'] : $thread->last_message_at, 'updated_at' => $stamp,
            ]);
            return (int) $saved->id;
        }, 3);
    }

    /** Bounded retry/backfill. Historical acceptance time is preserved, not reset. */
    public function projectPending(int $limit = 100, ?int $conversation = null, ?string $acceptedSince = null): array
    {
        if ($limit < 1 || $limit > 500 || ($conversation !== null && $conversation < 1)) throw new RuntimeException('INVALID_EVIDENCE_LIMIT');
        if ($acceptedSince !== null) $this->time($acceptedSince);
        $metrics = ['processed' => 0, 'projected' => 0, 'replayed' => 0, 'invalid' => 0, 'remaining' => 0, 'complete' => true, 'failed_conversation_ids' => [], 'pending_conversation_ids' => []];
        if (!Schema::hasTable('whatsapp_reply_requests')) return $metrics;
        if (!Schema::hasTable(WhatsAppInboxMessage::INDEX)) {
            $q = DB::table('whatsapp_reply_requests')->where('waba_id', WhatsAppInboxAccess::WABA_ID)
                ->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID)->where('state', 'ACCEPTED')->where('kind', 'text');
            if ($conversation !== null) $q->where('conversation_id', $conversation);
            if ($acceptedSince !== null) $q->where('completed_at', '>=', $acceptedSince);
            $metrics['remaining'] = (int) $q->count();
            $ids = $q->orderByDesc('id')->limit($limit)->get(['conversation_id'])->pluck('conversation_id')
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            $metrics['invalid'] = 1; $metrics['complete'] = false;
            $metrics['failed_conversation_ids'] = $ids; $metrics['pending_conversation_ids'] = $ids;
            return $metrics;
        }
        $query = $this->pending($conversation, $acceptedSince);
        $rows = $query->orderByDesc('r.id')->limit($limit)->addSelect('r.*')->get();
        foreach ($rows as $row) {
            $metrics['processed']++;
            try {
                $before = DB::table('whatsapp_inbox_messages')->where('message_key', $row->projection_key)->exists();
                $id = $this->projectAcceptedReply($row);
                $metrics[$id === null ? 'invalid' : ($before ? 'replayed' : 'projected')]++;
            } catch (\Throwable $error) { $metrics['invalid']++; $metrics['failed_conversation_ids'][] = (int) $row->conversation_id; }
        }
        $metrics['remaining'] = (int) $this->pending($conversation, $acceptedSince)->count();
        $metrics['complete'] = $metrics['remaining'] === 0;
        $metrics['failed_conversation_ids'] = array_values(array_unique($metrics['failed_conversation_ids']));
        $metrics['pending_conversation_ids'] = $this->pending($conversation, $acceptedSince)->orderByDesc('r.id')->limit($limit)
            ->select('r.conversation_id')->get()->pluck('conversation_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        return $metrics;
    }

    /** Pure read/validation for order snapshots and protected message readers. */
    public function unwrap(object $thread, object $row, bool $lock = false): array
    {
        if (($row->source ?? null) !== self::SOURCE || ($row->direction ?? null) !== 'outbound'
            || ($row->type ?? null) !== 'text' || (int) ($row->conversation_id ?? 0) !== (int) $thread->id)
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $this->assertThread($thread);
        $saved = $this->decode($row->content, 65536);
        if (($saved['schema'] ?? null) !== self::ENVELOPE || !is_int($saved['reply_id'] ?? null) || $saved['reply_id'] < 1)
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $q = DB::table('whatsapp_reply_requests')->where('id', $saved['reply_id']);
        $reply = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$reply) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $evidence = $this->receipt($thread, $reply, $lock);
        if ($saved !== $this->envelope($reply, $evidence) || $row->sent_at !== $evidence['sent_at']
            || !hash_equals((string) $row->message_key, $evidence['native_message_key']))
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        return $evidence;
    }

    /** Pure read: associated accepted API evidence must agree with a native business message. */
    public function validateNativeReceipt(object $thread, object $row, bool $lock = false): void
    {
        if (($row->direction ?? null) !== 'outbound' || ($row->type ?? null) !== 'text' || ($row->source ?? null) === self::SOURCE
            || !Schema::hasTable('whatsapp_reply_requests') || !Schema::hasTable(WhatsAppInboxMessage::INDEX)) return;
        $this->assertThread($thread);
        $q = DB::table('whatsapp_reply_requests as r')->join(WhatsAppInboxMessage::INDEX . ' as p', function ($j) {
            $j->on('p.reply_id', '=', 'r.id')->on('p.conversation_id', '=', 'r.conversation_id')
                ->on('p.waba_id', '=', 'r.waba_id')->on('p.phone_number_id', '=', 'r.phone_number_id')->on('p.kind', '=', 'r.kind');
        })->where('p.message_key', $row->message_key)->where('r.state', 'ACCEPTED')->where('r.kind', 'text')
            ->where('r.waba_id', WhatsAppInboxAccess::WABA_ID)->where('r.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->select('r.*')->limit(2);
        $rows = ($lock ? $q->lockForUpdate() : $q)->get();
        if ($rows->count() > 1) throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
        if ($rows->isEmpty()) return;
        $e = $this->receipt($thread, $rows->first(), $lock);
        $native = (new WhatsAppInboxMessage())->projected($thread, $row);
        if ($native['native_id'] !== $e['native_id'] || $native['kind'] !== 'text' || $native['direction'] !== 'outbound'
            || $native['text'] !== $e['text'] || ($native['peer_phone'] !== null && $native['peer_phone'] !== $e['peer_phone']))
            throw new RuntimeException('CONFLICTING_ACCEPTED_REPLY');
    }

    /** A genuine echo may have its own native timestamp; receipt evidence remains immutable. */
    public function matchesNativeEcho(object $thread, object $row, array $native, bool $lock = false): bool
    {
        try {
            $e = $this->unwrap($thread, $row, $lock);
            return ($native['message_id'] ?? null) === $e['native_id'] && ($native['direction'] ?? null) === 'outbound'
                && ($native['type'] ?? null) === 'text' && ($native['text'] ?? null) === $e['text']
                && ($native['peer_identity'] ?? null) === $e['peer_identity']
                && (!isset($native['peer_phone']) || $native['peer_phone'] === $e['peer_phone'])
                && (!isset($native['peer_user_id']) || $native['peer_user_id'] === $e['peer_user_id'])
                && in_array($native['source'] ?? null, ['standby', 'smb_message_echoes'], true);
        } catch (\Throwable $error) { return false; }
    }

    private function pending(?int $conversation, ?string $since)
    {
        $q = DB::table('whatsapp_reply_requests as r')->leftJoin(WhatsAppInboxMessage::INDEX . ' as p', function ($j) {
            $j->on('p.reply_id', '=', 'r.id')->on('p.conversation_id', '=', 'r.conversation_id')
                ->on('p.waba_id', '=', 'r.waba_id')->on('p.phone_number_id', '=', 'r.phone_number_id')->on('p.kind', '=', 'r.kind');
        })->where('r.waba_id', WhatsAppInboxAccess::WABA_ID)->where('r.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->where('r.state', 'ACCEPTED')->where('r.kind', 'text')
            ->where(function ($q) {
                $q->whereNull('p.reply_id')->orWhere('p.valid', false)->orWhereNotExists(function ($native) {
                    $native->selectRaw('1')->from('whatsapp_inbox_messages as m')->whereColumn('m.message_key', 'p.message_key')
                        ->whereColumn('m.conversation_id', 'r.conversation_id')->where('m.direction', 'outbound')->where('m.type', 'text');
                });
            });
        if ($conversation !== null) $q->where('r.conversation_id', $conversation);
        if ($since !== null) $q->where('r.completed_at', '>=', $since);
        return $q->addSelect('p.message_key as projection_key');
    }

    private function receipt(object $thread, object $r, bool $lock): array
    {
        $this->assertThread($thread);
        if (($r->state ?? null) !== 'ACCEPTED' || ($r->kind ?? null) !== 'text'
            || ($r->waba_id ?? null) !== WhatsAppInboxAccess::WABA_ID || ($r->phone_number_id ?? null) !== WhatsAppInboxAccess::PHONE_ID
            || (int) $r->conversation_id !== (int) $thread->id || (int) $r->id < 1 || (int) $r->actor_id < 1)
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $audit = $this->decode($r->audit, 65536); $response = $this->decode($r->response_details, 65536);
        $id = $response['message_id'] ?? null; $text = $audit['text'] ?? null; $phone = $audit['recipient'] ?? null;
        if (!is_string($id) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $id)
            || !is_string($text) || trim($text) === '' || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 4096
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)
            || !is_string($phone) || !preg_match('/\A[1-9][0-9]{7,14}\z/', $phone)
            || !is_string($audit['client_request_id'] ?? null)
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $audit['client_request_id'])
            || ($audit['voice_sha256'] ?? null) !== null || ($audit['attachment'] ?? null) !== null
            || ($audit['media'] ?? null) !== null || ($audit['emoji'] ?? null) !== null)
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $key = Crypt::getKey(); $scope = WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':';
        if (!hash_equals((string) $r->remote_message_key, hash('sha256', 'whatsapp-reply-message-v1:' . $scope . $id))
            || !hash_equals((string) $r->request_hash, hash_hmac('sha256', 'whatsapp-reply-request-v1:' . $r->actor_id . ':' . $r->conversation_id . ':' . strtolower($audit['client_request_id']), $key)))
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $values = $this->payloadValues($r, $audit);
        if (!hash_equals((string) $r->payload_hash, hash_hmac('sha256', 'whatsapp-reply-payload-v1:' . json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $key)))
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $nativeKey = hash('sha256', 'whatsapp-message-v1:' . $scope . $id);
        $q = DB::table(WhatsAppInboxMessage::INDEX)->where('reply_id', $r->id);
        $index = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$index || !$index->valid || (int) $index->conversation_id !== (int) $r->conversation_id
            || $index->kind !== 'text' || $index->waba_id !== $r->waba_id || $index->phone_number_id !== $r->phone_number_id
            || $index->message_key !== $nativeKey || $index->receipt_hash !== hash('sha256', $r->response_details . ':' . $r->remote_message_key . ':' . $r->conversation_id))
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $q = DB::table('whatsapp_inbox_messages')->where('conversation_id', $thread->id)->where('id', $r->inbound_message_id)->where('direction', 'inbound');
        $inbound = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$inbound) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $native = (new WhatsAppInboxMessage())->projected($thread, $inbound);
        $dto = $this->decode($inbound->content, 8388608);
        $identity = $dto['peer_identity'] ?? null; $user = $dto['peer_user_id'] ?? null;
        if ($native['peer_phone'] !== $phone || !is_string($identity)) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $customer = $this->decode($thread->customer, 65536);
        if (($customer['phone'] ?? null) !== null && $customer['phone'] !== $phone
            || ($customer['user_id'] ?? null) !== null && $customer['user_id'] !== $user)
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $accepted = $this->time($r->completed_at); $started = $this->time($r->send_started_at); $claimed = $this->time($r->claimed_at);
        $at = $this->time($inbound->sent_at);
        if ($claimed > $started || $started > $accepted || $at > $started || $accepted->getTimestamp() >= $at->getTimestamp() + 86400
            || $accepted > now('UTC')) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        return ['reply_id' => (int) $r->id, 'native_id' => $id, 'peer_identity' => $identity, 'peer_phone' => $phone,
            'peer_user_id' => $user, 'text' => $text, 'sent_at' => $r->completed_at, 'native_message_key' => $nativeKey,
            'quote_source' => $this->quoteSource($audit),
            'fingerprint' => hash('sha256', $r->payload_hash . ':' . $id)];
    }

    private function payloadValues(object $r, array $audit): array
    {
        $base = ['text', (int) $r->inbound_message_id, $audit['text'], null];
        $context = $audit['reply_to'] ?? null; $source = $audit['source'] ?? null;
        if (isset($audit['payload_values'])) {
            $v = $audit['payload_values'];
            if (!is_array($v) || !array_is_list($v) || !in_array(count($v), [4, 5], true) || array_slice($v, 0, 4) !== $base)
                throw new RuntimeException('INVALID_ACCEPTED_REPLY');
            if (count($v) === 4) {
                if ($context !== null || $source !== null) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
                return $v;
            }
            if (!is_array($v[4]) || !array_is_list($v[4]) || count($v[4]) !== 6 || $v[4][2] !== $source
                || $v[4][3] !== null || $v[4][4] !== null) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
            $message = $context['message_id'] ?? null; $reply = $context['reply_id'] ?? null;
            if ($v[4][0] !== $message || $v[4][1] !== $reply
                || ($context === null ? $v[4][5] !== null : (!is_string($v[4][5]) || !preg_match('/\A[a-f0-9]{64}\z/', $v[4][5]))))
                throw new RuntimeException('INVALID_ACCEPTED_REPLY');
            return $v;
        }
        // Legacy plain-text receipts are fully reconstructible. Old quoted contexts fail closed.
        if ($context !== null) throw new RuntimeException('UNPROVEN_QUOTED_REPLY');
        if ($source !== null) {
            if (($audit['action'] ?? null) !== 'forward' || !is_array($source)
                || !in_array($source['kind'] ?? null, ['message', 'reply'], true)
                || !is_int($source['conversation'] ?? null) || !is_int($source['id'] ?? null)
                || !is_string($source['fingerprint'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $source['fingerprint']))
                throw new RuntimeException('INVALID_ACCEPTED_REPLY');
            $base[] = [null, null, $source, null, null, null];
        }
        return $base;
    }

    /** Honest HMAC-bound audit pointer; protected display resolves it for the current operator. */
    private function quoteSource(array $audit): ?array
    {
        $quote = $audit['reply_to'] ?? null;
        if ($quote === null) return null;
        if (!is_array($quote) || !array_key_exists('message_id', $quote) || !array_key_exists('reply_id', $quote))
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $message = $quote['message_id']; $reply = $quote['reply_id'];
        if (!(($message === null && is_int($reply) && $reply > 0)
            || ($reply === null && is_int($message) && $message > 0))) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        return ['message_id' => $message, 'reply_id' => $reply];
    }

    private function envelope(object $reply, array $e): array
    {
        return ['schema' => self::ENVELOPE, 'reply_id' => $e['reply_id'], 'audit_hash' => hash('sha256', $reply->audit),
            'receipt_hash' => hash('sha256', $reply->response_details), 'payload_hash' => $reply->payload_hash,
            'native_id' => $e['native_id'], 'peer_identity' => $e['peer_identity'], 'peer_phone' => $e['peer_phone'],
            'peer_user_id' => $e['peer_user_id'], 'text' => $e['text'], 'accepted_at' => $e['sent_at']];
    }

    private function thread(int $id, bool $lock): object
    {
        $q = DB::table('whatsapp_inbox_conversations')->where('id', $id);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$r) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $this->assertThread($r); return $r;
    }
    private function assertThread(object $r): void
    {
        if (($r->waba_id ?? null) !== WhatsAppInboxAccess::WABA_ID || ($r->phone_number_id ?? null) !== WhatsAppInboxAccess::PHONE_ID)
            throw new RuntimeException('INVALID_ACCEPTED_REPLY');
    }
    private function decode($value, int $max): array
    {
        if (!is_string($value) || strlen($value) > $max) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $plain = Crypt::decryptString($value);
        if (strlen($plain) > $max) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $data = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        return $data;
    }
    private function time($value): \DateTimeImmutable
    {
        if (!is_string($value)) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d H:i:s') !== $value) throw new RuntimeException('INVALID_ACCEPTED_REPLY');
        return $d;
    }
}
