<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0'); ob_start();
$out=['diagnostic'=>'READ_ONLY_CAPTURE_PLACEHOLDER_METADATA','writes'=>0,'ai_calls'=>0,'network_requests'=>0,'events'=>[]]; $stage='OWNER';
try {
    $root='/home/fasakha/public_html'; $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']) throw new \RuntimeException();
    $pins=['app/Services/Dashboard/WhatsAppInboxConsumer.php'=>'e54715313901be8baefb551566b7bb88f4afe45b','app/Support/WhatsAppInboxProtocol.php'=>'ecde67c6a87ca8068bb077892fdbea522d89f217'];
    $stage='SOURCE_GUARD';
    $guard=static function($file,$hash) use($root) { $p=$root.'/'.$file; $s=@lstat($p);
        if (!$s||($s['mode']&0170000)!==0100000||$s['uid']!==posix_geteuid()||realpath($p)!==$p||$s['size']<1||$s['size']>8388608) throw new \RuntimeException();
        $b=file_get_contents($p); if (sha1('blob '.strlen($b)."\0".$b)!==$hash) throw new \RuntimeException(); };
    foreach($pins as $p=>$h) $guard($p,$h);
    $stage='BOOTSTRAP'; chdir($root); require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach([\App\Services\Dashboard\WhatsAppInboxConsumer::class=>'app/Services/Dashboard/WhatsAppInboxConsumer.php',\App\Support\WhatsAppInboxProtocol::class=>'app/Support/WhatsAppInboxProtocol.php'] as $c=>$p) {
        if (realpath((new \ReflectionClass($c))->getFileName())!==$root.'/'.$p) throw new \RuntimeException(); $guard($p,$pins[$p]); }
    $db=\Illuminate\Support\Facades\DB::class; $crypt=\Illuminate\Support\Facades\Crypt::class; $protocol=\App\Support\WhatsAppInboxProtocol::class;
    $decode=static function($cipher,$plainLimit) use($crypt) { if (!is_string($cipher)||strlen($cipher)>8388608) throw new \RuntimeException();
        $p=$crypt::decryptString($cipher); if(strlen($p)>$plainLimit) throw new \RuntimeException(); $v=json_decode($p,true,512,JSON_THROW_ON_ERROR); if(!is_array($v)) throw new \RuntimeException(); return $v; };
    $types=['text','image','video','document','audio','sticker','location','contacts','interactive','button','reaction','order','system','referral','edit','revoke','unsupported','unknown'];
    $type=static fn($v)=>in_array($v,$types,true)?$v:'OTHER';
    $kind=static fn($v)=>$v===null?'NULL':(is_array($v)?'ARRAY':(is_string($v)?'STRING':(is_int($v)?'INT':(is_bool($v)?'BOOL':(is_float($v)?'FLOAT':'OTHER')))));
    $errors=static function($raw) { $v=is_array($raw)?($raw['errors']??null):null; $codes=[]; $bad=0;
        foreach(array_slice(is_array($v)?$v:[],0,5) as $e) { $c=is_array($e)?($e['code']??null):null; if(is_int($c)&&$c>=0&&$c<=99999999) $codes[]=$c; else $bad++; }
        return ['errors_is_list'=>is_array($v)&&array_is_list($v),'error_count'=>is_array($v)?count($v):0,'integer_codes'=>$codes,'non_integer_or_out_of_range_codes'=>$bad,'codes_truncated'=>is_array($v)&&count($v)>5]; };
    $validId=new \ReflectionMethod($protocol,'identifier'); $validId->setAccessible(true);
    $ids=[5945,6135,6182,6436,6514,6515,6827,6837,6902,6989,7141,7182,7215,7338]; $budget=0; $stage='METADATA';
    foreach($ids as $eventId) {
        $r=['event_id'=>$eventId];
        try {
            $f=$db::table('whatsapp_inbox_ingestion_failures')->where('event_id',$eventId)->first(['reason']);
            $r['quarantined']=$f!==null; $r['reason']=$f&&in_array($f->reason,['CONFLICTING_MESSAGE','INVALID_EVENT'],true)?$f->reason:'OTHER';
            $e=$db::table('whatsapp_webhook_events')->where('id',$eventId)->select('id')->selectRaw('CASE WHEN LENGTH(payload)<=8388608 THEN payload ELSE NULL END AS payload')->first();
            if(!$e) throw new \RuntimeException(); $budget+=is_string($e->payload)?strlen($e->payload):0; if($budget>16777216) { $r['status']='BYTE_BUDGET'; $out['events'][]=$r; break; }
            $payload=$decode($e->payload,4194304); $report=$protocol::report($payload,'468336579702269','515388018324075');
            $r['normalized_count']=count($report['messages']); $r['parser_quarantined_count']=$report['quarantined_count']; $r['messages']=[];
            foreach(array_slice($report['messages'],0,20) as $m) {
                $key=hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$m['message_id']);
                $saved=$db::table('whatsapp_inbox_messages')->where('message_key',$key)->select('id','type','source')
                    ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
                $x=['incoming_type'=>$type($m['type']),'incoming_errors'=>$errors($m['content']['message']??null),'saved_message_id'=>$saved?(int)$saved->id:null];
                if($saved) { $dto=$decode($saved->content,4194304); $x['saved_column_type']=$type($saved->type); $x['saved_dto_type']=$type($dto['type']??null);
                    $x['saved_text_is_null']=array_key_exists('text',$dto)&&$dto['text']===null; $x['saved_errors']=$errors($dto['content']['message']??null); }
                $r['messages'][]=$x;
            }
            $r['messages_truncated']=count($report['messages'])>20;
            if($eventId===7215) {
                $r['status_shapes']=[]; $r['contact_profiles']=[];
                foreach(array_slice(is_array($payload['entry']??null)?$payload['entry']:[],0,5) as $entry) foreach(array_slice(is_array($entry['changes']??null)?$entry['changes']:[],0,5) as $change) {
                    $v=$change['value']??null; if(!is_array($v)||($entry['id']??null)!=='468336579702269'||($v['metadata']['phone_number_id']??null)!=='515388018324075') continue;
                    $channel=is_array($v['standby']??null)?$v['standby']:$v; $contacts=$channel['contacts']??($v['contacts']??[]);
                    foreach(array_slice(is_array($contacts)?$contacts:[],0,5) as $c) { if(count($r['contact_profiles'])>=5) break;
                        $present=is_array($c)&&array_key_exists('profile',$c); $r['contact_profiles'][]=['profile_present'=>$present,'profile_type'=>$present?$kind($c['profile']):'ABSENT']; }
                    foreach(array_slice(is_array($channel['statuses']??null)?$channel['statuses']:[],0,5) as $s) { if(count($r['status_shapes'])>=5) break;
                        $state=is_array($s)?($s['status']??null):null; $r['status_shapes'][]=['status'=>in_array($state,['sent','delivered','read','failed','deleted'],true)?$state:'OTHER',
                            'id_valid'=>is_array($s)&&$validId->invoke(null,$s['id']??null,512),'status_is_array'=>is_array($s),
                            'field'=>in_array($change['field']??null,['messages','standby','smb_message_echoes'],true)?$change['field']:'OTHER']; }
                }
            }
            $r['status']='READY';
        } catch(\Throwable $ignore) { $r['status']='UNREADABLE'; }
        $out['events'][]=$r;
    }
    while(ob_get_level()) ob_end_clean(); echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch(\Throwable $ignore) { while(ob_get_level()) ob_end_clean(); $out['status']='FAILED'; $out['stage']=$stage; echo json_encode($out,JSON_PRETTY_PRINT)."\n"; exit(1); }
