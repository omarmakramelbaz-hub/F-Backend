<?php
// Pure synthetic evidence and an in-memory transactional harness; no bootstrap or network.
define('WHATSAPP_ORDER_FULFILLMENT_RECLASSIFY_TEST_ONLY',true);
require dirname(__DIR__).'/app/Support/WhatsAppInboxProtocol.php';
require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderConversationEvidence.php';
require dirname(__DIR__).'/app/Support/WhatsAppOrderExtraction.php';
require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderSourceSelection.php';
require dirname(__DIR__).'/app/Services/Dashboard/WhatsAppOrderAiProvider.php';
require dirname(__DIR__).'/deployment/whatsapp_order_fulfillment_reclassify.php';
set_error_handler(static function($severity,$message,$file,$line): bool {
    if ($severity&E_ALL) throw new ErrorException($message,0,$severity,$file,$line); return false;
});
use WhatsAppOrderFulfillmentReclassify as Repair;
use App\Support\WhatsAppOrderExtraction as Extraction;
$checks=0;
function expect($actual,$expected,string $label): void {
    $GLOBALS['checks']++; if ($actual!==$expected) throw new RuntimeException('FAILED: '.$label);
}
function fixture(): array {
    $rows=[['id'=>'m1','speaker'=>'customer','sent_at'=>'2026-01-02 12:00:01',
        'text'=>'اسمي عميل تجريبي ورقمي 01000000001 وعنواني شارع المثال في المنصورة. عايز 0.5 كيلو فسيخ.', 'location'=>null],
        ['id'=>'m2','speaker'=>'business','sent_at'=>'2026-01-02 12:00:02',
            'text'=>'تأكيد طلب عميل تجريبي: 0.5 كيلو فسيخ من المنصورة إلى شارع المثال.', 'location'=>null],
        ['id'=>'m3','speaker'=>'customer','sent_at'=>'2026-01-02 12:00:03','text'=>'تمام موافق','location'=>null]];
    $data=['decision'=>'CONFIRMED','fulfillment'=>'UNKNOWN',
        'customer'=>['name'=>'عميل تجريبي','phone'=>'01000000001','address'=>'شارع المثال','area'=>'المنصورة','notes'=>null],
        'branch_hint'=>'المنصورة','items'=>[['name'=>'فسيخ','quantity'=>'0.5','quantity_mode'=>'weight','option_hint'=>null]],
        'approximate_total'=>null,'evidence'=>['request_ids'=>['m1'],'confirmation_ids'=>['m2'],
            'customer_acceptance_ids'=>['m3'],'location_id'=>null],'issues'=>[]];
    $draft=['id'=>41,'conversation_id'=>17,'evidence_floor'=>3,'evidence_ceiling'=>53,
        'evidence_hash'=>str_repeat('a',64),'status'=>'REVIEW','reason'=>'PICKUP_REQUIRES_REVIEW','revision'=>2,
        'customer_command_key'=>'12345678-1234-4234-8234-123456789abc','ticket_command_key'=>'abcdef12-1234-4234-8234-123456789abc',
        'ticket_id'=>null,'confirmation_key'=>null,'sealed_payload'=>null,'assigned_branch'=>null,'dispatch_actor_id'=>null,'dispatched_at'=>null];
    return ['configured'=>true,'pending'=>false,'fingerprint'=>str_repeat('b',64),'floor'=>3,'ceiling'=>53,'draft'=>$draft,
        'scan'=>['conversation_id'=>17,'analyzed_ceiling'=>53,'auto_floor'=>3,'claim_nonce'=>null,'lease_until'=>null,
            'claimed_ceiling'=>53,'attempts'=>3,'next_attempt_at'=>'2026-01-02 12:05:00'], 'data'=>$data,
        'snapshot'=>['complete'=>true,'hash'=>str_repeat('a',64),'transcript'=>$rows,'recovered_at'=>null]];
}
$target=['conversation'=>17,'draft'=>41,'ceiling'=>53,'refresh'=>true];
$method=new ReflectionMethod(App\Services\Dashboard\WhatsAppOrderAiProvider::class,'fulfillmentPolicy'); $method->setAccessible(true);
$policy=static fn(array $data,array $rows)=>$method->invoke(null,$data,$rows);
$validate=[Extraction::class,'validate'];
$fresh=static function(array $data,array $rows,?string $recovered): bool {
    $floor=max('2026-01-02 00:00:00',$recovered??'2026-01-02 00:00:00'); $map=array_column($rows,null,'id');
    foreach ($data['evidence']['confirmation_ids'] as $id) if ($map[$id]['sent_at']<$floor) return false;
    foreach (Extraction::committingRequestIds($data,$rows) as $id) if ($map[$id]['sent_at']>=$floor) return true;
    return false;
};
function runFixture(array $state,array $target,?callable $change=null,?callable $policyOverride=null,?callable $commitGuard=null): array {
    $reads=0; $applies=0; $transactions=0; $saved=null; $journal=[];
    $read=static function(bool $locked) use(&$reads,&$state,$change): array {
        $reads++; if ($locked&&$change) $state=$change($state); return $state;
    };
    $atomic=static function(callable $work) use(&$transactions,&$state,&$journal): void {
        $transactions++; $before=$state; $prior=$journal;
        try { $work(); } catch(Throwable $e) { $state=$before; $journal=$prior; throw $e; }
    };
    $apply=static function(array $observed,array $data) use(&$applies,&$saved,&$state,&$journal): void {
        $applies++; $saved=$data; $state['data']=$data; $state['draft']['revision']++; $state['draft']['reason']='REVIEW_REQUIRED';
        $state['scan']['claimed_ceiling']=0; $state['scan']['attempts']=0; $state['scan']['next_attempt_at']='2026-01-02 12:00:10';
        $journal=['cached_draft'=>1,'idle_scan'=>1];
    };
    $result=Repair::run($target,$read,$atomic,$apply,$GLOBALS['validate'],$policyOverride??$GLOBALS['policy'],$GLOBALS['fresh'],
        $commitGuard??static function(array $state): void {});
    return compact('result','reads','applies','transactions','saved','state','journal');
}
try {
    expect(Repair::arguments(['helper','--ceiling=53','--draft=41','--conversation=17','--refresh']),
        ['refresh'=>true,'ceiling'=>53,'draft'=>41,'conversation'=>17],'generic explicit bounded arguments');
    foreach ([['helper','--draft=41'],['helper','--draft=41','--draft=42','--conversation=17','--ceiling=53'],
        ['helper','--draft=41','--conversation=17','--ceiling=53','--refresh','--refresh'],
        ['helper','--draft=41','--conversation=17','--ceiling=053'],['helper','--draft=41','--conversation=17','--ceiling='.PHP_INT_MAX.'0']] as $args) {
        $refused=false; try { Repair::arguments($args); } catch(Throwable $e) { $refused=true; }
        expect($refused,true,'missing, duplicate and overflow selectors refuse before bootstrap');
    }
    $state=fixture(); expect(Extraction::validate($state['data'],$state['snapshot']['transcript'])['ok'],true,'actual validator accepts synthetic cached evidence');
    $plan=Repair::proposal($state,$target,$validate,$policy,$fresh); $expected=$state['data']; $expected['fulfillment']='DELIVERY';
    expect($plan,['status'=>'DELIVERY_READY','data'=>$expected],'policy changes fulfillment only');
    $dry=runFixture($state,array_replace($target,['refresh'=>false]));
    expect([$dry['result']['status'],$dry['applies'],$dry['transactions'],$dry['result']['writes']],['DELIVERY_READY',0,0,0],'preview has zero mutation');
    $run=runFixture($state,$target);
    expect([$run['result']['status'],$run['applies'],$run['transactions'],$run['journal']],
        ['CACHED_DELIVERY_REFRESHED',1,1,['cached_draft'=>1,'idle_scan'=>1]],'single target cached revision and scan wake');
    expect($run['saved'],$expected,'no alteration to items, customer, commitment or citations');
    foreach (['id','conversation_id','evidence_floor','evidence_ceiling','evidence_hash','customer_command_key','ticket_command_key'] as $key)
        expect($run['state']['draft'][$key],$state['draft'][$key],'identity and evidence stay unchanged');
    expect([$run['result']['ai_calls'],$run['result']['erp_writes'],$run['result']['writes'],$run['result']['database_rows_updated']],[0,0,2,2],'zero AI and ERP operations; row update count is explicit');
    $again=runFixture($run['state'],$target);
    expect([$again['result']['status'],$again['applies'],$again['transactions'],$again['state']],
        ['ALREADY_CLASSIFIED',0,0,$run['state']],'repeat preserves revision, attempts and next attempt');
    foreach (['ticket_id','confirmation_key','sealed_payload','assigned_branch','dispatch_actor_id','dispatched_at'] as $key) {
        $blocked=$state; $blocked['draft'][$key]='present'; $r=runFixture($blocked,$target);
        expect([$r['result']['status'],$r['applies'],$r['transactions']],['STOPPED',0,0],'sealed or dispatched state cannot be reclassified');
    }
    $cases=[static function($s){$s['configured']=false;return $s;},static function($s){$s['pending']=true;return $s;},
        static function($s){$s['ceiling']++;return $s;},static function($s){$s['floor']++;return $s;},
        static function($s){$s['snapshot']['hash']=str_repeat('c',64);return $s;},static function($s){$s['snapshot']['complete']=false;return $s;},
        static function($s){$s['data']['analysis_failure']=[];return $s;},static function($s){$s['data']['customer']['address']='عنوان مختلق';return $s;},
        static function($s){$s['data']['decision']='DRAFT';return $s;},static function($s){$s['draft']['reason']='INVALID_EXTRACTION';return $s;},
        static function($s){$s['scan']['claim_nonce']=str_repeat('d',64);$s['scan']['lease_until']='2025-01-01 00:00:00';return $s;},
        static function($s){$s['scan']['lease_until']='2026-01-02 13:00:00';return $s;},
        static function($s){$s['snapshot']['recovered_at']='2026-01-02 12:01:00';return $s;},
        static function($s){foreach ($s['snapshot']['transcript'] as &$r) $r['sent_at']=str_replace('2026-01-02','2026-01-01',$r['sent_at']);unset($r);return $s;}];
    foreach ($cases as $index=>$change) {
        $r=runFixture($change($state),$target);
        expect([$r['result']['status'],$r['applies'],$r['transactions']],['STOPPED',0,0],'stale, ungrounded, pending or even expired holder refuses '.$index);
    }
    foreach ([static function($s){$s['draft']['revision']++;return $s;},static function($s){$s['fingerprint']=str_repeat('e',64);return $s;},
        static function($s){$s['scan']['claim_nonce']=str_repeat('d',64);return $s;},static function($s){$s['pending']=true;return $s;}] as $change) {
        $r=runFixture($state,$target,$change);
        expect([$r['result']['status'],$r['applies'],$r['journal'], $r['result']['stage']],['STOPPED',0,[],'COMMIT'],'concurrent worker, capture or configuration change fails closed');
    }
    foreach (['SOURCE','CONFIGURATION'] as $change) {
        $final=static function(array $state) use($change): void { throw new RuntimeException($change.' private value must not leave the helper'); };
        $r=runFixture($state,$target,null,null,$final);
        expect([$r['result']['status'],$r['result']['writes'],$r['applies'],$r['journal'],$r['state']],
            ['STOPPED',0,1,[],$state],'source/config change after SQL deltas rolls back before commit');
        expect(strpos(json_encode($r['result']),$change.' private')===false,true,'failure exposes no private exception or changed value');
    }
    foreach (['الطلب تيك أواي','الطلب في الصالة','هستلم من الفرع'] as $mode) {
        $branch=$state; $branch['snapshot']['transcript'][0]['text'].=' ؛ '.$mode;
        $r=runFixture($branch,$target);
        expect([$r['result']['status'],$r['applies'],$r['transactions']],['BRANCH_REVIEW_REQUIRED',0,0],'explicit branch mode overrides contact details without delivery wake');
    }
    $late=$state; $late['snapshot']['transcript'][2]['text'].=' ؛ الطلب دليفري';
    expect(runFixture($late,$target)['result']['status'],'POLICY_REVIEW_REQUIRED','late mode choice cannot bypass a new confirmation');
    $malicious=static function($data,$rows) use($policy){$data=$policy($data,$rows);$data['customer']['name']='بديل';return $data;};
    $r=runFixture($state,$target,null,$malicious);
    expect([$r['result']['status'],$r['applies'],$r['transactions']],['STOPPED',0,0],'classifier cannot mutate any other cached field');
    $invalid=static function($data,$rows) use($policy){$data=$policy($data,$rows);$data['fulfillment']='INVALID';return $data;};
    expect(runFixture($state,$target,null,$invalid)['result']['status'],'POLICY_REVIEW_REQUIRED','unrecognized policy result cannot write');
    $source=file_get_contents(dirname(__DIR__).'/deployment/whatsapp_order_fulfillment_reclassify.php');
    expect((bool)preg_match('/->(?:extract|quote|dispatch|projectPending|projectOne|send|insert|insertGetId|insertOrIgnore)\s*\(/',$source),false,
        'helper contains no AI, quote, dispatch, accepted projection, send or insert calls');
    expect(strpos($source,'WhatsAppInboxAccess::WABA_ID')!==false&&strpos($source,'WhatsAppInboxAccess::PHONE_ID')!==false,true,'helper scopes through the deployed access constants');
    echo 'PASS: '.$checks." bounded cached fulfillment checks\n";
} catch(Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");exit(1); }
