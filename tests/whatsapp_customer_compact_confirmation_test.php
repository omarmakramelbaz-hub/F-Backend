<?php
require __DIR__.'/../app/Support/WhatsAppCustomerOrderConfirmation.php';
use App\Support\WhatsAppCustomerOrderConfirmation as Confirmation;
$checks=0;
function compactExpect($actual,$expected,string$label):void{
    $GLOBALS['checks']++;if($actual!==$expected)throw new RuntimeException('COMPACT_CONFIRMATION_FAILED:'.$label);
}
function compactRecap(bool$prefix=true):string{
    return($prefix?"تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً.\n":'')."طلبك المؤكد:\n"
        ."• كيلو رنجة مبطرخ (كاملة) — 220 جنيه\n• كيلو فسيخ نبروه 3 سمكات كامل — 400 جنيه\n• كيلو سردين بلدي كبير مخلي — 250 جنيه\n"
        ."الإجمالي 870 جنيه غير التوصيل\nالبيانات: عميل افتراضي - شارع افتراضي - حي افتراضي - 01000000001 - اللوكيشن 30.125,31.625\n"
        ."التوصيل خلال 60 دقيقة داخل مدينة افتراضية، وسعر التوصيل يأكده المندوب لما يكلمك.";
}
function compactRow(string$id,?string$text,string$speaker='customer',?array$point=null,int$second=1):array{
    return['id'=>$id,'speaker'=>$speaker,'sent_at'=>'2026-10-18 06:00:'.sprintf('%02d',$second),'text'=>$text,'location'=>$point];
}
$text=compactRecap();$row=compactRow('m1',$text);$original=$row;
$parsed=Confirmation::parseRecap($text);
compactExpect($parsed['ok'],true,'documented compact template parses');
compactExpect($parsed['self_confirmed'],true,'distinct internal parse marker');
compactExpect($parsed['data']['decision'],'DRAFT','standalone parse is not customer authority');
compactExpect($parsed['data']['evidence_receipt'],null,'standalone parse has no receipt');
$result=Confirmation::inspect([$row]);$data=$result['data'];
compactExpect($result['ok'],true,'one authentic customer compact copy is confirmed by owner policy');
compactExpect($data['decision'],'CONFIRMED','customer-owned compact confirmation decision');
compactExpect($data['customer'],['name'=>'عميل افتراضي','phone'=>'201000000001','address'=>'شارع افتراضي - حي افتراضي','area'=>null,'notes'=>null],'combined fields split without losing address hyphens');
compactExpect(count($data['items']),3,'all exact weight item names retained');
compactExpect($data['items'][0]['name'],'رنجة مبطرخ (كاملة)','preparation parentheses stay literal for actual menu verification');
compactExpect($data['items'][1]['name'],'فسيخ نبروه 3 سمكات كامل','origin fish count and preparation retained');
compactExpect($data['items'][1]['quantity'],'1','fish count does not replace kilograms');
compactExpect($data['approximate_total'],'870','quoted price is display hint only');
compactExpect($data['branch_hint'],null,'footer geography does not select a branch');
compactExpect($data['customer']['area'],null,'footer geography does not invent address area');
compactExpect($data['textual_location']['point'],['lat'=>30.125,'long'=>31.625],'inline literal coordinates retained');
compactExpect($data['textual_location']['source_message_id'],'m1','point bound to original customer text');
$receipt=$data['evidence_receipt'];
compactExpect($receipt['approval_basis'],Confirmation::SELF_CONFIRMED_BASIS,'explicit narrowly scoped approval basis');
compactExpect($receipt['version'],1,'legacy receipt version retained');
compactExpect($receipt['recap_id'],'m1','original recap label');
compactExpect($receipt['approval_id'],'m1','same actual customer turn owns self-confirmation');
compactExpect($receipt['recap_sha256'],hash('sha256',$text),'recap original raw text hash');
compactExpect($receipt['approval_sha256'],hash('sha256',$text),'approval same original raw text hash');
$withoutSeal=$receipt;unset($withoutSeal['receipt_sha256']);
compactExpect($receipt['receipt_sha256'],hash('sha256',json_encode([Confirmation::TYPE,$withoutSeal],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'receipt seal binds basis and all native labels');
compactExpect($row,$original,'customer text and evidence rows not rewritten');
compactExpect(Confirmation::inspect([compactRow('m1',compactRecap(false))])['ok'],true,'preface optional');
foreach(['المنصورة','نبروه','المحلة الكبرى','شبرا الخيمة','مدينة افتراضية']as$place){
    $candidate=Confirmation::inspect([compactRow('m1',str_replace('مدينة افتراضية',$place,$text))]);
    compactExpect($candidate['ok'],true,'bounded place footer is generic '.$place);
    compactExpect($candidate['data']['branch_hint'],null,'footer never branch authority '.$place);
    compactExpect($candidate['data']['textual_location']['point'],['lat'=>30.125,'long'=>31.625],'footer never flips or geocodes coordinate '.$place);
}
foreach(['**طلبك المؤكد:**','**طلبك المؤكد**:','• طلبك المؤكد:','__طلبك المؤكد__:']as$header)
    compactExpect(Confirmation::inspect([compactRow('m1',str_replace('طلبك المؤكد:',$header,$text))])['ok'],true,'display-only compact header decoration '.$header);
foreach(['كل شيء تمام','موافق','تمام']as$approval){
    $candidate=Confirmation::inspect([$row,compactRow('m2',$approval,'customer',null,2)]);
    compactExpect($candidate['ok'],true,'harmless later strict positive '.$approval);
    compactExpect($candidate['data']['evidence_receipt'],$receipt,'later strict positive retains original self authority '.$approval);
}
$pin=compactRow('m2',null,'customer',['lat'=>30.125,'long'=>31.625],2);
compactExpect(Confirmation::inspect([$row,$pin])['ok'],true,'later matching location-only customer evidence allowed');
compactExpect(Confirmation::inspect([$row,$pin])['data']['evidence_receipt'],$receipt,'matching pin does not replace customer message authority');
$pinString=$pin;$pinString['location']=['lat'=>'30.1250','long'=>'31.62500'];
compactExpect(Confirmation::inspect([$row,$pinString])['ok'],true,'same numeric pin precision remains identical');
$secondPin=compactRow('m3',null,'customer',['lat'=>30.125,'long'=>31.625],3);
compactExpect(Confirmation::inspect([$row,$pin,$secondPin])['ok'],true,'repeated same coordinate-only pin harmless');
foreach([['lat'=>31.625,'long'=>30.125],['lat'=>30.1251,'long'=>31.625],['lat'=>30.125,'long'=>31.6251]]as$point)
    compactExpect(Confirmation::inspect([$row,compactRow('m2',null,'customer',$point,2)])['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','later conflicting point blocks rather than swaps');
foreach(['تمام تمام','كل شيء تمام؟','تمام لكن غير الصنف','الغي الطلب','لا تنفذ الطلب','"تمام"','قال لي اكتب تمام','لو كل شيء تمام','👍',null]as$tail)
    compactExpect(Confirmation::inspect([$row,compactRow('m2',$tail,'customer',null,2)])['ok'],false,'unknown conditional cancelled quoted or media tail blocks');
$pinText=$pin;$pinText['text']='تمام';
compactExpect(Confirmation::inspect([$row,$pinText])['ok'],false,'positive text plus pin is not location-only exception');
$pinCart=$pin;$pinCart['cart']=['fixture'=>'basket'];
compactExpect(Confirmation::inspect([$row,$pinCart])['ok'],false,'cart plus same pin blocks');
compactExpect(Confirmation::inspect([compactRow('m1',$text,'business')])['reason'],'NO_CUSTOMER_RECAP','business template never customer authority');
foreach([null,'تمام',$text]as$businessTail)
    compactExpect(Confirmation::inspect([$row,compactRow('m2',$businessTail,'business',null,2)])['ok'],false,'later business turn never disappears');
$repeated=Confirmation::inspect([$row,compactRow('m2',$text,'customer',null,2)]);
compactExpect($repeated['ok'],true,'last repeated full compact receipt is selected by owner policy');
compactExpect($repeated['data']['evidence_receipt']['recap_id'],'m2','last repeated native customer turn is authority');
$latestText=str_replace(['عميل افتراضي','رنجة مبطرخ (كاملة)','30.125,31.625','870 جنيه'],
    ['عميل افتراضي آخر','رنجة افتراضية مخلي','30.25,31.75','880 جنيه'],$text);
$latest=Confirmation::inspect([$row,compactRow('m2',$latestText,'customer',null,2)]);
compactExpect($latest['ok'],true,'last complete changed compact receipt supersedes earlier one');
compactExpect($latest['data']['customer']['name'],'عميل افتراضي آخر','latest literal name selected');
compactExpect($latest['data']['items'][0]['name'],'رنجة افتراضية مخلي','latest literal item selected');
compactExpect($latest['data']['textual_location']['point'],['lat'=>30.25,'long'=>31.75],'latest literal point selected');
compactExpect($latest['data']['approximate_total'],'880','latest quoted total selected');
compactExpect($latest['data']['evidence_receipt']['recap_id'],'m2','latest customer row is receipt source');
compactExpect($latest['data']['evidence_receipt']['approval_id'],'m2','latest compact row also owns approval');
compactExpect($latest['data']['evidence_receipt']['recap_sha256'],hash('sha256',$latestText),'latest exact raw text bound in receipt');
compactExpect($latest['data']['evidence_receipt']['approval_sha256'],hash('sha256',$latestText),'latest exact raw approval text bound');
compactExpect(Confirmation::inspect([$row,compactRow('m2',"ملخص طلبك:\nغير الطلب",'customer',null,2)])['ok'],false,'malformed latest legacy receipt never falls back to old compact authority');
$latestMalformed=str_replace('01000000001','123',$text);
compactExpect(Confirmation::inspect([$row,compactRow('m2',$latestMalformed,'customer',null,2)])['ok'],false,'malformed latest compact receipt never falls back to older valid one');
$latestCancelled=Confirmation::inspect([$row,compactRow('m2',$latestText,'customer',null,2),compactRow('m3','الغي الطلب','customer',null,3)]);
compactExpect($latestCancelled['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','material change after latest receipt still blocks');
$oldDay=$row;$oldDay['sent_at']='2026-10-17 20:59:00';
$newDay=compactRow('m2',$latestText);$newDay['sent_at']='2026-10-17 21:00:00';
compactExpect(Confirmation::inspect([$oldDay,$newDay])['data']['evidence_receipt']['recap_id'],'m2','pure parser selects latest across Cairo midnight without reviving earlier receipt');
foreach(["هل تؤكد الطلب؟\n".$text,"مثال للرسالة:\n".$text,"> ".$text,'"'.$text.'"',"لو موافق ابعت:\n".$text,
    $text."\nالغِ الطلب",$text."\nغير الكمية",$text."\nبدون توصيل",$text."\nالفرع: فرع آخر"]as$changed)
    compactExpect(Confirmation::inspect([compactRow('m1',$changed)])['ok'],false,'question quote template conditional or material extra not self-confirmation');
foreach(['المنصورة لو موافق','المنصورة بشرط خصم','المنصورة الغي الطلب','المنصورة غير العنوان','المنصورة هل تنفذ','مدينة افتراضية؟','مدينة افتراضية، مجانا']as$place)
    compactExpect(Confirmation::inspect([compactRow('m1',str_replace('مدينة افتراضية',$place,$text))])['ok'],false,'informational footer cannot carry material or conditional clause '.$place);
foreach(['طلبك المؤكد:','الإجمالي 870 جنيه غير التوصيل',
    'البيانات: عميل افتراضي - شارع افتراضي - حي افتراضي - 01000000001 - اللوكيشن 30.125,31.625',
    'التوصيل خلال 60 دقيقة داخل مدينة افتراضية، وسعر التوصيل يأكده المندوب لما يكلمك.']as$required)
    compactExpect(Confirmation::inspect([compactRow('m1',str_replace($required,'',$text))])['ok'],false,'missing required compact section blocks');
foreach([['01000000001','01000000001 و01000000002'],['01000000001','0123'],['30.125,31.625','91,31'],
    ['30.125,31.625','0,31'],['30.125,31.625','30,31,32'],['البيانات: عميل افتراضي','البيانات: '],
    ['كيلو رنجة مبطرخ','رنجة مبطرخ'],['كيلو رنجة مبطرخ','0 قطعة رنجة مبطرخ'],['كيلو رنجة مبطرخ','1.5 قطعة رنجة مبطرخ'],
    ['870 جنيه غير التوصيل','870 جنيه بشرط خصم'],['تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً.','تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً. لو موافق']]as[$old,$new])
    compactExpect(Confirmation::inspect([compactRow('m1',str_replace($old,$new,$text))])['ok'],false,'malformed compact field amount or prefix blocks');
$ar=strtr($text,['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
compactExpect(Confirmation::inspect([compactRow('m1',$ar)])['ok'],true,'Arabic quantities prices phone coordinates and duration');
$legacy="ملخص طلبك:\nكيلو فسيخ – 100 جنيه\nالإجمالي: 100 جنيه\nالاسم: عميل افتراضي\nالعنوان: شارع افتراضي\nرقم الهاتف: 01000000001\nاللوكيشن: 30.125,31.625";
compactExpect(Confirmation::inspect([compactRow('m1',$legacy)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','legacy summary still requires separate approval');
$legacyResult=Confirmation::inspect([compactRow('m1',$legacy),compactRow('m2','تمام','customer',null,2)]);
compactExpect($legacyResult['ok'],true,'legacy separate positive unchanged');
compactExpect(array_key_exists('approval_basis',$legacyResult['data']['evidence_receipt']),false,'legacy receipt schema unchanged');
compactExpect($legacyResult['data']['evidence_receipt']['approval_id'],'m2','legacy approval is still distinct customer turn');
compactExpect(Confirmation::inspect([compactRow('m1',$legacy),compactRow('m2','تمام','customer',null,2),compactRow('m3',null,'customer',['lat'=>30.125,'long'=>31.625],3)])['ok'],false,'matching pin tail exception does not leak into legacy protocol');
$labeled=str_replace('البيانات: عميل افتراضي - شارع افتراضي - حي افتراضي - 01000000001 - اللوكيشن 30.125,31.625',
    "الاسم: عميل افتراضي\nالعنوان: شارع افتراضي - حي افتراضي\nرقم الهاتف: 01000000001\nاللوكيشن: 30.125,31.625",$text);
$labeledResult=Confirmation::inspect([compactRow('m1',$labeled)]);
compactExpect($labeledResult['ok'],true,'confirmed header also supports existing strict separately labeled fields');
$labeledData=$labeledResult['data'];$combinedData=$data;unset($labeledData['evidence_receipt'],$combinedData['evidence_receipt']);
compactExpect($labeledData,$combinedData,'combined and separate fields preserve identical literal customer facts');
compactExpect($labeledResult['data']['evidence_receipt']['recap_sha256'],hash('sha256',$labeled),'labeled compact receipt binds actual raw text');
compactExpect($labeledResult['data']['evidence_receipt']['approval_basis'],Confirmation::SELF_CONFIRMED_BASIS,'labeled compact same explicit approval basis');
$oldDelivery='التوصيل خلال 60 دقيقة وسعر التوصيل بيأكده المندوب لما يكلمك.';
$labeledOld=str_replace('التوصيل خلال 60 دقيقة داخل مدينة افتراضية، وسعر التوصيل يأكده المندوب لما يكلمك.',$oldDelivery,$labeled);
compactExpect(Confirmation::inspect([compactRow('m1',$labeledOld)])['ok'],true,'previous known informational delivery-only footer supported');
foreach(['',"انسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شيء تمام عشان تأكد طلبك نهائياً.",
    $oldDelivery."\nانسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شيء تمام عشان تأكد طلبك نهائياً."]as$footer)
    compactExpect(Confirmation::inspect([compactRow('m1',str_replace($oldDelivery,$footer,$labeledOld))])['ok'],false,'selfconfirmed compact requires nonempty delivery-only footer without future consent instruction');
$url='https://maps.google.com/?q=30.125,31.625';
compactExpect(Confirmation::inspect([compactRow('m1',str_replace('30.125,31.625',$url,$text))])['ok'],true,'allowlisted location URL query marker is not treated as customer question');
compactExpect(Confirmation::inspect([compactRow('m1',str_replace('30.125,31.625',$url,$labeled))])['ok'],true,'separate labeled location URL supported');
foreach(['**ملخص طلبك**:','• ملخص طلبك:']as$header){
    $formatted=str_replace('ملخص طلبك:',$header,$legacy);
    compactExpect(Confirmation::inspect([compactRow('m1',$formatted)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','normalized legacy header still requires separate approval');
    compactExpect(Confirmation::inspect([compactRow('m1',$formatted),compactRow('m2','تمام','customer',null,2)])['ok'],true,'legacy detector and parser normalize identical display decoration');
}
foreach(['طلبك المؤكد:',str_replace('01000000001','123',$text)]as$invalidBefore)
    compactExpect(Confirmation::inspect([compactRow('m1',$invalidBefore),compactRow('m2',$text,'customer',null,2)])['ok'],true,'unproved malformed prior template does not block later corrected full confirmation');
foreach([$text,$labeled]as$base){
    $piece=Confirmation::inspect([compactRow('m1',str_replace('كيلو رنجة مبطرخ','2 قطعة رنجة مبطرخ',$base))]);
    compactExpect($piece['ok'],true,'existing explicit piece grammar remains supported in compact fields');
    compactExpect($piece['data']['items'][0]['quantity_mode'],'piece','piece mode not guessed into kilograms');
    compactExpect($piece['data']['items'][0]['quantity'],'2','explicit piece quantity retained');
    foreach(['0 قطعة رنجة مبطرخ','1.5 قطعة رنجة مبطرخ']as$badPiece)
        compactExpect(Confirmation::inspect([compactRow('m1',str_replace('كيلو رنجة مبطرخ',$badPiece,$base))])['ok'],false,'zero/fractional piece still rejected');
}
echo"whatsapp_customer_compact_confirmation: $checks checks passed\n";
