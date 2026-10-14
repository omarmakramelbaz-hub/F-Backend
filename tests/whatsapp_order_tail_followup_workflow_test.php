<?php

// Real workflow/validator/receipt projections; only external extraction/send transports are fixtures.
require __DIR__.'/whatsapp_order_media_handoff_workflow_test.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as TailAi;
use App\Services\Dashboard\WhatsAppOrderFollowupService as TailService;
use App\Support\WhatsAppOrderExtraction as TailExtraction;
use Illuminate\Support\Facades\DB as TailDB;
use Illuminate\Support\Facades\Crypt as TailCrypt;

$tailChecks=0;
function tailExpect($condition,string $label): void {
    $GLOBALS['tailChecks']++;
    if (!$condition) throw new RuntimeException('TAIL_FOLLOWUP_WORKFLOW_FAILED:'.$label);
}
function tailThread(string $reply='شكرا'): int {
    $conversation=mediaHandoffThread(null,$reply);
    mediaHandoffNative($conversation,'wamid.tail-handoff-audio',5,null,'audio');
    mediaHandoffNative($conversation,'wamid.tail-handoff-document',6,null,'document');
    return $conversation;
}
function tailNoErp(string $label): void {
    tailExpect(TailDB::table('test_tickets')->count()===0&&TailDB::table('branch_customers')->count()===0
        &&TailDB::table('test_print')->count()===0&&TailDB::table('test_billing')->count()===0,$label);
}
function tailPlan(int $conversation): array {
    global $workflow;
    $workflow->analyze($conversation,(object)['id'=>7]);
    $draft=TailDB::table('whatsapp_order_drafts')->first();
    // This is the prior deployed LOCATION_UNCONFIRMED cache state, not changed customer facts.
    TailDB::table('whatsapp_order_drafts')->where('id',$draft->id)->update(['reason'=>'LOCATION_UNCONFIRMED']);
    $plan=(new ReflectionMethod($workflow,'missingInformationPlan'))->invoke($workflow,(int)$draft->id,(object)['id'=>7]);
    tailExpect(is_array($plan)&&$plan['fields']===['LOCATION'],'existing valid cached tail constructs actual LOCATION plan');
    return $plan;
}

followupSetup(); $conversation=tailThread(); $snapshot=handoffSnapshot($conversation);
$checked=TailExtraction::validateDetailed(TailAi::$data,$snapshot['transcript']);
tailExpect($checked['ok']&&$checked['data']['decision']==='CONFIRMED','mixed customer tail leaves original extracted order fully grounded');
$tail=array_slice($snapshot['transcript'],2);
tailExpect(count($tail)===4&&count(array_filter($tail,static fn($row)=>$row['text']===null))===3
    &&$tail[0]['text']==='شكرا'&&TailAi::$data['evidence']['customer_acceptance_ids']===['m3'],
    'actual fixture matches three authenticated nontext turns plus one cited ordinary customer reply after final merchant');
$plan=tailPlan($conversation); $cached=TailDB::table('whatsapp_order_drafts')->first();
$cipher=$cached->extraction; $ceiling=$cached->evidence_ceiling; $calls=TailAi::$calls;
tailExpect(app(TailService::class)->handoffAllowed($plan)
    &&!app(TailService::class)->approvalSatisfied($conversation,TailAi::$data,$snapshot),
    'merchant completion authorizes only a question while opaque/courtesy tail requires owned factual approval');
$result=followupRun();
tailExpect($result['review_required']===1&&count(FollowupFixtureReplies::$sent)===1
    &&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],
    'cached mixed-media tail gets exactly one missing-location question');
tailExpect(TailAi::$calls===$calls&&TailDB::table('whatsapp_order_drafts')->count()===1
    &&TailDB::table('whatsapp_order_drafts')->first()->id===$cached->id
    &&TailDB::table('whatsapp_order_drafts')->first()->extraction===$cipher
    &&TailDB::table('whatsapp_order_drafts')->first()->evidence_ceiling===$ceiling,
    'first tail question keeps same encrypted facts/draft/ceiling without another extraction');
tailNoErp('mixed media or courtesy does not create customer coordinates, billing, ticket or print');
TailDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $result=followupRun();
tailExpect($result['deferred']===1&&TailAi::$calls===$calls&&count(FollowupFixtureReplies::$sent)===1,
    'own authenticated missing-data question waits for actual customer input without repeat or AI spend');
insertMessage($conversation,'wamid.tail-handoff-pin','inbound','',301,['lat'=>31.00,'long'=>31.40],'US.media-handoff-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
TailAi::$data['evidence']['location_id']='m8'; TailAi::$data['issues']=['MISSING_BRANCH'];
tailExpect(TailExtraction::validateDetailed(TailAi::$data,handoffSnapshot($conversation)['transcript'])['ok'],
    'actual native pin supplies grounded location without promoting ordinary text or attachments to approval');
TailDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $result=followupRun();
tailExpect($result['review_required']===1&&count(FollowupFixtureReplies::$sent)===2
    &&FollowupFixtureReplies::$sent[1]['marker']['fields']===['CONFIRMATION']&&TailAi::$calls===$calls+1,
    'real pin resumes extraction then actual owned recap requests fresh approval of complete order');
tailExpect(str_contains(FollowupFixtureReplies::$sent[1]['text'],'1 كيلو فسيخ')
    &&str_contains(FollowupFixtureReplies::$sent[1]['text'],'شارع المثال المنصوره')
    &&str_contains(FollowupFixtureReplies::$sent[1]['text'],'اكتب موافق'),
    'owned recap displays actual requested item/contact/address before soliciting consent');
tailNoErp('pin plus model CONFIRMED plus old merchant summary still cannot execute without owned recap approval');
TailDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
tailExpect(TailAi::$calls===$calls+1&&count(FollowupFixtureReplies::$sent)===2,
    'owned factual recap alone waits and is not its own consent');
insertMessage($conversation,'wamid.tail-handoff-final-approval','inbound','تمام موافق',361,null,'US.media-handoff-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:07:00');
TailAi::$data['evidence']['confirmation_ids']=['m9']; TailAi::$data['evidence']['customer_acceptance_ids']=['m10'];
TailDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $result=followupRun();
tailExpect($result['dispatched']===1&&TailAi::$calls===$calls+2&&TailDB::table('test_tickets')->count()===1
    &&TailDB::table('test_tickets')->first()->branch==='f:363',
    'later whole positive cited approval authorizes exactly one intended-branch ERP order');
tailExpect(TailDB::table('test_print')->count()===1&&TailDB::table('test_tickets')->first()->payment_status==='unpaid'
    &&TailDB::table('test_billing')->count()===0,'approved tail completion prints once and leaves payment uncollected');
followupRun(); tailExpect(TailDB::table('test_tickets')->count()===1&&TailDB::table('test_print')->count()===1
    &&count(FollowupFixtureReplies::$sent)===2,'completed mixed-tail replay repeats no question, ticket or print');

foreach (['لا','No','مش موافق','غير موافق','تمام بس غير الطلب','عايز طلب جديد 2 كيلو فسيخ'] as $reply) {
    followupSetup(); tailThread($reply); followupRun();
    tailExpect(count(FollowupFixtureReplies::$sent)===0,'visible refusal/change/new order cannot borrow stale final summary '.$reply);
    tailNoErp('refused or changed mixed tail leaves ERP untouched '.$reply);
}
foreach (['شكرا','وصلت','العنوان جنب المحل','قال العميل تمام','تمام؟'] as $reply) {
    followupSetup(); $conversation=tailThread($reply); followupRun();
    tailExpect(count(FollowupFixtureReplies::$sent)===1&&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],
        'ordinary/quoted/question customer tail receives one missing-data question '.$reply);
    tailNoErp('ordinary mixed tail remains unapproved and creates no ERP '.$reply);
}
followupSetup(); $conversation=tailThread(); $before=handoffSnapshot($conversation);
FollowupFixtureReplies::$beforeSend=static function() use ($conversation): void {
    mediaHandoffAlterNative($conversation,static function(array &$dto): void { $dto['content']['message']['document']['caption']='لا'; });
};
followupRun(); tailExpect(handoffSnapshot($conversation)['hash']===$before['hash']&&count(FollowupFixtureReplies::$sent)===0,
    'raw document refusal during styling blocks sender even when semantic text hash remains unchanged');
tailNoErp('late raw caption refusal creates no ERP');
followupSetup(); tailThread(); TailDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
$result=followupRun(); tailExpect($result['capture_pending']===1&&TailAi::$calls===0&&count(FollowupFixtureReplies::$sent)===0,
    'pending capture blocks mixed tail before extraction or message send'); tailNoErp('pending mixed-tail capture does not mutate ERP');
followupSetup(); tailThread(); app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:07'); followupRun();
tailExpect(count(FollowupFixtureReplies::$sent)===0,'preactivation mixed tail cannot replay historical order'); tailNoErp('historical mixed tail creates no ERP');
followupSetup(); $conversation=tailThread();
insertMessage($conversation,'wamid.tail-handoff-probe','inbound','WA-1234',7,null,'US.media-handoff-fixture'); followupRun();
tailExpect(TailDB::table('whatsapp_order_drafts')->first()->status==='NONE'&&count(FollowupFixtureReplies::$sent)===0,
    'operational test normalization remains authoritative over raw CONFIRMED hint'); tailNoErp('operational mixed-tail test creates no ERP');
followupSetup(); tailThread(); TailAi::$data['evidence']['confirmation_ids']=[]; followupRun();
tailExpect(count(FollowupFixtureReplies::$sent)===0,'missing final merchant evidence cannot establish tail handoff'); tailNoErp('unproved final merchant creates no ERP');

\Carbon\Carbon::setTestNow();
echo 'WHATSAPP_ORDER_TAIL_FOLLOWUP_WORKFLOW_TESTS='.$tailChecks." PASS\n";
