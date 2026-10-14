<?php

// Actual provider with private synthetic HTTP fixtures. No network, credentials or ERP writes.
require dirname(__DIR__) . '/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__) . '/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require dirname(__DIR__) . '/app/Services/Dashboard/WhatsAppOrderAiProvider.php';

use App\Services\Dashboard\WhatsAppOrderAiProvider as Provider;
use App\Support\WhatsAppOrderExtraction as Extraction;

$checks = 0;
function fulfillmentExpect($actual, $expected, string $label): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException($label);
}
function fulfillmentRows(string $mode = 'شكرا'): array
{
    $texts = [
        ['customer', 'عايز 0.5 كيلو فسيخ'],
        ['customer', 'اسمي عميل مثال ورقمي 201000000001'],
        ['customer', 'عنواني شارع المثال في مدينة المثال'],
        ['customer', $mode],
        ['business', 'تأكيد طلب عميل مثال: 0.5 كيلو فسيخ إلى شارع المثال في مدينة المثال.'],
        ['customer', 'تمام موافق'],
    ];
    $rows = [];
    foreach ($texts as $index => [$speaker, $text]) $rows[] = ['id'=>'m'.($index + 1), 'speaker'=>$speaker,
        'sent_at'=>'2026-10-10 10:00:'.str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT),
        'text'=>$text, 'location'=>null];
    return $rows;
}
function fulfillmentData(): array
{
    return ['decision'=>'CONFIRMED', 'customer'=>['name'=>'عميل مثال', 'phone'=>'201000000001',
        'address'=>'شارع المثال', 'area'=>'مدينة المثال', 'notes'=>null], 'branch_hint'=>null,
        'fulfillment'=>'UNKNOWN', 'items'=>[['name'=>'فسيخ', 'quantity'=>'0.5', 'quantity_mode'=>'weight',
            'option_hint'=>null, 'cart_reference'=>null]], 'approximate_total'=>null,
        'evidence'=>['request_ids'=>['m1','m2','m3'], 'confirmation_ids'=>['m5'],
            'customer_acceptance_ids'=>['m6'], 'location_id'=>null], 'issues'=>['MISSING_BRANCH']];
}
function fulfillmentRun(array $rows, array $data, ?int &$calls = null): array
{
    $calls = 0;
    $provider = new Provider(static function ($body) use ($data, &$calls): array {
        $calls++;
        $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        fulfillmentExpect(strpos($request['instructions'], "The owner's fulfillment rule") !== false, true,
            'approved rule is supplied to model');
        fulfillmentExpect($request['store'], false, 'private extraction remains unstored');
        fulfillmentExpect(isset($request['tools']), false, 'classification cannot dispatch or call tools');
        return ['status'=>200, 'body'=>json_encode(['status'=>'completed', 'output'=>[
            ['type'=>'message','role'=>'assistant','content'=>[
                ['type'=>'output_text','text'=>json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]
        ]], JSON_THROW_ON_ERROR)];
    }, ['enabled'=>true, 'model'=>'fixture-model', 'api_key'=>'sk-synthetic-fulfillment-fixture']);
    return $provider->extract($rows);
}
function fulfillmentClassifies(array $rows, array $data, string $mode, string $label): void
{
    fulfillmentExpect(Extraction::validateDetailed($data, $rows)['ok'], true, $label.' fixture independently validates');
    $expected = $data; $expected['fulfillment'] = $mode;
    $result = fulfillmentRun($rows, $data, $calls);
    fulfillmentExpect($result, Extraction::result(true, null, $expected), $label.' only fulfillment changes');
    fulfillmentExpect($calls, 1, $label.' spends exactly one extraction attempt');
    fulfillmentExpect(Extraction::validateDetailed($result['data'], $rows)['ok'], true,
        $label.' classification retains original semantic proof');
}

try {
    $rows = fulfillmentRows(); $data = fulfillmentData();
    fulfillmentClassifies($rows, $data, 'DELIVERY', 'separate grounded phone and address');
    $short = $rows; $short[2]['text'] = 'مدينة المثال';
    $shortData = $data; $shortData['customer']['address'] = 'مدينة المثال';
    fulfillmentClassifies($short, $shortData, 'DELIVERY', 'literal short supplied address remains separate from dispatch readiness');
    $arabic = $rows; $arabic[1]['text'] = 'اسمي عميل مثال ورقمي ٠١٠٠٠٠٠٠٠٠١';
    fulfillmentClassifies($arabic, $data, 'DELIVERY', 'Arabic callback digits match canonical national phone');
    $formatted = $rows; $formatted[1]['text'] = 'اسمي عميل مثال ورقمي +20 100 000 0001';
    fulfillmentClassifies($formatted, $data, 'DELIVERY', 'formatted callback phone');
    foreach (['phone', 'address'] as $field) {
        $missing = $data; $missing['customer'][$field] = null;
        fulfillmentClassifies($rows, $missing, 'UNKNOWN', 'missing '.$field);
    }
    $areaOnly = $data; $areaOnly['customer']['address'] = null;
    fulfillmentClassifies($rows, $areaOnly, 'UNKNOWN', 'area does not synthesize address');
    $business = $rows; $business[1]['text'] = 'اسمي عميل مثال'; $business[2]['text'] = 'مدينة المثال';
    $business[4]['text'] .= ' رقم التواصل 201000000001';
    fulfillmentClassifies($business, $data, 'UNKNOWN', 'business-only phone and address never count as customer supplied');

    foreach (['الأوردر هايكون في الصالة', 'الطلب هيكون في الصاله', 'عايزه تيك أواي', 'عاوز الطلب تيك اواي',
        'خليه تيك اواى', 'هستلمه من الفرع', 'هستلم الأوردر من الفرع', 'استلام من الفرع',
        'تيك أواي', 'في الصالة', 'takeaway', 'dine-in', 'I want this order for takeaway',
        'the order will be dine-in', 'I will collect from the branch', 'مش دليفري، عايزه تيك أواي',
        'عايزه تيك أواي لو سمحت', 'تمام الأوردر هايكون في الصالة في مدينة المثال',
        'عايزه تيك أواي مش دليفري', 'هستلمه من الفرع مش توصيل', 'هستلمه من الفرع الساعة ٧',
        'هستلمه من فرع المثال', 'هعدي استلم الطلب', 'هاجي اخده من الفرع', 'خليه تيك أواي بدون توصيل',
        'عايز كيلو فسيخ تيك أواي', 'اسمي عميل مثال والطلب هايكون في الصالة',
        'عايز تيك أواي بدون شطة', 'الأوردر في الصالة بدون عيش', 'هستلمه من الفرع مش هتاخر',
        'انا هآكل في الصالة'] as $choice) {
        foreach (['UNKNOWN', 'DELIVERY'] as $before) {
            $branch = $data; $branch['fulfillment'] = $before;
            // The mode row deliberately is not cited: omitted choices still override routing.
            fulfillmentClassifies(fulfillmentRows($choice), $branch, 'PICKUP', 'explicit branch choice '.$choice.' from '.$before);
        }
    }
    foreach (['ينفع تيك أواي؟', 'هل الطلب في الصالة', 'ممكن الطلب تيك أواي', 'لو الطلب تيك أواي',
        'مش تيك أواي', 'الطلب مش في الصالة', 'عايزه مش تيك أواي', 'العميل قال الأوردر هايكون في الصالة',
        '"الأوردر هايكون في الصالة"', "'الأوردر هايكون في الصالة'", '"مثال، الطلب تيك أواي، نهاية المثال"',
        '«مثال، الطلب تيك أواي، نهاية المثال»', 'عنواني جنب الصالة',
        'شارع تيك أواي', 'دليفري للعنوان اللي في الصالة', 'Can the order be takeaway?',
        'If the order is dine-in', 'not takeaway', 'مش هآكل في الصالة', 'عايز فسيخ مش هآكل في الصالة'] as $mention) {
        fulfillmentClassifies(fulfillmentRows($mention), $data, 'DELIVERY', 'nonselection '.$mention);
    }
    $conflict = fulfillmentRows('الطلب دليفري، الطلب تيك أواي');
    fulfillmentClassifies($conflict, $data, 'UNKNOWN', 'contradictory explicit choices hold review');
    foreach (['مش دليفري', 'مش عايز توصيل', 'الطلب مش دليفري', 'لا أريد توصيل', 'بدون توصيل',
        'No delivery', 'I do not want delivery', "I don't want delivery", 'متبعتش دليفري',
        'الأوردر مش هايبقى دليفري', 'لا توصل الطلب', 'Delivery is not needed', 'متوصلش الطلب',
        'متبعتش الطلب', 'بلاش دليفري', 'مش محتاج توصيل', 'مش لازم توصيل', 'الدليفري مش مطلوب',
        "don't deliver the order"] as $refusal) {
        foreach ([$refusal=>'UNKNOWN', $refusal.'، عايزه تيك أواي'=>'PICKUP'] as $text => $expected) {
            $refusalRows = fulfillmentRows($text);
            if (Extraction::validateDetailed($data, $refusalRows)['ok'])
                fulfillmentClassifies($refusalRows, $data, $expected, 'explicit delivery refusal '.$text);
            else {
                $failed = fulfillmentRun($refusalRows, $data);
                fulfillmentExpect([$failed['ok'], $failed['reason'], $failed['data']], [false, 'INVALID_EVIDENCE', null],
                    'original negated-order rejection retains review '.$text);
            }
        }
    }
    foreach (['عايزه تيك أواي أو دليفري', 'الطلب دليفري ولا تيك أواي', 'I want takeaway or delivery'] as $alternative)
        fulfillmentClassifies(fulfillmentRows($alternative), $data, 'UNKNOWN', 'alternative choice holds review '.$alternative);
    $newlineConflict = fulfillmentRows("الطلب دليفري\nالطلب تيك أواي");
    $alreadyDelivery = $data; $alreadyDelivery['fulfillment'] = 'DELIVERY';
    fulfillmentClassifies($newlineConflict, $alreadyDelivery, 'UNKNOWN', 'newline choices cannot hide mode conflict');
    $later = $rows; $later[] = ['id'=>'m7', 'speaker'=>'customer', 'sent_at'=>'2026-10-10 10:00:07',
        'text'=>'الطلب تيك أواي', 'location'=>null];
    fulfillmentClassifies($later, $alreadyDelivery, 'UNKNOWN', 'later uncited choice cannot silently reroute confirmed order');
    $later[6]['text'] = 'هستلمه من الفرع';
    fulfillmentClassifies($later, $alreadyDelivery, 'UNKNOWN', 'later collection retains confirmation review');
    $pickup = $data; $pickup['fulfillment'] = 'PICKUP';
    fulfillmentClassifies($rows, $pickup, 'DELIVERY', 'spurious model pickup cannot override grounded contact rule');
    $cartRows = $rows; $cartRows[0]['text'] = null;
    $cartRows[0]['cart'] = \App\Support\WhatsAppInboxProtocol::cart(['catalog_id'=>'1234567',
        'text'=>'عايزه تيك أواي', 'product_items'=>[['product_retailer_id'=>'SKU_FIXTURE', 'quantity'=>'1',
            'item_price'=>'100.00', 'currency'=>'EGP']]]);
    $cartData = $data; $cartData['items'][0]['cart_reference'] = ['message_id'=>'m1','product_retailer_id'=>'SKU_FIXTURE'];
    fulfillmentClassifies($cartRows, $cartData, 'PICKUP', 'actual cart-only branch choice has same priority as native text');
    $shifted = $rows; $shiftedData = $data;
    foreach ($shifted as &$row) {
        $number = (int)substr($row['id'], 1) + 3; $row['id'] = 'm'.$number;
        $row['sent_at'] = '2026-10-10 10:00:'.str_pad((string)$number, 2, '0', STR_PAD_LEFT);
    } unset($row);
    foreach (['request_ids','confirmation_ids','customer_acceptance_ids'] as $key)
        $shiftedData['evidence'][$key] = array_map(static fn ($id) => 'm'.((int)substr($id, 1) + 3), $data['evidence'][$key]);
    $prefix = [['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-10 10:00:01','text'=>'الطلب تيك أواي','location'=>null],
        ['id'=>'m2','speaker'=>'customer','sent_at'=>'2026-10-10 10:00:02','text'=>'هذا الطلب اختبار لا ترسله','location'=>null],
        ['id'=>'m3','speaker'=>'customer','sent_at'=>'2026-10-10 10:00:03','text'=>'عايز طلب جديد: 0.5 كيلو فسيخ','location'=>null]];
    fulfillmentClassifies(array_merge($prefix, $shifted), $shiftedData, 'DELIVERY',
        'old branch choice before proven test/new-order boundary cannot poison current episode');
    foreach (['DRAFT', 'NONE', 'CANCELLED'] as $decision) {
        $notConfirmed = $data; $notConfirmed['decision'] = $decision;
        fulfillmentClassifies($rows, $notConfirmed, 'UNKNOWN', $decision.' cannot promote');
    }
    foreach (['CONFLICTING_DETAILS', 'TEST_MESSAGE', 'AMBIGUOUS_ITEM', 'UNCONFIRMED', 'PROMPT_INJECTION',
        'CART_ITEM_MAPPING_REQUIRED', 'MISSING_CUSTOMER_NAME'] as $issue) {
        $unsafe = $data; $unsafe['issues'][] = $issue;
        fulfillmentClassifies($rows, $unsafe, 'UNKNOWN', $issue.' cannot promote');
    }
    $uncited = $data; $uncited['evidence']['request_ids'] = ['m1'];
    $failed = fulfillmentRun($rows, $uncited);
    fulfillmentExpect([$failed['ok'], $failed['reason'], $failed['data']], [false, 'INVALID_EVIDENCE', null],
        'missing field citations are rejected before classification');
    $wrongPhone = $data; $wrongPhone['customer']['phone'] = '201000000009';
    $failed = fulfillmentRun($rows, $wrongPhone);
    fulfillmentExpect([$failed['ok'], $failed['reason'], $failed['data']], [false, 'INVALID_EVIDENCE', null],
        'ungrounded contact cannot be repaired into delivery');
    $cancelled = $rows; $cancelled[] = ['id'=>'m7', 'speaker'=>'customer', 'sent_at'=>'2026-10-10 10:00:07',
        'text'=>'مش عايز الطلب', 'location'=>null];
    fulfillmentExpect(fulfillmentRun($cancelled, $data)['ok'], false, 'later cancellation retains original rejection');
    $probe = fulfillmentRows('WA-1234');
    $result = fulfillmentRun($probe, $data, $calls);
    fulfillmentExpect($result, Extraction::result(true, null, Extraction::none('TEST_MESSAGE')), 'test marker stays NONE');
    fulfillmentExpect($calls, 0, 'test marker never spends AI or classifies');
    // Neither the pure provider nor this policy invokes ticket/customer/branch services.
    fulfillmentExpect(class_exists('App\\Services\\Dashboard\\PosServiceTicket', false), false, 'no ERP service loaded');
    echo 'WHATSAPP_ORDER_FULFILLMENT_POLICY_TESTS='.$checks." PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WHATSAPP_ORDER_FULFILLMENT_POLICY_TESTS_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
