<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\MetaAiSchedule;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** One fresh database policy controls both automatic analysis and automatic replies. */
class MetaAiControl
{
    private const CHANNELS = ['whatsapp', 'messenger', 'instagram'];
    private const MAX_REVISION = 9007199254740991;

    public static function defaults(): array
    {
        return ['enabled' => false, 'channels' => self::CHANNELS, 'schedule' => [
            'enabled' => false, 'days' => [0, 1, 2, 3, 4, 5, 6],
            'start' => '09:00', 'end' => '23:00', 'all_day' => true,
        ]];
    }

    /** Legacy fallback is allowed only before this schema is installed. */
    public function installed(): bool { return Schema::hasTable('meta_ai_controls'); }

    public function state($actor = null): array
    {
        if ($actor !== null) app(MetaInboxAccess::class)->actor($actor);
        $row = $this->read();
        if ($row === null) return ['available' => false, 'effective' => false,
            'status' => 'DISABLED', 'can_manage' => false, 'reason' => 'UNAVAILABLE'];
        $status = MetaAiSchedule::status($row['policy'], $this->now());
        $channels = [];
        foreach ($row['policy']['channels'] as $channel) {
            $gate = $this->gate($channel);
            $sameRevision = $gate['revision'] === $row['revision'];
            $channels[] = ['channel' => $channel, 'effective' => $sameRevision && $gate['effective'],
                'reason' => $sameRevision ? $gate['reason'] : 'STATE_CHANGED'];
        }
        $handoffValid = MetaBusinessAgent::validReceipt($row['handoff'], $row['policy']['channels'])
            && ($row['handoff']['actor_id'] ?? null) === $row['actor_id'];
        return ['available' => true, 'policy' => $row['policy'], 'revision' => $row['revision'],
            'effective' => $status['effective'], 'status' => $status['status'],
            'local_time' => $status['local_time'],
            'channels_state' => $channels,
            'handoff_method' => $handoffValid ? $row['handoff']['method'] : null,
            'handoff_required' => $row['policy']['enabled'] && !$handoffValid,
            'can_manage' => $actor !== null && app(MetaInboxAccess::class)->canConnect($actor),
            'updated_at_utc' => $row['updated_at_utc']];
    }

    /** No config cache, credentials or external APIs are changed by this operation. */
    public function update($actor, array $policy, int $expectedRevision, bool $externalAgentPaused = false): array
    {
        $owner = app(MetaInboxAccess::class)->owner($actor);
        if (config('desktop_dashboard.local', false)) throw new RuntimeException('UNAVAILABLE');
        $policy = MetaAiSchedule::validate($policy);
        if ($policy === null || $expectedRevision < 0 || $expectedRevision >= self::MAX_REVISION)
            throw new RuntimeException('INVALID_POLICY');
        if (!$this->available()) throw new RuntimeException('UNAVAILABLE');

        $before = $this->read();
        if ($before === null) throw new RuntimeException('UNAVAILABLE');
        if ($before['revision'] !== $expectedRevision) throw new RuntimeException('REVISION_CONFLICT');
        $handoff = $before['handoff'];
        if ($policy['enabled'] && (!$before['policy']['enabled']
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
        $result = ['effective' => false, 'reason' => 'UNAVAILABLE', 'revision' => null,
            'actor_id' => null, 'actor' => null, 'floor' => null];
        try {
            if (!in_array($channel, self::CHANNELS, true)) return array_replace($result, ['reason' => 'INVALID_CHANNEL']);
            $row = $this->read();
            if ($row === null) return $result;
            $result['revision'] = $row['revision'];
            $now = $this->now();
            $status = MetaAiSchedule::status($row['policy'], $now);
            if (!$status['effective']) return array_replace($result, ['reason' => $status['status']]);
            if (!in_array($channel, $row['policy']['channels'], true))
                return array_replace($result, ['reason' => 'CHANNEL_DISABLED']);
            if (!MetaBusinessAgent::validReceipt($row['handoff'], $row['policy']['channels'])
                || ($row['handoff']['actor_id'] ?? null) !== $row['actor_id'])
                return array_replace($result, ['reason' => 'EXTERNAL_AGENT_PAUSE_REQUIRED']);
            $floor = $row['activations'][$channel] ?? null;
            if ($floor === null || $floor['scope'] !== $this->scope($channel)
                || $this->utc($floor['started_at_utc']) > $now)
                return array_replace($result, ['reason' => 'ACTIVATION_REQUIRED']);
            $result['floor'] = array_diff_key($floor, ['scope' => true]);
            if ($channel !== 'whatsapp' && $this->accounts($channel) === [])
                return array_replace($result, ['reason' => 'ACCOUNT_UNAVAILABLE']);
            if (!$this->captureAvailable($channel))
                return array_replace($result, ['reason' => 'CAPTURE_UNAVAILABLE']);
            if ($row['actor_id'] < 1) return array_replace($result, ['reason' => 'ACTOR_UNAVAILABLE']);
            $identity = new User;
            $identity->id = $row['actor_id'];
            try { $owner = app(MetaInboxAccess::class)->owner($identity); }
            catch (\Throwable $error) { return array_replace($result, ['reason' => 'ACTOR_UNAVAILABLE']); }
            if ($inboundTime !== null || $inboundId !== null || $eventId !== null) {
                $inbound = $this->utc($inboundTime);
                $started = $this->utc($floor['started_at_utc']);
                if ($inbound === null || $inboundId === null || $inboundId <= $floor['message_floor']
                    || $inbound < $started || $inbound > $now
                    || ($eventId !== null && $eventId <= $floor['event_floor']))
                    return array_replace($result, ['reason' => 'BEFORE_ACTIVATION']);
            }
            return array_replace($result, ['effective' => true, 'reason' => null,
                'actor_id' => (int)$owner->getKey(), 'actor' => $owner]);
        } catch (\Throwable $error) { return $result; }
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
            if (!in_array($channel, self::CHANNELS, true) || !is_array($floor) || count($floor) !== 4
                || array_diff(array_keys($floor), ['message_floor', 'event_floor', 'started_at_utc', 'scope']) !== []
                || !is_int($floor['message_floor'] ?? null) || $floor['message_floor'] < 0
                || !is_int($floor['event_floor'] ?? null) || $floor['event_floor'] < 0
                || $this->utc($floor['started_at_utc'] ?? null) === null
                || !is_string($floor['scope'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $floor['scope'])) return null;
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
        return ['message_floor' => (int)($query->max('m.id') ?? 0),
            'event_floor' => (int)(DB::table($prefix.'_webhook_events')->max('id') ?? 0),
            'started_at_utc' => $now, 'scope' => $scope];
    }
}
