<?php
// Read-only pre-AI observation. No process/analyze/project/quote/planner/send/ERP invocation.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');ob_start();
$out=['diagnostic'=>'READ_ONLY_META_AI_WORKER_GUARDS','writes'=>0,'ai_calls'=>0,'message_sends'=>0,'graph_gets'=>0,
    'scope'=>'OBSERVATION_NOT_ORIGINAL_FAILURE','candidate_scope'=>'RECENT_FIVE_PLUS_RETRY_FIVE_NOT_PROCESS_SELECTION'];
$stage='ARGUMENTS';$pins=[];
function metaWorkerReadCall($object,string$name,array$args=[]){$m=new ReflectionMethod($object,$name);$m->setAccessible(true);return$m->invokeArgs($object,$args);}
function metaWorkerReadCode($value,array$allowed,string$fallback='OTHER'):string{return is_string($value)&&in_array($value,$allowed,true)?$value:$fallback;}
function metaWorkerReadError(Throwable$error):array{
    $allowed=['ANALYSIS_RETRY_LIMIT','ANALYSIS_BACKOFF','ANALYSIS_BUSY','AI_CONTROL_BLOCKED','AI_CONTROL_CHANGED',
        'AI_REPLY_SCHEDULE_CLOSED','AUTO_NOT_CONFIGURED','STALE_TRANSCRIPT','EMPTY_CONVERSATION','TRANSCRIPT_SETTLING',
        'CAPTURE_PENDING','ACCEPTED_REPLIES_PENDING','CONVERSATION_NOT_FOUND','DRAFT_NOT_FOUND','ORDER_ACCESS_DENIED',
        'INVALID_INBOX_MESSAGE','INVALID_ENCRYPTED_ORDER','INVALID_META_NATIVE','INVALID_META_RECEIPT','META_SCOPE_UNAVAILABLE',
        'INVALID_META_BOUNDARY','INVALID_PROJECTION_REVISION','INVALID_PHONE','INVALID_RECIPIENT','STATE_CHANGED'];
    $classes=['RuntimeException','TypeError','Error','ErrorException','JsonException','PDOException',
        'Illuminate\\Database\\QueryException','Illuminate\\Contracts\\Encryption\\DecryptException',
        'Symfony\\Component\\HttpKernel\\Exception\\HttpException'];
    $result=['reason'=>metaWorkerReadCode($error->getMessage(),$allowed,'UNCLASSIFIED'),
        'exception_class'=>metaWorkerReadCode(get_class($error),$classes)];
    $info=$error instanceof PDOException?($error->errorInfo??null):null;
    if(is_array($info)){$result['sqlstate']=is_string($info[0]??null)&&preg_match('/\A[A-Z0-9]{5}\z/D',$info[0])?$info[0]:null;
        $result['driver_code']=is_numeric($info[1]??null)&&$info[1]>=0&&$info[1]<=99999?(int)$info[1]:null;}
    foreach(array_merge([['file'=>$error->getFile(),'line'=>$error->getLine()]],$error->getTrace())as$frame){
        $file=$frame['file']??null;$prefix=getcwd().'/';
        if(is_string($file)&&str_starts_with($file,$prefix)&&isset($GLOBALS['pins'][substr($file,strlen($prefix))])){
            $result['source_file']=substr($file,strlen($prefix));$result['source_line']=min(10000,max(0,(int)($frame['line']??0)));break;
        }
    }return$result;
}
function metaWorkerReadBound($db,string$table,int$id,int$floor,int$ceiling):array{
    $b=$db::table($table)->where('conversation_id',$id)->where('id','>',$floor)->where('id','<=',$ceiling)
        ->selectRaw('COUNT(*) AS n, COALESCE(SUM(LENGTH(content)),0) AS bytes')->first();
    return['messages'=>$b?min(1000000,max(0,(int)$b->n)):null,'within_limit'=>$b&&(int)$b->n<=60&&(int)$b->bytes<=4194304];
}
function metaWorkerReadFootprint($db,string$table,int$id,int$floor,int$ceiling):?string{
    if(!metaWorkerReadBound($db,$table,$id,$floor,$ceiling)['within_limit'])return null;
    $parts=[];foreach($db::table($table)->where('conversation_id',$id)->where('id','>',$floor)->where('id','<=',$ceiling)
        ->orderBy('id')->limit(61)->get(['id','updated_at','content'])as$row){
        if(!is_string($row->content))return null;$parts[]=[(int)$row->id,$row->updated_at,hash('sha256',$row->content)];
    }return hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR));
}
try{
    $channel='whatsapp';$conversation=null;
    for($i=1;$i<count($argv);$i++){
        if($argv[$i]==='--channel'&&isset($argv[$i+1])&&in_array($argv[$i+1],['whatsapp','messenger','instagram'],true)){$channel=$argv[++$i];continue;}
        if($argv[$i]==='--conversation'&&$conversation===null&&isset($argv[$i+1])&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$argv[$i+1])){$conversation=(int)$argv[++$i];continue;}
        throw new RuntimeException();
    }
    $stage='OWNER';$owner=posix_getpwnam('fasakha');
    if(!is_array($owner)||posix_geteuid()!==$owner['uid']||!chdir('/home/fasakha/public_html'))throw new RuntimeException();
    $stage='SOURCE_GUARD';
    $pins=[
        'app/Support/MetaAiSchedule.php'=>'d1acd2541253d4e7746af502592a04411cf11795',
        'app/Services/Dashboard/MetaBusinessAgent.php'=>'4b2ad29c21ea092af94ad3fa1bace90ad4dd4672',
        'app/Services/Dashboard/MetaAiControl.php'=>'de6ee70e06e95b2c7381215b52f698f45e86b34a',
        'app/Support/WhatsAppOrderDialogue.php'=>'994acc368929036b2ed82197ea4eb48aa5c65f39',
        'app/Support/WhatsAppOrderExtraction.php'=>'a44f791013642aeca4b4f50dd96ec96b59eda012',
        'app/Services/Dashboard/WhatsAppOrderConversationEvidence.php'=>'478d620d36d801b5fc38bab8e8be2afcfdf5dd8c',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php'=>'79970f2f9157ae718dce9004f1d3e3b4ccfd4714',
        'app/Services/Dashboard/WhatsAppOrderFollowupService.php'=>'e817b327ba1fdfd042288d20b5c4ba8bef00cc81',
        'app/Services/Dashboard/MetaOrderNativeEvidence.php'=>'f11710e1439867ba63571a690526496684120ef5',
        'app/Services/Dashboard/MetaOrderFollowupService.php'=>'d14a3c6e84624ee5b2d20997b7f11159b9101720',
        'app/Services/Dashboard/MetaMediaService.php'=>'cfde72fdeb12f308e15daa81ec0ae7ce196aef9e',
        'app/Services/Dashboard/MetaReplyService.php'=>'c4226df516aa5f8b59999a8979f9f2ab43cf0e28',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'a6d4477d4f50107dd60fa15aec30a02d863f4998',
        'app/Services/Dashboard/MetaOrderWorkflow.php'=>'337cd9db9c3493be0af00a32cf7692e8dca9268d',
        'app/Services/Dashboard/MetaInboxConsumer.php'=>'3b7e9cb9abfabc85c0b78199d0bf9d2565424f42',
        'app/Http/Controllers/Dashboard/MetaAiControlController.php'=>'5b21e15e8519f31994b4bc3145c118adb070d79e',
        'app/Console/Commands/ProcessMetaAiMessages.php'=>'9a953aceba5bd0c6cae0d4da60c1b23e42c9c0e7',
        'database/migrations/2026_10_15_000001_create_meta_ai_controls.php'=>'3c6c7ef4d5fbf437f49cc7c2bb94ce82595b77ae',
        'resources/views/admin/meta/ai_controls.blade.php'=>'351ccc3e1fc4fdf1a27b0867d010adf363ecd195',
        'public/js/dashboard-meta-ai-controls.js'=>'dc2c2fb7351ba5ffac3103a7cf2b8ac0d66c0bb3',
        'public/css/dashboard-meta-ai-controls.css'=>'d8c229626f8a74568ef168bd477625f2a94e9e4e',
        'public/css/dashboard-meta-inbox-viewport.css'=>'1d3c4c9360619c6e3d7ab6a23d4188de36e96732',
        'routes/meta_ai_controls.php'=>'8a89374a33002b88f7ed593d6aab5817aa3cf2a4',
        'resources/views/admin/meta/channels.blade.php'=>'c922fd431ca3df5b12f6aeef05c0885bc0da3049',
        'routes/meta_inbox.php'=>'2b5f992b8392be450a032d71d5a625a8aeed822e',
        'app/Services/Dashboard/WhatsAppReplyService.php'=>'f80378c7b61cc42c747bdceefd71b79c566de553',
        'app/Services/Dashboard/WhatsAppInboxAccess.php'=>'186789d42113e81818de8d82a2327e3ee9d8d0b5',
        'app/Services/Dashboard/WhatsAppInboxMessage.php'=>'bedd91db5266d83bdce84a04836e988518891728',
        'app/Services/Dashboard/MetaInboxAccess.php'=>'7faaba6645308c1997bae8182fb4b26dbc5e3b9a',
        'app/Support/MetaMessagingProtocol.php'=>'d5c6cb4dfeaa1d6c8467025338d7d92d9ce5b0ed',
        'app/Services/Dashboard/PosServiceTicket.php'=>'ef4c7b6f4a1276c122634aafabb2d510926aaeef',
        'app/Services/Dashboard/TakeawayAccess.php'=>'6b27b7ac2faff6fdaf952494f90f2d8378a12484',
        'app/Services/Dashboard/WhatsAppOrderSourceSelection.php'=>'03db3211b80da905f07415fd16f1e7a62e0395b1',
        'app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php'=>'4b571f7f4a5cef9257c414886dba515c06dae2a1',
        'app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php'=>'9e221ba052589c1ffe474958b636a77ff5817b7d',
        'app/Support/WhatsAppInboxProtocol.php'=>'c64f8cf89437bb7822fc767202f7cf5112bfd7fd',
    ];
    foreach($pins as$path=>$pin){$out['source_guard_file']=$path;
        if(is_link($path)||!is_file($path)||filesize($path)>1048576)throw new RuntimeException();
        $bytes=file_get_contents($path);if(!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin)throw new RuntimeException();}
    unset($out['source_guard_file']);$stage='BOOTSTRAP';require'vendor/autoload.php';$app=require'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db=\Illuminate\Support\Facades\DB::class;$schema=\Illuminate\Support\Facades\Schema::class;
    $stage='READ_ONLY_SESSION';$connection=$db::connection();$driver=$connection->getDriverName();
    if($driver==='mysql')$connection->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
    elseif($driver==='sqlite')$connection->getPdo()->exec('PRAGMA query_only = ON');else throw new RuntimeException();
    $out['read_only_session']=true;$out['driver']=$driver;
    $stage='CONTROL';$control=app(\App\Services\Dashboard\MetaAiControl::class);$gate=$control->processingGate($channel);
    $out['channel']=$channel;$out['control_revision']=is_int($gate['revision']??null)?$gate['revision']:null;
    $analysisOnly=$control->analysisOnly($channel);
    $out['mode']=$analysisOnly?'ANALYZE_ONLY':'REPLY_AND_ANALYZE';
    $out['processing_allowed']=($gate['effective']??false)===true;
    $out['reply_allowed']=($control->gate($channel)['effective']??false)===true;
    $out['dispatch_allowed']=($control->dispatchGate($channel)['effective']??false)===true;
    $workflow=app($channel==='whatsapp'?\App\Services\Dashboard\WhatsAppOrderWorkflow::class:\App\Services\Dashboard\MetaOrderWorkflow::class);
    $prefix=$channel==='whatsapp'?'whatsapp_':'meta_';$messages=$prefix.'inbox_messages';$drafts=$prefix.'order_drafts';$scans=$prefix.'order_scans';
    $stage='SELECTION';$query=metaWorkerReadCall($workflow,'scope',[$db::table($prefix.'inbox_conversations as c'),'c.'])
        ->leftJoin($scans.' as s','s.conversation_id','=','c.id');
    if($channel!=='whatsapp')$query->where('c.waba_id',$channel);
    if($conversation!==null)$query->where('c.id',$conversation);
    $retry=(clone$query)->whereNotNull('s.next_attempt_at')->orderByDesc('s.updated_at')->limit(5)->get(['c.id']);
    $recent=(clone$query)->orderByDesc('c.last_message_at')->orderByDesc('c.id')->limit(5)->get(['c.id']);
    $threads=$retry->merge($recent)->unique('id')->take(10);$out['threads']=[];
    foreach($threads as$thread){
        $id=(int)$thread->id;$record=['conversation_id'=>$id,'status'=>'OBSERVING'];$phase='METADATA';$scan=null;$ceiling=0;$latestDraft=null;$metadataReady=false;$footprint=null;$floor=0;
        try{
            $scan=$db::table($scans)->where('conversation_id',$id)->first();
            $ceiling=(int)$db::table($messages)->where('conversation_id',$id)->max('id');
            $draft=$db::table($drafts)->where('conversation_id',$id)->where('evidence_ceiling',$ceiling)->first();
            $latestDraft=$draft??$db::table($drafts)->where('conversation_id',$id)->orderByDesc('id')->first();
            $metadataReady=true;$record['ceiling']=$ceiling;$record['draft_id']=$latestDraft?(int)$latestDraft->id:null;
            $record['draft_status']=$latestDraft?metaWorkerReadCode($latestDraft->status,['REVIEW','READY','NONE','CANCELLED','DISPATCHED']):null;
            $record['draft_at_current_ceiling']=$draft!==null;
            $record['scan']=['present'=>$scan!==null,'attempts'=>$scan?min(3,max(0,(int)$scan->attempts)):null,
                'analyzed_current'=>$scan&&(int)$scan->analyzed_ceiling===$ceiling,'claimed_current'=>$scan&&(int)$scan->claimed_ceiling===$ceiling,
                'lease_live'=>$scan&&$scan->claim_nonce&&$scan->lease_until>=now('UTC')->format('Y-m-d H:i:s'),
                'backoff_due'=>!$scan||!$scan->next_attempt_at||$scan->next_attempt_at<=now('UTC')->format('Y-m-d H:i:s')];
            $phase='ACTOR';metaWorkerReadCall($workflow,'automaticActor',[$id]);
            $phase='FLOOR';$floor=metaWorkerReadCall($workflow,'automaticFloor',[$id,$scan,false]);$record['floor']=$floor;
            if($ceiling<=$floor)throw new RuntimeException('STALE_TRANSCRIPT');
            $phase='SNAPSHOT_BOUNDS';$bounds=metaWorkerReadBound($db,$messages,$id,$floor,$ceiling);$record['snapshot_messages']=$bounds['messages'];
            if(!$bounds['within_limit']){ $record['status']='OBSERVATION_LIMIT';$record['stage']=$phase;$out['threads'][]=$record;continue; }
            $phase='WAITING';$record['waiting_for_customer']=null;
            if(metaWorkerReadBound($db,$messages,$id,(int)($scan->analyzed_ceiling??0),$ceiling)['within_limit'])
                $record['waiting_for_customer']=metaWorkerReadCall($workflow,'waitingForCustomer',[$id,(int)($scan->analyzed_ceiling??0),$ceiling]);
            $footprint=metaWorkerReadFootprint($db,$messages,$id,$floor,$ceiling);
            $phase='SNAPSHOT';$snapshot=metaWorkerReadCall($workflow,'snapshot',[$id,$floor,$ceiling,false]);
            $record['snapshot_complete']=($snapshot['complete']??false)===true;
            $record['cached_hash_current']=$draft!==null&&hash_equals((string)$draft->evidence_hash,$snapshot['hash']);
            $phase='NATIVE_INBOUND';$native=metaWorkerReadCall($workflow,'primaryInbound',[$id,$snapshot]);$record['native_inbound_valid']=$native!==null;
            if($native!==null){
                $record['native_window_open']=$native['sent_at']>now('UTC')->subHours(24)->format('Y-m-d H:i:s')&&$native['sent_at']<=now('UTC')->format('Y-m-d H:i:s');
                $record['snapshot_probe_present']=\App\Support\WhatsAppOrderExtraction::hasProbe($snapshot['transcript']);
                $phase='NATIVE_GUARDS';$record['native_cancelled']=metaWorkerReadCall($workflow,'primaryCancelled',[$native['text']]);
                $record['human_clear']=metaWorkerReadCall($workflow,'primaryHumanClear',[$id,$native,$snapshot]);
                $record['capture_clear']=metaWorkerReadCall($workflow,'primaryCaptureClear',[$id,$native]);
            }
            $phase='PRIMARY_AUTHORITY';$record['primary_authority']=metaWorkerReadCall($workflow,'primaryAuthority',[$id,$snapshot])!==null;
            $phase='CACHE_OR_CLAIM';$staleBounded=$draft!==null&&(int)$draft->evidence_floor<$floor;
            $staleEvidence=$draft!==null&&!$staleBounded&&!$record['cached_hash_current'];
            $reset=$scan===null||$scan->auto_floor===null||(int)$scan->auto_floor!==($gate['floor']['message_floor']??null)||$staleBounded||$staleEvidence;
            $retry=$draft&&$draft->reason==='AI_UNAVAILABLE'&&($reset?0:(int)($scan->attempts??0))<3;
            $cached=$draft&&!$staleBounded&&!$staleEvidence&&!$retry;
            $record['cache_return_expected']=$cached;$record['scan_rebaseline_expected']=$reset;
            if($record['scan']['lease_live'])throw new RuntimeException('ANALYSIS_BUSY');
            if(!$cached){
                if(!$reset&&!$record['scan']['backoff_due']&&$ceiling<=(int)($scan->analyzed_ceiling??0))throw new RuntimeException('ANALYSIS_BACKOFF');
                $attempt=$reset?1:((int)($scan->claimed_ceiling??0)===$ceiling?(int)$scan->attempts+1:1);
                if($attempt>3)throw new RuntimeException('ANALYSIS_RETRY_LIMIT');
                if(!$record['primary_authority'])throw new RuntimeException('AI_CONTROL_BLOCKED');
                if(!$analysisOnly&&!$out['reply_allowed'])throw new RuntimeException('AI_REPLY_SCHEDULE_CLOSED');
            }
            $record['status']='READ_READY';
        }catch(Throwable$error){$record['status']='OBSERVATION_BLOCKED';$record['stage']=$phase;$record['error']=metaWorkerReadError($error);}
        $record['observation_unchanged']=false;$record['transcript_unchanged']=null;
        if($metadataReady)try{
            if($footprint!==null)$record['transcript_unchanged']=hash_equals($footprint,(string)metaWorkerReadFootprint($db,$messages,$id,$floor,$ceiling));
            $scanAfter=$db::table($scans)->where('conversation_id',$id)->first();
            $draftAfter=$db::table($drafts)->where('conversation_id',$id)->orderByDesc('id')->first(['id','revision','updated_at']);
            $record['observation_unchanged']=$scanAfter==$scan&&$ceiling===(int)$db::table($messages)->where('conversation_id',$id)->max('id')
                &&($draftAfter->id??null)===($latestDraft->id??null)&&($draftAfter->revision??null)===($latestDraft->revision??null)
                &&($draftAfter->updated_at??null)===($latestDraft->updated_at??null)&&$record['transcript_unchanged']!==false;
        }catch(Throwable$ignored){}
        $out['threads'][]=$record;
    }
    $out['control_revision_unchanged']=($control->processingGate($channel)['revision']??null)===$out['control_revision'];
    $out['source_unchanged_during_read']=true;
    foreach($pins as$path=>$pin){$bytes=is_link($path)||!is_file($path)||filesize($path)>1048576?false:file_get_contents($path);
        if(!is_string($bytes)||sha1('blob '.strlen($bytes)."\0".$bytes)!==$pin){$out['source_unchanged_during_read']=false;break;}}
    $out['status']='READ_READY';
}catch(Throwable$error){$out['status']='READ_FAILED';$out['stage']=$stage;$out['error']=metaWorkerReadError($error);}
while(ob_get_level())ob_end_clean();echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
