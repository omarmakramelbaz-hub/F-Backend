<?php
// Diagnose literal catalog mapping only. No planner, quote, provider, projection,
// order processing, sender or ERP action is called.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_CATALOG','writes'=>0,'ai_calls'=>0,
    'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,'scope'=>'CATALOG_MAPPING_OBSERVATION_NOT_REPAIR_OR_ORDER_ELIGIBILITY'];
$stage='ARGUMENTS';
function waCatalogArguments(array$args):array{
    $names=['--conversation'=>'conversation','--expected-floor'=>'floor',
        '--expected-ceiling'=>'ceiling','--expected-revision'=>'revision','--draft'=>'draft'];$values=[];$compact=false;
    for($i=1;$i<count($args);$i++){
        if($args[$i]==='--compact'){if($compact)throw new RuntimeException('ARGUMENTS_INVALID');$compact=true;continue;}
        $name=$names[$args[$i]]??null;
        if($name===null||isset($values[$name])||!isset($args[$i+1])
            ||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$args[$i+1]))throw new RuntimeException('ARGUMENTS_INVALID');
        $values[$name]=(int)$args[++$i];
    }
    if(count($values)!==5||$values['conversation']<1||$values['revision']<1||$values['draft']<1||$values['ceiling']<=$values['floor'])
        throw new RuntimeException('ARGUMENTS_INVALID');
    return$values+['compact'=>$compact];
}
function waCatalogCall($object,string$name,array$args=[]){
    $method=new ReflectionMethod($object,$name);$method->setAccessible(true);return$method->invokeArgs($object,$args);
}
function waCatalogError(Throwable$error):array{
    $database=$error instanceof PDOException||is_a($error,'Illuminate\\Database\\QueryException');
    $info=$error->errorInfo??null;$sqlstate=is_array($info)?($info[0]??null):null;
    if(!is_string($sqlstate)||!preg_match('/\A[A-Z0-9]{5}\z/D',$sqlstate))$sqlstate=null;
    $number=is_array($info)?filter_var($info[1]??null,FILTER_VALIDATE_INT):false;
    return['category'=>$database?'DATABASE':'EXCEPTION','sqlstate'=>$sqlstate,
        'driver_errno'=>$number!==false&&$number>=0&&$number<=65535?$number:null];
}
function waCatalogTime($value):?string{
    if(!is_string($value))return null;
    $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return$time&&$time->format('Y-m-d H:i:s')===$value&&substr($value,0,4)!=='0000'?$value:null;
}
function waCatalogInteger($value):?int{
    $number=filter_var($value,FILTER_VALIDATE_INT);return$number!==false&&$number>=0?$number:null;
}
function waCatalogCode($value):?string{
    return is_string($value)&&preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D',$value)?$value:null;
}
function waCatalogSources(array$pins,array$protocols,?array$expected=null):array{
    $result=[];
    foreach($pins as$path=>$pin){
        $GLOBALS['out']['source_guard_file']=$path;
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)
            throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');
        $actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        if(!in_array($actual,$path==='app/Support/WhatsAppInboxProtocol.php'?$protocols:[$pin],true)
            ||$expected!==null&&($expected[$path]??null)!==$actual)throw new RuntimeException('SOURCE_CHANGED');
        $result[$path]=$actual;
    }
    unset($GLOBALS['out']['source_guard_file']);
    return$result;
}
function waCatalogFingerprint($value):string{
    $canonical=static function($node)use(&$canonical){if(!is_array($node))return$node;foreach($node as$key=>$child)$node[$key]=$canonical($child);if(!array_is_list($node))ksort($node,SORT_STRING);return$node;};
    return hash('sha256',json_encode($canonical($value),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function waCatalogPublicProduct(array$product,bool$compact=false):array{
    $out=['id'=>(int)($product['id']??0),'name'=>is_string($product['name']??null)?mb_substr($product['name'],0,200):null,
        'available'=>($product['available']??null)===true,'quantity_mode'=>in_array($product['quantity_mode']??null,['weight','piece','select'],true)?$product['quantity_mode']:'OTHER'];
    foreach(['options','preparation_options']as$key){
        $out[$key]=[];
        foreach(array_slice(is_array($product[$key]??null)?$product[$key]:[],0,$compact?4:100)as$option)
            if(is_array($option))$out[$key][]=['id'=>is_string($option['id']??null)?mb_substr($option['id'],0,80):null,
                'label'=>is_string($option['label']??null)?mb_substr($option['label'],0,200):null];
    }
    return$out;
}
/** Mirror dialogueCatalog's whole-menu guards using its own two normalizers. */
function waCatalogRead($workflow,string$branch,$actor):array{
    $catalog=[];$raw=[];$seen=[];$names=[];$reason=null;$detail=null;$pages=0;
    $fail=static function(string$code,?array$at=null)use(&$reason,&$detail){if($reason===null){$reason=$code;$detail=$at;}};
    for($page=1;$page<=3;$page++){
        $pages++;$list=app(\App\Services\Dashboard\TakeawayCatalog::class)->listing(['branch'=>$branch,'page'=>$page,'per_page'=>100],$actor);
        if(!is_array($list['items']??null)||!is_numeric($list['pagination']['last_page']??null)){$fail('CATALOG_PAGE_MALFORMED');break;}
        if(count($list['items'])>100){$fail('CATALOG_PAGE_LIMIT');break;}
        foreach($list['items']as$product){
            if(!is_array($product)||!is_numeric($product['id']??null)||!is_string($product['name']??null)
                ||mb_strlen($product['name'])>200||!is_array($product['options']??null)){$fail('CATALOG_PRODUCT_MALFORMED');continue;}
            $raw[]=$product;$id=(string)$product['id'];$at=['product_id'=>(int)$id,'product_name'=>$product['name']];
            if(isset($seen[$id]))$fail('CATALOG_DUPLICATE_PRODUCT_ID',$at);$seen[$id]=true;
            $available=($product['available']??false)===true;$name=waCatalogCall($workflow,'name',[$product['name']]);
            if($available&&isset($names[$name]))$fail('CATALOG_DUPLICATE_AVAILABLE_NAME',$at+['other_product_id'=>$names[$name]]);
            if($available)$names[$name]=(int)$id;
            $options=[];$optionIds=[];$labels=[];
            foreach($product['options']as$option){
                if(!is_array($option)||!is_string($option['id']??null)||!is_string($option['label']??null)){$fail('CATALOG_OPTION_MALFORMED',$at);continue;}
                $label=waCatalogCall($workflow,'name',[$option['label']]);
                if($label==='')$fail('CATALOG_OPTION_EMPTY_LABEL',$at);
                if(isset($optionIds[$option['id']]))$fail('CATALOG_DUPLICATE_OPTION_ID',$at);
                if(isset($labels[$label]))$fail('CATALOG_DUPLICATE_OPTION_LABEL',$at);
                $optionIds[$option['id']]=true;$labels[$label]=true;
                $options[]=['id'=>$option['id'],'label'=>$option['label'],'price'=>waCatalogCall($workflow,'informationPrice',[$option['price']??null])];
            }
            $preparations=waCatalogCall($workflow,'customerReceiptPreparations',[$product,$options]);
            if($preparations===null)$fail('CATALOG_PREPARATION_OPTIONS_INVALID',$at);
            $description=$product['description']??null;
            if(!is_string($description)||trim($description)===''||mb_strlen($description)>400||preg_match('/[\\x00-\\x1f\\x7f<>]/u',$description))$description=null;
            $catalog[]=['id'=>(int)$product['id'],'name'=>$product['name'],'available'=>$available,
                'quantity_mode'=>$product['quantity_mode']??'select','options'=>$options,
                'preparation_options'=>$preparations??[],
                'price'=>waCatalogCall($workflow,'informationPrice',[$product['price']??null]),'description'=>$description];
        }
        if((int)$list['pagination']['last_page']<=$page)break;
        if($page===3)$fail('CATALOG_PAGINATION_LIMIT');
    }
    usort($catalog,static fn($a,$b)=>$a['id']<=>$b['id']);
    return['catalog'=>$reason===null?$catalog:null,'raw'=>$raw,'reason'=>$reason,'detail'=>$detail,'pages'=>$pages,
        'signature'=>hash('sha256',serialize([$catalog,$raw,$reason,$detail,$pages]))];
}
function waCatalogItem($workflow,array$item,array$catalog,array$raw,int$ordinal,bool$compact=false,?string$branch=null):array{
    $name=waCatalogCall($workflow,'customerReceiptName',[$item['name']]);$candidates=[];$matches=[];$defaults=[];$eligible=0;$available=0;$literal=0;
    foreach($catalog as$product){
        $base=waCatalogCall($workflow,'customerReceiptName',[$product['name']]);$baseMatch=$name===$base;$inline=[];
        foreach(array_merge($product['options'],$product['preparation_options']??[])as$option){$label=waCatalogCall($workflow,'customerReceiptName',[$option['label']]);
            if($label!==''&&in_array($name,[$base.' '.$label,$label.' '.$base],true))$inline[$option['id']]=$option['id'];}
        $inline=array_values($inline);
        if(!$baseMatch&&$inline===[])continue;$literal++;
        $mode=in_array($product['quantity_mode']??'select',[$item['quantity_mode'],'select'],true);
        if($product['available'])$available++;if($product['available']&&$mode)$eligible++;
        $default=null;
        if($baseMatch&&$product['available']&&$mode){$copy=$item;$copy['name']=$product['name'];$default=$product['options']===[]?'':waCatalogCall($workflow,'catalogOption',[$copy,[$copy],[$product['name']],$product]);$defaults[]=$default;}
        if($product['available']&&$mode){
            if($baseMatch&&$default!==null)$matches[$product['id'].':'.$default]=['product_id'=>$product['id'],'option_id'=>$default];
            foreach($inline as$optionId)$matches[$product['id'].':'.$optionId]=['product_id'=>$product['id'],'option_id'=>$optionId];
        }
        $source=null;foreach($raw as$r)if((int)$r['id']===$product['id']){$source=$r;break;}
        if(count($candidates)<($compact?3:30))$candidates[]=waCatalogPublicProduct($source??$product,$compact)+['base_name_exact'=>$baseMatch,
            'inline_option_ids'=>$inline,'quantity_mode_matches'=>$mode,'default_option_resolved'=>$baseMatch?$default!==null:null];
    }
    $mapped=waCatalogCall($workflow,'customerReceiptItem',[$item,$catalog,$branch]);
    $mappedProduct=null;if($mapped!==null)foreach($catalog as$product)if($product['id']===$mapped['product_id']){$mappedProduct=$product;break;}
    $reason=$mapped!==null?'MAPPED':(waCatalogCall($workflow,'quantityMillis',[$item['quantity']])===null?'QUANTITY_INVALID':
        ($literal===0?'NO_LITERAL_PRODUCT_OR_OPTION_MATCH':($available===0?'MATCHED_PRODUCT_UNAVAILABLE':
            ($eligible===0?'QUANTITY_MODE_MISMATCH':(count($matches)>1?'AMBIGUOUS_LITERAL_MAPPING':
                ($defaults!==[]&&count(array_filter($defaults,static fn($v)=>$v!==null))===0?'DEFAULT_OPTION_COVERAGE_UNRESOLVED':'MAPPING_UNRESOLVED'))))));
    $suggestions=[];$tokens=waCatalogCall($workflow,'menuTokens',[$item['name']]);$suggestionCatalog=$catalog;
    if($compact){
        $rank=[];foreach($catalog as$product){$availableTokens=waCatalogCall($workflow,'menuTokens',[$product['name']]);
            $rank[$product['id']]=[-count(array_intersect($tokens,$availableTokens)),count($availableTokens),mb_strlen($product['name']),$product['id']];}
        usort($suggestionCatalog,static fn($a,$b)=>$rank[$a['id']]<=>$rank[$b['id']]);
    }
    foreach($suggestionCatalog as$product)if($product['available']&&waCatalogCall($workflow,'relatedMenuTokens',[$tokens,waCatalogCall($workflow,'menuTokens',[$product['name']])])){
        $source=null;foreach($raw as$r)if((int)$r['id']===$product['id']){$source=$r;break;}
        if($compact&&in_array($product['id'],array_column($candidates,'id'),true))continue;
        $suggestions[]=waCatalogPublicProduct($source??$product,$compact);if(count($suggestions)===($compact?3:12))break;
    }
    return['ordinal'=>$ordinal,'recapped_name'=>mb_substr($item['name'],0,200),'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],
        'mapped'=>$mapped!==null,'reason'=>$reason,'mapped_product_id'=>$mapped['product_id']??null,'mapped_option_id'=>$mapped['option_id']??null,
        'mapped_product_name'=>$mappedProduct['name']??null,'mapped_quantity'=>$mapped['quantity']??null,'mapped_quantity_mode'=>$mapped['quantity_mode']??null,
        'literal_candidates'=>$candidates,'literal_candidates_truncated'=>$literal>($compact?3:30),'diagnostic_similar_products'=>$suggestions,
        'suggestion_scope'=>'PUBLIC_MENU_SUGGESTIONS_ONLY_NOT_ERP_MAPPING'];
}
function waCatalogDraft(int$conversation,int$id):?object{
    return \Illuminate\Support\Facades\DB::table('whatsapp_order_drafts')->where('id',$id)->where('conversation_id',$conversation)
        ->select('id','conversation_id','status','reason','revision','evidence_floor','evidence_ceiling','evidence_hash','ticket_id','updated_at')
        ->selectRaw('CASE WHEN LENGTH(extraction)<=262144 THEN extraction ELSE NULL END AS extraction')->first();
}
try{
    $options=waCatalogArguments($argv);$conversation=$options['conversation'];$stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException('OWNER_INVALID');
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/WhatsAppCustomerOrderConfirmation.php'=>'0e32b2f095f20442012f900b243af22478d620c1',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'4aa5cd5a5fb653ec1022dbee02e6768733f3dc19',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
        'app/Services/Dashboard/TakeawayAccess.php'=>'d063ee444c347c41620916b4513d0f03785141aa',
        'app/Services/Dashboard/TakeawayCatalog.php'=>'e96fce33037a06330f95d6a91553950cdf32d763',
    ];
    $protocols=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];$sources=waCatalogSources($pins,$protocols);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();$stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');$out['read_only_session']=true;
    $stage='SCOPE';$access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    if(!$db::table('whatsapp_inbox_conversations')->where('id',$conversation)->where('waba_id',$access::WABA_ID)->where('phone_number_id',$access::PHONE_ID)->exists())throw new RuntimeException('CONVERSATION_SCOPE_MISMATCH');
    $workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);$control=$app->make(\App\Services\Dashboard\MetaAiControl::class);$state=$control->state();
    $stage='CONTROL';$token=waCatalogCall($workflow,'controlToken',[$conversation]);
    if(($state['available']??null)!==true||($state['policy']['enabled']??null)!==true||($state['policy']['mode']??null)!=='ANALYZE_ONLY'
        ||($state['revision']??null)!==$options['revision']||!is_array($token))throw new RuntimeException('CONTROL_EXPECTATION_CHANGED');
    $stage='SNAPSHOT';$activation=waCatalogCall($workflow,'activationBoundary',['activation_message_id',$conversation]);$ceiling=waCatalogCall($workflow,'ceiling',[$conversation]);
    $floor=waCatalogCall($workflow,'customerReceiptFloor',[$conversation,max((int)$activation,(int)waCatalogCall($workflow,'dispatchedCeiling',[$conversation])),$ceiling]);
    if(!is_int($activation)||$floor!==$options['floor']||$ceiling!==$options['ceiling'])throw new RuntimeException('NATIVE_EXPECTATION_CHANGED');
    $snapshot=waCatalogCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    if(($snapshot['complete']??null)!==true||!is_string($snapshot['hash']??null))throw new RuntimeException('SNAPSHOT_UNAVAILABLE');
    $stage='DRAFT';$draft=waCatalogDraft($conversation,$options['draft']);
    if(!$draft||!is_string($draft->extraction)||$draft->ticket_id!==null||(int)$draft->evidence_floor!==$floor||(int)$draft->evidence_ceiling!==$ceiling
        ||!hash_equals((string)$draft->evidence_hash,$snapshot['hash']))throw new RuntimeException('DRAFT_EXPECTATION_CHANGED');
    $plain=\Illuminate\Support\Facades\Crypt::decryptString($draft->extraction);if(strlen($plain)>131072)throw new RuntimeException('DRAFT_UNAVAILABLE');
    $data=json_decode($plain,true,32,JSON_THROW_ON_ERROR);$proof=\App\Support\WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']);
    if(!is_array($data)||($proof['ok']??null)!==true||($data['type']??null)!=='CUSTOMER_CONFIRMED_RECAP'||!hash_equals(waCatalogFingerprint($data),waCatalogFingerprint($proof['data'])))throw new RuntimeException('CUSTOMER_RECEIPT_CHANGED');
    $out+=['conversation_id'=>$conversation,'draft_id'=>(int)$draft->id,'draft_revision'=>(int)$draft->revision,
        'draft_reason'=>waCatalogCode($draft->reason),'floor'=>$floor,'ceiling'=>$ceiling,'control_revision'=>$options['revision'],
        'output_mode'=>$options['compact']?'COMPACT':'FULL'];
    $stage='BRANCH';$actor=waCatalogCall($workflow,'automaticActor',[$conversation]);
    $activationAt=waCatalogCall($workflow,'activationInstant',[$conversation]);$pointReason=null;
    if(!is_string($activationAt))throw new RuntimeException('BRANCH_UNRESOLVED');
    $point=waCatalogCall($workflow,'customerReceiptPoint',[$data,$snapshot,$activationAt,&$pointReason]);
    if(!is_array($point))throw new RuntimeException('LOCATION_UNCONFIRMED');
    $resolved=waCatalogCall($workflow,'customerReceiptBranch',[$point,$actor,false]);
    if(!is_array($resolved)||!is_string($branch=$resolved['fulfillment_branch']??$resolved['value']??null)||!preg_match('/\Af:[1-9][0-9]{0,18}\z/D',$branch))throw new RuntimeException('BRANCH_UNRESOLVED');
    $out['resolved_branch']=$branch;$out['branch_routing_basis']='LOCATION';$out['branch_route_resolved']=true;
    $out['preparation_scope']='LIVE_PREPARATIONS_AND_EXACT_OWNER_APPROVED_ALIASES_EXISTING_MAPPER_AUTHORITY';
    $stage='CATALOG';$read=waCatalogRead($workflow,$branch,$actor);$out['catalog']=['pages_read'=>$read['pages'],'products_read'=>count($read['raw']),
        'global_guard'=>$read['reason']??'CLEAR','global_guard_detail'=>$read['detail']];$out['items']=[];
    if(is_int($read['detail']['product_id']??null))foreach($read['raw']as$product)if((int)$product['id']===$read['detail']['product_id']){$out['catalog']['global_guard_product']=waCatalogPublicProduct($product,$options['compact']);break;}
    $seen=[];$duplicate=false;
    foreach($data['items']as$index=>$item){
        if($read['catalog']===null){$out['items'][]=['ordinal'=>$index+1,'recapped_name'=>mb_substr($item['name'],0,200),
            'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],'mapped'=>false,'mapped_quantity'=>null,
            'mapped_quantity_mode'=>null,'reason'=>'CATALOG_GLOBAL_GUARD_BLOCKED'];continue;}
        $row=waCatalogItem($workflow,$item,$read['catalog'],$read['raw'],$index+1,$options['compact'],$branch);
        if($row['mapped']){$identity=$row['mapped_product_id'].':'.$row['mapped_option_id'];if(isset($seen[$identity])){$row['reason']='DUPLICATE_EFFECTIVE_ITEM';$row['duplicates_ordinal']=$seen[$identity];$duplicate=true;}$seen[$identity]=$index+1;}
        $out['items'][]=$row;
    }
    $out['catalog']['duplicate_effective_item']=$duplicate;
    $stage='CURRENTNESS';$actual=waCatalogCall($workflow,'dialogueCatalog',[$branch,$actor]);
    $out['actual_dialogue_catalog_agrees']=$actual===$read['catalog'];
    $later=waCatalogRead($workflow,$branch,$actor);$out['catalog_unchanged_during_read']=$later['signature']===$read['signature'];
    $current=waCatalogCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    $out['snapshot_unchanged_during_read']=($current['complete']??null)===true&&($current['hash']??null)===$snapshot['hash']&&waCatalogCall($workflow,'ceiling',[$conversation])===$ceiling;
    $laterActivationAt=waCatalogCall($workflow,'activationInstant',[$conversation]);$laterPointReason=null;
    $laterPoint=is_string($laterActivationAt)?waCatalogCall($workflow,'customerReceiptPoint',[$data,$current,$laterActivationAt,&$laterPointReason]):null;
    $laterResolved=is_array($laterPoint)?waCatalogCall($workflow,'customerReceiptBranch',[$laterPoint,$actor,false]):null;
    $out['branch_route_unchanged_during_read']=$laterActivationAt===$activationAt&&$laterPoint===$point
        &&is_array($laterResolved)&&waCatalogFingerprint($laterResolved)===waCatalogFingerprint($resolved);
    $out['draft_unchanged_during_read']=serialize(waCatalogDraft($conversation,$options['draft']))===serialize($draft);
    $laterState=$control->state();$out['control_unchanged_during_read']=($laterState['revision']??null)===$options['revision']&&($laterState['policy']??null)===($state['policy']??null)
        &&waCatalogCall($workflow,'controlToken',[$conversation])===$token;
    $newActivation=waCatalogCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    $out['activation_floor_unchanged_during_read']=$newActivation===$activation&&waCatalogCall($workflow,'customerReceiptFloor',[$conversation,
        max((int)$newActivation,(int)waCatalogCall($workflow,'dispatchedCeiling',[$conversation])),$ceiling])===$floor;
    $stage='FINAL_SOURCE_GUARD';waCatalogSources($pins,$protocols,$sources);$out['source_unchanged_during_read']=true;
    if($connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $out['status']=$out['actual_dialogue_catalog_agrees']&&$out['catalog_unchanged_during_read']&&$out['snapshot_unchanged_during_read']
        &&$out['draft_unchanged_during_read']&&$out['control_unchanged_during_read']&&$out['activation_floor_unchanged_during_read']
        &&$out['branch_route_unchanged_during_read']?'READ_READY':'OBSERVATION_CHANGED';
}catch(Throwable$error){
    $allowed=['ARGUMENTS_INVALID','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED','CONVERSATION_SCOPE_MISMATCH',
        'CONTROL_EXPECTATION_CHANGED','NATIVE_EXPECTATION_CHANGED','SNAPSHOT_UNAVAILABLE','DRAFT_EXPECTATION_CHANGED','DRAFT_UNAVAILABLE','CUSTOMER_RECEIPT_CHANGED','BRANCH_UNRESOLVED','LOCATION_UNCONFIRMED'];
    $out['status']='READ_BLOCKED';$out['stage']=$stage;$out['reason']=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'OBSERVATION_ERROR';
    if($out['reason']==='OBSERVATION_ERROR')$out['error']=waCatalogError($error);
}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(($out['status']??null)==='READ_READY'?0:1);
