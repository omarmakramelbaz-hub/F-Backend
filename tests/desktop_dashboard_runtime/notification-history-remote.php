<?php
// Actual original history forms under native server outcome reservations.
$remoteHistoryOwn=(string)Str::uuid();$remoteHistoryOther=(string)Str::uuid();$remoteHistoryLate=(string)Str::uuid();
foreach([$remoteHistoryOwn=>1,$remoteHistoryOther=>20,$remoteHistoryLate=>1] as $note=>$actor)DB::table('notifications')->insert([
    'id'=>$note,'type'=>'FixtureNotification','notifiable_type'=>\App\Models\User::class,'notifiable_id'=>$actor,
    'data'=>json_encode(['title'=>'إشعار سجل نتيجة السيرفر','text'=>'إشعار مستقل']),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
$historyAttempt=$attempt('/admin/read/'.$remoteHistoryOwn);[, $historyProof]=$decide($historyAttempt);
$historyForm=['_token'=>$serverCsrf[1],'_method'=>'PUT','_desktop_command'=>(string)Str::uuid(),'desktop_notification_ids'=>json_encode([$remoteHistoryOwn])];
[$historyStatus,,$historyHeaders]=$http($historyAttempt['path'],$historyForm,$proof($historyProof));
verify($historyStatus===302&&in_array('Location: '.$origin.'/admin/notifications',$historyHeaders,true)
    &&DB::table('notifications')->where('id',$remoteHistoryOwn)->value('read_at')!==null&&$decide($historyAttempt,'settle')[1]['status']==='committed',
    'the original history single-read redirect commits with its reserved terminal server outcome');
$historyStamp=DB::table('notifications')->where('id',$remoteHistoryOwn)->value('read_at');
verify($http($historyAttempt['path'],$historyForm,$proof($historyProof))[0]===302&&DB::table('notifications')->where('id',$remoteHistoryOwn)->value('read_at')===$historyStamp,
    'a lost original history redirect returns its saved outcome without changing the first read timestamp');
$historyRetry=$attempt($historyAttempt['path']);[, $historyRetryProof]=$decide($historyRetry);
verify($http($historyRetry['path'],$historyForm,$proof($historyRetryProof))[0]===302&&$decide($historyRetry,'settle')[1]['status']==='committed',
    'another native transmission retains the original logical notification read operation');
$historyBulkAttempt=$attempt('/admin/read/all/notification');[, $historyBulkProof]=$decide($historyBulkAttempt);
$historyBulkForm=['_token'=>$serverCsrf[1],'_desktop_command'=>(string)Str::uuid(),'desktop_notification_ids'=>json_encode([$remoteHistoryOwn])];
verify($http($historyBulkAttempt['path'],$historyBulkForm,$proof($historyBulkProof))[0]===302
    &&DB::table('notifications')->whereIn('id',[$remoteHistoryOther,$remoteHistoryLate])->whereNotNull('read_at')->count()===0,
    'the original reserved mark-all controller leaves foreign and later notifications unread');
$altered=$historyBulkForm;$altered['desktop_notification_ids']=json_encode([$remoteHistoryLate]);
verify($http($historyBulkAttempt['path'],$altered,[...$proof($historyBulkProof),'Accept: application/json'])[0]===409
    &&DB::table('notifications')->where('id',$remoteHistoryLate)->value('read_at')===null,
    'a committed history operation rejects a changed read snapshot before any extra notification is marked');
$foreignAttempt=$attempt('/admin/read/all/notification');[, $foreignProof]=$decide($foreignAttempt);
$foreignForm=$historyBulkForm;$foreignForm['_desktop_command']=(string)Str::uuid();$foreignForm['desktop_notification_ids']=json_encode([$remoteHistoryOther]);
verify($http($foreignAttempt['path'],$foreignForm,[...$proof($foreignProof),'Accept: application/json'])[0]===404
    &&$decide($foreignAttempt,'settle')[1]['status']==='cancelled','a foreign reserved history snapshot creates no success and can be terminally cancelled');
DB::table('users')->where('id',1)->update(['status'=>'disabled']);
try{verify($http($historyAttempt['path'],$historyForm,$proof($historyProof))[0]===403,'a disabled account cannot obtain its stored original history reply');}
finally{DB::table('users')->where('id',1)->update(['status'=>'accepted']);}
$cancelHistory=$attempt('/admin/read/all/notification');[, $cancelHistoryProof]=$decide($cancelHistory);
verify($decide($cancelHistory,'settle')[1]['status']==='cancelled'
    &&$http($cancelHistory['path'],$altered,$proof($cancelHistoryProof))[0]===409
    &&DB::table('notifications')->where('id',$remoteHistoryLate)->value('read_at')===null,'a late cancelled history request cannot mark the still-unread notification');
$rollbackHistory=$attempt('/admin/read/all/notification');[, $rollbackHistoryProof]=$decide($rollbackHistory);
$rollbackForm=$altered;$rollbackForm['_desktop_command']=(string)Str::uuid();
DB::statement("CREATE TRIGGER reject_history_outcome BEFORE UPDATE ON desktop_dashboard_remote_attempts FOR EACH ROW BEGIN IF NEW.status='committed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated history outcome failure'; END IF; END");
try{verify($http($rollbackHistory['path'],$rollbackForm,[...$proof($rollbackHistoryProof),'Accept: application/json'])[0]===500
    &&DB::table('notifications')->where('id',$remoteHistoryLate)->value('read_at')===null,'failed terminal history outcome persistence rolls back the original server notification read');}
finally{DB::statement('DROP TRIGGER reject_history_outcome');}
verify($http($rollbackHistory['path'],$rollbackForm,$proof($rollbackHistoryProof))[0]===302
    &&$decide($rollbackHistory,'settle')[1]['status']==='committed','the same history reservation retries safely after a transactional outcome failure');
