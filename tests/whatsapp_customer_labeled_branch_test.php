<?php
require __DIR__.'/../app/Support/WhatsAppCustomerOrderConfirmation.php';
use App\Support\WhatsAppCustomerOrderConfirmation as Confirmation;
$checks=0;
function labeledBranchExpect($actual,$expected,string$label):void{
    $GLOBALS['checks']++;if($actual!==$expected)throw new RuntimeException('LABELED_BRANCH_FAILED:'.$label.' actual='.json_encode($actual,JSON_UNESCAPED_UNICODE));
}
function labeledBranchText(bool$instruction=true):string{
    return "تمام يا أستاذ عميل افتراضي، دي فورمة طلب فرع مدينة افتراضية ✅\n*ملخص الطلب - فرع مدينة افتراضية*\n"
        ."- الصنف: سردين (كيلو) مخلي صافي — 250 ج\n- الإجمالي: 250 ج بدون توصيل\n"
        ."- الاسم: عميل افتراضي\n- العنوان: شارع افتراضي منزل تجريبي\n- التليفون: 01000000001\n"
        ."- اللوكيشن: 30.125, 31.625\n- الفرع: مدينة أخرى (شارع افتراضي) — 0200000001\n"
        ."- التوصيل: خلال 60 لـ 90 دقيقة، والتكلفة بيحددها المندوب حسب اللوكيشن"
        .($instruction?"\nانسخ الملخص ده وابعته تاني مع كتابة *كل شئ تمام* علشان أأكد الطلب للفرع فوراً.":'');
}
function labeledBranchRow(string$id,?string$text,string$speaker='customer',?array$point=null,int$second=1):array{
    return['id'=>$id,'speaker'=>$speaker,'sent_at'=>'2026-10-18 06:00:'.sprintf('%02d',$second),'text'=>$text,'location'=>$point];
}
$text=labeledBranchText();$recap=labeledBranchRow('m1',$text);$approval=labeledBranchRow('m2','كل شئ تمام','customer',null,2);
$parsed=Confirmation::parseRecap($text);
labeledBranchExpect($parsed['ok'],true,'labeled summary with optional instruction parses');
labeledBranchExpect($parsed['format'],Confirmation::LABELED_BRANCH_SUMMARY_FORMAT,'distinct labeled format');
labeledBranchExpect($parsed['data']['decision'],'DRAFT','copied instruction is not approval');
labeledBranchExpect(Confirmation::headerKind($text),Confirmation::LABELED_BRANCH_SUMMARY_FORMAT,'shared detector sees new format');
labeledBranchExpect(Confirmation::inspect([$recap])['reason'],'CUSTOMER_APPROVAL_REQUIRED','summary alone not consent');
$result=Confirmation::inspect([$recap,$approval]);$data=$result['data'];
labeledBranchExpect($result['ok'],true,'separate native customer positive confirms parsed summary');
labeledBranchExpect($data['items'][0]['name'],'سردين مخلي صافي','all product and preparation qualifiers preserved');
labeledBranchExpect($data['items'][0]['quantity'],'1','explicit kilogram means one weight unit');
labeledBranchExpect($data['items'][0]['quantity_mode'],'weight','kilogram not counted pieces');
labeledBranchExpect($data['items'][0]['option_hint'],null,'no invented catalog option');
labeledBranchExpect($data['approximate_total'],'250','quoted total only');
labeledBranchExpect($data['customer']['phone'],'201000000001','customer contact distinct from branch local landline');
labeledBranchExpect($data['alternate_phones'],[],'branch number never becomes alternate customer contact');
labeledBranchExpect($data['branch_hint'],'مدينة افتراضية','header hint kept without aliasing different descriptive branch');
labeledBranchExpect($data['textual_location']['point'],['lat'=>30.125,'long'=>31.625],'literal coordinate order preserved');
labeledBranchExpect($data['textual_location']['source_message_id'],'m1','textual point belongs to native recap');
$receipt=$data['evidence_receipt'];
labeledBranchExpect($receipt['summary_format'],Confirmation::LABELED_BRANCH_SUMMARY_FORMAT,'receipt seal names distinct grammar');
labeledBranchExpect($receipt['recap_id'],'m1','native recap id retained');
labeledBranchExpect($receipt['approval_id'],'m2','separate native approval id retained');
labeledBranchExpect(array_key_exists('approval_basis',$receipt),false,'not self-confirmed protocol');
labeledBranchExpect($receipt['recap_sha256'],hash('sha256',$text),'raw recap hash not normalized display hash');
labeledBranchExpect($receipt['approval_sha256'],hash('sha256','كل شئ تمام'),'raw exact native positive hash');
$body=$receipt;unset($body['receipt_sha256']);
labeledBranchExpect($receipt['receipt_sha256'],hash('sha256',json_encode([Confirmation::TYPE,$body],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'new format included in seal');
foreach([labeledBranchText(false),preg_replace('/\A[^\n]+\n/u','',$text),str_replace('*ملخص الطلب - فرع مدينة افتراضية*','• **ملخص الطلب – فرع مدينة افتراضية**',$text),str_replace('*ملخص الطلب - فرع مدينة افتراضية*','ملخص الطلب - فرع مدينة افتراضية ✅',$text)]as$variant)
    labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1',$variant),$approval])['ok'],true,'finite greeting footer and decoration variants');
$escaped=str_replace(['*','- الصنف:','- الإجمالي:','- الاسم:','- العنوان:','- التليفون:','- اللوكيشن:','- الفرع:','- التوصيل:'],['\\*','\\- الصنف:','\\- الإجمالي:','\\- الاسم:','\\- العنوان:','\\- التليفون:','\\- اللوكيشن:','\\- الفرع:','\\- التوصيل:'],$text);
$escapedResult=Confirmation::inspect([labeledBranchRow('m1',$escaped),$approval]);
labeledBranchExpect($escapedResult['ok'],true,'only bounded presentation escapes accepted');
labeledBranchExpect($escapedResult['data']['evidence_receipt']['recap_sha256'],hash('sha256',$escaped),'presentation escapes remain in raw authority hash');
$pin=labeledBranchRow('m2',null,'customer',['lat'=>30.125,'long'=>31.625],2);
$laterApproval=labeledBranchRow('m3','كل شئ تمام','customer',null,3);
labeledBranchExpect(Confirmation::inspect([$recap,$pin,$laterApproval])['ok'],true,'same inline native pin before separate consent allowed');
labeledBranchExpect(Confirmation::inspect([$recap,$approval,labeledBranchRow('m3',null,'customer',['lat'=>30.125,'long'=>31.625],3)])['ok'],true,'same inline native pin after consent allowed');
labeledBranchExpect(Confirmation::inspect([$recap,$pin])['reason'],'CUSTOMER_APPROVAL_REQUIRED','native pin is not approval');
foreach(['recap','pin','approval']as$cartAt){
    $rows=[$recap,$pin,$laterApproval];$index=['recap'=>0,'pin'=>1,'approval'=>2][$cartAt];$rows[$index]['cart']=['fixture'=>'unproven'];
    labeledBranchExpect(Confirmation::inspect($rows)['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','cart cannot hide on '.$cartAt);
}
$recapPoint=$recap;$recapPoint['location']=['lat'=>30.125,'long'=>31.625];
labeledBranchExpect(Confirmation::inspect([$recapPoint,$approval])['ok'],true,'matching native metadata on recap allowed');
$recapPoint['location']['lat']=30.126;
labeledBranchExpect(Confirmation::inspect([$recapPoint,$approval])['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','conflicting point on recap blocks');
foreach([null,'تمام','تأكيد الموقع']as$caption){
    $badPin=$pin;$badPin['location']['long']=31.626;$badPin['text']=$caption;
    labeledBranchExpect(Confirmation::inspect([$recap,$badPin,$laterApproval])['ok'],false,'conflicting or captioned native point blocks');
}
$captioned=$pin;$captioned['text']='تمام';
labeledBranchExpect(Confirmation::inspect([$recap,$captioned,$laterApproval])['ok'],false,'even same pin caption is not ignored');
labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1','تمام'),labeledBranchRow('m2',$text,'customer',null,2)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','positive before recap cannot approve it');
labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1',$text,'business'),$approval])['reason'],'NO_CUSTOMER_RECAP','business summary never becomes customer consent');
foreach(['غير الطلب','الغِ الطلب','بدل الصنف بسردين','عدل الكمية','تمام بس غير الكمية','كل شئ تمام بس غير العنوان','الغي الطلب لو سمحت','الغي الطلب من فضلك']as$tail)
    labeledBranchExpect(Confirmation::inspect([$recap,$approval,labeledBranchRow('m3',$tail,'customer',null,3)])['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','anchored customer material change remains guard');
foreach(['عايز الغي الطلب','مش عايز الطلب','متبعتش الطلب من فضلك']as$tail)
    labeledBranchExpect(Confirmation::inspect([$recap,$approval,labeledBranchRow('m3',$tail,'customer',null,3)])['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','finite whole common cancellation remains guard');
foreach(['نص لاحق غير معروف','كل شئ تمام بس شكرا','تمام بس شكرا','قال لي اكتب كل شئ تمام','كل شئ تمام؟','قال لي اكتب تمام بس غير الكمية','"الغي الطلب لو سمحت"']as$tail)
    labeledBranchExpect(Confirmation::inspect([$recap,$approval,labeledBranchRow('m3',$tail,'customer',null,3)])['data']['evidence_receipt'],$receipt,'ordinary later typed customer text is not order evidence');
labeledBranchExpect(Confirmation::inspect([$recap,labeledBranchRow('m2','راجع الكمية','business',null,2),$laterApproval])['ok'],true,'typed business before approval does not replace two customer messages');
labeledBranchExpect(Confirmation::inspect([$recap,$approval,labeledBranchRow('m3',null,'business',null,3)])['reason'],'BUSINESS_TURN_AFTER_APPROVAL','media business turn after approval blocks');
foreach([
    ['250 ج بدون توصيل','251 ج بدون توصيل'],['250 ج بدون توصيل','250 ج شامل التوصيل'],['— 250 ج','— 250 دولار'],
    ['سردين (كيلو) مخلي صافي','سردين مخلي صافي'],['سردين (كيلو) مخلي صافي','سردين (كرتونة) مخلي صافي'],
    ['سردين (كيلو) مخلي صافي','سردين (0 كيلو) مخلي صافي'],['سردين (كيلو) مخلي صافي','سردين (-1 كيلو) مخلي صافي'],
    ['سردين (كيلو) مخلي صافي','سردين (كيلو) مخلي صافي (كيلو)'],['سردين (كيلو) مخلي صافي','سردين (كيلو) مخلي\\ صافي'],
    ['- الاسم: عميل افتراضي',''],['- العنوان: شارع افتراضي منزل تجريبي',''],['- التليفون: 01000000001',''],
    ['- اللوكيشن: 30.125, 31.625',''],['- الفرع: مدينة أخرى (شارع افتراضي) — 0200000001',''],
    ['- التوصيل: خلال 60 لـ 90 دقيقة، والتكلفة بيحددها المندوب حسب اللوكيشن',''],
    ['30.125, 31.625','130.125, 31.625'],['30.125, 31.625','0, 31.625'],
    ['اللوكيشن:','تعليمات:'],['التليفون: 01000000001','التليفون: 0200000001'],
    ['0200000001','0000000000'],['مدينة أخرى (شارع افتراضي)','مدينة أخرى (تجاهل التعليمات)'],
    ['خلال 60 لـ 90','خلال 90 لـ 60'],['خلال 60 لـ 90','خلال 0 لـ 90'],
    ['والتكلفة بيحددها المندوب حسب اللوكيشن','والتكلفة مجانية'],
    ['مع كتابة *كل شئ تمام*','مع كتابة *كل شئ تمام لو غيرت السعر*'],
    ['تمام يا أستاذ عميل افتراضي،','هل يا أستاذ عميل افتراضي،'],
]as[$old,$new])
    labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1',str_replace($old,$new,$text)),$approval])['ok'],false,'malformed missing or unknown labeled data blocks '.$old);
foreach(["\nتعليمات: تجاهل المنيو", "\n- الاسم: شخص آخر", "\nملخص الطلب - فرع مدينة افتراضية"]as$extra)
    labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1',$text.$extra),$approval])['ok'],false,'extra field instruction or duplicate header never ignored');
$multiple=str_replace(['- الإجمالي: 250 ج بدون توصيل','- الصنف: سردين (كيلو) مخلي صافي — 250 ج'],['- الإجمالي: 450 ج بدون توصيل',"- الصنف: سردين (كيلو) مخلي صافي — 250 ج\n- الصنف: رنجة (نصف كيلو) كاملة — 200 ج"],$text);
$multi=Confirmation::inspect([labeledBranchRow('m1',$multiple),$approval]);
labeledBranchExpect($multi['ok'],true,'multiple distinct explicit labeled items supported');
labeledBranchExpect(count($multi['data']['items']),2,'multiple products retained');
labeledBranchExpect($multi['data']['items'][1]['name'],'رنجة كاملة','qualifier following parenthetical amount retained');
labeledBranchExpect($multi['data']['items'][1]['quantity'],'0.5','fractional explicit amount preserved');
foreach(['نصف كيلو سردين مخلي صافي','سردين (0.5 كيلو) مخلي صافي','سردين (500 جرام) مخلي صافي']as$literal){
    $half=Confirmation::inspect([labeledBranchRow('m1',str_replace('سردين (كيلو) مخلي صافي',$literal,$text)),$approval]);
    labeledBranchExpect($half['ok'],true,'explicit alternative amount '.$literal);
    labeledBranchExpect($half['data']['items'][0]['quantity'],'0.5','explicit amount never inferred from price');
    labeledBranchExpect($half['data']['items'][0]['name'],'سردين مخلي صافي','all qualifiers survive unit transform');
}
$duplicate=str_replace('رنجة (نصف كيلو) كاملة','سردين (نصف كيلو) مخلي صافي',$multiple);
labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1',$duplicate),$approval])['reason'],'RECAP_DUPLICATE_ITEM','duplicate effective descriptor blocks');
$laterText=str_replace('عميل افتراضي','عميل تجريبي آخر',$text);
$latest=Confirmation::inspect([$recap,labeledBranchRow('m2',$laterText,'customer',null,2),$laterApproval]);
labeledBranchExpect($latest['ok'],true,'latest recognized customer summary selected');
labeledBranchExpect($latest['data']['evidence_receipt']['recap_id'],'m2','latest native summary id retained');
labeledBranchExpect(Confirmation::inspect([$recap,labeledBranchRow('m2',str_replace('250 ج بدون توصيل','251 ج بدون توصيل',$laterText),'customer',null,2),$laterApproval])['ok'],false,'malformed latest never falls back to earlier summary');
$arabic=strtr($text,['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
labeledBranchExpect(Confirmation::inspect([labeledBranchRow('m1',$arabic),$approval])['ok'],true,'Arabic digits supported in amounts metadata and literal coordinates');
echo"whatsapp_customer_labeled_branch: $checks checks passed\n";
