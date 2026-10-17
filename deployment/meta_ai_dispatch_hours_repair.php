<?php
// Owner-requested dispatch clock repair only. No analysis, replies or order work.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();

function metaDispatchHoursArguments(array $arguments): int
{
    if ($arguments !== [$arguments[0] ?? null, '--expected-revision', '13'])
        throw new RuntimeException('INVALID_ARGUMENTS');
    return 13;
}

function metaDispatchHoursSources(array $pins, ?array $expected = null): array
{
    $observed = [];
    foreach ($pins as $relative => $pin) {
        $path = getcwd().'/'.$relative;
        clearstatcache(true, $path); $before = @lstat($path);
        if (!is_array($before) || is_link($path) || realpath($path) !== $path
            || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > 1048576)
            throw new RuntimeException('SOURCE_CHANGED');
        $bytes = file_get_contents($path); clearstatcache(true, $path); $after = @lstat($path);
        $signature = static fn(array $value): array => array_intersect_key($value,
            array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime']));
        if (!is_string($bytes) || !is_array($after) || $signature($before) !== $signature($after)
            || sha1('blob '.strlen($bytes)."\0".$bytes) !== $pin)
            throw new RuntimeException('SOURCE_CHANGED');
        $observed[$relative] = ['blob' => $pin, 'metadata' => $signature($after)];
    }
    if ($expected !== null && $observed !== $expected) throw new RuntimeException('SOURCE_CHANGED');
    return $observed;
}

function metaDispatchHoursApply($db, $access, $control, int $expected, ?callable $guard = null): array
{
    $out = ['diagnostic' => 'META_AI_DISPATCH_HOURS_REPAIR', 'writes' => 0,
        'ai_calls' => 0, 'message_sends' => 0, 'erp_writes' => 0, 'graph_gets' => 0,
        'worker_actions' => 0];
    $stage = 'CURRENT_POLICY'; $before = null; $attempted = false;
    try {
        if ($expected !== 13) throw new RuntimeException('INVALID_ARGUMENTS');
        if ($guard !== null) $guard();
        $result = $db::transaction(static function () use ($db, $access, $control, $expected, $guard,
            &$out, &$stage, &$before, &$attempted): array {
            $before = $db::table('meta_ai_controls')->where('id', 1)->lockForUpdate()->first();
            $state = $control->state();
            if (!$before || ($state['available'] ?? false) !== true || !is_int($state['revision'] ?? null)
                || !is_array($state['policy'] ?? null) || !is_string($before->activations ?? null)
                || filter_var($before->revision ?? null, FILTER_VALIDATE_INT) !== $state['revision'])
                throw new RuntimeException('UNAVAILABLE');
            $out['revision_before'] = $state['revision'];
            $policy = $state['policy'];
            if (($policy['enabled'] ?? null) !== true || ($policy['mode'] ?? null) !== 'ANALYZE_ONLY'
                || ($policy['channels'] ?? null) !== ['whatsapp', 'messenger', 'instagram']
                || ($before->handoff ?? null) !== null)
                throw new RuntimeException('POLICY_SCOPE_CHANGED');
            $actorId = filter_var($before->actor_id ?? null, FILTER_VALIDATE_INT);
            if (!is_int($actorId) || $actorId < 1) throw new RuntimeException('OWNER_UNAVAILABLE');
            $stage = 'OWNER'; $identity = new \App\Models\User; $identity->id = $actorId;
            $owner = $access->owner($identity);
            if ((int)$owner->getKey() !== $actorId) throw new RuntimeException('OWNER_UNAVAILABLE');
            $schedule = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6],
                'start' => '09:00', 'end' => '00:30', 'all_day' => false];
            if ($policy['dispatch_schedule'] === $schedule && $state['revision'] >= $expected) {
                return ['status' => 'ALREADY_CURRENT', 'revision_after' => $state['revision'],
                    'dispatch_schedule' => $schedule, 'writes' => 0];
            }
            if ($state['revision'] !== $expected) throw new RuntimeException('REVISION_CONFLICT');
            $out['dispatch_schedule_before'] = $policy['dispatch_schedule'];
            $next = $policy; $next['dispatch_schedule'] = $schedule;
            $stage = 'SCHEDULE_CHANGE';
            if ($guard !== null) $guard();
            $attempted = true;
            // update() remains the only policy writer. Its own current-read lock,
            // persisted owner check and revision CAS run inside this transaction.
            $after = $control->update($owner, $next, $expected, false);
            $stage = 'VERIFY';
            $stored = $db::table('meta_ai_controls')->where('id', 1)->lockForUpdate()->first();
            $storedPolicy = $stored && is_string($stored->policy ?? null)
                ? json_decode($stored->policy, true, 8, JSON_THROW_ON_ERROR) : null;
            if (!$stored || ($after['available'] ?? false) !== true || ($after['revision'] ?? null) !== $expected + 1
                || filter_var($stored->revision ?? null, FILTER_VALIDATE_INT) !== $expected + 1
                || ($after['policy'] ?? null) !== $next || $storedPolicy !== $next
                || ($after['reply_state']['effective'] ?? true) !== false
                || $stored->activations !== $before->activations || ($stored->handoff ?? null) !== $before->handoff
                || (int)$stored->actor_id !== $actorId)
                throw new RuntimeException('VERIFY_FAILED');
            if ($guard !== null) $guard();
            return ['status' => 'DISPATCH_HOURS_UPDATED', 'revision_after' => $expected + 1,
                'dispatch_schedule' => $schedule, 'writes' => 1];
        }, 3);
        $out = array_replace($out, $result, ['analysis_schedule_preserved' => true,
            'activation_floors_preserved' => true, 'actor_preserved' => true,
            'mode' => 'ANALYZE_ONLY', 'automatic_replies_enabled' => false]);
    } catch (Throwable $error) {
        // Verification is inside the outer transaction, so a failed check rolls
        // back the API update. An unobservable commit outcome is never called zero.
        if ($attempted) {
            $out['writes'] = null;
            try {
                $current = $db::table('meta_ai_controls')->where('id', 1)->first();
                if ($before !== null && $current == $before) $out['writes'] = 0;
            } catch (Throwable $ignored) {}
        }
        $allowed = ['INVALID_ARGUMENTS', 'SOURCE_CHANGED', 'UNAVAILABLE', 'POLICY_SCOPE_CHANGED',
            'OWNER_UNAVAILABLE', 'REVISION_CONFLICT', 'VERIFY_FAILED', 'REPLIES_PENDING'];
        $out['status'] = 'DISPATCH_HOURS_BLOCKED'; $out['stage'] = $stage;
        $out['reason'] = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'UNAVAILABLE';
    }
    return $out;
}

// CLI entry point. No application-specific source is loaded before exact guards.
$out = ['diagnostic' => 'META_AI_DISPATCH_HOURS_REPAIR', 'writes' => 0,
    'ai_calls' => 0, 'message_sends' => 0, 'erp_writes' => 0, 'graph_gets' => 0, 'worker_actions' => 0];
$stage = 'ARGUMENTS';
try {
    $expected = metaDispatchHoursArguments($argv);
    $stage = 'OWNER'; $owner = posix_getpwnam('fasakha');
    if (!is_array($owner) || posix_geteuid() !== $owner['uid'] || !chdir('/home/fasakha/public_html'))
        throw new RuntimeException('OWNER_INVALID');
    $stage = 'SOURCE_GUARD';
    $pins = [
        'app/Services/Dashboard/MetaAiControl.php' => 'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/MetaAiSchedule.php' => '667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaInboxAccess.php' => '7faaba6645308c1997bae8182fb4b26dbc5e3b9a',
        'app/Services/Dashboard/WhatsAppInboxAccess.php' => '186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/TakeawayAccess.php' => 'd063ee444c347c41620916b4513d0f03785141aa',
    ];
    $sources = metaDispatchHoursSources($pins);
    $guard = static fn() => metaDispatchHoursSources($pins, $sources);
    $stage = 'BOOTSTRAP'; require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::class;
    if ($db::connection()->getDriverName() !== 'mysql' || $db::connection()->transactionLevel() !== 0)
        throw new RuntimeException('DATABASE_CONTEXT_INVALID');
    $out = metaDispatchHoursApply($db, $app->make(\App\Services\Dashboard\MetaInboxAccess::class),
        $app->make(\App\Services\Dashboard\MetaAiControl::class), $expected, $guard);
} catch (Throwable $error) {
    $out['status'] = 'DISPATCH_HOURS_BLOCKED'; $out['stage'] = $stage;
    $out['reason'] = in_array($error->getMessage(), ['INVALID_ARGUMENTS', 'OWNER_INVALID', 'SOURCE_CHANGED',
        'DATABASE_CONTEXT_INVALID'], true) ? $error->getMessage() : 'UNAVAILABLE';
}
while (ob_get_level()) ob_end_clean();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'], ['DISPATCH_HOURS_UPDATED', 'ALREADY_CURRENT'], true) ? 0 : 1);
