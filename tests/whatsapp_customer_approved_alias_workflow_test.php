<?php
// Real customer-copy parsing and workflow, with bounded live-menu and ERP fixtures.
require __DIR__.'/whatsapp_customer_preparation_workflow_test.php';
use Illuminate\Support\Facades\DB as AliasDB;
use App\Services\Dashboard\PosServiceTicket as AliasPos;
use App\Services\Dashboard\WhatsAppOrderAiProvider as AliasAi;
$aliasChecks=0;
function aliasExpect($ok,string$label):void{$GLOBALS['aliasChecks']++;if(!$ok)throw new RuntimeException('CUSTOMER_APPROVED_ALIAS_FAILED:'.$label);}
class ApprovedAliasPos extends AliasPos {
    public function quote($channel,array$values,$actor):array {
        abort_unless($channel==='phone'&&isset($values['delivery_quote_hash']),422,'ALIAS_PHONE_CONTRACT');
        app(\App\Services\Dashboard\TakeawayAccess::class)->branch($values['branch'],$actor);
        $products=array_column(DialogueFixtureCatalog::$products,null,'id');
        foreach($values['items']as$item){
            $product=$products[$item['product_id']]??null;
            abort_unless($product&&$product['available']===true&&$item['quantity_mode']==='weight'
                &&(float)$item['quantity']>0&&in_array($item['option_id'],array_merge([''],array_column($product['options'],'id')),true),422,'ALIAS_CATALOG_CONTRACT');
        }
        $total=10000+self::$drift;
        return['quote_hash'=>self::fingerprint([$values['branch'],$values['items'],$total]),'total'=>'100.00','total_cents'=>$total];
    }
}
class ApprovedAliasMetaWorkflow extends \App\Services\Dashboard\WhatsAppOrderWorkflow {
    protected function table(string$table):string{return str_replace('whatsapp_','meta_',$table);}
}
function aliasProduct(string$kind):array{
    if($kind==='herring')return['id'=>2576,'name'=>'رنجه هيرنج مبطرخ','available'=>true,'quantity_mode'=>'weight','price'=>'100.00','options'=>[]];
    $product=prepProduct('سردين');$product['id']=2578;return$product;
}
function aliasSetup(string$kind,?string$name=null):int{
    $name??=$kind==='herring'?'رنجة مبطرخ':'سردين بلدي كبير مخلي';
    $c=prepSetup($name,aliasProduct($kind),'0.5');app()->instance(AliasPos::class,new ApprovedAliasPos());return$c;
}
function aliasMap(array$item,array$catalog,?string$branch='f:363',$object=null):?array{
    global$workflow;$method=new ReflectionMethod($workflow,'customerReceiptItem');$method->setAccessible(true);
    return$method->invoke($object??$workflow,$item,$catalog,$branch);
}

foreach(['herring'=>'','sardine'=>'f:0:extra_clear']as$kind=>$option){
    $c=aliasSetup($kind);$cipher=AliasDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all();
    $r=primaryRun();
    aliasExpect($r['errors']===0&&$r['dispatched']===1&&AliasAi::$calls===0,'approved exact alias reaches normal silent workflow '.$kind);
    aliasExpect(AliasPos::$lastPayload['items'][0]===['product_id'=>$kind==='herring'?2576:2578,'quantity'=>'0.5','quantity_mode'=>'weight',
        'option_id'=>$option,'feature_id'=>0,'product_clean'=>''],'approved live sale identity and original fractional weight survive '.$kind);
    primaryRun();aliasExpect(AliasDB::table('test_tickets')->count()===1&&AliasDB::table('test_print')->count()===1,'approved alias replays one ticket and print '.$kind);
    aliasExpect(AliasDB::table('whatsapp_inbox_messages')->orderBy('id')->pluck('content')->all()===$cipher,'approved alias never rewrites customer wording '.$kind);
    customerRecapSilent('approved alias never replies '.$kind);
}

foreach(['herring','sardine']as$kind){
    $item=['name'=>$kind==='herring'?'رنجة مبطرخ':'سردين بلدي كبير مخلي','quantity'=>'1','quantity_mode'=>'weight'];
    $product=aliasProduct($kind);
    foreach([null,'f:94','f:301','f:307']as$branch)
        aliasExpect(aliasMap($item,[$product],$branch)===null,'approved alias cannot cross branch or absent scope '.$kind.':'.($branch??'null'));
    aliasExpect(aliasMap($item,[$product],'f:363',new ApprovedAliasMetaWorkflow())===null,'approved alias cannot cross to Meta storage '.$kind);
    foreach(['wrong-id','renamed','unavailable','piece','unapproved-suffix','zero-quantity']as$guard){
        $changed=$product;$request=$item;
        if($guard==='wrong-id')$changed['id']++;
        if($guard==='renamed')$changed['name'].=' كبير';
        if($guard==='unavailable')$changed['available']=false;
        if($guard==='piece')$request['quantity_mode']='piece';
        if($guard==='unapproved-suffix')$request['name'].=' ممتاز';
        if($guard==='zero-quantity')$request['quantity']='0';
        aliasExpect(aliasMap($request,[$changed])===null,'approved alias still enforces '.$kind.':'.$guard);
    }
    $literal=$product;$literal['id']=990;$literal['name']=$item['name'];$literal['options']=[];unset($literal['preparation_options']);
    aliasExpect(aliasMap($item,[$product,$literal])===null,'actual competing literal product remains ambiguous '.$kind);
}
foreach(['missing','wrong-id','wrong-label','missing-price']as$guard){
    $product=aliasProduct('sardine');
    if($guard==='missing')$product['options']=[];
    if($guard==='wrong-id')$product['options'][0]['id']='f:55:extra_clear';
    if($guard==='wrong-label')$product['options'][0]['label']='تنظيف';
    if($guard==='missing-price')unset($product['options'][0]['price']);
    aliasExpect(aliasMap(['name'=>'سردين بلدي كبير مخلي','quantity'=>'1','quantity_mode'=>'weight'],[$product])===null,
        'approved fillet requires actual full-unit configured fillet option '.$guard);
}

// Only the customer-copy path may disregard the branch's physical opening state.
aliasSetup('sardine');AliasDB::table('test_branches')->where('id',363)->update(['status'=>'closed']);
$r=primaryRun();aliasExpect($r['errors']===0&&$r['dispatched']===1,'owner-authorized closed-branch receipt dispatches through normal ERP');
aliasExpect(AliasDB::table('test_branches')->where('id',363)->value('status')==='closed','dispatch never changes physical branch status');
customerRecapSilent('closed-branch receipt remains analysis-only');

aliasSetup('herring');AliasDB::table('test_branches')->where('id',363)->update(['status'=>'closed']);PrimaryFixtureControl::$strictDispatchClock=true;
$r=primaryRun();aliasExpect($r['dispatched']===0&&$r['deferred']===1&&AliasDB::table('whatsapp_order_drafts')->value('reason')==='DISPATCH_OUTSIDE_HOURS',
    'closed-branch permission does not bypass global dispatch schedule');
customerRecapNoErp('outside-hours closed-branch receipt remains queued');
$calls=AliasAi::$calls;customerRecapClock('2026-10-10 06:00:00');$r=primaryRun();
aliasExpect($r['dispatched']===1&&AliasAi::$calls===$calls,'09:00 resumes exact cached closed-branch alias without new AI extraction');
primaryRun();aliasExpect(AliasDB::table('test_tickets')->count()===1,'scheduled closed-branch replay is idempotent');

aliasSetup('herring');DialogueFixtureCatalog::$products[0]['name']='رنجة غير معتمدة';primaryRun();
AliasDB::table('whatsapp_order_scans')->update(['attempts'=>3]);DialogueFixtureCatalog::$products[0]=aliasProduct('herring');
$calls=AliasAi::$calls;customerRecapClock('2026-10-09 22:06:01');$r=primaryRun();
aliasExpect($r['dispatched']===1&&AliasAi::$calls===$calls&&AliasDB::table('whatsapp_order_scans')->value('attempts')===3,
    'current cached receipt remaps approved live alias with exhausted extraction budget without resetting scan');

aliasSetup('herring');$guard=new ReflectionMethod($workflow,'branchOpen');$guard->setAccessible(true);$reason=null;
$args=[['id'=>999],&$reason,false];$open=$guard->invokeArgs($workflow,$args);
aliasExpect($open===false&&$reason==='BRANCH_POLICY_UNAVAILABLE','unknown physical branch policy remains a separate blocking reason');

$c=primaryCompleteDraft();AliasDB::table('test_branches')->where('id',363)->update(['status'=>'closed']);$r=primaryRun();
aliasExpect($r['dispatched']===0&&AliasDB::table('whatsapp_order_drafts')->orderByDesc('id')->value('reason')==='BRANCH_CLOSED',
    'ordinary nonreceipt automatic order retains physical branch guard');primaryNoErp('ordinary nonreceipt cannot inherit receipt-only closed permission');

aliasSetup('herring');app()->instance(\App\Services\Dashboard\PhoneDelivery::class,new CustomerRecapRaceDelivery());
CustomerRecapRaceDelivery::$duringQuote=static function():void{DialogueFixtureCatalog::$products[0]['name']='رنجة مختلفة';};
primaryRun();customerRecapNoErp('approved alias cannot survive live menu identity drift before dispatch');
echo 'whatsapp_customer_approved_alias_workflow: '.$aliasChecks." checks passed\n";
