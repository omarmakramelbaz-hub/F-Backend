<?php
// Real customer-native compact recaps, current receipt reproving and silent ERP flow.
// Existing suites run first so the old separate-consent protocol stays covered.
require __DIR__.'/whatsapp_customer_approved_alias_workflow_test.php';
use Illuminate\Support\Facades\DB as CompactDB;
use Illuminate\Support\Facades\Crypt as CompactCrypt;
use App\Services\Dashboard\WhatsAppOrderAiProvider as CompactAi;
use App\Services\Dashboard\PosServiceTicket as CompactPos;

$compactWorkflowChecks=0;
function compactExpect($ok,string$label):void{
    $GLOBALS['compactWorkflowChecks']++;
    if(!$ok)throw new RuntimeException('CUSTOMER_COMPACT_WORKFLOW_FAILED:'.$label);
}
function compactText(?string$item=null):string{
    return "تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً.\n\nطلبك المؤكد:\n"
        .($item??'• كيلو رنجة مبطرخ (كاملة) - 220 جنيه')."\nالإجمالي 220 جنيه غير التوصيل\n\n"
        ."البيانات: عميل اختبار - شارع البحر المنصورة - 201000000001 - اللوكيشن 31.04,31.37\n"
        .'التوصيل خلال 60 دقيقة داخل المنصورة، وسعر التوصيل يأكده المندوب لما يكلمك.';
}
function compactWholeHerring():array{
    $product=prepProduct('رنجه هيرنج مبطرخ');$product['id']=2576;return$product;
}
function compactSetup(?string$text=null):int{
    $c=customerRecapSetup($text??compactText(),'كل شئ تمام');
    DialogueFixtureCatalog::$products=[compactWholeHerring()];
    app()->instance(CompactPos::class,new ApprovedAliasPos());
    return$c;
}
class CompactCanonicalDelivery extends \App\Services\Dashboard\PhoneDelivery {
    public $afterFirstQuote=null;
    private int$quotes=0;
    public function quote(array$values,$actor):array{
        $settings=$this->settings($values['branch'],$actor);
        $result=parent::quote($values,$actor);
        $result['delivery']['delivery_quote_hash']=CompactPos::fingerprint([
            $result['delivery']['delivery_quote_hash'],$settings['latitude'],$settings['longitude'],$settings['km_price']]);
        if(++$this->quotes===1&&$this->afterFirstQuote)($this->afterFirstQuote)();
        return$result;
    }
}

$c=compactSetup();$native=CompactDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();$r=primaryRun();
$stored=customerRecapStored();$receipt=$stored['evidence_receipt']??[];
compactExpect($r['errors']===0&&$r['dispatched']===1&&CompactAi::$calls===0,
    'one authenticated customer compact recap with distinct exact confirmation dispatches without model call');
compactExpect(!isset($receipt['approval_basis'])
    &&($receipt['recap_id']??null)!==($receipt['approval_id']??null)
    &&($receipt['recap_sha256']??null)!==($receipt['approval_sha256']??null),
    'compact text and separate actual customer confirmation retain distinct native authority');
compactExpect(CompactPos::$lastPayload['items'][0]===['product_id'=>2576,'quantity'=>'1','quantity_mode'=>'weight',
    'option_id'=>'','feature_id'=>0,'product_clean'=>''],
    'parenthesized whole herring retains exact approved SKU and proven real whole preparation');
compactExpect(CompactDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
    'self-confirmation manufactures no native business or approval row');
compactExpect(CompactDB::table('test_billing')->count()===0,'compact receipt creates an unpaid order only');
customerRecapSilent('compact customer receipt remains silent');
primaryRun();compactExpect(CompactDB::table('test_tickets')->count()===1&&CompactDB::table('test_print')->count()===1,
    'worker replay of same compact input produces one ticket and print queue entry');
insertMessage($c,'wamid.compact.fresh-repeat','inbound',compactText(),302,null,'US.customer-confirmed');
insertMessage($c,'wamid.compact.fresh-repeat-ok','inbound','كل شئ تمام',303,null,'US.customer-confirmed');dialogueAdvance(304);$r=primaryRun();
compactExpect($r['dispatched']===0&&CompactDB::table('test_tickets')->count()===1
    &&CompactDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='CUSTOMER_RECAP_REPLAY',
    'new native ID for the same confirmed compact basket cannot recreate a completed order');
customerRecapSilent('new-ID compact duplicate sends no reply');

// All three literal owner-approved items keep separate live sale identities.
$text=str_replace(['• كيلو رنجة مبطرخ (كاملة) - 220 جنيه','الإجمالي 220 جنيه'],[
    "• كيلو رنجة مبطرخ (كاملة) - 220 جنيه\n• كيلو فسيخ نبروه 3 سمكات كامل - 400 جنيه\n• كيلو سردين بلدي كبير مخلي - 250 جنيه",
    'الإجمالي 870 جنيه'],compactText());
compactSetup($text);$fish=prepProduct('فسيخ نبروه 3 سمكات');$fish['id']=2533;
DialogueFixtureCatalog::$products=[compactWholeHerring(),$fish,aliasProduct('sardine')];$r=primaryRun();
compactExpect($r['errors']===0&&$r['dispatched']===1&&CompactAi::$calls===0,
    'complete compact three-item recap is mapped without model-selected products');
compactExpect(array_column(CompactPos::$lastPayload['items'],'product_id')===[2576,2533,2578]
    &&array_column(CompactPos::$lastPayload['items'],'option_id')===['','','f:0:extra_clear'],
    'whole fish and actual sardine fillet option retain live identities');
customerRecapSilent('three-item compact receipt neither asks nor replies');

// Exact whole preparation is a sale choice, never permission to strip qualifiers.
$request=['name'=>'رنجة مبطرخ (كاملة)','quantity'=>'1','quantity_mode'=>'weight'];
foreach(['missing-preparation','missing-counterpart','wrong-counterpart-price','wrong-id','renamed','unavailable']as$guard){
    compactSetup();$product=compactWholeHerring();
    if($guard==='missing-preparation')unset($product['preparation_options']);
    if($guard==='missing-counterpart')$product['options']=[];
    if($guard==='wrong-counterpart-price')foreach($product['options']as&$option)unset($option['price']);unset($option);
    if($guard==='wrong-id')$product['id']++;
    if($guard==='renamed')$product['name'].=' كبير';
    if($guard==='unavailable')$product['available']=false;
    compactExpect(aliasMap($request,[$product])===null,'compact whole alias requires '.$guard);
}
foreach(['رنجة مبطرخ (كاملة ممتازة)','رنجة مبطرخ (مخلي)','رنجة مبطرخ (كاملة) كبير','رنجة (مبطرخ) كاملة']as$name){
    compactSetup();$changed=$request;$changed['name']=$name;
    compactExpect(aliasMap($changed,[compactWholeHerring()])===null,'unapproved parentheses or qualifier is not discarded: '.$name);
}
foreach([null,'f:94','f:301','f:307']as$branch){
    compactSetup();compactExpect(aliasMap($request,[compactWholeHerring()],$branch)===null,
        'compact approved herring alias cannot cross unapproved or absent branch: '.($branch??'null'));
}
compactSetup();compactExpect(aliasMap($request,[compactWholeHerring()],'f:363',new ApprovedAliasMetaWorkflow())===null,
    'compact alias is not enabled in Meta message storage');

// Both independent clocks and the native receipt are reproved before ERP writes.
compactSetup();PrimaryFixtureControl::$strictDispatchClock=true;$r=primaryRun();
compactExpect($r['dispatched']===0&&$r['deferred']===1
    &&CompactDB::table('whatsapp_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'separately confirmed compact text cannot bypass 09:00-00:30 dispatch clock');
customerRecapNoErp('compact after-hours recap stays pending');
$calls=CompactAi::$calls;customerRecapClock('2026-10-10 06:00:00');$r=primaryRun();
compactExpect($r['dispatched']===1&&CompactAi::$calls===$calls,
    'scheduled opening reuses exact native compact receipt without extraction or reply');
primaryRun();compactExpect(CompactDB::table('test_tickets')->count()===1,'opening-time worker replay stays idempotent');

foreach(['basis','approval-row','item']as$tamper){
    compactSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();$data=customerRecapStored();
    if($tamper==='basis')$data['evidence_receipt']['approval_basis']='TRUST_CUSTOMER';
    if($tamper==='approval-row')$data['evidence_receipt']['approval_id']='m999';
    if($tamper==='item')$data['items'][0]['name']='رنجة أخرى';
    CompactDB::table('whatsapp_order_drafts')->update(['extraction'=>CompactCrypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR))]);
    PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],
        'start'=>'23:00','end'=>'02:00','all_day'=>false];
    customerRecapClock('2026-10-10 06:00:00');primaryRun();
    customerRecapNoErp('cached compact '.$tamper.' cannot replace fresh inspect proof while analysis is closed');
}
foreach(['customer-change','conflicting-pin','different-peer']as$change){
    $c=compactSetup();
    if($change==='customer-change')insertMessage($c,'wamid.compact.change','inbound','خلي الرنجة مخلي',4,null,'US.customer-confirmed');
    if($change==='conflicting-pin')insertMessage($c,'wamid.compact.change','inbound','',4,['lat'=>31.05,'long'=>31.38],'US.customer-confirmed');
    if($change==='different-peer')insertMessage($c,'wamid.compact.change','inbound','تمام',4,null,'US.someone-else');
    primaryRun();customerRecapNoErp('compact approval cannot absorb later '.$change);
}

// An already analyzed ordinary draft may upgrade only from the same re-inspected
// current native snapshot. No configuration, activation or draft history reset.
$c=compactSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$draft=CompactDB::table('whatsapp_order_drafts')->first();$scan=CompactDB::table('whatsapp_order_scans')->first();
CompactDB::table('whatsapp_order_drafts')->where('id',$draft->id)->update([
    'extraction'=>CompactCrypt::encryptString(json_encode(extractionData(),JSON_THROW_ON_ERROR)),
    'status'=>'REVIEW','reason'=>'REVIEW_REQUIRED']);
customerRecapClock('2026-10-09 22:06:01');primaryRun();
$after=CompactDB::table('whatsapp_order_scans')->first();$data=customerRecapStored();
compactExpect(!isset($data['evidence_receipt']['approval_basis'])
    &&($data['evidence_receipt']['recap_id']??null)!==($data['evidence_receipt']['approval_id']??null)
    &&CompactAi::$calls===0&&(int)$after->auto_floor===(int)$scan->auto_floor
    &&(int)$after->analyzed_ceiling===(int)$scan->analyzed_ceiling,
    'cached nonreceipt snapshot upgrades from authenticated compact receipt without provider or floor reset');
compactExpect(CompactDB::table('whatsapp_order_drafts')->count()===1,
    'compact upgrade retains one unique evidence draft rather than duplicating history');
customerRecapNoErp('closed schedule still blocks upgraded compact draft');

// Authenticated location drives the physical fulfillment branch. Text remains
// the customer's delivery description and cannot veto or replace their point.
foreach(['شارع البحر نبروه','عنوان توصيل بدون اسم مدينة']as$address){
    compactSetup(str_replace('شارع البحر المنصورة',$address,compactText()));$r=primaryRun();
    compactExpect($r['errors']===0&&$r['dispatched']===1&&CompactDB::table('test_tickets')->value('branch')==='f:363'
        &&CompactDB::table('branch_customers')->value('address')===$address,
        'explicit point selects Mansoura while preserving descriptive address: '.$address);
    customerRecapSilent('point-driven compact receipt never replies');
}
$text=str_replace('31.04,31.37','31.13,31.30',compactText('• كيلو فسيخ - 220 جنيه'));
compactSetup($text);DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','price'=>'100.00','options'=>[]]];
$r=primaryRun();compactExpect($r['errors']===0&&$r['dispatched']===1&&CompactDB::table('test_tickets')->value('branch')==='f:307',
    'Nabroh point selects physical Nabroh even when written address and delivery footer say Mansoura');
compactExpect(CompactDB::table('branch_customers')->value('address')==='شارع البحر المنصورة',
    'point selection does not rewrite customer address or silently geocode it');
customerRecapSilent('point-first Nabroh receipt stays silent');

foreach([[31.13,31.30],[0,0]]as[$oldLat,$oldLong]){
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;
    $c=addConversation('US.customer-confirmed');
    insertMessage($c,'wamid.compact.old-pin','inbound','',1,['lat'=>$oldLat,'long'=>$oldLong],'US.customer-confirmed');
    insertMessage($c,'wamid.compact.current','inbound',compactText(),2,null,'US.customer-confirmed');
    insertMessage($c,'wamid.compact.current-ok','inbound','كل شئ تمام',3,null,'US.customer-confirmed');
    DialogueFixtureCatalog::$products=[compactWholeHerring()];app()->instance(CompactPos::class,new ApprovedAliasPos());$r=primaryRun();
    compactExpect($r['errors']===0&&$r['dispatched']===1&&CompactDB::table('test_tickets')->value('branch')==='f:363'
        &&abs((float)CompactDB::table('branch_customers')->value('latitude')-31.04)<0.0000001,
        'new explicit compact point supersedes old unrelated or zero native pin without substitution');
    customerRecapSilent('new inline point authority sends no reply');
}
$c=compactSetup();insertMessage($c,'wamid.compact.matching-pin','inbound','',4,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');$r=primaryRun();
compactExpect($r['errors']===0&&$r['dispatched']===1,
    'later authenticated native pin with exactly matching compact point corroborates same order');
$c=compactSetup();insertMessage($c,'wamid.compact.invalid-later-pin','inbound','',4,['lat'=>0,'long'=>0],'US.customer-confirmed');
primaryRun();customerRecapNoErp('later zero native pin cannot overwrite or silently validate compact point');

foreach(['missing-point','invalid-point','nearest-denied','no-visible-branch','equal-distance','selected-rate']as$guard){
    compactSetup();
    if($guard==='missing-point')CompactDB::table('resturants')->where('id',301)->delete();
    if($guard==='invalid-point')CompactDB::table('resturants')->where('id',301)->update(['lat'=>'NaN']);
    if($guard==='nearest-denied')app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307']);
    if($guard==='no-visible-branch')CompactDB::table('test_users')->where('id',7)->update(['branches'=>'[]']);
    if($guard==='equal-distance')CompactDB::table('resturants')->whereIn('id',[307,363])->update(['lat'=>'31.04','lng'=>'31.37']);
    if($guard==='selected-rate')CompactDB::table('resturants')->where('id',363)->update(['km_price'=>'missing']);
    primaryRun();customerRecapNoErp('point routing cannot infer closest branch after '.$guard);
    compactExpect(CompactDB::table('whatsapp_order_drafts')->value('reason')==='BRANCH_UNRESOLVED',
        'unknown tied or disallowed branch remains explicit review: '.$guard);
}
compactSetup();CompactDB::table('resturants')->where('id',301)->update(['km_price'=>'missing']);$r=primaryRun();
compactExpect($r['errors']===0&&$r['dispatched']===1,
    'unselected far branch missing delivery rate does not erase its valid geometry or block ready closest branch');

compactSetup();CompactDB::table('test_branches')->insert(['id'=>309,'name'=>'المقطم ستور','status'=>'opened']);
CompactDB::table('resturants')->insert(['id'=>309,'name'=>'المقطم ستور','parent_id'=>301,'control'=>'show',
    'lat'=>'31.04','lng'=>'31.37','km_price'=>'1.00']);
CompactDB::table('test_users')->where('id',7)->update(['branches'=>'["f:94","f:301","f:307","f:363","f:309"]']);
app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307','363','309']);$r=primaryRun();
compactExpect($r['errors']===0&&$r['dispatched']===1&&CompactDB::table('test_tickets')->value('branch')==='f:363'
    &&!isset(CompactPos::$lastPayload['delivery_origin_branch']),
    'store point at customer location never replaces canonical physical fulfillment branch or tariff origin');

// The actual normal dispatcher repeats nearest-branch proof under the same
// transaction as ticket creation; live geometry changes cannot commit old route.
compactSetup(compactText('• كيلو فسيخ - 220 جنيه'));
DialogueFixtureCatalog::$products=[['id'=>11,'name'=>'فسيخ','available'=>true,'quantity_mode'=>'weight','price'=>'100.00','options'=>[]]];
app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static function():void{
    CompactDB::table('resturants')->where('id',363)->update(['lat'=>'30.00','lng'=>'30.00']);
};
primaryRun();customerRecapNoErp('closer-branch geometry change during quote cannot commit old fulfillment route');
compactSetup();$delivery=new CompactCanonicalDelivery();$delivery->afterFirstQuote=static function():void{
    CompactDB::table('resturants')->where('id',363)->update(['lat'=>'31.001']);
};app()->instance(\App\Services\Dashboard\PhoneDelivery::class,$delivery);
primaryRun();customerRecapNoErp('changed selected canonical point invalidates delivery quote even when closest branch identity stays the same');
compactSetup();app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static function():void{
    app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307']);
};
// This fixture changes configuration deliberately at a service boundary; call
// the worker directly because primaryRun asserts that no boundary mutates it.
$r=$workflow->process();customerRecapNoErp('nearest branch permission removed during quote cannot redirect or commit an ERP order');

echo 'whatsapp_customer_compact_confirmation_workflow: '.$compactWorkflowChecks." checks passed\n";
