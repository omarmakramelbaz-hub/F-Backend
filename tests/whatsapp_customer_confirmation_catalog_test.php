<?php
// Real CLI, copied pinned pure workflow methods, explicit host/database/catalog seams.
$root=dirname(__DIR__);$source=file_get_contents($root.'/deployment/whatsapp_customer_confirmation_catalog.php');
$workflowSource=file_get_contents($root.'/app/Services/Dashboard/WhatsAppOrderWorkflow.php');
$fixture=sys_get_temp_dir().'/wa-catalog-'.bin2hex(random_bytes(8));mkdir($fixture,0700);$checks=0;
register_shutdown_function(static function()use($fixture){if(!is_dir($fixture))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($fixture);});
function catalogExpect($ok,string$label):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException('CATALOG_DIAGNOSTIC_FAILED:'.$label);}
preg_match('/\$pins=\[(.*?)\n    \];/s',$source,$map);preg_match_all("/'([^']+)'=>'([^']+)'/",$map[1]??'',$pins,PREG_SET_ORDER);catalogExpect(count($pins)===12,'exact runtime source guard roster');
foreach($pins as[$all,$path,$pin]){
    $original=$root.'/'.$path;
    if(!is_file($original))$original=$root.'/tests/fixtures/whatsapp_confirmation_runtime/'.basename($path);
    $bytes=file_get_contents($original);$actual=sha1('blob '.strlen($bytes)."\0".$bytes);
    if($path==='app/Services/Dashboard/TakeawayCatalog.php'){
        catalogExpect($pin==='e96fce33037a06330f95d6a91553950cdf32d763','production preparation catalog remains pinned');
        $source=str_replace("'".$path."'=>'".$pin."'","'".$path."'=>'".$actual."'",$source,$count);catalogExpect($count===1,'explicit catalog-source host fixture seam');
    }else catalogExpect($actual===$pin,'actual reviewed source '.$path);
    $destination=$fixture.'/'.$path;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);file_put_contents($destination,$bytes);
}
$source=str_replace("posix_getpwnam('fasakha')","['uid'=>posix_geteuid()+(getenv('WA_CATALOG_CASE')==='owner'?1:0)]",$source,$count);catalogExpect($count===1,'owner seam');
$source=str_replace("chdir('/home/fasakha/public_html')",'chdir('.var_export($fixture,true).')',$source,$count);catalogExpect($count===1,'root seam');
catalogExpect(!preg_match('/(?:->|::)(?:analyze|process|extract|dispatch|send|quote|save|insert|update|delete|prepare|project|transaction|beginTransaction|lockForUpdate)\s*\(/',$source),'no action method or lock');
catalogExpect(substr_count($source,'SET SESSION TRANSACTION READ ONLY')===1&&!str_contains($source,'READ WRITE'),'session stays read only');
$methods='';foreach(['customerReceiptItem'=>'customerReceiptName','customerReceiptName'=>'customerReceiptReviewFingerprint','dialogueCatalog'=>'catalogClarification',
    'informationPrice'=>'dialogueContext','catalogOption'=>'completeCart','quantityMillis'=>'branchOpen','name'=>'reviewHash',
    'menuTokens'=>'relatedMenuTokens','relatedMenuTokens'=>'freshFollowupIntent']as$method=>$next){
    $start=strpos($workflowSource,'    private function '.$method.'(');$end=strpos($workflowSource,'    private function '.$next.'(',$start);
    catalogExpect($start!==false&&$end!==false,'exact pinned pure method extraction '.$method);$methods.=substr($workflowSource,$start,$end-$start)."\n";
}
foreach(['vendor','bootstrap']as$directory)mkdir($fixture.'/'.$directory,0700,true);
file_put_contents($fixture.'/catalog.php',$source);
file_put_contents($fixture.'/vendor/autoload.php',"<?php require dirname(__DIR__).'/app/Support/WhatsAppOrderExtraction.php'; require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';");
$bootstrap=<<<'PHP'
<?php
namespace Illuminate\Support\Facades {
    class DB{public static function connection(){return new \CatalogConnection;}public static function table($name){return new \CatalogQuery($name);}}
    class Crypt{public static function decryptString($value){return$value;}}
}
namespace App\Support {
    class WhatsAppCustomerOrderConfirmation{public static function inspect($transcript){return['ok'=>true,'data'=>\catalogData()];}}
}
namespace App\Services\Dashboard {
    use App\Support\WhatsAppOrderExtraction;
    class WhatsAppInboxAccess{const WABA_ID='TEST_WABA';const PHONE_ID='TEST_PHONE';}
    class MetaAiControl{public function state(){return['available'=>true,'policy'=>['enabled'=>true,'mode'=>'ANALYZE_ONLY'],'revision'=>13];}}
    class TakeawayCatalog{
        public function listing($values,$actor){
            if($values['branch']!==(getenv('WA_CATALOG_CASE')==='alias-other-branch'?'f:307':'f:363')||$values['per_page']!==100||$values['page']>3)throw new \RuntimeException('UNBOUNDED_CATALOG');
            $GLOBALS['catalogReads']=($GLOBALS['catalogReads']??0)+1;
            $items=\catalogProducts();if(getenv('WA_CATALOG_CASE')==='catalog-drift'&&$GLOBALS['catalogReads']>1)$items[0]['name']='Changed product';
            if(getenv('WA_CATALOG_CASE')==='pages'){$items[0]['id']+=$values['page'];$items[0]['name']='صنف '.$values['page'];}
            return['items'=>$items,'pagination'=>['last_page'=>getenv('WA_CATALOG_CASE')==='pages'?4:1]];
        }
    }
    class WhatsAppOrderWorkflow{
        private int$snapshots=0;
        private function controlToken($conversation){return['revision'=>13,'actor'=>'PRIVATE_ACTOR'];}
        private function automaticActor($conversation){return(object)['id'=>777,'name'=>'PRIVATE_ACTOR'];}
        private function activationInstant($conversation){return'2026-10-17 12:00:00';}
        private function customerReceiptPoint($data,$snapshot,$activation,&$reason){
            if(getenv('WA_CATALOG_CASE')==='location-missing'){$reason='LOCATION_UNCONFIRMED';return null;}
            return['lat'=>31.123456,'long'=>30.654321];
        }
        private function customerReceiptBranch($point,$actor,$lock=false){
            if($lock)throw new \RuntimeException('UNEXPECTED_LOCATION_LOCK');
            $GLOBALS['routeReads']=($GLOBALS['routeReads']??0)+1;
            return['value'=>getenv('WA_CATALOG_CASE')==='alias-other-branch'?'f:307':'f:363','name'=>'فرع المنصورة',
                'routing_fingerprint'=>str_repeat(getenv('WA_CATALOG_CASE')==='route-drift'&&$GLOBALS['routeReads']>1?'b':'a',64)];
        }
        private function table(string$name):string{return getenv('WA_CATALOG_CASE')==='alias-meta'?str_replace('whatsapp_','meta_',$name):$name;}
        private function activationBoundary($name,$conversation){return 6559;}
        private function ceiling($conversation){return 10046;}
        private function dispatchedCeiling($conversation){return 0;}
        private function customerReceiptFloor($conversation,$floor,$ceiling){return$floor;}
        private function snapshot($conversation,$floor,$ceiling){$this->snapshots++;return['complete'=>true,
            'hash'=>str_repeat(getenv('WA_CATALOG_CASE')==='snapshot-drift'&&$this->snapshots>1?'b':'a',64),'transcript'=>[['text'=>'PRIVATE_CUSTOMER_TEXT']]];}
__PURE_METHODS__
    }
}
namespace {
    function app($class=null){return$class===null?$GLOBALS['catalogApp']:$GLOBALS['catalogApp']->make($class);}
    function catalogData(){
        $case=getenv('WA_CATALOG_CASE');$name=match($case){'no-match'=>'سردين', 'inline'=>'فسيخ مخلي','whole'=>'فسيخ كامل',default=>'فسيخ'};
        $items=[['name'=>$name,'quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null]];
        if($case==='duplicate-effective')$items[]=$items[0];
        if($case==='compact-many')$items=[['name'=>'رنجة هيرنج','quantity'=>'2','quantity_mode'=>'weight','option_hint'=>null],
            ['name'=>'فسيخ كامل','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
            ['name'=>'سردين بلدي صغير مخلي','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null]];
        if(str_starts_with($case,'alias-'))$items=[['name'=>'رنجة مبطرخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
            ['name'=>'سردين بلدي كبير مخلي','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null]];
        if(str_starts_with($case,'pack-quarter'))$items=[['name'=>'سلطة رنجة','quantity'=>'0.25','quantity_mode'=>'weight','option_hint'=>null]];
        return['type'=>'CUSTOMER_CONFIRMED_RECAP','items'=>$items,'customer'=>['name'=>'PRIVATE_CUSTOMER_NAME','phone'=>'201011111111',
            'address'=>'PRIVATE_ADDRESS','area'=>'PRIVATE_AREA'],'textual_location'=>['point'=>['lat'=>$case==='float-roundtrip'?31.0:31.123456,'long'=>30.654321]]];
    }
    function catalogProducts(){
        $case=getenv('WA_CATALOG_CASE');$options=[];$preparation=[];
        if(in_array($case,['inline','whole','compact-many','option-label','option-id','option-empty','prep-invalid','prep-drift'],true))$options=[['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120']];
        if(in_array($case,['inline','whole','compact-many','prep-invalid','prep-drift'],true))$preparation=[
            ['id'=>'','label'=>'كامل','price'=>'100','feature_id'=>0,'product_clean'=>''],
            ['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120','feature_id'=>0,'product_clean'=>'extra_clear']];
        if($case==='prep-invalid')unset($preparation[0]['feature_id']);
        if($case==='prep-drift'&&($GLOBALS['catalogReads']??0)>1)$preparation[0]['label']='Changed preparation';
        if($case==='option-label')$options[]=['id'=>'f:0:extra_clean','label'=>'مخلي','price'=>'20'];
        if($case==='option-id')$options[]=['id'=>'f:0:extra_clear','label'=>'تنظيف','price'=>'20'];
        if($case==='option-empty')$options[0]['label']='';
        $product=['id'=>501,'name'=>'فسيخ','available'=>$case!=='unavailable','quantity_mode'=>$case==='mode'?'piece':'weight',
            'price'=>'100','options'=>$options,'preparation_options'=>$preparation,'description'=>'صنف من المنيو'];
        $description=['description-arabic'=>'صنف عربي','description-english'=>'Fresh menu item','description-digit'=>'item 123',
            'description-x'=>'x','description-angle'=>'<tag>','description-control'=>"hello\nworld"][$case]??null;
        if($description!==null)$product['description']=$description;
        $items=[$product];
        if(str_starts_with($case,'alias-')){
            $herring=$product;$herring['id']=2576;$herring['name']='رنجه هيرنج مبطرخ';
            $sardine=$product;$sardine['id']=2578;$sardine['name']='سردين';$sardine['options']=[['id'=>'f:0:extra_clear','label'=>'مخلي','price'=>'120']];
            if($case==='alias-wrong-id'){$herring['id']=2577;$sardine['id']=2579;}
            if($case==='alias-missing-clean')$sardine['options']=[];
            if($case==='alias-wrong-name')$sardine['name']='سردين معلب';
            if($case==='alias-unavailable'){$herring['available']=false;$sardine['available']=false;}
            if($case==='alias-mode'){$herring['quantity_mode']='piece';$sardine['quantity_mode']='piece';}
            $items=[$herring,$sardine];
        }
        if($case==='compact-many'){
            $herring=$product;$herring['id']=502;$herring['name']='رنجة هيرنج';$items[]=$herring;
            $sardine=$product;$sardine['id']=2578;$sardine['name']='سردين';$items[]=$sardine;
            for($i=0;$i<15;$i++){$meal=$product;$meal['id']=3000+$i;$meal['name']='وجبة سردين عرض '.$i;$items[]=$meal;}
        }
        if(str_starts_with($case,'pack-quarter'))$items=[['id'=>$case==='pack-quarter-unmapped'?2567:2566,
            'name'=>'ربع سلطه رنجه','available'=>true,'quantity_mode'=>str_starts_with($case,'pack-quarter-select')?'select':'piece',
            'price'=>'150.00','options'=>[],
            'preparation_options'=>$case==='pack-quarter-select'?
                [['id'=>'','label'=>'كامل','price'=>'150.00','feature_id'=>0,'product_clean'=>'']]:[],
            'description'=>'صنف من المنيو']];
        if($case==='duplicate-name'){$copy=$product;$copy['id']=502;$items[]=$copy;}
        if($case==='duplicate-id'){$copy=$product;$copy['name']='رنجة';$items[]=$copy;}
        if($case==='arabic-names'){$copy=$product;$copy['id']=502;$copy['name']='فَسيخ';$items[]=$copy;}
        if($case==='malformed')$items[0]['options']='PRIVATE_MALFORMED';
        return$items;
    }
    class CatalogConnection{public function getDriverName(){return getenv('WA_CATALOG_CASE')==='driver'?'sqlite':'mysql';}
        public function transactionLevel(){return getenv('WA_CATALOG_CASE')==='transaction'?1:0;}public function getPdo(){return new CatalogPdo;}}
    class CatalogPdo{public function exec($sql){if($sql!=='SET SESSION TRANSACTION READ ONLY')throw new \RuntimeException('UNEXPECTED_SQL');file_put_contents(getcwd().'/readonly','1');return 0;}}
    class CatalogQuery{
        private string$table;private array$conditions=[];
        public function __construct($table){$this->table=$table;}public function where($key,$value){$this->conditions[$key]=$value;return$this;}
        public function select(...$columns){return$this;}public function selectRaw($sql){if(!str_contains($sql,'LENGTH(extraction)<=262144'))throw new \RuntimeException('UNBOUNDED_EXTRACTION');return$this;}
        public function exists(){return$this->table==='whatsapp_inbox_conversations'&&$this->conditions===['id'=>11,'waba_id'=>'TEST_WABA','phone_number_id'=>'TEST_PHONE'];}
        public function first(){
            if($this->table!=='whatsapp_order_drafts'||$this->conditions!==['id'=>2171,'conversation_id'=>11])throw new \RuntimeException('UNSCOPED_DRAFT');
            $GLOBALS['draftReads']=($GLOBALS['draftReads']??0)+1;
            return(object)['id'=>2171,'conversation_id'=>11,'status'=>'REVIEW','reason'=>'CATALOG_UNRESOLVED','revision'=>1,'evidence_floor'=>6559,'evidence_ceiling'=>10046,
                'evidence_hash'=>str_repeat('a',64),'ticket_id'=>getenv('WA_CATALOG_CASE')==='ticket'?999:null,'updated_at'=>getenv('WA_CATALOG_CASE')==='draft-drift'&&$GLOBALS['draftReads']>1?'2026-10-17 23:00:01':'2026-10-17 23:00:00',
                'extraction'=>json_encode(catalogData(),JSON_THROW_ON_ERROR)];
        }
    }
    class CatalogKernel{public function bootstrap(){}}
    class CatalogApp{private array$objects=[];public function make($class){return$class==='Illuminate\\Contracts\\Console\\Kernel'?new CatalogKernel:($this->objects[$class]??=new$class);}}
    $GLOBALS['catalogApp']=new CatalogApp;return$GLOBALS['catalogApp'];
}
PHP;
file_put_contents($fixture.'/bootstrap/app.php',str_replace('__PURE_METHODS__',$methods,$bootstrap));
function catalogRun(string$case,array$args=[]):array{
    $fixture=$GLOBALS['fixture'];@unlink($fixture.'/readonly');$guard=$fixture.'/app/Services/Dashboard/WhatsAppInboxMessage.php';$saved=file_get_contents($guard);
    if($case==='source')file_put_contents($guard,$saved.' changed');
    if($args===[])$args=['--conversation','11','--expected-floor','6559','--expected-ceiling','10046','--expected-revision','13','--draft','2171'];
    $php=getenv('WA_CONFIRMATION_TEST_PHP')?:PHP_BINARY;$process=proc_open([$php,$fixture.'/catalog.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['WA_CATALOG_CASE'=>$case]));
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);file_put_contents($guard,$saved);
    catalogExpect($errors===''&&!str_contains($output,'PRIVATE_')&&!str_contains($output,'201011111111')&&!str_contains($output,'31.123456')&&!str_contains($output,'30.654321')&&!str_contains($output,'777'),'no customer coordinate actor or error prose '.$case);
    $value=json_decode($output,true,32,JSON_THROW_ON_ERROR);catalogExpect($value['writes']===0&&$value['ai_calls']===0&&$value['message_sends']===0&&$value['erp_writes']===0,'no actions '.$case);
    return[$exit,$value,is_file($fixture.'/readonly')];
}
[$exit,$value,$readonly]=catalogRun('valid');catalogExpect($exit===0&&$readonly&&$value['status']==='READ_READY'&&$value['items'][0]['mapped'],'valid actual literal mapper observed read-only');
catalogExpect($value['actual_dialogue_catalog_agrees']&&$value['catalog_unchanged_during_read']&&$value['items'][0]['mapped_product_id']===501,'actual copied dialogueCatalog agrees with diagnostic mirror');
catalogExpect($value['items'][0]['mapped_quantity']==='1'&&$value['items'][0]['mapped_quantity_mode']==='weight',
    'literal mapping reports the actual sale amount and unit separately');
[$exit,$value]=catalogRun('pack-quarter');$quarter=$value['items'][0];
catalogExpect($exit===0&&$quarter['mapped']&&$quarter['mapped_product_id']===2566
    &&$quarter['quantity']==='0.25'&&$quarter['quantity_mode']==='weight'
    &&$quarter['mapped_quantity']==='1'&&$quarter['mapped_quantity_mode']==='piece',
    'reviewed quarter salad maps one real fixed-size piece while preserving the recapped weight');
[$exit,$value]=catalogRun('pack-quarter-unmapped');
catalogExpect($exit===0&&!$value['items'][0]['mapped']&&$value['items'][0]['mapped_quantity']===null
    &&$value['items'][0]['mapped_quantity_mode']===null,'unmapped item reports no inferred sale quantity or mode');
[$exit,$value]=catalogRun('pack-quarter-select');$quarter=$value['items'][0];
catalogExpect($exit===0&&$value['actual_dialogue_catalog_agrees']&&$quarter['mapped']&&$quarter['mapped_product_id']===2566
    &&$quarter['quantity']==='0.25'&&$quarter['quantity_mode']==='weight'
    &&$quarter['mapped_quantity']==='1'&&$quarter['mapped_quantity_mode']==='piece'
    &&$quarter['diagnostic_similar_products'][0]['quantity_mode']==='select',
    'live selectable quarter SKU with genuine whole base reports one sale piece and original weight');
[$exit,$value]=catalogRun('pack-quarter-select-missing-base');
catalogExpect($exit===0&&!$value['items'][0]['mapped']&&$value['items'][0]['mapped_quantity']===null
    &&$value['items'][0]['mapped_quantity_mode']===null,'selectable quarter SKU without actual base preparation remains unresolved');
foreach(['no-match'=>'NO_LITERAL_PRODUCT_OR_OPTION_MATCH','unavailable'=>'MATCHED_PRODUCT_UNAVAILABLE','mode'=>'QUANTITY_MODE_MISMATCH']as$case=>$reason){
    [$exit,$value]=catalogRun($case);catalogExpect($exit===0&&$value['items'][0]['reason']===$reason,'per-item cause '.$case);
}
[$exit,$value]=catalogRun('whole');catalogExpect($exit===0&&$value['items'][0]['mapped']&&$value['items'][0]['mapped_option_id']===''
    &&$value['items'][0]['literal_candidates'][0]['preparation_options']===[['id'=>'','label'=>'كامل'],['id'=>'f:0:extra_clear','label'=>'مخلي']],
    'actual whole preparation maps original empty base identity with matching clean option');
[$exit,$value]=catalogRun('inline');catalogExpect($value['items'][0]['mapped']&&$value['items'][0]['mapped_option_id']==='f:0:extra_clear'
    &&$value['items'][0]['literal_candidates'][0]['preparation_options']===[['id'=>'','label'=>'كامل'],['id'=>'f:0:extra_clear','label'=>'مخلي']],
    'legacy and preparation overlap maps once through actual mapper');
[$exit,$value]=catalogRun('duplicate-effective');catalogExpect($value['catalog']['duplicate_effective_item']&&$value['items'][1]['reason']==='DUPLICATE_EFFECTIVE_ITEM','duplicate effective identities remain separate blocker');
foreach(['duplicate-name'=>'CATALOG_DUPLICATE_AVAILABLE_NAME','duplicate-id'=>'CATALOG_DUPLICATE_PRODUCT_ID','option-label'=>'CATALOG_DUPLICATE_OPTION_LABEL',
    'option-id'=>'CATALOG_DUPLICATE_OPTION_ID','option-empty'=>'CATALOG_OPTION_EMPTY_LABEL','malformed'=>'CATALOG_PRODUCT_MALFORMED','pages'=>'CATALOG_PAGINATION_LIMIT',
    'prep-invalid'=>'CATALOG_PREPARATION_OPTIONS_INVALID']as$case=>$reason){
    [$exit,$value]=catalogRun($case);catalogExpect($exit===0&&$value['catalog']['global_guard']===$reason&&$value['actual_dialogue_catalog_agrees']
        &&$value['items'][0]['reason']==='CATALOG_GLOBAL_GUARD_BLOCKED','actual whole-menu blocker '.$case);
}
[$exit,$value]=catalogRun('arabic-names');catalogExpect($exit===0&&$value['catalog']['global_guard']==='CLEAR','global name normalizer is not receipt Arabic normalizer');
foreach(['catalog-drift','snapshot-drift','draft-drift','route-drift']as$case){[$exit,$value]=catalogRun($case);catalogExpect($exit===1&&$value['status']==='OBSERVATION_CHANGED','stale observations rejected '.$case);}
[$exit,$value]=catalogRun('valid');catalogExpect($exit===0&&$value['branch_routing_basis']==='LOCATION'
    &&$value['branch_route_resolved']&&$value['branch_route_unchanged_during_read'],'actual point-based route is recomputed without exposing point or geometry fingerprint');
[$exit,$value]=catalogRun('location-missing');catalogExpect($exit===1&&$value['reason']==='LOCATION_UNCONFIRMED'
    &&!isset($value['branch_route_resolved']),'missing proved point cannot acquire a location-based branch');
[$exit,$value]=catalogRun('prep-drift');catalogExpect($exit===1&&$value['status']==='OBSERVATION_CHANGED','preparation facts participate in catalog fingerprint');
foreach(['description-arabic','description-english','description-digit','description-x','description-angle','description-control','float-roundtrip']as$case){
    [$exit,$value]=catalogRun($case);catalogExpect($exit===0&&$value['actual_dialogue_catalog_agrees'],
        'exact workflow description and receipt numeric semantics '.$case);
}
$compactArgs=['--conversation','11','--expected-floor','6559','--expected-ceiling','10046','--expected-revision','13','--draft','2171','--compact'];
[$exit,$value]=catalogRun('compact-many',$compactArgs);
catalogExpect($exit===0&&$value['output_mode']==='COMPACT'&&count($value['items'])===3&&$value['actual_dialogue_catalog_agrees'],'compact keeps all three recapped items and source agreement');
catalogExpect(array_column($value['items'],'recapped_name')===['رنجة هيرنج','فسيخ كامل','سردين بلدي صغير مخلي']
    &&array_column($value['items'],'quantity')===['2','1','1']&&array_column($value['items'],'quantity_mode')===['weight','weight','weight'],
    'compact preserves every item label quantity and unit');
catalogExpect(!$value['items'][2]['mapped']&&$value['items'][2]['diagnostic_similar_products'][0]['id']===2578,
    'generic sardine suggestion remains visible without mapping missing qualifiers');
foreach($value['items']as$item)catalogExpect(count($item['diagnostic_similar_products'])<=3&&count($item['literal_candidates'])<=3,
    'compact bounds relevant candidates and suggestions');
catalogExpect(strlen(json_encode($value,JSON_UNESCAPED_UNICODE))<8000,'compact output remains bounded for terminal paste');
[$exit,$value]=catalogRun('args',[...$compactArgs,'--compact']);catalogExpect($exit===1&&$value['reason']==='ARGUMENTS_INVALID','duplicate compact flag fails closed');
[$exit,$value]=catalogRun('alias-approved',$compactArgs);
catalogExpect($exit===0&&array_column($value['items'],'mapped')===[true,true]
    &&array_column($value['items'],'mapped_product_id')===[2576,2578]
    &&array_column($value['items'],'mapped_option_id')===['','f:0:extra_clear'],
    'diagnostic passes actual resolved branch to the two expressly approved aliases');
catalogExpect(array_column($value['items'],'mapped_product_name')===['رنجه هيرنج مبطرخ','سردين'],
    'compact approved aliases show exact public target labels');
foreach(['alias-other-branch','alias-meta','alias-wrong-id','alias-unavailable','alias-mode']as$case){
    [$exit,$value]=catalogRun($case,$compactArgs);catalogExpect($exit===0&&array_column($value['items'],'mapped')===[false,false],
        'approved aliases remain narrowly scoped '.$case);
}
foreach(['alias-missing-clean','alias-wrong-name']as$case){
    [$exit,$value]=catalogRun($case,$compactArgs);catalogExpect($exit===0&&$value['items'][0]['mapped']&&!$value['items'][1]['mapped'],
        'sardine alias requires exact target and configured fillet identity '.$case);
}
foreach(['owner'=>'OWNER_INVALID','source'=>'SOURCE_CHANGED','driver'=>'READ_ONLY_SESSION_REQUIRED','transaction'=>'READ_ONLY_SESSION_REQUIRED','ticket'=>'DRAFT_EXPECTATION_CHANGED']as$case=>$reason){
    [$exit,$value]=catalogRun($case);catalogExpect($exit===1&&$value['status']==='READ_BLOCKED'&&$value['reason']===$reason,'safe expected guards '.$case);
    if($case==='source')catalogExpect($value['source_guard_file']==='app/Services/Dashboard/WhatsAppInboxMessage.php','fixed source mismatch path only');
}
[$exit,$value]=catalogRun('args',['--conversation','11']);catalogExpect($exit===1&&$value['reason']==='ARGUMENTS_INVALID','explicit draft and bounds required');
echo 'whatsapp_customer_confirmation_catalog: '.$checks." checks passed\n";
