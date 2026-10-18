<?php
// Real read-only CLI and actual grounded AI proof; no API or production data.
$formatHarness=file_get_contents(__DIR__.'/whatsapp_customer_confirmation_format_test.php');
$boundary=strpos($formatHarness,'$recap=');eval(substr($formatHarness,5,$boundary-5));
$flex=file_get_contents(__DIR__.'/whatsapp_customer_flexible_ai_test.php');$boundary=strpos($flex,'$rows=flexRows();');
eval(substr($flex,5,$boundary-5));
$bootstrap=file_get_contents($fixture.'/bootstrap/app.php');
$anchor="'extraction'=>json_encode(['type'=>'CUSTOMER_CONFIRMED_RECAP','customer'=>['phone'=>'PRIVATE_PHONE']])";
$bootstrap=str_replace($anchor,"'extraction'=>getenv('WA_STATUS_AI_EXTRACTION') ?: json_encode(['type'=>'CUSTOMER_CONFIRMED_RECAP'])",$bootstrap,$count);
statusExpect($count===1,'explicit AI cache fixture seam');file_put_contents($fixture.'/bootstrap/app.php',$bootstrap);
file_put_contents($fixture.'/vendor/autoload.php',"<?php require ".var_export($root.'/app/Support/WhatsAppInboxProtocol.php',true).";require ".var_export($root.'/app/Support/WhatsAppOrderExtraction.php',true).";require ".var_export($root.'/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php',true).";");
$rows=flexRows();$proved=\App\Support\WhatsAppCustomerOrderConfirmation::validateAi(flexSelection(),$rows);statusExpect($proved['ok'],'authentic AI fixture proved before observation');
putenv('WA_STATUS_AI_EXTRACTION='.json_encode($proved['data'],JSON_UNESCAPED_UNICODE));
[$exit,$value]=formatRun($rows,['--conversation','11']);
statusExpect($exit===0&&$value['status']==='READ_READY'&&$value['candidate']['valid']&&$value['ai_understanding']['valid'],'CLI re-proves AI cache with current native text');
statusExpect($value['candidate']['summary_format']==='AI_CUSTOMER_ORDER_SUMMARY'&&$value['candidate']['items_count']===1
    &&$value['candidate']['approval_basis']==='SEPARATE_CUSTOMER_APPROVAL','candidate reports genuine separate consent and AI extraction basis');
$json=json_encode($value,JSON_UNESCAPED_UNICODE);
foreach(['عميل تجريبي','شارع تجريبي','01000000001','30.125','31.625','quantity_source','ai_extraction']as$private)
    statusExpect(!str_contains($json,$private),'AI diagnostic exposes no copied field or selection body');
$tampered=$proved['data'];$tampered['items'][0]['quantity']='3';putenv('WA_STATUS_AI_EXTRACTION='.json_encode($tampered));
[$exit,$value]=formatRun($rows,['--conversation','11']);
statusExpect($exit===0&&!$value['candidate']['valid']&&!$value['ai_understanding']['valid'],'tampered cache cannot become a valid candidate observation');
putenv('WA_STATUS_AI_EXTRACTION');
echo"WHATSAPP_CUSTOMER_FLEXIBLE_AI_STATUS_OK checks=$checks\n";
