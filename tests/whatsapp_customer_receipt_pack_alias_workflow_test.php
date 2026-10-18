<?php
// Owner-reviewed Mansoura pack identities; no generic sandwich alias is authorized.
require __DIR__.'/whatsapp_customer_branch_summary_workflow_test.php';
use Illuminate\Support\Facades\DB as PackAliasDB;
use App\Services\Dashboard\PosServiceTicket as PackAliasPos;
use App\Services\Dashboard\WhatsAppOrderAiProvider as PackAliasAi;

$packAliasChecks=0;
function packAliasExpect($ok,string$label):void{
    $GLOBALS['packAliasChecks']++;if(!$ok)throw new RuntimeException('CUSTOMER_PACK_ALIAS_FAILED:'.$label);
}
function packAliasProduct(string$kind):array{
    return match($kind){
        'box'=>['id'=>2551,'name'=>'علبة بطارخ ورن 400 جرام','quantity_mode'=>'piece','price'=>'300.00','available'=>true,'options'=>[]],
        'quarter'=>['id'=>2566,'name'=>'ربع سلطه رنجه','quantity_mode'=>'piece','price'=>'150.00','available'=>true,'options'=>[]],
        default=>['id'=>2576,'name'=>'رنجه هيرنج مبطرخ','quantity_mode'=>'weight','price'=>'220.00','available'=>true,'options'=>[]],
    };
}
function packAliasRequest(string$kind):array{
    return match($kind){
        'box'=>['name'=>'علبة بطارخ 400 جرام','quantity'=>'1','quantity_mode'=>'piece'],
        'quarter'=>['name'=>'سلطة رنجة','quantity'=>'0.25','quantity_mode'=>'weight'],
        default=>['name'=>'رنجة مبطرخة','quantity'=>'0.5','quantity_mode'=>'weight'],
    };
}
function packAliasQuarterSelect():array{
    $product=packAliasProduct('quarter');$product['quantity_mode']='select';
    $product['preparation_options']=[['id'=>'','label'=>'كامل','price'=>'150.00','feature_id'=>0,'product_clean'=>'']];
    return$product;
}
class PackAliasSelectFixturePos extends PackAliasPos {
    public function quote($channel,array$values,$actor):array{
        abort_unless($channel==='phone'&&isset($values['delivery_quote_hash']),422,'PACK_ALIAS_PHONE_CONTRACT');
        app(\App\Services\Dashboard\TakeawayAccess::class)->branch($values['branch'],$actor);
        $products=array_column(DialogueFixtureCatalog::$products,null,'id');$total=0;
        foreach($values['items']as$item){
            $product=$products[$item['product_id']]??null;
            $unitMatches=$product&&($product['quantity_mode']===$item['quantity_mode']
                ||($product['quantity_mode']==='select'&&$item['quantity_mode']==='piece'));
            abort_unless($product&&$product['available']===true&&$unitMatches
                &&$item['option_id']===''&&is_numeric($item['quantity'])&&(float)$item['quantity']>0,422,'PACK_ALIAS_LIVE_ITEM_CONTRACT');
            $total+=(int)round((float)$item['quantity']*(float)$product['price']*100);
        }
        return['quote_hash'=>self::fingerprint([$values['branch'],$values['items'],$total]),
            'total'=>number_format($total/100,2,'.',''),'total_cents'=>$total];
    }
}
function packAliasSetup(bool$explicitSandwich=true,bool$quarterSelect=false):int{
    primarySetup();PrimaryFixtureControl::$analysisOnly=true;PrimaryFixtureControl::$replyClockClosed=true;dayControl();
    $c=addConversation('US.customer-confirmed');$text=branchSummaryText();
    if($explicitSandwich)$text=str_replace('ساندوتشين فسيخ','ساندوتشين فسيخ دسوق نبروه مظبوط',$text);
    insertMessage($c,'wamid.pack-alias.summary','inbound',$text,2,null,'US.customer-confirmed');
    insertMessage($c,'wamid.pack-alias.point','inbound','',3,['lat'=>31.04,'long'=>31.37],'US.customer-confirmed');
    insertMessage($c,'wamid.pack-alias.approval','inbound','كل شئ تمام',4,null,'US.customer-confirmed');
    $catalog=branchSummaryCatalog();$catalog[0]=packAliasProduct('box');$catalog[2]=packAliasProduct('herring');$catalog[3]=packAliasProduct('quarter');
    if($quarterSelect)$catalog[3]=packAliasQuarterSelect();
    // This fifth ID remains a synthetic literal fixture, not a claimed live SKU.
    // An actual new customer message naming that complete variant is unambiguous.
    $catalog[4]['name']='ساندوتش فسيخ دسوق نبروه مظبوط';
    DialogueFixtureCatalog::$products=$catalog;app()->instance(PackAliasPos::class,$quarterSelect?new PackAliasSelectFixturePos():new BranchSummaryFixturePos());
    PackAliasAi::$data=extractionData();return$c;
}
$selectQuarter=packAliasQuarterSelect();$quarterRequest=packAliasRequest('quarter');
packAliasExpect(aliasMap($quarterRequest,[$selectQuarter])===['product_id'=>2566,'option_id'=>'','quantity'=>'1','quantity_mode'=>'piece'],
    'actual selectable quarter SKU with sole genuine whole preparation maps to one piece');
foreach(['missing-preparation','empty-preparations','changed-label','nonbase-id','wrong-feature','changed-cleaning','duplicate-preparation','sale-option']as$guard){
    $changed=$selectQuarter;
    if($guard==='missing-preparation')unset($changed['preparation_options']);
    if($guard==='empty-preparations')$changed['preparation_options']=[];
    if($guard==='changed-label')$changed['preparation_options'][0]['label']='مخلي';
    if($guard==='nonbase-id')$changed['preparation_options'][0]['id']='f:1:base';
    if($guard==='wrong-feature')$changed['preparation_options'][0]['feature_id']=1;
    if($guard==='changed-cleaning')$changed['preparation_options'][0]['product_clean']='extra_clean';
    if($guard==='duplicate-preparation')$changed['preparation_options'][]=$changed['preparation_options'][0];
    if($guard==='sale-option')$changed['options']=[['id'=>'f:1:base','label'=>'كومبو','price'=>'175.00']];
    packAliasExpect(aliasMap($quarterRequest,[$changed])===null,'selectable quarter pack requires current exact whole/base preparation: '.$guard);
}
foreach([null,'f:94','f:301','f:307']as$branch)
    packAliasExpect(aliasMap($quarterRequest,[$selectQuarter],$branch)===null,'selectable quarter pack cannot cross approved branch: '.($branch??'null'));
packAliasExpect(aliasMap($quarterRequest,[$selectQuarter],'f:363',new ApprovedAliasMetaWorkflow())===null,
    'selectable quarter pack cannot cross native WhatsApp namespace');
foreach(['wrong-id','wrong-name','unavailable','wrong-quantity','wrong-mode']as$guard){
    $changed=$selectQuarter;$request=$quarterRequest;
    if($guard==='wrong-id')$changed['id']++;
    if($guard==='wrong-name')$changed['name'].=' مختلف';
    if($guard==='unavailable')$changed['available']=false;
    if($guard==='wrong-quantity')$request['quantity']='0.5';
    if($guard==='wrong-mode')$request['quantity_mode']='piece';
    packAliasExpect(aliasMap($request,[$changed])===null,'selectable quarter pack retains existing identity and amount guard: '.$guard);
}
$literal=$selectQuarter;$literal['id']=999;$literal['name']=$quarterRequest['name'];$literal['quantity_mode']='weight';
packAliasExpect(aliasMap($quarterRequest,[$selectQuarter,$literal])===null,
    'selectable quarter alias never hides competing literal weight SKU');

foreach(['box','quarter','herring']as$kind){
    $request=packAliasRequest($kind);$product=packAliasProduct($kind);$mapped=aliasMap($request,[$product]);
    $quantity=$kind==='quarter'?'1':$request['quantity'];$mode=$kind==='quarter'?'piece':$request['quantity_mode'];
    packAliasExpect($mapped===['product_id'=>$product['id'],'option_id'=>'','quantity'=>$quantity,'quantity_mode'=>$mode],
        'exact reviewed identity and live unit produce intended sale amount: '.$kind);
    foreach([null,'f:94','f:301','f:307']as$branch)
        packAliasExpect(aliasMap($request,[$product],$branch)===null,'reviewed pack alias cannot cross branch: '.$kind.':'.($branch??'null'));
    packAliasExpect(aliasMap($request,[$product],'f:363',new ApprovedAliasMetaWorkflow())===null,
        'reviewed pack alias cannot cross native storage namespace: '.$kind);
    foreach(['wrong-id','wrong-name','unavailable','wrong-unit','select-unit']as$guard){
        $changed=$product;
        if($guard==='wrong-id')$changed['id']++;
        if($guard==='wrong-name')$changed['name'].=' مختلف';
        if($guard==='unavailable')$changed['available']=false;
        if($guard==='wrong-unit')$changed['quantity_mode']=$product['quantity_mode']==='piece'?'weight':'piece';
        if($guard==='select-unit')$changed['quantity_mode']='select';
        packAliasExpect(aliasMap($request,[$changed])===null,'reviewed identity fails closed on '.$kind.':'.$guard);
    }
    $literal=$product;$literal['id']=999;$literal['name']=$request['name'];$literal['quantity_mode']=$request['quantity_mode'];
    packAliasExpect(aliasMap($request,[$product,$literal])===null,
        'competing literal live product remains ambiguous rather than being hidden by approved alias: '.$kind);
}
foreach(['0.5','1','0','0.125']as$quantity){
    $request=packAliasRequest('quarter');$request['quantity']=$quantity;
    packAliasExpect(aliasMap($request,[packAliasProduct('quarter')])===null,'only exact 0.25 weight maps one quarter pack: '.$quantity);
}
foreach(['piece','unknown']as$mode){
    $request=packAliasRequest('quarter');$request['quantity_mode']=$mode;
    packAliasExpect(aliasMap($request,[packAliasProduct('quarter')])===null,'quarter conversion requires explicit original weight mode: '.$mode);
}
foreach(['سلطة رنجة مخصوص','سلطة فسيخ','سلطة رنجة كبير']as$name){
    $request=packAliasRequest('quarter');$request['name']=$name;
    packAliasExpect(aliasMap($request,[packAliasProduct('quarter')])===null,'quarter conversion cannot drop or change product descriptors: '.$name);
}
foreach(['علبة بطارخ 200 جرام','علبة بطارخ 400 جرام مخلي','علبة بطارخ']as$name){
    $request=packAliasRequest('box');$request['name']=$name;
    packAliasExpect(aliasMap($request,[packAliasProduct('box')])===null,'400g pack alias preserves explicit size and complete name: '.$name);
}
$request=packAliasRequest('box');$request['quantity']='2';
packAliasExpect(aliasMap($request,[packAliasProduct('box')])===null,'400g reviewed alias is limited to explicitly reviewed one-pack quantity');
$request=packAliasRequest('box');$request['quantity_mode']='weight';
packAliasExpect(aliasMap($request,[packAliasProduct('box')])===null,'400g pack cannot be silently changed to a kilogram sale');
$request=packAliasRequest('herring');$request['name']='رنجة مبطرخة ناعمة';
packAliasExpect(aliasMap($request,[packAliasProduct('herring')])===null,'feminine herring synonym cannot drop an extra grade or preparation qualifier');
$request=packAliasRequest('quarter');$sameIdLiteral=packAliasProduct('quarter');$sameIdLiteral['name']=$request['name'];$sameIdLiteral['quantity_mode']='weight';
packAliasExpect(aliasMap($request,[packAliasProduct('quarter'),$sameIdLiteral])===null,
    'same product ID with competing amount or unit interpretations is not deduplicated into arbitrary mapping');

$c=packAliasSetup();$native=PackAliasDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();$r=primaryRun();
packAliasExpect($r['errors']===0&&$r['dispatched']===1&&PackAliasAi::$calls===0,
    'proven pack and herring names plus fully literal sandwich variant dispatch through normal silent workflow');
packAliasExpect(array_column(PackAliasPos::$lastPayload['items'],'product_id')===[2551,602,2576,2566,605]
    &&array_column(PackAliasPos::$lastPayload['items'],'quantity')===['1','1','0.5','1','2']
    &&array_column(PackAliasPos::$lastPayload['items'],'quantity_mode')===['piece','weight','weight','piece','piece'],
    'ERP receives one 400g box and one quarter pack with exact remaining weights and sandwich piece count');
$data=customerRecapStored();
packAliasExpect($data['items'][3]['quantity']==='0.25'&&$data['items'][3]['quantity_mode']==='weight'
    &&$data['items'][3]['name']==='سلطة رنجة'
    &&PackAliasDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
    'pack conversion changes sale mapping only, never original extracted quantity or raw native receipt');
customerRecapSilent('reviewed pack mapping does not enable internal customer replies');
primaryRun();packAliasExpect(PackAliasDB::table('test_tickets')->count()===1&&PackAliasDB::table('test_print')->count()===1,
    'reviewed pack mapping keeps atomic ticket and print idempotency');

packAliasSetup(quarterSelect:true);$native=PackAliasDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();$r=primaryRun();
packAliasExpect($r['errors']===0&&$r['dispatched']===1&&PackAliasAi::$calls===0,
    'actual selectable quarter catalog dispatches through normal receipt, live quote and silent workflow');
$quarterSale=PackAliasPos::$lastPayload['items'][3];
packAliasExpect($quarterSale['product_id']===2566&&$quarterSale['quantity']==='1'
    &&$quarterSale['quantity_mode']==='piece'&&$quarterSale['option_id']==='',
    'actual selectable quarter is quoted and dispatched as one existing quarter pack');
$data=customerRecapStored();
packAliasExpect($data['items'][3]['quantity']==='0.25'&&$data['items'][3]['quantity_mode']==='weight'
    &&PackAliasDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$native,
    'selectable quarter conversion preserves native receipt and original extracted weight');
customerRecapSilent('selectable quarter dispatch does not enable internal replies');
primaryRun();packAliasExpect(PackAliasDB::table('test_tickets')->count()===1&&PackAliasDB::table('test_print')->count()===1,
    'selectable quarter retains ticket and print idempotency');

packAliasSetup(quarterSelect:true);DialogueFixtureCatalog::$products[3]['preparation_options']=[];primaryRun();
customerRecapNoErp('selectable quarter without genuine base preparation cannot create ERP work');
packAliasExpect(PackAliasDB::table('whatsapp_order_drafts')->value('reason')==='CATALOG_UNRESOLVED',
    'missing actual selectable quarter preparation stays a catalog review');

packAliasSetup(false);primaryRun();customerRecapNoErp('generic feseekh sandwich is still unresolved against a specific Nabroh proper variant');
packAliasExpect(PackAliasDB::table('whatsapp_order_drafts')->value('reason')==='CATALOG_UNRESOLVED',
    'one-order owner sandwich choice is not installed as a global generic sandwich alias');

echo 'whatsapp_customer_receipt_pack_alias_workflow: '.$packAliasChecks." checks passed\n";
