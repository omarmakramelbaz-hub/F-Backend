<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\MetaAiSchedule;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Fresh, separate authority for analysis, customer replies and order dispatch. */
class MetaAiControl
{
    private const CHANNELS = ['whatsapp', 'messenger', 'instagram'];
    private const MAX_REVISION = 9007199254740991;
    private static array $backlogCache = [];

    public static function defaults(): array
    {
        return ['enabled' => false, 'channels' => self::CHANNELS, 'schedule' => [
            'enabled' => false, 'days' => [0, 1, 2, 3, 4, 5, 6],
            'start' => '09:00', 'end' => '23:00', 'all_day' => true,
        ], 'dispatch_schedule' => MetaAiSchedule::defaultDispatchSchedule(),
            'mode' => MetaAiSchedule::REPLY_AND_ANALYZE];
    }

    /** Legacy fallback is allowed only before this schema is installed. */
    public function installed(): bool { return Schema::hasTable('meta_ai_controls'); }

    public function state($actor = null): array
    {
        if ($actor !== null) app(MetaInboxAccess::class)->actor($actor);
        $row = $this->read();
        if ($row === null) return ['available' => false, 'effective' => false,
            'status' => 'DISABLED', 'can_manage' => false, 'reason' => 'UNAVAILABLE'];
        $now = $this->now();
        $status = MetaAiSchedule::status($row['policy'], $now);
        $analysisOnly = $row['policy']['mode'] === MetaAiSchedule::ANALYZE_ONLY;
        $replyStatus = $analysisOnly && $row['policy']['enabled']
            ? ['effective' => false, 'status' => 'ANALYZE_ONLY', 'local_time' => $status['local_time']] : $status;
        if ($analysisOnly) $status = MetaAiSchedule::processingStatus($row['policy'], $now);
        $dispatch = MetaAiSchedule::dispatchStatus($row['policy'], $now);
        if ($dispatch['status'] === 'DISPATCH_OUTSIDE_HOURS') $dispatch['status'] = 'SCHEDULED_OFF';
        $channels = [];
        foreach ($row['policy']['channels'] as $channel) {
            $gate = $analysisOnly ? $this->processingGate($channel) : $this->gate($channel);
            $sameRevision = $gate['revision'] === $row['revision'];
            $channels[] = ['channel' => $channel, 'effective' => $sameRevision && $gate['effective'],
                'reason' => $sameRevision ? $gate['reason'] : 'STATE_CHANGED'];
        }
        $handoffValid = MetaBusinessAgent::validReceipt($row['handoff'], $row['policy']['channels'])
            && ($row['handoff']['actor_id'] ?? null) === $row['actor_id'];
        return ['available' => true, 'policy' => $row['policy'], 'revision' => $row['revision'],
            'effective' => $status['effective'], 'status' => $status['status'],
            'local_time' => $status['local_time'],
            'reply_state' => $replyStatus,
            'dispatch_state' => $dispatch,
            'channels_state' => $channels,
            'handoff_method' => !$analysisOnly && $handoffValid ? $row['handoff']['method'] : null,
            'handoff_required' => !$analysisOnly && $row['policy']['enabled'] && !$handoffValid,
            'can_manage' => $actor !== null && app(MetaInboxAccess::class)->canConnect($actor),
            'updated_at_utc' => $row['updated_at_utc']];
    }

    /** No config cache, credentials or external APIs are changed by this operation. */
    public function update($actor, array $policy, int $expectedRevision, bool $externalAgentPaused = false): array
    {
        $owner = app(MetaInboxAccess::class)->owner($actor);
        if (config('desktop_dashboard.local', false)) throw new RuntimeException('UNAVAILABLE');
        $before = $this->read();
        if ($before === null) throw new RuntimeException('UNAVAILABLE');
        // Old browser clients can save reply settings without resetting a newer
        // independently configured dispatch schedule to its default.
        if (!array_key_exists('dispatch_schedule', $policy))
            $policy['dispatch_schedule'] = $before['policy']['dispatch_schedule'];
        if (!array_key_exists('mode', $policy)) $policy['mode'] = $before['policy']['mode'];
        $policy = MetaAiSchedule::validate($policy);
        if ($policy === null || $expectedRevision < 0 || $expectedRevision >= self::MAX_REVISION)
            throw new RuntimeException('INVALID_POLICY');
        if (!$this->available()) throw new RuntimeException('UNAVAILABLE');

        if ($before['revision'] !== $expectedRevision) throw new RuntimeException('REVISION_CONFLICT');
        $analysisOnly = $policy['mode'] === MetaAiSchedule::ANALYZE_ONLY;
        // An old pause attestation cannot survive an interval in which the external
        // agent is allowed to respond. Returning to full mode requires a new handoff.
        $handoff = $analysisOnly ? null : $before['handoff'];
        if (!$analysisOnly && $policy['enabled'] && (!$before['policy']['enabled']
            || !MetaBusinessAgent::validReceipt($handoff, $policy['channels'])
            || ($handoff['actor_id'] ?? null) !== (int)$owner->getKey())) {
            // The external agent is paused or explicitly attested before automation is enabled.
            // A second revision check under lock prevents overwriting concurrent owner edits.
            $handoff = app(MetaBusinessAgent::class)->prepare($owner, $policy['channels'], $externalAgentPaused);
            if (!MetaBusinessAgent::validReceipt($handoff, $policy['channels'])
                || ($handoff['actor_id'] ?? null) !== (int)$owner->getKey())
                throw new RuntimeException('EXTERNAL_AGENT_PAUSE_REQUIRED');
        }

        DB::transaction(function () use ($actor, $policy, $expectedRevision, $handoff) {
            // Recheck persisted ownership after obtaining the policy lock.
            $owner = app(MetaInboxAccess::class)->owner($actor);
            $stored = DB::table('meta_ai_controls')->where('id', 1)->lockForUpdate()->first();
            $row = $this->decode($stored);
            if ($row === null) throw new RuntimeException('UNAVAILABLE');
            if ($row['revision'] !== $expectedRevision) throw new RuntimeException('REVISION_CONFLICT');
            if ($policy['enabled'] && $policy['mode'] === MetaAiSchedule::ANALYZE_ONLY
                && (!$row['policy']['enabled'] || $row['policy']['mode'] !== MetaAiSchedule::ANALYZE_ONLY)
                && $this->unsettledReplies(array_values(array_unique(array_merge($row['policy']['channels'], $policy['channels'])))))
                throw new RuntimeException('REPLIES_PENDING');
            $activations = $row['activations'];
            $now = $this->now()->format('Y-m-d H:i:s');
            if ($policy['enabled']) {
                foreach ($policy['channels'] as $channel) {
                    $scope = $this->scope($channel);
                    if (!$row['policy']['enabled'] || !in_array($channel, $row['policy']['channels'], true)
                        || !isset($activations[$channel]) || $activations[$channel]['scope'] !== $scope) {
                        $activations[$channel] = $this->capture($channel, $scope, $now);
                    }
                }
            }
            $updated = DB::table('meta_ai_controls')->where('id', 1)
                ->where('revision', $expectedRevision)->update([
                    'policy' => json_encode($policy, JSON_THROW_ON_ERROR),
                    'activations' => json_encode($activations, JSON_THROW_ON_ERROR),
                    'handoff' => $handoff === null ? null : json_encode($handoff, JSON_THROW_ON_ERROR),
                    'revision' => $expectedRevision + 1, 'actor_id' => (int)$owner->getKey(),
                    'updated_by' => (int)$owner->getKey(), 'updated_at' => $now,
                ]);
            if ($updated !== 1) throw new RuntimeException('REVISION_CONFLICT');
        });
        return $this->state($owner);
    }

    /**
     * Null inbound bounds are a pre-loop check. Actual work supplies both native
     * message id and UTC time. Off-hours messages can wait for the next active window.
     * Platform windows, human handoff and native authentication remain caller guards.
     */
    public function gate(string $channel, ?string $inboundTime = null, ?int $inboundId = null, ?int $eventId = null): array
    {
        return $this->operationGate($channel, $inboundTime, $inboundId, $eventId, 'reply');
    }

    /** Scanning can serve a reply turn or a separately scheduled approved order. */
    public function processingGate(string $channel, ?string $inboundTime = null, ?int $inboundId = null, ?int $eventId = null): array
    {
        return $this->operationGate($channel, $inboundTime, $inboundId, $eventId, 'processing');
    }

    /** Configured mode only. Callers must still obtain a fresh processing gate. */
    public function analysisOnly(string $channel): bool
    {
        $row = $this->read();
        return $row !== null && in_array($channel, $row['policy']['channels'], true)
            && $row['policy']['mode'] === MetaAiSchedule::ANALYZE_ONLY;
    }

    private function operationGate(string $channel, ?string $inboundTime, ?int $inboundId, ?int $eventId, string $purpose): array
    {
        $result = ['effective' => false, 'reason' => 'UNAVAILABLE', 'revision' => null,
            'actor_id' => null, 'actor' => null, 'floor' => null, 'mode' => null];
        try {
            if (!in_array($channel, self::CHANNELS, true)) return array_replace($result, ['reason' => 'INVALID_CHANNEL']);
            $row = $this->read();
            if ($row === null) return $result;
            $result['revision'] = $row['revision'];
            $result['mode'] = $row['policy']['mode'];
            $analysisOnly = $row['policy']['mode'] === MetaAiSchedule::ANALYZE_ONLY;
            $now = $this->now();
            $status = MetaAiSchedule::status($row['policy'], $now);
            if ($purpose === 'processing') $status = MetaAiSchedule::processingStatus($row['policy'], $now);
            elseif ($purpose === 'dispatch') $status = MetaAiSchedule::dispatchStatus($row['policy'], $now);
            if ($purpose === 'reply' && $analysisOnly && $row['policy']['enabled'])
                return array_replace($result, ['reason' => 'ANALYZE_ONLY']);
            if (!$status['effective']) return array_replace($result, ['reason' => $status['status']]);
            if (!in_array($channel, $row['policy']['channels'], true))
                return array_replace($result, ['reason' => 'CHANNEL_DISABLED']);
            if (!$analysisOnly && (!MetaBusinessAgent::validReceipt($row['handoff'], $row['policy']['channels'])
                || ($row['handoff']['actor_id'] ?? null) !== $row['actor_id']))
                return array_replace($result, ['reason' => 'EXTERNAL_AGENT_PAUSE_REQUIRED']);
            $floor = $row['activations'][$channel] ?? null;
            if ($floor === null || $floor['scope'] !== $this->scope($channel)
                || $this->utc($floor['started_at_utc']) > $now)
                return array_replace($result, ['reason' => 'ACTIVATION_REQUIRED']);
            if ($channel !== 'whatsapp' && $this->accounts($channel) === [])
                return array_replace($result, ['reason' => 'ACCOUNT_UNAVAILABLE']);
            if (!$this->captureAvailable($channel))
                return array_replace($result, ['reason' => 'CAPTURE_UNAVAILABLE']);
            if ($row['actor_id'] < 1) return array_replace($result, ['reason' => 'ACTOR_UNAVAILABLE']);
            $identity = new User;
            $identity->id = $row['actor_id'];
            try { $owner = app(MetaInboxAccess::class)->owner($identity); }
            catch (\Throwable $error) { return array_replace($result, ['reason' => 'ACTOR_UNAVAILABLE']); }
            // Activation identity and raw capture watermark stay intact. Only native
            // unanswered work from its bounded preceding platform window is admitted.
            $effectiveFloor = $this->effectiveFloor($channel, $floor, $row['revision']);
            $result['floor'] = $effectiveFloor;
            if ($inboundTime !== null || $inboundId !== null || $eventId !== null) {
                $inbound = $this->utc($inboundTime);
                $started = $this->utc($effectiveFloor['started_at_utc']);
                $backlog = $inbound !== null && $inbound <= $this->utc($floor['started_at_utc']);
                if ($inbound === null || $inboundId === null || $inboundId <= $effectiveFloor['message_floor']
                    || $inbound < $started || $inbound > $now
                    || ($eventId !== null && $eventId < 1)
                    || (!$backlog && $eventId !== null && $eventId <= $floor['event_floor']))
                    return array_replace($result, ['reason' => 'BEFORE_ACTIVATION']);
            }
            return array_replace($result, ['effective' => true, 'reason' => null,
                'actor_id' => (int)$owner->getKey(), 'actor' => $owner, 'backlog_version' => 1,
                'original_activation_started_at_utc' => $floor['started_at_utc']]);
        } catch (\Throwable $error) { return $result; }
    }

    /** Re-read master, schedule, owner, account and handoff at each ERP boundary. */
    public function dispatchGate(string $channel): array
    {
        return $this->operationGate($channel, null, null, null, 'dispatch');
    }

    /**
     * The policy row is locked before this check. Automatic reply claims obtain
     * that same current-read lock, so a claim either settles before the switch or
     * observes ANALYZE_ONLY. Uncertain delivery is never rewritten or replayed.
     */
    private function unsettledReplies(array $channels): bool
    {
        if (in_array('whatsapp', $channels, true) && Schema::hasTable('whatsapp_reply_requests')) {
            $pending = DB::table('whatsapp_reply_requests')->where('waba_id', WhatsAppInboxAccess::WABA_ID)
                ->where('phone_number_id', WhatsAppInboxAccess::PHONE_ID)->whereIn('state', ['CLAIMED', 'UNKNOWN'])
                ->lockForUpdate()->first(['id']);
            if ($pending !== null) return true;
        }
        $meta = array_values(array_intersect(['messenger', 'instagram'], $channels));
        if ($meta !== [] && Schema::hasTable('meta_reply_requests')) {
            $pending = MetaInboxAccess::scope(DB::table('meta_reply_requests'), '')
                ->whereIn('waba_id', $meta)->whereIn('state', ['CLAIMED', 'UNKNOWN'])->lockForUpdate()->first(['id']);
            if ($pending !== null) return true;
        }
        return false;
    }

    protected function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }

    private function available(): bool
    {
        return !config('desktop_dashboard.local', false) && Schema::hasTable('meta_ai_controls');
    }

    private function read(): ?array
    {
        try {
            if (!$this->available()) return null;
            $query = DB::table('meta_ai_controls')->where('id', 1);
            // MySQL's repeatable-read snapshot must not hide a later OFF/revision.
            // A current read also serializes the last in-transaction ERP guard with
            // owner updates: OFF is acknowledged only after authorized work commits.
            if (DB::connection()->transactionLevel() > 0) $query->lockForUpdate();
            return $this->decode($query->first());
        }
        catch (\Throwable $error) { return null; }
    }

    private function decode($stored): ?array
    {
        if (!$stored || !is_string($stored->policy ?? null) || strlen($stored->policy) > 4096
            || !is_string($stored->activations ?? null) || strlen($stored->activations) > 4096) return null;
        try {
            $policy = json_decode($stored->policy, true, 8, JSON_THROW_ON_ERROR);
            $activations = json_decode($stored->activations, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) { return null; }
        $policy = is_array($policy) ? MetaAiSchedule::validate($policy) : null;
        $handoff = null;
        if (($stored->handoff ?? null) !== null) {
            if (!is_string($stored->handoff) || strlen($stored->handoff) > 4096) return null;
            try { $handoff = json_decode($stored->handoff, true, 8, JSON_THROW_ON_ERROR); }
            catch (\Throwable $error) { return null; }
            if (!is_array($handoff)) return null;
        }
        $revision = filter_var($stored->revision ?? null, FILTER_VALIDATE_INT);
        $actorId = filter_var($stored->actor_id ?? 0, FILTER_VALIDATE_INT);
        if ($policy === null || $revision === false || $revision < 0 || $revision > self::MAX_REVISION
            || $actorId === false || $actorId < 0 || !is_array($activations) || count($activations) > 3
            || $this->utc($stored->updated_at ?? null) === null) return null;
        foreach ($activations as $channel => $floor) {
            if (!in_array($channel, self::CHANNELS, true) || !is_array($floor)
                || !in_array(count($floor), [4, 5], true)
                || array_diff(array_keys($floor), ['message_floor', 'event_floor', 'started_at_utc', 'scope', 'backlog']) !== []
                || !is_int($floor['message_floor'] ?? null) || $floor['message_floor'] < 0
                || !is_int($floor['event_floor'] ?? null) || $floor['event_floor'] < 0
                || $this->utc($floor['started_at_utc'] ?? null) === null
                || !is_string($floor['scope'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $floor['scope'])) return null;
            if (array_key_exists('backlog', $floor)) {
                $backlog = $floor['backlog'];
                if (!is_array($backlog) || count($backlog) !== 3
                    || array_diff(array_keys($backlog), ['version', 'message_floor', 'started_at_utc']) !== []
                    || ($backlog['version'] ?? null) !== 1 || !is_int($backlog['message_floor'] ?? null)
                    || $backlog['message_floor'] < 0 || $backlog['message_floor'] > $floor['message_floor']
                    || $this->utc($backlog['started_at_utc'] ?? null) === null
                    || $backlog['started_at_utc'] !== $this->backlogStart($floor['started_at_utc'])) return null;
            }
        }
        return ['policy' => $policy, 'activations' => $activations, 'revision' => $revision,
            'actor_id' => $actorId, 'handoff' => $handoff, 'updated_at_utc' => $stored->updated_at];
    }

    private function utc($value): ?DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $format = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value)
            ? 'Y-m-d H:i:s' : (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) ? 'Y-m-d\TH:i:s\Z' : null);
        if ($format === null) return null;
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format($format) === $value ? $date : null;
    }

    private function accounts(string $channel): array
    {
        $ids = [];
        foreach (MetaInboxAccess::accounts() as $account) if ($account['channel'] === $channel) $ids[] = $account['account_id'];
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); return $ids;
    }

    private function scope(string $channel): string
    {
        return hash('sha256', $channel.'|'.implode('|', $channel === 'whatsapp'
            ? [WhatsAppInboxAccess::WABA_ID, WhatsAppInboxAccess::PHONE_ID] : $this->accounts($channel)));
    }

    private function captureAvailable(string $channel): bool
    {
        $prefix = $channel === 'whatsapp' ? 'whatsapp' : 'meta';
        foreach ([$prefix.'_inbox_conversations', $prefix.'_inbox_messages', $prefix.'_webhook_events'] as $table)
            if (!Schema::hasTable($table)) return false;
        return true;
    }

    private function capture(string $channel, string $scope, string $now): array
    {
        if (!$this->captureAvailable($channel)) throw new RuntimeException('CAPTURE_UNAVAILABLE');
        $prefix = $channel === 'whatsapp' ? 'whatsapp' : 'meta';
        $query = DB::table($prefix.'_inbox_messages as m')
            ->join($prefix.'_inbox_conversations as c', 'c.id', '=', 'm.conversation_id');
        if ($channel === 'whatsapp') $query->where('c.waba_id', WhatsAppInboxAccess::WABA_ID)
            ->where('c.phone_number_id', WhatsAppInboxAccess::PHONE_ID);
        else MetaInboxAccess::scope($query, 'c.')->where('c.waba_id', $channel);
        $floor = ['message_floor' => (int)($query->max('m.id') ?? 0),
            'event_floor' => (int)(DB::table($prefix.'_webhook_events')->max('id') ?? 0),
            'started_at_utc' => $now, 'scope' => $scope];
        $floor['backlog'] = $this->captureBacklog($channel, $floor);
        return $floor;
    }

    private function backlogStart(string $started): string
    {
        return $this->utc($started)->modify('-24 hours')->format('Y-m-d H:i:s');
    }

    private function captureBacklog(string $channel, array $floor): array
    {
        $started = $this->backlogStart($floor['started_at_utc']);
        $prefix = $channel === 'whatsapp' ? 'whatsapp' : 'meta';
        $query = DB::table($prefix.'_inbox_messages as m')
            ->join($prefix.'_inbox_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.direction', 'inbound')->whereIn('m.source', ['messages', 'standby'])
            ->where('m.sent_at', '>=', $started)->where('m.sent_at', '<=', $floor['started_at_utc'])
            ->where('m.id', '<=', $floor['message_floor']);
        if ($channel === 'whatsapp') $query->where('c.waba_id', WhatsAppInboxAccess::WABA_ID)
            ->where('c.phone_number_id', WhatsAppInboxAccess::PHONE_ID);
        else MetaInboxAccess::scope($query, 'c.')->where('c.waba_id', $channel);
        $first = $query->min('m.id');
        return ['version' => 1, 'message_floor' => $first === null ? $floor['message_floor'] : max(0, (int)$first - 1),
            'started_at_utc' => $started];
    }

    private function effectiveFloor(string $channel, array $floor, int $revision): array
    {
        // Old installed activations receive the same bounded semantics without
        // reactivation, writes or moving their original time/message/event bounds.
        $connection = DB::connection();
        $key = hash('sha256', json_encode([spl_object_id($connection), $connection->getDatabaseName(),
            $channel, $revision, $floor], JSON_THROW_ON_ERROR));
        if (!isset(self::$backlogCache[$key])) {
            if (count(self::$backlogCache) >= 12) self::$backlogCache = [];
            self::$backlogCache[$key] = $floor['backlog'] ?? $this->captureBacklog($channel, $floor);
        }
        $backlog = self::$backlogCache[$key];
        return ['message_floor' => $backlog['message_floor'], 'event_floor' => $floor['event_floor'],
            'started_at_utc' => $backlog['started_at_utc']];
    }
}
