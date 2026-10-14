<?php

require __DIR__.'/../app/Support/MetaAiSchedule.php';

use App\Support\MetaAiSchedule;

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function scheduleCheck($condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
}

function policy(array $overrides = []): array
{
    return array_replace_recursive([
        'enabled' => true,
        'channels' => ['whatsapp', 'messenger', 'instagram'],
        'schedule' => ['enabled' => true, 'days' => [1], 'start' => '09:00', 'end' => '17:00', 'all_day' => false],
    ], $overrides);
}

function cairoStatus(array $policy, string $time): array
{
    return MetaAiSchedule::status($policy, new DateTimeImmutable($time, new DateTimeZone('Africa/Cairo')));
}

function expectState(array $policy, string $time, string $expected): void
{
    $result = cairoStatus($policy, $time);
    scheduleCheck(array_keys($result) === ['effective', 'status', 'local_time'], 'finite status keys');
    scheduleCheck($result['status'] === $expected, $time.' '.$expected);
    scheduleCheck($result['effective'] === ($expected === 'ACTIVE'), 'effective follows state');
}

$canonical = policy();
scheduleCheck(MetaAiSchedule::validate($canonical) === $canonical, 'canonical policy retained');
$unordered = ['schedule' => ['end' => '17:00', 'start' => '09:00', 'days' => [6, 0, 3], 'all_day' => false, 'enabled' => true],
    'channels' => ['instagram', 'whatsapp'], 'enabled' => true];
$normalized = MetaAiSchedule::validate($unordered);
scheduleCheck($normalized['channels'] === ['whatsapp', 'instagram'], 'canonical channel order');
scheduleCheck($normalized['schedule']['days'] === [0, 3, 6], 'canonical day order');
scheduleCheck(array_keys($normalized) === ['enabled', 'channels', 'schedule'], 'canonical top key order');
scheduleCheck(array_keys($normalized['schedule']) === ['enabled', 'days', 'start', 'end', 'all_day'], 'canonical schedule key order');
scheduleCheck(MetaAiSchedule::validate($normalized) === $normalized, 'canonicalization idempotent');

foreach (['enabled', 'channels', 'schedule'] as $key) {
    $bad = $canonical; unset($bad[$key]);
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'required policy key '.$key);
}
foreach (['enabled', 'days', 'start', 'end', 'all_day'] as $key) {
    $bad = $canonical; unset($bad['schedule'][$key]);
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'required schedule key '.$key);
}
foreach (['owner', 'timezone', 'extra', 0] as $key) {
    $bad = $canonical; $bad[$key] = true;
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'unknown policy key');
    $bad = $canonical; $bad['schedule'][$key] = true;
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'unknown schedule key');
}
foreach ([null, 0, 1, 'true', 'false', [], new stdClass()] as $value) {
    $bad = $canonical; $bad['enabled'] = $value;
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'strict master bool');
    foreach (['enabled', 'all_day'] as $key) {
        $bad = $canonical; $bad['schedule'][$key] = $value;
        scheduleCheck(MetaAiSchedule::validate($bad) === null, 'strict schedule bool '.$key);
    }
}
foreach ([null, true, 'whatsapp', new stdClass(), ['WhatsApp'], ['telegram'], [1], [null], ['whatsapp', 'whatsapp'],
    ['x' => 'whatsapp'], [1 => 'whatsapp'], ['whatsapp', 'messenger', 'instagram', 'whatsapp']] as $channels) {
    $bad = $canonical; $bad['channels'] = $channels;
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'invalid channels rejected');
}
foreach ([null, true, '', 0, new stdClass(), []] as $schedule) {
    $bad = $canonical; $bad['schedule'] = $schedule;
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'invalid schedule rejected');
}
foreach ([null, true, '1', new stdClass(), [-1], [7], ['1'], [1.0], [false], [null], [1, 1], ['day' => 1], [1 => 1], range(0, 7)] as $days) {
    $bad = $canonical; $bad['schedule']['days'] = $days;
    scheduleCheck(MetaAiSchedule::validate($bad) === null, 'invalid days rejected');
}
foreach ([null, true, 900, [], new stdClass(), '', '9:00', '09:0', '24:00', '00:60', '-1:00', '09:00:00',
    ' 09:00', '09:00 ', "09:00\n", "09:00\0", '٠٩:٠٠', '+9:00'] as $time) {
    foreach (['start', 'end'] as $key) {
        $bad = $canonical; $bad['schedule'][$key] = $time;
        scheduleCheck(MetaAiSchedule::validate($bad) === null, 'strict time '.$key);
    }
}
$bad = $canonical; $bad['channels'] = [];
scheduleCheck(MetaAiSchedule::validate($bad) === null, 'enabled requires a channel');
$bad['enabled'] = false;
scheduleCheck(MetaAiSchedule::validate($bad) !== null, 'disabled permits no channels');
$bad = $canonical; $bad['schedule']['days'] = [];
scheduleCheck(MetaAiSchedule::validate($bad) === null, 'enabled schedule requires days');
$bad['schedule']['enabled'] = false;
scheduleCheck(MetaAiSchedule::validate($bad) !== null, 'disabled schedule permits no days');
$equal = $canonical; $equal['schedule']['end'] = '09:00';
scheduleCheck(MetaAiSchedule::validate($equal) === null, 'equal times cannot imply all day');
$equal['schedule']['enabled'] = false;
scheduleCheck(MetaAiSchedule::validate($equal) === null, 'equal time strictness also on dormant schedule');
$equal['schedule']['all_day'] = true;
scheduleCheck(MetaAiSchedule::validate($equal) !== null, 'explicit all day permits equal times');

// Monday, with seconds around inclusive start and exclusive end.
foreach (['2026-10-12 00:00:00', '2026-10-12 08:59:59', '2026-10-12 17:00:00', '2026-10-12 23:59:59',
    '2026-10-11 12:00:00', '2026-10-13 12:00:00'] as $time) expectState($canonical, $time, 'SCHEDULED_OFF');
foreach (['2026-10-12 09:00:00', '2026-10-12 09:00:01', '2026-10-12 12:00:00', '2026-10-12 16:59:59'] as $time)
    expectState($canonical, $time, 'ACTIVE');

$disabled = $canonical; $disabled['enabled'] = false;
expectState($disabled, '2026-10-12 12:00:00', 'DISABLED');
expectState($disabled, '2026-10-11 12:00:00', 'DISABLED');
$always = $canonical; $always['schedule']['enabled'] = false; $always['schedule']['days'] = [];
foreach (['2026-10-11 00:00:00', '2026-10-12 12:00:00', '2026-10-17 23:59:59'] as $time)
    expectState($always, $time, 'ACTIVE');
$allDay = $canonical; $allDay['schedule']['all_day'] = true;
foreach (['2026-10-12 00:00:00', '2026-10-12 08:00:00', '2026-10-12 23:59:59'] as $time)
    expectState($allDay, $time, 'ACTIVE');
expectState($allDay, '2026-10-13 00:00:00', 'SCHEDULED_OFF');
expectState($allDay, '2026-10-11 23:59:59', 'SCHEDULED_OFF');

// Saturday-to-Sunday wrap: the early Sunday portion belongs to Saturday.
$overnight = $canonical; $overnight['schedule']['days'] = [6];
$overnight['schedule']['start'] = '22:00'; $overnight['schedule']['end'] = '03:00';
foreach (['2026-10-17 22:00:00', '2026-10-17 23:59:59', '2026-10-18 00:00:00', '2026-10-18 02:59:59'] as $time)
    expectState($overnight, $time, 'ACTIVE');
foreach (['2026-10-17 02:00:00', '2026-10-17 21:59:59', '2026-10-18 03:00:00', '2026-10-18 22:00:00',
    '2026-10-19 01:00:00'] as $time) expectState($overnight, $time, 'SCHEDULED_OFF');
$overnight['schedule']['days'] = [0];
expectState($overnight, '2026-10-18 02:00:00', 'SCHEDULED_OFF');
expectState($overnight, '2026-10-18 22:00:00', 'ACTIVE');
expectState($overnight, '2026-10-19 02:00:00', 'ACTIVE');
$overnight['schedule']['end'] = '00:00';
expectState($overnight, '2026-10-18 23:59:59', 'ACTIVE');
expectState($overnight, '2026-10-19 00:00:00', 'SCHEDULED_OFF');

// Every weekday integer uses PHP/ISO wall-clock convention Sunday=0.
for ($day = 0; $day <= 6; $day++) {
    $oneDay = $canonical; $oneDay['schedule']['days'] = [$day]; $oneDay['schedule']['all_day'] = true;
    for ($offset = 0; $offset <= 6; $offset++) {
        $date = (new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('Africa/Cairo')))->modify('+'.$offset.' days');
        scheduleCheck(MetaAiSchedule::status($oneDay, $date)['effective'] === ($offset === $day), 'all seven day mappings');
    }
}

// The same UTC clock time is evaluated against Cairo's real summer/winter offset.
$clock = $canonical; $clock['schedule']['days'] = range(0, 6);
$winter = MetaAiSchedule::status($clock, new DateTimeImmutable('2026-01-12T07:00:00Z'));
$summer = MetaAiSchedule::status($clock, new DateTimeImmutable('2026-07-13T06:00:00Z'));
scheduleCheck($winter === ['effective' => true, 'status' => 'ACTIVE', 'local_time' => '2026-01-12T09:00:00+02:00'], 'Cairo winter conversion');
scheduleCheck($summer === ['effective' => true, 'status' => 'ACTIVE', 'local_time' => '2026-07-13T09:00:00+03:00'], 'Cairo summer conversion');
scheduleCheck(MetaAiSchedule::status($clock, new DateTimeImmutable('2026-07-13T05:59:59Z'))['status'] === 'SCHEDULED_OFF', 'summer start previous second');
$input = new DateTimeImmutable('2026-07-13T02:00:00-04:00');
scheduleCheck(MetaAiSchedule::status($clock, $input) === $summer, 'input timezone is converted');
scheduleCheck($input->format(DATE_ATOM) === '2026-07-13T02:00:00-04:00', 'immutable input remains unchanged');
$oldDefault = date_default_timezone_get(); date_default_timezone_set('Pacific/Honolulu');
scheduleCheck(MetaAiSchedule::status($clock, new DateTimeImmutable('2026-07-13T06:00:00Z')) === $summer, 'server default timezone ignored');
$now = MetaAiSchedule::status($always);
scheduleCheck($now['effective'] && in_array(substr($now['local_time'], -6), ['+02:00', '+03:00'], true), 'omitted timestamp uses Cairo');
date_default_timezone_set($oldDefault);

// Fall-back repeats 23:30 on Thursday; both occurrences are within the same wall interval.
$fall = $canonical; $fall['schedule'] = ['enabled' => true, 'days' => [4], 'start' => '23:00', 'end' => '23:45', 'all_day' => false];
$firstFall = MetaAiSchedule::status($fall, new DateTimeImmutable('2026-10-29T20:30:00Z'));
$secondFall = MetaAiSchedule::status($fall, new DateTimeImmutable('2026-10-29T21:30:00Z'));
scheduleCheck($firstFall['effective'] && $firstFall['local_time'] === '2026-10-29T23:30:00+03:00', 'DST repeated hour first occurrence');
scheduleCheck($secondFall['effective'] && $secondFall['local_time'] === '2026-10-29T23:30:00+02:00', 'DST repeated hour second occurrence');

// Spring-forward skips midnight; a Friday interval beginning at 00:30 starts at the first existing minute.
$spring = $canonical; $spring['schedule'] = ['enabled' => true, 'days' => [5], 'start' => '00:30', 'end' => '01:30', 'all_day' => false];
scheduleCheck(MetaAiSchedule::status($spring, new DateTimeImmutable('2026-04-23T21:59:59Z'))['effective'] === false, 'before Cairo spring transition');
$firstSpring = MetaAiSchedule::status($spring, new DateTimeImmutable('2026-04-23T22:00:00Z'));
scheduleCheck($firstSpring['effective'] && $firstSpring['local_time'] === '2026-04-24T01:00:00+03:00', 'after Cairo missing hour');
scheduleCheck(MetaAiSchedule::status($spring, new DateTimeImmutable('2026-04-23T22:30:00Z'))['effective'] === false, 'spring end remains exclusive');

// Invalid configuration cannot accidentally enable AI, even during selected business hours.
foreach ([[], ['enabled' => true], ['enabled' => true, 'channels' => ['whatsapp'], 'schedule' => null]] as $invalid) {
    $result = cairoStatus($invalid, '2026-10-12 12:00:00');
    scheduleCheck($result['status'] === 'DISABLED' && $result['effective'] === false, 'invalid status fails closed');
}
scheduleCheck($canonical === policy(), 'input policy never mutated');

echo 'Meta AI schedule: '.$checks." checks passed.\n";
