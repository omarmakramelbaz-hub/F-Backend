<?php
// Exercise the optional blocker observer through the real read-only CLI with
// the actual pinned parser. Reuse only the existing synthetic fixture setup.
$formatHarness=file_get_contents(__DIR__.'/whatsapp_customer_confirmation_format_test.php');
$formatBoundary=strpos($formatHarness,'$recap=');
if($formatBoundary===false)throw new RuntimeException('FORMAT_FIXTURE_BOUNDARY_MISSING');
eval(substr($formatHarness,5,$formatBoundary-5));
function blockerRun(array$rows,array$args=['--conversation','11','--blocker']):array{
    putenv('WA_FORMAT_TRANSCRIPT='.json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return statusRun('valid',$args);
}
function blockerRow(int$ordinal,?string$text,?array$point=null,string$speaker='customer',?array$cart=null):array{
    $row=['id'=>'m'.$ordinal,'speaker'=>$speaker,'sent_at'=>'2026-10-18 12:00:'.sprintf('%02d',$ordinal),
        'text'=>$text,'location'=>$point];
    if($cart!==null)$row['cart']=$cart;
    return$row;
}
$recap="ملخص طلبك:\n1 كيلو فسيخ – 100 جنيه\nالإجمالي: 100 جنيه\nالاسم: PRIVATE_NAME\nرقم الهاتف: 01033333333\nالعنوان: PRIVATE_ADDRESS\nاللوكيشن: 30.125,31.625";
$point=['lat'=>30.125,'long'=>31.625];
$pair=[blockerRow(1,$recap),blockerRow(2,'كل شئ تمام')];
[$exit,$default]=blockerRun($pair,['--conversation','11']);
statusExpect($exit===0&&!array_key_exists('blocker_observation',$default),'default read path adds no optional blocker data');
[$exit,$value]=blockerRun($pair);$observer=$value['blocker_observation'];
statusExpect($exit===0&&$value['status']==='READ_READY'&&$observer['available'],'optional observer runs actual parser in read-only CLI');
statusExpect($observer['scope']==='PAIR_BLOCKER_OBSERVATION_NOT_ORDER_AUTHORITY'&&$observer['reason']===null
    &&$observer['latest_recap_ordinal']===1&&$observer['recap_parse_ok']===true
    &&$observer['first_later_approval_ordinal']===2&&$observer['blocker']===null,'exact valid pair supplies observations without a blocker');
$ordinary=$pair;
$ordinary[]=blockerRow(3,null,$point);
$ordinary[]=blockerRow(4,'شكرا لحضرتك');
$ordinary[]=blockerRow(5,'ممكن أعرف وقت وصول المندوب');
[$exit,$value]=blockerRun($ordinary);
statusExpect($value['candidate']['valid']&&$value['blocker_observation']['blocker']===null,'same pure native point and ordinary later text remain accepted');
$cases=[
    ['conflicting pure native point',blockerRow(3,null,['lat'=>30.126,'long'=>31.625]),false,false,true,false,false],
    ['small literal native point difference',blockerRow(3,null,['lat'=>30.12500000001,'long'=>31.625]),false,false,true,false,false],
    ['same native point with caption',blockerRow(3,'تمام',$point),false,true,true,false,true],
    ['conflicting native point with caption',blockerRow(3,'تمام',['lat'=>30.126,'long'=>31.625]),false,true,true,false,false],
    ['unknown null-text media',blockerRow(3,null),false,false,false,false,null],
    ['native cart on typed followup',blockerRow(3,'شكرا',null,'customer',['private'=>'PRIVATE_CART']),true,true,false,false,null],
    ['explicit whole order change',blockerRow(3,'غير الكمية'),false,true,false,true,null],
    ['whole cancellation after exact approval',blockerRow(3,'الغي الطلب لو سمحت'),false,true,false,true,null],
];
foreach($cases as[$label,$tail,$cart,$text,$location,$change,$matches]){
    [$exit,$value]=blockerRun([...$pair,$tail]);$observer=$value['blocker_observation'];$blocker=$observer['blocker'];
    statusExpect($exit===0&&$observer['reason']==='CUSTOMER_CHANGED_AFTER_RECAP'
        &&$blocker['ordinal']===3&&$blocker['speaker']==='customer','first exact current parser blocker located: '.$label);
    statusExpect($blocker['cart_present']===$cart&&$blocker['text_present']===$text
        &&$blocker['location_present']===$location&&$blocker['explicit_order_change']===$change
        &&$blocker['point_matches_inline']===$matches,'safe blocker shape only: '.$label);
    statusExpect($observer['prefix_reason_before_blocker']===null,'prior prefix is valid exact pair: '.$label);
}
foreach(['cart','point']as$kind){
    $rows=$pair;
    if($kind==='cart')$rows[0]['cart']=['private'=>'PRIVATE_CART'];
    else$rows[0]['location']=['lat'=>30.126,'long'=>31.625];
    [$exit,$value]=blockerRun($rows);$observer=$value['blocker_observation'];
    statusExpect($observer['latest_recap_ordinal']===1&&$observer['recap_parse_ok']
        &&$observer['blocker']['ordinal']===1&&$observer['reason']==='CUSTOMER_CHANGED_AFTER_RECAP',
        'native metadata on recap itself can be first blocker: '.$kind);
}
$recapPoint=$pair;$recapPoint[0]['location']=$point;
[$exit,$value]=blockerRun($recapPoint);
statusExpect($value['candidate']['valid']&&$value['blocker_observation']['blocker']===null,'same inline point on recap is not a blocker');
$observedShape=[];
for($ordinal=1;$ordinal<=18;$ordinal++)$observedShape[]=blockerRow($ordinal,'رسالة عادية لا تمنح صلاحية طلب');
$observedShape[]=blockerRow(19,$recap);$observedShape[]=blockerRow(20,'كل شئ تمام');
$observedShape[]=blockerRow(21,null,['lat'=>30.126,'long'=>31.625]);
$observedShape[]=blockerRow(22,'شكرا');$observedShape[]=blockerRow(23,'وقت التوصيل مناسب');
[$exit,$value]=blockerRun($observedShape);$observer=$value['blocker_observation'];
statusExpect($observer['latest_recap_ordinal']===19&&$observer['first_later_approval_ordinal']===20
    &&$observer['blocker']['ordinal']===21&&$observer['blocker']['point_matches_inline']===false,
    '23 native row observation locates actual late point without guessing from global pin count');
$branchFixture=file_get_contents(__DIR__.'/whatsapp_customer_branch_summary_test.php');
$branchStart=strpos($branchFixture,'function branchSummaryText():string{');$branchEnd=strpos($branchFixture,'function branchSummaryRow(',$branchStart);
statusExpect($branchStart!==false&&$branchEnd!==false,'reuse only synthetic no-inline branch summary function');
eval(substr($branchFixture,$branchStart,$branchEnd-$branchStart));
$branchRows=[blockerRow(1,branchSummaryText()),blockerRow(2,null,$point),blockerRow(3,'كل شئ تمام')];
[$exit,$value]=blockerRun($branchRows);
statusExpect($value['candidate']['valid']&&$value['blocker_observation']['blocker']===null,
    'permitted native branch pin before distinct approval is not a blocker despite no inline point');
$lateBranchPin=[blockerRow(1,branchSummaryText()),blockerRow(2,'كل شئ تمام'),blockerRow(3,null,$point)];
[$exit,$value]=blockerRun($lateBranchPin);$observer=$value['blocker_observation'];
statusExpect($observer['reason']==='CUSTOMER_CHANGED_AFTER_RECAP'&&$observer['blocker']['ordinal']===3
    &&$observer['blocker']['point_matches_inline']===null,'no inline point has null diagnostic match rather than a manufactured conflict boolean');
$approvalCart=$pair;$approvalCart[1]['cart']=['private'=>'PRIVATE_CART'];
[$exit,$value]=blockerRun($approvalCart);$observer=$value['blocker_observation'];
statusExpect($observer['blocker']['ordinal']===2&&$observer['blocker']['cart_present']
    &&$observer['first_later_approval_ordinal']===2
    &&$observer['prefix_reason_before_blocker']==='CUSTOMER_APPROVAL_REQUIRED',
    'observed exact phrase on cart-bearing row remains diagnostic and does not override blocker');
$before=[blockerRow(1,$recap),blockerRow(2,null,null,'business'),blockerRow(3,'كل شئ تمام')];
[$exit,$value]=blockerRun($before);$observer=$value['blocker_observation'];
statusExpect($observer['reason']==='INTERVENING_TURN'&&$observer['blocker']['ordinal']===2
    &&$observer['blocker']['speaker']==='business'&&$observer['first_later_approval_ordinal']===3
    &&$observer['prefix_reason_before_blocker']==='CUSTOMER_APPROVAL_REQUIRED','business null media before approval is independently observed');
[$exit,$value]=blockerRun([...$pair,blockerRow(3,null,null,'business')]);$observer=$value['blocker_observation'];
statusExpect($observer['reason']==='BUSINESS_TURN_AFTER_APPROVAL'&&$observer['blocker']['ordinal']===3
    &&$observer['blocker']['speaker']==='business','business null media after approval has its actual parser reason');
$oldBlocked=[...$pair,blockerRow(3,'الغي الطلب'),blockerRow(4,$recap),blockerRow(5,'كل شئ تمام')];
[$exit,$value]=blockerRun($oldBlocked);$observer=$value['blocker_observation'];
statusExpect($value['candidate']['valid']&&$observer['latest_recap_ordinal']===4
    &&$observer['first_later_approval_ordinal']===5&&$observer['blocker']===null,'earlier blocker is superseded by later complete recap and distinct approval');
$malformed=[...$pair,blockerRow(3,'ملخص طلبك:'),blockerRow(4,'كل شئ تمام')];
[$exit,$value]=blockerRun($malformed);$observer=$value['blocker_observation'];
statusExpect(!$value['candidate']['valid']&&$observer['latest_recap_ordinal']===3
    &&$observer['recap_parse_ok']===false&&$observer['blocker']===null,'latest malformed recap is never diagnosed through old valid summary fallback');
[$exit,$value]=blockerRun([blockerRow(1,'كل شئ تمام')]);$observer=$value['blocker_observation'];
statusExpect($observer['reason']==='NO_CUSTOMER_RECAP'&&$observer['latest_recap_ordinal']===null
    &&$observer['blocker']===null,'missing recap carries no manufactured blocker');
[$exit,$value]=blockerRun([blockerRow(1,$recap)]);$observer=$value['blocker_observation'];
statusExpect($observer['reason']==='CUSTOMER_APPROVAL_REQUIRED'&&$observer['first_later_approval_ordinal']===null
    &&$observer['blocker']===null,'missing separate approval is not a later native mutation');
$invalid=$pair;$invalid[1]['id']='PRIVATE_NATIVE_ID';
[$exit,$value]=blockerRun($invalid);$observer=$value['blocker_observation'];
statusExpect($observer['available']===false&&$observer['reason']==='TRANSCRIPT_INVALID'
    &&$observer['blocker']===null,'invalid transcript fails closed without partial native guesses');
foreach([['--conversation','11','--blocker','--blocker'],['--blocker'],['--conversation','11','--blocker','yes']]as$args){
    [$exit,$value,$booted]=blockerRun($pair,$args);
    statusExpect($exit===1&&$value['stage']==='ARGUMENTS'&&!$booted,'invalid optional blocker arguments rejected before bootstrap');
}
foreach([['--blocker','--conversation','11'],['--conversation','11','--draft','100','--blocker'],
    ['--format','--conversation','11','--blocker'],['--blocker','--conversation','11','--format']]as$args){
    [$exit,$value]=blockerRun($pair,$args);
    statusExpect($exit===0&&isset($value['blocker_observation']),'blocker flag order independent and format/draft compatible');
}
$leakRows=[...$pair,blockerRow(3,null,['lat'=>30.126,'long'=>31.625])];
[$exit,$value]=blockerRun($leakRows);$output=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach(['PRIVATE_','01033333333','201033333333','30.125','30.126','31.625','الغي الطلب','غير الكمية']as$private)
    statusExpect(!str_contains($output,$private),'no customer content, contact, point or command literal exported: '.$private);
$start=strpos($source,'function waConfirmationBlockerObservation');$end=strpos($source,'/** Inspect only the authenticated native row',$start);
statusExpect($start!==false&&$end!==false,'bounded pure observer source seam');
statusExpect(!preg_match('/(?:->|::)(?:table|select|first|get|insert|update|delete|analyze|process|dispatch|send|quote)\s*\(/',
    substr($source,$start,$end-$start)),'pure blocker observer adds no database queries or domain action');
foreach([['--conversation','11','--native-blocker'],['--native-blocker','--conversation','11','--blocker'],
    ['--blocker','--conversation','11','--native-blocker']]as$args){
    [$exit,$value]=blockerRun($leakRows,$args);
    statusExpect($exit===0&&$value['status']==='READ_READY'&&isset($value['blocker_observation'])
        &&$value['native_blocker_observation']['available']===false
        &&$value['native_blocker_observation']['reason']==='NATIVE_PROOF_UNAVAILABLE',
        'optional native flag never guesses native identity absent from fixture snapshot');
}
foreach([['--conversation','11','--native-blocker','--native-blocker'],['--native-blocker'],
    ['--conversation','11','--native-blocker','yes']]as$args){
    [$exit,$value,$booted]=blockerRun($pair,$args);
    statusExpect($exit===1&&$value['stage']==='ARGUMENTS'&&!$booted,'invalid native observation arguments fail before bootstrap');
}
echo"WHATSAPP_CUSTOMER_CONFIRMATION_BLOCKER_OK checks=$checks\n";
