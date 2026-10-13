<?php
// Pure fixture: no Laravel bootstrap, database, provider calls or production process methods.
define('WHATSAPP_CAPTURE_CONFLICT_DIAGNOSTIC_TEST_ONLY',true);
require dirname(__DIR__).'/deployment/whatsapp_order_capture_conflict_diagnostic.php';
require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';
set_error_handler(function($severity,$message,$file,$line){if($severity&E_ALL)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$checks=0;
function captureExpect($actual,$expected,$label){global $checks;$checks++;if($actual!==$expected)throw new RuntimeException('FAILED: '.$label);}
use WhatsAppOrderCaptureConflictDiagnostic as D;

$incoming=['message_id'=>'wamid.SECRET_NATIVE','peer_identity'=>'user:SECRET_USER','peer_user_id'=>'SECRET_USER','peer_phone'=>'201000000001',
    'direction'=>'outbound','type'=>'text','source'=>'smb_message_echoes','text'=>'SECRET_TEXT','sent_at'=>'2026-10-13 10:00:00',
    'content'=>['message'=>['id'=>'wamid.SECRET_NATIVE','to'=>'201000000001','to_user_id'=>'SECRET_USER',
        'text'=>['body'=>'SECRET_TEXT'],'context'=>['id'=>'wamid.SECRET_QUOTE']]]];
$customer=['name'=>'SECRET_NAME','phone'=>'201000000001','user_id'=>'SECRET_USER'];
$same=D::compare($incoming,$incoming,$customer);
captureExpect($same['dto_difference_names'],[],'same canonical native DTO');
captureExpect($same['payload_difference_names'],[],'same native payload');
captureExpect($same['timestamp_equal'],true,'equal native time');
captureExpect($same['customer_phone_compatible'],true,'same customer phone compatibility');
captureExpect($same['customer_user_id_compatible'],true,'same customer user compatibility');

$changed=$incoming;$changed['sent_at']='2026-10-13 10:01:00';$changed['text']='SECRET_DIFFERENT';$changed['content']['message']['text']['body']='SECRET_DIFFERENT';
$changed['content']['message']['context']['id']='wamid.SECRET_CHANGED_QUOTE';$changed['content']['message']['to']='201000000009';
$diff=D::compare($changed,$incoming,$customer);
captureExpect($diff['dto_difference_names'],['text','sent_at'],'consumer canonical difference names');
captureExpect($diff['payload_difference_names'],['text'],'consumer payload difference names');
captureExpect($diff['routing_difference_names'],['to'],'routing differences separated from payload');
captureExpect($diff['context_difference_names'],['id'],'quote context differences reveal names only');
captureExpect($diff['timestamp_delta_seconds'],60,'bounded timestamp delta');

$alias=$incoming;$alias['peer_identity']='phone:201000000001';$alias['peer_user_id']=null;
$a=D::compare($alias,$incoming,$customer);
captureExpect($a['incoming_identity_kind'],'PHONE','alias kind only');
captureExpect($a['identity_equal'],false,'peer identity difference visible');
captureExpect($a['phone_equal_when_both_present'],true,'same phone under user/phone alias');
captureExpect($a['user_both_present'],false,'missing identity component explicitly visible');
captureExpect($a['customer_user_id_compatible'],true,'missing incoming user does not contradict saved user');
$alias['peer_phone']='201000000009';
captureExpect(D::compare($alias,$incoming,$customer)['customer_phone_compatible'],false,'contradicting known customer phone');

foreach(['edit','revoke','unknown','referral','unsupported'] as $type){
    $x=$incoming;$x['type']=$type;$x['content']['message'][$type]=['value'=>'SECRET_A'];
    $y=$x;$y['content']['message'][$type]=['value'=>'SECRET_B'];
    captureExpect(D::type($type),$type,'fixed parser-supported enum '.$type);
    captureExpect(in_array($type,D::compare($x,$y,$customer)['payload_difference_names'],true),true,'raw type payload difference '.$type);
}
$raw=$incoming;$raw['content']['message']['text']=['body'=>'SECRET_TEXT','extra'=>['a'=>1,'b'=>2]];
$rawOrder=$raw;$rawOrder['content']['message']['text']['extra']=['b'=>2,'a'=>1];
captureExpect(D::compare($raw,$rawOrder,$customer)['payload_difference_names'],[],'canonical associative field ordering');
$evil=$incoming;$evil['type']='SECRET_TYPE';$evil['source']='SECRET_SOURCE';$evil['content']['message']['SECRET_FIELD']='SECRET_CONTENT';
$out=D::compare($evil,$incoming,$customer);
captureExpect($out['incoming_type'],'OTHER','unknown type cannot leak string');
captureExpect($out['incoming_source'],'OTHER','unknown source cannot leak string');
captureExpect(in_array('SECRET_FIELD',$out['payload_difference_names'],true),false,'unknown field names never returned');
$long=$incoming;$long['sent_at']='2030-10-13 10:00:00';
captureExpect(D::compare($long,$incoming,$customer)['timestamp_delta_seconds'],31536000,'large timestamp delta bounded');
captureExpect(D::compare($long,$incoming,$customer)['timestamp_delta_clamped'],true,'timestamp truncation explicit');

$payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>D::WABA,'changes'=>[['field'=>'smb_message_echoes','value'=>[
    'messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>D::PHONE,'display_phone_number'=>'201285545554'],
    'contacts'=>[['wa_id'=>'201000000001','user_id'=>'SECRET_USER','profile'=>['name'=>'SECRET_NAME']]],
    'message_echoes'=>[['id'=>'wamid.SECRET_NATIVE','timestamp'=>'1791885600','to'=>'201000000001','to_user_id'=>'SECRET_USER',
        'type'=>'text','text'=>['body'=>'SECRET_TEXT']]]]]]]]];
$report=\App\Support\WhatsAppInboxProtocol::report($payload,D::WABA,D::PHONE);
captureExpect(count($report['messages']),1,'actual pure parser sees scoped echo');
captureExpect($report['quarantined_count'],0,'actual pure parser accepts valid echo');
$shape=D::morphology($payload);
captureExpect($shape['object_matches'],true,'envelope product matches');
captureExpect($shape['changes'][0]['phone_scope_matches'],true,'fixed phone scope morphology');
captureExpect($shape['changes'][0]['message_echoes_shapes'][0]['text_body_is_string'],true,'message body type morphology');
$bad=$payload;$bad['entry'][0]['changes'][0]['value']['message_echoes'][0]['timestamp']=['SECRET_VALUE'];
$invalidReport=\App\Support\WhatsAppInboxProtocol::report($bad,D::WABA,D::PHONE);
captureExpect($invalidReport['quarantined_count'],1,'actual parser rejects invalid timestamp shape');
captureExpect(D::morphology($bad)['changes'][0]['message_echoes_shapes'][0]['timestamp_is_digits'],false,'invalid timestamp diagnosed without casting array');
$many=$payload;$many['entry']=array_fill(0,30,$payload['entry'][0]);
captureExpect(D::morphology($many)['truncated'],true,'entry morphology bounded');
$many=$payload;$many['entry'][0]['changes']=array_fill(0,30,$payload['entry'][0]['changes'][0]);
captureExpect(count(D::morphology($many)['changes'])<=10,true,'change morphology bounded');
$junk=['object'=>'SECRET_OBJECT','entry'=>[['id'=>'SECRET_WABA','changes'=>[['field'=>'SECRET_FIELD','value'=>['text'=>'SECRET_TEXT']]]]]];
captureExpect(D::morphology($junk)['changes'][0]['field'],'OTHER','unknown field morphology cannot leak arbitrary string');
$serialized=json_encode([$same,$diff,$a,$out,$shape,D::morphology($bad),D::morphology($many),D::morphology($junk)],JSON_THROW_ON_ERROR);
foreach(['SECRET_NATIVE','SECRET_USER','201000000001','201000000009','SECRET_TEXT','SECRET_DIFFERENT','SECRET_QUOTE','SECRET_CHANGED_QUOTE','SECRET_NAME','SECRET_CONTENT','SECRET_TYPE','SECRET_SOURCE','SECRET_FIELD','SECRET_OBJECT','SECRET_WABA','SECRET_VALUE'] as $secret)
    captureExpect(strpos($serialized,$secret),false,'no disclosure '.$secret);
$source=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_order_capture_conflict_diagnostic.php');
captureExpect(preg_match('/(?:->|::)(?:consume|process|projectPending|projectAcceptedReply|analyze|dispatch|insert|update|delete|save)\s*\(/',$source),0,'diagnostic contains no processing or write calls');
captureExpect(strpos($source,'->lockForUpdate('),false,'diagnostic contains no mutating serialization locks');
foreach(['e54715313901be8baefb551566b7bb88f4afe45b','ecde67c6a87ca8068bb077892fdbea522d89f217','4b571f7f4a5cef9257c414886dba515c06dae2a1','b30f25cf6dfc4a84061a58316db583f892a439a4','bedd91db5266d83bdce84a04836e988518891728','1ef23df825287f8d3dedafcdb5d70ffeac7f1f29','186789d42113e81818de8d82a2327e3ee9d8d0b5'] as $hash)
    captureExpect(strpos($source,$hash)!==false,true,'required immutable source guard');
echo 'WHATSAPP_CAPTURE_CONFLICT_DIAGNOSTIC_CHECKS='.$checks."\n";
