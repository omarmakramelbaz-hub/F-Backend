<?php
// Pure synthetic native rows and actual validators/provider with fake HTTP; no DB or network.
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
use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Support\WhatsAppInboxProtocol as Protocol;
use App\Support\WhatsAppOrderExtraction as Extraction;
$checks = 0;
function codedExpect($actual, $expected, string $label): void {
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException('FAILED: ' . $label);
}
function codedRow(int $number, string $speaker, ?string $text): array {
    return ['id'=>'m'.$number,'speaker'=>$speaker,'sent_at'=>'2026-10-15 00:00:'.sprintf('%02d',$number),
        'text'=>$text,'location'=>null];
}
function codedRows(): array {
    $old=codedRow(1,'customer','اسمي اسم سابق ورقمي 201000000002 وعنواني شارع سابق في المحلة. عايز 1 كيلو رنجة كامل. تمام موافق');
    $old['location']=['lat'=>30.1,'long'=>31.2];
    return [$old,codedRow(2,'business','WA-0121'),codedRow(3,'business','أهلًا'),codedRow(4,'customer',null),
        codedRow(5,'customer','عايز طلب جديد. اسمي عميل حالي ورقمي 201000000001 وعنواني شارع حالي في المنصورة. عايز 0.5 كيلو فسيخ بدون شطة.'),
        codedRow(6,'business','تأكيد طلب عميل حالي: 0.5 كيلو فسيخ بدون شطة من المنصورة إلى شارع حالي، الإجمالي التقريبي 450.50 جنيه.'),
        codedRow(7,'customer','تمام موافق')];
}
function codedData(): array {
    return ['decision'=>'CONFIRMED','customer'=>['name'=>'عميل حالي','phone'=>'201000000001','address'=>'شارع حالي',
        'area'=>'المنصورة','notes'=>'بدون شطة'],'branch_hint'=>'المنصورة','fulfillment'=>'DELIVERY',
        'items'=>[['name'=>'فسيخ','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>'بدون شطة']],
        'approximate_total'=>'450.50','evidence'=>['request_ids'=>['m5'],'confirmation_ids'=>['m6'],
            'customer_acceptance_ids'=>['m7'],'location_id'=>null],'issues'=>['PRICE_ESTIMATE_ONLY']];
}
function codedProvider(array $rows, array $data): array {
    $calls=0;$sent=null;
    $provider=new Provider(static function($body,$key)use(&$calls,&$sent,$data):array {
        $calls++;$request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        $sent=json_decode($request['input'][0]['content'],true,32,JSON_THROW_ON_ERROR)['transcript'];
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant',
            'content'=>[['type'=>'output_text','text'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]]],JSON_THROW_ON_ERROR)];
    },['enabled'=>true,'mode'=>'auto','model'=>'fixture-model','api_key'=>'sk-coded-private-fixture-key']);
    $result=$provider->extract($rows);
    return compact('calls','sent','result');
}
function codedCart(string $text): array {
    return Protocol::cart(['catalog_id'=>'1234567','text'=>$text,'product_items'=>[
        ['product_retailer_id'=>'SKU_OLD','quantity'=>'1','item_price'=>'100','currency'=>'EGP']]]);
}

try {
    $rows=codedRows();$data=codedData();
    codedExpect(Extraction::validateTranscript($rows)['ok'],true,'full native fixture is valid');
    codedExpect(Evidence::operationalTest($rows),false,'business coded marker is not mislabeled an operational test statement');
    codedExpect(Evidence::episodeBoundary($rows),'m5','live-shaped business marker requires later strict customer new order');
    codedExpect(array_column(Evidence::episodeTranscript($rows),'id'),['m5','m6','m7'],'fresh episode retains original canonical labels');
    codedExpect(Extraction::hasProbe($rows),false,'historical coded business row cannot poison explicit fresh episode');
    codedExpect(Extraction::validateDetailed($data,$rows)['ok'],true,'independently grounded fresh current order remains valid');
    $run=codedProvider($rows,$data);
    codedExpect($run['calls'],1,'fresh current order makes exactly one fake provider call');
    codedExpect($run['sent'],array_slice($rows,4),'provider sees only canonical fresh native episode');
    codedExpect($run['result'],Extraction::result(true,null,$data),'fresh extraction remains exact without rewriting basket');
    codedExpect(str_contains(json_encode($run['sent'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'اسم سابق'),false,'old identity never reaches model');

    foreach (['customer-text','business-text','customer-cart','text-and-cart'] as $kind) {
        $case=$rows;
        if ($kind==='customer-text') $case[1]['speaker']='customer';
        if ($kind==='customer-cart') {$case[1]['speaker']='customer';$case[1]['text']=null;$case[1]['cart']=codedCart('wa-1234');}
        if ($kind==='text-and-cart') {$case[1]['speaker']='customer';$case[1]['cart']=codedCart('WA-5678');}
        codedExpect(Extraction::validateTranscript($case)['ok'],true,$kind.' original snapshot valid');
        codedExpect(Evidence::episodeBoundary($case),'m5',$kind.' shares strict boundary rule');
        codedExpect(Extraction::hasProbe($case),false,$kind.' only clears behind genuine new order');
        codedExpect(Extraction::validateDetailed($data,$case)['ok'],true,$kind.' current evidence valid');
    }
    foreach (['new-order-row','current-customer','current-business','current-cart'] as $kind) {
        $case=$rows;
        if ($kind==='new-order-row') $case[4]['text'].=' WA-1234';
        if ($kind==='current-customer') $case[6]['text'].=' WA-1234';
        if ($kind==='current-business') $case[5]['text'].=' WA-1234';
        if ($kind==='current-cart') $case[6]['cart']=codedCart('WA-1234');
        codedExpect(Evidence::episodeBoundary($case),null,$kind.' cannot clear marker with same or earlier new order');
        codedExpect(Extraction::hasProbe($case),true,$kind.' current marker always blocks');
        $run=codedProvider($case,$data);
        codedExpect($run['calls'],0,$kind.' blocks paid provider request');
        codedExpect($run['result']['data'],Extraction::none('TEST_MESSAGE'),$kind.' retains fixed test classification');
    }
    foreach (['أهلًا','تمام','عايز كيلو فسيخ','ينفع أعمل طلب جديد؟','مش عايز أعمل طلب جديد',
        'العميل قال: "عايز أعمل طلب جديد"','عايز طلب جديد؟','عايز طلب جديد اختبار','عايز طلبية جديدة','ابدأ طلب جديد لو سمحت؟'] as $text) {
        $case=array_slice($rows,0,4);$case[]=codedRow(5,'customer',$text);
        codedExpect(Evidence::episodeBoundary($case),null,'non-authoritative lookalike does not clear coded episode');
        codedExpect(Extraction::hasProbe($case),true,'coded guard remains for non-authoritative lookalike');
        codedExpect(codedProvider($case,$data)['calls'],0,'lookalike makes no provider request');
    }
    $businessReset=$rows;$businessReset[4]['speaker']='business';
    codedExpect(Evidence::episodeBoundary($businessReset),null,'business statement cannot begin genuine customer episode');
    codedExpect(Extraction::hasProbe($businessReset),true,'business new-order lookalike leaves marker active');
    $later=$rows;$later[]=codedRow(8,'business','WA-2345');$later[]=codedRow(9,'customer','عايز طلب جديد كيلو رنجة');
    codedExpect(Evidence::episodeBoundary($later),'m9','latest coded marker overrides earlier boundary');
    codedExpect(array_column(Evidence::episodeTranscript($later),'id'),['m9'],'second boundary cannot retain first basket');
    codedExpect(Extraction::hasProbe($later),false,'later genuine order clears only last coded episode');
    codedExpect(Extraction::validateDetailed($data,$later)['ok'],false,'earlier fresh basket cannot cross later boundary');
    $operational=$rows;$operational[1]['speaker']='customer';$operational[1]['text']='هذا الطلب اختبار لا ترسله';
    codedExpect(Evidence::episodeBoundary($operational),'m5','existing operational episode reset is preserved');
    codedExpect(Extraction::hasProbe($operational),false,'existing operational fresh episode remains usable');

    foreach (['name','phone','address','area','notes','branch','item','option','summary','acceptance','location','cart','anchor','offer','quantity','context-summary','context-option'] as $field) {
        $bad=$data;$case=$rows;
        $old=['name'=>'اسم سابق','phone'=>'201000000002','address'=>'شارع سابق','area'=>'المحلة','notes'=>'كامل'];
        if (isset($old[$field])) {$bad['customer'][$field]=$old[$field];$bad['evidence']['request_ids'][]='m1';}
        if ($field==='branch') {$bad['branch_hint']='المحلة';$bad['evidence']['request_ids'][]='m1';}
        if ($field==='item') {$bad['items'][0]['name']='رنجة';$bad['evidence']['request_ids'][]='m1';}
        if ($field==='option') {$bad['items'][0]['option_hint']='كامل';$bad['evidence']['request_ids'][]='m1';}
        if ($field==='summary') {$case[2]['text']='تأكيد الطلب السابق 1 كيلو رنجة';$bad['evidence']['confirmation_ids'][]='m3';}
        if ($field==='acceptance') $bad['evidence']['customer_acceptance_ids'][]='m1';
        if ($field==='location') $bad['evidence']['location_id']='m1';
        if ($field==='cart') {$case[0]['cart']=codedCart('طلب سابق');$bad['items'][0]['cart_reference']=['message_id'=>'m1','product_retailer_id'=>'SKU_OLD'];$bad['evidence']['request_ids'][]='m1';}
        $referenceKeys=['anchor'=>'name_anchor_id','offer'=>'business_offer_id','quantity'=>'customer_quantity_id',
            'context-summary'=>'business_summary_id','context-option'=>'customer_option_id'];
        if (isset($referenceKeys[$field])) {
            $bad['items'][0]['conversation_reference']=['name_anchor_id'=>'m5','business_offer_id'=>'m6','customer_quantity_id'=>'m5',
                'business_summary_id'=>'m6','summary_clause_index'=>0,'customer_option_id'=>'m5'];
            $bad['items'][0]['conversation_reference'][$referenceKeys[$field]]=in_array($field,['offer','context-summary'],true)?'m3':'m1';
        }
        codedExpect(Evidence::evidenceAfterTest($bad,$case),false,$field.' pre-boundary reference rejected by shared evidence fence');
        codedExpect(Extraction::validateDetailed($bad,$case)['ok'],false,$field.' cannot become current authoritative extraction');
    }
    foreach (['duplicate','extra-key','invalid-location','associative','invalid-old-cart'] as $kind) {
        $case=$rows;
        if ($kind==='duplicate') $case[2]['id']='m2';
        if ($kind==='extra-key') $case[0]['unexpected']='bad';
        if ($kind==='invalid-location') $case[0]['location']=['lat'=>999,'long'=>31.2];
        if ($kind==='associative') $case=array_column($case,null,'id');
        if ($kind==='invalid-old-cart') {$case[1]['cart']=codedCart('WA-1234');}
        codedExpect(Extraction::validateTranscript($case)['ok'],false,$kind.' original snapshot is invalid');
        codedExpect(Evidence::episodeBoundary($case),null,$kind.' cannot produce public native episode boundary');
        codedExpect(codedProvider($case,$data)['calls'],0,$kind.' cannot be repaired by filtering old rows');
    }
    $noSummary=array_slice($rows,0,5);$unconfirmed=$data;$unconfirmed['evidence']['confirmation_ids']=[];$unconfirmed['evidence']['customer_acceptance_ids']=[];
    codedExpect(Extraction::validateDetailed($unconfirmed,$noSummary)['ok'],false,'new-order boundary does not manufacture confirmation or acceptance');
    $draft=Extraction::none('UNCONFIRMED');$draft['decision']='DRAFT';
    codedExpect(Extraction::validateDetailed($draft,$noSummary)['data']['decision'],'DRAFT','fresh boundary never upgrades draft into confirmed order');
    echo 'WHATSAPP_ORDER_CODED_PROBE_EPISODE_CHECKS='.$checks."\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n");
    echo "WHATSAPP_ORDER_CODED_PROBE_EPISODE_TEST_FAILED\n";
    exit(1);
}
