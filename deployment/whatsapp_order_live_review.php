<?php
// Projects at most 100 events and analyzes one live segment for human review only.
ini_set('display_errors', '0');
$report = ['operation' => 'LIVE_REVIEW_ONLY', 'scope' => 'FASAKHANSTA_WHATSAPP', 'status' => 'STARTING'];
try {
    if (PHP_SAPI !== 'cli' || $argc !== 1 || !chdir('/home/fasakha/public_html')) throw new \RuntimeException();
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('whatsapp_orders.mode') !== 'review' || config('whatsapp_orders.model') !== 'gpt-6-luna'
        || !in_array(config('whatsapp_orders.enabled'), [true, 1, '1'], true)) throw new \RuntimeException();
    $activation = config('whatsapp_orders.activation_message_id');
    if (!is_int($activation) && !(is_string($activation) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $activation))) {
        throw new \RuntimeException();
    }
    if ((int) $activation < 0 || (string) (int) $activation !== (string) $activation) throw new \RuntimeException();
    $report['activation_message_id'] = (int) $activation;
    $actor = app(\App\Services\Dashboard\WhatsAppInboxAccess::class)
        ->actor(\App\Models\User::withoutGlobalScopes()->find(1));
    if ((app(\App\Services\Dashboard\TakeawayAccess::class)->permissions($actor)['can_checkout'] ?? false) !== true) {
        throw new \RuntimeException();
    }
    $workflow = app(\App\Services\Dashboard\WhatsAppOrderWorkflow::class);
    if (!$workflow->available()) throw new \RuntimeException();
    $capture = app(\App\Services\Dashboard\WhatsAppInboxConsumer::class)->consume(100);
    $report['capture'] = [];
    foreach (['events_seen', 'events_processed', 'events_skipped', 'messages_inserted', 'messages_replayed',
        'quarantined_events', 'ignored_records', 'status_records', 'errors'] as $key) {
        $report['capture'][$key] = is_int($capture[$key] ?? null) ? $capture[$key] : null;
    }
    if (($report['capture']['errors'] ?? null) !== 0) {
        $report['status'] = 'CAPTURE_ERRORS';
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        exit(1);
    }
    $db = \Illuminate\Support\Facades\DB::class;
    $access = \App\Services\Dashboard\WhatsAppInboxAccess::class;
    $floors = $db::table('whatsapp_order_drafts')->where('status', 'DISPATCHED')
        ->select('conversation_id')->selectRaw('MAX(evidence_ceiling) AS floor')->groupBy('conversation_id');
    $segments = $db::table('whatsapp_inbox_conversations as c')
        ->leftJoinSub($floors, 'd', static function ($join) { $join->on('d.conversation_id', '=', 'c.id'); })
        ->join('whatsapp_inbox_messages as m', 'm.conversation_id', '=', 'c.id')
        ->where('c.waba_id', $access::WABA_ID)->where('c.phone_number_id', $access::PHONE_ID)
        ->whereRaw('m.id > COALESCE(d.floor, 0)')->select('c.id')
        ->selectRaw('COALESCE(d.floor, 0) AS floor, MAX(m.id) AS ceiling, COUNT(*) AS messages')
        ->selectRaw('SUM(CASE WHEN m.direction = ? THEN 1 ELSE 0 END) AS inbound', ['inbound'])
        ->selectRaw('SUM(CASE WHEN m.direction = ? THEN 1 ELSE 0 END) AS outbound', ['outbound'])
        ->groupBy('c.id', 'd.floor');
    $candidate = (clone $segments)->havingRaw('COUNT(*) <= 60')
        ->havingRaw('SUM(CASE WHEN m.direction = ? THEN 1 ELSE 0 END) > 0', ['inbound'])
        ->havingRaw('SUM(CASE WHEN m.direction = ? THEN 1 ELSE 0 END) > 0', ['outbound'])
        ->havingRaw('MAX(m.id) > ?', [(int) $activation])->orderByDesc('ceiling')->first();
    $counts = static fn ($row) => ['conversation_id' => (int) $row->id,
        'floor' => (int) $row->floor, 'ceiling' => (int) $row->ceiling, 'messages' => (int) $row->messages,
        'inbound' => (int) $row->inbound, 'outbound' => (int) $row->outbound,
        'new_since_activation' => (int) $row->ceiling > (int) $activation,
        'within_max_60_messages' => (int) $row->messages <= 60,
        'has_both_directions' => (int) $row->inbound > 0 && (int) $row->outbound > 0];
    if ($candidate === null) {
        $report['status'] = 'NO_ELIGIBLE_CONVERSATION';
        $report['latest_scoped_segments'] = (clone $segments)->orderByDesc('ceiling')->limit(10)->get()->map($counts)->all();
    } else {
        $report['selected_segment_before_analysis'] = $counts($candidate);
        $before = $db::table('whatsapp_order_drafts')->where('conversation_id', $candidate->id)
            ->where('evidence_ceiling', $candidate->ceiling)->first(['id', 'status']);
        $report['cached_draft_at_ceiling_before'] = $before !== null;
        if (($before->status ?? null) === 'DISPATCHED') {
            $report['status'] = 'ALREADY_DISPATCHED';
        } else {
            // Public manual analysis cannot quote, dispatch, save customers or send replies.
            $result = $workflow->analyze((int) $candidate->id, $actor, false);
            $draftId = $result['draft']['id'] ?? null;
            if (!is_int($draftId) || $draftId < 1) throw new \RuntimeException();
            unset($result);
            $draft = $db::table('whatsapp_order_drafts as d')
                ->join('whatsapp_inbox_conversations as c', 'c.id', '=', 'd.conversation_id')
                ->where('d.id', $draftId)->where('d.conversation_id', $candidate->id)
                ->where('c.waba_id', $access::WABA_ID)->where('c.phone_number_id', $access::PHONE_ID)
                ->first(['d.id', 'd.conversation_id', 'd.status', 'd.reason', 'd.extraction', 'd.evidence_floor', 'd.evidence_ceiling']);
            if (!$draft || !in_array($draft->status, ['REVIEW', 'READY', 'NONE', 'CANCELLED', 'DISPATCHED'], true)) {
                throw new \RuntimeException();
            }
            $data = null;
            if ($draft->extraction !== null) {
                if (!is_string($draft->extraction) || strlen($draft->extraction) > 262144) throw new \RuntimeException();
                $data = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($draft->extraction), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !in_array($data['decision'] ?? null, ['NONE', 'DRAFT', 'CONFIRMED', 'CANCELLED'], true)
                    || !is_array($data['evidence'] ?? null)) throw new \RuntimeException();
            }
            $evidence = [];
            foreach (['request_ids', 'confirmation_ids', 'customer_acceptance_ids'] as $key) {
                $ids = $data['evidence'][$key] ?? [];
                if (!is_array($ids) || !array_is_list($ids) || count($ids) > 60) throw new \RuntimeException();
                foreach ($ids as $id) if (!is_string($id) || !preg_match('/\Am(?:[1-9]|[1-5][0-9]|60)\z/', $id)) {
                    throw new \RuntimeException();
                }
                $evidence[$key] = count($ids);
            }
            $reasons = ['CONTEXT_INCOMPLETE', 'AI_UNAVAILABLE', 'INVALID_EXTRACTION', 'STALE_TRANSCRIPT', 'NO_ORDER',
                'CUSTOMER_CANCELLED', 'REVIEW_REQUIRED', 'PICKUP_REQUIRES_REVIEW', 'BRANCH_UNRESOLVED', 'CATALOG_UNRESOLVED',
                'LOCATION_UNCONFIRMED', 'PRICE_REQUIRES_REVIEW', 'CUSTOMER_CHANGE', 'BRANCH_CLOSED', 'BRANCH_POLICY_UNAVAILABLE',
                'AUTO_NOT_CONFIGURED', 'PROCESS_FAILED', 'CAPTURE_REVIEW_REQUIRED'];
            $report['status'] = 'ANALYSIS_COMPLETED';
            $report['returned_existing_draft'] = $before !== null && (int) $before->id === (int) $draft->id
                && (int) $draft->evidence_ceiling === (int) $candidate->ceiling;
            $report['draft'] = ['id' => (int) $draft->id, 'conversation_id' => (int) $draft->conversation_id,
                'status' => $draft->status, 'reason' => $draft->reason === null ? null
                    : (in_array($draft->reason, $reasons, true) ? $draft->reason : 'UNKNOWN'),
                'decision' => $data['decision'] ?? null, 'evidence_floor' => (int) $draft->evidence_floor,
                'evidence_ceiling' => (int) $draft->evidence_ceiling, 'evidence_counts' => $evidence];
        }
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $codes = ['ANALYSIS_BUSY', 'ANALYSIS_BACKOFF', 'ANALYSIS_RETRY_LIMIT', 'STALE_TRANSCRIPT', 'ANALYSIS_LEASE_LOST'];
    $report['status'] = in_array($e->getMessage(), $codes, true) ? $e->getMessage()
        : ($e->getStatusCode() === 409 ? 'REVIEW_CONFLICT' : 'REVIEW_ACCESS_OR_STATE_FAILED');
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    exit(1);
} catch (\Throwable $e) {
    $report['status'] = 'REVIEW_FLOW_CHECK_FAILED';
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    exit(1);
}
