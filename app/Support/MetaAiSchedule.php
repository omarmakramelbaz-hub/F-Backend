<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Pure policy validation and recurring wall-clock hours in Egypt. */
final class MetaAiSchedule
{
    private const CHANNELS = ['whatsapp', 'messenger', 'instagram'];
    public const REPLY_AND_ANALYZE = 'REPLY_AND_ANALYZE';
    public const ANALYZE_ONLY = 'ANALYZE_ONLY';

    public static function defaultDispatchSchedule(): array
    {
        return ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6],
            'start' => '09:00', 'end' => '00:30', 'all_day' => false];
    }

    /** Unknown keys and implicit type conversions are deliberately rejected. */
    public static function validate(array $policy): ?array
    {
        if (!array_key_exists('dispatch_schedule', $policy)) $policy['dispatch_schedule'] = self::defaultDispatchSchedule();
        if (!array_key_exists('mode', $policy)) $policy['mode'] = self::REPLY_AND_ANALYZE;
        if (!self::exactKeys($policy, ['enabled', 'channels', 'schedule', 'dispatch_schedule', 'mode'])
            || !is_bool($policy['enabled']) || !is_array($policy['channels'])
            || !array_is_list($policy['channels']) || count($policy['channels']) > 3
            || !is_string($policy['mode']) || !in_array($policy['mode'], [self::REPLY_AND_ANALYZE, self::ANALYZE_ONLY], true)
            || !is_array($policy['schedule']) || !is_array($policy['dispatch_schedule'])) return null;

        $channels = [];
        foreach ($policy['channels'] as $channel) {
            if (!is_string($channel) || !in_array($channel, self::CHANNELS, true)
                || in_array($channel, $channels, true)) return null;
            $channels[] = $channel;
        }
        if ($policy['enabled'] && $channels === []) return null;

        $schedule = self::validateSchedule($policy['schedule']);
        $dispatch = self::validateSchedule($policy['dispatch_schedule']);
        if ($schedule === null || $dispatch === null) return null;

        return [
            'enabled' => $policy['enabled'],
            'channels' => array_values(array_intersect(self::CHANNELS, $channels)),
            'schedule' => $schedule,
            'dispatch_schedule' => $dispatch,
            'mode' => $policy['mode'],
        ];
    }

    /** Analysis-only is independent of reply hours; dispatch keeps its own clock. */
    public static function processingStatus(array $policy, ?DateTimeImmutable $at = null): array
    {
        $policy = self::validate($policy);
        if ($policy !== null && $policy['mode'] === self::ANALYZE_ONLY) {
            $policy['schedule']['enabled'] = false;
            return self::status($policy, $at);
        }
        $reply = self::status($policy ?? [], $at);
        $dispatch = self::dispatchStatus($policy ?? [], $at);
        return !$reply['effective'] && $dispatch['effective'] ? $dispatch : $reply;
    }

    private static function validateSchedule(array $schedule): ?array
    {
        if (!self::exactKeys($schedule, ['enabled', 'days', 'start', 'end', 'all_day'])
            || !is_bool($schedule['enabled']) || !is_bool($schedule['all_day'])
            || !is_array($schedule['days']) || !array_is_list($schedule['days'])
            || count($schedule['days']) > 7
            || !self::validTime($schedule['start']) || !self::validTime($schedule['end'])
            || (!$schedule['all_day'] && $schedule['start'] === $schedule['end'])) return null;

        $days = [];
        foreach ($schedule['days'] as $day) {
            if (!is_int($day) || $day < 0 || $day > 6 || in_array($day, $days, true)) return null;
            $days[] = $day;
        }
        if ($schedule['enabled'] && $days === []) return null;
        sort($days, SORT_NUMERIC);

        return [
            'enabled' => $schedule['enabled'],
            'days' => $days,
            'start' => $schedule['start'],
            'end' => $schedule['end'],
            'all_day' => $schedule['all_day'],
        ];
    }

    /** The end is exclusive. An overnight interval belongs to its starting day. */
    public static function status(array $policy, ?DateTimeImmutable $at = null): array
    {
        $zone = new DateTimeZone('Africa/Cairo');
        $local = $at === null ? new DateTimeImmutable('now', $zone) : $at->setTimezone($zone);
        $policy = self::validate($policy);
        $state = 'DISABLED';

        if ($policy !== null && $policy['enabled']) {
            $schedule = $policy['schedule'];
            $active = !$schedule['enabled'];
            if ($schedule['enabled']) {
                $day = (int)$local->format('w');
                $minute = (int)$local->format('H') * 60 + (int)$local->format('i');
                $selectedDay = in_array($day, $schedule['days'], true);
                if ($schedule['all_day']) {
                    $active = $selectedDay;
                } else {
                    $start = self::minutes($schedule['start']);
                    $end = self::minutes($schedule['end']);
                    if ($start < $end) {
                        $active = $selectedDay && $minute >= $start && $minute < $end;
                    } else {
                        $previousDay = ($day + 6) % 7;
                        $active = ($selectedDay && $minute >= $start)
                            || (in_array($previousDay, $schedule['days'], true) && $minute < $end);
                    }
                }
            }
            $state = $active ? 'ACTIVE' : 'SCHEDULED_OFF';
        }

        return ['effective' => $state === 'ACTIVE', 'status' => $state,
            'local_time' => $local->format(DATE_ATOM)];
    }

    /** Dispatch uses its own Cairo schedule; reply hours do not constrain it. */
    public static function dispatchStatus(array $policy, ?DateTimeImmutable $at = null): array
    {
        $validated = self::validate($policy);
        if ($validated !== null) $validated['schedule'] = $validated['dispatch_schedule'];
        $status = self::status($validated ?? [], $at);
        if ($status['status'] === 'SCHEDULED_OFF') $status['status'] = 'DISPATCH_OUTSIDE_HOURS';
        return $status;
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        if (count($value) !== count($keys)) return false;
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $keys, true)) return false;
        }
        return true;
    }

    private static function validTime($time): bool
    {
        return is_string($time) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $time) === 1;
    }

    private static function minutes(string $time): int
    {
        return (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
    }
}
