<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read markers belong to the persisted central actor, never to a selected-user session. */
class WhatsAppInboxReadState
{
    private const TABLE = 'whatsapp_inbox_reads';
    private const THREAD_LIMIT = 1000;

    public function available(): bool
    {
        return app(WhatsAppInboxAccess::class)->available() && Schema::hasTable(self::TABLE);
    }

    public function unread($actor, ?array $ids = null): array
    {
        $actor = $this->actor($actor);
        abort_unless($this->available(), 503);
        if ($ids !== null) {
            abort_unless(array_is_list($ids) && count($ids) <= 100, 422);
            $ids = array_values(array_unique(array_map(function ($id) {
                return $this->positiveId($id);
            }, $ids)));
        }
        // One aggregate counts the actor's entire scoped inbox without loading chat history.
        $total = (int) DB::table('whatsapp_inbox_messages as m')
            ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin(self::TABLE . ' as r', function ($join) use ($actor) {
                $join->on('r.conversation_id', '=', 'm.conversation_id')->where('r.actor_id', '=', $actor->id);
            })
            ->where('c.waba_id', WhatsAppInboxAccess::WABA_ID)->where('c.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->where('m.direction', 'inbound')->whereRaw('m.id > COALESCE(r.seen_message_id, 0)')->count();
        $query = $this->threads();
        if ($ids !== null) $query->whereIn('id', $ids);
        $limit = $ids === null ? self::THREAD_LIMIT : 100;
        $threads = $query->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit($limit + 1)->pluck('id');
        $more = $threads->count() > $limit;
        $selected = $threads->take($limit)->map(fn ($id) => (int) $id)->all();
        if (!$selected) return ['success' => true, 'total_unread' => $total,
            'conversations' => [], 'has_more' => false];

        $rows = DB::table('whatsapp_inbox_messages as m')
            ->leftJoin(self::TABLE . ' as r', function ($join) use ($actor) {
                $join->on('r.conversation_id', '=', 'm.conversation_id')->where('r.actor_id', '=', $actor->id);
            })
            ->whereIn('m.conversation_id', $selected)->where('m.direction', 'inbound')
            ->select('m.conversation_id')
            ->selectRaw('SUM(CASE WHEN m.id > COALESCE(r.seen_message_id, 0) THEN 1 ELSE 0 END) AS unread_count')
            ->selectRaw('MAX(m.id) AS latest_inbound_id')->groupBy('m.conversation_id')->get()->keyBy('conversation_id');
        $items = [];
        foreach ($selected as $id) {
            $row = $rows->get($id);
            $count = (int) ($row->unread_count ?? 0);
            $items[] = ['id' => $id, 'unread_count' => $count,
                'latest_inbound_id' => (int) ($row->latest_inbound_id ?? 0)];
        }
        return ['success' => true, 'total_unread' => $total, 'conversations' => $items, 'has_more' => $more];
    }

    public function markRead(int $conversation, $seenMessage, $actor): array
    {
        $actor = $this->actor($actor);
        abort_unless($this->available(), 503);
        $conversation = $this->positiveId($conversation);
        $seenMessage = $this->positiveId($seenMessage);
        return DB::transaction(function () use ($conversation, $seenMessage, $actor) {
            $actor = $this->actor($actor);
            // The inbox consumer uses the same thread lock before inserting a new message.
            $thread = $this->threads()->where('id', $conversation)->lockForUpdate()->first(['id']);
            abort_unless($thread, 404);
            $message = DB::table('whatsapp_inbox_messages')->where('conversation_id', $conversation)
                ->where('id', $seenMessage)->where('direction', 'inbound')->lockForUpdate()->first(['id']);
            abort_unless($message, 422);
            DB::table(self::TABLE)->insertOrIgnore(['actor_id' => (int) $actor->id,
                'conversation_id' => $conversation, 'seen_message_id' => 0,
                'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $row = DB::table(self::TABLE)->where('actor_id', $actor->id)->where('conversation_id', $conversation)
                ->lockForUpdate()->first();
            abort_unless($row, 503);
            $seen = max((int) $row->seen_message_id, $seenMessage);
            if ($seen > (int) $row->seen_message_id) {
                DB::table(self::TABLE)->where('actor_id', $actor->id)->where('conversation_id', $conversation)
                    ->where('seen_message_id', '<', $seen)->update(['seen_message_id' => $seen, 'updated_at' => now('UTC')]);
            }
            return ['success' => true, 'conversation_id' => $conversation, 'seen_message_id' => $seen];
        }, 3);
    }

    private function actor($actor)
    {
        return app(WhatsAppInboxAccess::class)->actor($actor);
    }

    private function threads()
    {
        return DB::table('whatsapp_inbox_conversations')->where('waba_id', WhatsAppInboxAccess::WABA_ID)
            ->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID);
    }

    private function positiveId($id): int
    {
        abort_unless(is_int($id) || is_string($id), 422);
        $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(is_int($number), 422);
        return $number;
    }
}
