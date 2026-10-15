<?php
// The agent's delivered instructions must produce evidence accepted by the real validator.
require __DIR__.'/../app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require __DIR__.'/../app/Support/WhatsAppOrderExtraction.php';
use App\Support\WhatsAppOrderExtraction;
$checks=0;
$expect=static function($ok,string $label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException('EXTERNAL_RECAP_PROTOCOL_FAILED:'.$label);};
$prompt=file_get_contents(__DIR__.'/../deployment/references/meta_ai_external_agent_prompt_ar.txt');
$expect(is_string($prompt),'delivered prompt available');
$match=[];
$expect(preg_match('/^ملخص الطلب النهائي:\Rتأكيد الطلب:\R.*?^للموافقة على هذه البيانات اكتب: موافق على الطلب$/ums',$prompt,$match)===1,
    'fixed header and approval footer in delivered prompt');
$template=$match[0];
$expect(!str_contains($template,'الوحدة:')&&!str_contains($template,'؟'),'template has adjacent amount/unit and a final non-question summary');
$expect(str_contains($prompt,'جاري مراجعة البيانات لإرسال الطلب للفرع.'),'documented acknowledgement matches supported non-mutating form');
foreach ([['1','كيلو','weight'],['2','كيلو','weight'],['0.5','كيلو','weight'],['2','قطعة','piece']] as [$quantity,$unit,$mode]) {
    $summary=strtr($template,['[اسم العميل]'=>'عمر','[رقم التواصل]'=>'201000000001','[اسم الفرع]'=>'المنصورة',
        '[العنوان الكامل]'=>'شارع البحر','[المنطقة]'=>'المنصورة','[اسم الصنف]'=>'فسيخ','[الكمية]'=>$quantity,
        '[كيلو أو قطعة]'=>$unit,' | الاختيار: [الاختيار المؤكد إن وجد]'=>'',
        '[ملاحظات العميل أو لا يوجد]'=>'لا يوجد','[قيمة مؤكدة من المينيو الحالي]'=>'100.00']);
    $expect(!preg_match('/\[[^\]]+\]/u',$summary),'all documented placeholders rendered');
    $data=['decision'=>'CONFIRMED','customer'=>['name'=>'عمر','phone'=>'201000000001','address'=>'شارع البحر','area'=>'المنصورة','notes'=>null],
        'branch_hint'=>'المنصورة','fulfillment'=>'DELIVERY','items'=>[['name'=>'فسيخ','quantity'=>$quantity,'quantity_mode'=>$mode,'option_hint'=>null]],
        'approximate_total'=>'100.00','evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m3'],'customer_acceptance_ids'=>['m4'],'location_id'=>'m2'],'issues'=>[]];
    foreach ([false,true] as $ack) {
        $texts=['اسمي عمر ورقمي 201000000001 وعنواني شارع البحر في المنصورة. عايز '.$quantity.' '.$unit.' فسيخ من المنصورة.',
            null,$summary,'موافق على الطلب'];
        if($ack)$texts[]='جاري مراجعة البيانات لإرسال الطلب للفرع.';
        $rows=[];
        foreach($texts as $i=>$text)$rows[]=['id'=>'m'.($i+1),'speaker'=>in_array($i,[2,4])?'business':'customer',
            'sent_at'=>'2026-10-09 22:00:0'.($i+1),'text'=>$text,'location'=>$i===1?['lat'=>31.04,'long'=>31.37]:null];
        $checked=WhatsAppOrderExtraction::validateDetailed($data,$rows);
        $expect(($checked['ok']??false)===true,'real extraction accepts delivered '.$quantity.' '.$unit.' protocol'.($ack?' with ack':''));
    }
}
echo 'meta_ai_external_recap_protocol: '.$checks." checks passed\n";
