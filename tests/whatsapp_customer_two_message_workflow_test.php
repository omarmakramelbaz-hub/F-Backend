<?php
// End-to-end native normalization, encrypted SQLite receipts and normal ERP
// dispatch. All customer details, coordinates and identities are synthetic.
require __DIR__.'/whatsapp_customer_labeled_branch_workflow_test.php';
use Illuminate\Support\Facades\DB as TwoMessageDB;
use Illuminate\Support\Facades\Crypt as TwoMessageCrypt;
use App\Services\Dashboard\WhatsAppOrderAiProvider as TwoMessageAi;

$twoMessageChecks=0;
function twoMessageExpect($ok,string$label):void{
    $GLOBALS['twoMessageChecks']++;
    if(!$ok)throw new RuntimeException('TWO_MESSAGE_WORKFLOW_FAILED:'.$label);
}
function twoMessageNoErp(string$label):void{
    customerRecapNoErp($label);
    twoMessageExpect(TwoMessageAi::$calls===0,$label.' makes no provider call');
}

// This has the shape of the observed 23-row customer-only thread. Neither the
// unknown followup wording nor an unrelated agent turn becomes order input.
$c=labeledWorkflowSetup();TwoMessageDB::table('whatsapp_inbox_messages')->delete();
for($i=1;$i<=18;$i++)insertMessage($c,'wamid.two.history.'.$i,'inbound','حديث سابق مستقل '.$i,$i,null,'US.customer-confirmed');
insertMessage($c,'wamid.two.recap','inbound',labeledWorkflowText(),120,null,'US.customer-confirmed');
insertMessage($c,'wamid.two.approval','inbound','كل شئ تمام',121,null,'US.customer-confirmed');
insertMessage($c,'wamid.two.pin','inbound','',122,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');
insertMessage($c,'wamid.two.followup.1','inbound','هل الطلب وصل للفرع ولا لسه؟',123,null,'US.customer-confirmed');
insertMessage($c,'wamid.two.followup.2','inbound','أرجو سرعة الرد على استفساري وشكراً.',124,null,'US.customer-confirmed');
$native=TwoMessageDB::table('whatsapp_inbox_messages')->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all();
twoMessageExpect(count($native)===23,'fixture genuinely uses 23 real native customer rows');
$r=primaryRun();$stored=customerRecapStored();$receipt=$stored['evidence_receipt']??[];
twoMessageExpect($r['errors']===0&&$r['dispatched']===1&&TwoMessageAi::$calls===0,
    'full customer summary and exact customer confirmation dispatch despite ordinary later text');
twoMessageExpect(($receipt['recap_id']??null)==='m19'&&($receipt['approval_id']??null)==='m20'
    &&!isset($receipt['approval_basis']),
    'receipt cites only distinct original summary and exact confirmation, never subsequent chatter');
twoMessageExpect(TwoMessageDB::table('whatsapp_inbox_messages')->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all()===$native,
    'workflow preserves native message identity, ciphertext and timestamp bytes');
customerRecapSilent('restricted two-message flow sends no internal reply');
$ticket=TwoMessageDB::table('test_tickets')->first();$draft=TwoMessageDB::table('whatsapp_order_drafts')->first();
primaryRun();
twoMessageExpect(TwoMessageDB::table('test_tickets')->count()===1&&TwoMessageDB::table('test_print')->count()===1
    &&(array)TwoMessageDB::table('test_tickets')->first()===(array)$ticket
    &&(array)TwoMessageDB::table('whatsapp_order_drafts')->first()===(array)$draft,
    'normal replay leaves completed ticket, print and evidence draft immutable');

foreach(['كل شئ تمام','كل شيء تمام']as$confirmation){
    labeledWorkflowSetup($confirmation);$r=primaryRun();
    twoMessageExpect($r['dispatched']===1&&TwoMessageAi::$calls===0,
        'only a distinct original exact confirmation approves the full customer summary: '.$confirmation);
}
foreach([null,'تمام','موافق','موافق على الطلب','نعم','yes','ok','كل شئ تمام؟','كل شئ تمام بس غير الطلب']as$confirmation){
    labeledWorkflowSetup($confirmation);primaryRun();twoMessageNoErp('other consent wording is not accepted');
}

// A full compact-format summary is an input format, never self-approval.
customerRecapSetup(compactText(),null);DialogueFixtureCatalog::$products=[compactWholeHerring()];
primaryRun();twoMessageNoErp('compact confirmed title alone cannot manufacture separate customer confirmation');
twoMessageExpect(TwoMessageDB::table('whatsapp_order_drafts')->value('reason')==='CUSTOMER_APPROVAL_REQUIRED',
    'full summary with missing exact confirmation is a deterministic explicit review');
$c=compactSetup();
insertMessage($c,'wamid.two.business-followup','outbound','تم تأكيد طلبك، شكراً لحضرتك.',4,null,'US.customer-confirmed');
$r=primaryRun();twoMessageExpect($r['dispatched']===1&&TwoMessageAi::$calls===0,
    'ordinary typed business acknowledgement does not supply or veto customer order authority');

foreach(['missing','business','other-customer']as$summarySource){
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    $c=addConversation('US.customer-confirmed');
    if($summarySource==='business')insertMessage($c,'wamid.two.wrong-summary','outbound',labeledWorkflowText(),2,null,'US.customer-confirmed');
    if($summarySource==='other-customer')insertMessage($c,'wamid.two.wrong-summary','inbound',labeledWorkflowText(),2,null,'US.someone-else');
    insertMessage($c,'wamid.two.lonely-ok','inbound','كل شئ تمام',3,null,'US.customer-confirmed');
    TwoMessageAi::$data=extractionData();primaryRun();twoMessageNoErp('summary must be authenticated customer input: '.$summarySource);
}

// Latest malformed recap cannot fall back to the earlier complete confirmed
// basket, even when another exact confirmation follows the malformed one.
$c=labeledWorkflowSetup();
insertMessage($c,'wamid.two.latest-malformed','inbound',str_replace('- الاسم: عميل افتراضي', '- الاسم:',labeledWorkflowText()),4,null,'US.customer-confirmed');
insertMessage($c,'wamid.two.latest-malformed-ok','inbound','كل شئ تمام',5,null,'US.customer-confirmed');
primaryRun();twoMessageNoErp('latest incomplete recap never resurrects earlier approved data');

foreach(['الغي الطلب','غير الكمية إلى 2 كيلو','native-cart','conflicting-pin']as$change){
    $c=labeledWorkflowSetup();
    if($change==='native-cart')customerRecapNativeExtra($c,'wamid.two.material-cart','order',4,
        ['catalog_id'=>'synthetic','product_items'=>[['product_retailer_id'=>'x','quantity'=>2,'item_price'=>'250','currency'=>'EGP']]]);
    elseif($change==='conflicting-pin')insertMessage($c,'wamid.two.conflicting-pin','inbound','',4,['lat'=>31.13,'long'=>31.30],'US.customer-confirmed');
    else insertMessage($c,'wamid.two.explicit-change','inbound',$change,4,null,'US.customer-confirmed');
    primaryRun();twoMessageNoErp('explicit material change still requires review: '.$change);
}

// A current old NONE draft may refresh after the protocol becomes available,
// but the existing retry delay and activation/claimed boundaries remain binding.
$c=labeledWorkflowSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$old=TwoMessageDB::table('whatsapp_order_drafts')->first();
TwoMessageDB::table('whatsapp_order_drafts')->where('id',$old->id)->update([
    'extraction'=>TwoMessageCrypt::encryptString(json_encode(\App\Support\WhatsAppOrderExtraction::none(),JSON_THROW_ON_ERROR)),
    'status'=>'NONE','reason'=>'NO_ORDER']);
TwoMessageDB::table('whatsapp_order_scans')->update(['attempts'=>3,'next_attempt_at'=>now('UTC')->addMinute()]);
$before=TwoMessageDB::table('whatsapp_order_scans')->first();$control=TwoMessageDB::table('meta_ai_controls')->first();
primaryRun();twoMessageNoErp('existing future backoff remains authoritative for cached receipt refresh');
twoMessageExpect((array)TwoMessageDB::table('whatsapp_order_scans')->first()===(array)$before,
    'waiting for current cached NONE refresh never resets exhausted attempts or claim metadata');
customerRecapClock('2026-10-09 22:07:00');PrimaryFixtureControl::$strictDispatchClock=false;$r=primaryRun();
$after=TwoMessageDB::table('whatsapp_order_scans')->first();
twoMessageExpect($r['dispatched']===1&&TwoMessageAi::$calls===0
    &&(int)TwoMessageDB::table('whatsapp_order_drafts')->value('id')===(int)$old->id,
    'due normal worker refreshes one existing current NONE draft without AI or manual reset');
twoMessageExpect((int)$after->attempts===3&&(int)$after->claimed_ceiling===(int)$before->claimed_ceiling
    &&(int)$after->auto_floor===(int)$before->auto_floor
    &&(array)TwoMessageDB::table('meta_ai_controls')->first()===(array)$control,
    'refresh preserves attempts, native claimed ceiling, floor and complete control policy');

// The same native snapshot can already contain the real later exact phrase
// while its pending cache still uses the retired same-message authority.
// Normal due processing must migrate only the cache to the real two IDs.
$c=compactSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$old=TwoMessageDB::table('whatsapp_order_drafts')->first();$legacy=customerRecapStored();
$legacyReceipt=$legacy['evidence_receipt'];
$legacyReceipt['approval_id']=$legacyReceipt['recap_id'];
$legacyReceipt['approval_sha256']=$legacyReceipt['recap_sha256'];
$legacyReceipt['approval_basis']='CUSTOMER_SELF_CONFIRMED_RECAP';unset($legacyReceipt['receipt_sha256']);
$legacyReceipt['receipt_sha256']=hash('sha256',json_encode([
    \App\Support\WhatsAppCustomerOrderConfirmation::TYPE,$legacyReceipt],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$legacy['evidence_receipt']=$legacyReceipt;
TwoMessageDB::table('whatsapp_order_drafts')->where('id',$old->id)->update([
    'extraction'=>TwoMessageCrypt::encryptString(json_encode($legacy,JSON_THROW_ON_ERROR))]);
TwoMessageDB::table('whatsapp_order_scans')->update(['attempts'=>3,'next_attempt_at'=>now('UTC')->addMinute()]);
$before=TwoMessageDB::table('whatsapp_order_scans')->first();
$native=TwoMessageDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();
primaryRun();twoMessageNoErp('retired self-approved cache migration respects existing future retry delay');
twoMessageExpect((array)TwoMessageDB::table('whatsapp_order_scans')->first()===(array)$before,
    'waiting to migrate legacy self receipt preserves every scan field');
customerRecapClock('2026-10-09 22:07:00');PrimaryFixtureControl::$strictDispatchClock=false;$r=primaryRun();
$migrated=customerRecapStored()['evidence_receipt'];$after=TwoMessageDB::table('whatsapp_order_scans')->first();
twoMessageExpect($r['dispatched']===1&&TwoMessageAi::$calls===0
    &&$migrated['recap_id']==='m1'&&$migrated['approval_id']==='m2'&&!isset($migrated['approval_basis'])
    &&(int)TwoMessageDB::table('whatsapp_order_drafts')->value('id')===(int)$old->id,
    'due worker refreshes retired self authority from existing actual distinct native confirmation');
twoMessageExpect((int)$after->attempts===3&&(int)$after->claimed_ceiling===(int)$before->claimed_ceiling
    &&(int)$after->auto_floor===(int)$before->auto_floor
    &&TwoMessageDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
    'legacy cache migration preserves exhausted attempts, evidence boundaries and every native source byte');

foreach(['previous-day','unavailable-item','nearest-denied','pending-capture']as$guard){
    labeledWorkflowSetup();
    if($guard==='previous-day')customerRecapClock('2026-10-10 21:00:01');
    if($guard==='unavailable-item')DialogueFixtureCatalog::$products[0]['available']=false;
    if($guard==='nearest-denied')app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307']);
    if($guard==='pending-capture')TwoMessageDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
    primaryRun();twoMessageNoErp('two-message consent cannot bypass existing '.$guard.' guard');
}

// A pending legacy self-approved cache is not grandfathered into authority.
// Its only original customer row is the compact summary; a genuine separate
// confirmation has never been captured and must not be synthesized on upgrade.
$c=customerRecapSetup(compactText(),null);DialogueFixtureCatalog::$products=[compactWholeHerring()];
primaryRun();$pending=TwoMessageDB::table('whatsapp_order_drafts')->first();
$legacy=\App\Support\WhatsAppCustomerOrderConfirmation::parseRecap(compactText())['data'];
$legacy['decision']='CONFIRMED';
$legacy['textual_location']['source_message_id']='m1';
$legacyReceipt=['version'=>1,'recap_id'=>'m1','approval_id'=>'m1',
    'recap_sha256'=>hash('sha256',compactText()),'approval_sha256'=>hash('sha256',compactText()),
    'approval_basis'=>'CUSTOMER_SELF_CONFIRMED_RECAP'];
$legacyReceipt['receipt_sha256']=hash('sha256',json_encode([
    \App\Support\WhatsAppCustomerOrderConfirmation::TYPE,$legacyReceipt],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$legacy['evidence_receipt']=$legacyReceipt;
TwoMessageDB::table('whatsapp_order_drafts')->where('id',$pending->id)->update([
    'extraction'=>TwoMessageCrypt::encryptString(json_encode($legacy,JSON_THROW_ON_ERROR)),
    'status'=>'REVIEW','reason'=>'DISPATCH_OUTSIDE_HOURS']);
PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],
    'start'=>'09:00','end'=>'10:00','all_day'=>false];
customerRecapClock('2026-10-09 22:07:00');primaryRun();
twoMessageNoErp('old pending self-confirmed cache without distinct exact confirmation cannot dispatch');

// Model-created CONFIRMED caches also cannot cross the new native-only gate,
// including when only dispatch is currently open and analysis is closed.
$c=analysisDraft(null,'كل شئ تمام');primaryRun();$pending=TwoMessageDB::table('whatsapp_order_drafts')->first();
$model=extractionData();$model['evidence']['confirmation_ids']=['m3'];$model['evidence']['customer_acceptance_ids']=['m4'];
TwoMessageDB::table('whatsapp_order_drafts')->where('id',$pending->id)->update([
    'extraction'=>TwoMessageCrypt::encryptString(json_encode($model,JSON_THROW_ON_ERROR)),
    'status'=>'READY','reason'=>null]);
PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],
    'start'=>'09:00','end'=>'10:00','all_day'=>false];
customerRecapClock('2026-10-09 22:07:00');primaryRun();
twoMessageNoErp('cached model confirmation cannot override absent customer recap while analysis is closed');
twoMessageExpect(TwoMessageDB::table('whatsapp_order_drafts')->value('status')!=='DISPATCHED',
    'native receipt policy rechecks pending old model cache before quote or ERP');
$confirmationKey=new ReflectionMethod($workflow,'confirmationKey');$confirmationKey->setAccessible(true);$blocked=false;
try{$confirmationKey->invoke($workflow,TwoMessageDB::table('whatsapp_order_drafts')->first());}
catch(WorkflowBlocked$error){$blocked=$error->getCode()===422&&$error->getMessage()==='CUSTOMER_CONFIRMATION_REQUIRED';}
twoMessageExpect($blocked,'direct confirmation key refuses cached nonreceipt authority even with dispatch open and analysis closed');

echo 'whatsapp_customer_two_message_workflow: '.$twoMessageChecks." checks passed\n";
