<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Durable, recipient-scoped manual broadcasts. Never retry an ambiguous send. */
class DashboardPushCampaigns
{
    public function ready(): bool { return Schema::hasTable('dashboard_push_campaigns') && Schema::hasTable('dashboard_push_devices'); }
    private function scope(User $actor): string { return hash('sha256', $actor->account_type.'|'.$actor->added_by); }

    public function create(User $actor, array $data, array $tokens, ?array $audience = null): array
    {
        abort_unless($this->ready(), 503, trans('dashboard_push.upgrade'));
        $key = $data['request_key']; unset($data['request_key'], $data['durable']);
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
        $id = DB::transaction(function () use ($actor, $data, $tokens, $key, $hash, $audience) {
            // Serializes duplicate browser submissions before any provider calls.
            User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('dashboard_push_campaigns')->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($existing) { abort_unless(hash_equals($existing->request_hash, $hash), 409, trans('dashboard_push.changed')); return $existing->id; }
            $valid = []; $invalid = 0;
            foreach ($tokens as $token) {
                if (!DashboardPushSender::validToken($token)) { $invalid++; continue; }
                $valid[hash('sha256', $token)] = $token;
            }
            $values = ['actor_id' => $actor->id, 'request_key' => $key, 'request_hash' => $hash,
                'actor_scope' => $this->scope($actor), 'account_type' => $data['account_type'] ?? 'user', 'title' => $data['title'], 'body' => $data['body'],
                'invalid' => $invalid, 'status' => $valid ? 'queued' : 'finished', 'created_at' => now(), 'updated_at' => now()];
            if (Schema::hasColumn('dashboard_push_campaigns', 'audience')) $values['audience'] = $audience === null ? null : json_encode($audience);
            $id = DB::table('dashboard_push_campaigns')->insertGetId($values);
            foreach (array_chunk($valid, 100, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $tokenHash => $token) $rows[] = ['campaign_id' => $id, 'token_hash' => $tokenHash, 'token' => Crypt::encryptString($token), 'status' => 'pending'];
                DB::table('dashboard_push_devices')->insert($rows);
            }
            return $id;
        }, 3);
        return $this->status($id, $actor);
    }

    public function status(int $id, User $actor): array
    {
        $campaign = DB::table('dashboard_push_campaigns')->where('id', $id)->where('actor_id', $actor->id)->first();
        abort_unless($campaign, 404);
        $counts = DB::table('dashboard_push_devices')->where('campaign_id', $id)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $reasons = DB::table('dashboard_push_devices')->where('campaign_id', $id)->whereNotNull('reason')->select('reason', DB::raw('count(*) as total'))->groupBy('reason')->pluck('total', 'reason')->map(fn ($n) => (int) $n)->all();
        $result = ['id' => $id, 'status' => $campaign->status, 'reason' => $campaign->reason, 'accepted' => (int) ($counts['accepted'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0), 'uncertain' => (int) ($counts['uncertain'] ?? 0), 'invalid' => (int) $campaign->invalid,
            'not_sent' => (int) ($counts['pending'] ?? 0) + (int) ($counts['blocked'] ?? 0), 'sending' => (int) ($counts['sending'] ?? 0), 'reasons' => $reasons];
        $result['message'] = trans('dashboard_push.progress', array_filter($result, 'is_scalar'));
        $result['audience'] = isset($campaign->audience) ? json_decode($campaign->audience, true) : null;
        $result['audience_message'] = $result['audience'] ? trans('dashboard_push.audience_summary', $result['audience']) : trans('dashboard_push.audience_legacy');
        $result['diagnostic'] = $campaign->reason ? trans('dashboard_push.reason_'.$campaign->reason) : '';
        $result['issues'] = [];
        foreach ($reasons as $reason => $count) $result['issues'][] = trans('dashboard_push.reason_'.$reason).' ('.$count.')';
        return $result;
    }

    public function resume(int $id, User $actor): array
    {
        DB::transaction(function () use ($id, $actor) {
            $c = DB::table('dashboard_push_campaigns')->where('id', $id)->where('actor_id', $actor->id)->lockForUpdate()->first();
            abort_unless($c, 404);
            // Only definite global refusals/unattempted records are resumable.
            if ($c->status === 'paused') {
                abort_unless(hash_equals($c->actor_scope, $this->scope($actor)), 403);
                DB::table('dashboard_push_devices')->where('campaign_id', $id)->where('status', 'blocked')->update(['status' => 'pending', 'reason' => null]);
                DB::table('dashboard_push_campaigns')->where('id', $id)->update(['status' => 'queued', 'reason' => null, 'updated_at' => now()]);
            }
        }, 3);
        return $this->status($id, $actor);
    }

    public function step(int $id): void
    {
        $work = DB::transaction(function () use ($id) {
            $c = DB::table('dashboard_push_campaigns')->where('id', $id)->lockForUpdate()->first();
            if (!$c || !in_array($c->status, ['queued', 'sending'], true)) return null;
            if ($c->claim && $c->claimed_at > now()->subMinutes(2)->toDateTimeString()) return null;
            if ($c->claim) {
                // A crashed worker could have reached FCM: never resend these devices automatically.
                DB::table('dashboard_push_devices')->where('campaign_id', $id)->where('status', 'sending')
                    ->update(['status' => 'uncertain', 'reason' => 'interrupted', 'token' => null]);
            }
            $actor = User::withoutGlobalScopes()->find($c->actor_id);
            if (!$actor || !$actor->can('fcm_notification-create') || !hash_equals($c->actor_scope, $this->scope($actor))) {
                DB::table('dashboard_push_campaigns')->where('id', $id)->update(['status' => 'paused', 'reason' => 'authorization', 'claim' => null, 'claimed_at' => null, 'updated_at' => now()]);
                return null;
            }
            $devices = DB::table('dashboard_push_devices')->where('campaign_id', $id)->where('status', 'pending')->orderBy('id')->limit(DashboardPushSender::BATCH_SIZE)->get();
            if ($devices->isEmpty()) {
                DB::table('dashboard_push_campaigns')->where('id', $id)->update(['status' => 'finished', 'claim' => null, 'claimed_at' => null, 'updated_at' => now()]);
                return null;
            }
            $claim = (string) Str::uuid();
            DB::table('dashboard_push_devices')->whereIn('id', $devices->pluck('id'))->update(['status' => 'sending']);
            DB::table('dashboard_push_campaigns')->where('id', $id)->update(['status' => 'sending', 'claim' => $claim, 'claimed_at' => now(), 'updated_at' => now()]);
            return compact('c', 'devices', 'claim');
        }, 3);
        if (!$work) return;
        try {
            $tokens = $work['devices']->map(fn ($d) => Crypt::decryptString($d->token))->all();
        } catch (\Throwable $e) {
            $this->finish($work, ['outcomes' => [], 'reason' => 'configuration']); return;
        }
        try { $result = app(DashboardPushSender::class)->send($tokens, $work['c']->title, $work['c']->body, $work['c']->account_type); }
        catch (\Throwable $e) {
            $result = ['reason' => null, 'outcomes' => []];
            foreach ($work['devices'] as $d) $result['outcomes'][$d->token_hash] = ['status' => 'uncertain', 'reason' => 'interrupted'];
        }
        $this->finish($work, $result);
    }

    private function finish(array $work, array $result): void
    {
        DB::transaction(function () use ($work, $result) {
            $id = $work['c']->id;
            $c = DB::table('dashboard_push_campaigns')->where('id', $id)->lockForUpdate()->first();
            if (!$c || $c->claim !== $work['claim']) return;
            $pause = in_array($result['reason'], ['authentication', 'configuration', 'permission', 'payload_too_large'], true);
            $groups = [];
            foreach ($work['devices'] as $d) {
                $outcome = $result['outcomes'][$d->token_hash] ?? ['status' => 'pending', 'reason' => null];
                $values = $outcome;
                if (in_array($values['status'], ['accepted', 'failed', 'uncertain'], true)) $values['token'] = null;
                $group = json_encode($values);
                if (!isset($groups[$group])) $groups[$group] = ['values' => $values, 'ids' => []];
                $groups[$group]['ids'][] = $d->id;
            }
            foreach ($groups as $group) DB::table('dashboard_push_devices')->where('campaign_id', $id)->whereIn('id', $group['ids'])->update($group['values']);
            $pending = DB::table('dashboard_push_devices')->where('campaign_id', $id)->whereIn('status', ['pending', 'blocked'])->exists();
            DB::table('dashboard_push_campaigns')->where('id', $id)->update(['status' => $pause ? 'paused' : ($pending ? 'queued' : 'finished'),
                'reason' => $pause ? $result['reason'] : null, 'claim' => null, 'claimed_at' => null, 'updated_at' => now()]);
        }, 3);
    }
}
