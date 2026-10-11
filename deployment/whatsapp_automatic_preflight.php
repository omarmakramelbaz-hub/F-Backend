<?php
// Bounded read-only runtime inventory: no AI, external requests, messages or orders.
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ob_start();
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1 || !chdir('/home/fasakha/public_html')) throw new \RuntimeException();
    require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();
    $db = \Illuminate\Support\Facades\DB::class;
    $schema = \Illuminate\Support\Facades\Schema::class;
    $scope = static fn ($query) => $query->where('c.waba_id', '468336579702269')
        ->where('c.phone_number_id', '515388018324075');
    $flag = static fn ($v) => in_array($v, [true, 1, '1'], true);
    $configured = static fn ($v) => is_string($v) && trim($v) !== '';
    $safeId = static function ($v) {
        $s = (string) $v;
        return (is_int($v) || is_string($v)) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $s)
            ? $s : null;
    };
    $safeTime = static fn ($v) => is_string($v) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $v)
        ? $v : null;
    $pins = [
        'app/Services/Dashboard/WhatsAppReplyService.php'=>'7047caefba93935a25029ea99715dac645d9c24d',
        'public/js/dashboard-whatsapp-inbox.js'=>'19117235ba454c5e73bbd291920f9f56c84ebea8',
        'public/js/dashboard-whatsapp-replies.js'=>'304a0bc6acd2474adb7850664708f30bddbd6a29',
        'resources/views/admin/whatsapp/index.blade.php'=>'d56fe43423728bb9d48820cc51b34522cc1ad7cc',
        'resources/views/admin/whatsapp/replies.blade.php'=>'ac114cfb5524f91a7e8753e75a751d82b43af965',
        'resources/views/admin/layouts/footer.blade.php'=>'9dc3e2dfef9cd1bf94a1cf515aee57a30ba2d434',
        'app/Services/Dashboard/WhatsAppOrderWorkflow.php'=>'27603aae0a454edda0325ef244360e11ee6f3550',
        'app/Services/Dashboard/WhatsAppOrderAiProvider.php'=>'afac5b86bb3076a6caaea85c4a22d097a22e16b8',
        'app/Support/WhatsAppOrderExtraction.php'=>'0411582129f36ceb1c0d634245f37a09b445efea',
        'app/Console/Commands/ProcessWhatsAppOrders.php'=>'b7b18533efe8eb608315e0efbc6740080b25e1a7',
        'app/Services/Dashboard/WhatsAppInboxConsumer.php'=>'f993ff1eebc9c2a7be3b0bec93ab02732b96a46d',
        'app/Support/WhatsAppInboxProtocol.php'=>'7a2f342c86e0838fb920bfdea53d6542b01cd008',
        'config/whatsapp_orders.php'=>'88e3366e17d0d011b5c118220423e444adc9c2ad',
        'config/whatsapp_cart.php'=>'fc02576f4e7896c7cee33152561ca76c69b669cd',
        'config/whatsapp_replies.php'=>'c5ede60634743a6170b143a4fd4fd382d6afe9d6',
        'routes/whatsapp_orders.php'=>'b1932548cd0cd3af5d35bf2cc489df42f9faa8e9',
        'database/migrations/2026_10_10_000002_create_whatsapp_order_drafts.php'=>'5e8a0cb6b4f2f085fb6b35cc930570c7cc94a87b',
    ];
    $fileInfo = static function ($path, $hash) {
        $s = @lstat($path); if (!$s) return ['exists'=>false];
        $regular = ($s['mode'] & 0170000) === 0100000;
        $out = ['exists'=>true, 'regular'=>$regular, 'mode'=>sprintf('%04o', $s['mode'] & 0777),
            'links'=>$s['nlink'], 'owner_matches'=>function_exists('posix_geteuid') ? $s['uid'] === posix_geteuid() : null];
        if ($hash && $regular && $s['size'] >= 0 && $s['size'] <= 2097152) {
            $bytes = @file_get_contents($path);
            if (is_string($bytes)) $out['blob'] = sha1('blob '.strlen($bytes)."\0".$bytes);
        }
        return $out;
    };
    $report = ['diagnostic'=>'WHATSAPP_AUTOMATIC_READ_ONLY_PREFLIGHT', 'network_requests'=>0,
        'ai_calls'=>0, 'messages_sent'=>0, 'orders_created'=>0, 'database_writes'=>0,
        'configuration_writes'=>0, 'php_version'=>PHP_VERSION, 'runtime_files'=>[]];
    foreach ($pins as $path=>$pin) {
        $report['runtime_files'][$path] = $fileInfo($path, true);
        $report['runtime_files'][$path]['matches_reference_692320'] =
            ($report['runtime_files'][$path]['blob'] ?? null) === $pin;
    }
    $report['core_erp_files'] = [];
    foreach (['PhoneDelivery','BranchCustomers','PosServiceTicket','TakeawayCatalog','TakeawayAccess','PosServicePhone'] as $name) {
        $path='app/Services/Dashboard/'.$name.'.php';
        $report['core_erp_files'][$path]=$fileInfo($path,true);
    }
    $orders = (array) config('whatsapp_orders', []); $replies = (array) config('whatsapp_replies', []);
    $cart = (array) config('whatsapp_cart', []);
    $allowed = $orders['allowed_branch_ids'] ?? [];
    $allowed = is_array($allowed) ? array_values(array_filter(array_map($safeId, $allowed), static fn ($v)=>$v !== null && $v !== '0')) : [];
    $report['configuration'] = ['orders_enabled'=>$flag($orders['enabled'] ?? false),
        'mode'=>in_array($orders['mode'] ?? null, ['review','auto'], true) ? $orders['mode'] : 'UNKNOWN',
        'model_is_gpt_6_luna'=>($orders['model'] ?? null) === 'gpt-6-luna',
        'api_key_configured'=>$configured($orders['api_key'] ?? null),
        'manual_replies_enabled'=>$flag($replies['enabled'] ?? false),
        'whatsapp_token_configured'=>$configured($replies['access_token'] ?? null),
        'automation_actor_id'=>$safeId($orders['automation_actor_id'] ?? null),
        'allowed_branch_ids'=>array_slice($allowed, 0, 100), 'allowed_branch_ids_truncated'=>count($allowed)>100,
        'activation_message_id'=>$safeId($orders['activation_message_id'] ?? null),
        'activation_event_id'=>$safeId($orders['activation_event_id'] ?? null),
        'activated_at_utc'=>$safeTime($cart['activated_at'] ?? null),
        'debounce_seconds'=>is_int($cart['debounce_seconds'] ?? null) ? $cart['debounce_seconds'] : null];
    $report['private_file_metadata'] = ['env'=>$fileInfo('.env', false),
        'config_cache'=>$fileInfo('bootstrap/cache/config.php', false)];
    $counts = ['visited'=>0, 'objects'=>0, 'resources'=>0, 'truncated'=>false];
    $walk = static function ($value, $depth=0) use (&$walk, &$counts) {
        if (++$counts['visited'] > 20000 || $depth > 30) { $counts['truncated']=true; return; }
        if (is_array($value)) foreach ($value as $child) $walk($child, $depth+1);
        elseif (is_object($value)) $counts['objects']++;
        elseif (is_resource($value)) $counts['resources']++;
    };
    $walk(config()->all());
    $report['cache_shape'] = $counts;
    $cached = $app->getCachedConfigPath();
    $report['cache_shape']['path_is_standard'] = $cached === getcwd().'/bootstrap/cache/config.php';
    $report['cache_shape']['fully_qualified_names']=null;
    $report['cache_shape']['static_access_tokens']=null;
    if ($cached === getcwd().'/bootstrap/cache/config.php' && is_file($cached) && !is_link($cached)
        && filesize($cached) <= 8388608 && function_exists('token_get_all')) {
        $bytes=file_get_contents($cached); $fq=0; $static=0;
        foreach (token_get_all($bytes) as $token) if (is_array($token)) {
            if ($token[0] === T_NAME_FULLY_QUALIFIED) $fq++;
            if ($token[0] === T_DOUBLE_COLON) $static++;
        }
        $report['cache_shape']['fully_qualified_names']=$fq;
        $report['cache_shape']['static_access_tokens']=$static;
    }
    $commands = $kernel->all();
    $report['runtime_guards'] = ['process_orders_command'=>array_key_exists('whatsapp:process-orders', $commands),
        'consume_inbox_command'=>array_key_exists('whatsapp:consume-inbox', $commands)];
    foreach ([\App\Services\Dashboard\WhatsAppOrderWorkflow::class=>'AUTOMATIC_ACTIVATION_TIME_GUARD',
        \App\Services\Dashboard\WhatsAppInboxConsumer::class=>'QUARANTINE_BATCH_RECHECK'] as $class=>$constant) {
        try { $r=new \ReflectionClass($class); $v=$r->hasConstant($constant) ? $r->getConstant($constant) : null;
            $report['runtime_guards'][$constant] = is_bool($v) || $v === 'whatsapp-auto-activation-time-v1' ? $v : 'MISSING_OR_OTHER';
        } catch (\Throwable $e) { $report['runtime_guards'][$constant]='UNAVAILABLE'; }
    }
    try { $report['runtime_guards']['workflow_available']=app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class)->available(); }
    catch (\Throwable $e) { $report['runtime_guards']['workflow_available']=false; }
    $requirements = [
        'whatsapp_webhook_events'=>['id','processed_at'],
        'whatsapp_inbox_conversations'=>['id','waba_id','phone_number_id'],
        'whatsapp_inbox_messages'=>['id','conversation_id','direction','source','type'],
        'whatsapp_inbox_ingestion_failures'=>['event_id'],
        'whatsapp_order_drafts'=>['id','conversation_id','status','evidence_floor','evidence_ceiling','ticket_id','dispatched_at'],
        'whatsapp_order_scans'=>['conversation_id','analyzed_ceiling','claimed_ceiling','auto_floor','lease_until','next_attempt_at','attempts'],
    ];
    $report['schema']=[]; $ready=[];
    foreach ($requirements as $table=>$columns) {
        try { $exists=$schema::hasTable($table); $missing=$exists ? array_values(array_diff($columns, $schema::getColumnListing($table))) : $columns;
            $ready[$table]=$exists && $missing===[];
            $report['schema'][$table]=['exists'=>$exists,'missing_columns'=>$missing];
        } catch (\Throwable $e) { $ready[$table]=false; $report['schema'][$table]=['status'=>'CHECK_FAILED']; }
    }
    $report['inventory']=[];
    if ($ready['whatsapp_webhook_events'] && $ready['whatsapp_inbox_ingestion_failures']) {
        try {
            $raw=$db::table('whatsapp_webhook_events');
            $report['inventory']['raw_events']=['max_id'=>(int)$raw->max('id'),
                'unprocessed'=>(int)$db::table('whatsapp_webhook_events')->whereNull('processed_at')->count(),
                'pending_runnable'=>(int)$db::table('whatsapp_webhook_events as e')->whereNull('e.processed_at')
                    ->whereNotExists(function ($q) { $q->select('event_id')->from('whatsapp_inbox_ingestion_failures as f')->whereColumn('f.event_id','e.id'); })->count(),
                'quarantined'=>(int)$db::table('whatsapp_inbox_ingestion_failures')->count()];
        } catch (\Throwable $e) { $report['inventory']['raw_events']=['status'=>'CHECK_FAILED']; }
    }
    if ($ready['whatsapp_inbox_conversations'] && $ready['whatsapp_inbox_messages']) {
        try { $report['inventory']['messages']=['max_scoped_id'=>(int)$scope($db::table('whatsapp_inbox_messages as m')
                ->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id'))->max('m.id'),
            'scoped_conversations'=>(int)$scope($db::table('whatsapp_inbox_conversations as c'))->count()];
            $bySource=[];
            foreach ($scope($db::table('whatsapp_inbox_messages as m')->join('whatsapp_inbox_conversations as c','c.id','=','m.conversation_id'))
                ->select('m.direction','m.source')->selectRaw('COUNT(*) AS n')->groupBy('m.direction','m.source')->limit(25)->get() as $row) {
                $bySource[]=['direction'=>in_array($row->direction,['inbound','outbound'],true)?$row->direction:'OTHER',
                    'source'=>in_array($row->source,['messages','smb_message_echoes','standby'],true)?$row->source:'OTHER', 'count'=>(int)$row->n];
            }
            $report['inventory']['messages']['by_direction_source']=$bySource;
        }
        catch (\Throwable $e) { $report['inventory']['messages']=['status'=>'CHECK_FAILED']; }
    }
    if ($ready['whatsapp_order_drafts'] && $ready['whatsapp_inbox_conversations']) {
        try {
            $q=static fn ()=>$scope($db::table('whatsapp_order_drafts as d')->join('whatsapp_inbox_conversations as c','c.id','=','d.conversation_id'));
            $statuses=[];
            foreach ($q()->select('d.status')->selectRaw('COUNT(*) AS n')->groupBy('d.status')->limit(25)->get() as $row)
                $statuses[is_string($row->status) && preg_match('/\A[A-Z_]{1,24}\z/', $row->status) ? $row->status : 'OTHER']=(int)$row->n;
            $report['inventory']['drafts']=['total'=>(int)$q()->count(),'max_id'=>(int)$q()->max('d.id'),
                'statuses'=>$statuses,'dispatched'=>(int)$q()->whereNotNull('d.dispatched_at')->count(),
                'max_evidence_ceiling'=>(int)$q()->max('d.evidence_ceiling')];
        } catch (\Throwable $e) { $report['inventory']['drafts']=['status'=>'CHECK_FAILED']; }
    }
    if ($ready['whatsapp_order_scans'] && $ready['whatsapp_inbox_conversations']) {
        try {
            $q=static fn ()=>$scope($db::table('whatsapp_order_scans as s')->join('whatsapp_inbox_conversations as c','c.id','=','s.conversation_id'));
            $report['inventory']['scans']=['total'=>(int)$q()->count(),
                'min_auto_floor'=>$q()->min('s.auto_floor'), 'max_auto_floor'=>$q()->max('s.auto_floor'),
                'max_analyzed_ceiling'=>(int)$q()->max('s.analyzed_ceiling'),
                'max_claimed_ceiling'=>(int)$q()->max('s.claimed_ceiling'),
                'active_leases'=>(int)$q()->where('s.lease_until','>',gmdate('Y-m-d H:i:s'))->count(),
                'scheduled_retries'=>(int)$q()->whereNotNull('s.next_attempt_at')->count()];
        } catch (\Throwable $e) { $report['inventory']['scans']=['status'=>'CHECK_FAILED']; }
    }
    $mappings=$cart['product_mappings'] ?? []; $names=$cart['product_names'] ?? []; $mappingEntries=0;
    if (is_array($mappings)) foreach ($mappings as $products) if (is_array($products)) foreach ($products as $scopes)
        if (is_array($scopes)) $mappingEntries+=count($scopes);
    $report['catalog_setup']=['mapping_catalogs'=>is_array($mappings)?count($mappings):null,
        'mapping_entries'=>$mappingEntries,'named_catalogs'=>is_array($names)?count($names):null,
        'network_catalog_access_checked'=>false];
    $report['actor_and_branches']=['actor_id'=>1,'status'=>'CHECK_FAILED','branches'=>[]];
    try {
        $actor=\App\Models\User::withoutGlobalScopes()->find(1);
        $actor=app(\App\Services\Dashboard\WhatsAppInboxAccess::class)->actor($actor);
        $access=app(\App\Services\Dashboard\TakeawayAccess::class);
        $report['actor_and_branches']['can_checkout']=($access->permissions($actor)['can_checkout'] ?? false)===true;
        $report['actor_and_branches']['status']='READY';
        $catalog=app(\App\Services\Dashboard\TakeawayCatalog::class);
        foreach (['94','301','307','363'] as $id) {
            $entry=['id'=>(int)$id,'status'=>'CHECK_FAILED'];
            try {
                $branch=$access->branch('f:'.$id,$actor); $entry['status']='READY';
                $entry['name']=(string)($branch['name'] ?? '');
                $entry['delivery_settings_ready']=(app(\App\Services\Dashboard\PhoneDelivery::class)->settings('f:'.$id,$actor)['ready'] ?? false)===true;
                $items=[]; $truncated=false;
                for ($page=1;$page<=3;$page++) {
                    $list=$catalog->listing(['branch'=>'f:'.$id,'page'=>$page,'per_page'=>100],$actor);
                    foreach (array_slice($list['items'] ?? [],0,100) as $item) $items[]=$item;
                    if ((int)($list['pagination']['last_page'] ?? 1)<=$page) break;
                    if ($page===3) $truncated=true;
                }
                $entry['products_scanned']=count($items); $entry['products_truncated']=$truncated;
                $entry['available_products']=count(array_filter($items,static fn ($p)=>($p['available'] ?? false)===true));
                $checked=0;$missing=0;
                if (is_array($mappings)) foreach ($mappings as $products) if (is_array($products)) foreach ($products as $scopes) {
                    $mapping=is_array($scopes)?($scopes['f:'.$id] ?? null):null; if (!is_array($mapping)) continue;
                    $checked++; $matches=array_filter($items, static fn ($p)=>(string)($p['id'] ?? '')===(string)($mapping['product_id'] ?? '') && ($p['available'] ?? false)===true);
                    if (count($matches)!==1) $missing++;
                }
                $entry['mapping_entries_checked']=$checked; $entry['mapping_products_unavailable_or_missing']=$missing;
            } catch (\Throwable $e) { $entry['status']='CHECK_FAILED'; }
            $report['actor_and_branches']['branches'][]=$entry;
        }
    } catch (\Throwable $e) {}
    while (ob_get_level()>0) ob_end_clean();
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
} catch (\Throwable $e) {
    while (ob_get_level()>0) ob_end_clean();
    echo "AUTOMATIC_PREFLIGHT_FAILED_WITHOUT_SECRET_OUTPUT\n"; exit(1);
}
