<?php
// Execute the actual pure status helpers with fixed UTC timestamps and original
// receipt IDs. No database, current clock, sender, policy or ERP seam is needed.
$source=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_customer_confirmation_status.php');
$timeStart=strpos($source,'function waConfirmationTime(');$timeEnd=strpos($source,'function waConfirmationCall(',$timeStart);
$dayStart=strpos($source,'function waConfirmationCairoDate(');$dayEnd=strpos($source,'/** Pure format observations.',$dayStart);
if($timeStart===false||$timeEnd===false||$dayStart===false||$dayEnd===false)throw new RuntimeException('DAY_HELPER_BOUNDARY_MISSING');
$helpers=substr($source,$timeStart,$timeEnd-$timeStart).substr($source,$dayStart,$dayEnd-$dayStart);
eval($helpers);$checks=0;
function dayExpect($ok,string$label):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException('RECEIPT_DAY_OBSERVATION_FAILED:'.$label);}
function dayData(string$recap='recap',string$approval='approval'):array{
    return['evidence_receipt'=>['recap_id'=>$recap,'approval_id'=>$approval]];
}
function dayRows(string$recap,string$approval,?string$greeting=null):array{
    $rows=[['id'=>'recap','speaker'=>'customer','sent_at'=>$recap],['id'=>'approval','speaker'=>'customer','sent_at'=>$approval]];
    if($greeting!==null)$rows[]=['id'=>'greeting','speaker'=>'customer','sent_at'=>$greeting];
    return$rows;
}
$readAt='2026-10-17 22:00:00'; // 18 October, 01:00 in Cairo.
$value=waConfirmationReceiptDay(dayData(),dayRows('2026-10-17 21:01:00','2026-10-17 21:02:00'),$readAt);
dayExpect($value['current_date_cairo']==='2026-10-18'&&$value['available']&&$value['same_day'],'Cairo day differs from raw UTC date');
dayExpect($value['recap_same_day']&&$value['approval_same_day'],'both proved receipt rows belong to current day');
dayExpect($value['recap_ordinal']===1&&$value['approval_ordinal']===2,'receipt ordinals identify the original authority rows without customer content');
$value=waConfirmationReceiptDay(dayData(),dayRows('2026-10-17 20:59:59','2026-10-17 21:01:00','2026-10-17 21:59:59'),$readAt);
dayExpect($value['available']&&!$value['recap_same_day']&&$value['approval_same_day']&&!$value['same_day'],
    'today approval and latest greeting cannot renew yesterday recap');
$value=waConfirmationReceiptDay(dayData(),dayRows('2026-10-17 21:01:00','2026-10-17 20:59:59'),$readAt);
dayExpect($value['recap_same_day']&&!$value['approval_same_day']&&!$value['same_day'],'independent approval must also belong to today');
$value=waConfirmationReceiptDay(dayData(),dayRows('2026-10-17 20:50:00','2026-10-17 20:51:00'),'2026-10-17 21:10:00');
dayExpect(!$value['same_day'],'receipt twenty minutes old still crosses the Cairo calendar boundary');
$value=waConfirmationReceiptDay(dayData('recap','recap'),dayRows('2026-10-17 21:01:00','2026-10-17 20:59:59'),$readAt);
dayExpect($value['same_day'],'explicit self-confirmed receipt uses its one actual native row for both roles');
dayExpect($value['recap_ordinal']===1&&$value['approval_ordinal']===1,'self-confirmed receipt exposes one shared original ordinal');
$rows=dayRows('2026-10-17 21:01:00','2026-10-17 21:02:00');$rows[0]['speaker']='business';
$value=waConfirmationReceiptDay(dayData(),$rows,$readAt);
dayExpect(!$value['available']&&$value['recap_same_day']===null&&$value['same_day']===null,'business row never becomes customer recap day authority');
$value=waConfirmationReceiptDay(dayData('missing'),dayRows('2026-10-17 21:01:00','2026-10-17 21:02:00'),$readAt);
dayExpect(!$value['available']&&$value['same_day']===null,'missing receipt identity is unknown rather than guessed from latest message');
$value=waConfirmationReceiptDay(dayData(),dayRows('not-a-time','2026-10-17 21:02:00'),$readAt);
dayExpect(!$value['available']&&$value['same_day']===null,'malformed original timestamp yields unavailable proof');
$value=waConfirmationReceiptDay([],dayRows('2026-10-17 21:01:00','2026-10-17 21:02:00'),$readAt);
dayExpect(!$value['available']&&$value['recap_same_day']===null&&$value['approval_same_day']===null,'unproved candidate has no day proof');
dayExpect(waConfirmationCairoDate('2026-10-17 20:59:59')==='2026-10-17'
    &&waConfirmationCairoDate('2026-10-17 21:00:00')==='2026-10-18','one second across Cairo midnight changes the calendar guard');
dayExpect(waConfirmationCairoDate('2026-02-30 12:00:00')===null,'invalid calendar date is not normalized into authority');
dayExpect(!preg_match('/(?:->|::)(?:table|first|get|insert|update|delete|dispatch|send|quote|process|analyze|save)\s*\(/',$helpers),
    'day observer is pure and invokes no query or action');
dayExpect(str_contains($source,"&&\$out['cairo_date_unchanged_during_read']?'READ_READY':'OBSERVATION_CHANGED'"),
    'status observation detects a Cairo midnight crossing before returning ready');
echo"WHATSAPP_CUSTOMER_CONFIRMATION_DAY_OBSERVATION_OK checks=$checks\n";
