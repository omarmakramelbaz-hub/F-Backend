<?php

namespace App\Services\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Independent central policy: installation and credential reuse never enable public replies. */
class MetaCommentAiControl
{
    private const TABLE = 'meta_comment_ai_settings';
    private const ZONE = 'Africa/Cairo';

    public function ready(): bool
    {
        if (config('desktop_dashboard.local', false)) return false;
        try {
            foreach ([self::TABLE, 'meta_comment_ai_targets', 'meta_comment_ai_poll_states', 'meta_comment_reply_requests'] as $table) {
                if (!Schema::hasTable($table)) return false;
            }
            return true;
        } catch (\Throwable $error) { return false; }
    }

    public function snapshot(): ?array
    {
        if (!$this->ready()) return null;
        try {
            $row = DB::table(self::TABLE)->where('id', 1)->first();
            if (!$row) return null;
            $days = json_decode((string) $row->days, true, 4, JSON_THROW_ON_ERROR);
            if (!$this->validDays($days) || !in_array($row->mode, ['always', 'scheduled'], true)
                || !$this->clock($row->start) || !$this->clock($row->end) || $row->start === $row->end
                || (int) $row->revision < 0) return null;
            return ['enabled' => (bool) $row->enabled, 'mode' => $row->mode,
                'days' => array_values($days), 'start' => $row->start, 'end' => $row->end,
                'timezone' => self::ZONE, 'revision' => (int) $row->revision,
                'enabled_at' => $this->iso($row->enabled_at), 'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'worker_seen_at' => $this->iso($row->worker_seen_at),
                'last_reason' => $this->reason($row->last_reason), 'updated_at' => $this->iso($row->updated_at)];
        } catch (\Throwable $error) { return null; }
    }

    public function status($actor): array
    {
        if (config('desktop_dashboard.local', false)) throw new MetaCommentsFailure('CENTRAL_ONLY', 403);
        $access = app(MetaInboxAccess::class);
        $actor = $access->actor($actor);
        $snapshot = $this->snapshot();
        if ($snapshot === null) throw new MetaCommentsFailure('AI_UNAVAILABLE', 503);
        $control = $snapshot;
        unset($control['actor_id']);
        $control['active_now'] = $this->activeAt($snapshot, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $control['configured'] = app(MetaCommentAiProvider::class)->ready();
        return ['success' => true, 'control' => $control, 'can_manage' => $access->canConnect($actor)];
    }

    public function update($actor, array $input): array
    {
        if (config('desktop_dashboard.local', false)) throw new MetaCommentsFailure('CENTRAL_ONLY', 403);
        $actor = app(MetaInboxAccess::class)->owner($actor);
        $allowed = ['enabled', 'mode', 'days', 'start', 'end', 'expected_revision'];
        if (array_diff(array_keys($input), $allowed) || array_diff($allowed, array_keys($input))
            || !is_bool($input['enabled']) || !in_array($input['mode'], ['always', 'scheduled'], true)
            || !$this->validDays($input['days']) || !$this->clock($input['start']) || !$this->clock($input['end'])
            || $input['start'] === $input['end'] || !is_int($input['expected_revision']) || $input['expected_revision'] < 0) {
            throw new MetaCommentsFailure('INVALID_REQUEST', 422);
        }
        if (!$this->ready()) throw new MetaCommentsFailure('AI_UNAVAILABLE', 503);
        if ($input['enabled'] && !app(MetaCommentAiProvider::class)->ready()) {
            throw new MetaCommentsFailure('AI_NOT_CONFIGURED', 422);
        }
        $result = app(MetaCommentAiDispatchLock::class)->run(function () use ($actor, $input) {
            // A stop may wait for an in-flight send; recheck persisted ownership after that wait.
            $actor = app(MetaInboxAccess::class)->owner($actor);
            return DB::transaction(function () use ($actor, $input) {
                $row = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
                if (!$row) throw new MetaCommentsFailure('AI_UNAVAILABLE', 503);
                if ((int) $row->revision !== $input['expected_revision']) throw new MetaCommentsFailure('REVISION_CONFLICT', 409);
                $days = $input['days']; sort($days, SORT_NUMERIC);
                $oldDays = json_decode((string) $row->days, true);
                if (is_array($oldDays)) sort($oldDays, SORT_NUMERIC);
                $changed = (bool) $row->enabled !== $input['enabled'] || $row->mode !== $input['mode']
                    || $oldDays !== $days || $row->start !== $input['start'] || $row->end !== $input['end'];
                if ($changed) {
                    // Every actual policy change establishes a fresh cutoff; never flush an old backlog.
                    DB::table(self::TABLE)->where('id', 1)->update([
                        'enabled' => $input['enabled'], 'mode' => $input['mode'], 'days' => json_encode($days),
                        'start' => $input['start'], 'end' => $input['end'], 'revision' => (int) $row->revision + 1,
                        'enabled_at' => $input['enabled'] ? now('UTC') : null,
                        'actor_id' => (int) $actor->id, 'updated_at' => now('UTC'), 'last_reason' => null,
                    ]);
                }
                return true;
            });
        }, 30000);
        if ($result !== true) throw new MetaCommentsFailure('AI_BUSY', 409);
        return $this->status($actor);
    }

    /** [start,end), with an overnight window belonging to its starting weekday. */
    public function activeAt(array $policy, $time): bool
    {
        return $this->windowStart($policy, $time) !== null;
    }

    public function eligible(array $policy, $createdAt, $nowUtc): bool
    {
        $created = $this->date($createdAt);
        $now = $this->date($nowUtc);
        $activation = $this->date($policy['enabled_at'] ?? null);
        $window = $this->windowStart($policy, $nowUtc);
        if (!$created || !$now || !$activation || !$window || $created > $now || $created < $activation || $created < $window) return false;
        // A comment submitted outside a scheduled window is not carried into a later one.
        return $this->activeAt($policy, $created);
    }

    /** The same mutex is held by stop/update; durable send receipts commit before network I/O. */
    public function withActiveDispatch(array $expected, callable $send)
    {
        return app(MetaCommentAiDispatchLock::class)->run(function () use ($expected, $send) {
            $current = $this->snapshot();
            if (!$current || $current['revision'] !== ($expected['revision'] ?? null)
                || !$this->activeAt($current, new DateTimeImmutable('now', new DateTimeZone('UTC')))
                || !$current['actor_id']) return null;
            try {
                $actor = \App\Models\User::query()->find($current['actor_id']);
                app(MetaInboxAccess::class)->owner($actor);
            } catch (\Throwable $error) { return null; }
            return $send();
        }, 1500);
    }

    public function recordHeartbeat(?string $reason = null): void
    {
        try {
            if ($this->ready()) DB::table(self::TABLE)->where('id', 1)->update([
                'worker_seen_at' => now('UTC'), 'last_reason' => $this->reason($reason),
            ]);
        } catch (\Throwable $error) { /* A status write must not trigger a retry of a public reply. */ }
    }

    private function windowStart(array $policy, $time): ?DateTimeImmutable
    {
        if (($policy['enabled'] ?? false) !== true) return null;
        $date = $this->date($time);
        $activation = $this->date($policy['enabled_at'] ?? null);
        if (!$date || !$activation || $date < $activation) return null;
        if (($policy['mode'] ?? null) === 'always') return $activation;
        if (($policy['mode'] ?? null) !== 'scheduled' || !$this->validDays($policy['days'] ?? null)
            || !$this->clock($policy['start'] ?? null) || !$this->clock($policy['end'] ?? null)
            || $policy['start'] === $policy['end']) return null;
        $local = $date->setTimezone(new DateTimeZone(self::ZONE));
        $clock = $local->format('H:i');
        $overnight = $policy['start'] > $policy['end'];
        $startDay = $local;
        if ($overnight && $clock < $policy['end']) $startDay = $local->modify('-1 day');
        elseif ($clock < $policy['start'] || (!$overnight && $clock >= $policy['end'])) return null;
        if (!in_array((int) $startDay->format('N'), $policy['days'], true)) return null;
        [$hour, $minute] = array_map('intval', explode(':', $policy['start']));
        return $startDay->setTime($hour, $minute, 0)->setTimezone(new DateTimeZone('UTC'));
    }

    private function validDays($days): bool
    {
        if (!is_array($days) || !array_is_list($days) || !$days || count($days) > 7 || count(array_unique($days, SORT_REGULAR)) !== count($days)) return false;
        foreach ($days as $day) if (!is_int($day) || $day < 1 || $day > 7) return false;
        return true;
    }

    private function clock($time): bool
    {
        return is_string($time) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time) === 1;
    }

    private function date($value): ?DateTimeImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
            if (!is_string($value) || $value === '' || strlen($value) > 40 || !preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\z/', $value)) return null;
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable $error) { return null; }
    }

    private function iso($value): ?string
    {
        $date = $this->date($value);
        return $date ? $date->format(DATE_ATOM) : null;
    }

    private function reason($reason): ?string
    {
        return is_string($reason) && preg_match('/\A[A-Z][A-Z0-9_]{0,39}\z/', $reason) ? $reason : null;
    }
}
