<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SupportInbox
{
    public function __construct(protected SupportFirestore $firestore) {}

    public function canAccess(User $actor): bool
    {
        return (int) $actor->id === 1 || $actor->can('support_contact-list');
    }

    public function isStaff(User $actor): bool
    {
        return $actor->account_type === 'admin' && $this->canAccess($actor);
    }

    /** MainController::getSetting returns the first administrator to customer apps. */
    public function centralId(): int
    {
        return (int) (DB::table('users')->where('account_type', 'admin')->orderBy('id')->value('id') ?: 1);
    }

    public function inboxIds(User $actor): array
    {
        abort_unless($this->canAccess($actor), 403);
        return $this->isStaff($actor) ? array_values(array_unique([(int) $actor->id, $this->centralId()])) : [(int) $actor->id];
    }

    public function inboxId(User $actor, ?int $selected = null): int
    {
        $inboxes = $this->inboxIds($actor);
        $selected = $selected ?? (int) $actor->id;
        abort_unless(in_array($selected, $inboxes, true), 403);
        return $selected;
    }

    public function partner(User $actor, int $partner, ?int $selected = null): User
    {
        $inbox = $this->inboxId($actor, $selected);
        abort_if($inbox === $partner || $partner < 1, 404);
        $user = User::withoutGlobalScopes()->findOrFail($partner);
        abort_unless($this->isStaff($actor) ? $user->account_type !== 'admin'
            : $user->account_type === 'admin' && in_array($partner, [1, $this->centralId()], true), 403);
        return $user;
    }

    public function room(User $actor, int $partner, ?int $selected = null): string
    {
        $this->partner($actor, $partner, $selected);
        $ids = [$this->inboxId($actor, $selected), $partner]; sort($ids, SORT_NUMERIC);
        return 'room_'.implode('_', $ids);
    }

    protected function incoming(array $document, int $recipient): bool
    {
        $sender = (int) SupportFirestore::value($document, 'sender_id');
        return (int) SupportFirestore::value($document, 'user_id') === $recipient && $sender > 0 && $sender !== $recipient;
    }

    public function unread(User $actor, bool $fresh = false): array
    {
        $total = ['count' => 0, 'conversations' => [], 'threads' => []];
        foreach ($this->inboxIds($actor) as $inbox) {
            $result = $this->unreadInbox($inbox, $this->isStaff($actor), $fresh);
            $total['count'] += $result['count'];
            foreach ($result['conversations'] as $partner => $count) {
                $total['conversations'][(string) $partner] = ($total['conversations'][(string) $partner] ?? 0) + $count;
                $total['threads'][$inbox.':'.$partner] = $count;
            }
        }
        return $total;
    }

    protected function unreadInbox(int $inbox, bool $staff, bool $fresh): array
    {
        if ($fresh) Cache::forget($this->cacheKey($inbox));
        return Cache::remember($this->cacheKey($inbox), 8, function () use ($inbox, $staff) {
            $count = 0; $conversations = [];
            foreach ($this->firestore->rooms($inbox) as $room) {
                $name = basename($room['name'] ?? '');
                if (!preg_match('/^room_([1-9][0-9]*)_([1-9][0-9]*)$/D', $name, $ids)) continue;
                if ((int) $ids[1] !== $inbox && (int) $ids[2] !== $inbox) continue;
                $partner = (int) $ids[1] === $inbox ? (int) $ids[2] : (int) $ids[1];
                if ($staff ? !DB::table('users')->where('id', $partner)->where('account_type', '!=', 'admin')->exists()
                    : (!in_array($partner, [1, $this->centralId()], true) || !DB::table('users')->where('id', $partner)->where('account_type', 'admin')->exists())) continue;
                $version = (string) ($room['updateTime'] ?? SupportFirestore::value($room, 'lastMessageTimestamp') ?? '');
                $roomKey = $this->cacheKey($inbox).'-'.$name;
                $cached = Cache::get($roomKey);
                if ($version && is_array($cached) && ($cached['version'] ?? null) === $version) {
                    $unread = $cached['count'];
                } else {
                    $unread = 0; $page = ''; $complete = false;
                    for ($i = 0; $i < 100; $i++) {
                        $data = $this->firestore->messages($name, $page);
                        foreach ($data['documents'] ?? [] as $document) {
                            if ($this->incoming($document, $inbox) && (int) SupportFirestore::value($document, 'sender_id') === $partner
                                && !SupportFirestore::value($document, 'dashboard_support_read_at')) $unread++;
                        }
                        $page = $data['nextPageToken'] ?? '';
                        if (!$page) { $complete = true; break; }
                    }
                    if (!$complete) throw new \RuntimeException('Support count incomplete.');
                    if ($version) Cache::put($roomKey, ['version' => $version, 'count' => $unread], now()->addDay());
                }
                if ($unread) $conversations[(string) $partner] = $unread;
                $count += $unread;
            }
            return ['count' => $count, 'conversations' => $conversations];
        });
    }

    public function messages(User $actor, int $partner, string $page = '', ?int $selected = null): array
    {
        $room = $this->room($actor, $partner, $selected);
        $inbox = $this->inboxId($actor, $selected);
        $data = $this->firestore->messages($room, $page);
        $ids = [$inbox, $partner]; $messages = [];
        foreach ($data['documents'] ?? [] as $document) {
            $sender = (int) SupportFirestore::value($document, 'sender_id');
            $recipient = (int) SupportFirestore::value($document, 'user_id');
            if ($sender === $recipient || !in_array($sender, $ids, true) || !in_array($recipient, $ids, true)) continue;
            $messages[] = $this->present($document, $inbox);
        }
        return ['messages' => array_reverse($messages), 'next_page_token' => $data['nextPageToken'] ?? null,
            'room' => $room, 'inbox_id' => $inbox];
    }

    public function read(User $actor, int $partner, array $ids, ?int $selected = null): int
    {
        $room = $this->room($actor, $partner, $selected);
        $inbox = $this->inboxId($actor, $selected); $documents = [];
        $ids = array_unique($ids);
        foreach ($this->firestore->batchMessages($room, $ids) as $document) {
            if (!in_array(basename($document['name'] ?? ''), $ids, true)) continue;
            if (!$this->incoming($document, $inbox) || (int) SupportFirestore::value($document, 'sender_id') !== $partner
                || SupportFirestore::value($document, 'dashboard_support_read_at')) continue;
            $documents[] = $document;
        }
        // One atomic masked commit for the displayed snapshot; a concurrent edit is retried by the client.
        $this->firestore->markReads($room, $documents, (int) $actor->id);
        Cache::forget($this->cacheKey($inbox));
        Cache::forget($this->cacheKey($inbox).'-'.$room);
        return count($documents);
    }

    public function send(User $actor, int $partner, string $message, ?int $selected = null, ?string $requestKey = null): array
    {
        $recipient = $this->partner($actor, $partner, $selected);
        $inbox = $this->inboxId($actor, $selected);
        $document = $this->firestore->send($this->room($actor, $partner, $selected), [
            'message' => ['stringValue' => $message], 'messageType' => ['integerValue' => '0'],
            'sender_id' => ['stringValue' => (string) $inbox], 'user_id' => ['stringValue' => (string) $partner],
            'sender_name' => ['stringValue' => (string) $actor->name],
            'receiver_name' => ['stringValue' => (string) ($recipient->name ?: $recipient->mobile)],
            'timestamp' => ['timestampValue' => now()->toIso8601ZuluString()],
            'dashboard_sender_admin_id' => ['integerValue' => (string) $actor->id],
        ], $requestKey ?? (string) \Illuminate\Support\Str::uuid());
        Cache::forget($this->cacheKey($partner));
        Cache::forget($this->cacheKey($partner).'-'.$this->room($actor, $partner, $selected));
        return $this->present($document, $inbox);
    }

    protected function present(array $document, int $inbox): array
    {
        return [
            'id' => basename($document['name'] ?? ''), 'message' => SupportFirestore::value($document, 'message') ?? '',
            'sender_id' => (int) SupportFirestore::value($document, 'sender_id'),
            'recipient_id' => (int) SupportFirestore::value($document, 'user_id'),
            'timestamp' => SupportFirestore::value($document, 'timestamp'),
            'read' => (bool) SupportFirestore::value($document, 'dashboard_support_read_at'),
            'incoming' => $this->incoming($document, $inbox),
        ];
    }

    protected function cacheKey(int $inbox): string
    {
        return 'dashboard-support-unread-'.$this->firestore->project().'-'.$inbox;
    }
}
