<?php
require __DIR__.'/whatsapp_customer_two_message_workflow_test.php';
use App\Services\Dashboard\WhatsAppOrderAiProvider as FlexWorkflowAi;
use App\Support\WhatsAppCustomerOrderConfirmation as FlexWorkflowProof;
use Illuminate\Support\Facades\DB as FlexDB;
use Illuminate\Support\Facades\Crypt as FlexCrypt;
$flexWorkflowChecks=0;
function flexWorkflowExpect($ok,string$label):void{global$flexWorkflowChecks;$flexWorkflowChecks++;if(!$ok)throw new RuntimeException('FLEX_AI_WORKFLOW_FAILED:'.$label);}
function flexWorkflowText():string{return 'محتاج كيلو فسيخ. اسمي عميل تجريبي، تليفوني 01000000001 والعنوان شارع البحر. اللوكيشن 31.04,31.37. الإجمالي 100 جنيه';}
class FlexibleWorkflowProvider extends FlexWorkflowAi {
    public static $flexCalls=0,$during=null,$failed=false;
    public function extractCustomerRecap(array$transcript):array{
        self::$flexCalls++;
        flexWorkflowExpect(FlexDB::connection()->transactionLevel()===0,'AI network work stays outside database locks');
        $turns=[];$recap=null;
        foreach($transcript as$row)if($row['speaker']==='customer'&&is_string($row['text'])){
            $kind=$row['text']===flexWorkflowText()?'SUMMARY':'OTHER';
            if($kind==='SUMMARY')$recap=$row['id'];$turns[]=['id'=>$row['id'],'kind'=>$kind];
        }
        if(self::$during)(self::$during)();
        if(self::$failed)return['ok'=>false,'reason'=>'HTTP_ERROR','data'=>null,'diagnostic'=>['stage'=>'TRANSPORT','http_status'=>429]];
        return FlexWorkflowProof::validateAi(['status'=>'COMPLETE','recap_id'=>$recap,'turns'=>$turns,'fulfillment'=>'DELIVERY',
            'customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001','address'=>'شارع البحر','area'=>null,'notes'=>null],
            'items'=>[['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','quantity_source'=>'كيلو فسيخ','option_hint'=>null]],
            'total_source'=>'100 جنيه','location_literal'=>'31.04,31.37'],$transcript);
    }
}
function flexWorkflowSetup(?string$approval='كل شئ تمام'):int{
    $c=customerRecapSetup(flexWorkflowText(),$approval);
    FlexibleWorkflowProvider::$flexCalls=0;FlexibleWorkflowProvider::$during=null;FlexibleWorkflowProvider::$failed=false;
    app()->instance(FlexWorkflowAi::class,new FlexibleWorkflowProvider());return$c;
}
$c=flexWorkflowSetup();
customerRecapNativeExtra($c,'wamid.flex.sticker','sticker',5,['id'=>'MEDIA_SYNTHETIC','mime_type'=>'image/webp','sha256'=>base64_encode(str_repeat('x',32))]);
insertMessage($c,'wamid.flex.followup','inbound','هل الطلب وصل للفرع؟',6,null,'US.customer-confirmed');
$native=FlexDB::table('whatsapp_inbox_messages')->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all();
$r=primaryRun();$data=customerRecapStored();
flexWorkflowExpect($r['errors']===0&&$r['dispatched']===1&&FlexibleWorkflowProvider::$flexCalls===1,
    'natural customer summary and exact approval silently dispatch with nontext and status inquiry after pair');
flexWorkflowExpect(FlexDB::table('whatsapp_order_drafts')->value('ticket_id')!==null
    &&$data['evidence_receipt']['summary_format']==='AI_CUSTOMER_ORDER_SUMMARY','actual ERP ticket is bound to AI-grounded customer receipt');
flexWorkflowExpect(FlexDB::table('whatsapp_inbox_messages')->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all()===$native,
    'AI does not rewrite any native identity, ciphertext or timestamps');
customerRecapSilent('flexible path keeps Meta agent as the responder');
primaryRun();flexWorkflowExpect(FlexDB::table('test_tickets')->count()===1&&FlexibleWorkflowProvider::$flexCalls===1,'repeat processing creates no duplicate or unnecessary AI call');

$c=flexWorkflowSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
flexWorkflowExpect(FlexibleWorkflowProvider::$flexCalls===1&&FlexDB::table('test_tickets')->count()===0,'AI understanding respects independent closed dispatch clock');
PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'10:00','all_day'=>false];
PrimaryFixtureControl::$strictDispatchClock=false;customerRecapClock('2026-10-09 22:07:00');$r=primaryRun();
flexWorkflowExpect($r['dispatched']===1&&FlexibleWorkflowProvider::$flexCalls===1,'cached AI source proof dispatches without another network call when analysis clock is closed');

foreach(['previous-day','catalog-unavailable','nearest-denied','pending-capture','no-confirmation','provider-unavailable','control-race']as$guard){
    $c=flexWorkflowSetup($guard==='no-confirmation'?null:'كل شئ تمام');
    if($guard==='previous-day')customerRecapClock('2026-10-10 21:00:01');
    if($guard==='catalog-unavailable')DialogueFixtureCatalog::$products[0]['available']=false;
    if($guard==='nearest-denied')app('config')->set('whatsapp_orders.allowed_branch_ids',['94','301','307']);
    if($guard==='pending-capture')FlexDB::table('whatsapp_webhook_events')->insert(['processed_at'=>null]);
    if($guard==='provider-unavailable')FlexibleWorkflowProvider::$failed=true;
    if($guard==='control-race')FlexibleWorkflowProvider::$during=static fn()=>FlexDB::table('meta_ai_controls')->update(['revision'=>2,'enabled'=>false]);
    primaryRun();customerRecapNoErp('flexible AI retains '.$guard.' gate');
    if(in_array($guard,['pending-capture','no-confirmation'],true))flexWorkflowExpect(FlexibleWorkflowProvider::$flexCalls===0,'guard avoids provider before '.$guard);
}

$c=flexWorkflowSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$draft=FlexDB::table('whatsapp_order_drafts')->first();$cached=customerRecapStored();$cached['items'][0]['quantity']='3';
FlexDB::table('whatsapp_order_drafts')->where('id',$draft->id)->update(['extraction'=>FlexCrypt::encryptString(json_encode($cached))]);
PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'10:00','all_day'=>false];
PrimaryFixtureControl::$strictDispatchClock=false;customerRecapClock('2026-10-09 22:07:00');primaryRun();
customerRecapNoErp('cached AI data cannot alter original quantities while analysis is closed');

$c=flexWorkflowSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$draft=FlexDB::table('whatsapp_order_drafts')->first();
FlexDB::table('whatsapp_order_drafts')->where('id',$draft->id)->update(['extraction'=>FlexCrypt::encryptString(json_encode(['decision'=>'NONE'])),
    'status'=>'NONE','reason'=>'NO_ORDER']);
FlexDB::table('whatsapp_order_scans')->update(['attempts'=>3,'next_attempt_at'=>now('UTC')->addMinute()]);
$before=(array)FlexDB::table('whatsapp_order_scans')->first();$calls=FlexibleWorkflowProvider::$flexCalls;
primaryRun();flexWorkflowExpect((array)FlexDB::table('whatsapp_order_scans')->first()===$before&&FlexibleWorkflowProvider::$flexCalls===$calls,'upgrade respects pending backoff and exhausted attempts');
customerRecapClock('2026-10-09 22:07:00');PrimaryFixtureControl::$strictDispatchClock=false;$r=primaryRun();
flexWorkflowExpect($r['dispatched']===1&&(int)FlexDB::table('whatsapp_order_scans')->value('attempts')===3
    &&(int)FlexDB::table('whatsapp_order_drafts')->value('id')===(int)$draft->id,'normal due AI refresh retains old attempts and reuses pending draft');
echo"WHATSAPP_CUSTOMER_FLEXIBLE_AI_WORKFLOW_OK checks=$flexWorkflowChecks\n";
