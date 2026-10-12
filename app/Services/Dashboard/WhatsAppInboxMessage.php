<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Resolves only authenticated, native messages from this phone's encrypted projection. */
class WhatsAppInboxMessage
{
    public const HIDDEN = 'whatsapp_inbox_hidden_messages';
    public const INDEX = 'whatsapp_reply_projection_keys';
    public const REACTIONS = 'whatsapp_reply_reaction_targets';
    private const DISPLAY_KINDS = ['text', 'audio', 'image', 'video', 'document'];
    private $authorizer;

    public function __construct(?callable $authorizer = null) { $this->authorizer = $authorizer; }

    public function actor($actor)
    {
        $fresh = $this->authorizer !== null ? ($this->authorizer)($actor) : app(WhatsAppInboxAccess::class)->actor($actor);
        if (!is_object($fresh) || !is_numeric($fresh->id ?? null) || (int) $fresh->id < 1) throw new HttpException(403);
        return $fresh;
    }

    /** CLI installer only: bounded derived metadata, no original receipt/content updates. */
    public function backfillProjectionIndex(int $limit = 200, ?int $conversation = null): array
    {
        if ($limit < 1 || $limit > 500 || !Schema::hasTable(self::INDEX)) throw new HttpException(503);
        $query = $this->unindexedReplies();
        if ($conversation !== null) $query->where('r.conversation_id', $conversation);
        $rows = $query->orderBy('r.id')->limit($limit)->select(['r.id', 'r.conversation_id', 'r.waba_id', 'r.phone_number_id', 'r.kind', 'r.state', 'r.remote_message_key'])
            ->selectRaw('CASE WHEN LENGTH(r.response_details) <= 65536 THEN r.response_details ELSE NULL END AS response_details')->get();
        $valid = 0; $invalid = 0;
        foreach ($rows as $row) { if ($this->indexReply($row)) $valid++; else $invalid++; }
        $reactionRows = collect();
        if ($rows->count() < $limit && Schema::hasTable(self::REACTIONS)) {
            $reactionQuery = $this->unindexedReactions();
            if ($conversation !== null) $reactionQuery->where('r.conversation_id', $conversation);
            $reactionRows = $reactionQuery->orderBy('r.id')->limit($limit - $rows->count())
                ->select(['r.id', 'r.conversation_id', 'r.waba_id', 'r.phone_number_id', 'r.kind', 'r.state', 'r.remote_message_key', 'r.actor_id', 'r.completed_at'])
                ->selectRaw('CASE WHEN LENGTH(r.response_details) <= 65536 THEN r.response_details ELSE NULL END AS response_details')
                ->selectRaw('CASE WHEN LENGTH(r.audit) <= 1048576 THEN r.audit ELSE NULL END AS audit')->get();
            foreach ($reactionRows as $row) { if ($this->indexReaction($row)) $valid++; else $invalid++; }
        }
        $remainingQuery = $this->unindexedReplies();
        if ($conversation !== null) $remainingQuery->where('r.conversation_id', $conversation);
        $remaining = (int) $remainingQuery->count();
        if (Schema::hasTable(self::REACTIONS)) {
            $pendingReactions = $this->unindexedReactions();
            if ($conversation !== null) $pendingReactions->where('r.conversation_id', $conversation);
            $remaining += (int) $pendingReactions->count();
        }
        return ['processed' => $rows->count() + $reactionRows->count(), 'valid' => $valid, 'invalid' => $invalid,
            'remaining' => $remaining, 'complete' => $remaining === 0];
    }

    public function indexReply($row): bool
    {
        if (!Schema::hasTable(self::INDEX)) return false;
        if ($row->state !== 'ACCEPTED' || !in_array($row->kind, self::DISPLAY_KINDS, true)
            || $row->waba_id !== WhatsAppInboxAccess::WABA_ID || $row->phone_number_id !== WhatsAppInboxAccess::PHONE_ID) return false;
        return DB::transaction(function () use ($row) {
            $key = null; $valid = false;
            $receipt = is_string($row->response_details) && strlen($row->response_details) <= 65536 ? $row->response_details : '';
            $fingerprint = hash('sha256', $receipt . ':' . (string) $row->remote_message_key . ':' . $row->conversation_id);
            try {
                $this->thread((int) $row->conversation_id);
                $native = $this->decrypt($receipt)['message_id'] ?? null;
                if (!is_string($native) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $native)
                    || !hash_equals((string) $row->remote_message_key, hash('sha256', 'whatsapp-reply-message-v1:'
                        . WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':' . $native))) throw new \RuntimeException();
                $key = $this->replyNativeKey($row); $valid = true;
            } catch (\Throwable $error) { $key = null; }
            $old = DB::table(self::INDEX)->where('reply_id', $row->id)->lockForUpdate()->first();
            if ($old) return (bool) $old->valid && $old->receipt_hash === $fingerprint && $old->message_key === $key
                && (int) $old->conversation_id === (int) $row->conversation_id && $old->kind === $row->kind
                && $old->waba_id === $row->waba_id && $old->phone_number_id === $row->phone_number_id;
            if ($key !== null && DB::table(self::INDEX)->where('waba_id', $row->waba_id)->where('phone_number_id', $row->phone_number_id)
                ->where('message_key', $key)->lockForUpdate()->first()) { $key = null; $valid = false; }
            DB::table(self::INDEX)->insertOrIgnore(['reply_id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id,
                'waba_id' => $row->waba_id, 'phone_number_id' => $row->phone_number_id, 'message_key' => $key,
                'kind' => $row->kind,
                'receipt_hash' => $fingerprint, 'valid' => $valid, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $saved = DB::table(self::INDEX)->where('reply_id', $row->id)->first();
            return $saved && (bool) $saved->valid && $saved->receipt_hash === $fingerprint && $saved->message_key === $key;
        }, 3);
    }

    private function unindexedReplies()
    {
        return DB::table('whatsapp_reply_requests as r')->leftJoin(self::INDEX . ' as p', function ($join) {
                $join->on('p.reply_id', '=', 'r.id')->on('p.conversation_id', '=', 'r.conversation_id')
                    ->on('p.waba_id', '=', 'r.waba_id')->on('p.phone_number_id', '=', 'r.phone_number_id')->on('p.kind', '=', 'r.kind');
            })
            ->where('r.waba_id', WhatsAppInboxAccess::WABA_ID)->where('r.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->where('r.state', 'ACCEPTED')->whereIn('r.kind', self::DISPLAY_KINDS)->whereNull('p.reply_id');
    }

    public function indexReaction($row): bool
    {
        if (!Schema::hasTable(self::REACTIONS) || $row->state !== 'ACCEPTED' || $row->kind !== 'reaction'
            || $row->waba_id !== WhatsAppInboxAccess::WABA_ID || $row->phone_number_id !== WhatsAppInboxAccess::PHONE_ID) return false;
        $key = null; $source = null; $emoji = ''; $valid = false;
        $responseBytes = is_string($row->response_details) && strlen($row->response_details) <= 65536 ? $row->response_details : '';
        $hash = hash('sha256', $responseBytes);
        try {
            if (!is_string($row->audit) || strlen($row->audit) > 1048576) throw new \RuntimeException();
            $audit = $this->decrypt($row->audit); $response = $this->decrypt($responseBytes);
            $source = $audit['source'] ?? null; $native = $response['message_id'] ?? null;
            if (!is_array($source) || ($source['conversation'] ?? null) !== (int) $row->conversation_id
                || !in_array($source['kind'] ?? null, ['message', 'reply'], true) || !is_int($source['id'] ?? null) || $source['id'] < 1
                || !is_string($source['fingerprint'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $source['fingerprint'])
                || !is_string($native) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $native)
                || !hash_equals((string) $row->remote_message_key, hash('sha256', 'whatsapp-reply-message-v1:'
                    . WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':' . $native))
                || !in_array($audit['emoji'] ?? null, ['', '👍', '❤️', '😂', '😮', '😢', '🙏', '✅'], true)) throw new \RuntimeException();
            $thread = DB::table('whatsapp_inbox_conversations')->where('id', $row->conversation_id)
                ->where('waba_id', WhatsAppInboxAccess::WABA_ID)->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID)->first();
            if (!$thread) throw new \RuntimeException();
            if ($source['kind'] === 'message') {
                $target = DB::table('whatsapp_inbox_messages')->where('conversation_id', $row->conversation_id)->where('id', $source['id'])->first();
                if (!$target) throw new \RuntimeException();
                $binding = $this->projected($thread, $target);
            } else {
                $target = $this->replies((int) $row->conversation_id)->where('id', $source['id'])->where('state', 'ACCEPTED')->first();
                $index = DB::table(self::INDEX)->where('conversation_id', $row->conversation_id)->where('reply_id', $source['id'])->where('valid', true)->first();
                if (!$target || !$index) throw new \RuntimeException();
                $nativeTarget = $this->decrypt($target->response_details)['message_id'] ?? null;
                if (!is_string($nativeTarget) || $this->replyNativeKey($target) !== $index->message_key) throw new \RuntimeException();
                $binding = ['native_message_key' => $index->message_key, 'kind' => $target->kind,
                    'fingerprint' => hash('sha256', $target->payload_hash . ':' . $nativeTarget)];
            }
            if (!hash_equals($source['fingerprint'], $binding['fingerprint'])
                || (isset($audit['source_native_key']) && $audit['source_native_key'] !== $binding['native_message_key'])
                || (isset($audit['source_native_kind']) && $audit['source_native_kind'] !== $binding['kind'])
                || in_array($binding['kind'], ['unsupported', 'reaction'], true)) throw new \RuntimeException();
            $key = $binding['native_message_key']; $emoji = $audit['emoji']; $valid = true;
        } catch (\Throwable $error) { $key = null; $source = null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row->completed_at, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $row->completed_at || (int) $row->actor_id < 1) $valid = false;
        DB::table(self::REACTIONS)->insertOrIgnore(['reply_id' => (int) $row->id, 'conversation_id' => (int) $row->conversation_id,
            'waba_id' => $row->waba_id, 'phone_number_id' => $row->phone_number_id, 'actor_id' => max(0, (int) $row->actor_id),
            'message_id' => $source && $source['kind'] === 'message' ? $source['id'] : null,
            'target_reply_id' => $source && $source['kind'] === 'reply' ? $source['id'] : null,
            'message_key' => $key, 'valid' => $valid, 'emoji' => $emoji,
            'accepted_at' => $date ? $date->format('Y-m-d H:i:s') : now('UTC')->format('Y-m-d H:i:s'), 'receipt_hash' => $hash,
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $saved = DB::table(self::REACTIONS)->where('reply_id', $row->id)->first();
        return $saved && $saved->valid && $saved->receipt_hash === $hash && $saved->message_key === $key;
    }

    private function unindexedReactions()
    {
        return DB::table('whatsapp_reply_requests as r')->leftJoin(self::REACTIONS . ' as t', 't.reply_id', '=', 'r.id')
            ->where('r.waba_id', WhatsAppInboxAccess::WABA_ID)->where('r.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->where('r.state', 'ACCEPTED')->where('r.kind', 'reaction')->whereNull('t.reply_id');
    }

    public function reactions($actor, int $conversation, array $keys): array
    {
        $this->actor($actor); $this->thread($conversation);
        if (!$keys || !Schema::hasTable(self::REACTIONS)) return [];
        if (count($keys) > 100) throw new HttpException(422);
        foreach ($keys as $key) if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key)) throw new HttpException(422);
        $latest = DB::table(self::REACTIONS . ' as candidate')->where('candidate.valid', true)->where('candidate.conversation_id', $conversation)
            ->where('candidate.waba_id', WhatsAppInboxAccess::WABA_ID)->where('candidate.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->whereIn('candidate.message_key', array_unique($keys))->whereNotExists(function ($q) use ($conversation) {
                $q->selectRaw('1')->from(self::REACTIONS . ' as newer')->where('newer.valid', true)->where('newer.conversation_id', $conversation)
                    ->where('newer.waba_id', WhatsAppInboxAccess::WABA_ID)->where('newer.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
                    ->whereColumn('newer.message_key', 'candidate.message_key')->where(function ($recent) {
                        $recent->whereColumn('newer.accepted_at', '>', 'candidate.accepted_at')->orWhere(function ($tie) {
                            $tie->whereColumn('newer.accepted_at', 'candidate.accepted_at')->whereColumn('newer.reply_id', '>', 'candidate.reply_id');
                        });
                    });
            })->select('candidate.reply_id');
        $rows = DB::table(self::REACTIONS . ' as t')->joinSub($latest, 'latest', 'latest.reply_id', '=', 't.reply_id')
            ->join('whatsapp_reply_requests as r', 'r.id', '=', 't.reply_id')->where('r.state', 'ACCEPTED')
            ->where('r.kind', 'reaction')->where('r.conversation_id', $conversation)
            ->where('r.waba_id', WhatsAppInboxAccess::WABA_ID)->where('r.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->get(['t.message_key', 't.emoji', 't.actor_id', 't.accepted_at', 't.reply_id']);
        $result = [];
        foreach ($rows as $row) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row->accepted_at, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $row->accepted_at || (int) $row->actor_id < 1
                || !in_array($row->emoji, ['', '👍', '❤️', '😂', '😮', '😢', '🙏', '✅'], true)) continue;
            $result[$row->message_key] = ['emoji' => $row->emoji, 'actor_id' => (int) $row->actor_id, 'accepted_at' => $date->format('Y-m-d\TH:i:s\Z'),
                'source' => 'dashboard_api', 'state' => 'ACCEPTED', 'reply_id' => (int) $row->reply_id];
        }
        return $result;
    }

    public function hiddenIds($actor, int $conversation, array $ids): array
    {
        $fresh = $this->actor($actor);
        if (!$ids || !Schema::hasTable(self::HIDDEN)) return [];
        $rows = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)->whereIn('id', $ids)
            ->get(['id', 'conversation_id', 'message_key', 'direction', 'type']);
        return $this->hiddenProjected($fresh, $rows);
    }

    /** Batch metadata filtering for rows already fetched from scoped conversations. */
    public function hiddenProjected($actor, $rows): array
    {
        $fresh = $this->actor($actor);
        if ($rows->isEmpty() || !Schema::hasTable(self::HIDDEN)) return [];
        $hidden = DB::table(self::HIDDEN)->where('actor_id', (int) $fresh->id)
            ->whereIn('conversation_id', $rows->pluck('conversation_id')->unique()->all())
            ->where(function ($q) use ($rows) {
                $q->whereIn('message_id', $rows->pluck('id')->all())->orWhereIn('native_message_key', $rows->pluck('message_key')->all())
                    ->orWhere('item_key', 'all');
            })->get();
        $indexes = Schema::hasTable(self::INDEX) ? DB::table(self::INDEX)->where('valid', true)
            ->where('waba_id', WhatsAppInboxAccess::WABA_ID)->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->whereIn('message_key', $rows->pluck('message_key')->all())->get()->keyBy('message_key') : null;
        return $rows->filter(function ($row) use ($hidden, $indexes) {
            $index = $indexes ? $indexes->get($row->message_key) : null;
            return $hidden->contains(function ($item) use ($row, $index) {
                if ((int) $item->conversation_id !== (int) $row->conversation_id) return false;
                return (int) $item->message_id === (int) $row->id
                    || (is_string($item->native_message_key) && $item->native_message_key === $row->message_key)
                    || (int) $row->id <= (int) ($item->message_cutoff ?? 0)
                    || ($index && (int) $index->conversation_id === (int) $row->conversation_id && $row->direction === 'outbound'
                        && $index->kind === $row->type && (int) $index->id <= (int) ($item->projection_cutoff ?? 0));
            });
        })->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function hiddenReplyIds($actor, int $conversation, array $ids): array
    {
        $fresh = $this->actor($actor);
        if (!$ids || !Schema::hasTable(self::HIDDEN)) return [];
        $rows = $this->replies($conversation)->whereIn('id', $ids)->get();
        $marker = $this->marker($fresh, $conversation);
        $indexes = Schema::hasTable(self::INDEX) ? DB::table(self::INDEX)->where('conversation_id', $conversation)->whereIn('reply_id', $ids)->get()->keyBy('reply_id') : collect();
        $keys = [];
        foreach ($rows as $row) { $key = $indexes->get($row->id); if ($key && $key->valid) $keys[(int) $row->id] = $key->message_key; }
        $hidden = DB::table(self::HIDDEN)->where('actor_id', (int) $fresh->id)->where('conversation_id', $conversation)
            ->where(function ($q) use ($ids, $keys) { $q->whereIn('reply_id', $ids)->orWhereIn('native_message_key', array_values($keys)); })
            ->get(['reply_id', 'native_message_key']);
        return $rows->filter(fn ($row) => ($indexes->get($row->id) && (int) $indexes->get($row->id)->id <= (int) ($marker->projection_cutoff ?? 0))
            || $hidden->contains(fn ($item) => (int) $item->reply_id === (int) $row->id
            || (isset($keys[(int) $row->id]) && $item->native_message_key === $keys[(int) $row->id])))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function resolve($actor, int $conversation, int $message, bool $lock = false): array
    {
        $fresh = $this->actor($actor);
        if ($conversation < 1 || $message < 1 || config('desktop_dashboard.local', false)) throw new HttpException(404);
        $threads = DB::table('whatsapp_inbox_conversations')->where('id', $conversation)
            ->where('waba_id', WhatsAppInboxAccess::WABA_ID)->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID);
        $thread = ($lock ? $threads->lockForUpdate() : $threads)->first();
        if (!$thread) throw new HttpException(404);
        $query = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)->where('id', $message);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row || $this->hiddenIds($fresh, $conversation, [$message])) throw new HttpException(404);
        return $this->projected($thread, $row);
    }

    /** Pure validation of rows already read by an authenticated, scoped controller. */
    public function projected($thread, $row): array
    {
        $message = (int) $row->id; $conversation = (int) $thread->id;
        if (($thread->waba_id ?? null) !== WhatsAppInboxAccess::WABA_ID
            || ($thread->phone_number_id ?? null) !== WhatsAppInboxAccess::PHONE_ID
            || (int) ($row->conversation_id ?? 0) !== $conversation) throw new HttpException(422);
        try {
            if (!is_string($row->content) || strlen($row->content) > 8388608) throw new \RuntimeException();
            $plain = Crypt::decryptString($row->content);
            if (strlen($plain) > 4194304) throw new \RuntimeException();
            $dto = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            $raw = $dto['content']['message'] ?? null;
            $native = $dto['message_id'] ?? null;
            $identity = $dto['peer_identity'] ?? null;
            $phone = $dto['peer_phone'] ?? null;
            $user = $dto['peer_user_id'] ?? null;
            $hasUser = is_string($user) && $user !== '' && strlen($user) <= 512;
            $hasPhone = is_string($phone) && preg_match('/\A[1-9][0-9]{7,14}\z/', $phone);
            $expectedIdentity = $hasUser ? 'user:' . $user : ($hasPhone ? 'phone:' . $phone : null);
            $scope = WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':';
            $key = (string) config('app.key', '');
            if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true);
            $direction = $row->direction;
            $peerField = $direction === 'inbound' ? 'from' : 'to';
            $peerUserField = $direction === 'inbound' ? 'from_user_id' : 'to_user_id';
            if (!is_array($dto) || !is_array($raw) || !is_string($native)
                || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $native)
                || ($raw['id'] ?? null) !== $native || !is_string($key) || strlen($key) < 16
                || !is_string($identity) || $identity !== $expectedIdentity || (!$hasUser && !$hasPhone)
                || (isset($raw[$peerField]) && (!$hasPhone || ltrim((string) $raw[$peerField], '+') !== $phone))
                || (isset($raw[$peerUserField]) && $raw[$peerUserField] !== $user)
                || !(($hasPhone && isset($raw[$peerField])) || ($hasUser && ($raw[$peerUserField] ?? null) === $user))
                || !in_array($direction, ['inbound', 'outbound'], true)
                || ($dto['direction'] ?? null) !== $direction || ($dto['type'] ?? null) !== $row->type
                || ($raw['type'] ?? null) !== $row->type || ($dto['source'] ?? null) !== $row->source
                || ($dto['sent_at'] ?? null) !== $row->sent_at
                || !in_array($row->source, $direction === 'inbound' ? ['messages', 'standby'] : ['smb_message_echoes', 'standby'], true)
                || !hash_equals((string) $row->message_key, hash('sha256', 'whatsapp-message-v1:' . $scope . $native))
                || !hash_equals((string) $thread->peer_hash, hash_hmac('sha256', 'whatsapp-peer-v1:' . $scope . $identity, $key))) throw new \RuntimeException();
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row->sent_at, new \DateTimeZone('UTC'));
            if (!$time || $time->format('Y-m-d H:i:s') !== $row->sent_at
                || !preg_match('/\A[0-9]{1,10}\z/', (string) ($raw['timestamp'] ?? ''))
                || (int) $raw['timestamp'] !== $time->getTimestamp()) throw new \RuntimeException();
            $text = $row->type === 'text' ? ($raw['text']['body'] ?? null) : ($raw[$row->type]['caption'] ?? null);
            if ($text !== null && (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 20000)) throw new \RuntimeException();
            return ['id' => $message, 'conversation_id' => $conversation, 'native_id' => $native,
                'direction' => $direction, 'kind' => $row->type, 'text' => $text, 'raw' => $raw,
                'sent_at' => $row->sent_at, 'peer_phone' => $phone,
                'native_message_key' => $row->message_key, 'source_kind' => 'message',
                'fingerprint' => hash('sha256', json_encode($dto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
        } catch (\Throwable $error) { throw new HttpException(422); }
    }

    public function resolveReply($actor, int $conversation, int $reply, bool $lock = false): array
    {
        $fresh = $this->actor($actor);
        $this->thread($conversation, $lock);
        $query = $this->replies($conversation)->where('id', $reply)->where('state', 'ACCEPTED');
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row || $this->hiddenReplyIds($fresh, $conversation, [$reply])) throw new HttpException(404);
        if (Schema::hasTable(self::INDEX)) {
            $index = DB::table(self::INDEX)->where('reply_id', $reply)->where('conversation_id', $conversation)->first();
            if (!$index || !$index->valid || $index->kind !== $row->kind || $index->receipt_hash !== hash('sha256', (string) $row->response_details
                . ':' . (string) $row->remote_message_key . ':' . $row->conversation_id)) throw new HttpException(422);
        }
        try {
            $response = $this->decrypt($row->response_details); $audit = $this->decrypt($row->audit);
            $native = $response['message_id'] ?? null;
            if (!is_string($native) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $native)
                || !hash_equals((string) $row->remote_message_key, hash('sha256', 'whatsapp-reply-message-v1:'
                    . WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':' . $native))
                || !in_array($row->kind, ['text', 'audio', 'image', 'video', 'document'], true)
                || !is_string($audit['recipient'] ?? null) || !preg_match('/\A[1-9][0-9]{7,14}\z/', $audit['recipient'])) throw new \RuntimeException();
            $text = $row->kind === 'text' ? ($audit['text'] ?? null) : ($audit['caption'] ?? null);
            if ($text !== null && (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 4096)) throw new \RuntimeException();
            $raw = ['id' => $native, 'type' => $row->kind];
            if (is_array($audit['media'] ?? null)) $raw[$row->kind] = $audit['media'];
            $attachmentFingerprint = '';
            if (isset($audit['attachment'])) {
                $attachment = $audit['attachment']; $media = $audit['media'] ?? null;
                if (!is_array($attachment) || !is_array($media)
                    || !in_array($attachment['mime_type'] ?? null, WhatsAppInboxMedia::ATTACHMENT_TYPES, true)
                    || !is_string($attachment['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $attachment['sha256'])
                    || !is_int($attachment['size'] ?? null) || $attachment['size'] < 1
                    || $attachment['size'] > (str_starts_with($attachment['mime_type'], 'image/') ? WhatsAppInboxMedia::MAX_BYTES : WhatsAppInboxMedia::MAX_ATTACHMENT_BYTES)
                    || $row->kind !== (str_starts_with($attachment['mime_type'], 'image/') ? 'image' : 'document')
                    || !is_string($attachment['filename'] ?? null) || !mb_check_encoding($attachment['filename'], 'UTF-8')
                    || mb_strlen($attachment['filename']) > 120 || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $attachment['filename'])
                    || ($text !== null && (mb_strlen($text) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)))) throw new \RuntimeException();
                foreach (['sha256', 'mime_type', 'filename', 'size'] as $field)
                    if (($media[$field] ?? null) !== $attachment[$field]) throw new \RuntimeException();
                if ($this->media(['kind' => $row->kind, 'raw' => $raw]) === null) throw new \RuntimeException();
                $attachmentFingerprint = ':' . json_encode([$attachment, $media, $text], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            return ['id' => $reply, 'conversation_id' => $conversation, 'native_id' => $native, 'direction' => 'outbound',
                'kind' => $row->kind, 'text' => $text, 'raw' => $raw, 'sent_at' => $row->completed_at,
                'peer_phone' => $audit['recipient'], 'native_message_key' => $this->replyNativeKey($row), 'source_kind' => 'reply',
                'fingerprint' => hash('sha256', $row->payload_hash . ':' . $native . $attachmentFingerprint)];
        } catch (\Throwable $error) { throw new HttpException(422); }
    }

    public function summary(array $message): array
    {
        return ['message_id' => $message['source_kind'] === 'message' ? (int) $message['id'] : null,
            'reply_id' => $message['source_kind'] === 'reply' ? (int) $message['id'] : null,
            'text' => is_string($message['text']) ? mb_substr($message['text'], 0, 160) : null,
            'kind' => $message['kind'], 'direction' => $message['direction']];
    }

    public function media(array $message): ?array
    {
        $kind = $message['kind'];
        $types = ['image' => ['image/jpeg', 'image/png'], 'audio' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'],
            'video' => ['video/mp4', 'video/3gpp'], 'document' => ['text/plain', 'application/pdf', 'application/msword',
                'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.presentationml.presentation']];
        $media = $message['raw'][$kind] ?? null;
        if (!isset($types[$kind]) || !is_array($media) || !is_string($media['id'] ?? null)
            || !preg_match('/\A[1-9][0-9]{0,31}\z/', $media['id']) || !is_string($media['mime_type'] ?? null)
            || !in_array(strtolower(explode(';', $media['mime_type'])[0]), $types[$kind], true)) return null;
        $result = ['id' => $media['id'], 'mime' => strtolower(explode(';', $media['mime_type'])[0]), 'kind' => $kind,
            'sha256' => $this->digest($media['sha256'] ?? null)];
        if (is_int($media['size'] ?? null) && $media['size'] > 0 && $media['size'] <= WhatsAppInboxMedia::MAX_ATTACHMENT_BYTES)
            $result['size'] = $media['size'];
        if (is_string($media['filename'] ?? null) && mb_check_encoding($media['filename'], 'UTF-8')
            && mb_strlen($media['filename']) <= 120 && !preg_match('/[\x00-\x1f\x7f\/\\\\]/', $media['filename']))
            $result['filename'] = $media['filename'];
        return $result;
    }

    public function digest($value): ?string
    {
        if (!is_string($value)) return null;
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $value)) return strtolower($value);
        $bytes = base64_decode($value, true);
        return is_string($bytes) && strlen($bytes) === 32 ? bin2hex($bytes) : null;
    }

    public function hide($actor, int $conversation, int $message): array
    {
        $fresh = $this->actor($actor);
        if (!Schema::hasTable(self::HIDDEN)) throw new HttpException(503);
        return DB::transaction(function () use ($fresh, $conversation, $message) {
            $fresh = $this->actor($fresh);
            $this->thread($conversation, true);
            $row = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)->where('id', $message)->lockForUpdate()->first(['id', 'message_key']);
            if (!$row) throw new HttpException(404);
            $this->hideRows($fresh, $conversation, [$row], []);
            return ['success' => true, 'dashboard_only' => true, 'message_id' => $message, 'deleted' => true];
        }, 3);
    }

    public function updates($actor, int $conversation, array $messageIds, array $replyIds): array
    {
        $fresh = $this->actor($actor); $this->thread($conversation);
        if (!array_is_list($messageIds) || !array_is_list($replyIds) || count($messageIds) + count($replyIds) > 100) throw new HttpException(422);
        foreach (array_merge($messageIds, $replyIds) as $id) if (!is_int($id) || $id < 1) throw new HttpException(422);
        $messageIds = array_values(array_unique($messageIds)); $replyIds = array_values(array_unique($replyIds));
        $native = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)->whereIn('id', $messageIds)
            ->get(['id', 'conversation_id', 'message_key', 'direction', 'type']);
        $api = $this->replies($conversation)->where('state', 'ACCEPTED')->whereIn('kind', self::DISPLAY_KINDS)->whereIn('id', $replyIds)->get(['id']);
        if ($native->count() !== count($messageIds) || $api->count() !== count($replyIds)) throw new HttpException(422);
        $indexes = DB::table(self::INDEX)->where('conversation_id', $conversation)->whereIn('reply_id', $replyIds)->get()->keyBy('reply_id');
        $keys = array_unique(array_merge($native->pluck('message_key')->all(), $indexes->filter(fn ($row) => $row->valid)->pluck('message_key')->all()));
        $keys = array_values(array_filter($keys, fn ($key) => is_string($key) && preg_match('/\A[a-f0-9]{64}\z/', $key)));
        $reactions = $this->reactions($fresh, $conversation, $keys);
        $hiddenNative = $this->hiddenProjected($fresh, $native); $hiddenApi = $this->hiddenReplyIds($fresh, $conversation, $replyIds);
        $messages = []; $replies = [];
        foreach ($native as $row) { $deleted = in_array((int) $row->id, $hiddenNative, true);
            $messages[] = ['id' => (int) $row->id, 'deleted' => $deleted, 'reaction' => $deleted ? null : ($reactions[$row->message_key] ?? null)]; }
        foreach ($api as $row) { $deleted = in_array((int) $row->id, $hiddenApi, true); $index = $indexes->get($row->id);
            $replies[] = ['reply_id' => (int) $row->id, 'deleted' => $deleted, 'reaction' => !$deleted && $index && $index->valid ? ($reactions[$index->message_key] ?? null) : null]; }
        return ['success' => true, 'messages' => $messages, 'replies' => $replies];
    }

    public function selection($actor, int $conversation): array
    {
        $fresh = $this->actor($actor);
        $this->metadataReady();
        return DB::transaction(function () use ($fresh, $conversation) {
            $fresh = $this->actor($fresh); $this->thread($conversation, true);
            if ($this->unindexedReplies()->where('r.conversation_id', $conversation)->exists()
                && !$this->backfillProjectionIndex(200, $conversation)['complete']) throw new HttpException(503);
            $messageMax = (int) DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)->max('id');
            $replyMax = (int) $this->replies($conversation)->where('state', 'ACCEPTED')->whereIn('kind', self::DISPLAY_KINDS)->max('id');
            $projectionMax = (int) DB::table(self::INDEX)->where('conversation_id', $conversation)->max('id');
            $counts = $this->visibleCounts($fresh, $conversation, $messageMax, $projectionMax);
            $snapshot = ['actor' => (int) $fresh->id, 'conversation' => $conversation, 'waba' => WhatsAppInboxAccess::WABA_ID,
                'phone' => WhatsAppInboxAccess::PHONE_ID, 'expires' => time() + 900, 'message_max' => $messageMax, 'reply_max' => $replyMax, 'projection_max' => $projectionMax];
            return ['success' => true, 'snapshot_token' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'total_count' => $counts['messages'] + $counts['replies'], 'message_count' => $counts['messages'], 'reply_count' => $counts['replies'],
                'max_message_id' => $messageMax, 'max_reply_id' => $replyMax, 'max_projection_id' => $projectionMax, 'select_all_available' => true, 'truncated' => false];
        }, 3);
    }

    public function hideSelected($actor, int $conversation, array $input): array
    {
        $fresh = $this->actor($actor); $this->metadataReady();
        if (array_diff(array_keys($input), ['snapshot_token', 'select_all', 'message_ids', 'reply_ids'])
            || !is_string($input['snapshot_token'] ?? null) || strlen($input['snapshot_token']) > 4096
            || !is_bool($input['select_all'] ?? null)) throw new HttpException(422);
        try { $snapshot = $this->decrypt($input['snapshot_token']); } catch (\Throwable $error) { throw new HttpException(422); }
        if (($snapshot['actor'] ?? null) !== (int) $fresh->id || ($snapshot['conversation'] ?? null) !== $conversation
            || ($snapshot['waba'] ?? null) !== WhatsAppInboxAccess::WABA_ID || ($snapshot['phone'] ?? null) !== WhatsAppInboxAccess::PHONE_ID
            || !is_int($snapshot['expires'] ?? null) || $snapshot['expires'] < time()
            || !is_int($snapshot['message_max'] ?? null) || $snapshot['message_max'] < 0
            || !is_int($snapshot['reply_max'] ?? null) || $snapshot['reply_max'] < 0
            || !is_int($snapshot['projection_max'] ?? null) || $snapshot['projection_max'] < 0) throw new HttpException(422);
        $messages = $input['message_ids'] ?? []; $replies = $input['reply_ids'] ?? [];
        foreach ([$messages, $replies] as $ids) {
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) throw new HttpException(422);
            foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new HttpException(422);
        }
        if (count($messages) + count($replies) > 100 || ($input['select_all'] && ($messages || $replies))) throw new HttpException(422);
        $messages = array_values(array_unique($messages)); $replies = array_values(array_unique($replies));
        return DB::transaction(function () use ($fresh, $conversation, $messages, $replies, $snapshot, $input) {
            $fresh = $this->actor($fresh); $this->thread($conversation, true);
            $before = $this->visibleCounts($fresh, $conversation, $snapshot['message_max'], $snapshot['projection_max']);
            if ($input['select_all']) {
                $old = DB::table(self::HIDDEN)->where('actor_id', (int) $fresh->id)->where('conversation_id', $conversation)->where('item_key', 'all')->lockForUpdate()->first();
                $values = ['message_cutoff' => max((int) ($old->message_cutoff ?? 0), $snapshot['message_max']),
                    'projection_cutoff' => max((int) ($old->projection_cutoff ?? 0), $snapshot['projection_max']), 'updated_at' => now('UTC')];
                if ($old) DB::table(self::HIDDEN)->where('id', $old->id)->update($values);
                else DB::table(self::HIDDEN)->insert($values + ['actor_id' => (int) $fresh->id, 'conversation_id' => $conversation,
                    'item_key' => 'all', 'created_at' => now('UTC')]);
            } else {
                foreach ($messages as $id) if ($id > $snapshot['message_max']) throw new HttpException(422);
                foreach ($replies as $id) if ($id > $snapshot['reply_max']) throw new HttpException(422);
                $native = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)->whereIn('id', $messages)->lockForUpdate()->get(['id', 'message_key']);
                $accepted = DB::table(self::INDEX)->where('conversation_id', $conversation)->where('id', '<=', $snapshot['projection_max'])->whereIn('reply_id', $replies)->pluck('reply_id')->all();
                if (count($accepted) !== count($replies)) throw new HttpException(422);
                $api = $this->replies($conversation)->where('state', 'ACCEPTED')->whereIn('kind', self::DISPLAY_KINDS)->whereIn('id', $replies)->lockForUpdate()->get();
                if ($native->count() !== count($messages) || $api->count() !== count($replies)) throw new HttpException(422);
                $this->hideRows($fresh, $conversation, $native, $api);
            }
            $after = $this->visibleCounts($fresh, $conversation, $snapshot['message_max'], $snapshot['projection_max']);
            return ['success' => true, 'dashboard_only' => true,
                'hidden_count' => $before['messages'] + $before['replies'] - $after['messages'] - $after['replies'],
                'message_count' => $before['messages'] - $after['messages'], 'reply_count' => $before['replies'] - $after['replies']];
        }, 3);
    }

    private function metadataReady(): void
    {
        if (!Schema::hasTable(self::HIDDEN) || !Schema::hasTable(self::INDEX)) throw new HttpException(503);
    }

    private function visibleCounts($actor, int $conversation, int $messageMax, int $projectionMax): array
    {
        $native = $this->visibleNative($actor, $conversation)->where('m.id', '<=', $messageMax);
        $replies = $this->visibleApi($actor, $conversation)->where('p.id', '<=', $projectionMax)
            ->whereNotExists(function ($q) use ($actor, $conversation, $messageMax) {
                $q->selectRaw('1')->from('whatsapp_inbox_messages as counterpart')->where('counterpart.conversation_id', $conversation)
                    ->where('counterpart.id', '<=', $messageMax)->where('counterpart.direction', 'outbound')
                    ->whereColumn('counterpart.type', 'r.kind')->whereColumn('counterpart.message_key', 'p.message_key');
            });
        return ['messages' => (int) $native->count(), 'replies' => (int) $replies->count()];
    }

    private function visibleNative($actor, int $conversation)
    {
        return DB::table('whatsapp_inbox_messages as m')->where('m.conversation_id', $conversation)
            ->whereNotExists(function ($q) use ($actor, $conversation) {
                $q->selectRaw('1')->from(self::HIDDEN . ' as h')->where('h.actor_id', (int) $actor->id)->where('h.conversation_id', $conversation)
                    ->where(function ($same) { $same->whereColumn('h.message_id', 'm.id')->orWhereColumn('h.native_message_key', 'm.message_key')
                        ->orWhereColumn('h.message_cutoff', '>=', 'm.id'); });
            })->whereNotExists(function ($q) use ($actor, $conversation) {
                $q->selectRaw('1')->from(self::INDEX . ' as p')->join(self::HIDDEN . ' as cutoff', function ($join) use ($actor, $conversation) {
                    $join->where('cutoff.actor_id', (int) $actor->id)->where('cutoff.conversation_id', $conversation)->where('cutoff.item_key', 'all');
                })->where('p.conversation_id', $conversation)->where('p.valid', true)->where('p.waba_id', WhatsAppInboxAccess::WABA_ID)
                    ->where('p.phone_number_id', WhatsAppInboxAccess::PHONE_ID)->whereColumn('p.message_key', 'm.message_key')
                    ->where('m.direction', 'outbound')->whereColumn('m.type', 'p.kind')
                    ->whereColumn('p.id', '<=', 'cutoff.projection_cutoff');
            });
    }

    private function visibleApi($actor, int $conversation)
    {
        return DB::table('whatsapp_reply_requests as r')->leftJoin(self::INDEX . ' as p', function ($join) {
                $join->on('p.reply_id', '=', 'r.id')->on('p.conversation_id', '=', 'r.conversation_id')
                    ->on('p.waba_id', '=', 'r.waba_id')->on('p.phone_number_id', '=', 'r.phone_number_id')->on('p.kind', '=', 'r.kind');
            })
            ->where('r.conversation_id', $conversation)->where('r.waba_id', WhatsAppInboxAccess::WABA_ID)
            ->where('r.phone_number_id', WhatsAppInboxAccess::PHONE_ID)->where('r.state', 'ACCEPTED')->whereIn('r.kind', self::DISPLAY_KINDS)
            ->whereNotExists(function ($q) use ($actor, $conversation) {
                $q->selectRaw('1')->from(self::HIDDEN . ' as h')->where('h.actor_id', (int) $actor->id)->where('h.conversation_id', $conversation)
                    ->where(function ($same) { $same->whereColumn('h.reply_id', 'r.id')->orWhereColumn('h.native_message_key', 'p.message_key')
                        ->orWhereColumn('h.projection_cutoff', '>=', 'p.id'); });
            });
    }

    private function hideRows($actor, int $conversation, $messages, $replies): array
    {
        $counts = ['messages' => 0, 'replies' => 0]; $stamp = now('UTC');
        foreach (['messages' => $messages, 'replies' => $replies] as $kind => $rows) foreach ($rows as $row) {
            $native = null;
            try {
                $native = $kind === 'messages' ? $row->message_key : DB::table(self::INDEX)->where('reply_id', $row->id)
                    ->where('conversation_id', $conversation)->where('valid', true)->value('message_key');
            } catch (\Throwable $error) {}
            $counts[$kind] += DB::table(self::HIDDEN)->insertOrIgnore(['actor_id' => (int) $actor->id, 'conversation_id' => $conversation,
                'item_key' => ($kind === 'messages' ? 'm:' : 'r:') . $row->id, 'message_id' => $kind === 'messages' ? $row->id : null,
                'reply_id' => $kind === 'replies' ? $row->id : null, 'native_message_key' => $native, 'created_at' => $stamp, 'updated_at' => $stamp]);
        }
        return $counts;
    }

    private function replies(int $conversation)
    {
        return DB::table('whatsapp_reply_requests')->where('conversation_id', $conversation)
            ->where('waba_id', WhatsAppInboxAccess::WABA_ID)->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID);
    }
    private function marker($actor, int $conversation)
    {
        return DB::table(self::HIDDEN)->where('actor_id', (int) $actor->id)->where('conversation_id', $conversation)
            ->where('item_key', 'all')->first(['message_cutoff', 'projection_cutoff']);
    }
    private function thread(int $conversation, bool $lock = false)
    {
        $query = DB::table('whatsapp_inbox_conversations')->where('id', $conversation)->where('waba_id', WhatsAppInboxAccess::WABA_ID)->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID);
        $row = ($lock ? $query->lockForUpdate() : $query)->first(['id']);
        if (!$row) throw new HttpException(404);
        return $row;
    }
    private function decrypt(string $value): array
    {
        if (strlen($value) > 8388608) throw new \RuntimeException();
        $plain = Crypt::decryptString($value);
        if (strlen($plain) > 4194304) throw new \RuntimeException();
        $data = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException();
        return $data;
    }
    private function replyNativeKey($row): string
    {
        $native = $this->decrypt($row->response_details)['message_id'] ?? null;
        if (!is_string($native) || !preg_match('/\Awamid\.[A-Za-z0-9_+\/=.-]{1,500}\z/', $native)) throw new \RuntimeException();
        return hash('sha256', 'whatsapp-message-v1:' . WhatsAppInboxAccess::WABA_ID . ':' . WhatsAppInboxAccess::PHONE_ID . ':' . $native);
    }
}
