<?php
// Actual native normalization, encrypted SQLite evidence, workflow, live-catalog
// matching and ERP boundaries. Every identity and message below is synthetic.
require __DIR__.'/whatsapp_order_analysis_only_workflow_test.php';
require_once dirname(__DIR__).'/app/Support/WhatsAppCustomerOrderConfirmation.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as CustomerRecapAi;
use App\Services\Dashboard\PhoneDelivery as CustomerRecapDelivery;
use Illuminate\Support\Facades\DB as CustomerRecapDB;
use Illuminate\Support\Facades\Crypt as CustomerRecapCrypt;

$customerRecapChecks=0;
class CustomerRecapRaceDelivery extends CustomerRecapDelivery {
    public static $duringQuote=null;
    public function quote(array $values,$actor):array {
        $result=parent::quote($values,$actor);
        if(self::$duringQuote)(self::$duringQuote)();
        return $result;
    }
}
function customerRecapExpect($ok,string $label):void {
    $GLOBALS['customerRecapChecks']++;
    if(!$ok)throw new RuntimeException('CUSTOMER_RECAP_WORKFLOW_FAILED:'.$label);
}
function customerRecapText(array $changes=[]):string {
    $fields=array_replace(['الاسم'=>'عمر','رقم الهاتف'=>'201000000001','العنوان'=>'شارع البحر',
        'المنطقة'=>'المنصورة','الفرع'=>'المنصورة','الموقع'=>'خط العرض: 31.04، خط الطول: 31.37'],$changes);
    $lines=['ملخص طلبك:','1 كيلو فسيخ – 100 جنيه','الإجمالي: 100 جنيه'];
    foreach($fields as $label=>$value)if($value!==null)$lines[]=$label.': '.$value;
    $lines[]='انسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شيء تمام عشان تأكد طلبك نهائيًا.';
    return implode("\n",$lines);
}
function customerRecapSetup(?string $text=null,?string $approval='كل شيء تمام',bool $pin=false,bool $pinAddressProof=true):int {
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    CustomerRecapRaceDelivery::$duringQuote=null;
    $peer='US.customer-confirmed';$c=addConversation($peer);
    if($pin&&$pinAddressProof)insertMessage($c,'wamid.customer-recap.pin-address','inbound','عنواني شارع البحر',1,null,$peer);
    if($pin)insertMessage($c,'wamid.customer-recap.pin','inbound','',2,['lat'=>31.04,'long'=>31.37],$peer);
    $recapSecond=$pin?3:2;
    insertMessage($c,'wamid.customer-recap.summary','inbound',$text??customerRecapText(),$recapSecond,null,$peer);
    if($approval!==null)insertMessage($c,'wamid.customer-recap.approve','inbound',$approval,$recapSecond+1,null,$peer);
    // An unrelated CONFIRMED extraction must not be able to supply missing recap
    // fields or turn an invalid/customer-only proposal into merchant evidence.
    CustomerRecapAi::$data=extractionData();
    return $c;
}
function customerRecapSilent(string $label):void {
    customerRecapExpect(count($GLOBALS['dialogueGraphs'])===0&&count(DialogueFixtureProvider::$contexts)===0
        &&CustomerRecapDB::table('whatsapp_reply_requests')->count()===0,$label);
}
function customerRecapNoErp(string $label):void {
    primaryNoErp($label);customerRecapSilent($label.'; no automatic question or send');
}
function customerRecapClock(string $utc):void {
    \Carbon\Carbon::setTestNow($utc);\Carbon\CarbonImmutable::setTestNow($utc);
}
function customerRecapStored():array {
    $bytes=CustomerRecapDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('extraction');
    return is_string($bytes)?json_decode(CustomerRecapCrypt::decryptString($bytes),true,32,JSON_THROW_ON_ERROR):[];
}
function customerRecapNativeExtra(int $conversation,string $id,string $kind,int $second,array $content):int {
    $message=['id'=>$id,'timestamp'=>(string)(strtotime('2026-10-09 22:00:00 UTC')+$second),
        'type'=>$kind,'from'=>'201000000001','from_user_id'=>'US.customer-confirmed',$kind=>$content];
    $payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>'messages',
        'value'=>['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075',
            'display_phone_number'=>'201285545554'],'messages'=>[$message]]]]]]];
    $report=\App\Support\WhatsAppInboxProtocol::report($payload,'468336579702269','515388018324075');
    if(count($report['messages'])===0&&($report['quarantined_count']??0)>0){
        customerRecapExpect(true,'real native normalizer quarantines malformed supported suffix event');
        $event=CustomerRecapDB::table('whatsapp_webhook_events')->insertGetId(['processed_at'=>now('UTC')]);
        CustomerRecapDB::table('whatsapp_inbox_ingestion_failures')->insert([
            'event_id'=>$event,'reason'=>'INVALID_EVENT','attempts'=>1,'last_attempted_at'=>now('UTC')]);
        return 0;
    }
    customerRecapExpect(count($report['messages'])===1,'real native normalizer retains extra customer '.$kind.' row');
    $dto=$report['messages'][0];
    return CustomerRecapDB::table('whatsapp_inbox_messages')->insertGetId([
        'conversation_id'=>$conversation,'message_key'=>hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$id),
        'direction'=>$dto['direction'],'type'=>$dto['type'],'source'=>$dto['source'],'sent_at'=>$dto['sent_at'],
        'content'=>CustomerRecapCrypt::encryptString(json_encode($dto,JSON_THROW_ON_ERROR)),
        'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
}
function customerRecapLongThread(?string $recap=null,bool $oldPin=false,bool $probe=false):array {
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    CustomerRecapRaceDelivery::$duringQuote=null;$c=addConversation('US.customer-confirmed');
    for($i=1;$i<=65;$i++)insertMessage($c,'wamid.customer-recap.history.'.$i,'inbound','رسالة سابقة '.$i,$i,null,'US.customer-confirmed');
    if($oldPin){
        insertMessage($c,'wamid.customer-recap.history-address','inbound','عنواني شارع البحر',66,null,'US.customer-confirmed');
        insertMessage($c,'wamid.customer-recap.history-pin','inbound','',67,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');
    }
    if($probe)insertMessage($c,'wamid.customer-recap.history-probe','outbound','WA-4321',68,null,'US.customer-confirmed');
    $recapId=insertMessage($c,'wamid.customer-recap.long-summary','inbound',$recap??customerRecapText(),70,null,'US.customer-confirmed');
    insertMessage($c,'wamid.customer-recap.long-approval','inbound','كل شيء تمام',71,null,'US.customer-confirmed');
    CustomerRecapAi::$data=extractionData();return[$c,$recapId];
}
function customerRecapExhaustedWithoutCurrentDraft(?string $recap=null,?string $approval='كل شيء تمام',bool $probe=false):array {
    global $workflow;
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    CustomerRecapRaceDelivery::$duringQuote=null;$c=addConversation('US.customer-confirmed');
    $oldCeiling=insertMessage($c,'wamid.customer-recap.exhausted-old','inbound','أهلاً',1,null,'US.customer-confirmed');
    CustomerRecapAi::$data=\App\Support\WhatsAppOrderExtraction::none();
    $old=$workflow->analyze($c,(object)['id'=>7]);$oldDraft=(int)$old['draft']['id'];
    CustomerRecapDB::table('whatsapp_order_drafts')->where('id',$oldDraft)->update([
        'status'=>'REVIEW','reason'=>'REVIEW_REQUIRED','extraction'=>CustomerRecapCrypt::encryptString(json_encode(extractionData(),JSON_THROW_ON_ERROR))]);
    if($probe)insertMessage($c,'wamid.customer-recap.exhausted-probe','outbound','WA-4321',1,null,'US.customer-confirmed');
    $ceiling=insertMessage($c,'wamid.customer-recap.exhausted-copy','inbound',$recap??customerRecapText(),2,null,'US.customer-confirmed');
    if($approval!==null)$ceiling=insertMessage($c,'wamid.customer-recap.exhausted-approval','inbound',$approval,3,null,'US.customer-confirmed');
    CustomerRecapDB::table('whatsapp_order_scans')->where('conversation_id',$c)->update([
        'auto_floor'=>0,'analyzed_ceiling'=>$oldCeiling,'claimed_ceiling'=>$ceiling,'attempts'=>3,
        'next_attempt_at'=>now('UTC')->subMinute(),'claim_nonce'=>null,'lease_until'=>null,'updated_at'=>now('UTC')->subMinute()]);
    CustomerRecapAi::$calls=0;CustomerRecapAi::$data=extractionData();
    return['conversation'=>$c,'old_draft'=>$oldDraft,'ceiling'=>$ceiling,'floor'=>0,'revision'=>1];
}
function customerRecapRecoveryExpected(array $case):array {
    global $workflow;
    $snapshot=new ReflectionMethod($workflow,'snapshot');$snapshot->setAccessible(true);
    $data=$snapshot->invoke($workflow,$case['conversation'],$case['floor'],$case['ceiling']);
    $scan=CustomerRecapDB::table('whatsapp_order_scans')->where('conversation_id',$case['conversation'])->first();
    $scanHash=new ReflectionMethod($workflow,'customerReceiptRecoveryScanHash');$scanHash->setAccessible(true);
    return['control_revision'=>$case['revision'],'floor'=>$case['floor'],'ceiling'=>$case['ceiling'],
        'snapshot_hash'=>$data['hash'],'scan_hash'=>$scanHash->invoke($workflow,$scan)];
}
function customerRecapRecoveryState():array {
    $out=[];
    foreach(['whatsapp_order_drafts'=>'id','whatsapp_order_scans'=>'conversation_id','meta_ai_controls'=>'id']as$table=>$key)
        $out[$table]=CustomerRecapDB::table($table)->orderBy($key)->get()->map(static fn($row)=>(array)$row)->all();
    return$out;
}
function customerRecapRecoveryBlocked(array $case,array $expected,string $label):void {
    global $workflow;
    $before=customerRecapRecoveryState();$blocked=false;
    $replies=CustomerRecapDB::table('whatsapp_reply_requests')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
    try{$workflow->recoverCustomerReceipt($case['conversation'],$expected);}
    catch(WorkflowBlocked $error){$blocked=true;}
    customerRecapExpect($blocked,$label.' is refused by an explicit workflow guard');
    customerRecapExpect(customerRecapRecoveryState()===$before,$label.' changes no scan draft or activation metadata');
    customerRecapExpect(CustomerRecapAi::$calls===0,$label.' cannot fall back to extraction AI');
    primaryNoErp($label.' cannot execute ERP');
    customerRecapExpect(count($GLOBALS['dialogueGraphs'])===0&&count(DialogueFixtureProvider::$contexts)===0
        &&CustomerRecapDB::table('whatsapp_reply_requests')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all()===$replies,
        $label.' cannot send or reserve a new reply or alter an existing reply');
}

// Complete inbound-only copy plus a separate approval has no fabricated business
// row, merchant citation, network send, payment or duplicate kitchen operation.
$c=customerRecapSetup();$contents=CustomerRecapDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();
$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'full inbound recap and later separate whole approval dispatch once');
customerRecapExpect(CustomerRecapAi::$calls===0,'valid literal customer recap requires no extraction AI call');
$stored=customerRecapStored();
customerRecapExpect(($stored['type']??null)==='CUSTOMER_CONFIRMED_RECAP'
    &&is_array($stored['evidence_receipt']??null),'customer receipt has its own honest evidence type');
customerRecapExpect(CustomerRecapDB::table('whatsapp_inbox_messages')->where('direction','outbound')->count()===0
    &&CustomerRecapDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$contents,
    'deterministic receipt preserves every native row without manufacturing business messages');
customerRecapExpect(CustomerRecapDB::table('test_tickets')->value('branch')==='f:363'
    &&CustomerRecapDB::table('branch_customers')->value('phone_key')==='01000000001'
    &&abs((float)CustomerRecapDB::table('branch_customers')->value('latitude')-31.04)<0.0000001
    &&abs((float)CustomerRecapDB::table('branch_customers')->value('longitude')-31.37)<0.0000001,
    'native sender-scoped customer details and explicitly ordered coordinates reach matched physical branch');
customerRecapExpect(CustomerRecapDB::table('test_billing')->count()===0,'confirmed customer recap creates unpaid ERP order only');
customerRecapSilent('analysis-only customer confirmation neither composes nor sends replies');
$calls=CustomerRecapAi::$calls;primaryRun();
customerRecapExpect(CustomerRecapDB::table('test_tickets')->count()===1&&CustomerRecapDB::table('test_print')->count()===1
    &&CustomerRecapAi::$calls===$calls,'same native input replay cannot duplicate ticket print or extraction');
customerRecapSetup(str_replace('100 جنيه','999 جنيه',customerRecapText()));$r=primaryRun();
$sealed=json_decode(CustomerRecapCrypt::decryptString(CustomerRecapDB::table('whatsapp_order_drafts')->value('sealed_payload')),true,32,JSON_THROW_ON_ERROR);
customerRecapExpect($r['dispatched']===1&&($sealed['quote']['total']??null)==='100.00'
    &&($sealed['delivery']['delivery_fee']??null)==='50.00',
    'copied customer prices never override live ERP product or delivery quote values');
customerRecapSilent('live quote calculation composes no automatic reply');

// Keep the exact observed agent wording compatible while preserving the need
// for a distinct customer approval and rejecting material instructions in a footer.
$observedFooter="التوصيل خلال 60 دقيقة وسعر التوصيل بيأكده المندوب لما يكلمك.\n"
    .'انسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شئ تمام عشان نأكد طلبك نهائياً.';
$observedRecap=str_replace('انسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شيء تمام عشان تأكد طلبك نهائيًا.',
    $observedFooter,customerRecapText());
customerRecapSetup($observedRecap,'كل شئ تمام');$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapAi::$calls===0
    &&CustomerRecapDB::table('test_tickets')->count()===1&&CustomerRecapDB::table('test_print')->count()===1,
    'exact observed نأكد recap footer plus separate customer كل شئ تمام dispatches once without extraction AI');
customerRecapSilent('observed agent footer stays analysis-only without any automatic AI reply');
customerRecapSetup($observedRecap,null);primaryRun();
customerRecapNoErp('approval phrase copied inside observed footer cannot substitute for a separate customer approval');
customerRecapSetup($observedRecap."\nبس خلي الطلب 2 كيلو.",'كل شئ تمام');primaryRun();
customerRecapNoErp('material quantity instruction appended to observed footer invalidates customer recap');

// Coordinates in the labeled location field retain standard latitude/longitude
// order exactly as written; there is no guess, geocoding lookup or silent swap.
foreach([null,'31.04، 31.37']as$location){
    customerRecapSetup(customerRecapText(['الموقع'=>$location]),'كل شيء تمام',true);$r=primaryRun();
    customerRecapExpect($r['errors']===0&&$r['dispatched']===1,'customer recap retains exact matching authenticated native pin: '.json_encode([
        'location'=>$location,'result'=>$r,'draft_reason'=>CustomerRecapDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason'),
        'stored_type'=>customerRecapStored()['type']??null,'ai_calls'=>CustomerRecapAi::$calls],JSON_UNESCAPED_UNICODE));
    customerRecapSilent('native-pin customer receipt remains silent');
}
foreach([null,'91، 31.37','31.04، 181','0، 0','31.04، NaN','31.04، 31.37، 30']as$location){
    customerRecapSetup(customerRecapText(['الموقع'=>$location]));primaryRun();
    customerRecapNoErp('missing malformed out-of-range or zero location cannot authorize delivery');
}
foreach([['31.04، 31.37',31.04,31.37],['31.37، 31.04',31.37,31.04]]as[$literal,$latitude,$longitude]){
    customerRecapSetup(customerRecapText(['الموقع'=>$literal]));$r=primaryRun();
    customerRecapExpect($r['errors']===0&&$r['dispatched']===1
        &&abs((float)CustomerRecapDB::table('branch_customers')->value('latitude')-$latitude)<0.0000001
        &&abs((float)CustomerRecapDB::table('branch_customers')->value('longitude')-$longitude)<0.0000001,
        'labeled numeric location keeps literal latitude longitude order without correction');
    customerRecapSilent('literal coordinate order requires no reply');
}
customerRecapSetup(customerRecapText(['الموقع'=>'31.37، 31.04']),'كل شيء تمام',true);$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1
    &&abs((float)CustomerRecapDB::table('branch_customers')->value('latitude')-31.37)<0.0000001
    &&abs((float)CustomerRecapDB::table('branch_customers')->value('longitude')-31.04)<0.0000001,
    'newer explicit customer recap point supersedes older pin without swapping latitude and longitude');
customerRecapSilent('latest explicit recap coordinates remain analysis-only');
customerRecapSetup(customerRecapText(['الموقع'=>null]),'كل شيء تمام',true,false);primaryRun();
customerRecapNoErp('native pin before any address proof cannot be assumed to belong to later copied address');
customerRecapSetup(customerRecapText(['الموقع'=>null,'العنوان'=>'شارع مختلف']),'كل شيء تمام',true);primaryRun();
customerRecapNoErp('older pin for a different earlier address cannot authorize copied delivery address');
customerRecapSetup(customerRecapText(['الموقع'=>'https://www.google.com/maps/search/?api=1&query=31.04,31.37']));$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1,'allowlisted literal Google Maps query preserves explicit coordinate order without fetching');
customerRecapSilent('literal map URL uses no outbound reply transport');
customerRecapSetup(customerRecapText(['العنوان'=>'شارع البحر المنصورة','المنطقة'=>null,'الفرع'=>null]));$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapDB::table('test_tickets')->value('branch')==='f:363',
    'proved copied coordinates resolve physical branch without requiring explicit branch or area labels');
customerRecapSilent('point-derived branch remains analysis-only');
customerRecapSetup(customerRecapText(['الموقع'=>'خط العرض: 31.0، خط الطول: 31.37']));$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1
    &&(float)CustomerRecapDB::table('branch_customers')->value('latitude')===31.0,
    'integer-valued coordinate survives encrypted JSON serialization and receipt revalidation');
customerRecapSilent('integer-valued coordinates still require no AI reply');

// A copied footer is an instruction, not customer approval. Existing AI output
// must not silently fill that consent gap or replace a newer customer change.
foreach([null,'موافق؟','"موافق"','كل شيء تمام؟','كل شيء تمام بس خليهم 2 كيلو','مش موافق','الغاء الطلب']as$approval){
    customerRecapSetup(null,$approval);primaryRun();
    customerRecapNoErp('missing quoted questioning changed negative or cancelled customer consent cannot execute');
}
primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
$c=addConversation('US.customer-confirmed');
insertMessage($c,'wamid.customer-recap.early-approval','inbound','كل شيء تمام',1,null,'US.customer-confirmed');
insertMessage($c,'wamid.customer-recap.after-approval','inbound',customerRecapText(),2,null,'US.customer-confirmed');
CustomerRecapAi::$data=extractionData();primaryRun();customerRecapNoErp('approval before copied recap cannot approve later details');
$c=customerRecapSetup(null,null);
insertMessage($c,'wamid.customer-recap.other-peer','inbound','كل شيء تمام',3,null,'US.different-customer');
primaryRun();customerRecapNoErp('another authenticated peer cannot approve the customer recap in this conversation');
$c=customerRecapSetup(null,null);primaryRun();customerRecapNoErp('customer copied recap remains a proposal until later explicit consent');
insertMessage($c,'wamid.customer-recap.later-approve','inbound','كل شيء تمام',302,null,'US.customer-confirmed');dialogueAdvance(303);
$r=primaryRun();customerRecapExpect($r['errors']===0&&$r['dispatched']===1,'later genuine customer consent can complete an earlier copied recap');
customerRecapSilent('later confirmation keeps analysis-only mode silent');
customerRecapSetup(str_replace('رقم الهاتف: 201000000001','التليفونات: 201000000001، 201000000002',customerRecapText()));$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1
    &&CustomerRecapDB::table('branch_customers')->value('phone_key')==='01000000001'
    &&str_contains(CustomerRecapDB::table('branch_customers')->value('delivery_notes'),'201000000002'),
    'first explicitly listed contact stays primary while extra supplied contact is retained in delivery notes');
customerRecapSilent('multiple contacts create no customer reply');

foreach(['customer-change','new-pin','new-recap-without-approval']as$change){
    $c=customerRecapSetup();
    if($change==='customer-change')insertMessage($c,'wamid.customer-recap.changed','inbound','خلي الطلب 2 كيلو',4,null,'US.customer-confirmed');
    if($change==='new-pin')insertMessage($c,'wamid.customer-recap.changed','inbound','',4,['lat'=>31.05,'long'=>31.38],'US.customer-confirmed');
    if($change==='new-recap-without-approval')insertMessage($c,'wamid.customer-recap.changed','inbound',str_replace('1 كيلو فسيخ','2 كيلو فسيخ',customerRecapText()),4,null,'US.customer-confirmed');
    primaryRun();customerRecapNoErp('material activity after the approved recap invalidates the old customer receipt: '.$change);
}
$c=customerRecapSetup();
insertMessage($c,'wamid.customer-recap.business-text','outbound','غير الكمية إلى 2 كيلو.',4,null,'US.customer-confirmed');
$r=primaryRun();customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapAi::$calls===0,
    'typed business text is neither customer order input nor customer authority to alter the two-message basket');
customerRecapSilent('ignored business text cannot cause a reply');

foreach(['missing-phone','missing-address','duplicate-field','extra-item','unknown-item','pickup','location-shortlink']as$change){
    $text=customerRecapText();
    if($change==='missing-phone')$text=customerRecapText(['رقم الهاتف'=>null]);
    if($change==='missing-address')$text=customerRecapText(['العنوان'=>null]);
    if($change==='duplicate-field')$text=str_replace('رقم الهاتف:','الاسم: شخص آخر'."\n".'رقم الهاتف:',$text);
    if($change==='extra-item')$text=str_replace('الإجمالي:','1 كيلو صنف غير موجود – 100 جنيه'."\n".'الإجمالي:',$text);
    if($change==='unknown-item')$text=str_replace('كيلو فسيخ','كيلو صنف غير موجود',$text);
    if($change==='pickup')$text=customerRecapText(['ملاحظات'=>'تيك أواي']);
    if($change==='location-shortlink')$text=customerRecapText(['الموقع'=>'https://maps.app.goo.gl/not-a-real-link']);
    customerRecapSetup($text);primaryRun();customerRecapNoErp('incomplete malformed unknown or unsupported recap never executes automatically');
}

// Catalog resolution uses the current allowed branch, products and units. A
// customer's copied name never permits fuzzy product substitution or stale sales.
foreach(['unavailable','ambiguous','unit-mismatch']as$state){
    customerRecapSetup();$product=['id'=>11,'name'=>'فسيخ','available'=>$state!=='unavailable',
        'quantity_mode'=>$state==='unit-mismatch'?'piece':'weight','options'=>[]];
    DialogueFixtureCatalog::$products=$state==='ambiguous'?[$product,$product]:[$product];
    primaryRun();customerRecapNoErp('fresh catalog '.$state.' cannot authorize the copied basket');
}
customerRecapSetup(str_replace('1 كيلو فسيخ','نصف كيلو فسيخ دسوق',customerRecapText()));
$options=[['id'=>'desouq','label'=>'دسوق']];
\App\Services\Dashboard\TakeawayCatalog::$options=$options;
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'كيلو فسيخ','available'=>true,'quantity_mode'=>'weight','options'=>$options]];
$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1
    &&\App\Services\Dashboard\PosServiceTicket::$lastPayload['items'][0]['option_id']==='desouq'
    &&\App\Services\Dashboard\PosServiceTicket::$lastPayload['items'][0]['quantity']==='0.5',
    'literal catalog unit prefix and one exact real option preserve intended fractional weight');
customerRecapSilent('exact live catalog variant matching sends no clarification');
foreach(['permission','allowlist']as$guard){
    customerRecapSetup();
    if($guard==='permission')CustomerRecapDB::table('test_users')->where('id',7)->update(['branches'=>'[]']);
    if($guard==='allowlist')app('config')->set('whatsapp_orders.allowed_branch_ids',['94']);
    primaryRun();customerRecapNoErp('customer receipt still enforces branch '.$guard);
}

// Master, activation, capture currentness and independent schedules apply before
// and immediately before ERP writes, rather than trusting the stored receipt.
foreach(['enabled','schedule_active','actor','activation-id','activation-time','expired','pending-capture']as$guard){
    customerRecapSetup();
    if(in_array($guard,['enabled','schedule_active'],true))CustomerRecapDB::table('meta_ai_controls')->update([$guard=>false]);
    if($guard==='actor')CustomerRecapDB::table('test_users')->where('id',7)->update(['can_checkout'=>false]);
    if($guard==='activation-id')CustomerRecapDB::table('meta_ai_controls')->update(['message_floor'=>CustomerRecapDB::table('whatsapp_inbox_messages')->max('id')]);
    if($guard==='activation-time')CustomerRecapDB::table('meta_ai_controls')->update(['started_at_utc'=>'2026-10-09 22:00:04']);
    if($guard==='expired')customerRecapClock('2026-10-11 06:00:00');
    if($guard==='pending-capture')CustomerRecapDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
    primaryRun();customerRecapNoErp('customer recap cannot bypass '.$guard);
}
$c=customerRecapSetup();PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['deferred']===1
    &&CustomerRecapDB::table('whatsapp_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'approved customer recap queues while independent dispatch hours are closed');
customerRecapNoErp('dispatch schedule is enforced before order or kitchen writes');
$calls=CustomerRecapAi::$calls;customerRecapClock('2026-10-10 06:00:00');$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapAi::$calls===$calls,
    '09:00 dispatch opening resumes exact cached verified customer receipt without another AI call');
customerRecapSilent('scheduled customer receipt never sends an automatic reply');

foreach(['basket','point','receipt-hash']as$tamper){
    customerRecapSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
    $data=customerRecapStored();
    if($tamper==='basket')$data['items'][0]['quantity']='2';
    if($tamper==='point')$data['textual_location']['point']['lat']=31.25;
    if($tamper==='receipt-hash')$data['evidence_receipt']['recap_sha256']=str_repeat('0',64);
    CustomerRecapDB::table('whatsapp_order_drafts')->update(['extraction'=>CustomerRecapCrypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR))]);
    PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],
        'start'=>'23:00','end'=>'02:00','all_day'=>false];
    customerRecapClock('2026-10-10 06:00:00');primaryRun();
    customerRecapNoErp('cached customer '.$tamper.' tampering cannot replace rederived native receipt during scheduled dispatch');
}

$c=customerRecapSetup();PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],
    'start'=>'09:00','end'=>'10:00','all_day'=>false];PrimaryFixtureControl::$strictDispatchClock=true;
customerRecapClock('2026-10-10 06:00:00');
// Close only analysis after the first verified, queued snapshot has been created
// in the dedicated schedule suite; here a fresh closed clock cannot claim input.
PrimaryFixtureControl::$analysisSchedule['start']='10:00';PrimaryFixtureControl::$analysisSchedule['end']='11:00';
primaryRun();customerRecapExpect(CustomerRecapDB::table('whatsapp_order_drafts')->count()===0
    &&CustomerRecapDB::table('whatsapp_order_scans')->count()===0,'closed analysis schedule consumes no fresh recap or retry attempt');
customerRecapNoErp('open dispatch schedule alone cannot analyze a new copied receipt');

foreach(['control-drift','new-message','catalog-drift']as$race){
    $c=customerRecapSetup();$triggered=false;
    app()->instance(CustomerRecapDelivery::class,new CustomerRecapRaceDelivery());
    CustomerRecapRaceDelivery::$duringQuote=static function()use($c,$race,&$triggered){
        if($triggered)return;$triggered=true;
        if($race==='control-drift')CustomerRecapDB::table('meta_ai_controls')->update(['revision'=>2]);
        if($race==='new-message')insertMessage($c,'wamid.customer-recap.during-quote','inbound','الغي الطلب',4,null,'US.customer-confirmed');
        if($race==='catalog-drift')DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'فسيخ','available'=>false,'quantity_mode'=>'weight','options'=>[]]];
    };
    primaryRun();customerRecapExpect($triggered,'fixture reaches ordinary delivery boundary before '.$race);
    customerRecapNoErp('fresh '.$race.' blocks stale receipt before customer ticket or print writes');
}

// Re-sending the same completed basket under fresh inbound IDs is still one order,
// including when the worker's ordinary dispatched evidence floor moves forward.
$c=customerRecapSetup();primaryRun();
insertMessage($c,'wamid.customer-recap.duplicate-copy','inbound',customerRecapText(),302,null,'US.customer-confirmed');
insertMessage($c,'wamid.customer-recap.duplicate-approve','inbound','كل شيء تمام',303,null,'US.customer-confirmed');dialogueAdvance(304);
$r=primaryRun();
customerRecapExpect($r['errors']===0&&CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'fresh native IDs for identical same-day basket never create duplicate ERP order');
customerRecapSilent('duplicate confirmed customer recap remains silent');
customerRecapSetup(customerRecapText(['الموقع'=>'31.04000001، 31.37000001']));primaryRun();
$c=(int)CustomerRecapDB::table('whatsapp_inbox_conversations')->value('id');
insertMessage($c,'wamid.customer-recap.precision-copy','inbound',customerRecapText(['الموقع'=>'31.04، 31.37']),302,null,'US.customer-confirmed');
insertMessage($c,'wamid.customer-recap.precision-approve','inbound','كل شيء تمام',303,null,'US.customer-confirmed');dialogueAdvance(304);
$r=primaryRun();
customerRecapExpect($r['errors']===0&&CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'equivalent rounded coordinate precision cannot evade completed basket deduplication');
customerRecapSilent('coordinate precision replay remains silent');
$firstVariant=str_replace('1 كيلو فسيخ','1 كيلو فسيخ دسوق',customerRecapText());
$c=customerRecapSetup($firstVariant);$options=[['id'=>'desouq','label'=>'دسوق']];
\App\Services\Dashboard\TakeawayCatalog::$options=$options;
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'كيلو فسيخ','available'=>true,'quantity_mode'=>'weight','options'=>$options]];
$r=primaryRun();customerRecapExpect($r['dispatched']===1,'first exact-option basket reaches ERP once before equivalent word-order replay');
insertMessage($c,'wamid.customer-recap.word-order-copy','inbound',str_replace('فسيخ دسوق','دسوق فسيخ',$firstVariant),302,null,'US.customer-confirmed');
insertMessage($c,'wamid.customer-recap.word-order-approve','inbound','كل شيء تمام',303,null,'US.customer-confirmed');dialogueAdvance(304);
$r=primaryRun();
customerRecapExpect($r['errors']===0&&CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'equivalent exact product-option word order cannot evade completed ERP basket deduplication');
customerRecapSilent('equivalent catalog wording replay remains silent');

// A self-contained new customer receipt can form its own complete bounded
// evidence suffix without claiming that omitted earlier history was observed.
[$c,$recapId]=customerRecapLongThread();
$contents=CustomerRecapDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapAi::$calls===0
    &&CustomerRecapDB::table('test_tickets')->count()===1&&CustomerRecapDB::table('test_print')->count()===1,
    'full new self-contained recap and approval dispatch despite more than sixty older captured rows');
customerRecapExpect((int)CustomerRecapDB::table('whatsapp_order_drafts')->value('evidence_floor')===$recapId-1
    &&(int)CustomerRecapDB::table('meta_ai_controls')->value('message_floor')===0
    &&(int)CustomerRecapDB::table('whatsapp_order_scans')->value('auto_floor')===0,
    'narrow receipt evidence boundary does not rewrite activation or scan floors');
customerRecapExpect(CustomerRecapDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$contents
    &&CustomerRecapDB::table('whatsapp_inbox_messages')->where('direction','outbound')->count()===0,
    'long-thread receipt preserves native history and does not manufacture missing business turns');
customerRecapSilent('bounded self-contained receipt needs no extraction AI or reply');
primaryRun();customerRecapExpect(CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'long-thread receipt worker replay remains exactly once');
primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
$c=addConversation('US.customer-confirmed');
for($i=1;$i<=6;$i++)insertMessage($c,'wamid.customer-recap.long-text.'.$i,'inbound',str_repeat('ا',3000),$i,null,'US.customer-confirmed');
$recapId=insertMessage($c,'wamid.customer-recap.after-long-text','inbound',customerRecapText(),70,null,'US.customer-confirmed');
insertMessage($c,'wamid.customer-recap.after-long-text-approval','inbound','كل شيء تمام',71,null,'US.customer-confirmed');
$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapAi::$calls===0
    &&(int)CustomerRecapDB::table('whatsapp_order_drafts')->value('evidence_floor')===$recapId-1,
    'self-contained receipt also narrows an older character-limited history with fewer than sixty rows');
customerRecapSilent('character-boundary recovery remains deterministic and silent');
foreach(['later-change','malformed-cart','image','missing-textual-point','recent-probe']as$guard){
    [$c,$recapId]=customerRecapLongThread($guard==='missing-textual-point'?customerRecapText(['الموقع'=>null]):null,
        $guard==='missing-textual-point',$guard==='recent-probe');
    if($guard==='later-change')insertMessage($c,'wamid.customer-recap.long-change','inbound','خلي الطلب 2 كيلو',72,null,'US.customer-confirmed');
    if($guard==='malformed-cart')customerRecapNativeExtra($c,'wamid.customer-recap.long-cart','order',72,
        ['catalog_id'=>'999','product_items'=>[]]);
    if($guard==='image')customerRecapNativeExtra($c,'wamid.customer-recap.long-image','image',72,
        ['id'=>'11111111111111','mime_type'=>'image/jpeg','sha256'=>base64_encode(str_repeat('a',32))]);
    primaryRun();customerRecapNoErp('incomplete older history cannot authorize narrowed receipt with '.$guard);
}

// Already captured pending turns are reconsidered once when a new deterministic
// protocol recognizes them, even if the former AI extraction exhausted retries.
$c=customerRecapSetup();$analyzed=$workflow->analyze($c,(object)['id'=>7]);$draftId=(int)$analyzed['draft']['id'];
CustomerRecapDB::table('whatsapp_order_drafts')->where('id',$draftId)->update([
    'status'=>'REVIEW','reason'=>'INVALID_EXTRACTION','extraction'=>CustomerRecapCrypt::encryptString(json_encode(extractionData(),JSON_THROW_ON_ERROR))]);
$ceiling=(int)CustomerRecapDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->max('id');
CustomerRecapDB::table('whatsapp_order_scans')->where('conversation_id',$c)->update([
    'auto_floor'=>0,'claimed_ceiling'=>$ceiling,'analyzed_ceiling'=>$ceiling,'attempts'=>3,
    'next_attempt_at'=>now('UTC')->subSecond(),'claim_nonce'=>null,'lease_until'=>null]);
$r=primaryRun();
customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapDB::table('whatsapp_order_drafts')->count()===1
    &&(int)CustomerRecapDB::table('whatsapp_order_drafts')->value('id')===$draftId
    &&(customerRecapStored()['type']??null)==='CUSTOMER_CONFIRMED_RECAP'&&CustomerRecapAi::$calls===0,
    'recognized existing customer copy upgrades one exhausted legacy draft without a new message or AI extraction');
customerRecapSilent('legacy receipt upgrade cannot compose or send a reply');
primaryRun();customerRecapExpect(CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'upgraded legacy draft also retains dispatch idempotency');

// This is the observed production state: a previous claim exhausted retries
// without producing any draft at the fresh ceiling. Ordinary selection stays
// bounded; a separately authorized deterministic recovery must not reset it.
$exhausted=customerRecapExhaustedWithoutCurrentDraft();$r=primaryRun();
customerRecapExpect($r['threads_seen']===0&&CustomerRecapAi::$calls===0
    &&CustomerRecapDB::table('whatsapp_order_drafts')->where('evidence_ceiling',$exhausted['ceiling'])->count()===0
    &&(int)CustomerRecapDB::table('whatsapp_order_scans')->value('attempts')===3,
    'exhausted unchanged claim without current draft does not occupy or reset ordinary worker selection');
customerRecapNoErp('exhausted claim reproduction creates no ERP operation or reply');

// The CLI recovery authorizes only this exact deterministic customer receipt.
// Persisting its review draft is separate from the ordinary scheduled dispatcher.
$case=customerRecapExhaustedWithoutCurrentDraft();$expected=customerRecapRecoveryExpected($case);
$before=customerRecapRecoveryState();$result=$workflow->recoverCustomerReceipt($case['conversation'],$expected);
$current=CustomerRecapDB::table('whatsapp_order_drafts')->where('conversation_id',$case['conversation'])
    ->where('evidence_ceiling',$case['ceiling'])->first();
customerRecapExpect(($result['success']??false)===true&&($result['status']??null)==='RECORDED'
    &&($result['writes']??null)===2&&($result['ai_calls']??null)===0&&($result['message_sends']??null)===0
    &&($result['erp_writes']??null)===0&&$current!==null,
    'scoped deterministic recovery records one current review draft without AI sends or ERP');
customerRecapExpect($current->status==='REVIEW'&&$current->reason==='REVIEW_REQUIRED'
    &&(int)$current->evidence_floor===$case['floor']&&(int)$current->evidence_ceiling===$case['ceiling']
    &&$current->evidence_hash===$expected['snapshot_hash']
    &&(customerRecapStored()['type']??null)==='CUSTOMER_CONFIRMED_RECAP',
    'recovery draft binds exact authenticated current customer receipt and evidence boundaries');
$after=customerRecapRecoveryState();$scanBefore=$before['whatsapp_order_scans'][0];$scanAfter=$after['whatsapp_order_scans'][0];
customerRecapExpect((int)$scanAfter['attempts']===3&&(int)$scanAfter['claimed_ceiling']===$case['ceiling']
    &&(int)$scanAfter['analyzed_ceiling']===$case['ceiling']&&$scanAfter['next_attempt_at']===null
    &&$scanAfter['claim_nonce']===null&&$scanAfter['lease_until']===null,
    'deterministic receipt recovery retains exhausted generic AI budget and installs a cached current analysis');
foreach(['analyzed_ceiling','next_attempt_at','updated_at']as$key){unset($scanBefore[$key],$scanAfter[$key]);}
customerRecapExpect($scanBefore===$scanAfter&&$after['meta_ai_controls']===$before['meta_ai_controls']
    &&$after['whatsapp_order_drafts'][0]===$before['whatsapp_order_drafts'][0],
    'recovery preserves claim identity activation floors control policy and historical legacy draft byte for byte');
customerRecapExpect(CustomerRecapAi::$calls===0,'deterministic recovery never invokes extraction AI');
customerRecapNoErp('recovery only records review facts and does not dispatch');
$recorded=customerRecapRecoveryState();$again=$workflow->recoverCustomerReceipt($case['conversation'],$expected);
customerRecapExpect(($again['status']??null)==='ALREADY_RECORDED'&&($again['writes']??null)===0
    &&($again['draft_id']??null)===(int)$current->id&&customerRecapRecoveryState()===$recorded,
    'repeating the same authorized receipt with old scan hash is read-only and idempotent');
$r=primaryRun();customerRecapExpect($r['errors']===0&&$r['dispatched']===1&&CustomerRecapAi::$calls===0
    &&CustomerRecapDB::table('test_tickets')->count()===1&&CustomerRecapDB::table('test_print')->count()===1,
    'ordinary bounded worker later dispatches recovered cached receipt at attempt three without another AI call');
customerRecapSilent('normal dispatch after scoped recovery remains analysis-only');
primaryRun();customerRecapExpect(CustomerRecapDB::table('test_tickets')->count()===1
    &&CustomerRecapDB::table('test_print')->count()===1,'recovered receipt still produces one ticket and one print across worker replays');

foreach(['ceiling','revision','snapshot-hash','scan-hash','missing-key','unknown-key','wrong-type']as$guard){
    $case=customerRecapExhaustedWithoutCurrentDraft();$expected=customerRecapRecoveryExpected($case);
    if($guard==='ceiling')$expected['ceiling']++;
    if($guard==='revision')$expected['control_revision']++;
    if($guard==='snapshot-hash')$expected['snapshot_hash']=str_repeat('0',64);
    if($guard==='scan-hash')$expected['scan_hash']=str_repeat('0',64);
    if($guard==='missing-key')unset($expected['scan_hash']);
    if($guard==='unknown-key')$expected['force']=true;
    if($guard==='wrong-type')$expected['floor']='0';
    customerRecapRecoveryBlocked($case,$expected,'recovery expected '.$guard.' mismatch');
}
foreach(['activation','master-off','actor','mode','analysis-closed','live-lease','future-backoff','not-exhausted','different-claim',
    'analyzed-current','missing-scan','new-message','unknown-send','claimed-send','accepted-unprojected','pending-capture','capture-failure','expired']as$guard){
    $case=customerRecapExhaustedWithoutCurrentDraft();$expected=customerRecapRecoveryExpected($case);
    if($guard==='activation')CustomerRecapDB::table('meta_ai_controls')->update(['message_floor'=>1]);
    if($guard==='master-off')CustomerRecapDB::table('meta_ai_controls')->update(['enabled'=>false]);
    if($guard==='actor')CustomerRecapDB::table('test_users')->where('id',7)->update(['can_checkout'=>false]);
    if($guard==='mode')PrimaryFixtureControl::$analysisOnly=false;
    if($guard==='analysis-closed')PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],
        'start'=>'09:00','end'=>'10:00','all_day'=>false];
    if($guard==='live-lease'){
        CustomerRecapDB::table('whatsapp_order_scans')->update(['claim_nonce'=>str_repeat('a',64),'lease_until'=>now('UTC')->addMinute()]);
        $expected=customerRecapRecoveryExpected($case);
    }
    if(in_array($guard,['future-backoff','not-exhausted','different-claim','analyzed-current'],true)){
        $changes=match($guard){
            'future-backoff'=>['next_attempt_at'=>now('UTC')->addMinute()],
            'not-exhausted'=>['attempts'=>2],
            'different-claim'=>['claimed_ceiling'=>$case['ceiling']-1],
            'analyzed-current'=>['analyzed_ceiling'=>$case['ceiling']],
        };
        CustomerRecapDB::table('whatsapp_order_scans')->update($changes);$expected=customerRecapRecoveryExpected($case);
    }
    if($guard==='missing-scan')CustomerRecapDB::table('whatsapp_order_scans')->delete();
    if($guard==='new-message')insertMessage($case['conversation'],'wamid.customer-recap.recovery-new-message','inbound','خلي الطلب 2 كيلو',4,null,'US.customer-confirmed');
    if(in_array($guard,['unknown-send','claimed-send','accepted-unprojected'],true)){
        $now=now('UTC')->format('Y-m-d H:i:s');
        CustomerRecapDB::table('whatsapp_reply_requests')->insert(['waba_id'=>'468336579702269','phone_number_id'=>'515388018324075',
            'conversation_id'=>$case['conversation'],'actor_id'=>7,'request_hash'=>hash('sha256','scoped-recovery-'.$guard),
            'payload_hash'=>hash('sha256','scoped-recovery-payload'),'kind'=>'text','inbound_message_id'=>$case['ceiling'],
            'state'=>match($guard){'unknown-send'=>'UNKNOWN','claimed-send'=>'CLAIMED','accepted-unprojected'=>'ACCEPTED'},
            'audit'=>CustomerRecapCrypt::encryptString('{}'),'claimed_at'=>$now,
            'completed_at'=>$guard==='accepted-unprojected'?$now:null,'created_at'=>$now,'updated_at'=>$now]);
    }
    if($guard==='pending-capture')CustomerRecapDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
    if($guard==='capture-failure'){
        $event=CustomerRecapDB::table('whatsapp_webhook_events')->insertGetId(['processed_at'=>now('UTC')]);
        CustomerRecapDB::table('whatsapp_inbox_ingestion_failures')->insert([
            'event_id'=>$event,'reason'=>'INVALID_EVENT','attempts'=>1,'last_attempted_at'=>now('UTC')]);
    }
    if($guard==='expired')customerRecapClock('2026-10-11 06:00:00');
    customerRecapRecoveryBlocked($case,$expected,'scoped recovery '.$guard.' guard');
}
foreach(['no-approval','changed-approval','cancelled-tail','known-probe','missing-point','incomplete-suffix']as$guard){
    $case=customerRecapExhaustedWithoutCurrentDraft($guard==='missing-point'?customerRecapText(['الموقع'=>null]):null,
        $guard==='no-approval'?null:($guard==='changed-approval'?'كل شيء تمام بس خلي الطلب 2 كيلو':'كل شيء تمام'),$guard==='known-probe');
    if($guard==='cancelled-tail'){
        $case['ceiling']=insertMessage($case['conversation'],'wamid.customer-recap.exhausted-cancel','inbound','الغي الطلب',4,null,'US.customer-confirmed');
        CustomerRecapDB::table('whatsapp_order_scans')->update(['claimed_ceiling'=>$case['ceiling']]);
    }
    if($guard==='incomplete-suffix'){
        for($i=4;$i<=65;$i++)$case['ceiling']=insertMessage($case['conversation'],'wamid.customer-recap.recovery-tail.'.$i,
            'inbound','كل شيء تمام',$i,null,'US.customer-confirmed');
        CustomerRecapDB::table('whatsapp_order_scans')->update(['claimed_ceiling'=>$case['ceiling']]);
    }
    $expected=customerRecapRecoveryExpected($case);
    customerRecapRecoveryBlocked($case,$expected,'receipt-only recovery '.$guard.' proof');
}

$case=customerRecapExhaustedWithoutCurrentDraft();PrimaryFixtureControl::$strictDispatchClock=true;
$result=$workflow->recoverCustomerReceipt($case['conversation'],customerRecapRecoveryExpected($case));
customerRecapExpect(($result['status']??null)==='RECORDED','analysis-open recovery records review facts independently of closed dispatch clock');
$r=primaryRun();customerRecapExpect($r['deferred']===1
    &&CustomerRecapDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'ordinary worker queues a recovered receipt while independent dispatch clock is closed');
customerRecapNoErp('scoped recovery and closed dispatch clock create no ERP operation');
customerRecapClock('2026-10-10 06:00:00');$r=primaryRun();
customerRecapExpect($r['dispatched']===1&&CustomerRecapAi::$calls===0
    &&CustomerRecapDB::table('test_tickets')->count()===1&&CustomerRecapDB::table('test_print')->count()===1,
    '09:00 scheduled dispatch resumes the recovered receipt exactly once without new AI');
customerRecapSilent('scheduled recovered receipt never sends an AI reply');

echo 'whatsapp_customer_confirmation_workflow: '.$customerRecapChecks." checks passed\n";
