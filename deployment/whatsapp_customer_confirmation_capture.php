<?php
// Read-only classification of the exact bounded forward capture failures.
// No consumer, retry, projection, provider, sender, worker or ERP action runs.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_CAPTURE','writes'=>0,
    'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,'worker_actions'=>0,
    'scope'=>'BOUNDED_FORWARD_FAILURE_CLASSIFICATION_NOT_REPAIR_OR_ORDER_AUTHORITY'];
$stage='ARGUMENTS';
function waCaptureArguments(array$args):array{
    $names=['--conversation'=>'conversation','--expected-floor'=>'floor',
        '--expected-ceiling'=>'ceiling','--expected-revision'=>'revision','--expected-event-floor'=>'event_floor'];$values=[];
    for($i=1;$i<count($args);$i++){
        $name=$names[$args[$i]]??null;
        if($name===null||isset($values[$name])||!isset($args[$i+1])
            ||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$args[$i+1]))throw new RuntimeException('ARGUMENTS_INVALID');
        $values[$name]=(int)$args[++$i];
    }
    if(count($values)!==5||$values['conversation']<1||$values['revision']<1||$values['ceiling']<=$values['floor'])
        throw new RuntimeException('ARGUMENTS_INVALID');
    return$values;
}
function waCaptureCall($object,string$name,array$args=[]){
    $method=new ReflectionMethod($object,$name);$method->setAccessible(true);return$method->invokeArgs($method->isStatic()?null:$object,$args);
}
function waCaptureError(Throwable$error):array{
    $database=$error instanceof PDOException||is_a($error,'Illuminate\\Database\\QueryException');
    $info=$error->errorInfo??null;$sqlstate=is_array($info)?($info[0]??null):null;
    if(!is_string($sqlstate)||!preg_match('/\A[A-Z0-9]{5}\z/D',$sqlstate))$sqlstate=null;
    $number=is_array($info)?filter_var($info[1]??null,FILTER_VALIDATE_INT):false;
    return['category'=>$database?'DATABASE':'EXCEPTION','sqlstate'=>$sqlstate,
        'driver_errno'=>$number!==false&&$number>=0&&$number<=65535?$number:null];
}
function waCaptureTime($value):?string{
    if(!is_string($value))return null;
    $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return$time&&$time->format('Y-m-d H:i:s')===$value&&substr($value,0,4)!=='0000'?$value:null;
}
function waCaptureInteger($value):?int{
    $number=filter_var($value,FILTER_VALIDATE_INT);return$number!==false&&$number>=0?$number:null;
}
function waCaptureCode($value):?string{
    return is_string($value)&&preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D',$value)?$value:null;
}
function waCaptureSources(array$pins,array$protocols,?array$expected=null):array{
    $result=[];
    foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)
            throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');
        $actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        if(!in_array($actual,$path==='app/Support/WhatsAppInboxProtocol.php'?$protocols:[$pin],true)
            ||$expected!==null&&($expected[$path]??null)!==$actual)throw new RuntimeException('SOURCE_CHANGED');
        $result[$path]=$actual;
    }
    return$result;
}
function waCaptureProtocol(string$name,array$args=[]){return waCaptureCall(\App\Support\WhatsAppInboxProtocol::class,$name,$args);}
function waCaptureIncrement(array&$counts,string$key):void{$counts[$key]=($counts[$key]??0)+1;}
function waCaptureKind($value):string{
    return in_array($value,['text','image','video','document','audio','sticker','location','contacts','interactive','button','reaction',
        'order','system','referral','edit','revoke','unsupported','unknown'],true)?$value:'OTHER';
}
/** The first rejecting predicate follows the pinned pure message parser order. */
function waCaptureMessageReason(array$message,string$direction,array$contacts,?string$business):?string{
    $type=$message['type']??null;$timestamp=$message['timestamp']??null;
    if(!waCaptureProtocol('identifier',[$message['id']??null,512])||!is_string($type)||!preg_match('/\A[a-z_]{1,40}\z/',$type))return'MESSAGE_ID_OR_TYPE_INVALID';
    if((!is_string($timestamp)&&!is_int($timestamp))||!preg_match('/\A[0-9]{1,10}\z/',(string)$timestamp)
        ||(int)$timestamp<1||(int)$timestamp>min(2147483647,time()+86400))return'MESSAGE_TIMESTAMP_INVALID';
    $encoded=json_encode($message);if(!is_string($encoded)||strlen($encoded)>262144)return'MESSAGE_SIZE_INVALID';
    if($type==='text'){
        if(!is_array($message['text']??null)||!is_string($message['text']['body']??null))return'TEXT_BODY_INVALID';
        if(strlen($message['text']['body'])>65536)return'TEXT_BODY_TOO_LARGE';
    }elseif(!waCaptureProtocol('validContent',[$message,$type]))return'MESSAGE_CONTENT_INVALID';
    $phoneKey=$direction==='inbound'?'from':'to';$userKey=$direction==='inbound'?'from_user_id':'to_user_id';
    $phone=waCaptureProtocol('phone',[$message[$phoneKey]??null]);$user=waCaptureProtocol('user',[$message[$userKey]??null]);
    if((array_key_exists($phoneKey,$message)&&$phone===null)||(array_key_exists($userKey,$message)&&$user===null)||($phone===null&&$user===null))return'PEER_IDENTITY_INVALID';
    if($business!==null&&$phone===$business)return'PEER_EQUALS_BUSINESS_PHONE';
    if($direction==='outbound'&&array_key_exists('from',$message)){
        $sender=waCaptureProtocol('phone',[$message['from']]);if($sender===null||($business!==null&&$sender!==$business))return'OUTBOUND_SENDER_INVALID';
    }
    if($direction==='inbound'&&array_key_exists('to',$message)){
        $recipient=waCaptureProtocol('phone',[$message['to']]);if($recipient===null||($business!==null&&$recipient!==$business))return'INBOUND_RECIPIENT_INVALID';
    }
    foreach($contacts as$contact){
        if(!(($phone!==null&&$contact['phone']===$phone)||($user!==null&&$contact['user']===$user)))continue;
        if(($phone!==null&&$contact['phone']!==null&&$phone!==$contact['phone'])||($user!==null&&$contact['user']!==null&&$user!==$contact['user']))return'CONTACT_IDENTITY_CONFLICT';
        $phone=$phone??$contact['phone'];$user=$user??$contact['user'];
    }
    return$business!==null&&$phone===$business?'CONTACT_PEER_EQUALS_BUSINESS_PHONE':null;
}
/** Fixed categories only; supplied field/type/identity values never become keys. */
function waCaptureInspect(array$payload):array{
    $waba=\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID;$phone=\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID;
    $report=\App\Support\WhatsAppInboxProtocol::report($payload,$waba,$phone);
    if(!is_array($report['messages']??null))throw new RuntimeException('NORMALIZER_RESULT_INVALID');
    $out=['normalizer'=>['messages'=>count($report['messages'])],'envelope'=>['object_matches'=>($payload['object']??null)==='whatsapp_business_account',
        'entry_is_list'=>waCaptureProtocol('isList',[$payload['entry']??null])],'scoped_fields'=>[],'message_kinds'=>[],
        'error_codes'=>[],'status_values'=>[],'status_value_types'=>[],'status_id_valid'=>[],
        'rejections'=>[],'checks'=>[],'entries_seen'=>0,'changes_seen'=>0,'records_seen'=>0,'details_complete'=>true];
    foreach(['quarantined_count','ignored_count','status_count']as$key){
        if(!is_int($report[$key]??null)||$report[$key]<0)throw new RuntimeException('NORMALIZER_RESULT_INVALID');$out['normalizer'][$key]=$report[$key];
    }
    $reject=static function(string$reason)use(&$out){waCaptureIncrement($out['rejections'],$reason);};
    if(!$out['envelope']['object_matches']){$reject('OBJECT_NOT_WHATSAPP');return$out;}
    if(!$out['envelope']['entry_is_list']){$reject('ENTRY_LIST_INVALID');return$out;}
    if(count($payload['entry'])>100)$out['details_complete']=false;
    foreach(array_slice($payload['entry'],0,100)as$entry){
        $out['entries_seen']++;
        if(!is_array($entry)){$reject('ENTRY_RECORD_INVALID');continue;}
        if(!is_string($entry['id']??null)||!preg_match('/\A[0-9]+\z/',$entry['id'])){$reject('ENTRY_WABA_ID_INVALID');continue;}
        $scope=$entry['id']===$waba;$out['checks'][]=['waba_matches'=>$scope];if(!$scope)continue;
        if(array_key_exists('standby',$entry))$reject('UNSCOPED_ENTRY_STANDBY');
        if(!waCaptureProtocol('isList',[$entry['changes']??null])){$reject('CHANGES_LIST_INVALID');continue;}
        if(count($entry['changes'])>100)$out['details_complete']=false;
        foreach(array_slice($entry['changes'],0,100)as$change){
            $out['changes_seen']++;
            if(!is_array($change)||!is_string($change['field']??null)){$reject('CHANGE_FIELD_INVALID');continue;}
            $field=$change['field'];if(!in_array($field,['messages','standby','smb_message_echoes'],true)){waCaptureIncrement($out['scoped_fields'],'OTHER');continue;}
            $value=$change['value']??null;
            if(!is_array($value)||($value['messaging_product']??null)!=='whatsapp'||!is_array($value['metadata']??null)){$reject('VALUE_ENVELOPE_INVALID');continue;}
            $nativePhone=$value['metadata']['phone_number_id']??null;
            if(!is_string($nativePhone)||!preg_match('/\A[0-9]+\z/',$nativePhone)){$reject('METADATA_PHONE_ID_INVALID');continue;}
            $scope=$nativePhone===$phone;$out['checks'][]=['phone_scope_matches'=>$scope];if(!$scope)continue;
            waCaptureIncrement($out['scoped_fields'],$field);$channel=$value;$nested=$field==='standby'&&array_key_exists('standby',$value);
            if($nested){
                if(!is_array($value['standby'])){$reject('NESTED_STANDBY_INVALID');continue;}
                if(array_intersect(['messages','message_echoes','statuses'],array_keys($value))){$reject('NESTED_STANDBY_MIXED_CHANNEL');continue;}
                $channel=$value['standby'];
                if((array_key_exists('metadata',$channel)&&$channel['metadata']!==$value['metadata'])
                    ||(array_key_exists('messaging_product',$channel)&&$channel['messaging_product']!=='whatsapp')){$reject('NESTED_STANDBY_SCOPE_CONFLICT');continue;}
            }elseif(array_key_exists('standby',$value)){$reject('UNEXPECTED_STANDBY_ENVELOPE');continue;}
            $wrapped=false;foreach(is_array($channel['message_echoes']??null)?$channel['message_echoes']:[]as$record)
                if(is_array($record)&&array_key_exists('message',$record))$wrapped=true;
            if($wrapped&&(!$nested||array_diff(array_keys($value),['messaging_product','metadata','standby','contacts'])
                ||array_diff(array_keys($channel),['message_echoes','metadata','messaging_product','contacts'])
                ||(array_key_exists('contacts',$channel)&&array_key_exists('contacts',$value)&&$channel['contacts']!==$value['contacts']))){$reject('WRAPPED_ECHO_ENVELOPE_INVALID');continue;}
            $business=waCaptureProtocol('phone',[$value['metadata']['display_phone_number']??null]);
            $contacts=array_key_exists('contacts',$channel)?$channel['contacts']:(array_key_exists('contacts',$value)?$value['contacts']:[]);
            if(!waCaptureProtocol('isList',[$contacts])){$reject('CONTACTS_LIST_INVALID');continue;}
            $normalized=[];$badContact=false;
            foreach($contacts as$contact){
                if(!is_array($contact)){$reject('CONTACT_RECORD_INVALID');$badContact=true;break;}
                $cp=waCaptureProtocol('phone',[$contact['wa_id']??null]);$cu=waCaptureProtocol('user',[$contact['user_id']??null]);
                if((array_key_exists('wa_id',$contact)&&$cp===null)||(array_key_exists('user_id',$contact)&&$cu===null)){$reject('CONTACT_IDENTITY_INVALID');$badContact=true;break;}
                if(array_key_exists('profile',$contact)&&!is_array($contact['profile'])){$reject('CONTACT_PROFILE_INVALID');$badContact=true;break;}
                $name=$contact['profile']['name']??null;
                if($name!==null&&(!is_string($name)||strlen($name)>512)){$reject('CONTACT_NAME_INVALID');$badContact=true;break;}
                $normalized[]=['phone'=>$cp,'user'=>$cu,'name'=>$name];
            }
            if($badContact)continue;$seen=false;
            foreach(['messages','message_echoes','statuses']as$key){
                if(!array_key_exists($key,$channel))continue;$seen=true;
                if(!waCaptureProtocol('isList',[$channel[$key]])){$reject('CHANNEL_RECORD_LIST_INVALID');continue;}
                if($key==='statuses'&&$field==='smb_message_echoes'){$reject('ECHO_STATUS_CHANNEL_INVALID');continue;}
                if($key!=='statuses'&&(($field==='messages'&&$key!=='messages')||($field==='smb_message_echoes'&&$key!=='message_echoes'))){$reject('SOURCE_RECORD_CHANNEL_MISMATCH');continue;}
                if($key!=='statuses'&&$channel[$key]===[]){$reject('MESSAGE_LIST_EMPTY');continue;}
                if(count($channel[$key])>100)$out['details_complete']=false;
                foreach(array_slice($channel[$key],0,100)as$record){
                    if(++$out['records_seen']>300){$out['details_complete']=false;break 4;}
                    if(!is_array($record)){$reject('CHANNEL_RECORD_INVALID');continue;}
                    foreach(is_array($record['errors']??null)?$record['errors']:[]as$error){
                        $code=is_array($error)?waCaptureInteger($error['code']??null):null;
                        if($code!==null&&$code<=2147483647)waCaptureIncrement($out['error_codes'],(string)$code);
                    }
                    if($key==='statuses'){
                        $status=$record['status']??null;
                        waCaptureIncrement($out['status_values'],in_array($status,['sent','delivered','read','failed','deleted','played'],true)?$status:'OTHER');
                        waCaptureIncrement($out['status_value_types'],gettype($status));
                        waCaptureIncrement($out['status_id_valid'],waCaptureProtocol('identifier',[$record['id']??null,512])?'VALID':'INVALID');
                        $played=$field==='messages'&&!array_key_exists('messages',$channel)&&!array_key_exists('message_echoes',$channel);
                        if(!waCaptureProtocol('identifier',[$record['id']??null,512])||!(in_array($record['status']??null,['sent','delivered','read','failed','deleted'],true)||($played&&($record['status']??null)==='played')))$reject('STATUS_RECORD_INVALID');
                        continue;
                    }
                    if(array_key_exists('message',$record)){
                        $record=$nested&&$key==='message_echoes'?waCaptureProtocol('standbyEcho',[$record,$business]):null;
                        if($record===null){$reject('WRAPPED_ECHO_RECORD_INVALID');continue;}
                    }
                    waCaptureIncrement($out['message_kinds'],waCaptureKind($record['type']??null));
                    $direction=$key==='message_echoes'?'outbound':'inbound';
                    $parsed=waCaptureProtocol('message',[$record,$direction,$field,$normalized,$business]);
                    if($parsed===null)$reject(waCaptureMessageReason($record,$direction,$normalized,$business)??'MESSAGE_PARSE_UNCLASSIFIED');
                }
            }
            if(!$seen)$reject('SUPPORTED_CHANNEL_MISSING');
        }
    }
    if($out['normalizer']['quarantined_count']>0&&$out['rejections']===[])$reject('NORMALIZER_REJECTION_UNCLASSIFIED');
    return$out;
}
function waCaptureRows(int$eventFloor):array{
    $db=\Illuminate\Support\Facades\DB::class;
    $query=$db::table('whatsapp_inbox_ingestion_failures as f')->leftJoin('whatsapp_webhook_events as e','e.id','=','f.event_id')->where('f.event_id','>',$eventFloor);
    $count=(int)(clone$query)->count();if($count>10)throw new RuntimeException('FORWARD_FAILURE_LIMIT');
    $rows=(clone$query)->orderBy('f.event_id')->limit(11)->select('f.event_id','f.reason','f.attempts','f.last_attempted_at',
        'e.id as captured_event_id','e.received_at','e.processed_at','e.payload_hash')
        ->selectRaw('CASE WHEN LENGTH(e.payload)<=8388608 THEN e.payload ELSE NULL END AS payload')->get()->all();
    if(count($rows)!==$count)throw new RuntimeException('FAILURE_METADATA_CHANGED');return$rows;
}
function waCaptureSourceProfile():array{
    $out=[];
    foreach(['TakeawayAccess','TakeawayCatalog','WhatsAppInboxConsumer','PhoneDelivery','PosServiceTicket','BranchCustomers','PosServicePhone']as$name){
        $path='app/Services/Dashboard/'.$name.'.php';$row=['status'=>'UNAVAILABLE','blob'=>null];
        if(!is_link($path)&&is_file($path)&&realpath($path)===getcwd().'/'.$path){
            $before=stat($path);
            if(is_array($before)&&($before['mode']&0170000)===0100000&&$before['size']<=8388608){
                $bytes=file_get_contents($path);clearstatcache(true,$path);$after=stat($path);
                $signature=static function($metadata){return is_array($metadata)?array_intersect_key($metadata,array_flip(['dev','ino','mode','uid','gid','size','mtime','ctime'])):null;};
                if(is_string($bytes)&&$signature($before)===$signature($after))$row=['status'=>'READ_READY','blob'=>sha1('blob '.strlen($bytes)."\0".$bytes)];
            }
        }
        $out[$name]=$row;
    }
    return$out;
}
/** Native-id lookup only. Equality flags do not authorize a retry or identity merge. */
function waCaptureCompare(array$payload):array{
    $db=\Illuminate\Support\Facades\DB::class;$crypt=\Illuminate\Support\Facades\Crypt::class;
    $access=\App\Services\Dashboard\WhatsAppInboxAccess::class;$scope=$access::WABA_ID.':'.$access::PHONE_ID.':';
    $messages=\App\Support\WhatsAppInboxProtocol::report($payload,$access::WABA_ID,$access::PHONE_ID)['messages'];
    $out=['scope'=>'EXACT_NATIVE_KEY_EQUALITY_OBSERVATION_NOT_RETRY_AUTHORITY','complete'=>count($messages)<=10,'messages'=>[]];
    foreach(array_slice($messages,0,10)as$ordinal=>$message){
        $messageKey=hash('sha256','whatsapp-message-v1:'.$scope.$message['message_id']);
        $peerHash=hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$message['peer_identity'],$crypt::getKey());
        $existing=$db::table('whatsapp_inbox_messages')->where('message_key',$messageKey)
            ->select('id','conversation_id','direction','type','source')
            ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
        $row=['ordinal'=>$ordinal+1,'existing_message'=>$existing!==null];
        if($existing){
            $thread=$db::table('whatsapp_inbox_conversations')->where('id',$existing->conversation_id)->first(['id','waba_id','phone_number_id','peer_hash']);
            $row+=['existing_database_id'=>(int)$existing->id,'existing_conversation_id'=>(int)$existing->conversation_id,
                'scope_matches'=>$thread!==null&&$thread->waba_id===$access::WABA_ID&&$thread->phone_number_id===$access::PHONE_ID,
                'peer_hash_matches'=>$thread!==null&&hash_equals((string)$thread->peer_hash,$peerHash),
                'direction_matches'=>$existing->direction===$message['direction'],'type_matches'=>$existing->type===$message['type'],
                'dashboard_receipt_proof_required'=>$existing->source==='dashboard_api'];
            if($existing->source!=='dashboard_api'){
                try{
                    if(!is_string($existing->content)||strlen($existing->content)>8388608)throw new RuntimeException('STORED_SIZE_INVALID');
                    $plain=$crypt::decryptString($existing->content);if(strlen($plain)>4194304)throw new RuntimeException('STORED_SIZE_INVALID');
                    $saved=json_decode($plain,true,512,JSON_THROW_ON_ERROR);if(!is_array($saved))throw new RuntimeException('STORED_DTO_INVALID');
                    $row['stored_readable']=true;$row['dto_mismatch_fields']=[];$row['content_mismatch_fields']=[];
                    foreach(['message_id'=>'MESSAGE_ID','peer_identity'=>'PEER_IDENTITY','direction'=>'DIRECTION','type'=>'TYPE','text'=>'TEXT','sent_at'=>'SENT_AT']as$key=>$label)
                        if(!array_key_exists($key,$saved)||$saved[$key]!==$message[$key])$row['dto_mismatch_fields'][]=$label;
                    $fields=['errors','text','image','video','document','audio','sticker','location','contacts','interactive','button','reaction','order','system'];
                    foreach(array_unique(array_merge([$message['type']],$fields))as$field)
                        if(waCaptureProtocol('canonical',[$saved['content']['message'][$field]??null])!==waCaptureProtocol('canonical',[$message['content']['message'][$field]??null]))
                            $row['content_mismatch_fields'][]=in_array($field,$fields,true)?strtoupper($field):'OTHER';
                    $row['hydration_proof_required']=array_key_exists('hydration',$saved['content']??[]);
                }catch(Throwable$error){$row['stored_readable']=false;$row['error']=waCaptureError($error);}
            }
        }else{
            $thread=$db::table('whatsapp_inbox_conversations')->where('waba_id',$access::WABA_ID)->where('phone_number_id',$access::PHONE_ID)->where('peer_hash',$peerHash)
                ->select('id')->selectRaw('CASE WHEN LENGTH(customer)<=8388608 THEN customer ELSE NULL END AS customer')->first();
            $row['exact_peer_conversation_present']=$thread!==null;
            if($thread){
                try{
                    if(!is_string($thread->customer)||strlen($thread->customer)>8388608)throw new RuntimeException('STORED_SIZE_INVALID');
                    $plain=$crypt::decryptString($thread->customer);if(strlen($plain)>4194304)throw new RuntimeException('STORED_SIZE_INVALID');
                    $customer=json_decode($plain,true,512,JSON_THROW_ON_ERROR);if(!is_array($customer))throw new RuntimeException('STORED_DTO_INVALID');
                    $row['customer_identity_readable']=true;$row['customer_identity_mismatch_fields']=[];
                    foreach(['phone'=>'peer_phone','user_id'=>'peer_user_id']as$key=>$incoming)
                        if(($customer[$key]??null)!==null&&$message[$incoming]!==null&&$customer[$key]!==$message[$incoming])$row['customer_identity_mismatch_fields'][]=strtoupper($key);
                }catch(Throwable$error){$row['customer_identity_readable']=false;$row['error']=waCaptureError($error);}
            }
        }
        $out['messages'][]=$row;
    }
    return$out;
}
try{
    $options=waCaptureArguments($argv);$conversation=$options['conversation'];
    $stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException('OWNER_INVALID');
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/WhatsAppCustomerOrderConfirmation.php'=>'63cd0fcd2d9056fb0e5732ddc22f4423ee349da7',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'63c1e6e7e48c500227e0285707611b268e50b3ad',
        'app/Support/MetaAiSchedule.php'=>'667ffd6530444ef00dc6a05f76a5700d217fff80',
        'app/Services/Dashboard/MetaAiControl.php'=>'de44620835b22666417fc37752ab944d4419df36',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
    ];
    $protocols=['c64f8cf89437bb7822fc767202f7cf5112bfd7fd','f2eec9099f9a27915cda50b7c8e267efb1f0b030'];$sources=waCaptureSources($pins,$protocols);
    $out['observed_public_sources']=waCaptureSourceProfile();
    $out['observed_source_scope']='COMPATIBILITY_METADATA_ONLY_NOT_ALLOWED_PINS_OR_EXECUTION';
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();$stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');$out['read_only_session']=true;
    $stage='SCOPE';$access=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    if(!$db::table('whatsapp_inbox_conversations')->where('id',$conversation)->where('waba_id',$access::WABA_ID)->where('phone_number_id',$access::PHONE_ID)->exists())throw new RuntimeException('CONVERSATION_SCOPE_MISMATCH');
    $workflow=$app->make(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);$control=$app->make(\App\Services\Dashboard\MetaAiControl::class);
    $stage='CONTROL';$state=$control->state();$gate=waCaptureCall($workflow,'controlGate',[$conversation]);$token=waCaptureCall($workflow,'controlToken',[$conversation]);
    if(($state['available']??null)!==true||($state['policy']['enabled']??null)!==true||($state['policy']['mode']??null)!=='ANALYZE_ONLY'
        ||($state['revision']??null)!==$options['revision']||!is_array($token)||($gate['floor']['event_floor']??null)!==$options['event_floor'])throw new RuntimeException('CONTROL_EXPECTATION_CHANGED');
    $stage='NATIVE_BOUNDS';$activation=waCaptureCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    $ceiling=waCaptureCall($workflow,'ceiling',[$conversation]);
    if(!is_int($activation)||$ceiling!==$options['ceiling'])throw new RuntimeException('NATIVE_EXPECTATION_CHANGED');
    $floor=waCaptureCall($workflow,'customerReceiptFloor',[$conversation,max($activation,(int)waCaptureCall($workflow,'dispatchedCeiling',[$conversation])),$ceiling]);
    if($floor!==$options['floor'])throw new RuntimeException('NATIVE_EXPECTATION_CHANGED');
    $snapshot=waCaptureCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    if(($snapshot['complete']??null)!==true||!is_string($snapshot['hash']??null))throw new RuntimeException('SNAPSHOT_UNAVAILABLE');
    $scan=$db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first();
    $out+=['conversation_id'=>$conversation,'expected_floor'=>$floor,'expected_ceiling'=>$ceiling,
        'expected_control_revision'=>$options['revision'],'expected_event_floor'=>$options['event_floor'],'read_at_utc'=>gmdate('Y-m-d H:i:s')];
    $stage='FAILURES';$rows=waCaptureRows($options['event_floor']);$signature=hash('sha256',serialize($rows));
    $out['forward_failures_count']=count($rows);$out['forward_failures_complete']=true;$out['events']=[];$complete=true;
    foreach($rows as$row){
        $event=['event_id'=>(int)$row->event_id,'received_at_utc'=>waCaptureTime($row->received_at??null),
            'processed_at_utc'=>waCaptureTime($row->processed_at??null),'reason'=>in_array($row->reason,['INVALID_EVENT','CONFLICTING_MESSAGE'],true)?$row->reason:'OTHER'];
        try{
            if($row->captured_event_id===null)throw new RuntimeException('RAW_EVENT_MISSING');
            if(!is_string($row->payload)||strlen($row->payload)>8388608)throw new RuntimeException('RAW_PAYLOAD_SIZE_INVALID');
            $plain=\Illuminate\Support\Facades\Crypt::decryptString($row->payload);
            if(strlen($plain)>4194304)throw new RuntimeException('RAW_PAYLOAD_SIZE_INVALID');
            $event['payload_hash_valid']=is_string($row->payload_hash)&&preg_match('/\A[a-f0-9]{64}\z/D',$row->payload_hash)&&hash_equals($row->payload_hash,hash('sha256',$plain));
            if(!$event['payload_hash_valid'])throw new RuntimeException('RAW_PAYLOAD_HASH_MISMATCH');
            $payload=json_decode($plain,true,512,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new RuntimeException('RAW_PAYLOAD_ARRAY_REQUIRED');
            $event['classification']=waCaptureInspect($payload);$event['native_comparisons']=waCaptureCompare($payload);
            $event['classification_scope']='PINNED_PROTOCOL_AND_EQUALITY_OBSERVATIONS_NOT_CONSUMER_RETRY_AUTHORITY';
            $event['status']='READ_READY';$complete=$complete&&$event['classification']['details_complete']&&$event['native_comparisons']['complete'];
        }catch(Throwable$error){
            $event['status']='UNREADABLE';$event['error']=waCaptureError($error);
            $allowed=['RAW_EVENT_MISSING','RAW_PAYLOAD_SIZE_INVALID','RAW_PAYLOAD_HASH_MISMATCH','RAW_PAYLOAD_ARRAY_REQUIRED','NORMALIZER_RESULT_INVALID'];
            $event['stage']=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'PAYLOAD_CLASSIFICATION';$complete=false;
        }
        $out['events'][]=$event;
    }
    $stage='CURRENTNESS';$current=waCaptureCall($workflow,'snapshot',[$conversation,$floor,$ceiling]);
    $out['snapshot_unchanged_during_read']=($current['complete']??null)===true&&($current['hash']??null)===$snapshot['hash']&&waCaptureCall($workflow,'ceiling',[$conversation])===$ceiling;
    $newActivation=waCaptureCall($workflow,'activationBoundary',['activation_message_id',$conversation]);
    $out['activation_floor_unchanged_during_read']=$newActivation===$activation&&waCaptureCall($workflow,'customerReceiptFloor',[$conversation,
        max((int)$newActivation,(int)waCaptureCall($workflow,'dispatchedCeiling',[$conversation])),$ceiling])===$floor;
    $laterState=$control->state();$out['control_unchanged_during_read']=($laterState['revision']??null)===$options['revision']
        &&($laterState['policy']??null)===($state['policy']??null)&&waCaptureCall($workflow,'controlToken',[$conversation])===$token;
    $out['scan_unchanged_during_read']=serialize($db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->first())===serialize($scan);
    $out['failure_metadata_unchanged_during_read']=hash_equals($signature,hash('sha256',serialize(waCaptureRows($options['event_floor']))));
    $out['observed_public_sources_unchanged_during_read']=waCaptureSourceProfile()===$out['observed_public_sources'];
    $stage='FINAL_SOURCE_GUARD';waCaptureSources($pins,$protocols,$sources);$out['source_unchanged_during_read']=true;
    if($connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $stable=$out['snapshot_unchanged_during_read']&&$out['activation_floor_unchanged_during_read']&&$out['control_unchanged_during_read']
        &&$out['scan_unchanged_during_read']&&$out['failure_metadata_unchanged_during_read']&&$out['observed_public_sources_unchanged_during_read'];
    $out['status']=!$stable?'OBSERVATION_CHANGED':($complete?'READ_READY':'PARTIAL');
}catch(Throwable$error){
    $allowed=['ARGUMENTS_INVALID','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED','CONVERSATION_SCOPE_MISMATCH',
        'CONTROL_EXPECTATION_CHANGED','NATIVE_EXPECTATION_CHANGED','SNAPSHOT_UNAVAILABLE','FORWARD_FAILURE_LIMIT','FAILURE_METADATA_CHANGED'];
    $out['status']='READ_BLOCKED';$out['stage']=$stage;$out['reason']=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'OBSERVATION_ERROR';
    if($out['reason']==='OBSERVATION_ERROR')$out['error']=waCaptureError($error);
}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(($out['status']??null)==='READ_READY'?0:1);
