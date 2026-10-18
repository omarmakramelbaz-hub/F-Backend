<?php
// Actual captured format, real native customer pin, literal synthetic live catalog.
require __DIR__.'/whatsapp_customer_confirmation_day_workflow_test.php';
use Illuminate\Support\Facades\DB as BranchSummaryDB;
use App\Services\Dashboard\WhatsAppOrderAiProvider as BranchSummaryAi;
use App\Services\Dashboard\PosServiceTicket as BranchSummaryPos;

$branchSummaryChecks=0;
function branchSummaryExpect($ok,string$label):void{
    $GLOBALS['branchSummaryChecks']++;if(!$ok)throw new RuntimeException('CUSTOMER_BRANCH_SUMMARY_FAILED:'.$label);
}
function branchSummaryText():string{
    return "ملخص الأوردر - فرع نبروه ✅\n\nالأصناف:\n"
        ."• علبة بطارخ 400 جرام — 300 جنيه\n• كيلو سردين — 200 جنيه\n• نص رنجة مبطرخة — 110 جنيه\n"
        ."• ربع سلطة رنجة — 150 جنيه\n• ساندوتشين فسيخ — 260 جنيه (الواحد 130ج)\n"
        ."الإجمالي: 1020 جنيه بدون التوصيل - سعر التوصيل يحدده المندوب حسب اللوكيشن\n\n"
        ."البيانات:\nالاسم: عميل اختبار\nالعنوان: شارع البحر المنصورة\nأرقام التواصل: 201000000001 / 201000000002\n"
        ."الفرع: نبروه والمصنع (أمام بوابة نبروه) - 201000000003\n"
        .'مواعيد التجهيز: من 9 صباحا حتي 12 ليلا، والدليفري خلال 60 دقيقة داخل نبروه';
}
function branchSummaryCatalog():array{
    $result=[];
    foreach([[601,'علبة بطارخ 400 جرام','piece','300.00'],[602,'سردين','weight','200.00'],
        [603,'رنجة مبطرخة','weight','220.00'],[604,'سلطة رنجة','weight','600.00'],[605,'ساندوتش فسيخ','piece','130.00']]as[$id,$name,$mode,$price])
        $result[]=['id'=>$id,'name'=>$name,'quantity_mode'=>$mode,'price'=>$price,'available'=>true,'options'=>[]];
    return$result;
}
class BranchSummaryFixturePos extends BranchSummaryPos {
    public function quote($channel,array$values,$actor):array{
        abort_unless($channel==='phone'&&isset($values['delivery_quote_hash']),422,'BRANCH_SUMMARY_PHONE_CONTRACT');
        app(\App\Services\Dashboard\TakeawayAccess::class)->branch($values['branch'],$actor);
        $products=array_column(DialogueFixtureCatalog::$products,null,'id');$total=0;
        foreach($values['items']as$item){
            $product=$products[$item['product_id']]??null;
            abort_unless($product&&$product['available']===true&&$product['quantity_mode']===$item['quantity_mode']
                &&$item['option_id']===''&&is_numeric($item['quantity'])&&(float)$item['quantity']>0,422,'BRANCH_SUMMARY_LIVE_ITEM_CONTRACT');
            $total+=(int)round((float)$item['quantity']*(float)$product['price']*100);
        }
        return['quote_hash'=>self::fingerprint([$values['branch'],$values['items'],$total]),
            'total'=>number_format($total/100,2,'.',''),'total_cents'=>$total];
    }
}
function branchSummarySetup(?string$approval='تمام كله تمام',?array$point=['lat'=>31.04,'long'=>31.37],bool$priorAddress=true,
    string$recapUtc='2026-10-09 22:02:00',string$pinUtc='2026-10-09 22:03:00',
    string$approvalUtc='2026-10-09 22:04:00',string$nowUtc='2026-10-09 22:05:00'):int{
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;dayControl();customerRecapClock($nowUtc);
    $c=addConversation('US.customer-confirmed');
    if($priorAddress){
        insertMessage($c,'wamid.branch-summary.address','inbound','اسمي عميل اختبار وعنواني شارع البحر المنصورة ورقمي 201000000001.',
            daySecond($recapUtc)-20,null,'US.customer-confirmed');
        insertMessage($c,'wamid.branch-summary.early-ok','inbound','تمام',daySecond($recapUtc)-10,null,'US.customer-confirmed');
    }
    insertMessage($c,'wamid.branch-summary.recap','inbound',branchSummaryText(),daySecond($recapUtc),null,'US.customer-confirmed');
    if($point!==null)insertMessage($c,'wamid.branch-summary.pin','inbound','',daySecond($pinUtc),$point,'US.customer-confirmed');
    if($approval!==null)insertMessage($c,'wamid.branch-summary.approval','inbound',$approval,daySecond($approvalUtc),null,'US.customer-confirmed');
    DialogueFixtureCatalog::$products=branchSummaryCatalog();app()->instance(BranchSummaryPos::class,new BranchSummaryFixturePos());
    BranchSummaryAi::$data=extractionData();return$c;
}

foreach([true,false]as$priorAddress){
    branchSummarySetup(priorAddress:$priorAddress);$native=BranchSummaryDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();$r=primaryRun();
    branchSummaryExpect($r['errors']===0&&$r['dispatched']===1&&BranchSummaryAi::$calls===0,
        'customer observed branch summary plus later real native pin and separate whole approval dispatches silently');
    $data=customerRecapStored();$receipt=$data['evidence_receipt']??[];
    branchSummaryExpect(($receipt['summary_format']??null)==='BRANCH_ORDER_SUMMARY'
        &&($receipt['recap_id']??null)!==($receipt['approval_id']??null)&&!isset($receipt['approval_basis']),
        'observed branch summary remains separate native customer consent, never compact self-confirmation');
    branchSummaryExpect(BranchSummaryDB::table('test_tickets')->value('branch')==='f:363'
        &&BranchSummaryDB::table('branch_customers')->value('address')==='شارع البحر المنصورة',
        'native Mansoura point determines physical branch while Nabroh header remains descriptive');
    branchSummaryExpect(array_column(BranchSummaryPos::$lastPayload['items'],'quantity')===['1','1','0.5','0.25','2']
        &&array_column(BranchSummaryPos::$lastPayload['items'],'quantity_mode')===['piece','weight','weight','weight','piece'],
        '400g package stays one piece and sandwiches two pieces while explicit fish weights remain exact');
    branchSummaryExpect(BranchSummaryDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
        'observed receipt preserves every encrypted native message without invented pin or business echo');
    customerRecapSilent('observed branch summary never sends an internal reply');
    primaryRun();branchSummaryExpect(BranchSummaryDB::table('test_tickets')->count()===1&&BranchSummaryDB::table('test_print')->count()===1,
        'same observed native approval cannot repeat ERP ticket or print queue');
}
$c=branchSummarySetup();insertMessage($c,'wamid.branch-summary.old-zero-pin','inbound','',
    daySecond('2026-10-09 22:01:00'),['lat'=>0,'long'=>0],'US.customer-confirmed');$r=primaryRun();
branchSummaryExpect($r['errors']===0&&$r['dispatched']===1,
    'new separately approved post-recap pin supersedes an unrelated invalid pin before selected recap');
foreach([null,'تمام؟','تمام كله تمام بس خلي السردين 2 كيلو']as$approval){
    branchSummarySetup(approval:$approval);primaryRun();customerRecapNoErp('separate consent cannot be missing questioning or changed');
}
branchSummarySetup(point:null);primaryRun();customerRecapNoErp('summary header and written address cannot manufacture a native delivery point');
branchSummarySetup(point:['lat'=>0,'long'=>0]);primaryRun();customerRecapNoErp('zero native point cannot authorize delivery');
branchSummarySetup(pinUtc:'2026-10-09 22:01:00');primaryRun();customerRecapNoErp('pre-recap pin is not retroactively bound to observed copied summary');
branchSummarySetup(pinUtc:'2026-10-09 22:04:30');primaryRun();customerRecapNoErp('pin after separate approval is a later material turn');
$c=branchSummarySetup();insertMessage($c,'wamid.branch-summary.different-pin','inbound','',daySecond('2026-10-09 22:03:30'),
    ['lat'=>31.13,'long'=>31.30],'US.customer-confirmed');primaryRun();customerRecapNoErp('conflicting native pins cannot be silently selected before approval');
branchSummarySetup();DialogueFixtureCatalog::$products[2]['name']='رنجة مختلفة';primaryRun();
customerRecapNoErp('observed item wording cannot invent an alias to another live product');
branchSummarySetup();DialogueFixtureCatalog::$products[0]['available']=false;primaryRun();
customerRecapNoErp('confirmed copied item cannot override live catalog availability');

branchSummarySetup(recapUtc:'2026-10-09 20:58:00',pinUtc:'2026-10-09 20:58:30',approvalUtc:'2026-10-09 21:00:10',nowUtc:'2026-10-09 21:01:00');
primaryRun();customerRecapNoErp('new today approval cannot renew yesterday observed branch summary');
branchSummaryExpect(BranchSummaryDB::table('whatsapp_order_drafts')->value('reason')==='CUSTOMER_ORDER_PREVIOUS_DAY',
    'observed native-pin variant reuses the Cairo calendar-day authority guard');
branchSummarySetup(recapUtc:'2026-10-09 20:57:00',pinUtc:'2026-10-09 20:58:00',approvalUtc:'2026-10-09 20:58:30',nowUtc:'2026-10-09 20:59:00');
app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static fn()=>customerRecapClock('2026-10-09 21:00:00');
primaryRun();customerRecapNoErp('observed branch summary cannot cross Cairo midnight during quote and commit an old order');

echo 'whatsapp_customer_branch_summary_workflow: '.$branchSummaryChecks." checks passed\n";
