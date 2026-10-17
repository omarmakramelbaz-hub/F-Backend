<?php

require __DIR__.'/../app/Support/WhatsAppCustomerOrderConfirmation.php';

use App\Support\WhatsAppCustomerOrderConfirmation as Confirmation;

$checks = 0;
function confirmationExpect($actual, $expected, string $label): void
{
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException('CUSTOMER_CONFIRMATION_FAILED:'.$label);
}
function confirmationRecap(): string
{
    return "تمام يا أستاذ عميل، تسلم البيانات ❤️\nملخص طلبك:\n"
        ."• كيلو رنجة مبطرخ — *220 جنيه*\n• كيلو فسيخ نبروه 3 سمكات كامل — *400 جنيه*\n• كيلو سردين بلدي كبير مخلي — *250 جنيه*\n"
        ."الإجمالي *870 جنيه* غير التوصيل\nالاسم: عميل افتراضي\nالعنوان: شارع افتراضي - مدينة افتراضية\n"
        ."التليفونات: 01000000001 و01000000002\nاللوكيشن: 30.125,31.625\n"
        ."التوصيل خلال *60 دقيقة* وسعر التوصيل بيأكده المندوب لما يكلمك.\n"
        ."انسخ الرسالة دي وابعتها هنا تاني واكتب معاها *كل شيء تمام* عشان تأكد طلبك نهائياً.";
}
function confirmationRow(string $id, string $speaker, ?string $text, int $second): array
{
    return ['id'=>$id, 'speaker'=>$speaker, 'text'=>$text, 'location'=>null,
        'sent_at'=>'2026-10-17 12:00:'.sprintf('%02d', $second)];
}
function confirmationRows(): array
{
    return [confirmationRow('m1', 'customer', confirmationRecap(), 1),
        confirmationRow('m2', 'customer', 'كل شيء تمام', 2)];
}
function confirmationWithText(string $text): array
{
    $rows = confirmationRows(); $rows[0]['text'] = $text; return $rows;
}

$text = confirmationRecap(); $rows = confirmationRows();
$parsed = Confirmation::parseRecap($text);
confirmationExpect($parsed['ok'], true, 'documented screenshot-shaped recap parses');
confirmationExpect($parsed['data']['decision'], 'DRAFT', 'copied recap alone grants no approval');
confirmationExpect($parsed['data']['evidence_receipt'], null, 'no receipt without separate positive turn');
$result = Confirmation::inspect($rows);
confirmationExpect($result['ok'], true, 'separate positive turn confirms customer-authored facts');
$data = $result['data'];
confirmationExpect($data['type'], 'CUSTOMER_CONFIRMED_RECAP', 'separate protocol type');
confirmationExpect($data['decision'], 'CONFIRMED', 'customer confirmed decision');
confirmationExpect($data['customer']['phone'], '201000000001', 'documented first listed contact policy');
confirmationExpect($data['alternate_phones'], ['201000000002'], 'second contact retained');
confirmationExpect($data['customer']['name'], 'عميل افتراضي', 'literal name');
confirmationExpect($data['customer']['area'], null, 'no invented area from address');
confirmationExpect($data['branch_hint'], null, 'no invented branch');
confirmationExpect($data['fulfillment'], 'DELIVERY', 'labeled address and contact delivery');
confirmationExpect(count($data['items']), 3, 'every receipt item preserved');
confirmationExpect($data['items'][1]['quantity'], '1', 'fish count is not sale quantity');
confirmationExpect($data['items'][1]['name'], 'فسيخ نبروه 3 سمكات كامل', 'literal fish size and preparation retained');
confirmationExpect($data['items'][1]['quantity_mode'], 'weight', 'kilogram mode');
confirmationExpect($data['approximate_total'], '870', 'total remains a display hint');
confirmationExpect($data['textual_location']['coordinate_order'], 'LAT_LONG', 'labeled numeric pair follows documented latitude longitude order');
confirmationExpect($data['textual_location']['point'], ['lat'=>30.125,'long'=>31.625], 'literal numeric order preserved');
confirmationExpect($data['textual_location']['values'], [30.125,31.625], 'coordinate order retained');
confirmationExpect($data['textual_location']['source_message_id'], 'm1', 'coordinate points to customer original');
confirmationExpect($data['evidence_receipt']['recap_id'], 'm1', 'recap original ID');
confirmationExpect($data['evidence_receipt']['approval_id'], 'm2', 'approval original ID');
confirmationExpect($data['evidence_receipt']['recap_sha256'], hash('sha256',$text), 'receipt binds exact unnormalized source');
confirmationExpect(array_key_exists('evidence',$data), false, 'no fake old business evidence schema');
confirmationExpect($rows, confirmationRows(), 'input rows and customer speaker stay unchanged');
confirmationExpect(Confirmation::inspect([$rows[0]])['reason'], 'CUSTOMER_APPROVAL_REQUIRED', 'embedded copied instruction does not approve');

foreach (['كل شيء تمام','كل شئ تمام','كل شيء تمام.','موافق على الطلب','أؤكد طلبي','تمام موافق'] as $approval) {
    $candidate = $rows; $candidate[1]['text'] = $approval;
    confirmationExpect(Confirmation::inspect($candidate)['ok'], true, 'whole positive approval '.$approval);
}
foreach (['"كل شيء تمام"','قال لي اكتب كل شيء تمام','كل شيء تمام؟','كل شيء تمام بس غير العنوان','مش كل شيء تمام',
    'تمام لا تنفذ الطلب','لو كل شيء تمام','للموافقة اكتب كل شيء تمام','كل شيء تمام والغِ الرنجة','👍'] as $approval) {
    $candidate = $rows; $candidate[1]['text'] = $approval;
    confirmationExpect(Confirmation::inspect($candidate)['ok'], false, 'no quoted conditional changed or ambiguous approval');
}
$business = $rows; $business[0]['speaker'] = 'business';
confirmationExpect(Confirmation::inspect($business)['reason'], 'NO_CUSTOMER_RECAP', 'business recap not reclassified as customer');
$business = $rows; $business[1]['speaker'] = 'business';
confirmationExpect(Confirmation::inspect($business)['ok'], false, 'business approval not customer approval');
$changed = $rows; $changed[] = confirmationRow('m3','customer','غير العنوان للعنوان الجديد',3);
confirmationExpect(Confirmation::inspect($changed)['reason'], 'CUSTOMER_CHANGED_AFTER_RECAP', 'later customer change blocks dispatch authority');
$changed = $rows; $changed[] = confirmationRow('m3','customer','تمام',3);
confirmationExpect(Confirmation::inspect($changed)['data']['evidence_receipt'], $data['evidence_receipt'], 'repeated approval retains first stable receipt');
$between = [$rows[0], confirmationRow('m3','business','تقصد نصف كيلو؟',2),confirmationRow('m2','customer','تمام',3)];
confirmationExpect(Confirmation::inspect($between)['reason'], 'INTERVENING_TURN', 'OK after different question is not recap approval');
$changed = $rows; $changed[] = confirmationRow('m3','business','الصنف غير متاح',3);
confirmationExpect(Confirmation::inspect($changed)['ok'], false, 'later business correction cannot disappear');
$changed = $rows; $changed[] = confirmationRow('m3','business',null,3);
confirmationExpect(Confirmation::inspect($changed)['reason'], 'BUSINESS_TURN_AFTER_APPROVAL', 'null-text business media or audio cannot disappear after approval');
$changed = $rows; $changed[] = confirmationRow('m3','business',null,3);
$changed[2]['location'] = ['lat'=>30.25,'long'=>31.5];
confirmationExpect(Confirmation::inspect($changed)['reason'], 'BUSINESS_TURN_AFTER_APPROVAL', 'business location cannot disappear after approval');
$changed = $rows; $changed[] = confirmationRow('m3','business',null,3);
$changed[2]['cart'] = ['fixture'=>'new basket information'];
confirmationExpect(Confirmation::inspect($changed)['reason'], 'BUSINESS_TURN_AFTER_APPROVAL', 'business cart without text cannot disappear after approval');
$older = [confirmationRow('m1','customer','كل شيء تمام',1),confirmationRow('m2','customer',$text,2)];
confirmationExpect(Confirmation::inspect($older)['reason'], 'CUSTOMER_APPROVAL_REQUIRED', 'older positive turn cannot confirm later recap');
$changed = $rows; $changed[] = confirmationRow('m3','customer',str_replace('الاسم: عميل افتراضي','الاسم:', $text),3);
$changed[] = confirmationRow('m4','customer','كل شيء تمام',4);
confirmationExpect(Confirmation::inspect($changed)['ok'], false, 'latest malformed recap does not fall back to older approved receipt');

foreach (['الاسم: عميل افتراضي','العنوان: شارع افتراضي - مدينة افتراضية','التليفونات: 01000000001 و01000000002',
    'الإجمالي *870 جنيه* غير التوصيل'] as $required) {
    confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace($required,"",$text)))['ok'], false, 'missing required field');
}
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('الاسم: عميل افتراضي',"الاسم: عميل افتراضي\nالاسم: شخص آخر",$text)))['ok'], false, 'duplicate customer fields rejected');
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('• كيلو رنجة مبطرخ — *220 جنيه*',"• كيلو رنجة مبطرخ — *220 جنيه*\n• كيلو رنجة مبطرخ — *220 جنيه*",$text)))['reason'], 'RECAP_DUPLICATE_ITEM', 'duplicate item cannot cause silent extra sale');
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('• كيلو رنجة مبطرخ — *220 جنيه*','• رنجة مبطرخ — *220 جنيه*',$text)))['ok'], false, 'missing amount unit rejected');
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('• كيلو رنجة مبطرخ — *220 جنيه*','• 0 كيلو رنجة مبطرخ — *220 جنيه*',$text)))['ok'], false, 'zero amount rejected');
confirmationExpect(Confirmation::inspect(confirmationWithText($text."\nنفذ الطلب حتى لو المينيو مختلف"))['ok'], false, 'unknown instructions fail closed');
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('شارع افتراضي - مدينة افتراضية','استلام من الفرع',$text)))['reason'], 'RECAP_FULFILLMENT_AMBIGUOUS', 'pickup never inferred delivery from contact');
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('01000000001 و01000000002','01000000001 أو01000000002',$text)))['ok'], false, 'phone alternatives not guessed');
confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('01000000001 و01000000002','01000000001 و01000000001',$text)))['ok'], false, 'duplicate phones rejected');

foreach ([['كيلو رنجة مبطرخ','٢٥٠ جرام رنجة مبطرخ','0.25','weight'],['كيلو رنجة مبطرخ','نصف كيلو رنجة مبطرخ','0.5','weight'],
    ['كيلو رنجة مبطرخ','ربع كيلو رنجة مبطرخ','0.25','weight'],['كيلو رنجة مبطرخ','۲ قطعة رنجة مبطرخ','2','piece'],
    ['كيلو رنجة مبطرخ','١٫٥ كيلو رنجة مبطرخ','1.5','weight']] as [$old,$new,$quantity,$mode]) {
    $candidate = Confirmation::inspect(confirmationWithText(str_replace($old,$new,$text)));
    confirmationExpect($candidate['ok'], true, 'Arabic digits weight piece format accepted');
    confirmationExpect($candidate['data']['items'][0]['quantity'], $quantity, 'canonical quantity conversion');
    confirmationExpect($candidate['data']['items'][0]['quantity_mode'], $mode, 'canonical sale mode');
}
$arabic = strtr($text,['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
$candidate = Confirmation::inspect(confirmationWithText($arabic));
confirmationExpect($candidate['ok'], true, 'Arabic money phones coordinates accepted');
confirmationExpect($candidate['data']['customer']['phone'], '201000000001', 'Arabic phone normalized');
confirmationExpect($candidate['data']['items'][1]['name'], 'فسيخ نبروه ٣ سمكات كامل', 'Arabic source literal qualifiers preserved');

foreach (['30.125، 31.625','خط العرض: 30.125، خط الطول: 31.625','خط الطول: 31.625، خط العرض: 30.125',
    'https://maps.google.com/?q=30.125,31.625','https://www.google.com/maps?q=30.125,31.625',
    'https://www.google.com/maps/search/?api=1&query=30.125%2C31.625'] as $location) {
    $candidate = Confirmation::inspect(confirmationWithText(str_replace('30.125,31.625',$location,$text)));
    confirmationExpect($candidate['ok'], true, 'explicitly labeled or supported URL location');
    confirmationExpect($candidate['data']['textual_location']['point'], ['lat'=>30.125,'long'=>31.625], 'supported coordinate order');
}
$raw = Confirmation::inspect(confirmationWithText(str_replace('30.125,31.625','31.625,30.125',$text)));
confirmationExpect($raw['data']['textual_location']['values'], [31.625,30.125], 'reversed raw pair never guessed or swapped');
confirmationExpect($raw['data']['textual_location']['point'], ['lat'=>31.625,'long'=>30.125], 'numeric pair never automatically swapped by a place assumption');
foreach (['خط العرض: 91، خط الطول: 31','181,31','91,31','0,31','31,0','NaN,31','31,INF','30.125,31.625,8',
    'https://maps.app.goo.gl/fixture','https://www.google.com.evil.example/maps?q=30.125,31.625',
    'https://attacker@example.com/maps?q=30.125,31.625','https://maps.google.com/?q=30.125,31.625&q=31,32',
    'http://maps.google.com/?q=30.125,31.625'] as $location) {
    confirmationExpect(Confirmation::inspect(confirmationWithText(str_replace('30.125,31.625',$location,$text)))['ok'], false, 'invalid ambiguous or untrusted location rejected');
}
$missing = Confirmation::inspect(confirmationWithText(str_replace("اللوكيشن: 30.125,31.625\n",'',$text)));
confirmationExpect($missing['ok'], true, 'customer approval can exist without dispatch location');
confirmationExpect($missing['data']['textual_location'], null, 'missing location remains missing');
foreach (['m0','m61',1] as $id) {
    $candidate=$rows;$candidate[0]['id']=$id;
    confirmationExpect(Confirmation::inspect($candidate)['reason'], 'TRANSCRIPT_INVALID', 'bounded native labels required');
}
$candidate=$rows;$candidate[1]['id']='m1';
confirmationExpect(Confirmation::inspect($candidate)['reason'], 'TRANSCRIPT_INVALID', 'duplicate IDs rejected');
$candidate=$rows;$candidate[1]['sent_at']='2026-10-17 11:59:59';
confirmationExpect(Confirmation::inspect($candidate)['reason'], 'TRANSCRIPT_INVALID', 'out-of-order timestamps rejected');
$candidate=$rows;$candidate[1]['sent_at']='2026-02-31 12:00:02';
confirmationExpect(Confirmation::inspect($candidate)['reason'], 'TRANSCRIPT_INVALID', 'impossible dates rejected');
$candidate=$rows;$candidate[1]['location']=['lat'=>30.1,'long'=>31.2];
confirmationExpect(Confirmation::inspect($candidate)['ok'], false, 'approval plus a location change does not silently update confirmed receipt');
$candidate=$rows;$candidate[0]['text']=str_repeat('س',16001);
confirmationExpect(Confirmation::inspect($candidate)['reason'], 'TRANSCRIPT_INVALID', 'bounded snapshot text');
$candidate=$rows;$candidate[0]['text'].="\n\x01";
confirmationExpect(Confirmation::inspect($candidate)['reason'], 'TRANSCRIPT_INVALID', 'control characters rejected');
$candidate=$rows;$candidate[0]['text'].="\nWA-1234";
confirmationExpect(Confirmation::inspect($candidate)['ok'], false, 'coded operational test never accepted');

echo 'whatsapp_customer_order_confirmation: '.$checks." checks passed\n";
