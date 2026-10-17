<?php
// Exact stored-phone observation. Never merge peers, infer an identity from
// message timing, or invoke projection repairs, workers, replies or orders.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_WHATSAPP_CUSTOMER_CONFIRMATION_LOCATE','writes'=>0,'ai_calls'=>0,
    'message_sends'=>0,'erp_writes'=>0,'graph_gets'=>0,'worker_actions'=>0,
    'scope'=>'EXACT_STORED_CUSTOMER_PHONE_NOT_PEER_MERGE_OR_ORDER_AUTHORITY'];$stage='ARGUMENTS';
function waLocateArgument(array$args):string{
    if(count($args)!==3||$args[1]!=='--peer-phone'||!is_string($args[2])||!preg_match('/\A[1-9][0-9]{7,14}\z/D',$args[2]))throw new RuntimeException('ARGUMENTS_INVALID');return$args[2];
}
function waLocateKey($value):string{
    if(!is_string($value))throw new RuntimeException('IDENTITY_KEY_UNAVAILABLE');
    if(str_starts_with($value,'base64:'))$value=base64_decode(substr($value,7),true);
    if(!is_string($value)||strlen($value)<16||strlen($value)>4096)throw new RuntimeException('IDENTITY_KEY_UNAVAILABLE');return$value;
}
function waLocateTime($value):?string{
    if(!is_string($value))return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return$d&&$d->format('Y-m-d H:i:s')===$value&&substr($value,0,4)!=='0000'?$value:null;
}
function waLocateCode($value):?string{return is_string($value)&&preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D',$value)?$value:null;}
function waLocateKind($value):string{return in_array($value,['text','image','video','document','audio','sticker','location','contacts','interactive','button','reaction','order','system','referral','edit','revoke','unsupported','unknown'],true)?$value:'OTHER';}
function waLocateSources(array$pins,?array$expected=null):array{
    $seen=[];foreach($pins as$path=>$pin){
        if(is_link($path)||!is_file($path)||realpath($path)!==getcwd().'/'.$path||filesize($path)>1048576)throw new RuntimeException('SOURCE_CHANGED');
        $bytes=file_get_contents($path);if(!is_string($bytes))throw new RuntimeException('SOURCE_CHANGED');$actual=sha1('blob '.strlen($bytes)."\0".$bytes);
        if($actual!==$pin||$expected!==null&&($expected[$path]??null)!==$actual)throw new RuntimeException('SOURCE_CHANGED');$seen[$path]=$actual;
    }return$seen;
}
/** Opaque user IDs take precedence exactly as in the native consumer. */
function waLocateCustomer(array$customer,string$peerHash,string$key,string$wanted,string$scope):array{
    $phone=$customer['phone']??null;$user=$customer['user_id']??null;
    if($phone!==null&&(!is_string($phone)||!preg_match('/\A[0-9]{6,20}\z/D',$phone)))return['valid'=>false];
    if($user!==null&&(!is_string($user)||$user===''||strlen($user)>191||preg_match('/[\x00-\x20\x7f]/',$user)))return['valid'=>false];
    $identity=$user!==null?'user:'.$user:($phone!==null?'phone:'.$phone:null);
    if($identity===null||!preg_match('/\A[a-f0-9]{64}\z/D',$peerHash)
        ||!hash_equals($peerHash,hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$identity,$key)))return['valid'=>false];
    return['valid'=>true,'phone_match'=>$phone!==null&&hash_equals($wanted,$phone),'namespace'=>$user!==null?'USER':'PHONE'];
}
function waLocateThreads(bool$customers):array{
    $db=\Illuminate\Support\Facades\DB::class;$a=\App\Services\Dashboard\WhatsAppInboxAccess::class;$rows=[];$before=null;
    while(count($rows)<2001){
        $limit=min(50,2001-count($rows));$q=$db::table('whatsapp_inbox_conversations')->where('waba_id',$a::WABA_ID)->where('phone_number_id',$a::PHONE_ID);
        if($before!==null)$q->where('id','<',$before);
        $q->orderByDesc('id')->limit($limit)->select('id','waba_id','phone_number_id','peer_hash','updated_at')
            ->selectRaw('SHA2(customer,256) AS customer_digest');
        if($customers)$q->selectRaw('CASE WHEN LENGTH(customer)<=65536 THEN customer ELSE NULL END AS customer');
        $page=$q->get()->all();if(count($page)>$limit)throw new RuntimeException('THREAD_RESULT_INVALID');
        foreach($page as$row){
            $id=filter_var($row->id??null,FILTER_VALIDATE_INT);
            if($id===false||$id<1||$before!==null&&$id>=$before||($row->waba_id??null)!==$a::WABA_ID||($row->phone_number_id??null)!==$a::PHONE_ID)throw new RuntimeException('THREAD_RESULT_INVALID');
            $rows[]=$row;$before=$id;
        }if(count($page)<$limit)break;
    }return$rows;
}
function waLocateThreadSignature(array$rows):string{
    $values=[];foreach($rows as$r)$values[]=[(int)$r->id,$r->waba_id,$r->phone_number_id,$r->peer_hash,$r->customer_digest,$r->updated_at];return hash('sha256',serialize($values));
}
function waLocateNative(int$conversation,bool$content):?object{
    $q=\Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')->whereIn('source',['messages','standby'])
        ->orderByDesc('sent_at')->orderByDesc('id')->select('id','conversation_id','message_key','direction','source','type','sent_at','created_at','updated_at')
        ->selectRaw('SHA2(content,256) AS content_digest');
    if($content)$q->selectRaw('CASE WHEN LENGTH(content)<=1048576 THEN content ELSE NULL END AS content');return$q->first();
}
function waLocateNativeSignature(?object$row):string{
    if($row===null)return hash('sha256','NONE');$value=(array)$row;unset($value['content']);return hash('sha256',serialize($value));
}
function waLocateDraft(int$conversation):?object{
    return \Illuminate\Support\Facades\DB::table('whatsapp_order_drafts')->where('conversation_id',$conversation)->orderByDesc('id')
        ->first(['id','status','reason','evidence_ceiling','ticket_id','assigned_branch','updated_at']);
}
function waLocateDraftPublic(?object$row):?array{
    if($row===null)return null;$ticket=filter_var($row->ticket_id??null,FILTER_VALIDATE_INT);
    return['id'=>(int)$row->id,'status'=>in_array($row->status,['REVIEW','READY','NONE','CANCELLED','DISPATCHED'],true)?$row->status:'OTHER',
        'reason'=>waLocateCode($row->reason??null),'ceiling'=>(int)$row->evidence_ceiling,
        'ticket_id'=>$ticket!==false&&$ticket>0?$ticket:null,
        'branch'=>is_string($row->assigned_branch??null)&&preg_match('/\A(?:f|gs):[1-9][0-9]{0,18}\z/D',$row->assigned_branch)?$row->assigned_branch:null];
}
function waLocateLatestScoped():?object{
    $a=\App\Services\Dashboard\WhatsAppInboxAccess::class;
    return \Illuminate\Support\Facades\DB::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id')
        ->where('c.waba_id',$a::WABA_ID)->where('c.phone_number_id',$a::PHONE_ID)->orderByDesc('m.sent_at')->orderByDesc('m.id')
        ->first(['m.id','m.sent_at','m.created_at']);
}
try{
    $wanted=waLocateArgument($argv);$stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException('OWNER_INVALID');
    $stage='SOURCE_GUARD';$pins=[
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
    ];$sources=waLocateSources($pins);
    $stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$connection=$db::connection();$stage='READ_ONLY_SESSION';
    if($connection->getDriverName()!=='mysql'||$connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');$out['read_only_session']=true;
    $stage='IDENTITY_KEY';$key=waLocateKey(config('app.key'));$a=\App\Services\Dashboard\WhatsAppInboxAccess::class;$scope=$a::WABA_ID.':'.$a::PHONE_ID.':';
    $stage='THREADS';$rows=waLocateThreads(true);$signature=waLocateThreadSignature($rows);$truncated=count($rows)>2000;
    $out+=['conversation_limit'=>2000,'conversations_examined'=>min(2000,count($rows)),'conversation_scan_truncated'=>$truncated,
        'unreadable_customers'=>0,'invalid_customer_identities'=>0,'exact_phone_matches'=>0,'candidate_limit'=>10,'candidates'=>[]];$private=[];$projectionFailures=0;
    foreach(array_slice($rows,0,2000)as$row){
        try{
            if(!is_string($row->customer)||strlen($row->customer)>65536)throw new RuntimeException('UNREADABLE');
            $plain=\Illuminate\Support\Facades\Crypt::decryptString($row->customer);if(strlen($plain)>32768)throw new RuntimeException('UNREADABLE');
            $customer=json_decode($plain,true,16,JSON_THROW_ON_ERROR);if(!is_array($customer))throw new RuntimeException('UNREADABLE');
        }catch(Throwable$error){$out['unreadable_customers']++;continue;}
        $identity=waLocateCustomer($customer,(string)$row->peer_hash,$key,$wanted,$scope);
        if(!$identity['valid']){$out['invalid_customer_identities']++;continue;}if(!$identity['phone_match'])continue;
        $out['exact_phone_matches']++;if(count($out['candidates'])>=10)continue;
        $cid=(int)$row->id;$native=waLocateNative($cid,true);$draft=waLocateDraft($cid);$nativeValid=null;$nativePhone=null;
        if($native!==null){
            try{$projected=(new \App\Services\Dashboard\WhatsAppInboxMessage())->projected($row,$native);
                $nativeValid=$projected['direction']==='inbound'&&$projected['native_message_key']===$native->message_key;
                $phone=$projected['peer_phone']??null;$nativePhone=is_string($phone)?hash_equals($wanted,$phone):null;
                if(!$nativeValid)$projectionFailures++;
            }catch(Throwable$error){$nativeValid=false;$projectionFailures++;}
        }
        $out['candidates'][]=['conversation_id'=>$cid,'namespace'=>'whatsapp','peer_identity_namespace'=>$identity['namespace'],
            'customer_phone_match'=>true,'latest_native_present'=>$native!==null,'native_projection_valid'=>$nativeValid,'native_phone_match'=>$nativePhone,
            'latest_native_id'=>$native===null?null:(int)$native->id,'latest_native_type'=>$native===null?null:waLocateKind($native->type),
            'latest_native_at_utc'=>$native===null?null:waLocateTime($native->sent_at),'latest_draft'=>waLocateDraftPublic($draft)];
        $private[$cid]=[waLocateNativeSignature($native),serialize($draft)];
    }
    $out['candidate_output_truncated']=$out['exact_phone_matches']>10;$out['invalid_native_projections']=$projectionFailures;
    $stage='LATEST_CAPTURE_METADATA';$latest=waLocateLatestScoped();$out['latest_scoped_message_at_utc']=$latest===null?null:waLocateTime($latest->sent_at);
    $out['latest_scoped_message_created_at_utc']=$latest===null?null:waLocateTime($latest->created_at);
    $stage='CURRENTNESS';$out['conversation_metadata_unchanged_during_read']=waLocateThreadSignature(waLocateThreads(false))===$signature;
    $stable=true;foreach($private as$cid=>[$nativeSignature,$draftSignature])
        if(waLocateNativeSignature(waLocateNative($cid,false))!==$nativeSignature||serialize(waLocateDraft($cid))!==$draftSignature)$stable=false;
    $out['candidate_metadata_unchanged_during_read']=$stable;$out['capture_metadata_unchanged_during_read']=serialize(waLocateLatestScoped())===serialize($latest);
    $stage='FINAL_SOURCE_GUARD';waLocateSources($pins,$sources);$out['source_unchanged_during_read']=true;
    if($connection->transactionLevel()!==0)throw new RuntimeException('READ_ONLY_SESSION_REQUIRED');
    $out['complete']=!$truncated&&$out['unreadable_customers']===0&&$out['invalid_customer_identities']===0&&!$out['candidate_output_truncated']&&$projectionFailures===0;
    $out['status']=!$out['conversation_metadata_unchanged_during_read']||!$stable||!$out['capture_metadata_unchanged_during_read']?'OBSERVATION_CHANGED':($out['complete']?'READ_READY':'PARTIAL');
}catch(Throwable$error){
    $allowed=['ARGUMENTS_INVALID','OWNER_INVALID','SOURCE_CHANGED','READ_ONLY_SESSION_REQUIRED','IDENTITY_KEY_UNAVAILABLE','THREAD_RESULT_INVALID'];
    $out['status']='READ_BLOCKED';$out['stage']=$stage;$out['reason']=in_array($error->getMessage(),$allowed,true)?$error->getMessage():'OBSERVATION_ERROR';
}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit(in_array($out['status'],['READ_READY','PARTIAL','OBSERVATION_CHANGED'],true)?0:1);
