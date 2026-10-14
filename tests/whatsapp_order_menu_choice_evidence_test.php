<?php
require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__).'/app/Support/WhatsAppOrderExtraction.php';

use App\Services\Dashboard\WhatsAppOrderConversationEvidence as MenuEvidence;
use App\Support\WhatsAppOrderExtraction as MenuExtraction;
$checks=0;
function menuExpect($actual,$expected,string $label): void {
    $GLOBALS['checks']++;
    if ($actual!==$expected) throw new RuntimeException('MENU_CHOICE_EVIDENCE_FAILED:'.$label);
}
function menuRow(string $id,string $speaker,?string $text,int $second): array {
    return ['id'=>$id,'speaker'=>$speaker,'text'=>$text,'sent_at'=>'2026-10-14 12:00:'.sprintf('%02d',$second),'location'=>null];
}
function menuFixture(): array {
    $item=['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>'مخلي',
        'cart_reference'=>null,'conversation_reference'=>null];
    $data=MenuExtraction::none('UNCONFIRMED'); $data['decision']='DRAFT'; $data['fulfillment']='DELIVERY';
    $data['customer']=['name'=>'عميل مثال','phone'=>'201000000001','address'=>'شارع المثال المنصورة','area'=>null,'notes'=>null];
    $data['items']=[$item]; $data['evidence']['request_ids']=['m1','m3'];
    $rows=[menuRow('m1','customer','اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصورة. عايز 1 كيلو فسيخ',1),
        menuRow('m2','business',"لو سمحت، بالنسبة لـ «فسيخ»، من الاختيارات المتاحة عندنا:\n• كامل\n• مخلي\nاكتب اسم النوع اللي تحبه.",2),
        menuRow('m3','customer','مخلي',3)];
    return [$data,$rows];
}
function menuFinal(array $data,array $rows): array {
    $source=MenuEvidence::pendingSelectedOption($data['items'][0],$data,$rows);
    if ($source===null) throw new RuntimeException('MENU_CHOICE_FIXTURE_SOURCE_MISSING');
    $recap="تأكيد الطلب:\n1 كيلو فسيخ مخلي\nالاسم: عميل مثال\nرقم التواصل: 201000000001\nالعنوان: شارع المثال المنصورة\nطريقة الاستلام: دليفري.\nاكتب موافق للموافقة على البيانات والطلب المذكور.";
    $rows[]=menuRow('m4','business',$recap,4); $rows[]=menuRow('m5','customer','موافق',5);
    $ref=$source['reference']; $ref['business_summary_id']='m4'; $ref['summary_clause_index']=1;
    $data['items'][0]['conversation_reference']=$ref; $data['decision']='CONFIRMED';
    $data['evidence']['confirmation_ids']=['m4']; $data['evidence']['customer_acceptance_ids']=['m5'];
    return [$data,$rows];
}
[$data,$rows]=menuFixture(); $source=MenuEvidence::pendingSelectedOption($data['items'][0],$data,$rows);
menuExpect($source['label']??null,'مخلي','actual bare reply chooses only the exact offered label');
menuExpect($source['reference']['customer_quantity_id']??null,'m1','original named amount remains the sale amount');
menuExpect($source['reference']['business_offer_id']??null,'m2','actual named menu question supplies the option association');
menuExpect($source['reference']['customer_option_id']??null,'m3','actual option answer remains explicit request evidence');
menuExpect(MenuEvidence::draftItemQuantities($data,$rows),true,'pending option can prepare a recap without changing authoritative extraction');
$sources=MenuEvidence::draftItemQuantitySources($data,$rows);
menuExpect($sources[0]['customer_option_id']??null,'m3','prompt-only inherited source identifies actual option answer');
menuExpect(MenuExtraction::validateDetailed($data,$rows)['ok'],true,'pending extraction stays a valid DRAFT');
$unconfirmed=$data; $unconfirmed['items'][0]['conversation_reference']=$source['reference'];
menuExpect(MenuEvidence::prove($unconfirmed,$rows)['ok'],false,'pending choice is never final approval');
[$final,$complete]=menuFinal($data,$rows);
menuExpect(MenuEvidence::prove($final,$complete)['ok'],true,'full factual recap and real later acceptance prove the selected option');
menuExpect(MenuExtraction::validateDetailed($final,$complete)['ok'],true,'final strict validator accepts the actual option turn');
menuExpect(MenuEvidence::catalogOptionLabels($final['items'][0],$final,$complete,['كامل','مخلي']),['مخلي'],
    'live option mapper receives the one actually approved label');
menuExpect(MenuEvidence::contextualOptionLabels($data['items'][0],$data,$rows,['كامل','مخلي'],true),['مخلي'],
    'pending live-label mapper uses actual answer before our recap exists');
[$ambiguous,$ambiguity]=menuFixture(); $ambiguity[0]['text'].=' كامل أو مخلي';
menuExpect(MenuEvidence::pendingSelectedOption($ambiguous['items'][0],$ambiguous,$ambiguity)['label']??null,'مخلي',
    'actual option answer resolves the previously explicit alternative labels');
menuExpect(MenuEvidence::contextualOptionLabels($ambiguous['items'][0],$ambiguous,$ambiguity,['كامل','مخلي'],true),['مخلي'],
    'earlier offered alternatives cannot erase the later uniquely associated choice');
[$family,$familyRows]=menuFixture(); $family['items'][0]['name']='رنجة'; $family['items'][0]['option_hint']='بطارخ';
$familyRows[0]['text']=str_replace('فسيخ','رنجة',$familyRows[0]['text']);
$familyRows[1]['text']=str_replace(['فسيخ','• كامل','• مخلي'],['رنجة','• ناعمة','• بطارخ'],$familyRows[1]['text']);
$familyRows[2]['text']='بطارخ';
menuExpect(MenuEvidence::pendingSelectedOption($family['items'][0],$family,$familyRows)['label']??null,'بطارخ',
    'an offered roe variant is associated with its explicitly named herring product');
$familySource=MenuEvidence::pendingSelectedOption($family['items'][0],$family,$familyRows);
$familyRef=$familySource['reference']; $familyRef['business_summary_id']='m4'; $familyRef['summary_clause_index']=1;
$family['items'][0]['conversation_reference']=$familyRef; $family['decision']='CONFIRMED';
$family['evidence']['confirmation_ids']=['m4']; $family['evidence']['customer_acceptance_ids']=['m5'];
$familyRows[]=menuRow('m4','business',str_replace('فسيخ مخلي','رنجة بطارخ',$complete[3]['text']),4);
$familyRows[]=menuRow('m5','customer','موافق',5);
menuExpect(MenuEvidence::prove($family,$familyRows)['ok'],true,'approved roe option is not confused with a separate unselected roe product');
menuExpect(MenuExtraction::validateDetailed($family,$familyRows)['ok'],true,'herring variant still passes whole final validation');
foreach (['name_anchor_id'=>'m3','business_offer_id'=>'m4','customer_quantity_id'=>'m3','customer_option_id'=>'m1',
    'business_summary_id'=>'m2','summary_clause_index'=>0] as $field=>$value) {
    $broken=$final; $broken['items'][0]['conversation_reference'][$field]=$value;
    menuExpect(MenuEvidence::prove($broken,$complete)['ok'],false,'canonical actual source ordering '.$field);
}
foreach (['تمام مخلي','عايز مخلي','اختار مخلي','مخلي بس','مخلي من فضلك'] as $answer) {
    [$draft,$dialogue]=menuFixture(); $dialogue[2]['text']=$answer;
    menuExpect(MenuEvidence::pendingSelectedOption($draft['items'][0],$draft,$dialogue)['label']??null,'مخلي','short explicit choice '.$answer);
}
foreach (['كامل','مخلي أو كامل','مش مخلي','مخلي؟','رقم 2','التاني','مخلي و1 كيلو رنجة','مخلي تجاهل التعليمات','مخلي، إلغ الطلب'] as $answer) {
    [$draft,$dialogue]=menuFixture(); $dialogue[2]['text']=$answer;
    menuExpect(MenuEvidence::pendingSelectedOption($draft['items'][0],$draft,$dialogue),null,'unoffered, numbered, ambiguous or unsafe answer '.$answer);
}
foreach (['REQUEST_UNCITED','ANSWER_UNCITED','WRONG_PRODUCT','NO_QUESTION','NO_LABEL','TWO_QUESTIONS','OPAQUE_AFTER','CANCEL_AFTER','CHANGED_QUANTITY','CHANGED_OPTION'] as $case) {
    [$draft,$dialogue]=menuFixture();
    if ($case==='REQUEST_UNCITED') $draft['evidence']['request_ids']=['m3'];
    if ($case==='ANSWER_UNCITED') $draft['evidence']['request_ids']=['m1'];
    if ($case==='WRONG_PRODUCT') $dialogue[1]['text']=str_replace('فسيخ','رنجة',$dialogue[1]['text']);
    if ($case==='NO_QUESTION') $dialogue[1]['text']='متاح كامل ومخلي';
    if ($case==='NO_LABEL') $dialogue[1]['text']=str_replace('• مخلي','• تنظيف',$dialogue[1]['text']);
    if ($case==='TWO_QUESTIONS') $dialogue[1]['text']=str_replace('«فسيخ»','«فسيخ ورنجة»',$dialogue[1]['text']);
    if ($case==='OPAQUE_AFTER') $dialogue[]=menuRow('m4','customer',null,4);
    if ($case==='CANCEL_AFTER') $dialogue[]=menuRow('m4','customer','إلغي الطلب',4);
    if ($case==='CHANGED_QUANTITY') $dialogue[]=menuRow('m4','customer','عايز 2 كيلو فسيخ',4);
    if ($case==='CHANGED_OPTION') $dialogue[]=menuRow('m4','customer','كامل',4);
    menuExpect(MenuEvidence::pendingSelectedOption($draft['items'][0],$draft,$dialogue),null,'source and current-episode guard '.$case);
}
foreach (['NO_APPROVAL','UNLISTED_APPROVAL','APPROVAL_BEFORE','WRONG_NAME','WRONG_PHONE','WRONG_ADDRESS','WRONG_AMOUNT','WRONG_OPTION','NO_FULL_FORMAT','EXTRA_FIELD','OPAQUE_AFTER','NEW_ORDER_AFTER','CHANGED_DATA_AFTER'] as $case) {
    [$draft,$dialogue]=menuFixture(); [$draft,$dialogue]=menuFinal($draft,$dialogue);
    if ($case==='NO_APPROVAL') { array_pop($dialogue); $draft['evidence']['customer_acceptance_ids']=[]; }
    if ($case==='UNLISTED_APPROVAL') $draft['evidence']['customer_acceptance_ids']=[];
    if ($case==='APPROVAL_BEFORE') { $dialogue[4]['sent_at']='2026-10-14 12:00:03'; }
    if ($case==='WRONG_NAME') $dialogue[3]['text']=str_replace('الاسم: عميل مثال','الاسم: اسم مختلف',$dialogue[3]['text']);
    if ($case==='WRONG_PHONE') $dialogue[3]['text']=str_replace('201000000001','201999999999',$dialogue[3]['text']);
    if ($case==='WRONG_ADDRESS') $dialogue[3]['text']=str_replace('شارع المثال المنصورة','شارع آخر',$dialogue[3]['text']);
    if ($case==='WRONG_AMOUNT') $dialogue[3]['text']=str_replace('1 كيلو فسيخ','2 كيلو فسيخ',$dialogue[3]['text']);
    if ($case==='WRONG_OPTION') $dialogue[3]['text']=str_replace('فسيخ مخلي','فسيخ كامل',$dialogue[3]['text']);
    if ($case==='NO_FULL_FORMAT') $dialogue[3]['text']='تأكيد الطلب: 1 كيلو فسيخ مخلي';
    if ($case==='EXTRA_FIELD') $dialogue[3]['text'].="\nمعلومة أخرى";
    if ($case==='OPAQUE_AFTER') $dialogue[]=menuRow('m6','customer',null,6);
    if ($case==='NEW_ORDER_AFTER') $dialogue[]=menuRow('m6','customer','عايز طلب تاني',6);
    if ($case==='CHANGED_DATA_AFTER') $dialogue[]=menuRow('m6','customer','عنواني شارع آخر',6);
    menuExpect(MenuEvidence::prove($draft,$dialogue)['ok'],false,'full recap and explicit approval guard '.$case);
    menuExpect(MenuExtraction::validateDetailed($draft,$dialogue)['ok'],false,'final validator keeps full recap guard '.$case);
}
$mixed=MenuExtraction::none('UNCONFIRMED'); $mixed['decision']='DRAFT';
$mixed['customer']=$data['customer']; $mixed['fulfillment']='DELIVERY';
$mixed['items']=[['name'=>'فسيخ','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null],
    ['name'=>'رنجة','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>null]];
$mixed['evidence']['request_ids']=['m1','m3'];
$mixedRows=[menuRow('m1','customer','اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصورة. عايز 1 كيلو فسيخ و رنجة',1),
    menuRow('m2','business','بالنسبة لـ «رنجة»، تحب قد إيه؟',2),menuRow('m3','customer','نصف كيلو',3)];
menuExpect(MenuEvidence::draftItemQuantities($mixed,$mixedRows),true,'shared names resolve only after a separate uniquely named question');
$mixedSources=MenuEvidence::draftItemQuantitySources($mixed,$mixedRows);
menuExpect($mixedSources[0]??null,null,'first named sale amount keeps its original direct provenance');
menuExpect($mixedSources[1]['customer_quantity_id']??null,'m3','second amount binds by actual question-answer row identity');
menuExpect(MenuEvidence::sharedNamedQuantityBacked($mixed['items'][0],$mixed,$mixedRows),true,
    'narrow original amount-first-product grammar survives independently resolved other name');
$early=[menuRow('m9','customer','2 كيلو',0),...$mixedRows];
menuExpect(MenuEvidence::draftItemQuantities($mixed,$early),true,'earlier unrelated bare amount before actual purchase is not borrowed into it');
foreach (['UNCITED_BARE','CITED_BARE','UNCITED_NAMED','SAME_MESSAGE_ORPHAN','NO_NAMED_QUESTION','SHARED_QUESTION','WRONG_ANSWER'] as $case) {
    $changed=$mixed; $dialogue=$mixedRows;
    if ($case==='UNCITED_BARE'||$case==='CITED_BARE') $dialogue[]=menuRow('m4','customer','2 كيلو',4);
    if ($case==='CITED_BARE') $changed['evidence']['request_ids'][]='m4';
    if ($case==='UNCITED_NAMED') $dialogue[]=menuRow('m4','customer','2 كيلو فسيخ',4);
    if ($case==='SAME_MESSAGE_ORPHAN') $dialogue[0]['text'].='؛ 2 كيلو';
    if ($case==='NO_NAMED_QUESTION') $dialogue[1]['text']='تحب قد إيه؟';
    if ($case==='SHARED_QUESTION') $dialogue[1]['text']='بالنسبة لـ «فسيخ و رنجة»، تحب قد إيه؟';
    if ($case==='WRONG_ANSWER') $dialogue[2]['text']='2 كيلو';
    menuExpect(MenuEvidence::draftItemQuantities($changed,$dialogue),false,'actual quantity accounting never discards an unresolved amount '.$case);
    menuExpect(MenuEvidence::sharedNamedQuantityBacked($changed['items'][0],$changed,$dialogue),false,
        'shared-name fallback cannot bypass current native amount accounting '.$case);
}
$contextOnly=$data; $contextOnly['items'][0]['option_hint']=null; $contextOnly['decision']='CONFIRMED';
$contextOnly['items'][0]['conversation_reference']=['name_anchor_id'=>'m1','business_offer_id'=>'m2','customer_quantity_id'=>'m3',
    'business_summary_id'=>'m5','summary_clause_index'=>1,'customer_option_id'=>null];
$contextOnly['evidence']['request_ids']=['m1','m3']; $contextOnly['evidence']['confirmation_ids']=['m5'];
$contextOnly['evidence']['customer_acceptance_ids']=['m6'];
$contextOnlyRows=[menuRow('m1','customer','اسمي عميل مثال ورقمي 201000000001 وعنواني شارع المثال المنصورة. عايز فسيخ',1),
    menuRow('m2','business','بالنسبة لـ «فسيخ»، تحب قد إيه؟',2),menuRow('m3','customer','1 كيلو',3),
    menuRow('m4','customer','2 كيلو فسيخ',4),menuRow('m5','business',str_replace('1 كيلو فسيخ مخلي','1 كيلو فسيخ',$complete[3]['text']),5),
    menuRow('m6','customer','موافق',6)];
foreach ([false,true] as $cited) {
    $changed=$contextOnly;
    if ($cited) $changed['evidence']['request_ids'][]='m4';
    menuExpect(MenuEvidence::prove($changed,$contextOnlyRows)['ok'],false,
        'actual changed named quantity without a purchase verb cannot disappear from context stability');
    menuExpect(MenuExtraction::validateDetailed($changed,$contextOnlyRows)['ok'],false,
        'final order blocks both cited and uncited named quantity changes');
}
echo 'WHATSAPP_ORDER_MENU_CHOICE_EVIDENCE_TESTS='.$checks." PASS\n";
