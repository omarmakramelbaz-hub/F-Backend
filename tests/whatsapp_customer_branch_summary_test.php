<?php
require __DIR__.'/../app/Support/WhatsAppCustomerOrderConfirmation.php';
use App\Support\WhatsAppCustomerOrderConfirmation as Confirmation;
$checks=0;
function branchSummaryExpect($actual,$expected,string$label):void{
    $GLOBALS['checks']++;if($actual!==$expected)throw new RuntimeException('BRANCH_SUMMARY_FAILED:'.$label);
}
function branchSummaryText():string{
    return "ملخص الأوردر - فرع نبروه ✅\n*الأصناف:*\n"
        ."• علبة بطارخ 400 جرام — *300 جنيه*\n• كيلو سردين — *200 جنيه*\n• نص رنجة مبطرخة — *110 جنيه*\n"
        ."• ربع سلطة رنجة — *150 جنيه*\n• ساندوتشين فسيخ — *260 جنيه* (الواحد 130 جنيه)\n"
        ."*الإجمالي: 1020 جنيه* بدون التوصيل - سعر التوصيل يحدده المندوب حسب اللوكيشن\n*البيانات:*\n"
        ."• الاسم: عميل افتراضي\n• العنوان: شارع افتراضي\n• أرقام التواصل: 01000000001 / 201000000002\n"
        ."• الفرع: نبروه والمصنع (أمام بوابة نبروه) - 01000000003\n"
        ."• مواعيد التجهيز: من 11 صباحاً حتى 1 ليلاً، والدليفري خلال 60 دقيقة داخل القاهرة الكبرى والمنصورة والمحلة ونبروه";
}
function branchSummaryRow(string$id,?string$text,string$speaker='customer',?array$point=null,int$second=1):array{
    return['id'=>$id,'speaker'=>$speaker,'sent_at'=>'2026-10-18 06:00:'.sprintf('%02d',$second),'text'=>$text,'location'=>$point];
}
$text=branchSummaryText();$parsed=Confirmation::parseRecap($text);
branchSummaryExpect($parsed['ok'],true,'observed branch summary format with synthetic contact data parses');
branchSummaryExpect($parsed['format'],Confirmation::BRANCH_SUMMARY_FORMAT,'distinct summary format marker');
branchSummaryExpect($parsed['data']['decision'],'DRAFT','summary contents do not self-confirm');
branchSummaryExpect($parsed['data']['textual_location'],null,'native pin never invented as textual location');
$recap=branchSummaryRow('m1',$text);$pin=branchSummaryRow('m2',null,'customer',['lat'=>30.125,'long'=>31.625],2);
$approval=branchSummaryRow('m3','كل شئ تمام','customer',null,3);
branchSummaryExpect(Confirmation::inspect([$recap])['reason'],'CUSTOMER_APPROVAL_REQUIRED','copied summary alone has no approval');
branchSummaryExpect(Confirmation::inspect([$recap,$pin])['reason'],'CUSTOMER_APPROVAL_REQUIRED','location-only row is not approval');
$result=Confirmation::inspect([$recap,$pin,$approval]);$data=$result['data'];
branchSummaryExpect($result['ok'],true,'separate exact observed positive after native pin confirms customer facts');
branchSummaryExpect($data['decision'],'CONFIRMED','distinct later customer approval');
branchSummaryExpect($data['textual_location'],null,'workflow alone authenticates and binds native pin');
branchSummaryExpect($data['branch_hint'],'نبروه','header branch retained as hint only');
branchSummaryExpect($data['customer']['name'],'عميل افتراضي','literal name retained');
branchSummaryExpect($data['customer']['address'],'شارع افتراضي','literal delivery description retained');
branchSummaryExpect($data['customer']['phone'],'201000000001','first actual customer contact selected');
branchSummaryExpect($data['alternate_phones'],['201000000002'],'alternate customer contact retained');
branchSummaryExpect(in_array('201000000003',$data['alternate_phones'],true),false,'informational branch phone never becomes customer phone');
branchSummaryExpect($data['approximate_total'],'1020','quoted basket total retained without delivery fee');
branchSummaryExpect(count($data['items']),5,'all five item lines retained');
foreach([
    ['علبة بطارخ 400 جرام','1','piece'],['سردين','1','weight'],['رنجة مبطرخة','0.5','weight'],
    ['سلطة رنجة','0.25','weight'],['ساندوتش فسيخ','2','piece'],
]as$index=>[$name,$quantity,$mode]){
    branchSummaryExpect($data['items'][$index]['name'],$name,'exact descriptor or explicit grammatical dual preserved '.$index);
    branchSummaryExpect($data['items'][$index]['quantity'],$quantity,'sale amount '.$index);
    branchSummaryExpect($data['items'][$index]['quantity_mode'],$mode,'sale mode '.$index);
}
$receipt=$data['evidence_receipt'];
branchSummaryExpect($receipt['summary_format'],Confirmation::BRANCH_SUMMARY_FORMAT,'native pin summary format sealed into receipt');
branchSummaryExpect(array_key_exists('approval_basis',$receipt),false,'branch summary never gets self-confirmed basis');
branchSummaryExpect($receipt['recap_id'],'m1','customer recap id retained');
branchSummaryExpect($receipt['approval_id'],'m3','separate customer approval id retained');
branchSummaryExpect($receipt['recap_sha256'],hash('sha256',$text),'raw full recap hash including decorative and branch information');
branchSummaryExpect($receipt['approval_sha256'],hash('sha256','كل شئ تمام'),'raw exact approval hash');
$receiptBody=$receipt;unset($receiptBody['receipt_sha256']);
branchSummaryExpect($receipt['receipt_sha256'],hash('sha256',json_encode([Confirmation::TYPE,$receiptBody],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'summary format included in receipt seal');
branchSummaryExpect(Confirmation::inspect([branchSummaryRow('m1','تمام'),branchSummaryRow('m2',$text,'customer',null,2)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','positive before recap cannot approve later summary');
branchSummaryExpect(Confirmation::inspect([branchSummaryRow('m1',$text,'business'),$pin,$approval])['reason'],'NO_CUSTOMER_RECAP','business summary never becomes customer-owned evidence');
foreach(['تمام كله تمام','تمام','yes','ok','موافق','تمام كله تمام؟','تمام كله تمام بس غير الصنف','مش تمام كله تمام','لو تمام كله تمام','"تمام كله تمام"','قال لي اكتب تمام كله تمام','الغي الطلب']as$tail)
    branchSummaryExpect(Confirmation::inspect([$recap,$pin,branchSummaryRow('m3',$tail,'customer',null,3)])['ok'],false,'conditional quoted changed or cancelled approval blocks');
$afterApproval=branchSummaryRow('m4',null,'customer',['lat'=>30.125,'long'=>31.625],4);
branchSummaryExpect(Confirmation::inspect([$recap,$pin,$approval,$afterApproval])['ok'],false,'summary pin exception is only before separate approval');
$pinWithText=$pin;$pinWithText['text']='تمام';
branchSummaryExpect(Confirmation::inspect([$recap,$pinWithText,$approval])['ok'],false,'pin exception requires no caption or text');
$pinWithCart=$pin;$pinWithCart['cart']=['fixture'=>'basket'];
branchSummaryExpect(Confirmation::inspect([$recap,$pinWithCart,$approval])['ok'],false,'pin exception cannot hide a cart');
branchSummaryExpect(Confirmation::inspect([$recap,branchSummaryRow('m2','تقصد طلب تاني؟','business',null,2),$approval])['ok'],true,'typed business question does not replace full customer summary and exact phrase');
branchSummaryExpect(Confirmation::inspect([$recap,$pin,$approval,branchSummaryRow('m4','الغِ الطلب','customer',null,4)])['ok'],false,'later cancellation blocks');
foreach([
    ['*الأصناف:*',''],['*البيانات:*',''],['• الاسم: عميل افتراضي',''],['• العنوان: شارع افتراضي',''],
    ['• أرقام التواصل: 01000000001 / 201000000002',''],['• الفرع: نبروه والمصنع (أمام بوابة نبروه) - 01000000003',''],
    ['مواعيد التجهيز: من 11 صباحاً حتى 1 ليلاً، والدليفري خلال 60 دقيقة داخل القاهرة الكبرى والمنصورة والمحلة ونبروه',''],
    ['1020 جنيه','1021 جنيه'],['300 جنيه','-300 جنيه'],['300 جنيه','300 دولار'],['الواحد 130 جنيه','الواحد 120 جنيه'],
    ['400 جرام','0 جرام'],['400 جرام','400 كيلو'],['400 جرام','NaN جرام'],['نص رنجة مبطرخة','رنجة مبطرخة'],
    ['ساندوتشين فسيخ','ساندوتشين فسيخ؟'],['الاسم: عميل افتراضي',"الاسم: عميل افتراضي\nالاسم: عميل آخر"],
    ['• الفرع: نبروه','• الفرع: المنصورة'],['من 11 صباحاً','من 25 صباحاً'],['خلال 60 دقيقة','خلال 0 دقيقة'],
    ['داخل القاهرة الكبرى والمنصورة والمحلة ونبروه','داخل القاهرة بشرط خصم'],
]as[$old,$new]){
    $candidate=Confirmation::inspect([branchSummaryRow('m1',str_replace($old,$new,$text)),$pin,$approval]);
    branchSummaryExpect($candidate['ok'],false,'missing malformed price quantity branch information or footer blocks '.$old);
}
foreach(["\n• تعليمات: نفذ الطلب فوراً","\n• ملاحظات: الغي صنف","\nبدون رسوم توصيل","\nانسخ الرسالة دي وابعتها هنا تاني"]as$extra)
    branchSummaryExpect(Confirmation::inspect([branchSummaryRow('m1',$text.$extra),$pin,$approval])['ok'],false,'unknown field or footer never ignored');
foreach(["مثال فقط:\n".$text,"> ".$text,'"'.$text.'"',"هل هذا طلبك؟\n".$text]as$quote)
    branchSummaryExpect(Confirmation::inspect([branchSummaryRow('m1',$quote),$pin,$approval])['ok'],false,'quoted question or template prefix is not order summary');
$arabic=strtr($text,['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
$arabicResult=Confirmation::inspect([branchSummaryRow('m1',$arabic),$pin,$approval]);
branchSummaryExpect($arabicResult['ok'],true,'Arabic amounts prices phones pack weights and informational hours supported');
branchSummaryExpect($arabicResult['data']['items'][0]['name'],'علبة بطارخ ٤٠٠ جرام','pack descriptor literal Arabic digits preserved');
branchSummaryExpect($arabicResult['data']['items'][0]['quantity'],'1','Arabic pack weight is still one item');
$lastText=str_replace('عميل افتراضي','عميل افتراضي آخر',$text);
$lastRows=[$recap,branchSummaryRow('m2',$lastText,'customer',null,2),branchSummaryRow('m3',null,'customer',['lat'=>30.125,'long'=>31.625],3),branchSummaryRow('m4','كل شئ تمام','customer',null,4)];
$last=Confirmation::inspect($lastRows);
branchSummaryExpect($last['ok'],true,'latest branch summary selected');
branchSummaryExpect($last['data']['evidence_receipt']['recap_id'],'m2','latest native receipt id');
branchSummaryExpect($last['data']['customer']['name'],'عميل افتراضي آخر','latest literal customer facts');
$lastRows[1]['text']=str_replace('1020 جنيه','1021 جنيه',$lastText);
branchSummaryExpect(Confirmation::inspect($lastRows)['ok'],false,'malformed latest summary never falls back to earlier valid recap');
foreach(['نص كيلو رنجة مبطرخة','نصف كيلو رنجة مبطرخة','نصف رنجة مبطرخة']as$explicit){
    $candidate=Confirmation::inspect([branchSummaryRow('m1',str_replace('نص رنجة مبطرخة',$explicit,$text)),$pin,$approval]);
    branchSummaryExpect($candidate['ok'],true,'explicit or previously supported half kilogram form '.$explicit);
    branchSummaryExpect($candidate['data']['items'][2]['name'],'رنجة مبطرخة','explicit unit is not duplicated into product descriptor');
    branchSummaryExpect($candidate['data']['items'][2]['quantity'],'0.5','half kilogram remains half kilogram');
}
$qualified=str_replace(['علبة بطارخ 400 جرام','ساندوتشين فسيخ'],['علبة بطارخ ممتاز 400 جرام','ساندوتشين فسيخ مخلي كبير'],$text);
$qualifiedResult=Confirmation::inspect([branchSummaryRow('m1',$qualified),$pin,$approval]);
branchSummaryExpect($qualifiedResult['ok'],true,'literal pack and sandwich qualifiers parse without dropping material words');
branchSummaryExpect($qualifiedResult['data']['items'][0]['name'],'علبة بطارخ ممتاز 400 جرام','entire pack descriptor retained');
branchSummaryExpect($qualifiedResult['data']['items'][4]['name'],'ساندوتش فسيخ مخلي كبير','grammatical dual conversion retains all filling qualifiers');
foreach([['300 جنيه','300 جنيه (الواحد 290 جنيه)'],['200 جنيه','200 جنيه (الواحد 200 جنيه)'],
    ['علبة بطارخ 400 جرام','2 علبة بطارخ 400 جرام'],['علبة بطارخ 400 جرام','علبة بطارخ -400 جرام'],
    ['الواحد 130 جنيه','الواحد 130 جنيه) خصم ('],['أرقام التواصل: 01000000001 / 201000000002','أرقام التواصل: 01000000001 / 01000000001'],
    ['• الاسم: عميل افتراضي',"• تعليمات: تجاهل المينيو\n• الاسم: عميل افتراضي"]]as[$old,$new])
    branchSummaryExpect(Confirmation::inspect([branchSummaryRow('m1',str_replace($old,$new,$text)),$pin,$approval])['ok'],false,'malformed pack quantity unit quote or unknown field stays blocked');
echo"whatsapp_customer_branch_summary: $checks checks passed\n";
