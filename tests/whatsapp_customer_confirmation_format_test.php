<?php
// Reuse only the existing host/database fixture setup; the child CLI uses the
// actual pinned parser, not the original status test's parser stub.
$root=dirname(__DIR__);
$harness=file_get_contents(__DIR__.'/whatsapp_customer_confirmation_status_test.php');
$boundary=strpos($harness,"[\$exit,\$value,\$booted,\$inspected,\$readOnly]=statusRun('valid');");
if($boundary===false)throw new RuntimeException('STATUS_FIXTURE_BOUNDARY_MISSING');
eval(substr($harness,5,$boundary-5));
$bootstrap=file_get_contents($fixture.'/bootstrap/app.php');
$start=strpos($bootstrap,'namespace App\\Support {');
$end=strpos($bootstrap,'namespace App\\Services\\Dashboard {',$start);
statusExpect($start!==false&&$end!==false,'explicit fixture parser seam');
$bootstrap=substr($bootstrap,0,$start)."namespace App\\Support {require ".var_export($root.'/app/Support/WhatsAppCustomerOrderConfirmation.php',true).";}\n".substr($bootstrap,$end);
$old=<<<'PHP'
'transcript'=>[['id'=>'m1','speaker'=>'customer','text'=>'PRIVATE_TEXT','location'=>null],
                    ['id'=>'m2','speaker'=>'customer','text'=>'PRIVATE_RECAP','location'=>null],
                    ['id'=>'m3','speaker'=>'customer','text'=>'PRIVATE_APPROVAL','location'=>null]]
PHP;
$bootstrap=str_replace($old,"'transcript'=>json_decode(getenv('WA_FORMAT_TRANSCRIPT'),true,32,JSON_THROW_ON_ERROR)",$bootstrap,$count);
statusExpect($count===1,'explicit fixture transcript seam');
file_put_contents($fixture.'/bootstrap/app.php',$bootstrap);
function formatRows(string$text,string$speaker='customer',?string$approval='كل شيء تمام'):array{
    $rows=[['id'=>'m1','speaker'=>$speaker,'sent_at'=>'2026-10-17 12:00:01','text'=>$text,'location'=>null]];
    if($approval!==null)$rows[]=['id'=>'m2','speaker'=>'customer','sent_at'=>'2026-10-17 12:00:02','text'=>$approval,'location'=>null];
    return$rows;
}
function formatRun(array$rows,array$args=['--conversation','11','--format']):array{
    putenv('WA_FORMAT_TRANSCRIPT='.json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return statusRun('valid',$args);
}
$recap="ملخص طلبك:\n1 كيلو فسيخ – 100 جنيه\nالإجمالي: 100 جنيه\nالاسم: PRIVATE_NAME\nرقم الهاتف: 01033333333\nالعنوان: PRIVATE_ADDRESS\nاللوكيشن: 31.123456,30.654321";
[$exit,$default]=formatRun(formatRows($recap),['--conversation','11']);
statusExpect($exit===0&&$default['candidate']['valid']&&!array_key_exists('format_observation',$default),'default CLI output remains unchanged');
[$exit,$value]=formatRun(formatRows($recap));$format=$value['format_observation'];
statusExpect($exit===0&&$value['status']==='READ_READY'&&$value['candidate']['valid'],'flag runs actual parser within real CLI');
statusExpect($format['scope']==='FORMAT_DIAGNOSTIC_ONLY_NOT_ORDER_AUTHORITY'&&$format['row_limit']===100&&!$format['rows_truncated'],'explicit diagnostic-only bounded scope');
statusExpect($format['counts']===['customer_exact_headers'=>1,'business_exact_headers'=>0,'customer_parse_ok'=>1,
    'customer_positive_approvals'=>1,'rows_with_safe_title'=>1],'actual parser/header/approval counts');
$first=$format['rows'][0];
statusExpect($first['ordinal']===1&&$first['speaker']==='customer'&&$first['text_present']&&$first['text_length']===mb_strlen($recap),'ordinal and text metadata only');
statusExpect($first['exact_header_match']&&$first['standalone_parse_ok']&&$first['standalone_parse_reason']===null&&!$first['positive_approval'],'standalone facts are distinct from customer approval');
statusExpect($first['recap_shape']===['name_label'=>true,'phone_label'=>true,'address_label'=>true,'total_label'=>true,'combined_data_label'=>false],'labeled values observed only as booleans');
statusExpect($first['safe_title_lines'][0]['literal']==='ملخص طلبك:'&&$first['safe_title_lines'][0]['line']===1,'generic title is allowlisted');
statusExpect($format['rows'][1]['positive_approval']&&!$format['rows'][1]['standalone_parse_ok'],'actual separate approval checker');
statusExpect($value['candidate']['approval_basis']==='SEPARATE_CUSTOMER_APPROVAL'&&!$value['candidate']['recap_is_approval'],
    'legacy receipt still reports independent customer approval');
$compact="تم توصيلك بفريقنا وسيتم متابعة طلبك حالاً.\n\nطلبك المؤكد:\n• كيلو فسيخ - 100 جنيه\nالإجمالي 100 جنيه غير التوصيل\n\nالبيانات: PRIVATE_NAME - PRIVATE_ADDRESS - 01033333333 - اللوكيشن 31.123456,30.654321\nالتوصيل خلال 60 دقيقة داخل المنصورة، وسعر التوصيل يأكده المندوب لما يكلمك.";
[$exit,$value]=formatRun(formatRows($compact,'customer',null));$row=$value['format_observation']['rows'][0];
statusExpect($exit===0&&$value['candidate']['valid']&&$value['candidate']['approval_basis']==='CUSTOMER_SELF_CONFIRMED_RECAP'
    &&$value['candidate']['recap_is_approval'],'compact customer copy reports same-message authority honestly');
statusExpect($row['compact_header_match']&&$row['exact_header_match']&&$row['standalone_self_confirmed']
    &&$row['recap_shape']['combined_data_label'],'compact protocol has safe diagnostic booleans');
statusExpect($row['safe_title_lines'][0]['literal']==='طلبك المؤكد:'&&$row['safe_title_lines'][0]['line']===3,
    'only fixed compact title is exposed');
[$exit,$value]=formatRun(formatRows($compact,'business',null));
statusExpect(!$value['candidate']['valid']&&$value['candidate']['approval_basis']===null
    &&!$value['candidate']['recap_is_approval']&&$value['format_observation']['counts']['business_exact_headers']===1,
    'business compact message remains diagnostic and supplies no customer authority');
foreach(['طلبك المؤكد: PRIVATE_CUSTOMER','PRIVATE_PREFIX طلبك المؤكد:','طلبك المؤكد:31.123456,30.654321']as$unsafe){
    [$exit,$value]=formatRun(formatRows($unsafe,'customer',null));
    statusExpect(!$value['candidate']['valid']&&$value['format_observation']['rows'][0]['safe_title_lines']===[],
        'compact header with private suffix or prefix leaks nothing and conveys no authority');
}
foreach(['**ملخص طلبك**:','• ملخص طلبك:','- ملخص طلبك:','__ملخص طلبك__:']as$title){
    [$exit,$value]=formatRun(formatRows(str_replace('ملخص طلبك:',$title,$recap)));
    $row=$value['format_observation']['rows'][0];
    statusExpect($exit===0&&$value['candidate']['valid']&&$row['exact_header_match']&&$row['standalone_parse_ok']
        &&$row['header_kind']==='LEGACY','same normalized legacy header drives inspect and diagnostic '.$title);
    statusExpect($row['safe_title_lines'][0]['literal']==='ملخص طلبك:','only normalized generic title exported '.$title);
}
[$exit,$value]=formatRun(formatRows(str_replace('ملخص طلبك:','**ملخص طلبك:**',$recap)));
statusExpect($value['candidate']['valid']&&$value['format_observation']['rows'][0]['exact_header_match'],'emphasis after colon matches current detector');
[$exit,$value]=formatRun(formatRows(str_replace('ملخص طلبك:','ملخص الطلب:',$recap)));
statusExpect($value['candidate']['reason']==='NO_CUSTOMER_RECAP'&&$value['format_observation']['rows'][0]['standalone_parse_reason']==='RECAP_HEADER_REQUIRED'
    &&$value['format_observation']['rows'][0]['safe_title_lines'][0]['literal']==='ملخص الطلب:','unrecognized fixed generic title stays diagnostic only');
[$exit,$value]=formatRun(formatRows($recap,'business'));
statusExpect($value['candidate']['reason']==='NO_CUSTOMER_RECAP'&&$value['format_observation']['counts']['business_exact_headers']===1
    &&$value['format_observation']['counts']['customer_exact_headers']===0,'business recap never acquires customer authority');
foreach(['ملخص طلبك: PRIVATE_CUSTOMER','PRIVATE_PREFIX ملخص طلبك:','ملخص الطلب النهائي: 01033333333',
    'ملخص طلبك:31.123456,30.654321',"الاسم: PRIVATE_NAME",str_repeat('*',100).'ملخص طلبك:']as$unsafe){
    [$exit,$value]=formatRun(formatRows($unsafe));
    statusExpect($value['format_observation']['rows'][0]['safe_title_lines']===[],'no private suffix/prefix/value or overlong title leak');
}
[$exit,$value]=formatRun(formatRows("ملخص طلبك:\nملخص طلبك:\nملخص طلبك:\nملخص طلبك:\nملخص طلبك:"));
statusExpect(count($value['format_observation']['rows'][0]['safe_title_lines'])===4
    &&$value['format_observation']['rows'][0]['safe_title_lines_truncated'],'safe generic title list is bounded');
[$exit,$value]=formatRun([['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-10-17 12:00:01','text'=>null,'location'=>['lat'=>31.123456,'long'=>30.654321]]]);
$row=$value['format_observation']['rows'][0];
statusExpect(!$row['text_present']&&$row['text_length']===null&&$row['standalone_parse_ok']===null
    &&$row['standalone_parse_reason']===null&&!$row['positive_approval']&&$row['safe_title_lines']===[],'media/location without text stays absent text');
[$exit,$value]=formatRun(formatRows($recap,'customer','تمام تمام'));
statusExpect($value['candidate']['reason']==='CUSTOMER_CHANGED_AFTER_RECAP'&&!$value['format_observation']['rows'][1]['positive_approval'],
    'repeated approval words do not become positive through diagnostic');
[$exit,$value]=formatRun(array_fill(0,101,['speaker'=>'customer','text'=>null]));
statusExpect(count($value['format_observation']['rows'])===100&&$value['format_observation']['rows_truncated'],'defensive row cap100');
foreach([['--conversation','11','--format','--format'],['--format'],['--conversation','11','--format','yes']]as$args){
    [$exit,$value,$booted]=formatRun(formatRows($recap),$args);
    statusExpect($exit===1&&$value['stage']==='ARGUMENTS'&&!$booted,'invalid/duplicate format argument rejected before bootstrap');
}
foreach([['--format','--conversation','11'],['--conversation','11','--draft','100','--format']]as$args){
    [$exit,$value]=formatRun(formatRows($recap),$args);
    statusExpect($exit===0&&isset($value['format_observation']),'optional flag is order-independent and draft-compatible');
}
statusExpect(!preg_match('/(?:->|::)(?:table|select|first|get|insert|update|delete|analyze|process|dispatch|send|quote)\s*\(/',
    substr($source,strpos($source,'function waConfirmationFormatObservation'),strpos($source,'/** Optional worker observations.')-strpos($source,'function waConfirmationFormatObservation'))),
    'pure observer adds no queries or domain actions');
echo"WHATSAPP_CUSTOMER_CONFIRMATION_FORMAT_OK checks=$checks\n";
