<?php
// One owner-approved catalog choice, bound to synthetic native order evidence.
require __DIR__.'/whatsapp_customer_receipt_pack_alias_workflow_test.php';
use Illuminate\Support\Facades\DB as OwnerChoiceDB;
use Illuminate\Support\Facades\Crypt as OwnerChoiceCrypt;
use App\Services\Dashboard\PosServiceTicket as OwnerChoicePos;

$ownerChoiceChecks=0;
function ownerChoiceExpect($ok,string$label):void{
    $GLOBALS['ownerChoiceChecks']++;if(!$ok)throw new RuntimeException('OWNER_CHOICE_FAILED:'.$label);
}
function ownerChoiceSetup(?string$summary=null):array{
    global$workflow;
    $c=packAliasSetup(false,true);CustomerRecapRaceDelivery::$duringQuote=null;
    if($summary!==null){
        OwnerChoiceDB::table('whatsapp_inbox_messages')->where('conversation_id',$c)->delete();
        insertMessage($c,'wamid.owner-choice.summary','inbound',$summary,2,null,'US.customer-confirmed');
        insertMessage($c,'wamid.owner-choice.pin','inbound','',3,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');
        insertMessage($c,'wamid.owner-choice.approval','inbound','كل شئ تمام',4,null,'US.customer-confirmed');
    }
    DialogueFixtureCatalog::$products[4]=['id'=>2557,'name'=>'ساندوتش فسيخ دسوق نبروه مظبوط','available'=>true,
        'quantity_mode'=>'select','price'=>'130.00','options'=>[['id'=>'f:28:base','label'=>'كومبو','price'=>'150.00']],
        'preparation_options'=>[['id'=>'','label'=>'عادي','price'=>'130.00','feature_id'=>0,'product_clean'=>''],
            ['id'=>'f:28:base','label'=>'كومبو','price'=>'150.00','feature_id'=>28,'product_clean'=>'']]];
    primaryRun();$row=OwnerChoiceDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();
    return['draft'=>(int)$row->id,'expected'=>['conversation'=>$c,'control_revision'=>1,'draft_revision'=>(int)$row->revision,
        'floor'=>(int)$row->evidence_floor,'ceiling'=>(int)$row->evidence_ceiling,'snapshot_hash'=>$row->evidence_hash]];
}
function ownerChoiceState():array{
    $state=customerRecapRecoveryState();
    foreach(['whatsapp_inbox_messages','branch_customers','test_tickets','test_print']as$table)
        $state[$table]=OwnerChoiceDB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
    return$state;
}
function ownerChoiceBlocked(array$case,string$label):void{
    global$workflow;$before=ownerChoiceState();$blocked=false;
    try{$workflow->resolveCustomerReceiptChoice($case['draft'],$case['expected'],true);}catch(WorkflowBlocked$error){$blocked=true;}
    ownerChoiceExpect($blocked,$label.' refuses execution');
    ownerChoiceExpect(ownerChoiceState()===$before,$label.' leaves native rows, customer, draft, controls, scans and ERP unchanged');
    customerRecapNoErp($label.' cannot send or create ERP work');
}

$case=ownerChoiceSetup();$before=ownerChoiceState();$connection=OwnerChoiceDB::connection();$grammar=$connection->getQueryGrammar();
// MySQL grammar emits FOR UPDATE if any check-path code requests a lock. SQLite
// rejects that syntax, and query_only also rejects every accidental SQL write.
$connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar());$connection->getPdo()->exec('PRAGMA query_only=ON');
try{$checked=$workflow->resolveCustomerReceiptChoice($case['draft'],$case['expected']);}
finally{$connection->getPdo()->exec('PRAGMA query_only=OFF');$connection->setQueryGrammar($grammar);}
ownerChoiceExpect($checked['status']==='READY_TO_DISPATCH'&&$checked['writes']===0&&$checked['erp_writes']===0
    &&$checked['branch']==='f:363'&&count($checked['items'])===5,'read-only nonlocking check derives current native point and all five live items');
ownerChoiceExpect($checked['items'][4]===['product_id'=>2557,'quantity'=>'2','quantity_mode'=>'piece','option_id'=>''],
    'only reviewed fifth line resolves to two normal Nabroh sandwiches');
ownerChoiceExpect(ownerChoiceState()===$before,'read-only check reserves no receipt, quote, choice, customer, scan or ERP row');
primaryRun();customerRecapNoErp('check never installs owner choice into normal worker or global matching');

$case=ownerChoiceSetup();$native=OwnerChoiceDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();
$extraction=OwnerChoiceDB::table('whatsapp_order_drafts')->where('id',$case['draft'])->value('extraction');
$sent=$workflow->resolveCustomerReceiptChoice($case['draft'],$case['expected'],true);
ownerChoiceExpect($sent['status']==='DISPATCHED'&&$sent['ticket_id']>0&&OwnerChoiceDB::table('test_tickets')->count()===1
    &&OwnerChoiceDB::table('test_print')->count()===1,'explicit execution uses existing atomic phone-ticket and print path once');
ownerChoiceExpect(array_column(OwnerChoicePos::$lastPayload['items'],'product_id')===[2551,602,2576,2566,2557]
    &&array_column(OwnerChoicePos::$lastPayload['items'],'quantity')===['1','1','0.5','1','2'],
    'owner choice preserves every other item and exact mapped quarter pack amount');
$row=OwnerChoiceDB::table('whatsapp_order_drafts')->where('id',$case['draft'])->first();
$sealed=json_decode(OwnerChoiceCrypt::decryptString($row->sealed_payload),true,32,JSON_THROW_ON_ERROR);
ownerChoiceExpect(($sealed['owner_item_choice']['choice']['expected']??null)===$case['expected']
    &&($sealed['owner_item_choice']['choice']['product_id']??null)===2557&&($sealed['owner_item_choice']['actor_id']??null)===7,
    'encrypted sealed quote retains exact native bounds, original revision and owner item-choice audit');
ownerChoiceExpect($row->extraction===$extraction&&OwnerChoiceDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
    'owner choice never rewrites customer receipt extraction, native content or point');
$before=ownerChoiceState();$again=$workflow->resolveCustomerReceiptChoice($case['draft'],$case['expected'],true);
ownerChoiceExpect($again['status']==='ALREADY_DISPATCHED'&&$again['ticket_id']===$sent['ticket_id']&&$again['writes']===0
    &&count($again['items'])===5&&ownerChoiceState()===$before,'same original request returns only existing ticket despite advanced draft revision');
$changed=$case['expected'];$changed['draft_revision']++;$blocked=false;
try{$workflow->resolveCustomerReceiptChoice($case['draft'],$changed,true);}catch(WorkflowBlocked$error){$blocked=true;}
ownerChoiceExpect($blocked&&ownerChoiceState()===$before,'changed owner-choice replay cannot obtain another ticket or modify sealed audit');
customerRecapSilent('scoped owner choice never uses AI or sends customer messages');

insertMessage($case['expected']['conversation'],'wamid.owner-choice.repeat-summary','inbound',branchSummaryText(),6,null,'US.customer-confirmed');
insertMessage($case['expected']['conversation'],'wamid.owner-choice.repeat-pin','inbound','',7,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');
insertMessage($case['expected']['conversation'],'wamid.owner-choice.repeat-approval','inbound','كل شئ تمام',8,null,'US.customer-confirmed');
primaryRun();$new=OwnerChoiceDB::table('whatsapp_order_drafts')->orderByDesc('id')->first();
$repeat=['conversation'=>(int)$new->conversation_id,'control_revision'=>1,'draft_revision'=>(int)$new->revision,
    'floor'=>(int)$new->evidence_floor,'ceiling'=>(int)$new->evidence_ceiling,'snapshot_hash'=>$new->evidence_hash];
$before=ownerChoiceState();$blocked=false;
try{$workflow->resolveCustomerReceiptChoice((int)$new->id,$repeat,true);}catch(WorkflowBlocked$error){$blocked=true;}
ownerChoiceExpect($blocked&&ownerChoiceState()===$before&&OwnerChoiceDB::table('test_tickets')->count()===1,
    'new native IDs with the same same-day basket cannot duplicate the already dispatched owner choice');

foreach(['conversation','control_revision','draft_revision','floor','ceiling','snapshot_hash']as$guard){
    $case=ownerChoiceSetup();
    if($guard==='snapshot_hash')$case['expected'][$guard]=str_repeat('a',64);else$case['expected'][$guard]++;
    ownerChoiceBlocked($case,'stale expected '.$guard);
}
foreach(['master-disabled','mode-changed','capture-pending','capture-failure','lease-live','new-inbound','previous-cairo-day',
    'product-renamed','product-unavailable','product-unit','product-price','normal-missing','normal-renamed','literal-competitor']as$guard){
    $case=ownerChoiceSetup();
    if($guard==='master-disabled')OwnerChoiceDB::table('meta_ai_controls')->update(['enabled'=>false]);
    if($guard==='mode-changed')PrimaryFixtureControl::$analysisOnly=false;
    if($guard==='capture-pending')OwnerChoiceDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
    if($guard==='capture-failure'){
        $event=OwnerChoiceDB::table('whatsapp_webhook_events')->insertGetId(['processed_at'=>now('UTC')]);
        OwnerChoiceDB::table('whatsapp_inbox_ingestion_failures')->insert(['event_id'=>$event,'reason'=>'INVALID_EVENT','attempts'=>1,'last_attempted_at'=>now('UTC')]);
    }
    if($guard==='lease-live')OwnerChoiceDB::table('whatsapp_order_scans')->update(['claim_nonce'=>'other-worker','lease_until'=>now('UTC')->addMinute()]);
    if($guard==='new-inbound')insertMessage($case['expected']['conversation'],'wamid.owner-choice.changed','inbound','بدل السردين',5,null,'US.customer-confirmed');
    if($guard==='previous-cairo-day')customerRecapClock('2026-10-10 21:00:00');
    if($guard==='product-renamed')DialogueFixtureCatalog::$products[4]['name']='ساندوتش فسيخ دسوق مظبوط';
    if($guard==='product-unavailable')DialogueFixtureCatalog::$products[4]['available']=false;
    if($guard==='product-unit')DialogueFixtureCatalog::$products[4]['quantity_mode']='weight';
    if($guard==='product-price'){
        DialogueFixtureCatalog::$products[4]['price']='131.00';DialogueFixtureCatalog::$products[4]['preparation_options'][0]['price']='131.00';
    }
    if($guard==='normal-missing')array_shift(DialogueFixtureCatalog::$products[4]['preparation_options']);
    if($guard==='normal-renamed')DialogueFixtureCatalog::$products[4]['preparation_options'][0]['label']='كامل';
    if($guard==='literal-competitor')DialogueFixtureCatalog::$products[]=['id'=>999,'name'=>'ساندوتش فسيخ','available'=>true,
        'quantity_mode'=>'piece','price'=>'130.00','options'=>[]];
    ownerChoiceBlocked($case,$guard);
}

foreach(['menu-drift','price-drift','control-drift','capture-drift','cairo-midnight']as$race){
    $case=ownerChoiceSetup();app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
    CustomerRecapRaceDelivery::$duringQuote=static function()use($race):void{
        if($race==='menu-drift')DialogueFixtureCatalog::$products[4]['name']='ساندوتش آخر';
        if($race==='price-drift')DialogueFixtureCatalog::$products[4]['price']='131.00';
        if($race==='control-drift')OwnerChoiceDB::table('meta_ai_controls')->increment('revision');
        if($race==='capture-drift')OwnerChoiceDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
        if($race==='cairo-midnight')customerRecapClock('2026-10-10 21:00:00');
    };
    ownerChoiceBlocked($case,'quote boundary '.$race);CustomerRecapRaceDelivery::$duringQuote=null;
}
$case=ownerChoiceSetup(str_replace(["• علبة بطارخ 400 جرام — 300 جنيه\n",'1020 جنيه'],['','720 جنيه'],branchSummaryText()));
ownerChoiceBlocked($case,'four-line receipt cannot bypass fifth-line choice guard');
$case=ownerChoiceSetup(str_replace(['260 جنيه (الواحد 130ج)','1020 جنيه'],['262 جنيه (الواحد 131ج)','1022 جنيه'],branchSummaryText()));
ownerChoiceBlocked($case,'customer-declared sandwich price must still agree with the specifically reviewed live price');
class OwnerChoiceSaveRolloverPos extends PackAliasSelectFixturePos {
    public function save($channel,array$values,$actor):array{
        $result=parent::save($channel,$values,$actor);customerRecapClock('2026-10-10 21:00:00');return$result;
    }
}
$case=ownerChoiceSetup();app()->instance(OwnerChoicePos::class,new OwnerChoiceSaveRolloverPos());
ownerChoiceBlocked($case,'Cairo midnight after ERP save rolls customer, ticket, kitchen, print and sealed owner audit back');
echo 'whatsapp_customer_receipt_owner_choice_workflow: '.$ownerChoiceChecks." checks passed\n";
