<?php
require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__).'/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__).'/app/Support/WhatsAppCustomerOrderConfirmation.php';
require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
use App\Support\WhatsAppCustomerOrderConfirmation as Flexible;
use App\Services\Dashboard\WhatsAppOrderAiProvider as FlexibleProvider;
$checks=0;
function flexExpect($condition,string$label):void{global$checks;$checks++;if(!$condition)throw new RuntimeException('FLEXIBLE_AI_FAILED:'.$label);}
function flexRows():array{return[
    ['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-18 18:00:01','text'=>"محتاج كيلو سردين مخلي. اسمي عميل تجريبي، تلفوني 01000000001 وعنواني شارع تجريبي منزل 9. اللوكيشن 30.125,31.625. الإجمالي 250 ج",'location'=>null],
    ['id'=>'m2','speaker'=>'customer','sent_at'=>'2026-10-18 18:00:02','text'=>'كل شئ تمام','location'=>null],
    ['id'=>'m3','speaker'=>'customer','sent_at'=>'2026-10-18 18:00:03','text'=>null,'location'=>null],
    ['id'=>'m4','speaker'=>'customer','sent_at'=>'2026-10-18 18:00:04','text'=>'هل الطلب وصل للفرع؟','location'=>null],
];}
function flexSelection():array{return['status'=>'COMPLETE','recap_id'=>'m1','turns'=>[
    ['id'=>'m1','kind'=>'SUMMARY'],['id'=>'m2','kind'=>'OTHER'],['id'=>'m4','kind'=>'OTHER']],
    'fulfillment'=>'DELIVERY','customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001','address'=>'شارع تجريبي منزل 9','area'=>null,'notes'=>null],
    'items'=>[['name'=>'سردين','quantity'=>'1','quantity_mode'=>'weight','quantity_source'=>'كيلو سردين مخلي','option_hint'=>'مخلي']],
    'total_source'=>'250 ج','location_literal'=>'30.125,31.625'];}
$rows=flexRows();$selection=flexSelection();
$plan=Flexible::aiPlan($rows);flexExpect($plan['ok']&&count($plan['data']['input']['customer_messages'])===3,'customer text input only; no synthetic media text');
flexExpect(Flexible::inspect($rows)['reason']==='NO_CUSTOMER_RECAP','natural order has no prescribed title');
$proof=Flexible::validateAi($selection,$rows);
if(!$proof['ok'])throw new RuntimeException(json_encode($proof));
flexExpect($proof['data']['customer']['phone']==='201000000001'&&$proof['data']['items'][0]['quantity']==='1','literal contact normalization and backed quantity');
flexExpect($proof['data']['evidence_receipt']['recap_id']==='m1'&&$proof['data']['evidence_receipt']['approval_id']==='m2'
    &&$proof['data']['evidence_receipt']['summary_format']==='AI_CUSTOMER_ORDER_SUMMARY','only real distinct customer evidence grants consent');
flexExpect(Flexible::reprove($proof['data'],$rows)===$proof,'cached AI extraction re-proves without provider call');
foreach([['سردين (كيلو) مخلي صافي','1','weight','سردين','مخلي'],['كيلو ونص سردين مخلي','1.5','weight','سردين','مخلي'],
    ['ربع كيلو سردين','0.25','weight','سردين',null],['250 جرام سردين','0.25','weight','سردين',null],
    ['2 قطعة فسيخ','2','piece','فسيخ',null]]as[$clause,$quantity,$mode,$name,$option]){
    $alternate=$rows;$alternate[0]['text']=str_replace('كيلو سردين مخلي',$clause,$alternate[0]['text']);
    $selected=$selection;$selected['items'][0]=['name'=>$name,'quantity'=>$quantity,'quantity_mode'=>$mode,'quantity_source'=>$clause,'option_hint'=>$option];
    flexExpect(Flexible::validateAi($selected,$alternate)['ok'],'flexible grounded source quantity: '.$clause);
}
foreach(['name','phone','address']as$field){$bad=$selection;$bad['customer'][$field]='invented';flexExpect(!Flexible::validateAi($bad,$rows)['ok'],'model cannot invent '.$field);}
foreach(['name'=>'رنجة','quantity'=>'3','quantity_source'=>'كيلو رنجة','option_hint'=>'كامل']as$field=>$value){
    $bad=$selection;$bad['items'][0][$field]=$value;flexExpect(!Flexible::validateAi($bad,$rows)['ok'],'model cannot invent item '.$field);
}
foreach(['recap_id'=>'m4','fulfillment'=>'PICKUP','location_literal'=>'31.625,30.125','total_source'=>'999 جنيه']as$field=>$value){
    $bad=$selection;$bad[$field]=$value;flexExpect(!Flexible::validateAi($bad,$rows)['ok'],'no fabricated selection '.$field);
}
foreach(['تمام','موافق','كل شئ تمام؟','كل شئ تمام بس غير الطلب']as$text){
    $bad=$rows;$bad[1]['text']=$text;flexExpect(!Flexible::validateAi($selection,$bad)['ok'],'nonexact or conditional approval does not execute');
}
$bad=$rows;$bad[0]['speaker']='business';flexExpect(!Flexible::validateAi($selection,$bad)['ok'],'business recap never becomes customer');
$bad=$rows;$bad[1]['speaker']='business';flexExpect(!Flexible::validateAi($selection,$bad)['ok'],'business phrase never becomes consent');
$bad=$rows;$bad[3]['text']='بلاش الطلب';$change=$selection;$change['turns'][2]['kind']='CANCEL';
flexExpect(Flexible::validateAi($change,$bad)['reason']==='CUSTOMER_CHANGED_AFTER_RECAP','AI semantic cancellation remains a blocker');
$bad[3]['text']='الغي الطلب';$change['turns'][2]['kind']='OTHER';
flexExpect(Flexible::validateAi($change,$bad)['reason']==='CUSTOMER_CHANGED_AFTER_RECAP','explicit cancellation cannot be hidden by model classification');
$bad=$rows;$bad[2]['location']=['lat'=>30.126,'long'=>31.625];
flexExpect(!Flexible::validateAi($selection,$bad)['ok'],'conflicting later native pin still blocks');
$bad=$rows;$bad[2]['location']=['lat'=>30.125,'long'=>31.625];
flexExpect(Flexible::validateAi($selection,$bad)['ok'],'matching later native pin accepted');
$bad=$rows;$bad[2]['cart']=['private'=>'submitted'];flexExpect(!Flexible::validateAi($selection,$bad)['ok'],'later native cart cannot be ignored');
$bad=$selection;array_pop($bad['turns']);flexExpect(!Flexible::validateAi($bad,$rows)['ok'],'every later text must be classified');
$bad=$selection;$bad['turns'][2]['id']='m2';flexExpect(!Flexible::validateAi($bad,$rows)['ok'],'classification IDs cannot duplicate or cross native rows');
$later=$rows;$later[]=['id'=>'m5','speaker'=>'customer','sent_at'=>'2026-10-18 18:00:05','text'=>'ملخص طلبك:','location'=>null];
$bad=$selection;$bad['turns'][]=['id'=>'m5','kind'=>'OTHER'];flexExpect(!Flexible::validateAi($bad,$later)['ok'],'known malformed latest recap cannot be hidden by AI');
$bad['turns'][3]['kind']='SUMMARY';$bad['recap_id']='m5';$bad['status']='INCOMPLETE';
flexExpect(Flexible::validateAi($bad,$later)['reason']==='RECAP_REQUIRED_FIELDS_MISSING','latest incomplete summary has no old fallback');
$calls=0;$observed=null;$configuration=['enabled'=>true,'model'=>'gpt-6-luna','api_key'=>'sk-fixture-only-no-live-key'];
$transport=static function($body,$key)use(&$calls,&$observed,$selection){$calls++;$observed=json_decode($body,true);return['status'=>200,'body'=>json_encode([
    'status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode($selection,JSON_UNESCAPED_UNICODE)]]]]])];};
$provider=new FlexibleProvider($transport,$configuration);$result=$provider->extractCustomerRecap($rows);
flexExpect($result['ok']&&$calls===1,'actual Responses transport and grounding work together');
flexExpect($observed['store']===false&&$observed['stream']===false&&$observed['text']['format']['strict']===true
    &&$observed['text']['format']['name']==='whatsapp_customer_order_v1'&&!isset($observed['tools']),'structured stateless extraction grants no tools');
$without=$rows;$without[1]['text']='تمام';$provider->extractCustomerRecap($without);flexExpect($calls===1,'missing exact consent makes no API request');
foreach([['status'=>429,'body'=>'private'],['status'=>200,'body'=>'not JSON'],['status'=>200,'body'=>json_encode(['status'=>'incomplete'])],
    ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'refusal']]]]])]]as$response){
    $provider=new FlexibleProvider(static fn()=>$response,$configuration);$failed=$provider->extractCustomerRecap($rows);
    flexExpect(!$failed['ok']&&!str_contains(json_encode($failed),'private'),'failed/refused/incomplete transport never fabricates order');
}
echo"WHATSAPP_CUSTOMER_FLEXIBLE_AI_OK checks=$checks\n";
