<?php

// Real workflow/validator/service and authenticated reply projection; synthetic transport only.
require __DIR__.'/whatsapp_order_followup_workflow_test.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as HandoffAi;
use App\Support\WhatsAppOrderExtraction as HandoffExtraction;
use Illuminate\Support\Facades\DB as HandoffDB;
use Illuminate\Support\Facades\Crypt as HandoffCrypt;

$handoffChecks=0;
function handoffExpect($condition,string $label): void {
    $GLOBALS['handoffChecks']++;
    if (!$condition) throw new RuntimeException('HANDOFF_WORKFLOW_FAILED:'.$label);
}
function handoffThread(string $approval='تمام'): int {
    $c=addConversation('US.handoff-fixture');
    insertMessage($c,'wamid.handoff-request','inbound',
        'اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز 1 كيلو فسيخ.',1,null,'US.handoff-fixture');
    insertMessage($c,'wamid.handoff-external-final','outbound',
        'تأكيد طلب عميل مثال: 1 كيلو فسيخ إلى شارع المثال المنصوره.',2,null,'US.handoff-fixture');
    insertMessage($c,'wamid.handoff-customer-approval','inbound',$approval,3,null,'US.handoff-fixture');
    HandoffAi::$data=extractionData(); HandoffAi::$data['approximate_total']=null;
    HandoffAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    HandoffAi::$data['branch_hint']=null; HandoffAi::$data['issues']=['MISSING_BRANCH','LOCATION_REQUIRED'];
    HandoffAi::$data['evidence']=['request_ids'=>['m1'],'confirmation_ids'=>['m2'],'customer_acceptance_ids'=>['m3'],'location_id'=>null];
    return $c;
}
function handoffSnapshot(int $c): array {
    global $workflow;
    return (new ReflectionMethod($workflow,'snapshot'))->invoke($workflow,$c,0,
        (int)HandoffDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id'));
}

followupSetup(); $c=handoffThread();
handoffExpect(HandoffExtraction::validateDetailed(HandoffAi::$data,handoffSnapshot($c)['transcript'])['ok'],
    'external factual recap and actual customer approval pass unchanged extraction validator');
$original=HandoffAi::$data; $p=followupRun();
handoffExpect($p['review_required']===1&&count(FollowupFixtureReplies::$sent)===1
    &&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],
    'confirmed last customer approval hands off to exactly one missing-location question without later business response');
handoffExpect(HandoffAi::$data===$original&&HandoffDB::table('test_tickets')->count()===0
    &&HandoffDB::table('branch_customers')->count()===0,'handoff question preserves grounded extraction and writes no ERP');
$draft=HandoffDB::table('whatsapp_order_drafts')->first(); $cipher=$draft->extraction; $ceiling=$draft->evidence_ceiling;
$calls=HandoffAi::$calls; HandoffDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
handoffExpect($p['deferred']===1&&HandoffAi::$calls===$calls&&count(FollowupFixtureReplies::$sent)===1,
    'authenticated own question waits for actual customer answer without another AI extraction/send');
handoffExpect(HandoffDB::table('whatsapp_order_drafts')->first()->extraction===$cipher
    &&HandoffDB::table('whatsapp_order_drafts')->first()->evidence_ceiling===$ceiling,
    'waiting does not alter sealed customer facts or evidence ceiling');
insertMessage($c,'wamid.handoff-customer-pin','inbound','',301,['lat'=>31.00,'long'=>31.40],'US.handoff-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
HandoffAi::$data['evidence']['location_id']='m5'; HandoffAi::$data['issues']=['MISSING_BRANCH'];
handoffExpect(HandoffExtraction::validateDetailed(HandoffAi::$data,handoffSnapshot($c)['transcript'])['ok'],
    'actual pin answer keeps original merchant recap and customer acceptance valid');
HandoffDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
handoffExpect($p['dispatched']===1&&HandoffAi::$calls===$calls+1
    &&HandoffDB::table('test_tickets')->count()===1&&HandoffDB::table('test_tickets')->first()->branch==='f:363',
    'new native pin resumes normal validation and dispatches exactly one intended-branch order');
handoffExpect(HandoffDB::table('test_print')->count()===1&&HandoffDB::table('test_tickets')->first()->payment_status==='unpaid'
    &&HandoffDB::table('test_billing')->count()===0,'handoff completion prints once and collects no payment');
followupRun(); handoffExpect(HandoffDB::table('test_tickets')->count()===1&&count(FollowupFixtureReplies::$sent)===1,
    'completed handoff replay sends no extra question and creates no duplicate ERP');

foreach (['حاضر','نعم','تمام موافق','موافق على الطلب','okay'] as $approval) {
    followupSetup(); handoffThread($approval); followupRun();
    handoffExpect(count(FollowupFixtureReplies::$sent)===1&&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],
        'whole positive approval permits grounded handoff '.$approval);
}
followupSetup(); $c=handoffThread();
$analyzed=$workflow->analyze($c,(object)['id'=>7]); $cached=HandoffDB::table('whatsapp_order_drafts')->first();
HandoffDB::table('whatsapp_order_drafts')->where('id',$cached->id)->update(['reason'=>'LOCATION_UNCONFIRMED']);
$calls=HandoffAi::$calls; followupRun();
handoffExpect(HandoffAi::$calls===$calls&&HandoffDB::table('whatsapp_order_drafts')->count()===1
    &&HandoffDB::table('whatsapp_order_drafts')->first()->id===$cached->id
    &&HandoffDB::table('whatsapp_order_drafts')->first()->extraction===$cached->extraction,
    'existing cached location review resumes without reextraction or rewritten facts/draft');
handoffExpect(count(FollowupFixtureReplies::$sent)===1&&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],
    'cached genuine positive-approved order uses new handoff without DB wake or activation change');
foreach (['مش موافق','تمام بس غير الطلب','تمام، عنواني مكان آخر','قال لي العميل تمام','"تمام"','تمام؟','غير موافق'] as $approval) {
    followupSetup(); handoffThread($approval); followupRun();
    handoffExpect(count(FollowupFixtureReplies::$sent)===0&&HandoffDB::table('test_tickets')->count()===0
        &&HandoffDB::table('branch_customers')->count()===0,'refusal/change/quotation/question cannot authorize handoff '.$approval);
}
followupSetup(); $c=handoffThread();
insertMessage($c,'wamid.handoff-later-data','inbound','الاسم عميل آخر',4,null,'US.handoff-fixture'); followupRun();
handoffExpect(count(FollowupFixtureReplies::$sent)===0&&HandoffDB::table('test_tickets')->count()===0,
    'older cited positive approval cannot hand off after latest new customer detail');
followupSetup(); handoffThread(); HandoffAi::$data['evidence']['customer_acceptance_ids']=[]; followupRun();
handoffExpect(count(FollowupFixtureReplies::$sent)===0&&HandoffDB::table('test_tickets')->count()===0,
    'uncited positive text does not establish the new handoff');
followupSetup(); handoffThread(); HandoffAi::$data['decision']='DRAFT'; HandoffAi::$data['issues'][]='UNCONFIRMED'; followupRun();
handoffExpect(count(FollowupFixtureReplies::$sent)===0&&HandoffDB::table('test_tickets')->count()===0,
    'the focused final-approval handoff cannot promote an unconfirmed DRAFT');
followupSetup(); handoffThread(); app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:04'); followupRun();
handoffExpect(count(FollowupFixtureReplies::$sent)===0&&HandoffDB::table('test_tickets')->count()===0,
    'preactivation request/recap/approval remain historical and cannot hand off');
followupSetup(); handoffThread(); HandoffDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]); $p=followupRun();
handoffExpect($p['capture_pending']===1&&HandoffAi::$calls===0&&count(FollowupFixtureReplies::$sent)===0
    &&HandoffDB::table('test_tickets')->count()===0,'pending raw capture blocks handoff before extraction or send');
followupSetup(); $c=handoffThread();
FollowupFixtureReplies::$beforeSend=static fn()=>insertMessage($c,'wamid.handoff-during-style','inbound','مش موافق',301,null,'US.handoff-fixture');
followupRun(); handoffExpect(count(FollowupFixtureReplies::$sent)===0&&HandoffDB::table('test_tickets')->count()===0,
    'fresh refusal while styling invalidates exact handoff plan before send');

\Carbon\Carbon::setTestNow();
echo 'WHATSAPP_ORDER_HANDOFF_WORKFLOW_TESTS='.$handoffChecks." PASS\n";
