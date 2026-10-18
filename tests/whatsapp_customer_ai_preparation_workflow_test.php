<?php
// Actual native projection, encrypted receipts and workflow; AI transport and
// ERP remain fixture boundaries. All identities and customer fields are synthetic.
require __DIR__.'/whatsapp_customer_flexible_ai_workflow_test.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as AiPrepProvider;
use App\Services\Dashboard\TakeawayCatalog as AiPrepCatalog;
use App\Services\Dashboard\PosServiceTicket as AiPrepPos;
use App\Support\WhatsAppCustomerOrderConfirmation as AiPrepProof;
use Illuminate\Support\Facades\DB as AiPrepDB;
use Illuminate\Support\Facades\Crypt as AiPrepCrypt;

$aiPreparationChecks=0;
function aiPrepExpect($ok,string $label):void {
    $GLOBALS['aiPreparationChecks']++;
    if(!$ok)throw new RuntimeException('AI_PREPARATION_WORKFLOW_FAILED:'.$label);
}
function aiPrepText(string $source):string {
    return 'محتاج '.$source.'. اسمي عميل تجريبي، تليفوني 01000000001 والعنوان شارع البحر. اللوكيشن 31.04,31.37. الإجمالي 250 جنيه';
}
function aiPrepProduct():array {
    return ['id'=>11,'name'=>'سردين','available'=>true,'quantity_mode'=>'weight','price'=>'100.00',
        'options'=>[
            ['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120.00'],
            ['id'=>'f:0:extra_clean','label'=>'تنظيف','price'=>'110.00']],
        'preparation_options'=>[
            ['id'=>'','label'=>'كامل','price'=>'100.00','feature_id'=>0,'product_clean'=>''],
            ['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120.00','feature_id'=>0,'product_clean'=>'extra_clear'],
            ['id'=>'f:0:extra_clean','label'=>'تنظيف','price'=>'110.00','feature_id'=>0,'product_clean'=>'extra_clean']]];
}
class AiPreparationProvider extends AiPrepProvider {
    public static int $callsMade=0;
    public static string $source='سردين (كيلو) مخلي صافي';
    public static ?string $hint='مخلي';
    public static ?string $sourceOverride=null;
    public static string $extractedName='سردين';
    public function extractCustomerRecap(array $transcript):array {
        self::$callsMade++;
        aiPrepExpect(AiPrepDB::connection()->transactionLevel()===0,'preparation AI remains outside transaction');
        $recap=null;$turns=[];
        foreach($transcript as $row)if($row['speaker']==='customer'&&is_string($row['text'])) {
            $kind=$row['text']===aiPrepText(self::$source)?'SUMMARY':'OTHER';
            if($kind==='SUMMARY')$recap=$row['id'];
            $turns[]=['id'=>$row['id'],'kind'=>$kind];
        }
        return AiPrepProof::validateAi(['status'=>'COMPLETE','recap_id'=>$recap,'turns'=>$turns,'fulfillment'=>'DELIVERY',
            'customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001','address'=>'شارع البحر','area'=>null,'notes'=>null],
            'items'=>[['name'=>self::$extractedName,'quantity'=>'1','quantity_mode'=>'weight',
                'quantity_source'=>self::$sourceOverride??self::$source,'option_hint'=>self::$hint]],
            'total_source'=>'250 جنيه','location_literal'=>'31.04,31.37'],$transcript);
    }
}
function aiPrepSetup(string $source='سردين (كيلو) مخلي صافي',?string $hint='مخلي'):int {
    $c=customerRecapSetup(aiPrepText($source),'كل شئ تمام');
    AiPreparationProvider::$callsMade=0;
    AiPreparationProvider::$source=$source;AiPreparationProvider::$hint=$hint;AiPreparationProvider::$sourceOverride=null;
    AiPreparationProvider::$extractedName='سردين';
    DialogueFixtureCatalog::$products=[aiPrepProduct()];
    AiPrepCatalog::$options=DialogueFixtureCatalog::$products[0]['options'];
    app()->instance(AiPrepProvider::class,new AiPreparationProvider());
    return $c;
}

$c=aiPrepSetup();
$native=AiPrepDB::table('whatsapp_inbox_messages')->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all();
$r=primaryRun();$stored=customerRecapStored();
aiPrepExpect($r['errors']===0&&$r['dispatched']===1&&AiPreparationProvider::$callsMade===1,
    'source-proved split AI preparation maps one live catalog option');
aiPrepExpect(count(AiPrepPos::$lastPayload['items'])===1
    &&AiPrepPos::$lastPayload['items'][0]['product_id']===11
    &&AiPrepPos::$lastPayload['items'][0]['option_id']==='f:0:extra_clear'
    &&AiPrepPos::$lastPayload['items'][0]['quantity']==='1'
    &&AiPrepPos::$lastPayload['items'][0]['quantity_mode']==='weight',
    'original one-kilo sardine and configured deboned identity reach ERP without substitution');
aiPrepExpect($stored['items'][0]['option_hint']==='مخلي'
    &&$stored['evidence_receipt']['ai_extraction']['items'][0]['quantity_source']==='سردين (كيلو) مخلي صافي',
    'stored source clause and literal AI hint remain unchanged');
aiPrepExpect(AiPrepDB::table('whatsapp_inbox_messages')->orderBy('id')->get()->map(static fn($r)=>(array)$r)->all()===$native,
    'mapping does not rewrite customer identity, ciphertext or native timestamps');
customerRecapSilent('AI preparation dispatch keeps Meta agent as customer responder');
primaryRun();
aiPrepExpect(AiPrepDB::table('test_tickets')->count()===1&&AiPrepDB::table('test_print')->count()===1
    &&AiPreparationProvider::$callsMade===1,'same source receipt never creates duplicate order or AI extraction');

aiPrepSetup('كيلو سردين مخلي','مخلي');AiPreparationProvider::$extractedName='سردين مخلي';$r=primaryRun();
aiPrepExpect($r['errors']===0&&$r['dispatched']===1
    &&AiPrepPos::$lastPayload['items'][0]['option_id']==='f:0:extra_clear',
    'consistent preparation inside full AI name preserves the same source-backed live choice');
aiPrepSetup('كيلو سردين مخلي',null);$r=primaryRun();
aiPrepExpect($r['errors']===0&&$r['dispatched']===1
    &&AiPrepPos::$lastPayload['items'][0]['option_id']==='f:0:extra_clear',
    'omitted model hint cannot discard explicit unique preparation in native item clause');
aiPrepSetup('كيلو سردين كامل','كامل');$r=primaryRun();
aiPrepExpect($r['errors']===0&&$r['dispatched']===1&&AiPrepPos::$lastPayload['items'][0]['option_id']==='',
    'separate whole hint uses genuine live base preparation and configured cleaning counterpart');

aiPrepSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
aiPrepExpect(AiPreparationProvider::$callsMade===1&&AiPrepDB::table('test_tickets')->count()===0,
    'valid preparation can be cached while independent dispatch clock is closed');
PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'10:00','all_day'=>false];
PrimaryFixtureControl::$strictDispatchClock=false;customerRecapClock('2026-10-09 22:07:00');$r=primaryRun();
aiPrepExpect($r['dispatched']===1&&AiPreparationProvider::$callsMade===1
    &&AiPrepPos::$lastPayload['items'][0]['option_id']==='f:0:extra_clear',
    'cache re-proves original preparation and dispatches after analysis closes without API call');

foreach(['conflicting','negated','hint-negated','unknown-hint','missing-option','whole-missing-counterpart','unit-drift','other-item-source','inline-hint-conflict','inline-negated'] as $guard) {
    $source='سردين (كيلو) مخلي صافي';$hint='مخلي';
    if($guard==='conflicting')$source='كيلو سردين مخلي وتنظيف';
    if($guard==='negated')$source='كيلو سردين بدون مخلي';
    if($guard==='hint-negated'){$source='كيلو سردين بدون مخلي';$hint='بدون مخلي';}
    if($guard==='unknown-hint'){$source='كيلو سردين تجهيز خاص';$hint='تجهيز خاص';}
    if($guard==='other-item-source')$source='كيلو رنجة مخلي مع سردين';
    if($guard==='whole-missing-counterpart'){$source='كيلو سردين كامل';$hint='كامل';}
    if($guard==='inline-hint-conflict'){$source='كيلو سردين مخلي وتنظيف';$hint='تنظيف';}
    if($guard==='inline-negated')$source='كيلو سردين مخلي بدون مخلي';
    aiPrepSetup($source,$hint);
    if(in_array($guard,['inline-hint-conflict','inline-negated'],true))AiPreparationProvider::$extractedName='سردين مخلي';
    if(in_array($guard,['missing-option','whole-missing-counterpart'],true)) {
        DialogueFixtureCatalog::$products[0]['options']=[];
        DialogueFixtureCatalog::$products[0]['preparation_options']=[DialogueFixtureCatalog::$products[0]['preparation_options'][0]];
        AiPrepCatalog::$options=[];
    }
    if($guard==='unit-drift')DialogueFixtureCatalog::$products[0]['quantity_mode']='piece';
    if($guard==='other-item-source')AiPreparationProvider::$sourceOverride='كيلو رنجة مخلي';
    primaryRun();customerRecapNoErp('source preparation retains '.$guard.' guard');
}

// The generic option-coverage rules retain an unsupported qualifier instead of
// stripping it from a model hint to manufacture a catalog synonym.
aiPrepSetup('سردين (كيلو) مخلي صافي','مخلي صافي');primaryRun();
customerRecapNoErp('uncatalogued full preparation hint cannot be silently reduced to a live substring');

aiPrepSetup();app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static function():void {
    DialogueFixtureCatalog::$products[0]['options']=[];
    DialogueFixtureCatalog::$products[0]['preparation_options']=[DialogueFixtureCatalog::$products[0]['preparation_options'][0]];
    AiPrepCatalog::$options=[];
};
primaryRun();customerRecapNoErp('live preparation removal between quote and save cannot reach ERP');

aiPrepSetup();PrimaryFixtureControl::$strictDispatchClock=true;primaryRun();
$draft=AiPrepDB::table('whatsapp_order_drafts')->first();$cached=customerRecapStored();
$cached['evidence_receipt']['ai_extraction']['items'][0]['quantity_source']='كيلو رنجة مخلي';
AiPrepDB::table('whatsapp_order_drafts')->where('id',$draft->id)->update(['extraction'=>AiPrepCrypt::encryptString(json_encode($cached))]);
PrimaryFixtureControl::$analysisSchedule=['enabled'=>true,'days'=>[0,1,2,3,4,5,6],'start'=>'09:00','end'=>'10:00','all_day'=>false];
PrimaryFixtureControl::$strictDispatchClock=false;customerRecapClock('2026-10-09 22:07:00');primaryRun();
customerRecapNoErp('modified cached clause cannot grant preparation authority');

echo "WHATSAPP_CUSTOMER_AI_PREPARATION_WORKFLOW_OK checks=$aiPreparationChecks\n";
