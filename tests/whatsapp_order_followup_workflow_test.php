<?php

// Real SQLite workflow, extraction validation, receipt projection and followup policy.
// The reply boundary below simulates accepted transport only; no external message is sent.
require __DIR__.'/whatsapp_order_address_branch_workflow_test.php';
require_once dirname(__DIR__).'/app/Services/Dashboard/WhatsAppReplyService.php';
require_once dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderFollowupService.php';

use App\Services\Dashboard\WhatsAppOrderFollowupService as Followup;
use App\Services\Dashboard\WhatsAppReplyService as Replies;
use App\Services\Dashboard\WhatsAppOrderAiProvider as FollowupAi;
use App\Services\Dashboard\WhatsAppInboxAccess as FollowupAccess;
use App\Support\WhatsAppOrderExtraction as FollowupExtraction;
use Illuminate\Support\Facades\DB as FollowupDB;
use Illuminate\Support\Facades\Crypt as FollowupCrypt;

$followupChecks=0;
function followupExpect($condition,string $label): void {
    $GLOBALS['followupChecks']++;
    if (!$condition) throw new RuntimeException('FOLLOWUP_WORKFLOW_FAILED:'.$label);
}
class FollowupFixtureProvider extends FollowupAi {
    public static $styles=0;
    public function followupVariant(array $fields): array { self::$styles++; return ['ok'=>true,'variant'=>'SHORT']; }
}
class FollowupFixtureReplies extends Replies {
    public static $sent=[];
    public static $beforeSend;
    public function __construct() {}
    public function state($actor,int $conversation,?string $clientRequestId=null): array {
        $latest=FollowupDB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')
            ->orderByDesc('sent_at')->orderByDesc('id')->first();
        return ['can_reply'=>true,'latest_inbound_id'=>(int)$latest->id];
    }
    public function orderFollowupState($actor,int $conversation,string $uuid,int $inbound): ?array {
        foreach (FollowupDB::table('whatsapp_reply_requests')->where('conversation_id',$conversation)->get() as $row) {
            $audit=json_decode(FollowupCrypt::decryptString($row->audit),true);
            if (($audit['client_request_id']??null)===$uuid)
                return ['state'=>$row->state,'reply_id'=>(int)$row->id,'replayed'=>true];
        }
        return null;
    }
    public function sendOrderFollowup($actor,int $conversation,array $input,callable $guard): array {
        if (!$guard()) return ['state'=>'FAILED'];
        $text=($input['_compose'])();
        if (self::$beforeSend) (self::$beforeSend)();
        if (!$guard()) return ['state'=>'FAILED'];
        $uuid=$input['client_request_id']; $inbound=$input['expected_inbound_id']; $marker=$input['_followup'];
        followupExpect(Followup::validMarker($marker,$conversation,$uuid,$inbound),'actual marker binds stable customer input');
        $scope=FollowupAccess::WABA_ID.':'.FollowupAccess::PHONE_ID.':'; $key=FollowupCrypt::getKey();
        $values=['text',$inbound,$text,null];
        $audit=['client_request_id'=>$uuid,'text'=>$text,'recipient'=>'201000000001','payload_values'=>$values,'order_followup'=>$marker];
        $native='wamid.followup-fixture.'.(FollowupDB::table('whatsapp_reply_requests')->max('id')+1);
        $details=FollowupCrypt::encryptString(json_encode(['message_id'=>$native])); $stamp=now('UTC')->format('Y-m-d H:i:s');
        $id=FollowupDB::table('whatsapp_reply_requests')->insertGetId(['conversation_id'=>$conversation,'actor_id'=>$actor->id,
            'waba_id'=>FollowupAccess::WABA_ID,'phone_number_id'=>FollowupAccess::PHONE_ID,
            'request_hash'=>hash_hmac('sha256','whatsapp-reply-request-v1:'.$actor->id.':'.$conversation.':'.$uuid,$key),
            'payload_hash'=>hash_hmac('sha256','whatsapp-reply-payload-v1:'.json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$key),
            'inbound_message_id'=>$inbound,'kind'=>'text','state'=>'ACCEPTED','audit'=>FollowupCrypt::encryptString(json_encode($audit)),
            'response_details'=>$details,'remote_message_key'=>hash('sha256','whatsapp-reply-message-v1:'.$scope.$native),
            'claimed_at'=>$stamp,'send_started_at'=>$stamp,'completed_at'=>$stamp,'created_at'=>$stamp,'updated_at'=>$stamp]);
        followupExpect((new \App\Services\Dashboard\WhatsAppInboxMessage)->indexReply(
            FollowupDB::table('whatsapp_reply_requests')->where('id',$id)->first()),'actual accepted reply index proves synthetic transport receipt');
        self::$sent[]=['conversation'=>$conversation,'text'=>$text,'marker'=>$marker];
        return ['state'=>'ACCEPTED','reply_id'=>$id,'replayed'=>false];
    }
}
function followupSetup(): void {
    addressFlowSetup(); \Carbon\Carbon::setTestNow('2026-10-09 22:05:00');
    FollowupDB::table('whatsapp_inbox_ingestion_failures')->delete();
    FollowupDB::table(\App\Services\Dashboard\WhatsAppInboxMessage::INDEX)->delete();
    FollowupFixtureReplies::$sent=[]; FollowupFixtureReplies::$beforeSend=null; FollowupFixtureProvider::$styles=0;
    app()->instance(Followup::class,new Followup(new FollowupFixtureReplies,new FollowupFixtureProvider));
}
function followupDraft(string $request='عايز 1 كيلو فسيخ',string $business='تمام، نكمل بيانات الطلب.',string $suffix=''): int {
    $peer='US.followup-fixture'.$suffix; $c=addConversation($peer);
    insertMessage($c,'wamid.followup-request'.$suffix,'inbound',$request,1,null,$peer);
    insertMessage($c,'wamid.followup-business'.$suffix,'outbound',$business,2,null,$peer);
    FollowupAi::$data=['decision'=>'DRAFT','customer'=>['name'=>null,'phone'=>null,'address'=>null,'area'=>null,'notes'=>null],
        'branch_hint'=>null,'fulfillment'=>'UNKNOWN','items'=>[['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null]],
        'approximate_total'=>null,'evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>[],'customer_acceptance_ids'=>[],'location_id'=>null],
        'issues'=>['MISSING_CUSTOMER_NAME','MISSING_PHONE','MISSING_ADDRESS','MISSING_BRANCH','UNCONFIRMED']];
    return $c;
}
function followupRun(): array {
    global $workflow;
    $config=app('config')->all(); $result=$workflow->process();
    followupExpect($result['errors']===0,'followup keeps processing errors at zero');
    followupExpect(app('config')->all()===$config,'followup leaves activation/configuration unchanged');
    return $result;
}
function followupComplete(bool $pin=true): int {
    $c=addConversation('US.followup-fixture');
    insertMessage($c,'wamid.followup-complete-request','inbound',
        'اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز 1 كيلو فسيخ.',1,null,'US.followup-fixture');
    insertMessage($c,'wamid.followup-complete-point','inbound',$pin?'':'تمام',2,$pin?['lat'=>31.00,'long'=>31.40]:null,'US.followup-fixture');
    insertMessage($c,'wamid.followup-complete-business','outbound',
        'تأكيد طلب عميل مثال: 1 كيلو فسيخ إلى شارع المثال المنصوره، الإجمالي 100.00 جنيه.',3,null,'US.followup-fixture');
    FollowupAi::$data=extractionData();
    FollowupAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
    FollowupAi::$data['branch_hint']=null; FollowupAi::$data['issues']=['MISSING_BRANCH'];
    FollowupAi::$data['evidence']['location_id']=$pin?'m2':null;
    return $c;
}

followupSetup(); $c=followupDraft(); $p=followupRun();
followupExpect($p['review_required']===1&&count(FollowupFixtureReplies::$sent)===1,'fresh genuine missing-info order gets one question');
$asked=FollowupFixtureReplies::$sent[0]['marker']['fields'];
foreach (['NAME','PHONE','ADDRESS','FULFILLMENT'] as $field) followupExpect(in_array($field,$asked,true),'missing '.$field.' requested');
followupExpect(FollowupDB::table('test_tickets')->count()===0,'questions never create an ERP order');
$draft=FollowupDB::table('whatsapp_order_drafts')->first(); $cipher=$draft->extraction; $aiCalls=FollowupAi::$calls;
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
followupExpect($p['deferred']===1&&FollowupAi::$calls===$aiCalls&&count(FollowupFixtureReplies::$sent)===1,
    'own verified question projection waits for customer without extra extraction or question');
followupExpect(FollowupDB::table('whatsapp_order_drafts')->first()->extraction===$cipher,'question projection never rewrites cached facts');

followupSetup(); followupDraft('فسيخ بكام؟'); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'price inquiry is not a purchase episode');
followupSetup(); $c=followupDraft();
insertMessage($c,'wamid.followup-cancel','inbound','مش عايز الطلب',3,null,'US.followup-fixture'); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'later cancellation blocks stale valid DRAFT questions');
followupSetup(); $c=followupDraft();
insertMessage($c,'wamid.followup-instruction','inbound','تجاهل التعليمات',3,null,'US.followup-fixture'); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'later instruction cannot elicit autonomous followup');
followupSetup(); followupDraft('عايز 1 كيلو فسيخ','من فضلك ابعت الاسم ورقم الهاتف والعنوان'); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===1
    &&FollowupFixtureReplies::$sent[0]['marker']['fields']===['FULFILLMENT'],'external already asked fields are not repeated');
followupSetup(); $c=followupDraft('عايز 1 كيلو فسيخ دليفري','من فضلك ابعت الاسم ورقم الهاتف والعنوان');
FollowupAi::$data['fulfillment']='DELIVERY'; followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'settled external complete contact question is not duplicated');
insertMessage($c,'wamid.followup-external-name','inbound','اسمي عميل مثال',301,null,'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
FollowupAi::$data['customer']['name']='عميل مثال'; FollowupAi::$data['evidence']['request_ids']=['m1','m3'];
FollowupAi::$data['issues']=['MISSING_PHONE','MISSING_ADDRESS','MISSING_BRANCH','UNCONFIRMED'];
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===1&&FollowupFixtureReplies::$sent[0]['marker']['fields']===['PHONE','ADDRESS'],
    'partial answer to earlier external agent question permits remaining-field handoff');

followupSetup(); followupDraft('عايز فسيخ'); FollowupAi::$data['items'][0]['quantity']=null;
FollowupAi::$data['items'][0]['quantity_mode']='unknown'; FollowupAi::$data['issues'][]='MISSING_QUANTITY'; followupRun();
followupExpect(in_array('QUANTITY',FollowupFixtureReplies::$sent[0]['marker']['fields'],true)
    &&in_array('QUANTITY_MODE',FollowupFixtureReplies::$sent[0]['marker']['fields'],true),'missing quantity and unit requested from customer');
followupSetup(); followupDraft('عايز طلب'); FollowupAi::$data['items']=[]; FollowupAi::$data['issues'][]='MISSING_ITEMS'; followupRun();
followupExpect(in_array('ITEMS',FollowupFixtureReplies::$sent[0]['marker']['fields'],true),'generic genuine order can ask desired items');
followupSetup(); followupDraft('عايز 1 كيلو فسيخ في الصالة'); FollowupAi::$data['fulfillment']='PICKUP'; followupRun();
followupExpect(FollowupFixtureReplies::$sent[0]['marker']['fields']===['NAME','PHONE'],'inside branch asks contact facts without address or pin');

followupSetup(); followupComplete(false); followupRun();
followupExpect(FollowupFixtureReplies::$sent[0]['marker']['fields']===['LOCATION'],'confirmed order with no usable point asks native location');
followupSetup(); followupComplete(); FollowupAi::$data['evidence']['location_id']=null; followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'existing actual pin with omitted model citation is not requested again');
followupSetup(); followupComplete(false);
FollowupDB::table('test_branches')->where('id',363)->update(['status'=>'closed']); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'closed branch is not a missing-customer-info question');
followupSetup(); followupComplete();
\App\Services\Dashboard\TakeawayCatalog::$ambiguous=true; followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===0,'ERP catalog ambiguity stays review without customer question');

followupSetup(); followupComplete();
FollowupAi::$data['decision']='DRAFT'; FollowupAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
FollowupAi::$data['approximate_total']=null;
$original=FollowupAi::$data; followupRun();
followupExpect(FollowupFixtureReplies::$sent[0]['marker']['fields']===['CONFIRMATION'],'complete genuine DRAFT gets actual factual approval recap');
followupExpect(FollowupAi::$data===$original&&FollowupDB::table('test_tickets')->count()===0,'recap does not force extraction decision or ERP');
$calls=FollowupAi::$calls; FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
followupExpect($p['deferred']===1&&FollowupAi::$calls===$calls,'owned recap alone waits for customer');
insertMessage((int)FollowupDB::table('whatsapp_inbox_conversations')->first()->id,'wamid.followup-approval','inbound','موافق',301,null,'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
FollowupAi::$data['decision']='CONFIRMED'; FollowupAi::$data['issues']=['MISSING_BRANCH'];
FollowupAi::$data['evidence']['confirmation_ids']=['m4']; FollowupAi::$data['evidence']['customer_acceptance_ids']=['m5'];
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
followupExpect($p['dispatched']===1&&FollowupDB::table('test_tickets')->count()===1,'real later approved recap passes unchanged extraction and dispatch gates');
followupExpect(FollowupDB::table('test_print')->count()===1&&FollowupDB::table('test_tickets')->first()->payment_status==='unpaid',
    'approved dialogue creates one unpaid order and kitchen print');
followupRun(); followupExpect(FollowupDB::table('test_tickets')->count()===1,'completed dialogue replay remains idempotent');

followupSetup(); $c=followupComplete();
FollowupAi::$data['decision']='DRAFT'; FollowupAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
FollowupAi::$data['approximate_total']=null; followupRun();
insertMessage($c,'wamid.followup-not-approval','inbound','شكرا',301,null,'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
FollowupAi::$data['decision']='CONFIRMED'; FollowupAi::$data['issues']=['MISSING_BRANCH'];
FollowupAi::$data['evidence']['confirmation_ids']=['m4'];
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
followupExpect($p['review_required']===1&&FollowupDB::table('test_tickets')->count()===0
    &&FollowupDB::table('branch_customers')->count()===0,'model CONFIRMED plus earlier commitment cannot bypass real post-own-recap approval');
followupExpect(count(FollowupFixtureReplies::$sent)===2&&FollowupFixtureReplies::$sent[1]['marker']['fields']===['CONFIRMATION'],
    'fresh nonapproval input can request approval again without forcing decision or creating ERP');

followupSetup(); $c=followupDraft();
FollowupFixtureReplies::$beforeSend=static fn()=>insertMessage($c,'wamid.followup-during-composition','inbound','مش عايز الطلب',301,null,'US.followup-fixture');
followupRun();
followupExpect(FollowupFixtureProvider::$styles===1&&count(FollowupFixtureReplies::$sent)===0
    &&FollowupDB::table('whatsapp_reply_requests')->count()===0&&FollowupDB::table('test_tickets')->count()===0,
    'new customer input during composition invalidates exact live plan before accepted send');
followupSetup(); followupDraft();
FollowupFixtureReplies::$beforeSend=static fn()=>FollowupDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
followupRun(); followupExpect(count(FollowupFixtureReplies::$sent)===0&&FollowupDB::table('test_tickets')->count()===0,
    'new pending raw event invalidates customer followup before accepted send');

// An explicitly mapped native cart proves quantity/unit without a redundant quantity question.
followupSetup(); $c=addConversation();
insertMessage($c,'wamid.followup-cart-contact','inbound',
    'اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصوره. عايز فسيخ من الكتالوج.',1);
insertCartMessage($c,'wamid.followup-cart-order',2,[['product_retailer_id'=>'half-fish','quantity'=>2,'item_price'=>'45.00','currency'=>'EGP']]);
insertMessage($c,'wamid.followup-cart-point','inbound','',3,['lat'=>31.00,'long'=>31.40]);
insertMessage($c,'wamid.followup-cart-business','outbound','تمام، نكمل بيانات الطلب.',4);
FollowupAi::$data=extractionData(); FollowupAi::$data['decision']='DRAFT';
FollowupAi::$data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصوره','area'=>'المنصوره','notes'=>null];
FollowupAi::$data['branch_hint']=null; FollowupAi::$data['approximate_total']=null;
FollowupAi::$data['issues']=['UNCONFIRMED','MISSING_BRANCH'];
FollowupAi::$data['items'][0]['cart_reference']=['message_id'=>'m2','product_retailer_id'=>'half-fish'];
FollowupAi::$data['evidence']=['request_ids'=>['m1','m2'],'confirmation_ids'=>[],'customer_acceptance_ids'=>[],'location_id'=>'m3'];
app('config')->set('whatsapp_cart.product_mappings',['999'=>['half-fish'=>['f:363'=>[
    'product_id'=>11,'quantity_mode'=>'weight','quantity_per_unit'=>'0.5','option_id'=>'']]]]);
followupRun(); followupExpect(count(FollowupFixtureReplies::$sent)===1
    &&FollowupFixtureReplies::$sent[0]['marker']['fields']===['CONFIRMATION'],
    'approved native cart mapping supplies bounded quantity/unit and permits actual factual recap');

// A real sequence of new customer inputs completes a partial order without fabricated evidence.
followupSetup(); $c=followupDraft(); followupRun();
insertMessage($c,'wamid.followup-name','inbound','اسمي عميل مثال',301,null,'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:06:00');
FollowupAi::$data['customer']['name']='عميل مثال';
FollowupAi::$data['evidence']['request_ids']=['m1','m4'];
FollowupAi::$data['issues']=['MISSING_PHONE','MISSING_ADDRESS','MISSING_BRANCH','UNCONFIRMED'];
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===2
    &&FollowupFixtureReplies::$sent[1]['marker']['fields']===['PHONE','ADDRESS','FULFILLMENT'],
    'partial name answer continues with only remaining contact/address/mode facts');
insertMessage($c,'wamid.followup-contact-address','inbound',
    'رقمي 201000000001 وعنواني شارع المثال المنصوره',361,null,'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:07:00');
FollowupAi::$data['customer']['phone']='201000000001';
FollowupAi::$data['customer']['address']='شارع المثال المنصوره';
FollowupAi::$data['customer']['area']='المنصوره';
FollowupAi::$data['evidence']['request_ids']=['m1','m4','m6'];
FollowupAi::$data['issues']=['MISSING_BRANCH','UNCONFIRMED'];
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===3
    &&FollowupFixtureReplies::$sent[2]['marker']['fields']===['LOCATION'],
    'grounded contact plus address derives delivery/branch and asks only missing native pin');
followupExpect(FollowupAi::$data['fulfillment']==='UNKNOWN'&&FollowupAi::$data['decision']==='DRAFT',
    'planning local delivery mode never rewrites authoritative extraction');
insertMessage($c,'wamid.followup-new-point','inbound','',421,['lat'=>31.00,'long'=>31.40],'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:08:00');
FollowupAi::$data['evidence']['location_id']='m8';
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
followupExpect(count(FollowupFixtureReplies::$sent)===4
    &&FollowupFixtureReplies::$sent[3]['marker']['fields']===['CONFIRMATION']
    &&strpos(FollowupFixtureReplies::$sent[3]['text'],'دليفري')!==false,
    'new native pin resumes DRAFT and exact factual recap uses owner-derived delivery mode');
followupExpect(FollowupDB::table('test_tickets')->count()===0
    &&FollowupDB::table('branch_customers')->count()===0,'all questions and unapproved recap remain outside ERP');
$calls=FollowupAi::$calls; FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); followupRun();
followupExpect(FollowupAi::$calls===$calls&&count(FollowupFixtureReplies::$sent)===4,
    'full dialogue own recap waits without another AI extraction or duplicate question');
insertMessage($c,'wamid.followup-final-approval','inbound','تمام موافق',481,null,'US.followup-fixture');
\Carbon\Carbon::setTestNow('2026-10-09 22:09:00');
FollowupAi::$data['decision']='CONFIRMED'; FollowupAi::$data['fulfillment']='DELIVERY';
FollowupAi::$data['issues']=['MISSING_BRANCH'];
FollowupAi::$data['evidence']['confirmation_ids']=['m9'];
FollowupAi::$data['evidence']['customer_acceptance_ids']=['m10'];
followupExpect(FollowupExtraction::validateDetailed(FollowupAi::$data,
    (new ReflectionMethod($workflow,'snapshot'))->invoke($workflow,$c,0,
        (int)FollowupDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id'))['transcript'])['ok'],
    'final factual recap and later positive acceptance pass unchanged pure extraction validator');
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
followupExpect($p['dispatched']===1&&FollowupDB::table('test_tickets')->count()===1
    &&FollowupDB::table('test_tickets')->first()->branch==='f:363','full missing-information dialogue dispatches one selected-branch order');
followupExpect(FollowupDB::table('test_print')->count()===1&&FollowupDB::table('test_tickets')->first()->payment_status==='unpaid'
    &&FollowupDB::table('test_billing')->count()===0,'completed missing-info dialogue prints once without collecting payment');
followupRun(); followupExpect(FollowupDB::table('test_tickets')->count()===1
    &&count(FollowupFixtureReplies::$sent)===4,'completed missing-info dialogue does not replay sends or ERP');

// A full waiting batch releases queue slots without advancing any analyzed evidence cutoff.
followupSetup(); for ($i=0;$i<10;$i++) followupDraft('عايز 1 كيلو فسيخ','تمام، نكمل بيانات الطلب.','-queue-'.$i);
followupRun(); followupExpect(count(FollowupFixtureReplies::$sent)===10,'full batch gets one real question per customer input');
followupDraft('عايز 1 كيلو فسيخ','تمام، نكمل بيانات الطلب.','-queue-new');
$calls=FollowupAi::$calls; $scans=FollowupDB::table('whatsapp_order_scans')->orderBy('conversation_id')->pluck('analyzed_ceiling','conversation_id')->all();
FollowupDB::table('whatsapp_order_scans')->update(['next_attempt_at'=>null]); $p=followupRun();
followupExpect($p['deferred']===10&&FollowupAi::$calls===$calls,'waiting batch spends no further extraction calls');
followupExpect(FollowupDB::table('whatsapp_order_scans')->orderBy('conversation_id')->pluck('analyzed_ceiling','conversation_id')->all()===$scans,
    'waiting scheduling preserves original analyzed evidence cutoffs');
$p=followupRun(); followupExpect($p['threads_seen']===1&&count(FollowupFixtureReplies::$sent)===11&&FollowupAi::$calls===$calls+1,
    'backoff lets newer conversation proceed on next tick while older customers are unanswered');

\Carbon\Carbon::setTestNow();
echo 'WHATSAPP_ORDER_FOLLOWUP_WORKFLOW_TESTS='.$followupChecks." PASS\n";
