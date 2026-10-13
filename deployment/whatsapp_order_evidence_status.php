<?php
// Fixed read-only observation for the reported conversation. No provider or workflow processing.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
$out = ['diagnostic'=>'READ_ONLY_ORDER_EVIDENCE_STATUS','writes'=>0,'ai_calls'=>0];
$stage = 'OWNER';
try {
    $owner = posix_getpwnam('fasakha');
    if (!is_array($owner) || posix_geteuid() !== $owner['uid']) throw new \RuntimeException();
    $stage = 'BOOTSTRAP';
    chdir('/home/fasakha/public_html');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::class;
    $class = \App\Services\Dashboard\WhatsAppOrderWorkflow::class;
    $file = (new \ReflectionClass($class))->getFileName();
    $bytes = file_get_contents($file);
    $out['workflow_blob'] = sha1('blob '.strlen($bytes)."\0".$bytes);
    if ($out['workflow_blob'] !== 'b30f25cf6dfc4a84061a58316db583f892a439a4') throw new \RuntimeException();
    $out['enabled'] = (bool) config('whatsapp_orders.enabled');
    $out['mode'] = config('whatsapp_orders.mode');
    $out['activation_at_utc'] = config('whatsapp_cart.activated_at');
    $cid = 11;
    $stage = 'CONVERSATION';
    $thread = $db::table('whatsapp_inbox_conversations')->where('id',$cid)
        ->where('waba_id','468336579702269')->where('phone_number_id','515388018324075')->first(['id']);
    if (!$thread) throw new \RuntimeException();
    $out['conversation_id'] = $cid;
    $out['message_sources'] = $db::table('whatsapp_inbox_messages')->where('conversation_id',$cid)
        ->select('source')->selectRaw('COUNT(*) AS total')->groupBy('source')->get();
    $method = new \ReflectionMethod($class,'safeAnalysisFailure'); $method->setAccessible(true);
    foreach ($db::table('whatsapp_order_drafts')->where('conversation_id',$cid)->orderByDesc('id')->limit(5)->get() as $row) {
        $data = null;
        if (is_string($row->extraction)) {
            $plain = \Illuminate\Support\Facades\Crypt::decryptString($row->extraction);
            if (strlen($plain)>1048576) throw new \RuntimeException();
            $data = json_decode($plain,true,64,JSON_THROW_ON_ERROR);
        }
        $out['drafts'][] = ['id'=>(int)$row->id,'status'=>$row->status,'reason'=>$row->reason,
            'floor'=>(int)$row->evidence_floor,'ceiling'=>(int)$row->evidence_ceiling,
            'items_count'=>count($data['items']??[]),'ticket_id'=>$row->ticket_id,
            'branch'=>$row->assigned_branch,'updated_at'=>$row->updated_at,
            'analysis_failure'=>isset($data['analysis_failure'])
                ? $method->invoke(app($class),$data['analysis_failure']) : null];
    }
    $stage = 'CAPTURE_BLOCKERS';
    $floor = config('whatsapp_orders.activation_event_id');
    if (!is_numeric($floor) || (int)$floor<0) throw new \RuntimeException();
    $failures = $db::table('whatsapp_inbox_ingestion_failures')->where('event_id','>',(int)$floor)
        ->orderBy('event_id')->limit(21)->get(['event_id','reason','attempts']);
    $out['capture_blockers_truncated'] = $failures->count()>20;
    $out['capture_blockers'] = $failures->take(20)->values()->all();
    $out['pending_raw_events'] = $db::table('whatsapp_webhook_events as e')->whereNull('e.processed_at')
        ->whereNotExists(function($q) use($db) {
            $q->select($db::raw('1'))->from('whatsapp_inbox_ingestion_failures as f')->whereColumn('f.event_id','e.id');
        })->count();
    while (ob_get_level()) ob_end_clean();
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch (\Throwable $error) {
    while (ob_get_level()) ob_end_clean();
    $out['status']='FAILED'; $out['stage']=$stage;
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
    exit(1);
}
