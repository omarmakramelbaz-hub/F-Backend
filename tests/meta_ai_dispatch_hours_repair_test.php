<?php
// Reuse the existing actual owner/control/SQLite fixture setup without its
// unrelated HTTP/CSRF checks or a full Laravel application bootstrap.
$fixture = file_get_contents(__DIR__.'/meta_ai_control_test.php');
$setupEnd = strpos($fixture, '    check(!$control->installed()');
if ($setupEnd === false) throw new RuntimeException('DISPATCH_FIXTURE_LAYOUT');
eval(substr($fixture, 5, $setupEnd - 5)."\n}");
$source = file_get_contents(__DIR__.'/../deployment/meta_ai_dispatch_hours_repair.php');
$start = strpos($source, 'function metaDispatchHoursArguments');
$end = strpos($source, '// CLI entry point.');
if ($start === false || $end === false) throw new RuntimeException('DISPATCH_HELPER_LAYOUT');
eval(substr($source, $start, $end - $start));
$dispatchChecks = 0;
function dispatchHoursExpect($ok, string $label): void {
    $GLOBALS['dispatchChecks']++; if (!$ok) throw new RuntimeException('DISPATCH_HOURS_TEST:'.$label);
}
dispatchHoursExpect(metaDispatchHoursArguments(['repair.php', '--expected-revision', '13']) === 13,
    'only the reviewed revision argument is accepted');
foreach ([['repair.php'], ['repair.php', '--expected-revision', '14'], ['repair.php', '--expected-revision', '013'],
    ['repair.php', '--force', '13'], ['repair.php', '--expected-revision', '13', '--enable']] as $arguments) {
    $blocked = false; try { metaDispatchHoursArguments($arguments); } catch (RuntimeException $ignored) { $blocked = true; }
    dispatchHoursExpect($blocked, 'unknown, stale, implicit or forced arguments are rejected');
}
(new CreateMetaAiControls)->up();
$db = \Illuminate\Support\Facades\DB::class;
foreach (['whatsapp','meta'] as $prefix) {
    \Illuminate\Support\Facades\Schema::create($prefix.'_inbox_conversations', static function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->integer('id')->primary(); $table->string('waba_id'); $table->string('phone_number_id');
    });
    \Illuminate\Support\Facades\Schema::create($prefix.'_inbox_messages', static function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->integer('id')->primary(); $table->integer('conversation_id'); $table->string('direction')->nullable();
        $table->string('source')->nullable(); $table->timestamp('sent_at')->nullable();
    });
    \Illuminate\Support\Facades\Schema::create($prefix.'_webhook_events', static function (\Illuminate\Database\Schema\Blueprint $table): void { $table->integer('id')->primary(); });
}
$db::table('whatsapp_inbox_conversations')->insert(['id'=>1, 'waba_id'=>\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID,
    'phone_number_id'=>\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID]);
$db::table('whatsapp_inbox_messages')->insert(['id'=>10, 'conversation_id'=>1]);
$db::table('whatsapp_webhook_events')->insert(['id'=>800]);
$db::table('meta_inbox_conversations')->insert([['id'=>1, 'waba_id'=>'messenger', 'phone_number_id'=>'101'], ['id'=>2, 'waba_id'=>'instagram', 'phone_number_id'=>'202']]);
$db::table('meta_inbox_messages')->insert([['id'=>20, 'conversation_id'=>1], ['id'=>30, 'conversation_id'=>2]]);
$db::table('meta_webhook_events')->insert(['id'=>1000]);
$people->users[1] = new \App\Models\User;
app('config')->set('desktop_dashboard.local', false);
app('config')->set('meta_messaging.accounts', [['channel'=>'messenger','account_id'=>'101'], ['channel'=>'instagram','account_id'=>'202']]);
$control->clock = '2026-10-17 19:00:00';
$policy = \App\Services\Dashboard\MetaAiControl::defaults();
$policy['enabled'] = true; $policy['mode'] = 'ANALYZE_ONLY';
$policy['schedule'] = ['enabled'=>true, 'days'=>[0,1,2,3,4,5,6], 'start'=>'07:15', 'end'=>'22:10', 'all_day'=>true];
$policy['dispatch_schedule']['all_day'] = true;
$control->update(new \App\Models\User, $policy, 0, false);
$db::table('meta_ai_controls')->where('id', 1)->update(['revision'=>13]);
$before = $db::table('meta_ai_controls')->first(); $configBefore = app('config')->all();
$network = count($transportCalls); $prepares = count($business->calls); $writes = [];
$db::listen(static function ($query) use (&$writes): void {
    if (preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql)) $writes[] = $query->sql;
});
$result = metaDispatchHoursApply($db, $access, $control, 13);
$after = $db::table('meta_ai_controls')->first();
$expected = $policy; $expected['dispatch_schedule']['all_day'] = false;
dispatchHoursExpect($result['status'] === 'DISPATCH_HOURS_UPDATED' && $result['writes'] === 1
    && $result['revision_before'] === 13 && $result['revision_after'] === 14, 'real API changes exactly one policy revision');
dispatchHoursExpect(count($writes) === 1 && str_contains($writes[0], 'meta_ai_controls'), 'only the approved control API writes one row');
dispatchHoursExpect($control->state()['policy'] === $expected, 'only dispatch all_day changes while every analysis setting and channel remains exact');
dispatchHoursExpect($after->activations === $before->activations && $after->actor_id === $before->actor_id
    && $after->handoff === $before->handoff, 'activation bytes, original owner and handoff remain unchanged');
dispatchHoursExpect(!$control->gate('whatsapp')['effective'] && !$control->state()['reply_state']['effective'], 'analysis-only mode never enables automatic replies');
dispatchHoursExpect(count($transportCalls) === $network && count($business->calls) === $prepares
    && app('config')->all() === $configBefore, 'no external handoff, Graph request or configuration mutation');
foreach (['ai_calls','message_sends','erp_writes','graph_gets','worker_actions'] as $counter)
    dispatchHoursExpect($result[$counter] === 0, 'no unrelated boundary: '.$counter);
foreach ([['2026-10-17T08:59:59+03:00', false], ['2026-10-17T09:00:00+03:00', true],
    ['2026-10-17T23:59:59+03:00', true], ['2026-10-18T00:29:59+03:00', true],
    ['2026-10-18T00:30:00+03:00', false], ['2026-10-18T08:59:59+03:00', false]] as [$clock,$allowed]) {
    dispatchHoursExpect(\App\Support\MetaAiSchedule::dispatchStatus($expected, new DateTimeImmutable($clock))['effective'] === $allowed,
        'Cairo overnight dispatch boundary '.$clock);
}
$writes = []; $saved = $db::table('meta_ai_controls')->first();
$again = metaDispatchHoursApply($db, $access, $control, 13);
dispatchHoursExpect($again['status'] === 'ALREADY_CURRENT' && $again['writes'] === 0 && $again['revision_after'] === 14
    && $writes === [] && $db::table('meta_ai_controls')->first() == $saved, 'exact rerun at revision14 is read-only');
$db::table('meta_ai_controls')->where('id', 1)->update(['revision'=>19]); $savedLater = $db::table('meta_ai_controls')->first(); $writes = [];
$later = metaDispatchHoursApply($db, $access, $control, 13);
dispatchHoursExpect($later['status'] === 'ALREADY_CURRENT' && $later['writes'] === 0 && $writes === []
    && $db::table('meta_ai_controls')->first() == $savedLater, 'later desired policy is never overwritten');

foreach (['revision','disabled','reply-mode','channels','handoff','revoked-owner','bad-arguments','source'] as $case) {
    $db::table('meta_ai_controls')->where('id', 1)->update((array)$before); $changed = $policy;
    if ($case === 'revision') $db::table('meta_ai_controls')->where('id', 1)->update(['revision'=>14]);
    if ($case === 'disabled') $changed['enabled'] = false;
    if ($case === 'reply-mode') $changed['mode'] = 'REPLY_AND_ANALYZE';
    if ($case === 'channels') $changed['channels'] = ['whatsapp'];
    if (in_array($case, ['disabled','reply-mode','channels'], true)) $db::table('meta_ai_controls')->where('id', 1)->update(['policy'=>json_encode($changed)]);
    if ($case === 'handoff') $db::table('meta_ai_controls')->where('id', 1)->update(['handoff'=>'{"stale":true}']);
    if ($case === 'revoked-owner') $people->users[1]->active = false;
    $saved = $db::table('meta_ai_controls')->first(); $writes = [];
    $guard = $case === 'source' ? static function (): void { throw new RuntimeException('SOURCE_CHANGED'); } : null;
    $blocked = metaDispatchHoursApply($db, $access, $control, $case === 'bad-arguments' ? 14 : 13, $guard);
    dispatchHoursExpect($blocked['status'] === 'DISPATCH_HOURS_BLOCKED' && $blocked['writes'] === 0
        && $writes === [] && $db::table('meta_ai_controls')->first() == $saved, 'blocked scope never updates policy: '.$case);
    $people->users[1]->active = true;
}
foreach (['bad-result','activation-drift','post-update-error','actor-drift','schedule-drift'] as $case) {
    $db::table('meta_ai_controls')->where('id', 1)->update((array)$before);
    $wrapped = new class($control, $case) {
        public function __construct(private $control, private string $case) {}
        public function state(): array { return $this->control->state(); }
        public function update($owner, $policy, $revision, $attested): array {
            $result = $this->control->update($owner, $policy, $revision, $attested);
            if ($this->case === 'bad-result') $result['revision'] = 99;
            if ($this->case === 'activation-drift') \Illuminate\Support\Facades\DB::table('meta_ai_controls')->where('id', 1)->update(['activations'=>'{}']);
            if ($this->case === 'actor-drift') \Illuminate\Support\Facades\DB::table('meta_ai_controls')->where('id', 1)->update(['actor_id'=>3]);
            if ($this->case === 'schedule-drift') { $policy['schedule']['start']='06:00'; \Illuminate\Support\Facades\DB::table('meta_ai_controls')->where('id', 1)->update(['policy'=>json_encode($policy)]); }
            if ($this->case === 'post-update-error') throw new RuntimeException('PRIVATE_INTERNAL_ERROR');
            return $result;
        }
    };
    $blocked = metaDispatchHoursApply($db, $access, $wrapped, 13);
    dispatchHoursExpect($blocked['status'] === 'DISPATCH_HOURS_BLOCKED' && $blocked['writes'] === 0
        && $db::table('meta_ai_controls')->first() == $before, 'outer transaction rolls back inconsistent API update: '.$case);
    dispatchHoursExpect(!isset($blocked['activation_floors_preserved']) && !str_contains(json_encode($blocked), 'PRIVATE'), 'failed update exposes no false success or exception text');
}
// A changed account scope would normally capture another activation in update().
// The helper rejects that result and rolls back, retaining the original bounds.
$db::table('meta_ai_controls')->where('id', 1)->update((array)$before);
app('config')->set('meta_messaging.accounts', [['channel'=>'messenger','account_id'=>'999'], ['channel'=>'instagram','account_id'=>'202']]);
$scope = metaDispatchHoursApply($db, $access, $control, 13);
dispatchHoursExpect($scope['status'] === 'DISPATCH_HOURS_BLOCKED' && $scope['writes'] === 0
    && $db::table('meta_ai_controls')->first() == $before, 'scope-driven activation recapture cannot commit');
app('config')->set('meta_messaging.accounts', $configBefore['meta_messaging']['accounts']);
dispatchHoursExpect(count($transportCalls) === $network && count($business->calls) === $prepares, 'all rollback and rejection paths remain offline');
echo 'META_AI_DISPATCH_HOURS_REPAIR_TESTS='.$dispatchChecks." PASS\n";
