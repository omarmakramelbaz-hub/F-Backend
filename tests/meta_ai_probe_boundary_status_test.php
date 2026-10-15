<?php
// Real encrypted native evidence and SQL query_only; no live AI, Graph or ERP calls.
require __DIR__.'/whatsapp_order_primary_ai_workflow_test.php';
$probeSource=file_get_contents(__DIR__.'/../deployment/meta_ai_probe_boundary_status.php');
$start=strpos($probeSource,'function metaProbeBoundaryBase');
$end=strpos($probeSource,"\n\$out=metaProbeBoundaryBase(); \$stage='ARGUMENTS';");
if ($start===false||$end===false) throw new RuntimeException('PROBE_FUNCTION_LAYOUT');
eval(substr($probeSource,$start,$end-$start));
$probeChecks=0;
function probeExpect($ok,string $label): void {
    $GLOBALS['probeChecks']++; if (!$ok) throw new RuntimeException('PROBE_BOUNDARY_FAILED:'.$label);
}
function probeObserve(int $conversation,$selectedWorkflow=null,$selectedControl=null): array {
    global $workflow;
    return metaProbeBoundaryObserve(\Illuminate\Support\Facades\DB::class,$selectedWorkflow??$workflow,
        $selectedControl??app('App\\Services\\Dashboard\\MetaAiControl'),$conversation);
}
function probeWritable(): void { \Illuminate\Support\Facades\DB::connection()->getPdo()->exec('PRAGMA query_only = OFF'); }
function probeNoBoundaryCalls(): void {
    probeExpect(\App\Services\Dashboard\WhatsAppOrderAiProvider::$calls===0&&count($GLOBALS['dialogueGraphs'])===0
        &&\App\Services\Dashboard\PosServiceTicket::$saveCalls===0,'classifier never calls AI Graph or ERP');
}
probeExpect(metaProbeBoundaryArguments(['helper.php'])===31,'default is the selected production conversation');
probeExpect(metaProbeBoundaryArguments(['helper.php','--conversation','31'])===31,'explicit positive conversation accepted');
foreach ([['helper.php','--conversation','0'],['helper.php','--conversation','-1'],['helper.php','--conversation','31','--force'],
    ['helper.php','--conversation','PRIVATE_PHONE'],['helper.php','--channel','instagram']] as $args) {
    try { metaProbeBoundaryArguments($args); probeExpect(false,'invalid arguments rejected'); }
    catch (RuntimeException $ignored) { probeExpect(true,'only fixed parser options are accepted'); }
}
probeExpect(!preg_match('/(?:->|::)(?:process|analyze|extract|send|dispatch|quote|save|insert|update|delete|projectPending|consume)\s*\(/',$probeSource),
    'helper source has no domain mutation or outbound boundary calls');
preg_match('/\$pins=\[(.*?)\n    \];/s',$probeSource,$pinBlock);
preg_match_all("/'([^']+)'=>'([a-f0-9]{40})'/",$pinBlock[1]??'',$pinRows,PREG_SET_ORDER);
probeExpect(count($pinRows)===15,'all relevant current runtime source files guarded');
foreach ($pinRows as $pin) if (is_file(__DIR__.'/../'.$pin[1])) {
    $bytes=file_get_contents(__DIR__.'/../'.$pin[1]);
    probeExpect(sha1('blob '.strlen($bytes)."\0".$bytes)===$pin[2],'guard pin matches actual runtime source '.$pin[1]);
}

foreach (['coded','operational','operational-new-order','coded-new-order','business-coded-new-order','connection-check'] as $case) {
    primarySetup();$peer='US.probe-private';$conversation=addConversation($peer);
    $first=match($case) {
        'coded','coded-new-order','business-coded-new-order'=>'PRIVATE_CUSTOMER_PHONE_ADDRESS WA-1234',
        'connection-check'=>'اختبار اتصال PRIVATE_CUSTOMER_PHONE_ADDRESS',
        default=>'هذا الطلب اختبار لا ترسله',
    };
    insertMessage($conversation,'wamid.PRIVATE_MARKER_OLD',$case==='business-coded-new-order'?'outbound':'inbound',$first,1,null,$peer);
    insertMessage($conversation,'wamid.PRIVATE_BUSINESS','outbound','PRIVATE_BUSINESS_REPLY',2,null,$peer);
    insertMessage($conversation,'wamid.PRIVATE_MARKER_LATEST','inbound',str_contains($case,'new-order')?'عايز طلب جديد':'PRIVATE_LATEST_GREETING',3,null,$peer);
    $db=\Illuminate\Support\Facades\DB::class;$ceiling=(int)$db::table('whatsapp_inbox_messages')->max('id');
    $db::table('whatsapp_order_scans')->insert(['conversation_id'=>$conversation,'analyzed_ceiling'=>0,'claimed_ceiling'=>$ceiling,
        'attempts'=>3,'auto_floor'=>0,'next_attempt_at'=>now('UTC')->addHour(),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    $scan=$db::table('whatsapp_order_scans')->first();$native=$db::table('whatsapp_inbox_messages')->orderBy('id')->get()->toJson();
    $policy=$db::table('meta_ai_controls')->first();$out=probeObserve($conversation);$json=json_encode($out,JSON_THROW_ON_ERROR);
    probeExpect(($out['status']??null)==='READ_READY'&&$out['read_only_session']===true&&$out['driver']==='sqlite','actual bounded native observation succeeds '.$case);
    probeExpect($out['observation_unchanged']===true&&$out['scan_unchanged']===true&&$out['floor_unchanged']===true
        &&$out['control_revision_unchanged']===true,'scope floor scan control and snapshot stay unchanged '.$case);
    probeExpect($out['classification']['snapshot_probe_present']===in_array($case,['coded','operational'],true),'only unresolved coded and operational boundaries block the effective episode '.$case);
    $coded=str_contains($case,'coded');
    probeExpect($out['classification']['historical_coded_probe_present']===$coded
        &&$out['classification']['last_coded_probe_ordinal']===($coded?1:null),'historical coded marker remains observable after the new episode '.$case);
    probeExpect($out['classification']['operational_test_unresolved']===($case==='operational'),'exact unresolved operational test identified '.$case);
    probeExpect($out['classification']['latest_customer_ordinal']===3&&$out['classification']['latest_customer_probe_present']===false,'latest native customer remains independently clean '.$case);
    probeExpect($out['rows'][0]['coded_text']===$coded&&$out['rows'][0]['coded_cart']===false,'coded marker source is text only '.$case);
    probeExpect($out['rows'][0]['operational_test_statement']===str_starts_with($case,'operational'),'only exact customer test statement classified '.$case);
    probeExpect($out['classification']['new_order_after_test_ordinal']===($case==='operational-new-order'?3:null),'explicit order boundary is only after an operational test '.$case);
    probeExpect($out['classification']['episode_first_ordinal']===(str_contains($case,'new-order')?3:1),'only an explicit new order after the latest marker separates the episode '.$case);
    probeExpect(!str_contains($json,'PRIVATE')&&!str_contains($json,'WA-1234')&&!str_contains($json,'اختبار اتصال')
        &&!str_contains($json,'message_key')&&!str_contains($json,'recipient')&&!str_contains($json,'extraction'),'no raw text keys marker values customer data or exception prose emitted '.$case);
    probeExpect($db::table('whatsapp_order_scans')->first()==$scan&&$db::table('meta_ai_controls')->first()==$policy
        &&$db::table('whatsapp_inbox_messages')->orderBy('id')->get()->toJson()===$native,'actual retry counters leases policy and native bytes unchanged '.$case);
    try { $db::table('whatsapp_order_scans')->where('conversation_id',$conversation)->update(['attempts'=>0]); probeExpect(false,'read-only session prevents write'); }
    catch (Throwable $ignored) { probeExpect(true,'SQL query_only prevents even a deliberate write attempt'); }
    probeNoBoundaryCalls();probeWritable();
}

// A cart field is independent of native message text and never leaks its contents.
primarySetup();$peer='US.probe-cart';$conversation=addConversation($peer);
$m=['id'=>'wamid.PRIVATE_CART_ID','timestamp'=>(string)strtotime('2026-10-09 22:00:01 UTC'),'type'=>'order',
    'from'=>'201000000001','from_user_id'=>$peer,'order'=>['catalog_id'=>'1234567890123','text'=>'PRIVATE_CART WA-9876',
        'product_items'=>[['product_retailer_id'=>'PRIVATE_SKU','quantity'=>1,'item_price'=>'99.00','currency'=>'EGP']]]];
$payload=['object'=>'whatsapp_business_account','entry'=>[['id'=>'468336579702269','changes'=>[['field'=>'messages',
    'value'=>['messaging_product'=>'whatsapp','metadata'=>['phone_number_id'=>'515388018324075'],'messages'=>[$m]]]]]]];
$report=\App\Support\WhatsAppInboxProtocol::report($payload,'468336579702269','515388018324075');
probeExpect(count($report['messages'])===1,'actual native cart normalizer accepts fixture');$dto=$report['messages'][0];
$db::table('whatsapp_inbox_messages')->insert(['conversation_id'=>$conversation,
    'message_key'=>hash('sha256','whatsapp-message-v1:468336579702269:515388018324075:'.$m['id']),
    'direction'=>$dto['direction'],'type'=>$dto['type'],'source'=>$dto['source'],'sent_at'=>$dto['sent_at'],
    'content'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($dto)),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
insertMessage($conversation,'wamid.PRIVATE_CART_NEXT','inbound','PRIVATE_LATEST_GREETING',2,null,$peer);
$out=probeObserve($conversation);
probeExpect($out['status']==='READ_READY'&&$out['rows'][0]['native_kind']==='order'
    &&$out['rows'][0]['coded_cart']===true&&$out['rows'][0]['coded_text']===false,'native and cart coded sources distinguished correctly');
probeExpect(!str_contains(json_encode($out),'PRIVATE')&&!str_contains(json_encode($out),'WA-9876'),'cart SKU catalog text and marker never emitted');
probeNoBoundaryCalls();probeWritable();

foreach (['foreign','bad-native','too-many','too-large'] as $case) {
    primarySetup();$conversation=primaryInbound('PRIVATE_CIPHER_AND_PHONE');
    if ($case==='foreign') $db::table('whatsapp_inbox_conversations')->where('id',$conversation)->update(['waba_id'=>'PRIVATE_FOREIGN_ACCOUNT']);
    if ($case==='bad-native') $db::table('whatsapp_inbox_messages')->update(['content'=>'PRIVATE_BROKEN_CIPHERTEXT']);
    if ($case==='too-many') for ($i=2;$i<=61;$i++) insertMessage($conversation,'wamid.PRIVATE_LIMIT_'.$i,'inbound','PRIVATE_MESSAGE',$i,null,'US.primary-fixture');
    if ($case==='too-large') $db::table('whatsapp_inbox_messages')->update(['content'=>str_repeat('X',4194305)]);
    $out=probeObserve($conversation);$json=json_encode($out);
    probeExpect($out['status']==='READ_FAILED'&&$out['stage']===match($case){'foreign'=>'SCOPE','bad-native'=>'SNAPSHOT',default=>'BOUNDS'},'bounded failure stage is fixed and typed '.$case);
    probeExpect(!isset($out['rows'],$out['classification'])&&!str_contains($json,'PRIVATE')&&!str_contains($json,'Exception'),'failed observation emits no partial plaintext or exception strings '.$case);
    probeNoBoundaryCalls();probeWritable();
}
primarySetup();$conversation=primaryInbound('PRIVATE_STABILITY');
$changingControl=new class {
    private int $reads=0;
    public function processingGate($channel):array { $gate=(new PrimaryFixtureControl())->processingGate($channel);$gate['revision']=++$this->reads;return $gate; }
};
$out=probeObserve($conversation,null,$changingControl);
probeExpect($out['status']==='OBSERVATION_CHANGED'&&$out['control_revision_unchanged']===false&&$out['observation_unchanged']===false,'revision drift is observed without pretending to provide a stable diagnosis');
probeNoBoundaryCalls();probeWritable();
primarySetup();$conversation=primaryInbound('PRIVATE_FLOOR_CHANGE');
$movingFloor=new class extends \App\Services\Dashboard\WhatsAppOrderWorkflow {
    private int $reads=0;
    private function automaticFloor(int $conversation,?object $scan=null,bool $lock=false):int { return ++$this->reads===1?0:1; }
};
$out=probeObserve($conversation,$movingFloor);
probeExpect($out['status']==='OBSERVATION_CHANGED'&&$out['floor_unchanged']===false&&$out['observation_unchanged']===false,
    'a changed effective processing floor prevents a stable boundary claim even when scan and messages are identical');
probeNoBoundaryCalls();probeWritable();
echo 'META_AI_PROBE_BOUNDARY_STATUS_TESTS='.$probeChecks." PASS\n";
