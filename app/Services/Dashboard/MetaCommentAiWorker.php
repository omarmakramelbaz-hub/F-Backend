<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Independent, bounded public-comment polling. No inbox/order job or webhook subscription is changed. */
class MetaCommentAiWorker
{
    private const MAX_QUEUE = 40;
    private const MAX_PENDING = 100;
    private const FRESH_HEADS = 2;
    private const RECENT_REPLIES = 6;
    private MetaCommentAiControl $control;
    private MetaCommentsService $comments;
    private MetaCommentAiProvider $provider;
    private MetaCommentReplyGate $gate;
    private $owner;
    private $clock;

    public function __construct(?MetaCommentAiControl $control = null, ?MetaCommentsService $comments = null,
        ?MetaCommentAiProvider $provider = null, ?MetaCommentReplyGate $gate = null,
        ?callable $owner = null, ?callable $clock = null)
    {
        $this->control = $control ?? app(MetaCommentAiControl::class);
        $this->comments = $comments ?? app(MetaCommentsService::class);
        $this->provider = $provider ?? app(MetaCommentAiProvider::class);
        $this->gate = $gate ?? app(MetaCommentReplyGate::class);
        $this->owner = $owner ?? static fn ($id) => \App\Models\User::query()->find($id);
        $this->clock = $clock ?? static fn () => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Read-only deployment check: deliberately no Graph/OpenAI requests, heartbeat, or claims. */
    public function check(): array
    {
        $ready = $this->control->ready() && $this->gate->ready();
        $snapshot = $ready ? $this->control->snapshot() : null;
        $configured = $this->provider->ready();
        return ['ready' => $ready && $snapshot !== null, 'enabled' => (bool) ($snapshot['enabled'] ?? false),
            'configured' => $configured, 'reason' => !$ready || $snapshot === null ? 'AI_UNAVAILABLE'
                : (!$configured ? 'AI_NOT_CONFIGURED' : null)];
    }

    public function run(int $budgetSeconds = 30, int $sendLimit = 4): array
    {
        $result = ['seen' => 0, 'attempted' => 0, 'sent' => 0, 'skipped' => 0, 'unknown' => 0, 'errors' => 0,
            'skipped_timing' => 0, 'skipped_self' => 0, 'skipped_unreplyable' => 0,
            'skipped_gate' => 0, 'skipped_business' => 0, 'skipped_ai' => 0, 'head_deferred' => 0, 'reason' => null];
        $budgetSeconds = max(1, min(60, $budgetSeconds));
        $sendLimit = max(1, min(4, $sendLimit));
        $deadline = hrtime(true) + $budgetSeconds * 1000000000;
        $this->comments->setAutomationDeadline($deadline);
        $generationStopped = false;
        $policy = $this->control->snapshot();
        if (!$policy || !$this->gate->ready()) return $this->done($result, 'AI_UNAVAILABLE');
        if (!$this->control->activeAt($policy, ($this->clock)())) return $this->done($result, 'INACTIVE');
        if (!$this->provider->ready()) return $this->done($result, 'AI_NOT_CONFIGURED');
        try {
            $actor = ($this->owner)($policy['actor_id'] ?? null);
            $accounts = $this->comments->automationAccounts($actor);
        } catch (\Throwable $error) { return $this->done($result, 'ACCESS_REVOKED'); }
        $states = [];
        foreach ($accounts as $account) {
            if (empty($account['connected']) || !is_string($account['account_key'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $account['account_key']) !== 1) continue;
            $states[$account['account_key']] = $this->load($account['account_key'], (int) $policy['revision']);
        }
        // A busy Page cannot consume every run before the other configured channel receives a turn.
        uasort($states, static fn ($left, $right) => ($left['turn'] ?? 0) <=> ($right['turn'] ?? 0));
        foreach ($states as $accountKey => $state) {
            if (hrtime(true) >= $deadline || $result['attempted'] >= $sendLimit) break;
            $state['turn'] = ($this->clock)()->getTimestamp();
            $post = null; $comment = null;
            try {
                $recentPosts = null;
                if (!$state['queue']) $state = $this->fill($actor, $accountKey, $state, $recentPosts);
                if ($recentPosts === null) $recentPosts = $this->comments->automationPosts($actor, $accountKey)['posts'];
                $headFollowups = [];
                $state = $this->refreshHeads($actor, $accountKey, $state, $recentPosts, $headFollowups, $result);
                $visits = count($state['queue']);
                while ($state['queue'] && $visits-- > 0 && hrtime(true) < $deadline
                    && $result['attempted'] < $sendLimit) {
                    $post = array_shift($state['queue']);
                    $comment = null;
                    if (!isset($post['pending'])) {
                        $page = $this->comments->automationComments($actor, $accountKey, $post['native_id'], $post['cursor'] ?? null);
                        $post['pending'] = array_values($page['comments']);
                        $post['cursor'] = $page['next_cursor'];
                    }
                    $stop = false;
                    $candidates = count($post['pending']);
                    while ($post['pending'] && $candidates-- > 0) {
                        if (hrtime(true) >= $deadline || $result['attempted'] >= $sendLimit) break;
                        $comment = array_shift($post['pending']);
                        if (($comment['retry_at'] ?? 0) > ($this->clock)()->getTimestamp()) {
                            $post['pending'][] = $comment; continue;
                        }
                        $result['seen']++;
                        if (!$this->control->eligible($policy, $comment['comment_created_at'] ?? null, ($this->clock)())) {
                            $result['skipped']++; $result['skipped_timing']++; continue;
                        }
                        if (($comment['is_self'] ?? false) === true || ($comment['can_reply'] ?? true) === false
                            || trim((string) ($comment['text'] ?? '')) === '') {
                            $this->gate->skip($comment, ($comment['is_self'] ?? false) ? 'SELF_COMMENT' : 'NOT_REPLYABLE');
                            $result[($comment['is_self'] ?? false) ? 'skipped_self' : 'skipped_unreplyable']++;
                            $result['skipped']++; continue;
                        }
                        if (!$this->gate->mayAutomate($comment)) { $result['skipped']++; $result['skipped_gate']++; continue; }
                        $replied = $this->comments->automationHasBusinessReply($actor, $comment);
                        if ($replied === true) {
                            $this->gate->skip($comment, 'BUSINESS_REPLIED'); $result['skipped']++; $result['skipped_business']++; continue;
                        }
                        if ($replied === null) {
                            // Do not guess when an entire reply thread cannot be read within its bounded pages.
                            $comment['retry_at'] = ($this->clock)()->getTimestamp() + 300;
                            $post['pending'][] = $comment; $result['reason'] = 'REPLIES_INCOMPLETE'; continue;
                        }
                        if ($generationStopped) { $post['pending'][] = $comment; continue; }
                        $draft = $this->provider->reply(['post_text' => (string) ($post['text'] ?? ''),
                            'comment_text' => (string) $comment['text'], 'product_names' => [],
                            'recent_replies' => $state['recent_replies'],
                            'request_timeout_seconds' => max(1, min(30, (int) (($deadline - hrtime(true)) / 1000000000)))]);
                        if (($draft['kind'] ?? null) === 'SKIP' && (($draft['ok'] ?? false) === true
                            || in_array($draft['reason'] ?? null, ['NO_SAFE_REPLY', 'UNSAFE_REPLY', 'UNGROUNDED_REPLY', 'INVALID_INPUT', 'REFUSED'], true))) {
                            $this->gate->skip($comment, 'AI_SKIPPED'); $result['skipped']++; $result['skipped_ai']++; continue;
                        }
                        if (($draft['ok'] ?? false) !== true || !is_string($draft['message'] ?? null) || trim($draft['message']) === '') {
                            $comment['failures'] = min(5, (int) ($comment['failures'] ?? 0) + 1);
                            $comment['retry_at'] = ($this->clock)()->getTimestamp() + min(900, 60 * (2 ** ($comment['failures'] - 1)));
                            $post['pending'][] = $comment;
                            $result['reason'] = $this->reason($draft['reason'] ?? null, 'AI_GENERATION_FAILED');
                            $result['errors']++; $generationStopped = true; continue;
                        }
                        if (hrtime(true) >= $deadline) { array_unshift($post['pending'], $comment); break; }
                        // Refresh outside the stop/update mutex: only the bounded provider POST holds that mutex.
                        $replied = $this->comments->automationHasBusinessReply($actor, $comment);
                        if ($replied !== false) {
                            if ($replied === true) {
                                $this->gate->skip($comment, 'BUSINESS_REPLIED'); $result['skipped']++; $result['skipped_business']++; continue;
                            }
                            array_unshift($post['pending'], $comment); $result['reason'] = 'REPLIES_INCOMPLETE'; $stop = true; break;
                        }
                        if (hrtime(true) >= $deadline) { array_unshift($post['pending'], $comment); break; }
                        $receipt = $this->control->withActiveDispatch($policy, function () use ($actor, $comment, $draft, $policy, &$result) {
                            // Recheck after generation; a human may have replied while AI was working.
                            if (!$this->gate->mayAutomate($comment)) { $result['skipped_gate']++; return ['state' => 'SKIPPED']; }
                            if (!$this->control->eligible($policy, $comment['comment_created_at'] ?? null, ($this->clock)())) { $result['skipped_timing']++; return ['state' => 'SKIPPED']; }
                            $result['attempted']++;
                            return $this->comments->sendAutomation($actor, $comment, $draft['message'], (int) $policy['revision']);
                        });
                        if ($receipt === null || ($receipt['state'] ?? null) === 'DEFERRED') {
                            array_unshift($post['pending'], $comment); $result['reason'] = 'DISPATCH_STOPPED'; $stop = true; break;
                        }
                        if (($receipt['state'] ?? null) === 'ACCEPTED') {
                            $result['sent']++;
                            // Style memory contains published prose only, never uncertain or failed drafts.
                            if (($draft['kind'] ?? null) === 'REPLY') {
                                $state['recent_replies'] = $this->recentReplies(array_merge([$draft['message']], $state['recent_replies']));
                            }
                        }
                        elseif (($receipt['state'] ?? null) === 'UNKNOWN') $result['unknown']++;
                        elseif (($receipt['state'] ?? null) === 'SKIPPED') $result['skipped']++;
                        else {
                            $result['errors']++; $result['reason'] = $this->reason($receipt['reason'] ?? null, 'SEND_FAILED');
                            $stop = true;
                        }
                        if ($stop) break;
                    }
                    // Pending rows and deep comment cursors survive the wall/send budget and process restarts.
                    if ($post['pending'] || $post['cursor'] !== null) {
                        if (!$post['pending']) unset($post['pending']);
                        if (!isset($post['pending']) && isset($headFollowups[$post['native_id']])) {
                            // A refreshed head must not prevent its saved deep cursor from advancing.
                            unset($headFollowups[$post['native_id']]); $visits++;
                            array_unshift($state['queue'], $post);
                        } else $state['queue'][] = $post;
                    }
                    $post = null; $comment = null;
                    if ($stop) break;
                }
            } catch (MetaCommentsFailure $error) {
                $result['errors']++; $result['reason'] = $this->reason($error->reason, 'POLL_FAILED');
                if (in_array($error->reason, ['INVALID_CURSOR', 'INVALID_HANDLE'], true)) {
                    $state['queue'] = []; $state['history_cursor'] = null; $state['phase'] = 'recent';
                } else $state = $this->restore($state, $post, $comment);
            } catch (\Throwable $error) {
                $result['errors']++; $result['reason'] = 'POLL_FAILED';
                $state = $this->restore($state, $post, $comment);
            } finally {
                $this->save($accountKey, $state);
            }
            if ($result['reason'] === 'DISPATCH_STOPPED') break;
        }
        return $this->done($result, $result['reason']);
    }

    private function fill($actor, string $accountKey, array $state, ?array &$recentPosts = null): array
    {
        $recent = $state['phase'] === 'recent';
        $page = $this->comments->automationPosts($actor, $accountKey, $recent ? null : $state['history_cursor']);
        if ($recent) $recentPosts = $page['posts'];
        foreach ($page['posts'] as $post) {
            if (!is_string($post['native_id'] ?? null) || preg_match('/\A[1-9][0-9]{0,31}(?:_[1-9][0-9]{0,31})?\z/', $post['native_id']) !== 1) continue;
            $state['queue'][] = ['native_id' => $post['native_id'], 'text' => mb_substr((string) ($post['text'] ?? ''), 0, 10000), 'cursor' => null];
        }
        if (!$recent || $state['history_cursor'] === null) $state['history_cursor'] = $page['next_cursor'];
        $state['phase'] = $recent ? 'history' : 'recent';
        return $state;
    }

    /** Fresh heads have their own polling lane; an old busy thread cannot hide a new comment. */
    private function refreshHeads($actor, string $accountKey, array $state, array $recentPosts,
        array &$followups, array &$result): array
    {
        $heads = []; $seen = [];
        foreach (array_slice($recentPosts, 0, self::FRESH_HEADS) as $recent) {
            $native = $recent['native_id'] ?? null;
            if (!is_string($native) || isset($seen[$native])) continue;
            $seen[$native] = true;
            $index = null;
            foreach ($state['queue'] as $key => $queued) if (($queued['native_id'] ?? null) === $native) { $index = $key; break; }
            if ($index === null && count($state['queue']) + count($heads) >= self::MAX_QUEUE) {
                $result['head_deferred']++; continue;
            }
            $page = $this->comments->automationComments($actor, $accountKey, $native);
            $post = $index === null ? ['native_id' => $native, 'cursor' => $page['next_cursor']] : $state['queue'][$index];
            if ($index !== null) unset($state['queue'][$index]);
            $post['text'] = mb_substr((string) ($recent['text'] ?? ''), 0, 10000);
            if (($post['cursor'] ?? null) === null) $post['cursor'] = $page['next_cursor'];
            $pending = []; $order = [];
            foreach ($post['pending'] ?? [] as $row) {
                $id = $row['native_id'] ?? null;
                if (!is_string($id) || isset($pending[$id])) continue;
                $pending[$id] = $row; $order[] = $id;
            }
            $fresh = []; $freshIds = [];
            foreach ($page['comments'] as $row) {
                $id = $row['native_id'] ?? null;
                if (!is_string($id) || isset($freshIds[$id])) continue;
                if (!isset($pending[$id]) && count($pending) >= self::MAX_PENDING) {
                    $result['head_deferred']++; continue;
                }
                // Saved retry/backoff metadata wins over a fresh DTO for the same comment.
                $fresh[] = $pending[$id] ?? $row; $freshIds[$id] = true;
                if (!isset($pending[$id])) $pending[$id] = $row;
            }
            foreach ($order as $id) if (!isset($freshIds[$id])) $fresh[] = $pending[$id];
            $post['pending'] = $fresh;
            $heads[] = $post; $followups[$native] = true;
        }
        $state['queue'] = array_merge($heads, array_values($state['queue']));
        return $state;
    }

    private function restore(array $state, ?array $post, ?array $comment): array
    {
        if ($post === null) return $state;
        if ($comment !== null && isset($post['pending']) && !array_filter($post['pending'],
            static fn ($row) => ($row['native_id'] ?? null) === ($comment['native_id'] ?? null))) {
            array_unshift($post['pending'], $comment);
        }
        $state['queue'][] = $post;
        return $state;
    }

    private function load(string $accountKey, int $revision): array
    {
        $empty = ['v' => 1, 'revision' => $revision, 'phase' => 'recent', 'history_cursor' => null, 'queue' => [], 'turn' => 0,
            'recent_replies' => []];
        try {
            $row = DB::table('meta_comment_ai_poll_states')->where('account_key', $accountKey)->first();
            if (!$row || !is_string($row->state) || strlen($row->state) > 2097152) return $empty;
            $state = json_decode(Crypt::decryptString($row->state), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['v'] ?? null) !== 1 || ($state['revision'] ?? null) !== $revision
                || !in_array($state['phase'] ?? null, ['recent', 'history'], true)
                || !is_array($state['queue'] ?? null) || count($state['queue']) > self::MAX_QUEUE) return $empty;
            $state['recent_replies'] = $this->recentReplies($state['recent_replies'] ?? []);
            return $state + $empty;
        } catch (\Throwable $error) { return $empty; }
    }

    /** A bounded, encrypted per-account wording sample; old poll-state versions default to empty. */
    private function recentReplies($messages): array
    {
        if (!is_array($messages) || !array_is_list($messages)) return [];
        $recent = [];
        foreach (array_slice($messages, 0, self::RECENT_REPLIES) as $message) {
            if (!is_string($message) || !preg_match('//u', $message)) continue;
            // Public replies permit line breaks; style samples use one line so a valid
            // accepted multiline response cannot invalidate the next model request.
            $message = trim(preg_replace('/[ \t\r\n]+/u', ' ', $message));
            $checked = MetaCommentAiReplyPolicy::validateReply($message);
            if (($checked['ok'] ?? false) !== true || ($checked['kind'] ?? null) !== 'REPLY') continue;
            if (!in_array($checked['message'], $recent, true)) $recent[] = $checked['message'];
        }
        return $recent;
    }

    private function save(string $accountKey, array $state): void
    {
        try {
            // Never retain more than one bounded batch of posts and their bounded, encrypted pending rows.
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($json) > 1048576) throw new \RuntimeException();
            DB::table('meta_comment_ai_poll_states')->updateOrInsert(['account_key' => $accountKey],
                ['state' => Crypt::encryptString($json), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        } catch (\Throwable $error) { /* Durable target receipts still prevent any public resend. */ }
    }

    private function done(array $result, ?string $reason): array
    {
        $this->comments->setAutomationDeadline(null);
        $result['reason'] = $reason;
        $this->control->recordHeartbeat($reason);
        return $result;
    }

    private function reason($reason, string $fallback): string
    {
        return is_string($reason) && preg_match('/\A[A-Z][A-Z0-9_]{0,39}\z/', $reason) ? $reason : $fallback;
    }
}
