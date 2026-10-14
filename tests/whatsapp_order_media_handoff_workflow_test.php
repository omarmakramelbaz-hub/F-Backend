<?php

// Real workflow, unchanged extraction validation and authenticated native media DTOs.
// The inherited reply fixture simulates accepted transport without external messages.
require __DIR__.'/whatsapp_order_handoff_workflow_test.php';

use App\Services\Dashboard\WhatsAppInboxAccess as MediaHandoffAccess;
use App\Services\Dashboard\WhatsAppInboxMessage as MediaHandoffInbox;
use App\Services\Dashboard\WhatsAppOrderAiProvider as MediaHandoffAi;
use App\Services\Dashboard\WhatsAppOrderFollowupService as MediaHandoffService;
use App\Support\WhatsAppInboxProtocol as MediaHandoffProtocol;
use App\Support\WhatsAppOrderExtraction as MediaHandoffExtraction;
use Illuminate\Support\Facades\DB as MediaHandoffDB;
use Illuminate\Support\Facades\Crypt as MediaHandoffCrypt;

$mediaHandoffChecks=0;
function mediaHandoffExpect($condition,string $label): void {
    $GLOBALS['mediaHandoffChecks']++;
    if (!$condition) throw new RuntimeException('MEDIA_HANDOFF_WORKFLOW_FAILED:'.$label);
}
function mediaHandoffNative(int $conversation,string $native,int $second,?string $caption=null,string $kind='image',string $peer='US.media-handoff-fixture'): int {
    $mime=['image'=>'image/jpeg','video'=>'video/mp4','audio'=>'audio/ogg','document'=>'application/pdf'][$kind];
    $message=['id'=>$native,'timestamp'=>(string)(strtotime('2026-10-09 22:00:00 UTC')+$second),
        'type'=>$kind,'from'=>'201000000001','from_user_id'=>$peer,
        $kind=>['id'=>'100000000000001','mime_type'=>$mime,'sha256'=>str_repeat('a',64)]];
    if ($caption!==null) $message[$kind]['caption']=$caption;
    $payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>MediaHandoffAccess::WABA_ID,'changes'=>[
        ['field'=>'messages','value'=>['messaging_product'=>'whatsapp','metadata'=>[
            'phone_number_id'=>MediaHandoffAccess::PHONE_ID,'display_phone_number'=>'201285545554'],
            'messages'=>[$message]]]]]]];
    $report=MediaHandoffProtocol::report($payload,MediaHandoffAccess::WABA_ID,MediaHandoffAccess::PHONE_ID);
    mediaHandoffExpect(count($report['messages'])===1,'actual native '.$kind.' webhook normalization');
    $dto=$report['messages'][0];
    $id=MediaHandoffDB::table('whatsapp_inbox_messages')->insertGetId(['conversation_id'=>$conversation,
        'message_key'=>hash('sha256','whatsapp-message-v1:'.MediaHandoffAccess::WABA_ID.':'.MediaHandoffAccess::PHONE_ID.':'.$native),
        'direction'=>$dto['direction'],'type'=>$dto['type'],'source'=>$dto['source'],'sent_at'=>$dto['sent_at'],
        'content'=>MediaHandoffCrypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
        'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $authenticated=(new MediaHandoffInbox)->projected(
        MediaHandoffDB::table('whatsapp_inbox_conversations')->where('id',$conversation)->first(),
        MediaHandoffDB::table('whatsapp_inbox_messages')->where('id',$id)->first());
    mediaHandoffExpect($authenticated['kind']===$kind&&$authenticated['native_id']===$native
        &&$authenticated['direction']==='inbound'&&$authenticated['text']===$caption,
        'real native projection authenticates '.$kind.' identity/timestamp/type/caption');
    return $id;
}
function mediaHandoffThread(?string $caption=null,?string $approval='تمام',string $kind='image'): int {
    $peer='US.media-handoff-fixture'; $conversation=addConversation($peer);
    insertMessage($conversation,'wamid.media-handoff-request','inbound',
        'اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز 1 كيلو فسيخ.',1,null,$peer);
    insertMessage($conversation,'wamid.media-handoff-final','outbound',
        'تأكيد طلب عميل مثال: 1 كيلو فسيخ إلى شارع المثال المنصوره.',2,null,$peer);
    if ($approval!==null) insertMessage($conversation,'wamid.media-handoff-approval','inbound',$approval,3,null,$peer);
    mediaHandoffNative($conversation,'wamid.media-handoff-latest',4,$caption,$kind);
    MediaHandoffAi::$data=extractionData(); MediaHandoffAi::$data['approximate_total']=null;
    MediaHandoffAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001',
        'address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    MediaHandoffAi::$data['branch_hint']=null; MediaHandoffAi::$data['issues']=['MISSING_BRANCH','LOCATION_REQUIRED'];
    MediaHandoffAi::$data['evidence']=['request_ids'=>['m1'],'confirmation_ids'=>['m2'],
        'customer_acceptance_ids'=>$approval===null?[]:['m3'],'location_id'=>null];
    return $conversation;
}
function mediaHandoffNoWrites(string $label): void {
    mediaHandoffExpect(count(FollowupFixtureReplies::$sent)===0&&MediaHandoffDB::table('test_tickets')->count()===0
        &&MediaHandoffDB::table('branch_customers')->count()===0,$label);
}
function mediaHandoffAlterNative(int $conversation,callable $alter): void {
    $row=MediaHandoffDB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
        ->where('direction','inbound')->orderByDesc('id')->first();
    $dto=json_decode(MediaHandoffCrypt::decryptString($row->content),true,64,JSON_THROW_ON_ERROR);
    $alter($dto);
    MediaHandoffDB::table('whatsapp_inbox_messages')->where('id',$row->id)
        ->update(['content'=>MediaHandoffCrypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
}
function mediaHandoffCachedPlan(int $conversation): array {
    global $workflow;
    $workflow->analyze($conversation,(object)['id'=>7]);
    $cached=MediaHandoffDB::table('whatsapp_order_drafts')->first();
    $reason=null; $review=(new ReflectionMethod($workflow,'automaticReview'))->invokeArgs($workflow,
        [$cached,(object)['id'=>7],&$reason]);
    mediaHandoffExpect($review===null&&$reason==='LOCATION_UNCONFIRMED','native image cached-plan needs actual point');
    (new ReflectionMethod($workflow,'reviewReason'))->invoke($workflow,$cached,$reason);
    $plan=(new ReflectionMethod($workflow,'missingInformationPlan'))->invoke($workflow,(int)$cached->id,(object)['id'=>7]);
    mediaHandoffExpect(is_array($plan)&&$plan['fields']===['LOCATION'],'actual workflow constructs bounded LOCATION plan');
    return $plan;
}

followupSetup(); $conversation=mediaHandoffThread();
$snapshot=handoffSnapshot($conversation); $checked=MediaHandoffExtraction::validateDetailed(MediaHandoffAi::$data,$snapshot['transcript']);
mediaHandoffExpect($checked['ok']&&$checked['data']['decision']==='CONFIRMED'
    &&$snapshot['transcript'][3]['text']===null&&$snapshot['transcript'][3]['location']===null,
    'native captionless image does not change grounded confirmed extraction or supply coordinates');
$analyzed=$workflow->analyze($conversation,(object)['id'=>7]);
$cached=MediaHandoffDB::table('whatsapp_order_drafts')->first();
$reason=null; $review=(new ReflectionMethod($workflow,'automaticReview'))->invokeArgs($workflow,
    [$cached,(object)['id'=>7],&$reason]);
mediaHandoffExpect($review===null&&$reason==='LOCATION_UNCONFIRMED',
    'normal automatic review independently identifies missing verified point');
(new ReflectionMethod($workflow,'reviewReason'))->invoke($workflow,$cached,$reason);
$cached=MediaHandoffDB::table('whatsapp_order_drafts')->first();
mediaHandoffExpect($cached->status==='REVIEW'&&$cached->reason==='LOCATION_UNCONFIRMED',
    'actual analyzed cached image order is held for missing verified point');
$cipher=$cached->extraction; $ceiling=$cached->evidence_ceiling; $calls=MediaHandoffAi::$calls;
$result=followupRun();
mediaHandoffExpect($result['review_required']===1&&count(FollowupFixtureReplies::$sent)===1
    &&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],
    'cached confirmed image ending gets exactly one native location question');
mediaHandoffExpect(MediaHandoffAi::$calls===$calls&&MediaHandoffDB::table('whatsapp_order_drafts')->count()===1
    &&MediaHandoffDB::table('whatsapp_order_drafts')->first()->id===$cached->id
    &&MediaHandoffDB::table('whatsapp_order_drafts')->first()->extraction===$cipher
    &&MediaHandoffDB::table('whatsapp_order_drafts')->first()->evidence_ceiling===$ceiling,
    'cached image handoff keeps same sealed facts and draft without another extraction');
mediaHandoffExpect(MediaHandoffDB::table('test_tickets')->count()===0&&MediaHandoffDB::table('branch_customers')->count()===0
    &&MediaHandoffDB::table('test_print')->count()===0,'image is only handoff context and writes no ERP or customer point');
MediaHandoffDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $result=followupRun();
mediaHandoffExpect($result['deferred']===1&&MediaHandoffAi::$calls===$calls&&count(FollowupFixtureReplies::$sent)===1,
    'accepted authenticated question waits and image-ending replay does not repeat question');
insertMessage($conversation,'wamid.media-handoff-customer-pin','inbound','',301,
    ['lat'=>31.00,'long'=>31.40],'US.media-handoff-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
MediaHandoffAi::$data['evidence']['location_id']='m6'; MediaHandoffAi::$data['issues']=['MISSING_BRANCH'];
mediaHandoffExpect(MediaHandoffExtraction::validateDetailed(MediaHandoffAi::$data,handoffSnapshot($conversation)['transcript'])['ok'],
    'actual native pin answer passes unchanged validator with earlier merchant recap/approval');
MediaHandoffDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $result=followupRun();
mediaHandoffExpect($result['dispatched']===1&&MediaHandoffAi::$calls===$calls+1
    &&MediaHandoffDB::table('test_tickets')->count()===1
    &&MediaHandoffDB::table('test_tickets')->first()->branch==='f:363',
    'later native pin resumes normal dispatch to exactly one approved branch order');
mediaHandoffExpect(MediaHandoffDB::table('test_print')->count()===1
    &&MediaHandoffDB::table('test_tickets')->first()->payment_status==='unpaid'
    &&MediaHandoffDB::table('test_billing')->count()===0,'image handoff completion prints once and collects no payment');
followupRun(); mediaHandoffExpect(MediaHandoffDB::table('test_tickets')->count()===1
    &&count(FollowupFixtureReplies::$sent)===1,'completed image handoff replay creates no duplicate ERP or question');

foreach ([null,''] as $caption) {
    followupSetup(); $conversation=mediaHandoffThread($caption,null);
    $checked=MediaHandoffExtraction::validateDetailed(MediaHandoffAi::$data,handoffSnapshot($conversation)['transcript']);
    mediaHandoffExpect($checked['ok']&&$checked['data']['decision']==='CONFIRMED'
        &&MediaHandoffAi::$data['evidence']['customer_acceptance_ids']===[],
        'committing request and native final recap remain confirmed without extra approval or image interpretation');
    followupRun();
    mediaHandoffExpect(count(FollowupFixtureReplies::$sent)===1&&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION']
        &&MediaHandoffDB::table('test_tickets')->count()===0&&MediaHandoffDB::table('branch_customers')->count()===0,
        'absent or exact empty caption permits asking missing point without promoting image into consent');
}
foreach (['تمام','مش موافق','غير الطلب','عنواني مكان آخر','اللوكيشن ده',' ','تمام؟'] as $caption) {
    followupSetup(); $conversation=mediaHandoffThread($caption);
    $snapshot=handoffSnapshot($conversation);
    mediaHandoffExpect($snapshot['transcript'][3]['text']===null
        &&MediaHandoffExtraction::validateDetailed(MediaHandoffAi::$data,$snapshot['transcript'])['ok'],
        'raw caption is absent from otherwise valid order transcript '.$caption);
    followupRun(); mediaHandoffNoWrites('nonempty raw image caption cannot establish prompt handoff '.$caption);
}
followupSetup(); $conversation=mediaHandoffThread();
mediaHandoffAlterNative($conversation,static function(array &$dto): void { $dto['content']['message']['image']['caption']=null; });
followupRun(); mediaHandoffNoWrites('explicit null caption fails closed rather than behaving as absent caption');
foreach (['audio','video','document'] as $kind) {
    followupSetup(); mediaHandoffThread(null,'تمام',$kind); followupRun();
    mediaHandoffNoWrites('other native media kinds do not borrow image-only handoff '.$kind);
}
followupSetup(); $conversation=mediaHandoffThread();
mediaHandoffNative($conversation,'wamid.media-handoff-second-image',5); followupRun();
mediaHandoffNoWrites('multiple opaque postrecap images remain review without interpreting attachments');
foreach (['مش موافق','تمام بس غير الطلب','تمام، عنواني مكان آخر','قال لي العميل تمام','"تمام"','تمام؟','شكرا'] as $approval) {
    followupSetup(); mediaHandoffThread(null,$approval); followupRun();
    mediaHandoffNoWrites('postrecap refusal/change/quotation/question/courtesy cannot hand off via later image '.$approval);
}
followupSetup(); mediaHandoffThread(); MediaHandoffAi::$data['evidence']['customer_acceptance_ids']=[]; followupRun();
mediaHandoffNoWrites('uncited earlier positive text cannot be bypassed by later captionless image');
followupSetup(); $conversation=mediaHandoffThread(null,null);
insertMessage($conversation,'wamid.media-handoff-pre-summary-approval','inbound','تمام',0,null,'US.media-handoff-fixture');
$request=MediaHandoffDB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('type','text')
    ->where('direction','inbound')->orderBy('id')->first();
$dto=json_decode(MediaHandoffCrypt::decryptString($request->content),true,64,JSON_THROW_ON_ERROR);
$dto['text']=str_replace('عايز 1 كيلو فسيخ.','الصنف 1 كيلو فسيخ.',$dto['text']);
$dto['content']['message']['text']['body']=$dto['text'];
MediaHandoffDB::table('whatsapp_inbox_messages')->where('id',$request->id)->update([
    'content'=>MediaHandoffCrypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
MediaHandoffAi::$data['evidence']=['request_ids'=>['m2'],'confirmation_ids'=>['m3'],
    'customer_acceptance_ids'=>['m1'],'location_id'=>null];
mediaHandoffExpect(!MediaHandoffExtraction::validateDetailed(MediaHandoffAi::$data,handoffSnapshot($conversation)['transcript'])['ok'],
    'pre-summary positive text does not establish genuine committing request or later customer acceptance');
followupRun(); mediaHandoffNoWrites('image cannot lend consent to an uncommitted pre-summary approval episode');
followupSetup(); $conversation=mediaHandoffThread();
insertMessage($conversation,'wamid.media-handoff-later-data','inbound','الاسم عميل آخر',5,null,'US.media-handoff-fixture'); followupRun();
mediaHandoffNoWrites('later customer data makes image no longer latest and blocks cached handoff');
followupSetup(); mediaHandoffThread(); MediaHandoffAi::$data['decision']='DRAFT'; MediaHandoffAi::$data['issues'][]='UNCONFIRMED'; followupRun();
mediaHandoffNoWrites('captionless image cannot turn unconfirmed DRAFT into confirmed customer handoff');
followupSetup(); mediaHandoffThread(); app('config')->set('whatsapp_cart.activated_at','2026-10-09 22:00:05'); followupRun();
mediaHandoffNoWrites('historical preactivation image/request/recap cannot wake autonomous followup');
followupSetup(); mediaHandoffThread(); MediaHandoffDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
$result=followupRun(); mediaHandoffExpect($result['capture_pending']===1&&MediaHandoffAi::$calls===0,
    'pending raw capture blocks image handoff before extraction');
mediaHandoffNoWrites('pending capture leaves image order unsent and ERP untouched');
followupSetup(); $conversation=mediaHandoffThread();
insertMessage($conversation,'wamid.media-handoff-probe','inbound','WA-1234',5,null,'US.media-handoff-fixture'); followupRun();
mediaHandoffExpect(MediaHandoffDB::table('whatsapp_order_drafts')->first()->status==='NONE',
    'probe-normalized NONE cannot borrow raw CONFIRMED or earlier image handoff');
mediaHandoffNoWrites('operational probe episode sends no customer followup or ERP command');
foreach (['','bad id',str_repeat('x',513),"bad\nimage"] as $imageId) {
    followupSetup(); $conversation=mediaHandoffThread();
    mediaHandoffAlterNative($conversation,static function(array &$dto) use ($imageId): void { $dto['content']['message']['image']['id']=$imageId; });
    followupRun(); mediaHandoffNoWrites('missing/oversized/control/whitespace image identifier cannot establish native media handoff');
}
followupSetup(); $conversation=mediaHandoffThread();
mediaHandoffAlterNative($conversation,static function(array &$dto): void { $dto['content']['message']['timestamp']='0'; });
followupRun(); mediaHandoffNoWrites('raw timestamp mismatch fails native authentication despite unchanged text snapshot');
followupSetup(); $conversation=mediaHandoffThread();
mediaHandoffAlterNative($conversation,static function(array &$dto): void { $dto['content']['message']['from']='201000000002'; });
followupRun(); mediaHandoffNoWrites('raw peer mismatch fails native authentication despite unchanged text snapshot');
followupSetup(); $conversation=mediaHandoffThread();
mediaHandoffAlterNative($conversation,static function(array &$dto): void { $dto['source']='standby'; });
MediaHandoffDB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('type','image')->update(['source'=>'standby']);
followupRun(); mediaHandoffExpect(count(FollowupFixtureReplies::$sent)===1
    &&FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION']&&MediaHandoffDB::table('test_tickets')->count()===0,
    'properly authenticated standby native image uses same bounded question handoff');
followupSetup(); $conversation=mediaHandoffThread();
FollowupFixtureReplies::$beforeSend=static fn()=>insertMessage($conversation,'wamid.media-handoff-during-style',
    'inbound','مش موافق',301,null,'US.media-handoff-fixture');
followupRun(); mediaHandoffNoWrites('fresh customer refusal during styling invalidates exact image handoff before send');
followupSetup(); $conversation=mediaHandoffThread(); $beforeSnapshot=handoffSnapshot($conversation);
FollowupFixtureReplies::$beforeSend=static function() use ($conversation): void {
    mediaHandoffAlterNative($conversation,static function(array &$dto): void {
        $dto['content']['message']['image']['caption']='مش موافق';
    });
};
followupRun();
mediaHandoffExpect(handoffSnapshot($conversation)['hash']===$beforeSnapshot['hash'],
    'raw caption mutation leaves semantic text snapshot unchanged and must be checked natively');
mediaHandoffNoWrites('fresh raw caption during styling invalidates native handoff proof before send');

foreach (['CLAIMED','UNKNOWN','FAILED'] as $state) {
    followupSetup(); $conversation=mediaHandoffThread(); $plan=mediaHandoffCachedPlan($conversation);
    $uuid=MediaHandoffService::requestUuid($plan,MediaHandoffCrypt::getKey());
    $stamp=now('UTC')->format('Y-m-d H:i:s');
    MediaHandoffDB::table('whatsapp_reply_requests')->insert(['conversation_id'=>$conversation,'actor_id'=>7,
        'waba_id'=>MediaHandoffAccess::WABA_ID,'phone_number_id'=>MediaHandoffAccess::PHONE_ID,
        'request_hash'=>hash_hmac('sha256','whatsapp-reply-request-v1:7:'.$conversation.':'.$uuid,MediaHandoffCrypt::getKey()),
        'payload_hash'=>str_repeat('a',64),'inbound_message_id'=>$plan['latest_customer_id'],
        'kind'=>'text','state'=>$state,'audit'=>MediaHandoffCrypt::encryptString(json_encode(['client_request_id'=>$uuid])),
        'claimed_at'=>$stamp,'created_at'=>$stamp,'updated_at'=>$stamp]);
    $calls=MediaHandoffAi::$calls; followupRun();
    mediaHandoffExpect(MediaHandoffAi::$calls===$calls&&FollowupFixtureProvider::$styles===0,
        'existing durable '.$state.' tombstone blocks image retry before AI composition');
    mediaHandoffNoWrites('image handoff never repeats tombstoned customer question '.$state);
}

followupSetup(); $conversation=mediaHandoffThread(); $plan=mediaHandoffCachedPlan($conversation);
$mediaGuard=new ReflectionMethod(MediaHandoffService::class,'confirmedMediaQuestionHandoff');
$service=app(MediaHandoffService::class);
$confirmationPlan=$plan; $confirmationPlan['fields']=['CONFIRMATION'];
mediaHandoffExpect(!$mediaGuard->invoke($service,$confirmationPlan),
    'media path cannot establish confirmation handoff even with unchanged valid CONFIRMED extraction');
$wrongIdPlan=$plan; $wrongIdPlan['latest_customer_id']--;
mediaHandoffExpect(!$mediaGuard->invoke($service,$wrongIdPlan),'actual image row ID must match customer plan');
$wrongKeyPlan=$plan; $wrongKeyPlan['latest_customer_key']=str_repeat('f',64);
mediaHandoffExpect(!$mediaGuard->invoke($service,$wrongKeyPlan),'actual image native key must match customer plan');

followupSetup(); $conversation=followupComplete();
MediaHandoffAi::$data['decision']='DRAFT'; MediaHandoffAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
MediaHandoffAi::$data['approximate_total']=null; followupRun();
mediaHandoffExpect(count(FollowupFixtureReplies::$sent)===1&&FollowupFixtureReplies::$sent[0]['marker']['fields']===['CONFIRMATION'],
    'normal own factual recap is receipt-bound before testing an opaque later image');
insertMessage($conversation,'wamid.media-handoff-own-recap-approval','inbound','موافق',301,null,'US.followup-fixture');
mediaHandoffNative($conversation,'wamid.media-handoff-own-recap-image',302,null,'image','US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
MediaHandoffAi::$data['decision']='CONFIRMED'; MediaHandoffAi::$data['issues']=['MISSING_BRANCH'];
MediaHandoffAi::$data['evidence']['confirmation_ids']=['m4']; MediaHandoffAi::$data['evidence']['customer_acceptance_ids']=['m5'];
mediaHandoffExpect((new ReflectionMethod($workflow,'projectAcceptedReplies'))->invoke($workflow,$conversation,'2026-10-09 22:00:00'),
    'actual accepted own recap is projected before its evidence citation is validated');
mediaHandoffExpect(MediaHandoffExtraction::validateDetailed(MediaHandoffAi::$data,handoffSnapshot($conversation)['transcript'])['ok'],
    'image after own recap and genuine approval does not rewrite unchanged extraction validator');
MediaHandoffDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
mediaHandoffExpect(count(FollowupFixtureReplies::$sent)===2&&FollowupFixtureReplies::$sent[1]['marker']['fields']===['CONFIRMATION']
    &&MediaHandoffDB::table('test_tickets')->count()===0
    &&MediaHandoffDB::table('branch_customers')->count()===0&&MediaHandoffDB::table('test_print')->count()===0,
    'opaque image remains unacceptable post-own-recap consent; existing owned-recap path asks fresh approval without ERP');

\Carbon\Carbon::setTestNow();
echo 'WHATSAPP_ORDER_MEDIA_HANDOFF_WORKFLOW_TESTS='.$mediaHandoffChecks." PASS\n";
