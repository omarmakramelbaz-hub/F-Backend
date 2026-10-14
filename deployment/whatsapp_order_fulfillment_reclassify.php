<?php

/** One bounded cached-policy revision. No AI, quote, send, customer or ERP calls. */
final class WhatsAppOrderFulfillmentReclassify
{
    public const SOURCE_PINS = [
        'App\\Services\\Dashboard\\WhatsAppOrderWorkflow' => ['app/Services/Dashboard/WhatsAppOrderWorkflow.php','b1bdc5962330c5f61a8ab6fa4c640d0fcae8799d'],
        'App\\Services\\Dashboard\\WhatsAppOrderAiProvider' => ['app/Services/Dashboard/WhatsAppOrderAiProvider.php','cbaaa8ed7014da5bad05d429151afa5ff377ceab'],
        'App\\Support\\WhatsAppOrderExtraction' => ['app/Support/WhatsAppOrderExtraction.php','56d2b9575da152de3eac4876fd93140ee7bc9e39'],
        'App\\Services\\Dashboard\\WhatsAppOrderConversationEvidence' => ['app/Services/Dashboard/WhatsAppOrderConversationEvidence.php','1bd223861e3772e10658df2d555b4bb040243a4a'],
        'App\\Services\\Dashboard\\WhatsAppOrderSourceSelection' => ['app/Services/Dashboard/WhatsAppOrderSourceSelection.php','03db3211b80da905f07415fd16f1e7a62e0395b1'],
        'App\\Support\\WhatsAppInboxProtocol' => ['app/Support/WhatsAppInboxProtocol.php','c64f8cf89437bb7822fc767202f7cf5112bfd7fd'],
        'App\\Services\\Dashboard\\WhatsAppAcceptedReplyEvidence' => ['app/Services/Dashboard/WhatsAppAcceptedReplyEvidence.php','4b571f7f4a5cef9257c414886dba515c06dae2a1'],
        'App\\Services\\Dashboard\\WhatsAppInboxMessage' => ['app/Services/Dashboard/WhatsAppInboxMessage.php','bedd91db5266d83bdce84a04836e988518891728'],
        'App\\Services\\Dashboard\\WhatsAppNativePlaceholderHydration' => ['app/Services/Dashboard/WhatsAppNativePlaceholderHydration.php','9e221ba052589c1ffe474958b636a77ff5817b7d'],
        'App\\Services\\Dashboard\\WhatsAppInboxAccess' => ['app/Services/Dashboard/WhatsAppInboxAccess.php','186789d42113e81818de8d82a2327e3ee9d8d0b5'],
        'App\\Services\\Dashboard\\PosServiceTicket' => ['app/Services/Dashboard/PosServiceTicket.php','ef4c7b6f4a1276c122634aafabb2d510926aaeef'],
    ];

    public static function integer($value): int
    {
        $digits=is_int($value)||is_string($value)?(string)$value:''; $max=(string)PHP_INT_MAX;
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/',$digits) || strlen($digits)>strlen($max)
            || (strlen($digits)===strlen($max)&&strcmp($digits,$max)>0)) throw new RuntimeException();
        return (int)$digits;
    }

    public static function arguments(array $argv): array
    {
        $out=['refresh'=>false];
        foreach (array_slice($argv,1) as $arg) {
            if ($arg==='--refresh' && !$out['refresh']) { $out['refresh']=true; continue; }
            if (!is_string($arg)||!preg_match('/\A--(conversation|draft|ceiling)=([1-9][0-9]*)\z/',$arg,$m)
                || array_key_exists($m[1],$out)) throw new RuntimeException();
            $out[$m[1]]=self::integer($m[2]);
        }
        if (count($out)!==4) throw new RuntimeException();
        return $out;
    }

    /** Injectable pure preflight; cached customer facts and citations never change. */
    public static function proposal(array $state,array $target,callable $validate,callable $policy,callable $fresh): array
    {
        $d=$state['draft']; $s=$state['scan']; $snap=$state['snapshot']; $data=$state['data'];
        if (($state['configured']??null)!==true || ($state['pending']??null)!==false
            || self::integer($d['id'])!==$target['draft'] || self::integer($d['conversation_id'])!==$target['conversation']
            || self::integer($s['conversation_id'])!==$target['conversation']
            || self::integer($d['evidence_ceiling'])!==$target['ceiling'] || $state['ceiling']!==$target['ceiling']
            || self::integer($d['evidence_floor'])!==$state['floor'] || $state['floor']>=$target['ceiling']
            || ($snap['complete']??null)!==true || !is_string($d['evidence_hash']) || !is_string($snap['hash']??null)
            || !hash_equals($d['evidence_hash'],$snap['hash']) || $d['status']!=='REVIEW'
            || self::integer($d['revision'])<1 || self::integer($d['revision'])>=4294967295) throw new RuntimeException();
        foreach (['ticket_id','confirmation_key','sealed_payload','assigned_branch','dispatch_actor_id','dispatched_at'] as $field)
            if (!array_key_exists($field,$d)||$d[$field]!==null) throw new RuntimeException();
        foreach (['customer_command_key','ticket_command_key'] as $field)
            if (!is_string($d[$field])||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/i',$d[$field])) throw new RuntimeException();
        if (!is_array($data)||array_key_exists('analysis_failure',$data)) throw new RuntimeException();
        $checked=$validate($data,$snap['transcript']);
        if (($checked['ok']??null)!==true || ($checked['data']['decision']??null)!=='CONFIRMED'
            || !$fresh($checked['data'],$snap['transcript'],$snap['recovered_at']??null)) throw new RuntimeException();
        if (($data['fulfillment']??null)==='DELIVERY') return ['status'=>'ALREADY_CLASSIFIED'];
        if (($data['fulfillment']??null)!=='UNKNOWN'||$d['reason']!=='PICKUP_REQUIRES_REVIEW'
            || !array_key_exists('claim_nonce',$s)||$s['claim_nonce']!==null||$s['lease_until']!==null) throw new RuntimeException();
        $classified=$policy($checked['data'],$snap['transcript']);
        $only=$classified; $only['fulfillment']=$checked['data']['fulfillment'];
        if ($only!==$checked['data']) throw new RuntimeException();
        if (($classified['fulfillment']??null)==='PICKUP') return ['status'=>'BRANCH_REVIEW_REQUIRED'];
        if (($classified['fulfillment']??null)!=='DELIVERY') return ['status'=>'POLICY_REVIEW_REQUIRED'];
        $data['fulfillment']='DELIVERY'; $verified=$validate($data,$snap['transcript']);
        if (($verified['ok']??null)!==true || $verified['data']!==$classified) throw new RuntimeException();
        return ['status'=>'DELIVERY_READY','data'=>$data];
    }

    /** The transaction callback rereads under locks and applies exactly two row deltas. */
    public static function run(array $target,callable $read,callable $atomic,callable $apply,
        callable $validate,callable $policy,callable $fresh,callable $commitGuard): array
    {
        $out=['diagnostic'=>'WHATSAPP_ORDER_FULFILLMENT_RECLASSIFY','writes'=>0,'database_rows_updated'=>0,'ai_calls'=>0,'erp_writes'=>0]; $stage='STATE';
        try {
            $before=$read(false); $plan=self::proposal($before,$target,$validate,$policy,$fresh);
            if ($plan['status']!=='DELIVERY_READY'||!$target['refresh']) return $out+['status'=>$plan['status']];
            $stage='COMMIT';
            $atomic(static function () use ($target,$before,$read,$apply,$validate,$policy,$fresh,$commitGuard): void {
                $current=$read(true);
                if ($current!==$before) throw new RuntimeException();
                $plan=self::proposal($current,$target,$validate,$policy,$fresh);
                if ($plan['status']!=='DELIVERY_READY') throw new RuntimeException();
                $apply($current,$plan['data']);
                $commitGuard($current); // External source/config changes roll back both SQL deltas.
            });
            $out['writes']=2; $out['database_rows_updated']=2;
            return $out+['status'=>'CACHED_DELIVERY_REFRESHED'];
        } catch (Throwable $ignore) { return $out+['status'=>'STOPPED','stage'=>$stage]; }
    }
}

if (defined('WHATSAPP_ORDER_FULFILLMENT_RECLASSIFY_TEST_ONLY') && WHATSAPP_ORDER_FULFILLMENT_RECLASSIFY_TEST_ONLY===true) return;
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('display_startup_errors','0'); ini_set('log_errors','0'); ob_start(); umask(0077);
$output=['diagnostic'=>'WHATSAPP_ORDER_FULFILLMENT_RECLASSIFY','writes'=>0,'database_rows_updated'=>0,'ai_calls'=>0,'erp_writes'=>0]; $stage='AUTHORIZATION';
try {
    $target=WhatsAppOrderFulfillmentReclassify::arguments($argv); $stage='OWNER';
    if (!function_exists('posix_geteuid')||!function_exists('posix_getpwnam')) throw new RuntimeException();
    $owner=posix_getpwnam('fasakha');
    if (!is_array($owner)||posix_geteuid()!==$owner['uid']) throw new RuntimeException();
    $root='/home/fasakha/public_html';
    $readFile=static function(string $relative,bool $required=true) use($root,$owner): ?string {
        $path=$root.'/'.$relative; clearstatcache(true,$path); $s=@lstat($path);
        if (!$s&&!$required) return null;
        if (!$s||($s['mode']&0170000)!==0100000||$s['uid']!==$owner['uid']||($s['mode']&0022)
            || realpath($path)!==$path||$s['size']<1||$s['size']>8388608) throw new RuntimeException();
        $bytes=file_get_contents($path); if (!is_string($bytes)||strlen($bytes)!==$s['size']) throw new RuntimeException(); return $bytes;
    };
    $guard=static function(bool $loaded=false) use($readFile,$root): void {
        foreach (WhatsAppOrderFulfillmentReclassify::SOURCE_PINS as $class=>[$path,$pin]) {
            $bytes=$readFile($path);
            if (!preg_match('/\A[a-f0-9]{40}\z/',$pin)||!hash_equals($pin,sha1('blob '.strlen($bytes)."\0".$bytes))
                || ($loaded&&realpath((new ReflectionClass($class))->getFileName())!==$root.'/'.$path)) throw new RuntimeException();
        }
    };
    $stage='SOURCE_GUARD'; $guard(); $stage='BOOTSTRAP';
    if (!chdir($root)) throw new RuntimeException();
    require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $guard(true);
    $db=\Illuminate\Support\Facades\DB::class; $crypt=\Illuminate\Support\Facades\Crypt::class;
    $workflow=app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    $invoke=static function(string $method,...$args) use($workflow) {
        $r=new ReflectionMethod($workflow,$method); $r->setAccessible(true); return $r->invoke($workflow,...$args);
    };
    $policyMethod=new ReflectionMethod(\App\Services\Dashboard\WhatsAppOrderAiProvider::class,'fulfillmentPolicy'); $policyMethod->setAccessible(true);
    $read=static function(bool $lock) use($target,$db,$crypt,$guard,$readFile,$invoke): array {
        $guard(true); $cfg=config('whatsapp_orders',[]); $cart=config('whatsapp_cart',[]);
        if (!is_array($cfg)||!is_array($cart)||!in_array($cfg['enabled']??null,[true,1,'1'],true)||($cfg['mode']??null)!=='auto'
            || config('desktop_dashboard.local',false)||!is_array($cfg['allowed_branch_ids']??null)||!$cfg['allowed_branch_ids']
            || WhatsAppOrderFulfillmentReclassify::integer($cfg['automation_actor_id']??null)<1) throw new RuntimeException();
        $activation=$invoke('activationBoundary','activation_message_id'); $eventFloor=$invoke('activationBoundary','activation_event_id'); $instant=$invoke('activationInstant');
        if ($activation===null||$eventFloor===null||$instant===null) throw new RuntimeException();
        $hashes=[];
        foreach (['.env','config/whatsapp_orders.php','config/whatsapp_cart.php','bootstrap/cache/config.php'] as $path) {
            $bytes=$readFile($path,$path!=='bootstrap/cache/config.php'); $hashes[$path]=$bytes===null?null:hash('sha256',$bytes);
        }
        $fingerprint=hash('sha256',json_encode([$cfg,$cart,$hashes],JSON_THROW_ON_ERROR));
        // Match dispatch's lock order: draft, scoped conversation, then scan. No scan insertion.
        $q=$db::table('whatsapp_order_drafts')->where('id',$target['draft'])->select('id','conversation_id','evidence_floor','evidence_ceiling',
            'evidence_hash','confirmation_key','status','reason','revision','customer_command_key','ticket_command_key','ticket_id',
            'dispatch_actor_id','assigned_branch','dispatched_at','created_at','updated_at')
            ->selectRaw('CASE WHEN LENGTH(extraction) <= 262144 THEN extraction ELSE NULL END AS extraction')
            ->selectRaw('CASE WHEN sealed_payload IS NULL THEN NULL ELSE 1 END AS sealed_payload');
        if ($lock) $q->lockForUpdate(); $draft=$q->first();
        if (!$draft||(int)$draft->conversation_id!==$target['conversation']) throw new RuntimeException();
        $q=$db::table('whatsapp_inbox_conversations')->where('id',$target['conversation'])
            ->where('waba_id',\App\Services\Dashboard\WhatsAppInboxAccess::WABA_ID)
            ->where('phone_number_id',\App\Services\Dashboard\WhatsAppInboxAccess::PHONE_ID);
        if ($lock) $q->lockForUpdate(); if (!$q->first(['id'])) throw new RuntimeException();
        $q=$db::table('whatsapp_order_scans')->where('conversation_id',$target['conversation']); if ($lock) $q->lockForUpdate(); $scan=$q->first();
        if (!$scan) throw new RuntimeException();
        $floor=$invoke('automaticFloor',$target['conversation'],$scan,$lock); $ceiling=$invoke('ceiling',$target['conversation'],$lock);
        if ((int)$draft->evidence_floor!==$floor||(int)$draft->evidence_ceiling!==$ceiling||$ceiling!==$target['ceiling']) throw new RuntimeException();
        $q=$db::table('whatsapp_inbox_ingestion_failures')->where('event_id','>',$eventFloor); if ($lock) $q->lockForUpdate();
        $blocked=$q->first(['event_id'])!==null||$invoke('pendingCapture',$lock);
        $accepted=app(\App\Services\Dashboard\WhatsAppAcceptedReplyEvidence::class);
        $pending=new ReflectionMethod($accepted,'pending'); $pending->setAccessible(true); $q=$pending->invoke($accepted,$target['conversation'],$instant);
        if ($lock) $q->lockForUpdate(); $blocked=$blocked||$q->first(['r.id'])!==null;
        if (!is_string($draft->extraction)||strlen($draft->extraction)>262144) throw new RuntimeException();
        $plain=$crypt::decryptString($draft->extraction); if (strlen($plain)>131072) throw new RuntimeException();
        $data=json_decode($plain,true,32,JSON_THROW_ON_ERROR); $budget=0;
        foreach ($db::table('whatsapp_inbox_messages')->where('conversation_id',$target['conversation'])->where('id','>',$floor)->where('id','<=',$ceiling)
            ->orderByDesc('id')->limit(61)->select('id')->selectRaw('LENGTH(content) AS content_bytes')->get() as $row) {
            $size=WhatsAppOrderFulfillmentReclassify::integer($row->content_bytes); $budget+=$size;
            if ($size>8388608||$budget>16777216) throw new RuntimeException();
        }
        $snap=$invoke('snapshot',$target['conversation'],$floor,$ceiling,false); $guard(true);
        return ['configured'=>true,'pending'=>$blocked,'fingerprint'=>$fingerprint,'floor'=>$floor,'ceiling'=>$ceiling,
            'draft'=>(array)$draft,'scan'=>(array)$scan,'data'=>$data,'snapshot'=>$snap];
    };
    $apply=static function(array $state,array $data) use($db,$crypt,$target): void {
        $time=now('UTC'); $timestamp=$time->format('Y-m-d H:i:s'); $debounce=config('whatsapp_cart.debounce_seconds',10);
        if (!is_int($debounce)&&!(is_string($debounce)&&preg_match('/\A[0-9]{1,3}\z/',$debounce))) $debounce=10;
        $draftDelta=['extraction'=>$crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR)),
            'revision'=>(int)$state['draft']['revision']+1,'reason'=>'REVIEW_REQUIRED','updated_at'=>$timestamp];
        $scanDelta=['claimed_ceiling'=>0,'attempts'=>0,'next_attempt_at'=>$time->copy()->addSeconds(max(0,min(120,(int)$debounce)))->format('Y-m-d H:i:s'),'updated_at'=>$timestamp];
        // Encrypted conditional-undo receipt. Restoration must CAS these exact after-values;
        // it must refuse if the worker has since quoted, dispatched, claimed or changed either row.
        $parent='/home/fasakha/whatsapp-release-backups'; clearstatcache(true,$parent); $s=@lstat($parent);
        if (!$s||($s['mode']&0170000)!==0040000||$s['uid']!==posix_geteuid()||($s['mode']&0777)!==0700||realpath($parent)!==$parent) throw new RuntimeException();
        $directory=$parent.'/order-fulfillment-cache-'.bin2hex(random_bytes(12));
        if (!mkdir($directory,0700)) throw new RuntimeException();
        $receipt=$crypt::encryptString(json_encode(['version'=>1,'policy'=>'owner-address-phone-delivery-v1',
            'before'=>['draft'=>$state['draft'],'scan'=>$state['scan']], 'after_delta'=>['draft'=>$draftDelta,'scan'=>$scanDelta],
            'configuration_fingerprint'=>$state['fingerprint'],'source_pins'=>WhatsAppOrderFulfillmentReclassify::SOURCE_PINS],JSON_THROW_ON_ERROR));
        $stream=fopen($directory.'/receipt.enc','x'); if (!$stream) throw new RuntimeException();
        try { if (fwrite($stream,$receipt)!==strlen($receipt)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream))) throw new RuntimeException(); }
        finally { fclose($stream); }
        if ($db::table('whatsapp_order_drafts')->where('id',$target['draft'])->where('revision',$state['draft']['revision'])
            ->where('evidence_hash',$state['draft']['evidence_hash'])->where('evidence_ceiling',$target['ceiling'])->update($draftDelta)!==1
            || $db::table('whatsapp_order_scans')->where('conversation_id',$target['conversation'])->whereNull('claim_nonce')->whereNull('lease_until')->update($scanDelta)!==1) throw new RuntimeException();
    };
    $commitGuard=static function(array $state) use($guard,$readFile): void {
        $guard(true); $hashes=[];
        foreach (['.env','config/whatsapp_orders.php','config/whatsapp_cart.php','bootstrap/cache/config.php'] as $path) {
            $bytes=$readFile($path,$path!=='bootstrap/cache/config.php'); $hashes[$path]=$bytes===null?null:hash('sha256',$bytes);
        }
        $fingerprint=hash('sha256',json_encode([config('whatsapp_orders',[]),config('whatsapp_cart',[]),$hashes],JSON_THROW_ON_ERROR));
        if (!hash_equals($state['fingerprint'],$fingerprint)) throw new RuntimeException();
    };
    $stage='RECLASSIFY';
    $output=WhatsAppOrderFulfillmentReclassify::run($target,$read,static fn(callable $work)=>$db::transaction($work,3),$apply,
        [\App\Support\WhatsAppOrderExtraction::class,'validate'],static fn(array $data,array $rows)=>$policyMethod->invoke(null,$data,$rows),
        static fn(array $data,array $rows,?string $recovered)=>$invoke('freshAutomaticEvidence',$data,$rows)
            && ($recovered===null||$invoke('freshAutomaticEvidence',$data,$rows,$recovered)),$commitGuard);
} catch (Throwable $ignore) { $output+=['status'=>'STOPPED','stage'=>$stage]; }
while (ob_get_level()>0) ob_end_clean();
echo json_encode($output,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($output['status']==='STOPPED'?1:0);
