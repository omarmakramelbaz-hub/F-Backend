<?php
// Real customer receipt parsing, native ciphertext, SQLite workflow and ERP traps.
require __DIR__.'/whatsapp_customer_confirmation_workflow_test.php';
use Illuminate\Support\Facades\DB as PrepDB;
use App\Services\Dashboard\WhatsAppOrderAiProvider as PrepAi;
use App\Services\Dashboard\PosServiceTicket as PrepPos;
use App\Services\Dashboard\TakeawayCatalog as PrepCatalog;
$preparationChecks=0;
function prepExpect($ok,string$label):void{$GLOBALS['preparationChecks']++;if(!$ok)throw new RuntimeException('CUSTOMER_PREPARATION_FAILED:'.$label);}
function prepProduct(string$name='فسيخ'):array{
    return['id'=>11,'name'=>$name,'available'=>true,'quantity_mode'=>'weight','price'=>'100.00',
        'options'=>[['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120.00'],['id'=>'f:0:extra_clean','label'=>'تنظيف','price'=>'110.00']],
        'preparation_options'=>[
            ['id'=>'','label'=>'كامل','price'=>'100.00','feature_id'=>0,'product_clean'=>''],
            ['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120.00','feature_id'=>0,'product_clean'=>'extra_clear'],
            ['id'=>'f:0:extra_clean','label'=>'تنظيف','price'=>'110.00','feature_id'=>0,'product_clean'=>'extra_clean']]];
}
function prepSetup(string$recapped,array$product,?string$quantity=null):int{
    $text=str_replace('1 كيلو فسيخ',($quantity??'1').' كيلو '.$recapped,customerRecapText());$c=customerRecapSetup($text);
    DialogueFixtureCatalog::$products=[$product];PrepCatalog::$options=$product['options'];return$c;
}
function prepCall(string$method,array$args=[]){global$workflow;$m=new ReflectionMethod($workflow,$method);$m->setAccessible(true);return$m->invokeArgs($workflow,$args);}

foreach(['فسيخ نبروه 3 سمكات كامل','كامل فسيخ نبروه 3 سمكات']as$name){
    $product=prepProduct('فسيخ نبروه 3 سمكات');prepSetup($name,$product,'0.5');$r=primaryRun();
    prepExpect($r['errors']===0&&$r['dispatched']===1&&PrepAi::$calls===0,'complete literal product plus live whole label dispatches silently');
    prepExpect(PrepPos::$lastPayload['items'][0]['option_id']===''&&PrepPos::$lastPayload['items'][0]['quantity']==='0.5'
        &&PrepPos::$lastPayload['items'][0]['product_id']===11,'whole uses original base identity without dividing explicit weight');
    customerRecapSilent('whole preparation sends no AI reply');
    primaryRun();prepExpect(PrepDB::table('test_tickets')->count()===1&&PrepDB::table('test_print')->count()===1,'whole receipt replay remains one ticket and print');
}
foreach(['مخلي'=>'f:0:extra_clear','تنظيف'=>'f:0:extra_clean']as$label=>$option){
    prepSetup('فسيخ '.$label,prepProduct());$r=primaryRun();
    prepExpect($r['dispatched']===1&&$r['errors']===0&&PrepPos::$lastPayload['items'][0]['option_id']===$option,
        'coherent legacy and preparation overlap has one exact sale identity '.$label);
    customerRecapSilent('preparation overlap stays silent');
}
$combo=prepProduct();$combo['options'][]=['id'=>'f:55:base','label'=>'كومبو','price'=>'150.00'];
$combo['preparation_options']=[['id'=>'','label'=>'عادي','price'=>'100.00','feature_id'=>0,'product_clean'=>''],
    ['id'=>'f:55:base','label'=>'كومبو','price'=>'150.00','feature_id'=>55,'product_clean'=>'']];
prepSetup('فسيخ عادي',$combo);$r=primaryRun();prepExpect($r['dispatched']===1&&PrepPos::$lastPayload['items'][0]['option_id']==='',
    'normal base requires and preserves same-product configured combo alternative');
prepSetup('فسيخ كومبو',$combo);$r=primaryRun();prepExpect($r['dispatched']===1&&PrepPos::$lastPayload['items'][0]['option_id']==='f:55:base',
    'combo remains the exact configured feature identity');

foreach(['plain-whole','normal-without-combo','region-stripped','size-stripped','sardine-qualifiers','missing-preparation']as$guard){
    $product=prepProduct();$name='فسيخ كامل';
    if($guard==='plain-whole'){$product['options']=[];$product['preparation_options']=[$product['preparation_options'][0]];}
    if($guard==='normal-without-combo'){$name='فسيخ عادي';$product['preparation_options'][0]['label']='عادي';}
    if($guard==='region-stripped'){$name='فسيخ نبروه كامل';}
    if($guard==='size-stripped'){$name='فسيخ 3 سمكات كامل';}
    if($guard==='sardine-qualifiers'){$name='سردين بلدي كبير مخلي';$product=prepProduct('سردين');}
    if($guard==='missing-preparation')unset($product['preparation_options']);
    prepSetup($name,$product);primaryRun();customerRecapNoErp('explicit preparation cannot infer '.$guard);
    prepExpect(PrepDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='CATALOG_UNRESOLVED',
        'unproved preparation or product qualifier remains catalog review '.$guard);
    if($guard==='plain-whole'){
        prepSetup('فسيخ',$product);$r=primaryRun();prepExpect($r['dispatched']===1,'legacy bare base remains available for plain SKU');
    }
}
foreach(['duplicate-id','duplicate-label','base-price','overlap-price','overlap-label','missing-price','feature-type','clean-enum','id-coherence','portion-feature','non-list']as$guard){
    $product=prepProduct();
    if($guard==='duplicate-id'){$copy=$product['preparation_options'][0];$copy['label']='عادي';$product['preparation_options'][]=$copy;}
    if($guard==='duplicate-label')$product['preparation_options'][1]['label']='كامل';
    if($guard==='base-price')$product['preparation_options'][0]['price']='101.00';
    if($guard==='overlap-price')$product['preparation_options'][1]['price']='121.00';
    if($guard==='overlap-label')$product['preparation_options'][1]['label']='تنظيف كامل';
    if($guard==='missing-price')unset($product['preparation_options'][0]['price']);
    if($guard==='feature-type')$product['preparation_options'][0]['feature_id']='0';
    if($guard==='clean-enum')$product['preparation_options'][0]['product_clean']='unrecognized';
    if($guard==='id-coherence')$product['preparation_options'][0]['id']='f:99:base';
    if($guard==='portion-feature'){
        $product['options'][]=['id'=>'f:56:base','label'=>'نصف','price'=>'50.00'];
        $product['preparation_options'][]=['id'=>'f:56:base','label'=>'نصف','price'=>'50.00','feature_id'=>56,'product_clean'=>''];
    }
    if($guard==='non-list')$product['preparation_options']=['untrusted'=>$product['preparation_options'][0]];
    prepSetup('فسيخ كامل',$product);$catalog=prepCall('dialogueCatalog',['f:363',(object)['id'=>7]]);
    prepExpect($catalog===null,'invalid live preparation metadata fails entire catalog guard '.$guard);
    primaryRun();customerRecapNoErp('malformed live preparation cannot dispatch '.$guard);
}
$product=prepProduct();$other=['id'=>12,'name'=>'فسيخ كامل','available'=>true,'quantity_mode'=>'weight','options'=>[],'price'=>'100.00'];
prepSetup('فسيخ كامل',$product);DialogueFixtureCatalog::$products[]=$other;primaryRun();customerRecapNoErp('two distinct products cannot be collapsed by visible preparation wording');
prepExpect(PrepDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='CATALOG_UNRESOLVED','two whole-product meanings remain ambiguous');

$product=prepProduct();prepSetup('فسيخ كامل',$product);$before=prepCall('dialogueCatalog',['f:363',(object)['id'=>7]]);
prepExpect($before[0]['preparation_options'][0]===['id'=>'','label'=>'كامل','price'=>'100.00','feature_id'=>0,'product_clean'=>''],
    'validated preparation sale metadata is retained in catalog fingerprint input');
DialogueFixtureCatalog::$products[0]['preparation_options'][0]['label']='عادي';$after=prepCall('dialogueCatalog',['f:363',(object)['id'=>7]]);
prepExpect(PrepPos::fingerprint($before)!==PrepPos::fingerprint($after),'preparation-only changes invalidate catalog fingerprints');
prepSetup('فسيخ كامل',$product);app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static function():void{unset(DialogueFixtureCatalog::$products[0]['preparation_options']);};
primaryRun();customerRecapNoErp('live preparation removed during quote cannot reach ERP writes');
echo 'whatsapp_customer_preparation_workflow: '.$preparationChecks." checks passed\n";
