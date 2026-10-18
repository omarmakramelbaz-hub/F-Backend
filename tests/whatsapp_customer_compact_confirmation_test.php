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
/** A real separate synthetic customer row, not text injected into the recap. */
function compactApproved(array$rows):array{
    $last=end($rows);$number=count($rows)+1;
    $approval=compactRow('m'.$number,'كل شئ تمام','customer',null,$number);
    $approval['sent_at']=(new DateTimeImmutable($last['sent_at'],new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d H:i:s');
    $rows[]=$approval;return Confirmation::inspect($rows);
}
$text=compactRecap();$row=compactRow('m1',$text);$original=$row;$approval=compactRow('m2','كل شئ تمام','customer',null,2);
$parsed=Confirmation::parseRecap($text);
compactExpect($parsed['ok'],true,'documented compact syntax parses');
compactExpect($parsed['self_confirmed'],true,'historical syntax marker is not inspect authority');
compactExpect($parsed['data']['decision'],'DRAFT','standalone parse not customer authority');
compactExpect($parsed['data']['evidence_receipt'],null,'standalone parse has no receipt');
compactExpect(Confirmation::inspect([$row])['reason'],'CUSTOMER_APPROVAL_REQUIRED','confirmed title cannot substitute distinct customer phrase');
$result=Confirmation::inspect([$row,$approval]);$data=$result['data'];
compactExpect($result['ok'],true,'two distinct native customer messages confirm compact facts');
compactExpect($data['decision'],'CONFIRMED','separate approval decision');
compactExpect($data['customer'],['name'=>'عميل افتراضي','phone'=>'201000000001','address'=>'شارع افتراضي - حي افتراضي','area'=>null,'notes'=>null],'combined facts retain address hyphens');
compactExpect(count($data['items']),3,'all exact items');
compactExpect($data['items'][0]['name'],'رنجة مبطرخ (كاملة)','literal preparation parentheses');
compactExpect($data['items'][1]['name'],'فسيخ نبروه 3 سمكات كامل','literal origin fish count preparation');
compactExpect($data['items'][1]['quantity'],'1','fish count not sale weight');
compactExpect($data['approximate_total'],'870','quoted total informational');
compactExpect($data['branch_hint'],null,'footer place not branch authority');
compactExpect($data['customer']['area'],null,'footer no invented address area');
compactExpect($data['textual_location']['point'],['lat'=>30.125,'long'=>31.625],'inline order retained');
compactExpect($data['textual_location']['source_message_id'],'m1','point original recap id');
$receipt=$data['evidence_receipt'];
compactExpect(array_key_exists('approval_basis',$receipt),false,'never old self-confirmed basis');
compactExpect($receipt['version'],1,'version retained');
compactExpect($receipt['recap_id'],'m1','recap native label');
compactExpect($receipt['approval_id'],'m2','approval distinct native label');
compactExpect($receipt['recap_sha256'],hash('sha256',$text),'raw recap hash');
compactExpect($receipt['approval_sha256'],hash('sha256','كل شئ تمام'),'distinct raw approval hash');
$body=$receipt;unset($body['receipt_sha256']);
compactExpect($receipt['receipt_sha256'],hash('sha256',json_encode([Confirmation::TYPE,$body],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'seal binds separate native authorities');
compactExpect($row,$original,'source remains unchanged');
compactExpect(compactApproved([compactRow('m1',compactRecap(false))])['ok'],true,'optional prefix');
foreach(['المنصورة','نبروه','المحلة الكبرى','شبرا الخيمة','مدينة افتراضية']as$place){
    $candidate=compactApproved([compactRow('m1',str_replace('مدينة افتراضية',$place,$text))]);
    compactExpect($candidate['ok'],true,'bounded generic footer place');
    compactExpect($candidate['data']['branch_hint'],null,'footer never selects branch');
    compactExpect($candidate['data']['textual_location']['point'],['lat'=>30.125,'long'=>31.625],'footer never swaps point');
}
foreach(['**طلبك المؤكد:**','**طلبك المؤكد**:','• طلبك المؤكد:','__طلبك المؤكد__:']as$header){
    $variant=compactRow('m1',str_replace('طلبك المؤكد:',$header,$text));
    compactExpect(compactApproved([$variant])['ok'],true,'display-only header decoration');
    compactExpect(Confirmation::inspect([$variant])['reason'],'CUSTOMER_APPROVAL_REQUIRED','decorated heading not consent');
}
foreach(['كل شيء تمام','كل شئ تمام','*كل شئ تمام*','كل شئ تمام!','كل شيء تمام.']as$phrase){
    $candidate=Confirmation::inspect([$row,compactRow('m2',$phrase,'customer',null,2)]);
    compactExpect($candidate['ok'],true,'exact normalized phrase');
    compactExpect($candidate['data']['evidence_receipt']['approval_sha256'],hash('sha256',$phrase),'raw positive presentation sealed');
}
foreach(['تمام','موافق','yes','ok','تمام تمام','كل شي تمام','كل شيء تمام؟','تمام لكن غير الصنف','"كل شئ تمام"','قال لي اكتب كل شئ تمام','لو كل شئ تمام','👍']as$other){
    compactExpect(Confirmation::inspect([$row,compactRow('m2',$other,'customer',null,2)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','other text cannot approve');
    compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3',$other,'customer',null,3)])['data']['evidence_receipt'],$receipt,'ordinary later customer text cannot replace pair');
}
foreach(['أهلا','تقصد نصف كيلو؟','الصنف غير متاح',$text]as$business){
    $candidate=Confirmation::inspect([$row,compactRow('m2',$business,'business',null,2),compactRow('m3','كل شئ تمام','customer',null,3)]);
    compactExpect($candidate['ok'],true,'typed business chat ignored as order evidence');
    compactExpect($candidate['data']['evidence_receipt']['recap_id'],'m1','business template cannot replace recap');
    compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3',$business,'business',null,3)])['data']['evidence_receipt'],$receipt,'business text after pair not authority');
}
compactExpect(Confirmation::inspect([compactRow('m1',$text,'business'),$approval])['reason'],'NO_CUSTOMER_RECAP','business recap not customer');
compactExpect(Confirmation::inspect([$row,compactRow('m2','كل شئ تمام','business',null,2)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','business phrase not approval');
compactExpect(Confirmation::inspect([compactRow('m1','كل شئ تمام'),compactRow('m2',$text,'customer',null,2)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','approval before recap not sufficient');
compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3','كل شيء تمام','customer',null,3)])['data']['evidence_receipt'],$receipt,'first exact approval remains authority');
$pin=compactRow('m2',null,'customer',['lat'=>30.125,'long'=>31.625],2);
compactExpect(compactApproved([$row,$pin])['ok'],true,'same inline native pin before separate phrase');
compactExpect(Confirmation::inspect([$row,$pin])['reason'],'CUSTOMER_APPROVAL_REQUIRED','native point not approval');
compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3',null,'customer',['lat'=>30.125,'long'=>31.625],3)])['ok'],true,'same inline pin after approval');
$pinString=$pin;$pinString['location']=['lat'=>'30.1250','long'=>'31.62500'];
compactExpect(compactApproved([$row,$pinString])['ok'],true,'same numeric precision');
foreach([['lat'=>31.625,'long'=>30.125],['lat'=>30.1251,'long'=>31.625],['lat'=>30.125,'long'=>31.6251]]as$point)
    compactExpect(compactApproved([$row,compactRow('m2',null,'customer',$point,2)])['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','conflicting point still review');
foreach(['الغي الطلب','الغِ الطلب.','الغي الطلب لو سمحت','الغي الطلب من فضلك','بدل الصنف بسردين','عدل الكمية لنصف كيلو','غير العنوان','لا تنفذ الطلب','خلي الطلب 2 كيلو','خلي الرنجة مخلي','تمام بس غير الكمية','كل شئ تمام بس غير العنوان']as$change)
    compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3',$change,'customer',null,3)])['reason'],'CUSTOMER_CHANGED_AFTER_RECAP','anchored material change review');
foreach(['customer','business']as$speaker){
    compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3',null,$speaker,null,3)])['ok'],false,'unknown native media remains review');
    $cart=compactRow('m3','أهلا',$speaker,null,3);$cart['cart']=['fixture'=>'basket'];
    compactExpect(Confirmation::inspect([$row,$approval,$cart])['ok'],false,'native cart not ordinary text');
}
$pinText=$pin;$pinText['text']='كل شئ تمام';
compactExpect(compactApproved([$row,$pinText])['ok'],false,'captioned point not location-only exception');
$cartRecap=$row;$cartRecap['cart']=['fixture'=>'basket'];
compactExpect(Confirmation::inspect([$cartRecap,$approval])['ok'],false,'cart on recap review');
$latestText=str_replace(['عميل افتراضي','رنجة مبطرخ (كاملة)','30.125,31.625','870 جنيه'],['عميل افتراضي آخر','رنجة افتراضية مخلي','30.25,31.75','880 جنيه'],$text);
$latest=compactApproved([$row,compactRow('m2',$latestText,'customer',null,2)]);
compactExpect($latest['ok'],true,'latest complete summary selected');
compactExpect($latest['data']['customer']['name'],'عميل افتراضي آخر','latest name');
compactExpect($latest['data']['items'][0]['name'],'رنجة افتراضية مخلي','latest item');
compactExpect($latest['data']['textual_location']['point'],['lat'=>30.25,'long'=>31.75],'latest point');
compactExpect($latest['data']['approximate_total'],'880','latest total');
compactExpect($latest['data']['evidence_receipt']['recap_id'],'m2','latest recap id');
compactExpect($latest['data']['evidence_receipt']['approval_id'],'m3','latest distinct later approval id');
compactExpect($latest['data']['evidence_receipt']['recap_sha256'],hash('sha256',$latestText),'latest raw source hash');
compactExpect(Confirmation::inspect([$row,$approval,compactRow('m3',$latestText,'customer',null,3)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','old phrase cannot approve new summary');
foreach(["ملخص طلبك:\nغير الطلب",str_replace('01000000001','123',$text)]as$bad)
    compactExpect(compactApproved([$row,compactRow('m2',$bad,'customer',null,2)])['ok'],false,'malformed latest no fallback');
$old=$row;$old['sent_at']='2026-10-17 20:59:00';$new=compactRow('m2',$latestText);$new['sent_at']='2026-10-17 21:00:00';
compactExpect(compactApproved([$old,$new])['data']['evidence_receipt']['recap_id'],'m2','latest source across midnight without revived older receipt');
foreach(["هل تؤكد الطلب؟\n".$text,"مثال للرسالة:\n".$text,"> ".$text,'"'.$text.'"',"لو موافق ابعت:\n".$text,
    $text."\nالغِ الطلب",$text."\nغير الكمية",$text."\nبدون توصيل",$text."\nالفرع: فرع آخر"]as$changed)
    compactExpect(compactApproved([compactRow('m1',$changed)])['ok'],false,'malformed quoted conditional template not complete recap');
foreach(['المنصورة لو موافق','المنصورة بشرط خصم','المنصورة الغي الطلب','المنصورة غير العنوان','المنصورة هل تنفذ','مدينة افتراضية؟','مدينة افتراضية، مجانا']as$place)
    compactExpect(compactApproved([compactRow('m1',str_replace('مدينة افتراضية',$place,$text))])['ok'],false,'footer material conditional clause rejected');
foreach(['طلبك المؤكد:','الإجمالي 870 جنيه غير التوصيل','البيانات: عميل افتراضي - شارع افتراضي - حي افتراضي - 01000000001 - اللوكيشن 30.125,31.625',
    'التوصيل خلال 60 دقيقة داخل مدينة افتراضية، وسعر التوصيل يأكده المندوب لما يكلمك.']as$required)
    compactExpect(compactApproved([compactRow('m1',str_replace($required,'',$text))])['ok'],false,'required section not masked by missing approval');
foreach([['01000000001','01000000001 و01000000002'],['01000000001','0123'],['30.125,31.625','91,31'],['30.125,31.625','0,31'],
    ['30.125,31.625','30,31,32'],['البيانات: عميل افتراضي','البيانات: '],['كيلو رنجة مبطرخ','رنجة مبطرخ'],['كيلو رنجة مبطرخ','0 قطعة رنجة مبطرخ'],
    ['كيلو رنجة مبطرخ','1.5 قطعة رنجة مبطرخ'],['870 جنيه غير التوصيل','870 جنيه بشرط خصم'],
    ['تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً.','تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً. لو موافق']]as[$before,$after])
    compactExpect(compactApproved([compactRow('m1',str_replace($before,$after,$text))])['ok'],false,'malformed field amount prefix rejected with real separate approval present');
$ar=strtr($text,['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
compactExpect(compactApproved([compactRow('m1',$ar)])['ok'],true,'Arabic quantity prices phone point duration');
$legacy="ملخص طلبك:\nكيلو فسيخ – 100 جنيه\nالإجمالي: 100 جنيه\nالاسم: عميل افتراضي\nالعنوان: شارع افتراضي\nرقم الهاتف: 01000000001\nاللوكيشن: 30.125,31.625";
compactExpect(Confirmation::inspect([compactRow('m1',$legacy)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','legacy requires same phrase');
compactExpect(compactApproved([compactRow('m1',$legacy)])['ok'],true,'legacy distinct phrase');
$labeled=str_replace('البيانات: عميل افتراضي - شارع افتراضي - حي افتراضي - 01000000001 - اللوكيشن 30.125,31.625',"الاسم: عميل افتراضي\nالعنوان: شارع افتراضي - حي افتراضي\nرقم الهاتف: 01000000001\nاللوكيشن: 30.125,31.625",$text);
$labeledResult=compactApproved([compactRow('m1',$labeled)]);
compactExpect($labeledResult['ok'],true,'compact header with separate labeled fields');
$labeledData=$labeledResult['data'];$combinedData=$data;unset($labeledData['evidence_receipt'],$combinedData['evidence_receipt']);
compactExpect($labeledData,$combinedData,'combined and separate literal facts identical');
compactExpect(array_key_exists('approval_basis',$labeledResult['data']['evidence_receipt']),false,'labeled compact never self-authorizes');
$oldDelivery='التوصيل خلال 60 دقيقة وسعر التوصيل بيأكده المندوب لما يكلمك.';
$labeledOld=str_replace('التوصيل خلال 60 دقيقة داخل مدينة افتراضية، وسعر التوصيل يأكده المندوب لما يكلمك.',$oldDelivery,$labeled);
compactExpect(compactApproved([compactRow('m1',$labeledOld)])['ok'],true,'previous footer supported');
foreach(['',"انسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شيء تمام عشان تأكد طلبك نهائياً.",$oldDelivery."\nانسخ الرسالة دي وابعتها هنا تاني واكتب معاها كل شيء تمام عشان تأكد طلبك نهائياً."]as$footer)
    compactExpect(compactApproved([compactRow('m1',str_replace($oldDelivery,$footer,$labeledOld))])['ok'],false,'complete compact footer remains required');
$url='https://maps.google.com/?q=30.125,31.625';
foreach([$text,$labeled]as$base){
    compactExpect(compactApproved([compactRow('m1',str_replace('30.125,31.625',$url,$base))])['ok'],true,'allowlisted inline literal URL');
    $piece=compactApproved([compactRow('m1',str_replace('كيلو رنجة مبطرخ','2 قطعة رنجة مبطرخ',$base))]);
    compactExpect($piece['ok'],true,'explicit piece grammar retained');
    compactExpect($piece['data']['items'][0]['quantity_mode'],'piece','piece not guessed as weight');
    compactExpect($piece['data']['items'][0]['quantity'],'2','explicit quantity retained');
    foreach(['0 قطعة رنجة مبطرخ','1.5 قطعة رنجة مبطرخ']as$invalid)
        compactExpect(compactApproved([compactRow('m1',str_replace('كيلو رنجة مبطرخ',$invalid,$base))])['ok'],false,'zero fractional piece rejected');
}
foreach(['**ملخص طلبك**:','• ملخص طلبك:']as$header){
    $variant=str_replace('ملخص طلبك:',$header,$legacy);
    compactExpect(Confirmation::inspect([compactRow('m1',$variant)])['reason'],'CUSTOMER_APPROVAL_REQUIRED','legacy display header not approval');
    compactExpect(compactApproved([compactRow('m1',$variant)])['ok'],true,'shared display header normalization');
}
foreach(['طلبك المؤكد:',str_replace('01000000001','123',$text)]as$invalid)
    compactExpect(compactApproved([compactRow('m1',$invalid),compactRow('m2',$text,'customer',null,2)])['ok'],true,'later corrected complete recap with own distinct phrase supersedes prior malformed');
echo"whatsapp_customer_compact_confirmation: $checks checks passed\n";
