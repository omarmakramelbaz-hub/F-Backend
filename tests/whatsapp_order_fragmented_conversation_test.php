<?php
// Synthetic conversation only; current pure helper/extractor/provider, no bootstrap, DB or network.
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
set_error_handler(static function ($severity, $message, $file, $line) {
    if ($severity & E_ALL) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
use App\Services\Dashboard\WhatsAppOrderConversationEvidence as Evidence;
use App\Support\WhatsAppOrderExtraction as Extraction;
use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
$checks=0;
function fragmentExpect($actual,$expected,string $label):void {
    $GLOBALS['checks']++;
    if($actual!==$expected)throw new RuntimeException('FAILED: '.$label);
}
function fragmentRow(string $id,string $speaker,string $text,int $second):array {
    return ['id'=>$id,'speaker'=>$speaker,'sent_at'=>'2026-10-13 12:00:'.sprintf('%02d',$second),'text'=>$text,'location'=>null];
}
function fragmentRows():array {
    return [
        fragmentRow('m1','customer','الفسيخ بكام؟',1),
        fragmentRow('m2','business','فسيخ من الكيلو 3 حبه بسعر 300 جنيه، ومن الكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟',2),
        fragmentRow('m3','customer','عايز كيلو ونص من الكيلو 3 حبه',3),
        fragmentRow('m4','business','تمام',4),
        fragmentRow('m5','customer','في رنجه',5),
        fragmentRow('m6','business','رنجه بسعر 220 جنيه للكيلو. تحب قد إيه؟',6),
        fragmentRow('m7','customer','تمام اعملي كيلو',7),
        fragmentRow('m8','business','تحب الفسيخ كامل ولا تنظيف؟',8),
        fragmentRow('m9','customer','كيلو ونص كامل',9),
        fragmentRow('m10','business','طلبك: 1.5 كيلو فسيخ من الكيلو 3 حبه كامل؛ 1 كيلو رنجه',10),
        fragmentRow('m11','customer','تمام موافق',11),
        fragmentRow('m12','business','ابعت الاسم ورقم الهاتف والعنوان',12),
        fragmentRow('m13','customer','عميل تجريبي',13),
        fragmentRow('m14','customer','01000000001',14),
        fragmentRow('m15','customer','مدينة التجربة المنصورة شارع المثال',15),
        fragmentRow('m16','business','تم استلام الاسم ورقم الهاتف والعنوان وتأكيد الطلب',16),
    ];
}
function fragmentReference(string $anchor,string $offer,string $quantity,int $clause,?string $option=null):array {
    return ['name_anchor_id'=>$anchor,'business_offer_id'=>$offer,'customer_quantity_id'=>$quantity,
        'business_summary_id'=>'m10','summary_clause_index'=>$clause,'customer_option_id'=>$option];
}
function fragmentData():array {
    return ['decision'=>'CONFIRMED','customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001',
        'address'=>'مدينة التجربة المنصورة شارع المثال','area'=>'المنصورة','notes'=>null],
        'branch_hint'=>null,'fulfillment'=>'DELIVERY','items'=>[
            ['name'=>'فسيخ','quantity'=>'1.5','quantity_mode'=>'weight','option_hint'=>'كامل','cart_reference'=>null,
                'conversation_reference'=>fragmentReference('m1','m2','m3',0,'m9')],
            ['name'=>'رنجه','quantity'=>'1','quantity_mode'=>'weight','option_hint'=>null,'cart_reference'=>null,
                'conversation_reference'=>fragmentReference('m5','m6','m7',1)],
        ],'approximate_total'=>null,'evidence'=>['request_ids'=>['m1','m3','m5','m7','m9','m13','m14','m15'],
            'confirmation_ids'=>['m10','m16'],'customer_acceptance_ids'=>['m11'],'location_id'=>null],'issues'=>[]];
}
function fragmentProve(array $data,array $rows):array {return Evidence::prove($data,array_column($rows,null,'id'));}
function fragmentAssertRejected(array $data,array $rows,string $label):void {
    $result=Extraction::validateDetailed($data,$rows);
    fragmentExpect($result['ok'],false,$label.' fails authoritative extraction');
    fragmentExpect(in_array($result['reason'],['INVALID_EVIDENCE','INVALID_EXTRACTION'],true),true,$label.' fixed fail-closed reason');
}
function fragmentResponse(array $data):array {
    return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant',
        'content'=>[['type'=>'output_text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]]],JSON_THROW_ON_ERROR)];
}
function fragmentConfig():array {return ['enabled'=>true,'mode'=>'auto','model'=>'fixture-model','api_key'=>'sk-fragment-private-fixture-key'];}

$rows=fragmentRows();$data=fragmentData();
fragmentExpect(Extraction::validateTranscript($rows)['ok'],true,'synthetic fragmented conversation meets current transcript contract');
fragmentExpect(fragmentProve($data,$rows),['ok'=>true,'reason'=>null],'bounded episode proves two selected products without inventing names');
$result=Extraction::validateDetailed($data,$rows);
fragmentExpect($result['ok'],true,'fragmented quantities plus later customer data and explicit acknowledgment are confirmed');
fragmentExpect($result['data'],$data,'helper never rewrites customer/order data');
fragmentExpect(Evidence::addressAcknowledged($data,array_column($rows,null,'id')),true,'explicit data acknowledgment accepts literal supplied address in same episode');
fragmentExpect([$result['data']['items'][0]['quantity'],$result['data']['items'][1]['quantity']],['1.5','1'],'three fish per kilo cannot become sale quantity three');
fragmentExpect($result['data']['customer']['name'],'عميل تجريبي','bare customer name is retained exactly from customer message');
fragmentExpect($result['data']['branch_hint'],null,'area does not invent a branch or force Shubra');
fragmentExpect($result['data']['evidence']['location_id'],null,'textual address never invents GPS');
$relative=$rows;$relativeData=$data;
$relative[5]['text']='تحبه كامل ولا تنظيف؟';
$relative[6]['text']='كيلو ونص كامل';
$relative[7]['text']='رنجه بسعر 220 جنيه للكيلو. تحب قد إيه؟';
$relative[8]['text']='تمام اعملي كيلو';
$relative[9]['text']='طلبك: 1.5 كيلو من الكيلو 3 حبه كامل؛ 1 كيلو رنجه';
$relative[13]['text']='رقم التليفون 01000000001';
$relative[15]['text']='تم تاكيد الاورد';
$relative[]=fragmentRow('m17','business','والبيانات والعنوان',17);
$relativeConnection=fragmentRow('m18','customer','دي رسالة اختبار علشان أتأكد إن ربط الواتساب شغال',18);
$relativeConnection['sent_at']='2026-10-14 12:00:18';$relative[]=$relativeConnection;
$relativeData['items'][0]['conversation_reference']['customer_option_id']='m7';
$relativeData['items'][1]['conversation_reference']['business_offer_id']='m8';
$relativeData['items'][1]['conversation_reference']['customer_quantity_id']='m9';
$relativeData['evidence']['request_ids']=['m1','m3','m5','m7','m9','m13','m14','m15'];
$relativeData['evidence']['confirmation_ids']=['m10','m16','m17'];
fragmentExpect(fragmentProve($relativeData,$relative),['ok'=>true,'reason'=>null],
    'renga inquiry before generic whole question does not steal uniquely weighted feseekh option before later renga quantity');
fragmentExpect(Extraction::validateDetailed($relativeData,$relative)['ok'],true,
    'exact relative turn order proves unnamed recap, prefixed phone, short final order acknowledgment and adjacent data acknowledgment');
fragmentExpect(Evidence::addressAcknowledged($relativeData,$relative),true,
    'separate adjacent data acknowledgment confirms the literal customer address in exact relative flow');
fragmentExpect(Extraction::committingRequestIds($relativeData,$relative),['m3','m9'],
    'exact relative flow uses selected feseekh and renga quantity rows without option or customer-data fallback');
fragmentExpect(Extraction::hasProbe($relative),false,'next-day technical connection test leaves earlier closed relative order intact');
$unnamed=$rows;
$unnamed[1]['text']='الكيلو 3 حبه بسعر 300 جنيه، والكيلو 2 حبه بسعر 350 جنيه. تحب قد إيه؟';
$unnamed[7]['text']='تحبه كامل ولا تنظيف؟';
$unnamed[9]['text']='طلبك: 1.5 كيلو من الكيلو 3 حبه كامل؛ 1 كيلو رنجه';
fragmentExpect(fragmentProve($data,$unnamed),['ok'=>true,'reason'=>null],
    'unnamed feseekh price reply and recap bind only via customer anchor, selected size, unique weight and basket acceptance');
fragmentExpect(Extraction::validateDetailed($data,$unnamed)['ok'],true,'actual relative flow need not repeat product name in each selected turn');
// Context proof narrows option matching to its exact item clause and preserves priced fish size.
fragmentExpect(Evidence::catalogOptionLabels($data['items'][0],$data,$unnamed,['كامل','مخلي']),null,
    'priced three-fish size cannot be silently dropped while choosing only whole preparation');
fragmentExpect(Evidence::catalogOptionLabels($data['items'][0],$data,$unnamed,['الكيلو 3 حبه','كامل']),null,
    'separate size and preparation options cannot exceed current one-option ERP contract');
fragmentExpect(Evidence::catalogOptionLabels($data['items'][0],$data,$unnamed,['الكيلو 3 حبه كامل']),['الكيلو 3 حبه كامل'],
    'one exact live combined size/preparation label preserves all independently proved priced choices');
fragmentExpect(Evidence::catalogOptionLabels($data['items'][0],$data,$unnamed,['الكيلو 2 حبه كامل']),null,
    'live alternative size cannot replace selected three-fish size');
fragmentExpect(Evidence::catalogOptionLabels($data['items'][0],$data,$unnamed,[]),null,
    'selected priced size cannot fall through to implicit catalog default without live size choice');
fragmentExpect(Evidence::catalogOptionLabels($data['items'][0],$data,$unnamed,['الكيلو 3 حبات كامل']),null,
    'unapproved noun-form alias cannot synthesize a different literal catalog choice');
$missingSelectedSize=$data;$missingSelectedSizeRows=$rows;
$missingSelectedSizeRows[2]['text']='عايز كيلو ونص';
$missingSelectedSizeRows[9]['text']='طلبك: 1.5 كيلو فسيخ كامل؛ 1 كيلو رنجه';
fragmentAssertRejected($missingSelectedSize,$missingSelectedSizeRows,'offer containing two priced sizes requires explicit selected size rather than default inference');
$variantFree=$data;$variantFreeRows=$unnamed;
$variantFreeRows[1]['text']='فسيخ بسعر 300 جنيه. تحب قد إيه؟';
$variantFreeRows[2]['text']='عايز كيلو ونص';
$variantFreeRows[9]['text']='طلبك: 1.5 كيلو كامل؛ 1 كيلو رنجه';
fragmentExpect(fragmentProve($variantFree,$variantFreeRows),['ok'=>true,'reason'=>null],'variant-free option fixture independently proves its selected basket');
fragmentExpect(Evidence::catalogOptionLabels($variantFree['items'][0],$variantFree,$variantFreeRows,['كامل','مخلي']),['كامل'],
    'variant-free unnamed contextual feseekh recap maps its literal live whole option');
fragmentExpect(Evidence::catalogOptionLabels($variantFree['items'][1],$variantFree,$variantFreeRows,['كامل','مخلي']),[],
    'whole option on another recap clause cannot be applied to renga');
fragmentExpect(Evidence::catalogOptionLabels($variantFree['items'][0],$variantFree,$variantFreeRows,['كامل','كامل']),['كامل'],
    'duplicate supplied live label does not invent a second option');
fragmentExpect(Evidence::catalogOptionLabels($variantFree['items'][0],$variantFree,$variantFreeRows,['تغليف مفرغ']),[],
    'uncited live labels are never invented from catalog presence');
foreach(['no-context','absent-context','not-member','invalid-reference','cart-origin','invalid-live-label','too-many-live-labels']as$case) {
    $optionData=$variantFree;$item=$variantFree['items'][0];$labels=['كامل','مخلي'];
    if($case==='no-context') {$item['conversation_reference']=null;$optionData['items'][0]=$item;}
    if($case==='absent-context') {unset($item['conversation_reference']);$optionData['items'][0]=$item;}
    if($case==='not-member')$item['quantity']='0.5';
    if($case==='invalid-reference') {$item['conversation_reference']['business_summary_id']='m60';$optionData['items'][0]=$item;}
    if($case==='cart-origin') {$item['cart_reference']=['message_id'=>'m3','product_retailer_id'=>'opaque-catalog-line'];$optionData['items'][0]=$item;}
    if($case==='invalid-live-label')$labels=['كامل',123];
    if($case==='too-many-live-labels')$labels=array_fill(0,101,'كامل');
    fragmentExpect(Evidence::catalogOptionLabels($item,$optionData,$variantFreeRows,$labels),null,$case.' has no contextual option mapping authority');
}
foreach(['negated','positive-and-negative','alternative','two-positive']as$case) {
    $optionRows=$variantFreeRows;
    $replacement=['negated'=>'بدون كامل','positive-and-negative'=>'كامل بدون كامل',
        'alternative'=>'كامل أو مخلي','two-positive'=>'كامل مخلي'][$case];
    $optionRows[9]['text']=str_replace('كامل',$replacement,$optionRows[9]['text']);
    fragmentExpect(Evidence::catalogOptionLabels($variantFree['items'][0],$variantFree,$optionRows,['كامل','مخلي']),null,
        $case.' contradictory selected recap cannot choose live option');
}
$longChoice=$variantFree;$longChoiceRows=$variantFreeRows;
$longChoice['items'][0]['option_hint']='تنظيف إضافي';
$longChoiceRows[8]['text']='كيلو ونص تنظيف إضافي';
$longChoiceRows[9]['text']=str_replace('كامل','تنظيف إضافي',$longChoiceRows[9]['text']);
fragmentExpect(Evidence::catalogOptionLabels($longChoice['items'][0],$longChoice,$longChoiceRows,['تنظيف','تنظيف إضافي']),['تنظيف إضافي'],
    'longest literal live label preserves specific choice rather than generic substring');
$nameOverlap=$variantFree;$nameOverlapRows=$variantFreeRows;
$nameOverlap['items'][0]['name']='فسيخ كامل';$nameOverlap['items'][0]['option_hint']=null;
$nameOverlapRows[0]['text']='الفسيخ كامل بكام؟';
$nameOverlapRows[1]['text']=str_replace('فسيخ','فسيخ كامل',$nameOverlapRows[1]['text']);
$nameOverlapRows[9]['text']='طلبك: 1.5 كيلو فسيخ كامل؛ 1 كيلو رنجه';
fragmentExpect(fragmentProve($nameOverlap,$nameOverlapRows),['ok'=>true,'reason'=>null],'full product name overlap fixture retains strict contextual proof');
fragmentExpect(Evidence::catalogOptionLabels($nameOverlap['items'][0],$nameOverlap,$nameOverlapRows,['كامل','مخلي']),[],
    'option-like word contained within full product name cannot become a selected live option');
$bareQuantity=$rows;$bareQuantity[6]['text']='كيلو';
fragmentExpect(Extraction::validateDetailed($data,$bareQuantity)['ok'],true,'bare quantity answer to uniquely named short-turn offer requires complete recap acceptance and data proof');
fragmentExpect(Extraction::committingRequestIds($data,$rows),['m3','m7'],'only proven contextual sale-quantity rows count as real commitment');
$implicit=$bareQuantity;$implicit[2]['text']='كيلو ونص من الكيلو 3 حبه';
fragmentExpect(Extraction::validateDetailed($data,$implicit)['ok'],true,'whole basket proof binds terse quantity selections without repeated product names');
fragmentExpect(Extraction::committingRequestIds($data,$implicit),['m3','m7'],'verified terse selections are commitment IDs without name/address fallback');
$schema=Extraction::schema();$referenceSchema=$schema['properties']['items']['items']['properties']['conversation_reference'];
fragmentExpect($referenceSchema['anyOf'][1],['type'=>'null'],'new context reference explicitly nullable');
$referenceSchema=$referenceSchema['anyOf'][0];
fragmentExpect($referenceSchema['additionalProperties'],false,'reference schema denies arbitrary operational fields');
fragmentExpect($referenceSchema['required'],array_keys(fragmentReference('m1','m2','m3',0,'m9')),'reference schema requires complete literal proof shape');
fragmentExpect($referenceSchema['properties']['summary_clause_index'],['type'=>'integer','minimum'=>0,'maximum'=>29],
    'recap clause reference bounded within schema item limit');

foreach(['request-anchor','business-offer','customer-quantity','business-summary','customer-option']as$case) {
    $bad=$data;
    $field=['request-anchor'=>'name_anchor_id','business-offer'=>'business_offer_id','customer-quantity'=>'customer_quantity_id',
        'business-summary'=>'business_summary_id','customer-option'=>'customer_option_id'][$case];
    $bad['items'][0]['conversation_reference'][$field]=in_array($case,['business-offer','business-summary'],true)?'m3':'m2';
    fragmentAssertRejected($bad,$rows,$case.' speaker roles cannot be interchanged');
}
foreach(['missing-label','not-cited-anchor','not-cited-quantity','not-cited-option','not-cited-summary',
    'extra-key','missing-key','negative-clause','clause-too-large','string-clause','missing-clause','wrong-product-clause','cart-and-context']as$case) {
    $bad=$data;
    if($case==='missing-label')$bad['items'][0]['conversation_reference']['name_anchor_id']='m60';
    if($case==='not-cited-anchor')$bad['evidence']['request_ids']=array_values(array_diff($bad['evidence']['request_ids'],['m1']));
    if($case==='not-cited-quantity')$bad['evidence']['request_ids']=array_values(array_diff($bad['evidence']['request_ids'],['m3']));
    if($case==='not-cited-option')$bad['evidence']['request_ids']=array_values(array_diff($bad['evidence']['request_ids'],['m9']));
    if($case==='not-cited-summary')$bad['evidence']['confirmation_ids']=['m16'];
    if($case==='extra-key')$bad['items'][0]['conversation_reference']['branch_id']=301;
    if($case==='missing-key')unset($bad['items'][0]['conversation_reference']['business_offer_id']);
    if($case==='negative-clause')$bad['items'][0]['conversation_reference']['summary_clause_index']=-1;
    if($case==='clause-too-large')$bad['items'][0]['conversation_reference']['summary_clause_index']=30;
    if($case==='string-clause')$bad['items'][0]['conversation_reference']['summary_clause_index']='0';
    if($case==='missing-clause')$bad['items'][0]['conversation_reference']['summary_clause_index']=29;
    if($case==='wrong-product-clause')$bad['items'][0]['conversation_reference']['summary_clause_index']=1;
    if($case==='cart-and-context')$bad['items'][0]['cart_reference']=['message_id'=>'m3','product_retailer_id'=>'opaque-id'];
    fragmentAssertRejected($bad,$rows,$case.' reference scope/shape cannot authorize');
}

// Prices, alternatives and unrelated named topics are never product selections.
foreach(['price-inquiry','unselected-alternative','topic-switch','offer-after-quantity','anchor-after-offer','too-distant-quantity']as$case) {
    $badRows=$rows;$bad=$data;
    if($case==='price-inquiry')$badRows[2]['text']='الكيلو ونص بكام؟';
    if($case==='unselected-alternative')$badRows[2]['text']='عايز كيلو ونص من الكيلو 2 حبه';
    if($case==='topic-switch')$badRows[2]['text']='عايز كيلو ونص رنجه';
    if($case==='offer-after-quantity')$bad['items'][0]['conversation_reference']['business_offer_id']='m8';
    if($case==='anchor-after-offer')$bad['items'][0]['conversation_reference']['name_anchor_id']='m5';
    if($case==='too-distant-quantity') {
        // Bind the old offer to a distant customer message; the intervening topics cannot be ignored.
        $bad['items'][0]['conversation_reference']['customer_quantity_id']='m13';$badRows[12]['text']='عايز كيلو ونص من الكيلو 3 حبه';
        $bad['customer']['name']=null;
    }
    fragmentAssertRejected($bad,$badRows,$case.' cannot create a committing item');
}
foreach(['negative-mesh','negative-la','signed-amount','extra-unbound-fraction']as$case) {
    $negativeRows=$rows;
    $negativeRows[2]['text']=['negative-mesh'=>'مش اعملي كيلو ونص من الكيلو 3 حبه',
        'negative-la'=>'لا اعملي كيلو ونص من الكيلو 3 حبه','signed-amount'=>'عايز -1.5 كيلو من الكيلو 3 حبه',
        'extra-unbound-fraction'=>'عايز كيلو ونص ونص من الكيلو 3 حبه'][$case];
    fragmentAssertRejected($data,$negativeRows,$case.' cannot be normalized into an unqualified positive sale amount');
}
$switchRows=$rows;$switchData=$data;
$switchRows[2]=fragmentRow('m3','customer','في سردين؟',3);
$switchRows[3]=fragmentRow('m4','customer','عايز كيلو ونص من الكيلو 3 حبه',4);
$switchData['items'][0]['conversation_reference']['customer_quantity_id']='m4';
$switchData['evidence']['request_ids']=array_values(array_diff($switchData['evidence']['request_ids'],['m3']));
$switchData['evidence']['request_ids'][]='m4';
fragmentAssertRejected($switchData,$switchRows,'competing named topic between offer and unnamed quantity requires review');
$unknownSwitch=$switchRows;$unknownSwitch[2]['text']='في جمبري؟';
fragmentAssertRejected($switchData,$unknownSwitch,'unselected shrimp inquiry before unnamed quantity must not retain old fish topic');
$unknownWithoutQuestion=$switchRows;$unknownWithoutQuestion[2]['text']='في جمبري';
fragmentAssertRejected($switchData,$unknownWithoutQuestion,'named shrimp inquiry without question punctuation still changes the preceding product topic');

// A later unqualified whole-option choice needs one unique selected sale quantity and a matching recap.
$sameQuantity=$data;$sameRows=$rows;
$sameQuantity['items'][1]['quantity']='1.5';$sameRows[6]['text']='تمام اعملي كيلو ونص';
$sameRows[9]['text']=str_replace('1 كيلو رنجه','1.5 كيلو رنجه',$sameRows[9]['text']);
fragmentAssertRejected($sameQuantity,$sameRows,'two products at equal weight cannot attribute later kilo-and-half whole choice');
$noWhole=$rows;$noWhole[9]['text']=str_replace(' كامل','',$noWhole[9]['text']);
fragmentAssertRejected($data,$noWhole,'later whole choice must be repeated on its selected recap item');
$wrongWhole=$rows;$wrongWhole[8]['text']='كيلو ونص تنظيف';
fragmentAssertRejected($data,$wrongWhole,'different customer option cannot prove whole product');
$changedSize=$rows;$changedSize[9]['text']=str_replace('3 حبه','2 حبه',$changedSize[9]['text']);
fragmentAssertRejected($data,$changedSize,'recap fish-size choice must match explicitly selected size');
$three=$data;$three['items'][0]['quantity']='3';
fragmentAssertRejected($three,$rows,'fish count per kilo cannot replace selected sale weight');
$threePiece=$data;$threePiece['items'][0]['quantity']='3';$threePiece['items'][0]['quantity_mode']='piece';
fragmentAssertRejected($threePiece,$rows,'fish-size count cannot become piece order');
$unclaimedRecap=$rows;$unclaimedRecap[9]['text'].='؛ 0.5 كيلو سردين';
fragmentAssertRejected($data,$unclaimedRecap,'extra unaccounted recap sale line cannot disappear from the accepted basket');
$deliveryRecap=$rows;$deliveryRecap[9]['text'].='؛ مصاريف التوصيل 30 جنيه';
fragmentExpect(Extraction::validateDetailed($data,$deliveryRecap)['ok'],true,'non-sale delivery price hint cannot become an invented extra product');

// Evidence after selection/recap/confirmation stays authoritative: changes and cancellations invalidate.
foreach(['later-change','later-cancel','summary-missing-quantity','no-recap','no-basket-acceptance','early-final-ack',
    'missing-address-ack','unrelated-final-ack','details-after-final-ack','profile-name-fallback']as$case) {
    $badRows=$rows;$bad=$data;
    if($case==='later-change')$badRows[]=fragmentRow('m17','customer','غير الطلب بدل كيلو ونص عايز نص كيلو',17);
    if($case==='later-cancel')$badRows[]=fragmentRow('m17','customer','الغ الطلب مش عايزه',17);
    if($case==='summary-missing-quantity')$badRows[9]['text']='طلبك: فسيخ من الكيلو 3 حبه كامل؛ 1 كيلو رنجه';
    if($case==='no-recap') {$badRows[9]['text']='تم تأكيد الطلب';$bad['evidence']['confirmation_ids']=['m16'];}
    if($case==='no-basket-acceptance') {$bad['evidence']['customer_acceptance_ids']=[];$badRows[10]['text']='شكرا';}
    if($case==='early-final-ack') {$badRows[11]['text']='تم استلام الاسم ورقم الهاتف والعنوان وتأكيد الطلب';$badRows[15]['text']='شكرا';}
    if($case==='missing-address-ack')$badRows[15]['text']='تم استلام الاسم ورقم الهاتف وتأكيد الطلب';
    if($case==='unrelated-final-ack')$badRows[15]['text']='تمام';
    if($case==='details-after-final-ack') {
        $badRows[14]['text']='العنوان هبعته';$badRows[]=fragmentRow('m17','customer',$data['customer']['address'],17);
        $bad['evidence']['request_ids']=array_values(array_diff($bad['evidence']['request_ids'],['m15']));
        $bad['evidence']['request_ids'][]='m17';
    }
    if($case==='profile-name-fallback')$bad['customer']['name']='اسم ملف واتساب غير مذكور';
    fragmentAssertRejected($bad,$badRows,$case.' cannot authorize fragmented confirmation');
}
$addressChanged=$rows;$addressChanged[]=fragmentRow('m17','customer','العنوان الجديد في شارع مختلف',17);
fragmentExpect(Evidence::addressAcknowledged($data,array_column($addressChanged,null,'id')),false,'customer address correction after final data acknowledgment is unconfirmed');
$addressUncited=$data;$addressUncited['evidence']['request_ids']=array_values(array_diff($addressUncited['evidence']['request_ids'],['m15']));
fragmentExpect(Evidence::addressAcknowledged($addressUncited,array_column($rows,null,'id')),false,'uncited textual address cannot be accepted by generic data acknowledgment');
foreach(['expected-phone-other-street','expected-name-other-street']as$case) {
    $residualRows=$rows;
    if($case==='expected-phone-other-street')$residualRows[13]['text']=$data['customer']['phone'].' شارع مختلف';
    else $residualRows[12]['text']=$data['customer']['name'].' شارع مختلف';
    fragmentAssertRejected($data,$residualRows,$case.' cannot hide a conflicting bare address behind another correctly copied customer field');
    fragmentExpect(Evidence::addressAcknowledged($data,$residualRows),false,$case.' customer bundle residual invalidates address acknowledgment');
}
$combinedRows=$rows;$combinedData=$data;
$combinedRows[12]['text']=$data['customer']['name'].' '.$data['customer']['phone'].' '.$data['customer']['address'];
$combinedRows=array_values(array_filter($combinedRows,static fn($row)=>!in_array($row['id'],['m14','m15'],true)));
$combinedData['evidence']['request_ids']=array_values(array_diff($combinedData['evidence']['request_ids'],['m14','m15']));
fragmentExpect(Extraction::validateDetailed($combinedData,$combinedRows)['ok'],true,'one fully literal cited name-phone-address bundle is accepted without inventing separate rows');
fragmentExpect(Evidence::addressAcknowledged($combinedData,$combinedRows),true,'fully grounded combined customer bundle preserves positive acknowledgment');
foreach(['named-quantity','bare-quantity','named-phone','bare-phone']as$case) {
    $lateRows=$rows;
    $text=['named-quantity'=>'عايز 2 كيلو فسيخ','bare-quantity'=>'2 كيلو','named-phone'=>'رقمي 01000000002',
        'bare-phone'=>'01000000002'][$case];
    $lateRows[]=fragmentRow('m17','customer',$text,17);
    fragmentAssertRejected($data,$lateRows,$case.' after final acknowledgment invalidates old basket/customer evidence');
    fragmentExpect(Evidence::addressAcknowledged($data,$lateRows),false,$case.' cannot preserve stale address acknowledgment');
}
foreach(['question','future','negative']as$case) {
    $ackRows=$rows;
    $ackRows[15]['text']=['question'=>'تم تأكيد الطلب: الاسم ورقم الهاتف والعنوان؟',
        'future'=>'تم تأكيد الطلب. سوف نراجع الاسم ورقم الهاتف والعنوان',
        'negative'=>'تم تأكيد الطلب لكن لم نستلم الاسم ورقم الهاتف والعنوان'][$case];
    fragmentAssertRejected($data,$ackRows,$case.' data labels cannot be interpreted as completed positive acknowledgment');
    fragmentExpect(Evidence::addressAcknowledged($data,$ackRows),false,$case.' data labels provide no current address acknowledgment');
}

// Existing literal request+summary contract remains valid without contextual references.
$legacyRows=[fragmentRow('m1','customer','اسمي عميل اختبار ورقمي 01000000001 وعنواني شارع الاختبار في المنصورة. عايز 0.5 كيلو فسيخ بدون شطة.',1),
    fragmentRow('m2','business','تأكيد طلب عميل اختبار: 0.5 كيلو فسيخ بدون شطة إلى شارع الاختبار، الإجمالي 450 جنيه.',2),
    fragmentRow('m3','customer','تمام موافق',3)];
$legacy=['decision'=>'CONFIRMED','customer'=>['name'=>'عميل اختبار','phone'=>'01000000001','address'=>'شارع الاختبار','area'=>'المنصورة','notes'=>'بدون شطة'],
    'branch_hint'=>null,'fulfillment'=>'DELIVERY','items'=>[['name'=>'فسيخ','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>'بدون شطة']],
    'approximate_total'=>'450','evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m2'],'customer_acceptance_ids'=>['m3'],'location_id'=>null],
    'issues'=>['PRICE_ESTIMATE_ONLY']];
fragmentExpect(Extraction::validateDetailed($legacy,$legacyRows)['ok'],true,'legacy four-key item contract preserved');
fragmentExpect(Extraction::committingRequestIds($legacy,$legacyRows),['m1','m3'],'legacy direct request and post-summary acceptance commitment contract retained');
$legacyFive=$legacy;$legacyFive['items'][0]['cart_reference']=null;
fragmentExpect(Extraction::validateDetailed($legacyFive,$legacyRows)['ok'],true,'legacy five-key item contract preserved');
$legacySix=$legacyFive;$legacySix['items'][0]['conversation_reference']=null;
fragmentExpect(Extraction::validateDetailed($legacySix,$legacyRows)['ok'],true,'new nullable reference preserves normal literal order');
$legacyWrong=$legacy;$legacyWrong['items'][0]['quantity']='1';
fragmentAssertRejected($legacyWrong,$legacyRows,'fragment path cannot relax legacy quantity grounding');
$legacyBare=$legacy;$legacyBare['customer']['name']='اسم ملف واتساب غير مذكور';
fragmentAssertRejected($legacyBare,$legacyRows,'literal legacy name check remains authoritative');
fragmentExpect(Extraction::hasProbe($legacyRows),false,'bare customer name containing test is not an operational probe marker');

// Pure parsed quantities separate weights, fish-size specifications and unselected alternative weights.
fragmentExpect(Evidence::quantities('عايز كيلو ونص من الكيلو 3 حبه'),[['quantity'=>1500,'mode'=>'weight']],
    'composite kilo-and-half remains one sale quantity; size suffix excluded');
fragmentExpect(Evidence::quantities('كيلو ونص كامل'),[['quantity'=>1500,'mode'=>'weight']],'unqualified later whole option retains weight');
fragmentExpect(Evidence::quantities('تمام اعملي كيلو'),[['quantity'=>1000,'mode'=>'weight']],'implicit one-kilo request normalized exactly');

// An explicit ORDER test stops processing; a later technical WhatsApp test does not negate a completed order.
$connectionTest=$rows;$connectionTest[]=fragmentRow('m17','customer','دي رسالة اختبار علشان أتأكد إن ربط الواتساب شغال',17);
fragmentExpect(Evidence::operationalTest($connectionTest),false,'connection test alone is not an explicit statement that the earlier order was a test');
fragmentExpect(Extraction::hasProbe($connectionTest),false,'later technical connection test does not poison earlier closed order');
fragmentExpect(Extraction::validateDetailed($data,$connectionTest)['ok'],true,'completed grounded order remains confirmed before technical message');
$operational=$rows;$operational[]=fragmentRow('m17','customer','هذا الطلب اختبار لا ترسله',17);
fragmentExpect(Evidence::operationalTest($operational),true,'explicit statement the order is a test recognized');
fragmentExpect(Extraction::hasProbe($operational),true,'application preflight recognizes explicit order test before AI');
fragmentExpect(Extraction::validateDetailed($data,$operational)['data']['decision'],'NONE','explicit order test cannot be a confirmed real order');
$calls=0;$provider=new Provider(static function($body,$key)use(&$calls,$data):array{$calls++;return fragmentResponse($data);},fragmentConfig());
$testResult=$provider->extract($operational);
fragmentExpect($calls,0,'explicit order test makes no paid provider request');
fragmentExpect($testResult['data']['decision'],'NONE','operational test fails closed to NONE');
fragmentExpect($testResult['data']['issues'],['TEST_MESSAGE'],'operational test has fixed classification');
$ordinary=$rows;$ordinary[12]['text']='عميل اختبار';$ordinaryData=$data;$ordinaryData['customer']['name']='عميل اختبار';
fragmentExpect(Evidence::operationalTest($ordinary),false,'ordinary test-containing customer name not overblocked');
fragmentExpect(Extraction::hasProbe($ordinary),false,'bare name bypasses no order evidence checks but is not probe');
fragmentExpect(Extraction::validateDetailed($ordinaryData,$ordinary)['ok'],true,'test-containing customer name can appear in a real grounded order');
$calls=0;$provider=new Provider(static function($body,$key)use(&$calls,$ordinaryData):array{$calls++;return fragmentResponse($ordinaryData);},fragmentConfig());
$actual=$provider->extract($ordinary);
fragmentExpect($calls,1,'ordinary customer order still makes exactly one provider call');
fragmentExpect($actual['ok'],true,'current provider delegates successful fragmented evidence to authoritative validator');
fragmentExpect($actual['data'],$ordinaryData,'provider retains exact trusted-in-validation data');

// Only an explicit later NEW order establishes a post-operational-test episode; labels and cutoffs stay intact.
function fragmentShifted(array $rows,array $data,int $offset):array {
    $label=static fn(string $id):string=>'m'.((int)substr($id,1)+$offset);
    foreach($rows as&$row) {
        $number=(int)substr($row['id'],1)+$offset;$row['id']=$label($row['id']);
        $row['sent_at']='2026-10-13 12:00:'.sprintf('%02d',$number);
    }unset($row);
    foreach(['request_ids','confirmation_ids','customer_acceptance_ids']as$key)
        $data['evidence'][$key]=array_map($label,$data['evidence'][$key]);
    if($data['evidence']['location_id']!==null)$data['evidence']['location_id']=$label($data['evidence']['location_id']);
    foreach($data['items']as&$item)foreach(['name_anchor_id','business_offer_id','customer_quantity_id','business_summary_id','customer_option_id']as$key)
        if($item['conversation_reference'][$key]!==null)$item['conversation_reference'][$key]=$label($item['conversation_reference'][$key]);
    unset($item);
    return [$rows,$data];
}
[$newRows,$newData]=fragmentShifted(fragmentRows(),fragmentData(),3);
$oldCustomer=fragmentRow('m1','customer','اسمي اسم سابق وعنواني شارع سابق وعايز فسيخ',1);
$oldCustomer['location']=['lat'=>30.1,'long'=>31.2];
$prefix=[$oldCustomer,fragmentRow('m2','customer','هذا الطلب اختبار لا ترسله',2),
    fragmentRow('m3','customer','عايز طلب جديد: كيلو ونص فسيخ كامل وكيلو رنجه',3)];
$newRows=array_merge($prefix,$newRows);
fragmentExpect(Evidence::operationalTest($newRows),false,'later explicit new order qualifies a bounded post-test episode');
fragmentExpect(Extraction::hasProbe($newRows),false,'post-test explicit order is not globally suppressed');
fragmentExpect(Extraction::validateDetailed($newData,$newRows)['ok'],true,'all current references after new-order boundary remain valid');
$calls=0;$sentRows=null;
$provider=new Provider(static function($body,$key)use(&$calls,&$sentRows,$newData):array{
    $calls++;$request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    $sentRows=json_decode($request['input'][0]['content'],true,32,JSON_THROW_ON_ERROR)['transcript'];
    return fragmentResponse($newData);
},fragmentConfig());
$newResult=$provider->extract($newRows);
fragmentExpect($calls,1,'one provider request for bounded explicit post-test order');
fragmentExpect($newResult['ok'],true,'explicit post-test provider order validated');
fragmentExpect($sentRows[0]['id'],'m3','post-test provider input preserves supplied message labels');
fragmentExpect(count($sentRows),17,'provider excludes only earlier operational-test episode');
fragmentExpect(str_contains(json_encode($sentRows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'اسم سابق'),false,
    'provider never receives customer facts from old operational-test episode');
$associative=array_column($newRows,null,'id');$calls=0;
$provider=new Provider(static function($body,$key)use(&$calls,$newData):array{$calls++;return fragmentResponse($newData);},fragmentConfig());
$malformedResult=$provider->extract($associative);
fragmentExpect($calls,0,'original associative transcript rejected before post-test episode filtering and HTTP');
fragmentExpect($malformedResult['ok'],false,'episode filter cannot repair malformed original transcript shape');
fragmentExpect($malformedResult['reason'],'INVALID_INPUT','malformed associative input retains authoritative invalid-input classification');
$codedBeforeTest=$newRows;$codedBeforeTest[0]['text'].=' WA-1234';$calls=0;
$provider=new Provider(static function($body,$key)use(&$calls,$newData):array{$calls++;return fragmentResponse($newData);},fragmentConfig());
$codedBeforeResult=$provider->extract($codedBeforeTest);
fragmentExpect($calls,1,'historical coded marker cleared only by later strict new-order boundary allows one request');
fragmentExpect($codedBeforeResult['data'],$newData,'current isolated evidence remains authoritative after historical coded marker');
fragmentExpect(Evidence::episodeBoundary($codedBeforeTest),'m3','coded and operational predecessors share the same fresh boundary');
foreach(['old-name','old-address','old-location','old-item-anchor','old-offer','old-acceptance']as$case) {
    $bad=$newData;$badRows=$newRows;
    if($case==='old-name') {$bad['customer']['name']='اسم سابق';$bad['evidence']['request_ids'][]='m1';}
    if($case==='old-address') {$bad['customer']['address']='شارع سابق';$bad['evidence']['request_ids'][]='m1';}
    if($case==='old-location')$bad['evidence']['location_id']='m1';
    if($case==='old-item-anchor') {$bad['items'][0]['conversation_reference']['name_anchor_id']='m1';$bad['evidence']['request_ids'][]='m1';}
    if($case==='old-offer') {$badRows[0]['speaker']='business';$badRows[0]['text']='فسيخ من الكيلو 3 حبه بسعر 300 جنيه';
        $bad['items'][0]['conversation_reference']['business_offer_id']='m1';}
    if($case==='old-acceptance')$bad['evidence']['customer_acceptance_ids'][]='m1';
    fragmentAssertRejected($bad,$badRows,$case.' cannot cross explicit new-order evidence boundary');
}
foreach(['question','negative','quote','generic-quantity','bare-test-name']as$case) {
    $noReset=$operational;
    $text=['question'=>'ينفع أعمل طلب جديد؟','negative'=>'مش عايز أعمل طلب جديد','quote'=>'العميل قال: "عايز أعمل طلب جديد"',
        'generic-quantity'=>'اعملي كيلو','bare-test-name'=>'عميل اختبار'][$case];
    $noReset[]=fragmentRow('m18','customer',$text,18);
    fragmentExpect(Evidence::operationalTest($noReset),true,$case.' does not reset operational-test episode');
    fragmentExpect(Extraction::hasProbe($noReset),true,$case.' original operational guard stays active');
    $calls=0;$provider=new Provider(static function($body,$key)use(&$calls,$data):array{$calls++;return fragmentResponse($data);},fragmentConfig());
    fragmentExpect($provider->extract($noReset)['data']['decision'],'NONE',$case.' cannot create a post-test confirmed order');
    fragmentExpect($calls,0,$case.' non-reset makes no paid request');
}
$codedTest=$newRows;$codedTest[3]['text'].=' WA-1234';
fragmentExpect(Extraction::hasProbe($codedTest),true,'coded marker inside current episode still blocks despite earlier new order');
$calls=0;$provider=new Provider(static function($body,$key)use(&$calls,$newData):array{$calls++;return fragmentResponse($newData);},fragmentConfig());
fragmentExpect($provider->extract($codedTest)['data']['decision'],'NONE','legacy coded test cannot be reset by contextual references');
fragmentExpect($calls,0,'legacy coded marker still prevents paid request');
$twoOrders=$newRows;
$twoOrders[]=fragmentRow('m20','customer','عايز طلب جديد كيلو رنجه',20);
$twoOrders[]=fragmentRow('m21','business','تم تأكيد الطلب الجديد: 1 كيلو رنجه',21);
fragmentAssertRejected($newData,$twoOrders,'two independent post-test orders still require review rather than silently picking first');
echo 'WHATSAPP_ORDER_FRAGMENTED_CONVERSATION_CHECKS='.$checks."\n";
