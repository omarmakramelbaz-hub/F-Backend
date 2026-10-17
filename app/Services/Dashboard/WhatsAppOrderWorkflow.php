<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\WhatsAppOrderExtraction;
use App\Support\WhatsAppInboxProtocol;
use App\Support\WhatsAppOrderDialogue;
use App\Support\WhatsAppCustomerOrderConfirmation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Suggestions are encrypted; only the existing unpaid phone-order services can dispatch. */
class WhatsAppOrderWorkflow
{
    public const AUTOMATIC_ACTIVATION_TIME_GUARD = 'whatsapp-auto-activation-time-v1';
    protected const DRAFTS = 'whatsapp_order_drafts';
    protected const SCANS = 'whatsapp_order_scans';
    private const PHYSICAL_BRANCH_IDS = ['94','301','307','363'];
    private const MAX_MESSAGES = 60;
    private const MAX_CHARS = 16000;
    private const REASONS = ['CONTEXT_INCOMPLETE', 'AI_UNAVAILABLE', 'INVALID_EXTRACTION', 'STALE_TRANSCRIPT',
        'NO_ORDER', 'CUSTOMER_CANCELLED', 'REVIEW_REQUIRED', 'PICKUP_REQUIRES_REVIEW', 'BRANCH_UNRESOLVED',
        'CATALOG_UNRESOLVED', 'LOCATION_UNCONFIRMED', 'PRICE_REQUIRES_REVIEW', 'CUSTOMER_CHANGE',
        'BRANCH_CLOSED', 'BRANCH_POLICY_UNAVAILABLE', 'AUTO_NOT_CONFIGURED', 'PROCESS_FAILED','CAPTURE_REVIEW_REQUIRED',
        'RECOVERED_HISTORY_REVIEW','DISPATCH_OUTSIDE_HOURS','CUSTOMER_RECAP_REPLAY'];

    public function available(): bool
    {
        return $this->inboxAccess()->available() && Schema::hasTable(static::DRAFTS) && Schema::hasTable(static::SCANS);
    }

    public function state(int $conversation, $actor): array
    {
        $this->actor($actor);
        $control=$this->controlInstalled()?$this->controlGate($conversation):null;
        $enabled=$control===null?(bool)$this->orderConfig('whatsapp_orders.enabled',false):($control['effective']??false)===true;
        if (!$this->available()) return ['success'=>true,'available'=>false,'enabled'=>$enabled,'control'=>$this->controlPresentation($control),
            'mode'=>$this->mode(),'pending_analysis'=>false,'drafts'=>[]];
        $this->conversation($conversation);
        $scan = DB::table(static::SCANS)->where('conversation_id', $conversation)->first();
        $drafts = DB::table(static::DRAFTS)->where('conversation_id', $conversation)->orderByDesc('id')->limit(10)->get();
        return ['success'=>true, 'available'=>true, 'enabled'=>$enabled,'control'=>$this->controlPresentation($control),
            'mode'=>$this->mode(), 'pending_analysis'=>$this->ceiling($conversation) > (int)($scan->analyzed_ceiling ?? 0),
            'automatic'=>['configured'=>$control!==null?$enabled:((bool)$this->orderConfig('whatsapp_orders.enabled',false)&&$this->mode()==='auto'
                &&is_numeric($this->orderConfig('whatsapp_orders.automation_actor_id'))&&(int)$this->orderConfig('whatsapp_orders.automation_actor_id')>0
                &&is_array($this->orderConfig('whatsapp_orders.allowed_branch_ids'))&&$this->orderConfig('whatsapp_orders.allowed_branch_ids')!==[]),
                'debounce_seconds'=>$this->debounceSeconds(),'last_analysis_at'=>$scan->updated_at??null,'next_attempt_at'=>$scan->next_attempt_at??null],
            'drafts'=>$drafts->map(fn($r)=>$this->present($r))->all()];
    }

    public function analyze(int $conversation, $actor, bool $force = false): array
    {
        return $this->analyzeInternal($conversation, $this->actor($actor), $force, false);
    }

    /**
     * CLI repair for a proved customer receipt stranded behind an exhausted scan.
     * This never runs a provider, projector, quote, sender or ERP service. The
     * normal worker independently reviews and dispatches the resulting draft.
     */
    public function recoverCustomerReceipt(int $conversation,array $expected): array
    {
        $keys=['control_revision','floor','ceiling','snapshot_hash','scan_hash'];
        abort_unless(PHP_SAPI==='cli',403,'CUSTOMER_RECEIPT_RECOVERY_CLI_ONLY');
        abort_unless(count($expected)===count($keys)&&array_diff(array_keys($expected),$keys)===[]
            &&is_int($expected['control_revision']??null)&&$expected['control_revision']>=1
            &&is_int($expected['floor']??null)&&$expected['floor']>=0
            &&is_int($expected['ceiling']??null)&&$expected['ceiling']>$expected['floor']
            &&is_string($expected['snapshot_hash']??null)&&preg_match('/\A[a-f0-9]{64}\z/',$expected['snapshot_hash'])
            &&is_string($expected['scan_hash']??null)&&preg_match('/\A[a-f0-9]{64}\z/',$expected['scan_hash']),422,'CUSTOMER_RECEIPT_RECOVERY_EXPECTATION_INVALID');
        $this->ready();
        abort_unless($this->controlInstalled()&&$this->mode()==='auto'&&$this->customerReceiptEnabled($conversation)
            &&$this->automationChannel($conversation)==='whatsapp',403,'CUSTOMER_RECEIPT_RECOVERY_MODE_REQUIRED');
        return DB::transaction(function()use($conversation,$expected) {
            $this->conversation($conversation,true);
            $scan=DB::table(static::SCANS)->where('conversation_id',$conversation)->lockForUpdate()->first();
            abort_unless($scan,409,'CUSTOMER_RECEIPT_RECOVERY_SCAN_MISSING');
            abort_unless($this->customerReceiptEnabled($conversation),403,'CUSTOMER_RECEIPT_RECOVERY_MODE_REQUIRED');
            $authority=$this->controlToken($conversation);
            abort_unless($authority!==null&&$authority['revision']===$expected['control_revision'],409,'AI_CONTROL_CHANGED');
            abort_unless($this->ceiling($conversation,true)===$expected['ceiling'],409,'STALE_TRANSCRIPT');
            $snapshot=$this->snapshot($conversation,$expected['floor'],$expected['ceiling'],true);
            abort_unless(($snapshot['complete']??false)===true
                &&hash_equals($expected['snapshot_hash'],$snapshot['hash']),409,'STALE_TRANSCRIPT');
            $proof=WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']);
            abort_unless(($proof['ok']??false)===true&&!WhatsAppOrderExtraction::hasProbe($snapshot['transcript'])
                &&is_array($proof['data']['textual_location']['point']??null)
                &&$this->validPoint($proof['data']['textual_location']['point']['lat']??null,
                    $proof['data']['textual_location']['point']['long']??null)!==null,409,'CUSTOMER_RECEIPT_RECOVERY_PROOF_REQUIRED');
            $data=$proof['data'];
            $existing=DB::table(static::DRAFTS)->where('conversation_id',$conversation)
                ->where('evidence_ceiling',$expected['ceiling'])->lockForUpdate()->get();
            abort_unless(count($existing)<=1,409,'CUSTOMER_RECEIPT_RECOVERY_DRAFT_CONFLICT');
            if (count($existing)===1) {
                $row=$existing[0]; $stored=$this->decode($row->extraction);
                abort_unless(in_array($row->status,['REVIEW','READY','DISPATCHED'],true)
                    &&(int)$row->evidence_floor===$expected['floor']&&hash_equals((string)$row->evidence_hash,$expected['snapshot_hash'])
                    &&is_array($stored)&&hash_equals(PosServiceTicket::fingerprint($stored),PosServiceTicket::fingerprint($data)),
                    409,'CUSTOMER_RECEIPT_RECOVERY_DRAFT_CONFLICT');
                return ['success'=>true,'status'=>'ALREADY_RECORDED','draft_id'=>(int)$row->id,
                    'writes'=>0,'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0];
            }
            abort_unless(hash_equals($expected['scan_hash'],$this->customerReceiptRecoveryScanHash($scan)),
                409,'CUSTOMER_RECEIPT_RECOVERY_SCAN_CHANGED');
            abort_if($scan->claim_nonce&&$scan->lease_until>=now('UTC')->format('Y-m-d H:i:s'),409,'ANALYSIS_BUSY');
            abort_unless((int)$scan->claimed_ceiling===$expected['ceiling']&&(int)$scan->attempts===3
                &&(int)$scan->analyzed_ceiling<$expected['ceiling'],409,'CUSTOMER_RECEIPT_RECOVERY_NOT_EXHAUSTED');
            abort_if($scan->next_attempt_at&&$scan->next_attempt_at>now('UTC')->format('Y-m-d H:i:s'),409,'ANALYSIS_BACKOFF');
            $floor=$this->automaticFloor($conversation,$scan,true);
            abort_unless($this->customerReceiptFloor($conversation,$floor,$expected['ceiling'])===$expected['floor'],
                409,'STALE_TRANSCRIPT');
            $this->requireAnalysisHours($conversation,$authority);
            $actor=$this->automaticActor($conversation);
            abort_unless((int)$actor->id===$authority['actor_id'],409,'AI_CONTROL_CHANGED');
            abort_if($this->pendingCapture(true),409,'CAPTURE_PENDING');
            abort_unless($this->customerReceiptRecoveryProjectionClear($conversation,$authority['started_at_utc']),
                409,'ACCEPTED_REPLIES_PENDING');
            $observed=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)->max('created_at');
            abort_unless(is_string($observed)&&$observed<=$this->settledBefore(),409,'TRANSCRIPT_SETTLING');
            abort_unless($this->primaryAuthority($conversation,$snapshot)===$authority,409,'AI_CONTROL_BLOCKED');
            $rows=array_column($snapshot['transcript'],null,'id');
            foreach ([$data['evidence_receipt']['recap_id'],$data['evidence_receipt']['approval_id']] as $id) {
                $message=$rows[$id]??null;
                abort_unless($message&&$message['speaker']==='customer'&&is_string($snapshot['keys'][$id]??null)
                    &&$message['sent_at']>=$authority['started_at_utc']
                    &&$message['sent_at']>now('UTC')->subHours(24)->format('Y-m-d H:i:s')
                    &&$message['sent_at']<=now('UTC')->format('Y-m-d H:i:s')
                    &&(($snapshot['recovered_at']??null)===null||$message['sent_at']>=$snapshot['recovered_at']),
                    409,'STALE_TRANSCRIPT');
            }
            // Repeat the same native/control proof at the write boundary. Unlike
            // normal analysis, recovery never calls the accepted-reply projector.
            abort_unless($this->customerReceiptEnabled($conversation),403,'CUSTOMER_RECEIPT_RECOVERY_MODE_REQUIRED');
            $this->requireAnalysisHours($conversation,$authority);
            abort_unless($this->primaryAuthority($conversation,$snapshot)===$authority
                &&$this->ceiling($conversation,true)===$expected['ceiling']
                &&hash_equals($expected['snapshot_hash'],$this->snapshot($conversation,$expected['floor'],$expected['ceiling'],true)['hash']),
                409,'STALE_TRANSCRIPT');
            abort_if($this->pendingCapture(true),409,'CAPTURE_PENDING');
            abort_unless($this->customerReceiptRecoveryProjectionClear($conversation,$authority['started_at_utc']),
                409,'ACCEPTED_REPLIES_PENDING');
            $id=DB::table(static::DRAFTS)->insertGetId(['conversation_id'=>$conversation,
                'evidence_floor'=>$expected['floor'],'evidence_ceiling'=>$expected['ceiling'],'evidence_hash'=>$expected['snapshot_hash'],
                'confirmation_key'=>null,'status'=>'REVIEW','reason'=>'REVIEW_REQUIRED','revision'=>1,
                'extraction'=>$this->encrypt($data),'sealed_payload'=>null,'customer_command_key'=>$this->uuid(),
                'ticket_command_key'=>$this->uuid(),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            // Preserve the exhausted retry audit, claimed ceiling and all floors.
            DB::table(static::SCANS)->where('conversation_id',$conversation)->update([
                'analyzed_ceiling'=>$expected['ceiling'],'next_attempt_at'=>null,'updated_at'=>now('UTC')]);
            return ['success'=>true,'status'=>'RECORDED','draft_id'=>(int)$id,
                'writes'=>2,'ai_calls'=>0,'message_sends'=>0,'erp_writes'=>0];
        },3);
    }

    /** Stable private metadata binding for the reviewed one-conversation recovery. */
    private function customerReceiptRecoveryScanHash(object $scan): string
    {
        $state=[];
        foreach (['auto_floor','analyzed_ceiling','claimed_ceiling','attempts'] as $key)
            $state[$key]=($scan->$key??null)===null?null:(int)$scan->$key;
        foreach (['next_attempt_at','claim_nonce','lease_until','updated_at'] as $key)
            $state[$key]=($scan->$key??null)===null?null:(string)$scan->$key;
        return PosServiceTicket::fingerprint($state);
    }

    /** Pure pending-acceptance check: this repair must not project inbox rows. */
    private function customerReceiptRecoveryProjectionClear(int $conversation,string $since): bool
    {
        if (!Schema::hasTable('whatsapp_reply_requests')) return false;
        $accepted=DB::table('whatsapp_reply_requests as r')->where('r.conversation_id',$conversation)
            ->where('r.waba_id',WhatsAppInboxAccess::WABA_ID)->where('r.phone_number_id',WhatsAppInboxAccess::PHONE_ID)
            ->where('r.state','ACCEPTED')->where('r.kind','text')->where('r.completed_at','>=',$since);
        if (!Schema::hasTable(WhatsAppInboxMessage::INDEX)) return !$accepted->lockForUpdate()->exists();
        return !$accepted->leftJoin(WhatsAppInboxMessage::INDEX.' as p',function($join) {
            $join->on('p.reply_id','=','r.id')->on('p.conversation_id','=','r.conversation_id')
                ->on('p.waba_id','=','r.waba_id')->on('p.phone_number_id','=','r.phone_number_id')->on('p.kind','=','r.kind');
        })->where(function($query) {
            $query->whereNull('p.reply_id')->orWhere('p.valid',false)->orWhereNotExists(function($native) {
                $native->selectRaw('1')->from('whatsapp_inbox_messages as m')->whereColumn('m.message_key','p.message_key')
                    ->whereColumn('m.conversation_id','r.conversation_id')->where('m.direction','outbound')->where('m.type','text');
            });
        })->lockForUpdate()->exists();
    }

    /** Consumer only: a proven native correction starts a new semantic attempt sequence. */
    public function noteProjectionRevision(int $conversation,int $message): bool
    {
        abort_unless($conversation>0 && $message>0,422,'INVALID_PROJECTION_REVISION');
        if (!Schema::hasTable(static::SCANS)) return true;
        abort_unless(Schema::hasTable(static::DRAFTS),503,'WHATSAPP_ORDERS_UNAVAILABLE');
        return DB::transaction(function () use ($conversation,$message) {
            $thread=$this->conversation($conversation,true);
            $row=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)->where('id',$message)->first();
            abort_unless($row && $row->direction==='inbound' && $row->type==='text'
                && $this->nativeHydration($this->decode($row->content)??[],$thread,$row)!==null,500,'INVALID_PROJECTION_REVISION');
            $scan=DB::table(static::SCANS)->where('conversation_id',$conversation)->lockForUpdate()->first();
            if (!$scan) return true;
            $floor=$this->dispatchedCeiling($conversation,true);
            if ($this->mode()==='auto') {
                $activation=$this->activationBoundary('activation_message_id',$conversation);
                abort_unless($activation!==null,403,'AUTO_NOT_CONFIGURED');
                $floor=max($floor,$activation,$this->controlInstalled()?0:(int)($scan->auto_floor??0));
            }
            if ($message<=$floor) return true;
            // The consumer must roll its correction back and retry after the current holder.
            // Never clear or reset another holder's nonce, lease, counters or backoff.
            if ($scan->claim_nonce && $scan->lease_until>=now('UTC')->format('Y-m-d H:i:s')) return false;
            DB::table(static::SCANS)->where('conversation_id',$conversation)->update([
                'analyzed_ceiling'=>0,'claimed_ceiling'=>0,'attempts'=>0,
                'next_attempt_at'=>$this->debounceSeconds()>0?now('UTC')->addSeconds($this->debounceSeconds()):null,
                'updated_at'=>now('UTC')]);
            return true;
        },3);
    }

    public function quote(int $draft, array $review, $actor): array
    {
        $actor = $this->actor($actor); $this->ready(); $row = $this->draft($draft);
        $v = $this->review($this->storedDeliveryOrigin($row,$review)); $this->mutable($row, $v);
        $this->current($row); $this->branch($v['branch'], $actor); $this->deliveryOrigin($v,$actor);
        $delivery = app(PhoneDelivery::class)->quote($v, $actor)['delivery'];
        $v['delivery_quote_hash'] = $delivery['delivery_quote_hash'];
        $quote = app(PosServiceTicket::class)->quote('phone', $v, $actor);
        $v['quote_hash'] = $quote['quote_hash'];
        $sealed = ['review'=>$v, 'quote'=>$quote, 'delivery'=>$delivery];
        DB::transaction(function () use ($draft, $actor, $v, $sealed) {
            $this->actor($actor);
            $row = $this->draft($draft, true); $this->mutable($row, $v); $this->current($row);
            $this->deliveryOrigin($v,$actor,true);
            $key = $this->confirmationKey($row);
            // A business confirmation can have only one ERP order, across actors and branches.
            $other = DB::table(static::DRAFTS)->where('confirmation_key', $key)->where('id', '!=', $draft)->lockForUpdate()->first();
            if ($other && $other->status!=='DISPATCHED' && (int)$other->conversation_id===(int)$row->conversation_id
                && $this->ceiling((int)$other->conversation_id)!==(int)$other->evidence_ceiling) {
                DB::table(static::DRAFTS)->where('id',$other->id)->update(['confirmation_key'=>null,'sealed_payload'=>null,
                    'status'=>'REVIEW','reason'=>'STALE_TRANSCRIPT','revision'=>(int)$other->revision+1,'updated_at'=>now('UTC')]);
                $other=null;
            }
            abort_if($other !== null, 409, 'ORDER_ALREADY_REVIEWED');
            $customer=DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',BranchCustomers::phoneKey($v['customer_phone']))->first();
            $sealed['customer_version']=$customer ? ['id'=>(int)$customer->id,'revision'=>(int)$customer->revision] : null;
            DB::table(static::DRAFTS)->where('id', $draft)->update(['confirmation_key'=>$key, 'sealed_payload'=>$this->encrypt($sealed),
                'revision'=>(int)$row->revision+1, 'status'=>'READY', 'reason'=>null, 'updated_at'=>now('UTC')]);
        }, 3);
        return ['success'=>true, 'draft'=>$this->present($this->draft($draft)), 'quote'=>$quote, 'delivery'=>$delivery];
    }

    public function dispatch(int $draft, array $review, $actor): array
    {
        return $this->dispatchInternal($draft, $review, $this->actor($actor), false);
    }

    /** CLI only: bounded unanswered native turns, with immutable completed receipts. */
    public function process(int $limit = 10): array
    {
        abort_unless($limit >= 1 && $limit <= 20, 422, 'INVALID_LIMIT');
        $metrics = ['threads_seen'=>0, 'baselined'=>0, 'analyzed'=>0, 'review_required'=>0,
            'dispatched'=>0, 'replayed'=>0, 'errors'=>0, 'disabled'=>0, 'capture_pending'=>0, 'deferred'=>0];
        $controlled=$this->controlInstalled();
        if ((!$controlled&&!(bool)$this->orderConfig('whatsapp_orders.enabled', false)) || !$this->available()) { $metrics['disabled']=1; return $metrics; }
        $actor=null;
        if (!$controlled) {
            try { $actor = $this->automaticActor(); }
            catch (\Throwable $error) { $metrics['disabled']=1; return $metrics; }
        }
        // Shared Meta workers choose the actual native channel per thread below.
        $selectionToken=$controlled&&$this->automationChannel()!==''?$this->controlToken():null;
        $activation=$controlled?($selectionToken['message_floor']??0):$this->activationBoundary('activation_message_id');
        if (!$controlled&&($activation===null || $this->activationBoundary('activation_event_id')===null || $this->activationInstant()===null)) { $metrics['disabled']=1; return $metrics; }
        if ($controlled&&$this->automationChannel()!==''&&$this->controlToken()===null) { $metrics['disabled']=1; return $metrics; }
        // API replies already accepted by Meta are durable business evidence even when no
        // business-app echo arrives. Project their receipts before selecting a message ceiling.
        // A receipt has an explicit conversation: invalid evidence blocks that conversation
        // below, while this bounded global pass also wakes fresh API-only confirmations.
        $this->projectAcceptedReplies(null,$controlled?null:$this->activationInstant());
        // Incomplete projection must never classify a partial transcript or spend another API call.
        if ($this->pendingCapture()) { $metrics['capture_pending']=1; return $metrics; }
        $rows = $this->scope(DB::table($this->table('whatsapp_inbox_conversations').' as c'), 'c.')
            ->join($this->table('whatsapp_inbox_messages').' as m', 'm.conversation_id', '=', 'c.id')
            ->leftJoin(static::SCANS.' as s', 's.conversation_id', '=', 'c.id')
            ->where(function ($q) use ($activation,$controlled) {
                // A newer cutoff starts a new bounded attempt sequence, under the scan lock below.
                $q->whereNull('s.auto_floor')->orWhere('s.auto_floor','<',$activation)
                    ->orWhereNull('s.next_attempt_at')->orWhere('s.next_attempt_at', '<=', now('UTC'));
                if ($controlled) $q->orWhereColumn('m.id','>','s.analyzed_ceiling');
            })
            ->when($controlled,fn($query)=>$this->filterAutomaticCandidates($query))
            ->select('c.id', 's.analyzed_ceiling', 's.auto_floor', 's.claimed_ceiling', 's.attempts')->selectRaw('MAX(m.id) AS ceiling, MAX(m.created_at) AS observed_at')
            ->groupBy('c.id', 's.analyzed_ceiling', 's.auto_floor', 's.claimed_ceiling', 's.attempts')
            ->havingRaw('MAX(m.created_at) <= ?', [$this->settledBefore()])
            ->havingRaw('MAX(m.id) > ?',[$activation])
            ->when(!$controlled,fn($query)=>$query->havingRaw('MAX(m.id) > COALESCE(s.auto_floor, 0)'))
            ->when($controlled,fn($query)=>$query->havingRaw('(MAX(m.id) > COALESCE(s.analyzed_ceiling, 0) OR (MAX(m.id) = s.analyzed_ceiling AND EXISTS (SELECT 1 FROM '.static::DRAFTS.' AS d WHERE d.conversation_id = c.id AND d.evidence_ceiling = s.analyzed_ceiling AND d.status IN (?, ?, ?))))',['REVIEW','READY','NONE']),
                fn($query)=>$query->havingRaw('(MAX(m.id) > COALESCE(s.analyzed_ceiling, 0) OR (MAX(m.id) = s.analyzed_ceiling AND EXISTS (SELECT 1 FROM '.static::DRAFTS.' AS d WHERE d.conversation_id = c.id AND d.evidence_ceiling = s.analyzed_ceiling AND d.status IN (?, ?) AND COALESCE(d.reason, ?) NOT IN (?, ?))))', ['REVIEW','READY','','INVALID_EXTRACTION','CONTEXT_INCOMPLETE']))
            ->when(!$controlled,fn($query)=>$query->havingRaw('(MAX(m.id) != COALESCE(s.claimed_ceiling, 0) OR COALESCE(s.attempts, 0) < 3 OR s.auto_floor IS NULL OR s.auto_floor < ?)',[$activation]))
            ->when($controlled,fn($query)=>$query->orderByRaw('COALESCE(MIN(s.updated_at), MIN(c.created_at)) ASC'))
            ->orderBy('c.id')->limit($limit)->get();
        foreach ($rows as $thread) {
            $metrics['threads_seen']++;
            try {
                $authority=$controlled?$this->controlToken((int)$thread->id):null;
                if ($controlled&&$authority===null) { $metrics['disabled']=1; continue; }
                if ($controlled) $actor=$this->automaticActor((int)$thread->id);
                if ($this->waitingForCustomer((int)$thread->id,(int)($thread->analyzed_ceiling??0),(int)$thread->ceiling)) {
                    $this->deferDispatch((int)$thread->id);
                    $metrics['deferred']++; continue;
                }
                if ($controlled&&!$this->analysisControlOpen((int)$thread->id)) {
                    $scan=DB::table(static::SCANS)->where('conversation_id',$thread->id)->first();
                    abort_if($scan&&$scan->claim_nonce&&$scan->lease_until>=now('UTC')->format('Y-m-d H:i:s'),409,'ANALYSIS_BUSY');
                    // Dispatch hours may be open while automatic analysis is scheduled off.
                    // Only the exact cached, already approved turn may progress: no AI call.
                    $draft=DB::table(static::DRAFTS)->where('conversation_id',$thread->id)
                        ->where('evidence_ceiling',$thread->ceiling)->whereIn('status',['REVIEW','READY'])
                                                ->orderByDesc('id')->first();
                    if (!$draft) { $metrics['deferred']++; continue; }
                } else {
                    $result = $this->analyzeInternal((int)$thread->id, $actor, false, true); $metrics['analyzed']++;
                    if ($result['baselined'] ?? false) $metrics['baselined']++;
                    $draft = $this->draft((int)$result['draft']['id']);
                }
                if ($draft->status==='DISPATCHED') { $metrics['replayed']++; continue; }
                if ($draft->status==='CANCELLED') continue;
                if ($draft->status==='NONE') {
                    if ($controlled) { $this->requestMissingInformation($draft,$actor); $this->deferDispatch((int)$draft->conversation_id); }
                    continue;
                }
                if ($draft->reason==='INVALID_EXTRACTION') {
                    if ($this->requestMissingInformation($draft,$actor)||$controlled) $this->deferDispatch((int)$draft->conversation_id);
                    $metrics['review_required']++; continue;
                }
                if (in_array($draft->reason,['AI_UNAVAILABLE','CONTEXT_INCOMPLETE'],true)) {
                    if ($controlled) $this->deferDispatch((int)$draft->conversation_id);
                    $metrics['review_required']++; continue;
                }
                if ($this->mode() !== 'auto') { $metrics['review_required']++; continue; }
                $reason = null; $review = $this->automaticReview($draft, $actor, $reason);
                if ($review === null) {
                    $this->reviewReason($draft, $reason ?? 'REVIEW_REQUIRED');
                    $this->requestMissingInformation($draft,$actor);
                    $this->deferDispatch((int)$draft->conversation_id); $metrics['review_required']++; continue;
                }
                if ($controlled) {
                    abort_unless($this->controlToken((int)$thread->id)===$authority,409,'AI_CONTROL_CHANGED');
                    $this->requireDispatchHours((int)$thread->id,$authority);
                }
                $quoted = $this->quote((int)$draft->id, $review, $actor);
                $review = $quoted['draft']['review']; $review['expected_revision']=$quoted['draft']['revision'];
                // A business estimate is advisory. The unpaid ticket uses the current ERP catalog
                // and delivery quote, and dispatch rechecks both fingerprints before any write.
                $sent = $this->dispatchInternal((int)$draft->id, $review, $this->automaticActor((int)$thread->id), true,$authority);
                $metrics[$sent['replayed'] ? 'replayed' : 'dispatched']++;
            } catch (\Throwable $error) {
                if ($error->getMessage()==='DISPATCH_OUTSIDE_HOURS') {
                    $queued=DB::table(static::DRAFTS)->where('conversation_id',$thread->id)->orderByDesc('id')->first();
                    if ($queued) $this->reviewReason($queued,'DISPATCH_OUTSIDE_HOURS');
                    $this->deferDispatch((int)$thread->id); $metrics['deferred']++;
                }
                elseif (in_array($error->getMessage(),['TRANSCRIPT_SETTLING','ANALYSIS_BUSY','ANALYSIS_OUTSIDE_HOURS'],true)) $metrics['deferred']++;
                elseif (in_array($error->getMessage(),['CAPTURE_PENDING','ACCEPTED_REPLIES_PENDING'],true)) $metrics['capture_pending']=1;
                else { $metrics['errors']++; $this->deferDispatch((int)$thread->id); }
            }
        }
        return $metrics;
    }

    private function analyzeInternal(int $conversation, $actor, bool $force, bool $automatic): array
    {
        $this->ready(); $this->conversation($conversation);
        $bounded=$automatic || (!$this->controlInstalled()&&(bool)$this->orderConfig('whatsapp_orders.enabled',false) && $this->mode()==='auto'
            && $this->activationBoundary('activation_message_id',$conversation)!==null
            && $this->activationBoundary('activation_event_id',$conversation)!==null && $this->activationInstant($conversation)!==null);
        abort_unless($this->projectAcceptedReplies($conversation,$bounded?$this->activationInstant($conversation):null),409,'ACCEPTED_REPLIES_PENDING');
        $claim = DB::transaction(function () use ($conversation, $force, $automatic,$bounded) {
            $this->scanInsert($conversation);
            $scan = DB::table(static::SCANS)->where('conversation_id', $conversation)->lockForUpdate()->first();
            $ceiling = $this->ceiling($conversation); abort_unless($ceiling > 0, 422, 'EMPTY_CONVERSATION');
            if ($automatic) {
                abort_if($this->pendingCapture(),409,'CAPTURE_PENDING');
                $observed=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)->max('created_at');
                abort_unless(is_string($observed)&&$observed<=$this->settledBefore(),409,'TRANSCRIPT_SETTLING');
            }
            $existing = DB::table(static::DRAFTS)->where('conversation_id', $conversation)->where('evidence_ceiling', $ceiling)->first();
            // Completed receipts remain immutable even when automatic activation moves forward.
            if ($existing && $existing->status==='DISPATCHED') return ['existing'=>$existing,'baselined'=>false];
            abort_if($scan->claim_nonce && $scan->lease_until >= now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_BUSY');
            $activation=$bounded ? $this->activationBoundary('activation_message_id',$conversation) : null;
            $eventActivation=$bounded ? $this->activationBoundary('activation_event_id',$conversation) : null;
            $activationTime=$bounded ? $this->activationInstant($conversation) : null;
            if ($bounded) abort_unless($activation!==null && $eventActivation!==null && $activationTime!==null,403,'AUTO_NOT_CONFIGURED');
            $floor=$bounded ? $this->automaticFloor($conversation,$scan,true) : $this->dispatchedCeiling($conversation);
            if ($bounded) abort_unless($ceiling>$floor,409,'STALE_TRANSCRIPT');
            $floor=$this->customerReceiptFloor($conversation,$floor,$ceiling);
            $baselined=$bounded && ($scan->auto_floor===null || ($this->controlInstalled()
                ? (int)$scan->auto_floor!==$activation : (int)$scan->auto_floor<$activation));
            $staleBounded=$bounded && $existing && (int)$existing->evidence_floor<$floor;
            $staleEvidence=$existing && !$staleBounded
                && !hash_equals((string)$existing->evidence_hash,$this->snapshot($conversation,$floor,$ceiling)['hash']);
            if ($baselined || $staleBounded || $staleEvidence) {
                // Never reset a live lease. Keep historical drafts and completed command receipts.
                $updates=['claimed_ceiling'=>0,'attempts'=>0,'next_attempt_at'=>null,'updated_at'=>now('UTC')];
                if ($bounded) $updates['auto_floor']=$this->controlInstalled()?$activation:max($activation,(int)($scan->auto_floor??0));
                DB::table(static::SCANS)->where('conversation_id',$conversation)->update($updates);
                if ($bounded) $scan->auto_floor=$updates['auto_floor'];
                $scan->claimed_ceiling=0; $scan->attempts=0; $scan->next_attempt_at=null;
            }
            $retry=$automatic && $existing && $existing->reason==='AI_UNAVAILABLE' && (int)$scan->attempts<3;
            // A newly installed receipt protocol may recognize an already captured,
            // still pending turn. Re-analyze that exact snapshot once, without
            // resetting activation floors, leases or completed orders.
            $receiptRefresh=false;
            if ($existing&&!$staleBounded&&!$staleEvidence&&$this->customerReceiptEnabled($conversation)) {
                $stored=$this->decode($existing->extraction);
                if (($stored['type']??null)!==WhatsAppCustomerOrderConfirmation::TYPE) {
                    $candidate=$this->snapshot($conversation,$floor,$ceiling);
                    $receiptRefresh=($candidate['complete']??false)===true
                        &&(WhatsAppCustomerOrderConfirmation::inspect($candidate['transcript'])['ok']??false)===true;
                }
            }
            if ($existing && !$staleBounded && !$staleEvidence && !$force && !$retry&&!$receiptRefresh) return ['existing'=>$existing,'baselined'=>$baselined];
            if (!$force) abort_if($scan->next_attempt_at && $scan->next_attempt_at > now('UTC')->format('Y-m-d H:i:s')
                && !($this->controlInstalled()&&$ceiling>(int)$scan->analyzed_ceiling), 409, 'ANALYSIS_BACKOFF');
            // A closed automatic-analysis clock never consumes a fresh attempt. Exact
            // cached results above remain readable for independently scheduled dispatch.
            $analysisAuthority=$automatic&&$this->controlInstalled()?$this->requireAnalysisHours($conversation):null;
            $attempts = $receiptRefresh?1:((int)$scan->claimed_ceiling === $ceiling ? (int)$scan->attempts+1 : 1);
            abort_if($attempts > 3 && !$force, 409, 'ANALYSIS_RETRY_LIMIT');
            $nonce = bin2hex(random_bytes(32));
            DB::table(static::SCANS)->where('conversation_id', $conversation)->update(['claimed_ceiling'=>$ceiling, 'claim_nonce'=>$nonce,
                'lease_until'=>now('UTC')->addSeconds(180), 'attempts'=>min(3,$attempts), 'next_attempt_at'=>null, 'updated_at'=>now('UTC')]);
            return ['nonce'=>$nonce, 'floor'=>$floor, 'ceiling'=>$ceiling, 'existing_id'=>$existing ? (int)$existing->id : null,
                'activation'=>$activation,'event_activation'=>$eventActivation,'activation_time'=>$activationTime,'baselined'=>$baselined,
                'analysis_authority'=>$analysisAuthority];
        }, 3);
        if (isset($claim['existing'])) return ['success'=>true, 'draft'=>$this->present($claim['existing']),'baselined'=>$claim['baselined']];
        try {
            $snapshot = $this->snapshot($conversation, $claim['floor'], $claim['ceiling']);
            $authority=$automatic&&$this->controlInstalled()?$this->primaryAuthority($conversation,$snapshot):null;
            if ($automatic&&$this->controlInstalled()) abort_unless($authority!==null&&(int)$actor->id===$authority['actor_id'],403,'AI_CONTROL_BLOCKED');
            if ($automatic&&$this->controlInstalled()) {
                abort_unless($authority===$claim['analysis_authority'],409,'AI_CONTROL_CHANGED');
                $this->requireAnalysisHours($conversation,$authority);
            }
            $receipt=$snapshot['complete']&&$this->customerReceiptEnabled($conversation)
                ?WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']):null;
            $result = ($receipt['ok']??false)===true?$receipt:($snapshot['complete']
                ?$this->automaticProvider($authority)->extract(WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']))
                : ['ok'=>false, 'reason'=>'CONTEXT_INCOMPLETE', 'data'=>null]);
            if (!is_array($result) || !($result['ok'] ?? false)) {
                $data = ['analysis_failure'=>$this->safeAnalysisFailure($result)];
                $reason = in_array($result['reason']??null,['INVALID_INPUT','INVALID_EVIDENCE','INVALID_EXTRACTION'],true)
                    ? 'INVALID_EXTRACTION' : (($result['reason'] ?? null) === 'CONTEXT_INCOMPLETE' ? 'CONTEXT_INCOMPLETE' : 'AI_UNAVAILABLE');
                $status='REVIEW';
            } else {
                $checked = ($receipt['ok']??false)===true?$receipt
                    :WhatsAppOrderExtraction::validate($result['data'], $snapshot['transcript']);
                $data = ($checked['ok'] ?? false) ? $checked['data'] : null;
                $reason = $data === null ? 'INVALID_EXTRACTION' : 'REVIEW_REQUIRED'; $status='REVIEW';
                if (($data['decision'] ?? '') === 'NONE') { $status='NONE'; $reason='NO_ORDER'; }
                if (($data['decision'] ?? '') === 'CANCELLED') { $status='CANCELLED'; $reason='CUSTOMER_CANCELLED'; }
            }
            // A reply may be accepted while extraction is in flight. Project it before CAS
            // so neither a partial NONE result nor an earlier confirmation can be persisted.
            abort_unless($this->projectAcceptedReplies($conversation,$bounded?$claim['activation_time']:null),409,'ACCEPTED_REPLIES_PENDING');
            $id = DB::transaction(function () use ($conversation, $actor, $claim, $snapshot, $data, $reason, $status,$automatic,$bounded,$authority) {
                $this->actor($actor);
                if ($automatic&&$this->controlInstalled()) $this->requireAnalysisHours($conversation,$authority);
                if ($automatic&&$this->controlInstalled()) abort_unless($authority!==null&&$this->primaryAuthority($conversation,$snapshot)===$authority,409,'AI_CONTROL_CHANGED');
                $scan = DB::table(static::SCANS)->where('conversation_id', $conversation)->lockForUpdate()->first();
                abort_unless($scan && hash_equals((string)$scan->claim_nonce, $claim['nonce']) && $scan->lease_until >= now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_LEASE_LOST');
                if ($bounded) {
                    abort_unless($this->activationBoundary('activation_message_id',$conversation)===$claim['activation']
                        && $this->activationBoundary('activation_event_id',$conversation)===$claim['event_activation']
                        && $this->activationInstant($conversation)===$claim['activation_time']
                        && $claim['floor'] >= $this->automaticFloor($conversation,$scan,true),409,'STALE_TRANSCRIPT');
                }
                abort_unless($this->ceiling($conversation) === $claim['ceiling'] && $this->snapshot($conversation,$claim['floor'],$claim['ceiling'])['hash'] === $snapshot['hash'], 409, 'STALE_TRANSCRIPT');
                $values = ['conversation_id'=>$conversation, 'evidence_floor'=>$claim['floor'],
                    'evidence_ceiling'=>$claim['ceiling'], 'evidence_hash'=>$snapshot['hash'], 'confirmation_key'=>null,
                    'status'=>$status, 'reason'=>$reason, 'revision'=>1, 'extraction'=>$data === null ? null : $this->encrypt($data),
                    'sealed_payload'=>null, 'customer_command_key'=>$this->uuid(), 'ticket_command_key'=>$this->uuid(),
                    'created_at'=>now('UTC'), 'updated_at'=>now('UTC')];
                if ($claim['existing_id']) {
                    $old=$this->draft($claim['existing_id'],true); abort_if($old->status==='DISPATCHED',409,'ORDER_ALREADY_DISPATCHED');
                    unset($values['customer_command_key'],$values['ticket_command_key'],$values['created_at']);
                    $values['revision']=(int)$old->revision+1;
                    DB::table(static::DRAFTS)->where('id',$old->id)->update($values); $id=(int)$old->id;
                } else $id=DB::table(static::DRAFTS)->insertGetId($values);
                DB::table(static::SCANS)->where('conversation_id',$conversation)->update(['analyzed_ceiling'=>$reason==='AI_UNAVAILABLE'&&$automatic?$claim['floor']:$claim['ceiling'],
                    'claim_nonce'=>null,'lease_until'=>null,'next_attempt_at'=>$reason==='AI_UNAVAILABLE'&&$automatic?now('UTC')->addSeconds(60):null,'updated_at'=>now('UTC')]);
                return $id;
            }, 3);
            return ['success'=>true,'draft'=>$this->present($this->draft($id)),'baselined'=>$claim['baselined']];
        } catch (\Throwable $error) {
            // The lease can only be released by its holder. Exception content is never recorded.
            DB::table(static::SCANS)->where('conversation_id',$conversation)->where('claim_nonce',$claim['nonce'])
                ->update(['claim_nonce'=>null,'lease_until'=>null,'next_attempt_at'=>now('UTC')->addSeconds(60),'updated_at'=>now('UTC')]);
            throw $error;
        }
    }

    private function dispatchInternal(int $draft, array $review, $actor, bool $automatic,?array $authority=null): array
    {
        $this->ready(); $v = $this->review($this->storedDeliveryOrigin($this->draft($draft),$review), true);
        return DB::transaction(function () use ($draft, $v, $actor, $automatic,$authority) {
            if ($automatic) abort_unless($this->mode()==='auto',403,'AUTO_NOT_CONFIGURED');
            $row = $this->draft($draft, true);
            $actor = $automatic ? $this->automaticActor((int)$row->conversation_id) : $this->actor($actor);
            if ($automatic&&$this->controlInstalled()) abort_unless($authority!==null&&$this->controlToken((int)$row->conversation_id)===$authority,409,'AI_CONTROL_CHANGED');
            if ($row->status === 'DISPATCHED') {
                $sealed = $this->decode($row->sealed_payload);
                abort_unless($sealed && $this->reviewHash($v) === $this->reviewHash($sealed['review']),409,'DISPATCH_REPLAY_CHANGED');
                $this->branch((string)$row->assigned_branch,$actor);
                return ['success'=>true,'replayed'=>true,'draft'=>$this->present($row),'ticket_id'=>(int)$row->ticket_id,'print_queued'=>true];
            }
            if ($automatic&&$this->controlInstalled()) {
                $scan=DB::table(static::SCANS)->where('conversation_id',$row->conversation_id)->lockForUpdate()->first();
                abort_if($scan&&$scan->claim_nonce&&$scan->lease_until>=now('UTC')->format('Y-m-d H:i:s'),409,'ANALYSIS_BUSY');
                $this->requireDispatchHours((int)$row->conversation_id,$authority);
            }
            $this->mutable($row,$v); abort_unless($row->status==='READY' && $row->sealed_payload && $row->confirmation_key,409,'QUOTE_REQUIRED');
            $this->conversation((int)$row->conversation_id,true);
            $this->current($row,true); $this->branch($v['branch'],$actor,true); $this->deliveryOrigin($v,$actor,true);
            if ($automatic) {
                abort_if($this->pendingCapture(true),409,'CAPTURE_PENDING');
                $reason=null; $candidate=$this->automaticReview($row,$actor,$reason,true);
                abort_unless($candidate!==null && $this->reviewHash($candidate) === $this->reviewHash($v,false),409,'AUTO_REVIEW_REQUIRED');
            }
            $sealed=$this->decode($row->sealed_payload);
            abort_unless($sealed && $this->reviewHash($v) === $this->reviewHash($sealed['review']),409,'REVIEW_CHANGED');
            // Reprice immediately before writing; drift never creates a new ticket.
            $delivery=app(PhoneDelivery::class)->quote($v,$actor)['delivery'];
            abort_unless(hash_equals((string)$delivery['delivery_quote_hash'],$v['delivery_quote_hash']),409,'DELIVERY_QUOTE_CHANGED');
            $quote=app(PosServiceTicket::class)->quote('phone',$v,$actor);
            abort_unless(hash_equals((string)$quote['quote_hash'],$v['quote_hash']),409,'ORDER_QUOTE_CHANGED');
            $key=BranchCustomers::phoneKey($v['customer_phone']);
            $customer=DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',$key)->lockForUpdate()->first();
            $version=$sealed['customer_version']??null;
            abort_unless($version===null ? $customer===null : ($customer && (int)$customer->id===$version['id'] && (int)$customer->revision===$version['revision']),409,'CUSTOMER_VERSION_CHANGED');
            $customerValues=['branch'=>$v['branch'],'idempotency_key'=>$row->customer_command_key,'name'=>$v['customer_name'],
                'phone'=>$v['customer_phone'],'address'=>$v['address'],'area'=>$v['area'],'delivery_notes'=>$v['delivery_notes'],
                'latitude'=>$v['latitude'],'longitude'=>$v['longitude']];
            if ($customer) $customerValues+=['customer_id'=>(int)$customer->id,'expected_revision'=>(int)$customer->revision];
            if ($automatic&&$this->controlInstalled()) {
                $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling,true);
                abort_unless($authority!==null&&$this->primaryAuthority((int)$row->conversation_id,$snapshot)===$authority,409,'AI_CONTROL_CHANGED');
            }
            if ($automatic&&$this->controlInstalled()) $this->requireDispatchHours((int)$row->conversation_id,$authority);
            $saved=$automatic&&$customer&&$this->sameCustomerValues($customer,$v)
                ? ['customer'=>['id'=>(int)$customer->id]] : app(BranchCustomers::class)->save($customerValues,$actor);
            $payload=$v; unset($payload['expected_revision']);
            $payload+=['idempotency_key'=>$row->ticket_command_key,'customer_id'=>(int)$saved['customer']['id'],
                'send_to_kitchen'=>true,'discount'=>'0.00','discount_reason'=>''];
            if ($automatic&&$this->controlInstalled()) abort_unless($this->controlToken((int)$row->conversation_id)===$authority,409,'AI_CONTROL_CHANGED');
            if ($automatic&&$this->controlInstalled()) $this->requireDispatchHours((int)$row->conversation_id,$authority);
            $result=app(PosServiceTicket::class)->save('phone',$payload,$actor);
            if ($automatic&&$this->controlInstalled()) $this->requireDispatchHours((int)$row->conversation_id,$authority);
            $ticket=(int)($result['ticket']['id'] ?? $result['operation']['ticket_id'] ?? 0); abort_unless($ticket>0,500,'DISPATCH_FAILED');
            DB::table(static::DRAFTS)->where('id',$draft)->update(['status'=>'DISPATCHED','reason'=>null,'revision'=>(int)$row->revision+1,
                'ticket_id'=>$ticket,'dispatch_actor_id'=>(int)$actor->id,'assigned_branch'=>$v['branch'],'dispatched_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['success'=>true,'replayed'=>false,'draft'=>$this->present($this->draft($draft)),'ticket_id'=>$ticket,'print_queued'=>true];
        },3);
    }

    private function review(array $values, bool $hashes = false): array
    {
        if (is_string($values['customer_phone']??null) && preg_match('/\A[+0-9٠-٩۰-۹ ()-]{6,30}\z/u',$values['customer_phone'])) {
            $values['customer_phone']=BranchCustomers::phoneKey($values['customer_phone']);
        }
        $rules=['expected_revision'=>'required|integer|min:1','branch'=>['required','string','regex:/^f:[1-9][0-9]{0,18}$/D'],
            'delivery_origin_branch'=>['nullable','string','regex:/^f:[1-9][0-9]{0,18}$/D'],
            'customer_name'=>'required|string|max:100','customer_phone'=>['required','string','max:30','regex:/^[+0-9 ()-]{6,30}$/D'],
            'address'=>'required|string|max:500','area'=>'nullable|string|max:150','delivery_notes'=>'nullable|string|max:500',
            'latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_confirmed'=>'required|accepted',
            'items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|integer|min:1',
            'items.*.quantity'=>'required|string|regex:/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/D','items.*.quantity_mode'=>'required|in:piece,weight',
            'items.*.option_id'=>'nullable|string|max:80','items.*.feature_id'=>'nullable|integer|min:0',
            'items.*.product_clean'=>'nullable|in:extra_clear,extra_clean,extra_vacuim'];
        if ($hashes) $rules+=['quote_hash'=>'required|string|size:64|regex:/^[a-f0-9]+$/D','delivery_quote_hash'=>'required|string|size:64|regex:/^[a-f0-9]+$/D'];
        $v=Validator::make($values,$rules)->validate();
        // Keep older same-branch reviews byte-compatible; only a distinct origin is sealed.
        if (($v['delivery_origin_branch']??null)===null || $v['delivery_origin_branch']===$v['branch']) unset($v['delivery_origin_branch']);
        foreach (['customer_name','customer_phone','address','area','delivery_notes'] as $k) $v[$k]=trim((string)($v[$k]??''));
        abort_if($v['customer_name']===''||$v['address']===''||BranchCustomers::phoneKey($v['customer_phone'])==='',422,'INVALID_CUSTOMER');
        $v['expected_revision']=(int)$v['expected_revision']; $v['latitude']=round((float)$v['latitude'],7); $v['longitude']=round((float)$v['longitude'],7);
        abort_if($v['latitude']===0.0&&$v['longitude']===0.0,422,'LOCATION_UNCONFIRMED'); $v['location_confirmed']=true;
        $v['items']=array_map(function ($item) {
            return ['product_id'=>(int)$item['product_id'],'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],
                'option_id'=>(string)($item['option_id']??''),'feature_id'=>(int)($item['feature_id']??0),'product_clean'=>(string)($item['product_clean']??'')];
        },$v['items']);
        return $v;
    }

    private function automaticReview(object $row, $actor, ?string &$reason,bool $lock=false): ?array
    {
        $eventFloor=$this->activationBoundary('activation_event_id',(int)$row->conversation_id);
        if ($eventFloor===null || $this->activationBoundary('activation_message_id',(int)$row->conversation_id)===null || $this->activationInstant((int)$row->conversation_id)===null) { $reason='AUTO_NOT_CONFIGURED'; return null; }
        $floor=$this->automaticFloor((int)$row->conversation_id,null,$lock);
        if ((int)$row->evidence_floor<$floor || (int)$row->evidence_ceiling<=$floor) { $reason='STALE_TRANSCRIPT'; return null; }
        $failures=DB::table($this->table('whatsapp_inbox_ingestion_failures'))->where('event_id','>',$eventFloor);
        if ($lock) $failures->lockForUpdate();
        if ($failures->first(['event_id'])) { $reason='CAPTURE_REVIEW_REQUIRED'; return null; }
        $data=$this->decode($row->extraction); $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        if ($this->controlInstalled()&&$this->primaryAuthority((int)$row->conversation_id,$snapshot)===null) { $reason='AUTO_NOT_CONFIGURED'; return null; }
        if (isset($data['type'])&&$data['type']===WhatsAppCustomerOrderConfirmation::TYPE)
            return $this->customerReceiptReview($row,$data,$snapshot,$actor,$reason,$lock);
        // An unanswered product question never consents to execute an older basket,
        // including when a cached model result still calls that basket CONFIRMED.
        if ($this->controlInstalled()&&$this->informationTurn($snapshot['transcript'])!==null) { $reason='REVIEW_REQUIRED'; return null; }
        if (!$snapshot['complete'] || !$data || ($data['decision']??null)!=='CONFIRMED'
            || array_diff($data['issues']??[],['PRICE_ESTIMATE_ONLY','LOCATION_REQUIRED','MISSING_BRANCH','MISSING_AREA'])!==[]) { $reason='REVIEW_REQUIRED'; return null; }
        if ($snapshot['recovered_at']!==null && !$this->freshAutomaticEvidence($data,$snapshot['transcript'],$snapshot['recovered_at'],(int)$row->conversation_id)) {
            $reason='RECOVERED_HISTORY_REVIEW'; return null;
        }
        if (!$this->freshAutomaticEvidence($data,$snapshot['transcript'],null,(int)$row->conversation_id)) { $reason='STALE_TRANSCRIPT'; return null; }
        $followup=$this->followupService();
        $approved=$this->analysisOnly((int)$row->conversation_id)
            ?$this->externalRecapApproved($data,$snapshot)
            :($followup===null||$followup->approvalSatisfied((int)$row->conversation_id,$data,$snapshot,$lock));
        if (!$approved) {
            $reason='REVIEW_REQUIRED'; return null;
        }
        if (($data['fulfillment']??null)!=='DELIVERY') { $reason='PICKUP_REQUIRES_REVIEW'; return null; }
        $resolved=$this->automaticBranch($data,$actor);
        if ($resolved===null) { $reason='BRANCH_UNRESOLVED'; return null; }
        $branch=$resolved['fulfillment_branch']??$resolved['value'];
        $fulfillment=$this->branch($branch,$actor,$lock);
        if (!$this->branchOpen($fulfillment,$reason,$lock)) return null;
        $origin=$resolved['value']!==$branch?$resolved['value']:null;
        if ($origin!==null) {
            try { app(FasakhanstaDeliveryOrigin::class)->check($origin,$branch,$actor,$lock); }
            catch (\Throwable $error) { $reason='BRANCH_UNRESOLVED'; return null; }
        }
        // Keep the authenticated full snapshot for currentness and consent, while
        // location and catalog facts belong only to this independently started order.
        $transcript=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
        $customer=$data['customer']??[];
        foreach (['name','phone','address'] as $k) if (!is_string($customer[$k]??null)||trim($customer[$k])==='') { $reason='REVIEW_REQUIRED'; return null; }
        $savedQuery=DB::table('branch_customers')->where('branch',$branch)->where('phone_key',BranchCustomers::phoneKey($customer['phone']));
        if ($lock) $savedQuery->lockForUpdate(); $saved=$savedQuery->first();
        $location=null;
        $locationTime=null; $addressTime=null; $lastLocation=null; $summaryAddress=false;
        foreach ($transcript as $m) {
            if ($m['speaker']==='customer' && ((is_string($m['text']) && strpos($m['text'],$customer['address'])!==false)
                || (is_string($m['cart']['text']??null)&&strpos($m['cart']['text'],$customer['address'])!==false))) $addressTime=$m['sent_at'];
            if ($m['speaker']==='customer' && $m['location']!==null) $lastLocation=$m['id'];
            if ($m['id']===($data['evidence']['location_id']??null)&&$m['speaker']==='customer') { $location=$m['location']; $locationTime=$m['sent_at']; }
            if ($m['speaker']==='business' && in_array($m['id'],$data['evidence']['confirmation_ids']??[],true)
                && is_string($m['text']) && strpos($m['text'],$customer['address'])!==false) $summaryAddress=true;
        }
        if (!$summaryAddress && WhatsAppOrderConversationEvidence::addressAcknowledged($data,
                array_column($transcript,null,'id'))) $summaryAddress=true;
        if (!$addressTime || !$summaryAddress) { $reason='LOCATION_UNCONFIRMED'; return null; }
        if ($lastLocation!==null) {
            // A newer native pin cannot be replaced by an older saved customer address.
            if (!$location || $locationTime<$addressTime || $lastLocation!==($data['evidence']['location_id']??null)) {
                $reason='LOCATION_UNCONFIRMED'; return null;
            }
        } else {
            $location=$this->existingLocation($customer,$branch,$actor,$saved,$lock);
            if ($location===null) { $reason='LOCATION_UNCONFIRMED'; return null; }
        }
        $items=[]; $catalog=[];
        for ($page=1;$page<=3;$page++) {
            $list=app(TakeawayCatalog::class)->listing(['branch'=>$branch,'page'=>$page,'per_page'=>100],$actor);
            $catalog=array_merge($catalog,$list['items']);
            if ((int)($list['pagination']['last_page']??1)<=$page) break;
            if ($page===3) { $reason='CATALOG_UNRESOLVED'; return null; }
        }
        $summaryTexts=[];
        foreach ($transcript as $message) if ($message['speaker']==='business'&&in_array($message['id'],$data['evidence']['confirmation_ids']??[],true)&&is_string($message['text'])) $summaryTexts[]=$message['text'];
        $usedCartLines=[];
        foreach ($data['items'] as $item) {
            if (($item['cart_reference']??null)!==null) {
                $resolved=$this->cartItem($item,$data['items'],$summaryTexts,$transcript,$catalog,$branch,$usedCartLines);
                if ($resolved===null) { $reason='CATALOG_UNRESOLVED'; return null; }
                $items[]=$resolved; continue;
            }
            $matches=array_values(array_filter($catalog,fn($p)=>($p['available']??false)&&$this->name($p['name'])===$this->name($item['name'])));
            if (count($matches)!==1 || !is_string($item['quantity']??null) || !in_array($item['quantity_mode'],['piece','weight'],true)
                || !in_array($matches[0]['quantity_mode']??'select',[$item['quantity_mode'],'select'],true)) { $reason='CATALOG_UNRESOLVED'; return null; }
            $p=$matches[0]; $option=$this->catalogOption($item,$data['items'],$summaryTexts,$p,$data,$transcript);
            if ($option===null) { $reason='CATALOG_UNRESOLVED'; return null; }
            $items[]=['product_id'=>$p['id'],'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],'option_id'=>$option];
        }
        if (!$this->completeCart($transcript,$data,$usedCartLines)) { $reason='CATALOG_UNRESOLVED'; return null; }
        $sourceMatches=$saved&&BranchCustomers::phoneKey($saved->phone)===BranchCustomers::phoneKey($customer['phone'])
            &&$this->name($saved->address)===$this->name($customer['address'])&&$this->sourceAreaAgrees($saved->area??null,$customer['area']??null);
        $area=$customer['area']??($sourceMatches?trim((string)($saved->area??'')):($location['source_area']??''));
        $notes=$customer['notes']??($sourceMatches?trim((string)($saved->delivery_notes??'')):($location['source_notes']??''));
        $v=['expected_revision'=>(int)$row->revision,'branch'=>$branch,'customer_name'=>$customer['name'],'customer_phone'=>$customer['phone'],
            'address'=>$customer['address'],'area'=>$area,'delivery_notes'=>$notes,
            'latitude'=>$location['lat'],'longitude'=>$location['long'],'location_confirmed'=>true,'items'=>$items];
        if ($origin!==null) $v['delivery_origin_branch']=$origin;
        $v=$this->review($v);
        return $v;
    }

    /** The customer-copy protocol is deliberately limited to silent WhatsApp analysis. */
    private function customerReceiptEnabled(int $conversation): bool
    {
        return $this->table('whatsapp_inbox_messages')==='whatsapp_inbox_messages'
            &&$this->analysisOnly($conversation)&&class_exists(WhatsAppCustomerOrderConfirmation::class);
    }

    /** A complete new customer receipt can stand independently of a long older
     * chat. Select a new evidence range, never alter activation/dispatched floors.
     * All messages after its native recap remain in the complete CAS snapshot. */
    private function customerReceiptFloor(int $conversation,int $floor,int $ceiling): int
    {
        if (!$this->customerReceiptEnabled($conversation)) return $floor;
        $full=$this->snapshot($conversation,$floor,$ceiling);
        if (($full['complete']??false)===true||WhatsAppOrderExtraction::hasProbe($full['transcript']??[])) return $floor;
        foreach (array_reverse($full['transcript']??[]) as $candidate) {
            if ($candidate['speaker']!=='customer'||!is_string($candidate['text'])) continue;
            $parsed=WhatsAppCustomerOrderConfirmation::parseRecap($candidate['text']);
            if (($parsed['ok']??false)!==true) continue;
            // An unrelated historical pin is not part of an independent receipt.
            if (!is_array($parsed['data']['textual_location']['point']??null)) return $floor;
            $key=$full['keys'][$candidate['id']]??null;
            if (!is_string($key)) return $floor;
            $native=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)
                ->where('message_key',$key)->where('direction','inbound')->where('type','text')->first(['id']);
            if (!$native||(int)$native->id<=$floor||(int)$native->id>$ceiling) return $floor;
            $receiptFloor=max($floor,(int)$native->id-1);
            $suffix=$this->snapshot($conversation,$receiptFloor,$ceiling);
            if (($suffix['complete']??false)!==true) return $floor;
            $proof=WhatsAppCustomerOrderConfirmation::inspect($suffix['transcript']);
            $recap=$proof['data']['evidence_receipt']['recap_id']??null;
            return ($proof['ok']??false)===true&&is_string($recap)&&($suffix['keys'][$recap]??null)===$key
                ?$receiptFloor:$floor;
        }
        return $floor;
    }

    /** Re-prove the actual inbound rows on every quote/dispatch; never trust a cached marker. */
    private function customerReceiptReview(object $row,array $data,array $snapshot,$actor,?string &$reason,bool $lock): ?array
    {
        $conversation=(int)$row->conversation_id;
        if (!$this->customerReceiptEnabled($conversation)||($snapshot['complete']??false)!==true) { $reason='REVIEW_REQUIRED'; return null; }
        $proved=WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']);
        if (($proved['ok']??false)!==true||!hash_equals(PosServiceTicket::fingerprint($proved['data']),PosServiceTicket::fingerprint($data))) {
            $reason='INVALID_EXTRACTION'; return null;
        }
        if (WhatsAppOrderExtraction::hasProbe($snapshot['transcript'])) { $reason='REVIEW_REQUIRED'; return null; }
        $receipt=$data['evidence_receipt']; $rows=array_column($snapshot['transcript'],null,'id');
        $activation=$this->activationInstant($conversation);
        if ($activation===null) { $reason='AUTO_NOT_CONFIGURED'; return null; }
        foreach ([$receipt['recap_id'],$receipt['approval_id']] as $id) {
            $message=$rows[$id]??null;
            if (!$message||!is_string($snapshot['keys'][$id]??null)||$message['speaker']!=='customer'
                ||$message['sent_at']<$activation||$message['sent_at']<=now('UTC')->subHours(24)->format('Y-m-d H:i:s')
                ||$message['sent_at']>now('UTC')->format('Y-m-d H:i:s')) { $reason='STALE_TRANSCRIPT'; return null; }
            if (($snapshot['recovered_at']??null)!==null&&$message['sent_at']<$snapshot['recovered_at']) {
                $reason='RECOVERED_HISTORY_REVIEW'; return null;
            }
        }
        $resolved=$this->automaticBranch($data,$actor);
        if ($resolved===null) { $reason='BRANCH_UNRESOLVED'; return null; }
        $branch=$resolved['fulfillment_branch']??$resolved['value'];
        $fulfillment=$this->branch($branch,$actor,$lock);
        if (!$this->branchOpen($fulfillment,$reason,$lock)) return null;
        $origin=$resolved['value']!==$branch?$resolved['value']:null;
        if ($origin!==null) {
            try { app(FasakhanstaDeliveryOrigin::class)->check($origin,$branch,$actor,$lock); }
            catch (\Throwable $error) { $reason='BRANCH_UNRESOLVED'; return null; }
        }
        $point=$data['textual_location']['point']??null; $nativePoint=null; $pinPosition=null; $addressPosition=null;
        // A customer may supply a genuine pin before the copied recap. A later
        // conflicting pin invalidates the recap instead of being silently swapped in.
        foreach ($snapshot['transcript'] as $position=>$message) {
            if ($message['speaker']!=='customer') continue;
            // A recap cannot retroactively bind an old pin to a new address.
            if ($message['id']!==$receipt['recap_id']&&$message['sent_at']>=$activation
                &&is_string($message['text'])&&str_contains($message['text'],$data['customer']['address'])) $addressPosition=$position;
            if (($message['location']??null)===null) continue;
            $nativePoint=$this->validPoint($message['location']['lat']??null,$message['location']['long']??null);
            $pinPosition=$position;
            if ($nativePoint===null) { $reason='LOCATION_UNCONFIRMED'; return null; }
        }
        if ($point!==null) $point=$this->validPoint($point['lat']??null,$point['long']??null);
        if ($nativePoint!==null&&$point!==null&&$point!==$nativePoint) { $reason='LOCATION_UNCONFIRMED'; return null; }
        if ($point===null&&($addressPosition===null||$pinPosition===null||$pinPosition<=$addressPosition)) {
            $reason='LOCATION_UNCONFIRMED'; return null;
        }
        $point=$point??$nativePoint;
        if ($point===null) { $reason='LOCATION_UNCONFIRMED'; return null; }
        $customer=$data['customer'];
        if (mb_strlen($customer['name'])>100||mb_strlen($customer['address'])>500
            ||mb_strlen((string)$customer['area'])>150) { $reason='REVIEW_REQUIRED'; return null; }
        $catalog=$this->dialogueCatalog($branch,$actor);
        if ($catalog===null) { $reason='CATALOG_UNRESOLVED'; return null; }
        $items=[]; $identities=[];
        foreach ($data['items'] as $item) {
            $mapped=$this->customerReceiptItem($item,$catalog);
            if ($mapped===null) { $reason='CATALOG_UNRESOLVED'; return null; }
            $identity=$mapped['product_id'].':'.$mapped['option_id'];
            if (isset($identities[$identity])) { $reason='CATALOG_UNRESOLVED'; return null; }
            $identities[$identity]=true; $items[]=$mapped;
        }
        $notes=trim((string)($customer['notes']??''));
        if (($data['alternate_phones']??[])!==[]) $notes=trim($notes."\nأرقام تواصل إضافية: ".implode('، ',$data['alternate_phones']));
        if (mb_strlen($notes)>500) { $reason='REVIEW_REQUIRED'; return null; }
        $values=['expected_revision'=>(int)$row->revision,'branch'=>$branch,
            'customer_name'=>$customer['name'],'customer_phone'=>$customer['phone'],'address'=>$customer['address'],
            'area'=>$customer['area']??'','delivery_notes'=>$notes,
            'latitude'=>$point['lat'],'longitude'=>$point['long'],'location_confirmed'=>true,'items'=>$items];
        if ($origin!==null) $values['delivery_origin_branch']=$origin;
        $values=$this->review($values);
        // Distinct wamids, wording/option order or coordinate precision do not
        // turn the same effective basket into another order. A genuinely repeated
        // order inside this bounded window needs a human to confirm it is new.
        $fingerprint=$this->customerReceiptReviewFingerprint($values);
        $previous=DB::table(static::DRAFTS)->where('conversation_id',$conversation)->where('status','DISPATCHED')
            ->where('id','!=',(int)$row->id)->where('dispatched_at','>=',now('UTC')->subHours(24)->format('Y-m-d H:i:s'))
            ->orderByDesc('id')->limit(101);
        if ($lock) $previous->lockForUpdate();
        $completed=$previous->selectRaw('CASE WHEN LENGTH(sealed_payload)<=262144 THEN sealed_payload ELSE NULL END AS sealed_payload')->get();
        if (count($completed)>100) { $reason='CUSTOMER_RECAP_REPLAY'; return null; }
        foreach ($completed as $old) {
            $sealed=$this->decode($old->sealed_payload);
            if (!is_array($sealed['review']??null)) { $reason='REVIEW_REQUIRED'; return null; }
            if (hash_equals($fingerprint,$this->customerReceiptReviewFingerprint($sealed['review']))) {
                $reason='CUSTOMER_RECAP_REPLAY'; return null;
            }
        }
        return $values;
    }

    /** Match whole literal product/option labels only; similarity is never order authority. */
    private function customerReceiptItem(array $item,array $catalog): ?array
    {
        $name=$this->customerReceiptName($item['name']); $matches=[];
        $add=static function(int $product,string $option)use(&$matches):void {
            $matches[$product.':'.$option]=['product_id'=>$product,'option_id'=>$option];
        };
        foreach ($catalog as $product) {
            if (($product['available']??false)!==true
                ||!in_array($product['quantity_mode']??'select',[$item['quantity_mode'],'select'],true)) continue;
            $base=$this->customerReceiptName($product['name']);
            if ($name===$base) {
                $options=$product['options']??[];
                // Default options still pass the existing qualifier coverage guard.
                $literalItem=$item; $literalItem['name']=$product['name'];
                $option=$options===[]?'':$this->catalogOption($literalItem,[$literalItem],[$product['name']],$product);
                if ($option!==null) $add($product['id'],$option);
            }
            foreach ($product['options']??[] as $option) {
                $label=$this->customerReceiptName($option['label']);
                if ($label!==''&&in_array($name,[$base.' '.$label,$label.' '.$base],true))
                    $add($product['id'],$option['id']);
            }
            foreach ($product['preparation_options']??[] as $preparation) {
                $label=$this->customerReceiptName($preparation['label']);
                if ($label===''||!in_array($name,[$base.' '.$label,$label.' '.$base],true)) continue;
                if ($preparation['id']==='') {
                    // POS exposes a base preparation for plain SKUs too. Whole
                    // fish needs an actual configured full-unit cleaning choice;
                    // Normal needs the same product's genuine combo alternative.
                    $counterpart=false;
                    foreach ($product['options']??[] as $option) {
                        if ($this->informationPrice($option['price']??null)===null) continue;
                        if (in_array($label,['كامل','whole'],true)&&preg_match('/\Af:0:(?:extra_clear|extra_clean)\z/D',$option['id'])) $counterpart=true;
                        if (in_array($label,['عادي','normal'],true)&&preg_match('/\Af:[1-9][0-9]*:base\z/D',$option['id'])
                            &&in_array($this->name($option['label']),['كومبو','combo'],true)) $counterpart=true;
                    }
                    if (!$counterpart) continue;
                }
                $add($product['id'],$preparation['id']);
            }
        }
        $matches=array_values($matches);
        if (count($matches)!==1||!is_int($matches[0]['product_id'])||$matches[0]['product_id']<1
            ||$this->quantityMillis($item['quantity'])===null) return null;
        return $matches[0]+['quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode']];
    }

    private function customerReceiptName(string $value): string
    {
        $value=strtr(mb_strtolower($value,'UTF-8'),['أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي','ة'=>'ه',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $value=trim(preg_replace('/[\p{Z}\s]+/u',' ',$value));
        return preg_replace('/\A(?:كيلو|كيلوجرام|كيلوغرام|كجم|كغ)\s+/u','',$value);
    }

    private function customerReceiptReviewFingerprint(array $review): string
    {
        $items=array_map(fn($item)=>[(int)$item['product_id'],$this->quantityMillis($item['quantity']),$item['quantity_mode'],
            (string)($item['option_id']??''),(int)($item['feature_id']??0),(string)($item['product_clean']??'')],$review['items']);
        usort($items,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
        return PosServiceTicket::fingerprint([$review['branch'],$review['delivery_origin_branch']??$review['branch'],
            BranchCustomers::phoneKey($review['customer_phone']),$this->name($review['address']),
            $this->validPoint($review['latitude'],$review['longitude']),$items]);
    }

    protected function followupService()
    {
        if ($this->table('whatsapp_inbox_messages')!=='whatsapp_inbox_messages'
            ||!class_exists(WhatsAppOrderFollowupService::class)) return null;
        return app(WhatsAppOrderFollowupService::class);
    }

    private function waitingForCustomer(int $conversation,int $analyzed,int $ceiling): bool
    {
        if ($this->analysisOnly($conversation)) return false;
        try { $service=$this->followupService(); return $service!==null&&$service->waitingForCustomer($conversation,$analyzed,$ceiling); }
        catch (\Throwable $ignore) { return false; }
    }

    /** Asking for facts is separate from confirming, quoting or dispatching an order. */
    private function requestMissingInformation(object $row,$actor): bool
    {
        try {
            if ($this->analysisOnly((int)$row->conversation_id)) return false;
            if ($this->controlInstalled()&&!$this->replyControlOpen((int)$row->conversation_id)) return false;
            $service=$this->followupService(); if ($service===null) return false;
            $plan=$this->missingInformationPlan((int)$row->id,$actor);
            if ($plan===null) return false;
            $service->request($actor,$plan,fn()=>$this->missingInformationPlan((int)$row->id,$actor));
            return true;
        } catch (\Throwable $ignore) {
            // A question failure leaves the ordinary review/backoff and all ERP gates intact.
        }
        return false;
    }

    private function missingInformationPlan(int $id,$actor): ?array
    {
        if ($this->analysisOnly((int)$this->draft($id)->conversation_id)) return null;
        if ($this->controlInstalled()&&!$this->replyControlOpen((int)$this->draft($id)->conversation_id)) return null;
        if ((!$this->controlInstalled()&&!(bool)$this->orderConfig('whatsapp_orders.enabled',false))||$this->mode()!=='auto'
            ||$this->pendingCapture()) return null;
        $row=$this->draft($id);
        $fresh=$this->automaticActor((int)$row->conversation_id); if ((int)$fresh->id!==(int)$actor->id) return null;
        $questionReasons=['REVIEW_REQUIRED','LOCATION_UNCONFIRMED','BRANCH_UNRESOLVED','PICKUP_REQUIRES_REVIEW',
            'CATALOG_UNRESOLVED','INVALID_EXTRACTION','NO_ORDER'];
        if ($this->controlInstalled()) $questionReasons[]='BRANCH_CLOSED';
        if (!in_array($row->status,$this->controlInstalled()?['REVIEW','NONE']:['REVIEW'],true)||$row->ticket_id!==null
            ||!in_array($row->reason,$questionReasons,true)) return null;
        $this->current($row);
        $floor=$this->automaticFloor((int)$row->conversation_id);
        $eventFloor=$this->activationBoundary('activation_event_id',(int)$row->conversation_id); $activation=$this->activationInstant((int)$row->conversation_id);
        if ($eventFloor===null||$activation===null||(int)$row->evidence_floor<$floor
            ||(int)$row->evidence_ceiling<=$floor
            ||DB::table($this->table('whatsapp_inbox_ingestion_failures'))->where('event_id','>',$eventFloor)->exists()) return null;
        $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        $data=$this->decode($row->extraction);
        $primary=$this->controlInstalled()?$this->primaryAuthority((int)$row->conversation_id,$snapshot):null;
        if ($this->controlInstalled()&&$primary===null) return null;
        if (!$snapshot['complete']||(!is_array($data)&&$primary===null)) return null;
        $transcript=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
        $notBefore=max($activation,$snapshot['recovered_at']??$activation);
        $continuation=$this->followupService()===null?null:$this->followupService()->questionContinuation((int)$row->conversation_id,$snapshot,$notBefore);
        $noOrder=$primary!==null&&$row->status==='NONE';
        $recovery=$row->reason==='INVALID_EXTRACTION';
        $recoveryIntent=$recovery&&$continuation===null&&$this->followupService()!==null
            ?$this->followupService()->questionRecoveryIntent((int)$row->conversation_id,$snapshot,$notBefore):null;
        $recoveryField=$data['analysis_failure']['diagnostic']['validation_field']??null;
        if ($recovery||$noOrder) {
            // Rejected model facts are never repaired or reused. This ephemeral empty
            // DRAFT permits only a clarification after an authenticated question
            // continuation or a verified native order intent and merchant handoff.
            if ($primary===null&&$continuation===null&&$recoveryIntent===null) return null;
            $data=WhatsAppOrderExtraction::none('MISSING_ITEMS'); $data['decision']='DRAFT';
            foreach ($transcript as $message) if ($message['speaker']==='customer'
                &&(is_string($message['text'])||($message['cart']??null)!==null)) $data['evidence']['request_ids'][]=$message['id'];
        }
        $checked=WhatsAppOrderExtraction::validateDetailed($data,$snapshot['transcript']);
        if (!$checked['ok']||!in_array($data['decision']??null,['DRAFT','CONFIRMED'],true)
            ||array_diff($data['issues']??[],['MISSING_CUSTOMER_NAME','MISSING_PHONE','MISSING_ADDRESS','MISSING_AREA',
                'MISSING_BRANCH','MISSING_ITEMS','MISSING_QUANTITY','LOCATION_REQUIRED','UNCONFIRMED','PRICE_ESTIMATE_ONLY',
                'AMBIGUOUS_ITEM','AMBIGUOUS_BRANCH','CONFLICTING_DETAILS'])) return null;
        $episode=$this->freshFollowupIntent($data,$transcript,$notBefore);
        if ($primary===null&&$episode===null&&$continuation===null&&$recoveryIntent===null) return null;
        if ($data['decision']==='CONFIRMED'&&!$this->freshAutomaticEvidence($data,$snapshot['transcript'],$notBefore,(int)$row->conversation_id)) return null;
        $latest=null;
        foreach ($transcript as $message) if ($message['speaker']==='customer') $latest=$message;
        if ($latest===null||$latest['sent_at']<$notBefore) return null;
        $customerRow=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$row->conversation_id)
            ->where('message_key',$snapshot['keys'][$latest['id']])->where('direction','inbound')->first(['id','message_key']);
        if (!$customerRow) return null;
        $fields=[]; $customer=$data['customer'];
        $dialogue=null; $catalog=null;
        foreach (['name'=>'NAME','phone'=>'PHONE'] as $field=>$label)
            if (!is_string($customer[$field])||trim($customer[$field])==='') $fields[]=$label;
        $mode=WhatsAppOrderAiProvider::followupFulfillment($data,$transcript); $pickup=$mode==='PICKUP';
        if (!$pickup&&(!is_string($customer['address'])||trim($customer['address'])==='')) $fields[]='ADDRESS';
        if ($mode==='UNKNOWN') $fields[]='FULFILLMENT';
        if (!$data['items']) $fields[]='ITEMS';
        foreach ($data['items'] as $item) {
            if ($item['quantity']===null) $fields[]='QUANTITY';
            if ($item['quantity_mode']==='unknown') $fields[]='QUANTITY_MODE';
        }
        $routing=null; $routeData=$data;
        $information=$primary===null?null:$this->informationTurn($transcript);
        $inquiry=$noOrder&&$information===null&&$this->primaryCatalogInquiry($transcript);
        if ($inquiry) {
            $nativeTexts=[]; foreach ($transcript as $message) if ($message['speaker']==='customer'&&is_string($message['text'])) $nativeTexts[]=$message['text'];
            $routeData['customer']['address']=implode(' ',$nativeTexts);
        }
        $resolved=$this->automaticBranch($routeData,$fresh,$routing,$information['text']??null);
        if ($resolved===null&&($primary!==null||$fields===[])&&in_array($routing,['MISSING','UNKNOWN_HINT'],true)) $fields[]='BRANCH';
        if ($resolved===null&&in_array($routing,['AMBIGUOUS','CONFLICTING'],true)) {
            $fields=['BRANCH'];
            $dialogue=$this->dialogueContext('BRANCH','CONFLICT',null,[],$snapshot['transcript']);
        }
        if ($resolved!==null) {
            $questionBranch=$this->branch($resolved['fulfillment_branch']??$resolved['value'],$fresh);
            // Primary reply hours may collect a future order while the physical branch is closed.
            // Automatic ERP review and dispatch retain the existing live branch-hours guard.
            $reason=null; if ($primary===null&&!$this->branchOpen($questionBranch,$reason)) return null;
            if ($data['items']!==[]) {
                $catalog=$this->dialogueCatalog($resolved['fulfillment_branch']??$resolved['value'],$fresh);
                if ($catalog===null) return null;
                $dialogue=$this->catalogClarification($data,$snapshot['transcript'],$catalog);
            }
        }
        if ($data['decision']==='CONFIRMED'&&($row->reason==='LOCATION_UNCONFIRMED'||($primary!==null&&$row->reason==='BRANCH_CLOSED'))&&!$pickup&&$resolved!==null
            &&!$this->followupHasPoint($data,$transcript,$resolved['fulfillment_branch']??$resolved['value'],$fresh)) $fields[]='LOCATION';
        $needsApproval=$data['decision']==='CONFIRMED'&&$this->followupService()!==null
            &&!$this->followupService()->approvalSatisfied((int)$row->conversation_id,$data,$snapshot);
        if ($fields===[]&&$dialogue===null&&($data['decision']==='DRAFT'||$needsApproval)&&!$pickup&&$resolved!==null) {
            if (!$this->followupItemQuantities($data,$transcript,$resolved['fulfillment_branch']??$resolved['value'])) {
                foreach ($data['items'] as $item) if (($item['cart_reference']??null)!==null) return null;
                $fields[]='QUANTITY';
                $name=$this->quantityQuestionItem($data,$transcript,'QUANTITY');
                $dialogue=$name===null?$this->dialogueContext('ITEMS','CONFLICT',null,[],$snapshot['transcript'])
                    :$this->dialogueContext('QUANTITY','QUANTITY_ASSOCIATION',$name,[],$snapshot['transcript']);
            }
            else {
                $branch=$resolved['fulfillment_branch']??$resolved['value'];
                if (!$this->followupHasPoint($data,$transcript,$branch,$fresh)) $fields[]='LOCATION';
                elseif ($this->followupCatalogReady($data,$transcript,$branch,$fresh)) $fields[]='CONFIRMATION';
            }
        }
        if ($noOrder) {
            if ($inquiry&&$resolved===null) $dialogue=$this->dialogueContext('BRANCH','MISSING',null,[],$snapshot['transcript']);
            elseif ($inquiry) {
                $catalog=$this->dialogueCatalog($resolved['fulfillment_branch']??$resolved['value'],$fresh);
                if ($catalog===null) return null;
                $candidates=[];
                foreach ($catalog as $product) if (($product['available']??false)===true&&is_string($product['name']??null)
                    &&!in_array($product['name'],array_column($candidates,'label'),true)) {
                    $candidates[]=['id'=>'c'.(count($candidates)+1),'label'=>$product['name']];
                    if (count($candidates)===12) break;
                }
                if ($candidates===[]) return null;
                $dialogue=$this->dialogueContext('ITEMS','CATALOG_INFORMATION',null,$candidates,$snapshot['transcript']);
            } else $dialogue=$this->dialogueContext('ITEMS','GREETING',null,[],$snapshot['transcript']);
        }
        if ($recovery) {
            $field=$continuation['fields'][0]??(['ITEM_QUANTITY'=>'QUANTITY','CUSTOMER_NAME'=>'NAME',
                'CUSTOMER_PHONE'=>'PHONE','CUSTOMER_ADDRESS'=>'ADDRESS','LOCATION_REFERENCE'=>'LOCATION'][$recoveryField??'']??'ITEMS');
            $dialogue=$this->dialogueContext($field,'UNREADABLE',null,[],$snapshot['transcript']);
        }
        // Answer the actual latest customer question before resuming any missing
        // field or menu choice. This changes only the reply plan, never stored facts.
        if ($information!==null) {
            if ($resolved!==null&&$catalog===null) {
                $catalog=$this->dialogueCatalog($resolved['fulfillment_branch']??$resolved['value'],$fresh);
                if ($catalog===null) return null;
            }
            $dialogue=$this->informationContext($information,$catalog,$snapshot['transcript']);
            if ($dialogue===null) return null;
        }
        if ($dialogue!==null) $fields=[$dialogue['field']];
        if ($fields===[]) return null;
        $fields=array_values(array_unique($fields)); sort($fields);
        $fields=WhatsAppOrderFollowupService::unaskedTopics($fields,$snapshot['transcript']);
        if ($fields===[]) return null;
        if ($fields!==['CONFIRMATION']&&$dialogue===null) {
            // One precise question at a time, with the actual conversation available
            // to the selector. Its answer is re-extracted normally on the next turn.
            if ($primary!==null) {
                $priority=['BRANCH','FULFILLMENT','ITEMS','QUANTITY','QUANTITY_MODE','NAME','PHONE','ADDRESS','LOCATION'];
                $field=array_values(array_intersect($priority,$fields))[0];
            } else $field=in_array('BRANCH',$fields,true)?'BRANCH':$fields[0];
            $name=in_array($field,['QUANTITY','QUANTITY_MODE'],true)?$this->quantityQuestionItem($data,$transcript,$field):null;
            $dialogue=$this->dialogueContext($field,$recovery?'UNREADABLE':'MISSING',$name,[],$snapshot['transcript']);
            if ($dialogue===null) return null;
            $fields=[$field];
        }
        $plan=['draft_id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,
            'evidence_floor'=>(int)$row->evidence_floor,'evidence_ceiling'=>(int)$row->evidence_ceiling,
            'evidence_hash'=>$row->evidence_hash,'latest_customer_id'=>(int)$customerRow->id,
            'latest_customer_key'=>$customerRow->message_key,'episode_customer_key'=>$episode===null
                ?($continuation['episode_customer_key']??$recoveryIntent??($primary!==null?$customerRow->message_key:null)):$snapshot['keys'][$episode],
            'fields'=>$fields,'data'=>$data,'transcript'=>$snapshot['transcript']];
        if ($primary!==null) $plan['primary_ai']=$primary;
        if ($dialogue!==null) {
            $plan['dialogue']=$dialogue;
            $plan['catalog_fingerprint']=hash('sha256',json_encode($catalog,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }
        return $plan;
    }

    private function primaryCatalogInquiry(array $transcript): bool
    {
        foreach ($transcript as $message) if ($message['speaker']==='customer'&&is_string($message['text'])
            &&preg_match('/(?:منيو|مينيو|قائمة|الأسعار|الاسعار|بكام|سعر|أسعار|اسعار|موجود|متاح|بتبيع)|\b(?:menu|price|prices|available|availability)\b/iu',$message['text'])) return true;
        return false;
    }

    /** Latest native text intent only; these hints never select a product or approve an order. */
    private function informationTurn(array $transcript): ?array
    {
        $latest=null; $preceding=null; $previous=null;
        foreach (WhatsAppOrderConversationEvidence::episodeTranscript($transcript) as $row) {
            if (($row['speaker']??null)==='customer') { $latest=$row; $preceding=$previous; }
            $previous=$row;
        }
        if ($latest===null||!is_string($latest['text']??null)||trim($latest['text'])==='') return null;
        $text=$latest['text'];
        $quantityAnswer=preg_match('/\\A\\s*(?:تمام\\s+)?(?:(?:[0-9٠-٩۰-۹]+(?:[.,٫][0-9٠-٩۰-۹]+)?|نص|نصف|ربع)\\s*)?(?:كيلو(?:جرام|غرام)?|كجم|كغ|جرام|غرام|جم|قطعة|قطعه|حبة|حبه|علبة|علبه|سمكة|سمكه)(?:\\s+(?:ونص|ونصف|وربع|لو\\s+سمحت|بس|فقط))*\\s*[?؟.!،]*\\s*\\z/u',$text)===1;
        if ($quantityAnswer&&($preceding['speaker']??null)==='business'&&is_string($preceding['text']??null)
            &&preg_match('/(?:تحب|عايز|عاوز|تحتاج).{0,30}(?:قد\\s+إيه|قد\\s+ايه|قد\\s+أيه|كام|كم)|(?:how\\s+(?:much|many)|what\\s+quantity)/iu',$preceding['text'])) return null;
        $information=preg_match('/(?:يعني|معن[ىي]|معناه|معناها|تشرح|اشرح|وضح|مش\\s+فاهم|قصدك|تقصد|الفرق|يفرق|مكونات|وصف|تفاصيل|نوعها|نوعه|بكام|سعر|أسعار|اسعار|متاح|موجود|بتبيع|اعرف|أعرف|منيو|مينيو|كتالوج|تطبيق|رقمكم|رقم\\s+(?:فرع|الفرع|فسخانستا)|عنوان\\s+(?:فرع|الفرع))|(?<![\\p{L}\\p{N}])(?:إيه|ايه|كام|هل|كيف|ليه|ازاي|إزاي|امتى|امتي|إمتى)(?![\\p{L}\\p{N}])|\\b(?:what|why|how|when|mean|meaning|explain|difference|description|ingredients|price|prices|available|availability)\\b/iu',$text)===1;
        // A courteous question mark does not turn an actual purchase request into
        // a product-definition question. It still supplies no final approval.
        $purchase=preg_match('/\\A\\s*(?:(?:أنا|انا)\\s+)?(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمللي|ضيف|زود)(?![\\p{L}\\p{N}])|'
            .'\\A\\s*ممكن\\s+(?:[0-9٠-٩]+(?:[.,٫][0-9٠-٩]+)?|نص|نصف|ربع|تلات|ثلاث|اتنين|اثنين)?\\s*(?:كيلو|كجم|جرام|جم|قطعة|قطعه|حبة|حبه|سمكة|سمكه|علبة|علبه|وجبة|وجبه)(?![\\p{L}\\p{N}])|'
            .'\\A\\s*(?:I\\s+want|please\\s+(?:send|order)|order|send)\\b/iu',$text)===1;
        if ($purchase&&!$information) return null;
        $question=preg_match('/[?؟]|(?:مش\\s+فاهم|قصدك|تقصد)|(?<![\\p{L}\\p{N}])(?:يعني|معن[ىي]|معناه|معناها|إيه|ايه|بكام|كام|هل|كيف|ليه|ازاي|إزاي|امتى|امتي|إمتى|اعرف|أعرف)(?![\\p{L}\\p{N}])|\\b(?:what|why|how|when|explain|difference)\\b/iu',$text)===1;
        if (!$question&&preg_match('/\\A\\s*(?:تمام\\s+)?(?:موافق|اتفقنا|أؤكد|اؤكد|أكد|اكد|yes|confirm|ok(?:ay)?)(?![\\p{L}\\p{N}])/iu',$text)) return null;
        if (!$question&&!$information&&!preg_match('/(?:مواعيد|ساعات\\s+العمل|(?:أرقام|ارقام|أماكن|اماكن|عناوين)\\s+الفروع|عنوانكم|طرق\\s+(?:الطلب|الدفع)|طريقة\\s+الطلب)/u',$text)) return null;
        return $latest;
    }

    /** Exact branch facts for a conversational answer; private prices are never ERP authority. */
    private function informationContext(array $turn,?array $catalog,array $transcript): ?array
    {
        $episode=WhatsAppOrderConversationEvidence::episodeTranscript($transcript);
        $text=$turn['text']; $facts=[];
        $add=static function(string $subject,string $value)use(&$facts):void {
            if (count($facts)>=24||mb_strlen($subject)>240||mb_strlen($value)>500
                ||preg_match('/[\\x00-\\x1f\\x7f]/u',$subject.$value)) return;
            $facts[]=['id'=>'f'.(count($facts)+1),'text'=>$value,'subject'=>$subject];
        };
        $meaning=preg_match('/(?:يعني|معن[ىي]|معناه|معناها|تشرح|اشرح|وضح|مش\\s+فاهم|قصدك|تقصد|الفرق|يفرق|مكونات|وصف|تفاصيل)|(?<!\\p{L})(?:إيه\\s+ده|ايه\\s+ده|ليه|ازاي|إزاي)(?!\\p{L})|\\b(?:mean|meaning|explain|difference|description|ingredients|why|how)\\b/iu',$text)===1;
        $price=preg_match('/(?:بكام|سعر|أسعار|اسعار)|\\b(?:price|prices|cost)\\b/iu',$text)===1;
        $availability=preg_match('/(?:متاح|موجود|بتبيع|منيو|مينيو)|\\b(?:menu|available|availability)\\b/iu',$text)===1;
        $ordering=preg_match('/(?:ازاي|إزاي|كيف).{0,35}(?:أطلب|اطلب|أوردر|اوردر|طلب)|(?:أطلب|اطلب|طلب|أوردر|اوردر).{0,35}(?:ازاي|إزاي|كيف)|\\bhow.{0,25}order\\b/iu',$text)===1;
        $hours=preg_match('/(?:شغال|فاتح|بتفتح|بتقفل|مواعيد|موعد|امتى|امتي|إمتى)|\\b(?:hours|open|close|when)\\b/iu',$text)===1;
        $deliveryFee=preg_match('/(?:سعر|تكلفه|تكلفة|رسوم).{0,15}(?:التوصيل|الدليفري)|(?:التوصيل|الدليفري).{0,15}(?:بكام|كام|سعر)|\\bdelivery.{0,15}(?:price|fee|cost)\\b/iu',$text)===1;
        $knowledge=$this->businessInformationFacts($text); $covered=[];
        $previous=null;
        foreach ($episode as $row) { if ($row['id']===$turn['id']) break; $previous=$row; }
        if ($meaning&&($previous['speaker']??null)==='business'&&is_string($previous['text']??null)) {
            $prior=$previous['text'];
            if (preg_match('/(?:رقم\\s+(?:هاتف|الهاتف|للتواصل|الموبايل)|رقمك|رقم\\s+تواصل)/u',$prior))
                $knowledge[]=['topic'=>'CONTACT','subject'=>'سبب رقم التواصل','text'=>'نحتاج رقم تواصل واحد للتواصل مع حضرتك بخصوص تفاصيل الطلب والتوصيل. رقم إضافي اختياري.'];
            elseif (preg_match('/(?:لوكيشن|الموقع|موقع\\s+التوصيل|location)/iu',$prior))
                $knowledge[]=['topic'=>'LOCATION','subject'=>'سبب موقع التوصيل','text'=>'نحتاج تأكيد نقطة التوصيل على الخريطة لحساب المسافة ومراجعة وصول الطلب إلى العنوان المقصود. مشاركة الموقع لا تعني الموافقة على تنفيذ الطلب.'];
            elseif (preg_match('/(?:عنوان\\s+التوصيل|عنوانك|العنوان)/u',$prior))
                $knowledge[]=['topic'=>'ADDRESS','subject'=>'سبب عنوان التوصيل','text'=>'عنوان التوصيل يحدد المكان المقصود لإيصال الطلب ويساعد على مراجعة بيانات الدليفري. كتابة العنوان وحدها لا تؤكد الطلب.'];
        }
        foreach ($knowledge as $fact) { $add($fact['subject'],$fact['text']); $covered[$fact['topic']]=true; }
        if ($ordering&&!isset($covered['ORDER'])) $add('طريقة الطلب','لبداية الطلب بنحدد الصنف والكمية، والفرع وطريقة الاستلام، ثم بيانات التواصل. للدليفري نحتاج عنوان التوصيل. بعد اكتمال البيانات بنراجع ملخص الطلب مع العميل للموافقة قبل التنفيذ.');
        elseif ($hours&&!isset($covered['HOURS'])&&$knowledge===[]) $add('مواعيد الفرع','مواعيد عمل الفرع غير موجودة في بيانات المنيو لهذا الاستفسار. لا يمكن تأكيد وقت فتح أو إغلاق الفرع من هذه البيانات.');
        if ($deliveryFee) $add('رسوم التوصيل','رسوم التوصيل النهائية غير موجودة في بيانات المنيو لهذا الاستفسار، وتحتاج إلى عنوان العميل وحساب التوصيل الفعلي. لا يمكن تخمين مبلغها.');
        if ($meaning&&preg_match('/(?:هيرنج|هيرينج|herring)/iu',$text))
            $add('هيرنج','هيرنج هو الاسم الإنجليزي لسمك الرنجة. الاسم وحده لا يحدد طريقة تجهيز الصنف أو مكوناته.');
        if ($catalog===null) {
            if ($price) $add('سعر الفرع','لا يوجد فرع محدد للاستفسار الحالي، ولذلك لا تتوفر قيمة سعر مؤكدة له. يلزم معرفة الفرع أو المدينة أولاً.');
            elseif ($knowledge===[]&&$facts===[]) $add('تفاصيل المنيو','تفاصيل الصنف وتوفره في فرع معين غير مؤكدة قبل تحديد الفرع أو المدينة.');
        } else {
            $tokens=$this->menuTokens($text); $matched=[];
            foreach ($catalog as $product)
                if ($this->relatedMenuTokens($tokens,$this->menuTokens($product['name']))) $matched[]=$product;
            // A short "مش فاهم" follows the actual business topic, without selecting
            // any offered product on the customer's behalf.
            if ($matched===[]&&$meaning) {
                $business=null;
                foreach ($episode as $row) {
                    if ($row['id']===$turn['id']) break;
                    if ($row['speaker']==='business'&&is_string($row['text'])) $business=$row['text'];
                }
                if ($business!==null) foreach ($catalog as $product)
                    if (strpos($business,$product['name'])!==false) $matched[]=$product;
                if ($knowledge===[]&&$business!==null) {
                    foreach ($this->businessInformationFacts($text.' '.$business) as $fact) {
                        if (!in_array($fact['topic'],['SALT','PREPARATION','ROE','MIX'],true)) continue;
                        $add($fact['subject'],$fact['text']); $covered[$fact['topic']]=true;
                    }
                }
            }
            if ($matched===[]&&$availability) $matched=array_values(array_filter($catalog,static fn($p)=>$p['available']));
            if ($matched===[]&&!$ordering&&!$hours&&!$deliveryFee&&$knowledge===[]&&$covered===[]) $add('التفصيل المطلوب','لا توجد بيانات مؤكدة تجيب التفصيل المحدد في السؤال الحالي. يمكن سؤال العميل عن الجزئية المقصودة لتوضيحها دون تخمين.');
            if ($ordering||$hours||$deliveryFee) $matched=[];
            foreach (array_slice($matched,0,6) as $product) {
                $name=$product['name'];
                if ($meaning&&$product['description']===null&&!array_intersect(array_keys($covered),['SALT','PREPARATION','ROE','MIX']))
                    $add($name,'لا يوجد وصف تفصيلي مسجل لـ «'.$name.'». لا يمكن استنتاج طريقة التجهيز أو المكونات أو الفرق عن صنف آخر من الاسم فقط.');
                $add($name,'اسم الصنف في منيو الفرع هو «'.$name.'»، وحالته '.($product['available']?'متاح':'غير متاح').' في هذا الفرع وقت الاستفسار.');
                if ($product['description']!==null) $add($name,'الوصف المسجل لـ «'.$name.'»: '.$product['description']);
                $unit=['weight'=>'كيلو','piece'=>'قطعة'][$product['quantity_mode']]??null;
                if ($price) {
                    if ($product['price']!==null&&$unit!==null)
                        $add($name,'سعر «'.$name.'» المسجل في منيو هذا الفرع هو '.$product['price'].' جنيه مصري لكل '.$unit.'. هذا سعر الصنف وليس إجمالي الطلب أو التوصيل.');
                    else $add($name,'لا يوجد سعر مؤكد مع وحدة بيع واضحة لـ «'.$name.'» في البيانات المتاحة؛ لا يمكن تخمينه.');
                    foreach ($product['options'] as $option) if ($option['price']!==null)
                        $add($name,'قيمة السعر المسجلة بجوار اختيار «'.$option['label'].'» للصنف «'.$name.'» هي '.$option['price'].' جنيه مصري. '
                            .($unit===null?'وحدة البيع غير موضحة. ':'وحدة بيع الصنف '.$unit.'. ')
                            .'البيانات لا توضح هل هذه القيمة سعر نهائي أم إضافة، ولذلك لا تمثل سعراً نهائياً مؤكداً للطلب.');
                } elseif ($unit!==null) $add($name,'وحدة بيع «'.$name.'» المسجلة هي '.$unit.'.');
                if ($product['options']!==[]) $add($name,'الاختيارات المسجلة لـ «'.$name.'»: '.implode('، ',array_column($product['options'],'label')).'. الأسماء وحدها لا توضح تفاصيل تجهيزها.');
            }
        }
        if ($facts===[]) return null;
        $context=['field'=>'ITEMS','kind'=>'PRODUCT_INFORMATION','item_name'=>null,'candidates'=>[],
            'transcript'=>$transcript,'facts'=>$facts];
        return WhatsAppOrderDialogue::validContext($context)?$context:null;
    }

    /** Curated owner FAQ, reviewed 2026-10-15. Knowledge is never inventory, policy or ERP authority. */
    private function businessInformationFacts(string $text): array
    {
        $facts=[];
        $add=static function(string $topic,string $subject,string $value)use(&$facts):void {
            $facts[]=['topic'=>$topic,'subject'=>$subject,'text'=>$value];
        };
        $normalized=$this->routingName($text);
        if (preg_match('/(?:مواعيد|ساعات\\s+العمل|شغال|فاتح|فاتحين|بتفتح|بتقفل|وقت\\s+(?:العمل|التجهيز))|(?:تجهيز|بتجهز).{0,25}(?:امتي|امتى|متي|من|لحد)|\\b(?:working\\s+hours|opening|closing|open|close)\\b/iu',$normalized))
            $add('HOURS','مواعيد العمل والتجهيز','بنفتح وبنجهز الطلبات كل يوم من 10:00 الصبح لحد 01:00 بعد نص الليل بتوقيت القاهرة.');
        if (preg_match('/(?:التوصيل|الدليفري|الطلب).{0,30}(?:وقت|مده|بيوصل|هيوصل|يوصل|امتي|امتى)|(?:وقت|مده|امتي|امتى|هيوصل|يوصل).{0,30}(?:التوصيل|الدليفري|الطلب)|\\b(?:delivery\\s+time|delivery\\s+eta|when.*arrive)\\b/iu',$normalized))
            $add('ETA','مدة التوصيل المحلي','الدليفري المحلي بياخد تقريباً من 60 إلى 90 دقيقة حسب المنطقة وظروف التوصيل؛ المدة تقديرية ومش موعد مضمون.');
        if (preg_match('/(?:ازاي|كيف).{0,35}(?:اطلب|اوردر|طلب)|(?:اطلب|طلب|اوردر).{0,35}(?:ازاي|كيف)|طريقه\\s+الطلب|طرق\\s+الطلب|\\bhow.{0,25}order\\b/iu',$normalized))
            $add('ORDER','طريقة الطلب','يمكن بدء الطلب في محادثة واتساب أو ماسنجر أو من تطبيق فسخانستا. نحدد الصنف والكمية والفرع وطريقة الاستلام، ثم الاسم ورقم تواصل واحد وعنوان التوصيل للدليفري. رقم إضافي اختياري. بعد اكتمال البيانات نراجع الملخص مع العميل للموافقة قبل التنفيذ.');
        if (preg_match('/(?:منيو|مينيو|كتالوج|كتالوك|قائمه\\s+(?:الطعام|الاصناف))|\\b(?:menu|catalog)\\b/iu',$normalized))
            $add('MENU','كتالوج واتساب الرسمي','حضرتك تقدر تتصفح كتالوج واتساب الرسمي. السعر والتوفر والاختيارات النهائية من منيو الفرع الحالي. الرابط https://wa.me/c/201285545554');
        if (preg_match('/(?:تطبيق|ابليكيشن|ابلكيشن|تنزيل|تحميل)|\\b(?:app|application|download)\\b/iu',$normalized))
            $add('APP','تطبيق فسخانستا الرسمي','رابط تحميل تطبيق فسخانستا الرسمي https://fasakhaninja.com/downloadApp');
        if (preg_match('/(?:فرع|فروع|عناوين|عنوانكم|اماكن|مقر|تليفون|هاتف|ارقام|رقم\\s+(?:الفرع|فسخانستا))|\\b(?:branch|branches|address|phone)\\b/iu',$normalized)) {
            $branches=[['نبروه','نبروه','طريق نبروه قبل البوابة بجوار مصنع أبو أمين','01055644633'],
                ['المنصوره','المنصورة','عبد السلام عارف','0502353511'],
                ['المحله','المحلة','ميدان البندر','0402253800'],
                ['شبرا','شبرا الخيمة','الشارع الجديد','0248272734']];
            $selected=array_values(array_filter($branches,static fn($branch)=>strpos($normalized,$branch[0])!==false));
            foreach ($selected?:$branches as $branch) $add('BRANCHES','فرع '.$branch[1],'فرع '.$branch[1].': '.$branch[2].'. رقم التواصل: '.$branch[3].'.');
            if (preg_match('/(?:المقطم|التجمع|زايد|مدينتي)/u',$normalized))
                $add('ONLINE','مناطق الخدمة عبر التطبيق','المقطم والتجمع وزايد ومدينتي مناطق خدمة عبر التطبيق، وليست فروعاً فعلية لاستقبال الزوار.');
        }
        if (preg_match('/(?:صاله|صالة|جلوس|قاعدين|قعده|داخل\\s+الفرع|dine)/iu',$normalized))
            $add('DINE_IN','الصالة','الصالة للضيوف متاحة في الفروع الفعلية فقط، وليست في مناطق الخدمة عبر التطبيق.');
        if (preg_match('/(?:مناطق|اماكن|محافظات|محافظه|طنطا|شرقيه|دقهليه|شحن|موقف).{0,30}(?:توصيل|بتوصل|شحن)|(?:توصيل|بتوصل|شحن).{0,30}(?:مناطق|اماكن|محافظات|محافظه|طنطا|شرقيه|دقهليه|موقف)|\\bdelivery\\s+areas\\b/iu',$normalized))
            $add('AREAS','مناطق وخيارات التوصيل','توجد خدمة توصيل في القاهرة الكبرى والمنصورة والمحلة ونبروه. الشحن للمحافظات يكون إلى موقف أو محطة متفق عليها، وتحتاج نقطة الاستلام إلى تأكيد؛ لا يعني ذلك التوصيل إلى باب المنزل.');
        if (preg_match('/(?:فسيخ|فسخ)/u',$normalized)&&preg_match('/(?:نبروه|دسوق|ملح|ملوحه|الفرق|يفرق)/u',$normalized))
            $add('SALT','فسيخ نبروه ودسوق','فسيخ نبروه ملحه خفيف، وفسيخ دسوق ملحه مظبوط. النوعان من نفس المصنع وبنفس مستوى الجودة. درجة الملح مختلفة عن طريقة التنظيف أو التجهيز.');
        if (preg_match('/(?:مخلي|مخليه|متنضف|متنظف|تنظيف|تجهيز)/u',$normalized)
            &&(!array_intersect(array_column($facts,'topic'),['HOURS','ORDER'])||preg_match('/(?:مخلي|مخليه|متنضف|متنظف|تنظيف)/u',$normalized)))
            $add('PREPARATION','التنظيف والتخلية','مخلي يعني بدون عظم. متنضف يعني فتح السمكة وتنظيف البطن وفصل الرأس. طريقة التجهيز غير درجة الملح، والاختيارات اللي تقدر تطلبها من منيو الفرع الحالي.');
        if (preg_match('/(?:مبطرخ|مبطرخه|بطارخ)/u',$normalized))
            $add('ROE','الرنجة المبطرخة','مبطرخة تعني مع البطارخ. النوع والاختيارات والتوفر الفعلي يرجع إلى منيو الفرع الحالي.');
        if (preg_match('/(?:ميكس|mix)/iu',$normalized))
            $add('MIX','الوجبة الميكس','الوجبة الميكس فيها فسيخ ورنجة وسردين. حجم الحصة والإضافات والسعر والاختيارات الفعلية من منيو الفرع الحالي.');
        if (preg_match('/(?:عيش|خبز).{0,25}(?:منفصل|لوحده|لوحدها|تغليف)|(?:منفصل|لوحده|تغليف).{0,25}(?:عيش|خبز)|زيت.{0,20}(?:فقط|بس|بدون|من\\s+غير)/u',$normalized))
            $add('PREFERENCE','تفضيلات التجهيز والتغليف','حضرتك تقدر تطلب الخبز متغلف لوحده، أو الزيت بس من غير شطة. بنسجل الرغبة ونراجع الاختيارات المتاحة للصنف.');
        if (preg_match('/(?:حجز|احجز|مسبق|بكره|بكرا|يوم\\s+(?:تاني|اخر|آخر))|\\b(?:preorder|reservation|tomorrow)\\b/iu',$normalized))
            $add('PREORDER','الحجز والطلب ليوم لاحق','الطلب المسبق ليوم لاحق غير متاح؛ يرجى التواصل يوم الطلب. زيارة الفرع لا تحتاج إلى حجز مسبق.');
        if (preg_match('/(?:جمله|جملة|بالجمله|بالجملة)|\\bwholesale\\b/iu',$normalized))
            $add('WHOLESALE','طلبات الجملة','الحد الأدنى للجملة 50 كيلو. معلومات وأسعار الجملة يراجعها الموظف مباشرة مع العميل.');
        if (preg_match('/(?:طلب|اوردر).{0,25}(?:اضافه|ازود|تعديل|المندوب|خرج)|(?:اضافه|ازود|تعديل|المندوب).{0,25}(?:طلب|اوردر)/u',$normalized))
            $add('EXISTING_ORDER','تعديل طلب قائم','إضافة أصناف أو تعديل طلب قائم يحتاج إلى مراجعة الموظف. بعد خروج المندوب لا يمكن الوعد بإمكانية التعديل، والاستفسار وحده لا ينفذ إضافة على طلب سابق.');
        if (preg_match('/(?:طرق|طريقه|وسائل).{0,15}(?:دفع|سداد)|(?:فودافون|انستاباي|انستا\\s+باي)|\\b(?:Vodafone|InstaPay|payment\\s+methods)\\b/iu',$normalized))
            $add('METHODS','الوسائل المتاحة','الوسائل المتاحة: Vodafone Cash و InstaPay.');
        return array_slice($facts,0,12);
    }

    private function informationPrice($value): ?string
    {
        if (is_int($value)&&$value>=0) $value=(string)$value;
        elseif (is_float($value)&&is_finite($value)&&$value>=0&&round($value,2)===$value) $value=number_format($value,2,'.','');
        return is_string($value)&&preg_match('/\\A(?:0|[1-9][0-9]{0,6})(?:\\.[0-9]{1,2})?\\z/',$value)?$value:null;
    }

    private function dialogueContext(string $field,string $kind,?string $name,array $candidates,array $transcript): ?array
    {
        $context=['field'=>$field,'kind'=>$kind,'item_name'=>$name,'candidates'=>$candidates,'transcript'=>$transcript];
        return WhatsAppOrderDialogue::validContext($context)?$context:null;
    }

    private function quantityQuestionItem(array $data,array $transcript,string $field): ?string
    {
        foreach ($data['items'] as $item)
            if (($field==='QUANTITY'&&$item['quantity']===null)||($field==='QUANTITY_MODE'&&$item['quantity_mode']==='unknown')) return $item['name'];
        if ($data['decision']==='DRAFT') {
            $sources=null; $unresolved=null;
            if (!WhatsAppOrderConversationEvidence::draftItemQuantities($data,$transcript,$sources,$unresolved)
                &&is_int($unresolved)&&isset($data['items'][$unresolved])) return $data['items'][$unresolved]['name'];
        }
        return null;
    }

    /** Actor- and branch-scoped menu snapshot. No model-selected product is dispatched. */
    private function dialogueCatalog(string $branch,$actor): ?array
    {
        $catalog=[]; $seen=[]; $availableNames=[];
        for ($page=1;$page<=3;$page++) {
            $list=app(TakeawayCatalog::class)->listing(['branch'=>$branch,'page'=>$page,'per_page'=>100],$actor);
            if (!is_array($list['items']??null)||!is_numeric($list['pagination']['last_page']??null)) return null;
            foreach ($list['items'] as $product) {
                if (!is_array($product)||!is_numeric($product['id']??null)||!is_string($product['name']??null)
                    ||mb_strlen($product['name'])>200||!is_array($product['options']??null)) return null;
                if (isset($seen[(string)$product['id']])) return null;
                $seen[(string)$product['id']]=true;
                $available=($product['available']??false)===true;
                $normalizedName=$this->name($product['name']);
                // A customer cannot distinguish two ERP identities with the same
                // visible name. Do not ask a choice that cannot resolve that ambiguity.
                if ($available&&isset($availableNames[$normalizedName])) return null;
                if ($available) $availableNames[$normalizedName]=true;
                // Include unavailable rows in the private fingerprint so availability
                // changes during composition invalidate the exact refresh guard.
                $options=[]; $optionIds=[]; $optionLabels=[];
                foreach ($product['options'] as $option) {
                    if (!is_array($option)||!is_string($option['id']??null)||!is_string($option['label']??null)) return null;
                    $normalizedLabel=$this->name($option['label']);
                    if ($normalizedLabel===''||isset($optionIds[$option['id']])||isset($optionLabels[$normalizedLabel])) return null;
                    $optionIds[$option['id']]=true; $optionLabels[$normalizedLabel]=true;
                    $options[]=['id'=>$option['id'],'label'=>$option['label'],'price'=>$this->informationPrice($option['price']??null)];
                }
                $preparations=$this->customerReceiptPreparations($product,$options);
                if ($preparations===null) return null;
                $description=$product['description']??null;
                if (!is_string($description)||trim($description)===''||mb_strlen($description)>400
                    ||preg_match('/[\\x00-\\x1f\\x7f<>]/u',$description)) $description=null;
                $catalog[]=['id'=>(int)$product['id'],'name'=>$product['name'],'available'=>$available,
                    'quantity_mode'=>$product['quantity_mode']??'select','options'=>$options,
                    'preparation_options'=>$preparations,'price'=>$this->informationPrice($product['price']??null),'description'=>$description];
            }
            if ((int)$list['pagination']['last_page']<=$page) break;
            if ($page===3) return null;
        }
        usort($catalog,static fn($a,$b)=>$a['id']<=>$b['id']);
        return $catalog;
    }

    /** Public preparation labels retain their generated sale identity and price. */
    private function customerReceiptPreparations(array $product,array $options): ?array
    {
        if (!array_key_exists('preparation_options',$product)) return [];
        if (!is_array($product['preparation_options'])||!array_is_list($product['preparation_options'])
            ||count($product['preparation_options'])>100) return null;
        $minor=function($value):?int {
            $price=$this->informationPrice($value);if($price===null)return null;
            [$whole,$fraction]=array_pad(explode('.',$price,2),2,'');
            return(int)$whole*100+(int)str_pad($fraction,2,'0');
        };
        $basePrice=$minor($product['price']??null);$result=[];$ids=[];$labels=[];
        foreach ($product['preparation_options'] as $preparation) {
            if (!is_array($preparation)||!is_string($preparation['id']??null)||strlen($preparation['id'])>80
                ||!is_string($preparation['label']??null)||mb_strlen($preparation['label'])>200
                ||!is_int($preparation['feature_id']??null)||$preparation['feature_id']<0
                ||!is_string($preparation['product_clean']??null)
                ||!in_array($preparation['product_clean'],['','extra_clear','extra_clean','extra_vacuim'],true)) return null;
            $label=$this->name($preparation['label']);$price=$minor($preparation['price']??null);
            if ($label===''||$price===null||isset($ids[$preparation['id']])||isset($labels[$label])) return null;
            $feature=$preparation['feature_id'];$clean=$preparation['product_clean'];
            $id=$feature===0&&$clean===''?'':'f:'.$feature.':'.($clean===''?'base':$clean);
            if ($preparation['id']!==$id) return null;
            if ($id==='') { if($basePrice===null||$price!==$basePrice)return null; }
            else {
                $matching=array_values(array_filter($options,fn($option)=>$option['id']===$id));
                if(count($matching)!==1||$this->name($matching[0]['label'])!==$label||$minor($matching[0]['price']??null)!==$price) return null;
            }
            // A portion feature is never an extra preparation for an explicit
            // weight. Only the configured combo is a feature-only preparation.
            if($feature>0&&$clean===''&&!in_array($label,['كومبو','combo'],true)) return null;
            $ids[$id]=true;$labels[$label]=true;
            $result[]=['id'=>$id,'label'=>$preparation['label'],'price'=>$this->informationPrice($preparation['price']),
                'feature_id'=>$feature,'product_clean'=>$clean];
        }
        return$result;
    }

    private function catalogClarification(array $data,array $transcript,array $catalog): ?array
    {
        $texts=[];
        foreach (WhatsAppOrderConversationEvidence::episodeTranscript($transcript) as $message)
            if (is_string($message['text'])&&(($message['speaker']==='customer'&&in_array($message['id'],$data['evidence']['request_ids'],true))
                ||($message['speaker']==='business'&&in_array($message['id'],$data['evidence']['confirmation_ids'],true)))) $texts[]=$message['text'];
        foreach ($data['items'] as $item) {
            if (($item['cart_reference']??null)!==null) continue;
            $exact=array_values(array_filter($catalog,fn($product)=>$product['available']&&$this->name($product['name'])===$this->name($item['name'])));
            if (count($exact)===1) {
                if ($this->catalogOption($item,$data['items'],$texts,$exact[0],$data,WhatsAppOrderConversationEvidence::episodeTranscript($transcript))!==null) continue;
                $candidates=[];
                foreach ($exact[0]['options'] as $option) if ($option['label']!==''&&!in_array($option['label'],array_column($candidates,'label'),true)) {
                    $candidates[]=['id'=>'c'.(count($candidates)+1),'label'=>$option['label']]; if (count($candidates)===12) break;
                }
                if ($candidates!==[]) return $this->dialogueContext('ITEMS','OPTION_CHOICE',$item['name'],$candidates,$transcript);
            }
            $tokens=$this->menuTokens($item['name']); $candidates=[];
            foreach ($catalog as $product) {
                if (!$product['available']||!$this->relatedMenuTokens($tokens,$this->menuTokens($product['name']))) continue;
                if (in_array($product['name'],array_column($candidates,'label'),true)) continue;
                $candidates[]=['id'=>'c'.(count($candidates)+1),'label'=>$product['name']]; if (count($candidates)===12) break;
            }
            if ($candidates!==[]) return $this->dialogueContext('ITEMS','PRODUCT_CHOICE',$item['name'],$candidates,$transcript);
            return $this->dialogueContext('ITEMS','CONFLICT',$item['name'],[],$transcript);
        }
        return null;
    }

    private function menuTokens(string $text): array
    {
        $text=strtr(mb_strtolower($text,'UTF-8'),['ة'=>'ه','ى'=>'ي','أ'=>'ا','إ'=>'ا','آ'=>'ا','ؤ'=>'و','ئ'=>'ي']);
        $tokens=preg_split('/[^\p{L}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $tokens=array_map(static fn($token)=>mb_strlen($token)>4&&str_starts_with($token,'ال')?mb_substr($token,2):$token,$tokens);
        return array_values(array_unique(array_filter($tokens,static fn($token)=>mb_strlen($token)>=3
            &&!in_array($token,['كيلو','جرام','قطعه','قطعة','حبات','سمكات','نصف','ربع','واحد','ثلاثه','اثنين','عدد','طلب','عايز','عاوز','اريد'],true))));
    }

    /** Similar spellings are suggestions for the customer, never an ERP mapping. */
    private function relatedMenuTokens(array $requested,array $available): bool
    {
        if (array_intersect($requested,$available)) return true;
        foreach ($requested as $left) foreach ($available as $right)
            if ((mb_strlen($right)>=3&&$left==='و'.$right)
                ||(mb_strlen($left)>=4&&mb_strlen($right)>=4&&mb_substr($left,0,2)===mb_substr($right,0,2)
                &&abs(mb_strlen($left)-mb_strlen($right))<=1&&levenshtein($left,$right)<=2)) return true;
        return false;
    }

    /** Prompt-only intent proof; it cannot authorize confirmation or dispatch. */
    private function freshFollowupIntent(array $data,array $transcript,string $notBefore): ?string
    {
        if ($data['decision']==='CONFIRMED') {
            $requests=WhatsAppOrderExtraction::committingRequestIds($data,$transcript);
            foreach ($transcript as $message)
                if ($message['sent_at']>=$notBefore&&in_array($message['id'],$requests,true)) return $message['id'];
            return null;
        }
        $intent=null; $position=null;
        foreach ($transcript as $index=>$message) {
            if ($message['speaker']!=='customer'||$message['sent_at']<$notBefore
                ||!in_array($message['id'],$data['evidence']['request_ids'],true)) continue;
            if (($message['cart']??null)!==null) { $intent=$message['id']; $position=$index; break; }
            $text=$message['text']??'';
            if (!is_string($text)||preg_match('/[?؟"«»“”]|(?:مش\s+(?:عايز|عاوز)|لا\s+(?:اريد|أريد)|(?<![\p{L}\p{N}])(?:و)?(?:إلغاء|الغاء|إلغي|إلغى|الغي|الغى|الغيه|إلغيه|ألغي|ألغى|لغي|لغيه|إلغ|الغ|الغوا|إلغوا|الغيت|ألغيت|هلغي)(?![\p{L}\p{N}])|'
                . 'اعرف|أعرف|بكام|سعر|هل|ينفع|تجاهل|تعليمات|ignore|instructions|cancel|not\s+want)/iu',$text)
                ||!preg_match('/(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمل\s+لي|\b(?:want|order|send)\b)/iu',$text)) continue;
            $named=preg_match('/(?<![\p{L}\p{N}])(?:طلب|الطلب|أوردر|اوردر|الأوردر|الاوردر|order)(?![\p{L}\p{N}])/iu',$text)===1;
            foreach ($data['items'] as $item) if (strpos($text,$item['name'])!==false) $named=true;
            if ($named) { $intent=$message['id']; $position=$index; break; }
        }
        if ($intent===null) return null;
        foreach ($transcript as $index=>$message) if ($index>$position&&$message['speaker']==='customer'
            &&is_string($message['text'])&&preg_match('/(?:مش\s+(?:عايز|عاوز|موافق)|لا\s+(?:أريد|اريد|ترسل)|(?<![\p{L}\p{N}])(?:و)?(?:إلغاء|الغاء|إلغي|إلغى|الغي|الغى|الغيه|إلغيه|ألغي|ألغى|لغي|لغيه|إلغ|الغ|الغوا|إلغوا|الغيت|ألغيت|هلغي)(?![\p{L}\p{N}])|'
                . '(?:غير|غيّر|بدل|بدّل)\s+(?:الطلب|الأوردر|الاوردر)|تجاهل|تعليمات|cancel|not\s+want|ignore|instructions|change\s+the\s+order)/iu',$message['text'])) return null;
        return $intent;
    }

    private function followupHasPoint(array $data,array $transcript,string $branch,$actor): bool
    {
        $transcript=WhatsAppOrderConversationEvidence::episodeTranscript($transcript);
        $addressAt=null; $lastPin=null;
        foreach ($transcript as $message) {
            if ($message['speaker']!=='customer') continue;
            foreach ([$message['text'],$message['cart']['text']??null] as $text)
                if (is_string($text)&&strpos($text,$data['customer']['address'])!==false) $addressAt=$message['sent_at'];
            if ($message['location']!==null) $lastPin=$message;
        }
        if ($lastPin!==null) return $addressAt!==null&&$lastPin['sent_at']>=$addressAt
            &&$this->validPoint($lastPin['location']['lat'],$lastPin['location']['long'])!==null;
        $saved=DB::table('branch_customers')->where('branch',$branch)->where('phone_key',BranchCustomers::phoneKey($data['customer']['phone']))->first();
        return $this->existingLocation($data['customer'],$branch,$actor,$saved,false)!==null;
    }

    private function followupItemQuantities(array $data,array $transcript,string $branch): bool
    {
        $transcript=WhatsAppOrderConversationEvidence::episodeTranscript($transcript);
        if ($data['decision']==='CONFIRMED') return WhatsAppOrderExtraction::validateDetailed($data,$transcript)['ok'];
        if (count(array_filter($data['items'],static fn($item)=>($item['cart_reference']??null)!==null))===0)
            return WhatsAppOrderConversationEvidence::draftItemQuantities($data,$transcript);
        $rows=array_column($transcript,null,'id');
        foreach ($data['items'] as $item) {
            $reference=$item['cart_reference']??null;
            if ($reference!==null) {
                $cart=$rows[$reference['message_id']]['cart']??null; $line=null;
                foreach ($cart['product_items']??[] as $candidate)
                    if ($candidate['product_retailer_id']===$reference['product_retailer_id']) $line=$candidate;
                $maps=$this->orderConfig('whatsapp_cart.product_mappings',[]);
                $mapping=is_array($maps)?($maps[$cart['catalog_id']??''][$reference['product_retailer_id']][$branch]??null):null;
                if ($line===null||!is_array($mapping)||($mapping['quantity_mode']??null)!==$item['quantity_mode']) return false;
                $unit=$this->quantityMillis($mapping['quantity_per_unit']??null); $quantity=$this->quantityMillis($item['quantity']);
                if ($unit===null||$quantity===null||$unit*(int)$line['quantity']!==$quantity) return false;
                continue;
            }
            if (($item['conversation_reference']??null)!==null) {
                if (!WhatsAppOrderConversationEvidence::prove($data,$transcript)['ok']) return false;
                continue;
            }
            $found=false; $amount=['quantity'=>$this->quantityMillis($item['quantity']), 'mode'=>$item['quantity_mode']];
            foreach ($transcript as $message) {
                if ($message['speaker']!=='customer'||!in_array($message['id'],$data['evidence']['request_ids'],true)
                    ||!is_string($message['text'])) continue;
                foreach (WhatsAppOrderConversationEvidence::clauses($message['text']) as $clause)
                    if (strpos($clause,$item['name'])!==false&&in_array($amount,WhatsAppOrderConversationEvidence::quantities($clause),true)) $found=true;
            }
            if (!$found) return false;
        }
        return $data['items']!==[];
    }

    private function followupCatalogReady(array $data,array $transcript,string $branch,$actor): bool
    {
        $transcript=WhatsAppOrderConversationEvidence::episodeTranscript($transcript);
        $catalog=[];
        for ($page=1;$page<=3;$page++) {
            $list=app(TakeawayCatalog::class)->listing(['branch'=>$branch,'page'=>$page,'per_page'=>100],$actor);
            $catalog=array_merge($catalog,$list['items']);
            if ((int)($list['pagination']['last_page']??1)<=$page) break;
            if ($page===3) return false;
        }
        $texts=[];
        foreach ($transcript as $message) if (is_string($message['text'])
            &&(($message['speaker']==='customer'&&in_array($message['id'],$data['evidence']['request_ids'],true))
                ||($message['speaker']==='business'&&in_array($message['id'],$data['evidence']['confirmation_ids'],true)))) $texts[]=$message['text'];
        $used=[];
        foreach ($data['items'] as $item) {
            if (($item['cart_reference']??null)!==null) {
                if ($this->cartItem($item,$data['items'],$texts,$transcript,$catalog,$branch,$used)===null) return false;
                continue;
            }
            $matches=array_values(array_filter($catalog,fn($p)=>($p['available']??false)&&$this->name($p['name'])===$this->name($item['name'])));
            if (count($matches)!==1||!in_array($matches[0]['quantity_mode']??'select',[$item['quantity_mode'],'select'],true)
                ||$this->catalogOption($item,$data['items'],$texts,$matches[0],$data,$transcript)===null) return false;
        }
        return $this->completeCart($transcript,$data,$used);
    }

    /** Insert ids alone cannot exclude late delivery of a pre-activation webhook. */
    private function freshAutomaticEvidence(array $data,array $transcript,?string $notBefore=null,?int $conversation=null): bool
    {
        $activation=$this->activationInstant($conversation);
        if ($activation===null) return false;
        if ($notBefore!==null) $activation=max($activation,$notBefore);
        $rows=array_column($transcript,null,'id');
        $confirmations=$data['evidence']['confirmation_ids']??[];
        if (!is_array($confirmations)||$confirmations===[]) return false;
        foreach ($confirmations as $id) {
            $row=is_string($id)?($rows[$id]??null):null;
            if (!is_array($row)||$row['speaker']!=='business'||!is_string($row['sent_at']??null)||$row['sent_at']<$activation) return false;
        }
        // The pure validator distinguishes a genuine request/cart/acceptance from name-only
        // followups. New contact details cannot turn a late historical order into a new one.
        $requests=WhatsAppOrderExtraction::committingRequestIds($data,$transcript);
        foreach ($requests as $id) {
            $row=is_string($id)?($rows[$id]??null):null;
            if (is_array($row)&&$row['speaker']==='customer'&&is_string($row['sent_at']??null)&&$row['sent_at']>=$activation) return true;
        }
        return false;
    }

    private function activationInstant(?int $conversation=null): ?string
    {
        if ($this->controlInstalled()) {
            $gate=$this->controlGate($conversation);
            return ($gate['effective']??false)===true?($gate['floor']['started_at_utc']??null):null;
        }
        $value=$this->orderConfig('whatsapp_cart.activated_at');
        if (!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$value)) return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        if ($date===false||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0))||$date->format('Y-m-d H:i:s')!==$value||substr($value,0,4)==='0000'||$value>now('UTC')->addSeconds(5)->format('Y-m-d H:i:s')) return null;
        return $value;
    }

    /** Recognized place labels in supplied customer text can select only one authorized branch. */
    private function automaticBranch(array $data,$actor,?string &$routing=null,?string $informationText=null): ?array
    {
        $routing='MISSING';
        $available=[]; $all=app(TakeawayAccess::class)->branches($actor);
        $relationships=[];
        if (Schema::hasTable('resturants')&&Schema::hasColumn('resturants','parent_id')) {
            $ids=array_column(array_filter($all,static fn($branch)=>($branch['kind']??null)==='f'),'id');
            foreach (DB::table('resturants')->whereIn('id',$ids)->get(['id','name','parent_id']) as $row) $relationships[(string)$row->id]=$row;
        }
        foreach ($all as $branch) {
            if (($branch['kind']??null)!=='f') continue;
            // The approved physical branches remain fulfillment destinations even when
            // an older ownership hierarchy links one of them to Shubra.
            if (in_array((string)$branch['id'],self::PHYSICAL_BRANCH_IDS,true)) {
                $available[]=$branch;
                continue;
            }
            // The reviewed Cairo registry is authoritative. Existing inventory has no parent
            // links; do not create links or classify an unknown future child as a Cairo store.
            $row=$relationships[(string)$branch['id']]??null;
            if (FasakhanstaDeliveryOrigin::isConfiguredOrigin($branch['value'])) {
                if ($row&&in_array((int)($row->parent_id??0),[0,301],true)
                    &&FasakhanstaDeliveryOrigin::matchesRegisteredName($branch['value'],$branch['name'])
                    &&FasakhanstaDeliveryOrigin::matchesRegisteredName($branch['value'],(string)$row->name)) {
                    $branch['fulfillment_branch']='f:301'; $available[]=$branch;
                }
                continue;
            }
            if ($row&&(int)($row->parent_id??0)===301) continue;
            if ($this->allowed($branch['value'])) $available[]=$branch;
        }
        $aliases=$this->orderConfig('whatsapp_cart.branch_aliases',[]); $hint=trim((string)($data['branch_hint']??''));
        $area=trim((string)($data['customer']['area']??''));
        $address=trim((string)($data['customer']['address']??'')); $byHint=[]; $labels=[];
        foreach ($available as $branch) {
            $names=[$branch['name']]; $configured=is_array($aliases)?($aliases[(string)$branch['id']]??[]):[];
            if (isset($branch['fulfillment_branch'])) {
                // Approved public store labels may omit only their literal store suffix.
                // Only registered origins receive the existing literal store-suffix alias.
                $label=trim(preg_replace('/\s*(?:ستور|استور|store)\z/iu','',$branch['name']));
                if ($label!==''&&$label!==$branch['name']) $names[]=$label;
            }
            foreach (is_array($configured)?$configured:[] as $alias) if (is_string($alias)&&$alias!=='') $names[]=$alias;
            $names=array_map(fn($name)=>$this->routingName($name),$names);
            $places=[];
            foreach ($names as $name) {
                // Removing this literal branch prefix exposes its existing city/place label.
                $place=trim(preg_replace('/\Aفرع\s+/u','',$name));
                if (mb_strlen($place,'UTF-8')<3||mb_strlen($place,'UTF-8')>200
                    ||!preg_match('/\p{L}/u',$place)
                    ||in_array($place,['فرع','الفرع','branch','store','ستور','استور','محل','المحل',
                        'مدينه','المدينه','فسخانستا','fasakhansta'],true)) continue;
                $places[$place]=true;
                $labels[]=['label'=>$place,'branch'=>$branch];
            }
            if ($hint!==''&&in_array($this->routingName($hint),array_merge($names,array_keys($places)),true))
                $byHint[$branch['value']]=$branch;
        }
        $byArea=$this->placeBranches($area,$labels,true);
        $byAddress=$this->placeBranches($address,$labels,true);
        // A menu question may name its branch. It is routing input for this answer
        // only and never becomes a stored customer address or basket fact.
        $byInquiry=$informationText===null?[]:$this->placeBranches($informationText,$labels,false);
        $byPlace=$byArea+$byAddress+$byInquiry;
        if ($hint!=='') {
            if (count($byHint)!==1) { $routing=count($byHint)>1?'AMBIGUOUS':'UNKNOWN_HINT'; return null; }
            $branch=array_values($byHint)[0];
            // Two explicit places disagreeing cannot silently pick the nearest or first branch.
            if ($byPlace!==[]&&(count($byPlace)!==1||!isset($byPlace[$branch['value']]))) { $routing='CONFLICTING'; return null; }
            $routing=$this->allowed($branch['fulfillment_branch']??$branch['value'])?'RESOLVED':'DENIED';
            return $routing==='RESOLVED'?$branch:null;
        }
        if (count($byPlace)!==1) { $routing=count($byPlace)>1?'AMBIGUOUS':'MISSING'; return null; }
        $branch=array_values($byPlace)[0];
        // Permission filtering must not erase a conflicting known place from customer text.
        $routing=$this->allowed($branch['fulfillment_branch']??$branch['value'])?'RESOLVED':'DENIED';
        return $routing==='RESOLVED'?$branch:null;
    }

    /** Comparison only: never rewrite customer facts or consult an inferred/nearest location. */
    private function routingName(string $value): string
    {
        $value=strtr($value,['أ'=>'ا','إ'=>'ا','آ'=>'ا','ٱ'=>'ا','ى'=>'ي','ة'=>'ه']);
        $value=preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u','',$value);
        return $this->name($value);
    }

    /** Keep the longest approved label for each overlapping occurrence; distinct places conflict. */
    private function placeBranches(string $text,array $labels,bool $address): array
    {
        if ($text==='') return [];
        $text=$this->routingName($text); $hits=[];
        foreach ($labels as $entry) {
            $label=$entry['label'];
            $quoted=preg_quote($label,'/'); $place=$quoted;
            if (preg_match('/\p{Arabic}/u',$label)) {
                // Lam contracts the initial alif only in a definite Arabic place label.
                $place=mb_substr($label,0,2,'UTF-8')==='ال'
                    ? '(?:(?:[بف])?'.$quoted.'|ل'.preg_quote(mb_substr($label,1,null,'UTF-8'),'/').')'
                    : '(?:[بلف])?'.$quoted;
            }
            $pattern='/(?<![\p{L}\p{N}])'.$place.'(?![\p{L}\p{N}])/u';
            preg_match_all($pattern,$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $start=$match[0][1]; $end=$start+strlen($match[0][0]);
                $before=substr($text,0,$start); $after=substr($text,$end);
                if (preg_match('/(?<![\p{L}\p{N}])(?:مش|لا|ليس|غير)\s+(?:(?:في|فى|من|داخل)\s+)?\z/u',$before)) continue;
                // A street called Mansoura is not by itself a supplied Mansoura destination.
                if ($address&&preg_match('/(?<![\p{L}\p{N}])(?:شارع|طريق|ش\.?|street|road)\s*[:：\-]?\s*\z/iu',$before)) continue;
                // A different compound place is not reduced to an unregistered shorter city.
                if (preg_match('/\A\s+(?:الجديده|الجديد|جديده|جديد)(?![\p{L}\p{N}])/u',$after)) continue;
                $hits[]=['start'=>$start,'end'=>$end,'branch'=>$entry['branch']];
            }
        }
        $found=[];
        foreach ($hits as $hit) {
            $contained=false;
            foreach ($hits as $other) {
                if ($other['start']<=$hit['start']&&$other['end']>=$hit['end']
                    &&($other['start']<$hit['start']||$other['end']>$hit['end'])) { $contained=true; break; }
            }
            if (!$contained) $found[$hit['branch']['value']]=$hit['branch'];
        }
        return $found;
    }

    /** Reuse existing phone-order lookup. New addresses still require a real pin. */
    private function existingLocation(array $customer,string $branch,$actor,?object $saved,bool $lock): ?array
    {
        $points=[];
        if ($saved&&BranchCustomers::phoneKey($saved->phone)===BranchCustomers::phoneKey($customer['phone'])
            &&$this->name($saved->address)===$this->name($customer['address'])&&$this->sourceAreaAgrees($saved->area??null,$customer['area']??null)) {
            $point=$this->validPoint($saved->latitude,$saved->longitude);
            if ($point!==null) $points[PosServiceTicket::fingerprint($point)]=['location'=>$point,'area'=>trim((string)($saved->area??'')),'notes'=>trim((string)($saved->delivery_notes??''))];
        }
        $lookup=app(PosServicePhone::class)->customers(['branch'=>$branch,'phone'=>$customer['phone'],'prefix'=>false],$actor);
        foreach ($lookup['items']??[] as $candidate) {
            if (!is_array($candidate)||BranchCustomers::phoneKey((string)($candidate['phone']??''))!==BranchCustomers::phoneKey($customer['phone'])
                ||$this->name((string)($candidate['address']??''))!==$this->name($customer['address'])) continue;
            $point=$this->validPoint($candidate['latitude']??null,$candidate['longitude']??null);
            if ($point===null) continue;
            // Local saved records are independently read/locked above. History needs a stable
            // phone ticket source; app/search suggestions with no source identity need review.
            if (isset($candidate['last_ticket_id'])&&Schema::hasTable('pos_service_tickets')) {
                $query=DB::table('pos_service_tickets')->where('id',(int)$candidate['last_ticket_id'])->where('channel','phone');
                if ($lock) $query->lockForUpdate(); $ticket=$query->first();
                if (!$ticket||$ticket->status==='cancelled'||($ticket->payment_status==='unpaid'&&empty($ticket->bill_issued_at)&&$ticket->status!=='awaiting_bill')
                    ||BranchCustomers::phoneKey($ticket->customer_phone)!==BranchCustomers::phoneKey($customer['phone'])
                    ||$this->name($ticket->address)!==$this->name($customer['address'])||!$this->sourceAreaAgrees($ticket->area??null,$customer['area']??null)) continue;
                app(TakeawayAccess::class)->branch($ticket->branch,$actor,$lock);
                $snapshot=json_decode((string)$ticket->delivery_snapshot,true);
                $original=is_array($snapshot)?$this->validPoint($snapshot['latitude']??null,$snapshot['longitude']??null):null;
                if ($original!==$point) return null;
                $pointKey=PosServiceTicket::fingerprint($point);
                if (!isset($points[$pointKey])) $points[$pointKey]=['location'=>$point,'area'=>trim((string)($ticket->area??'')),'notes'=>trim((string)($ticket->delivery_notes??''))];
            } elseif ($saved&&isset($candidate['customer_id'])&&(int)$candidate['customer_id']===(int)$saved->id) {
                $original=$this->validPoint($saved->latitude,$saved->longitude);
                if ($original!==$point) return null;
            }
        }
        if (count($points)!==1) return null; $source=array_values($points)[0];
        return $source['location']+['source_area'=>$source['area'],'source_notes'=>$source['notes']];
    }
    private function sourceAreaAgrees($source,$explicit): bool
    {
        return $explicit===null||trim((string)$explicit)===''||$this->name((string)$source)===$this->name((string)$explicit);
    }
    private function validPoint($latitude,$longitude): ?array
    {
        if (!is_numeric($latitude)||!is_numeric($longitude)||!is_finite((float)$latitude)||!is_finite((float)$longitude)
            ||abs((float)$latitude)>90||abs((float)$longitude)>180||((float)$latitude===0.0&&(float)$longitude===0.0)) return null;
        return ['lat'=>round((float)$latitude,7),'long'=>round((float)$longitude,7)];
    }

    /** Retailer ids are scoped external ids, never ERP ids or inferred sale quantities. */
    private function cartItem(array $item,array $allItems,array $summaryTexts,array $transcript,array $catalog,string $branch,array &$used): ?array
    {
        $reference=$item['cart_reference']??null;
        if (!is_array($reference)||!is_string($reference['message_id']??null)||!is_string($reference['product_retailer_id']??null)) return null;
        $message=null;
        foreach ($transcript as $m) if ($m['id']===$reference['message_id']&&$m['speaker']==='customer') $message=$m;
        $cart=$message['cart']??null;
        if (!is_array($cart)||($cart['currency']??null)!=='EGP') return null;
        $line=null;
        foreach ($cart['product_items']??[] as $candidate) if ($candidate['product_retailer_id']===$reference['product_retailer_id']) $line=$candidate;
        if ($line===null) return null;
        $key=$reference['message_id'].':'.$reference['product_retailer_id'];
        if (isset($used[$key])) return null;
        $maps=$this->orderConfig('whatsapp_cart.product_mappings',[]);
        $mapping=is_array($maps) ? ($maps[$cart['catalog_id']][$line['product_retailer_id']][$branch]??null) : null;
        if (!is_array($mapping)||!preg_match('/\A[1-9][0-9]{0,18}\z/',(string)($mapping['product_id']??''))
            ||!in_array($mapping['quantity_mode']??null,['piece','weight'],true)) return null;
        $unit=$this->quantityMillis($mapping['quantity_per_unit']??null);
        $requested=$this->quantityMillis($item['quantity']??null);
        if ($unit===null||$requested===null||$unit*(int)$line['quantity']!==$requested||$requested>9999999
            ||($mapping['quantity_mode']==='piece'&&$requested%1000!==0)||$mapping['quantity_mode']!==($item['quantity_mode']??null)) return null;
        $matches=array_values(array_filter($catalog,fn($p)=>(string)$p['id']===(string)$mapping['product_id']));
        if (count($matches)!==1) return null; $product=$matches[0];
        if (!($product['available']??false)||$this->name($product['name'])!==$this->name($item['name'])
            ||!in_array($product['quantity_mode']??'select',[$mapping['quantity_mode'],'select'],true)) return null;
        $option=$mapping['option_id']??'';
        if (!is_string($option)||strlen($option)>80) return null;
        $proven=$this->catalogOption($item,$allItems,$summaryTexts,$product);
        if ($proven===null||$proven!==$option) return null;
        $used[$key]=true;
        return ['product_id'=>(int)$product['id'],'quantity'=>$item['quantity'],'quantity_mode'=>$mapping['quantity_mode'],'option_id'=>$option];
    }

    /** A base/default choice cannot omit a literal live sale option in the final item clause. */
    private function catalogOption(array $item,array $allItems,array $summaryTexts,array $product,
        ?array $conversation=null,array $transcript=[]): ?string
    {
        $options=$product['options']??[]; if (!is_array($options)||count($options)>100||!is_string($product['name']??null)) return null;
        $labels=[];
        foreach ($options as $option) {
            if (!is_array($option)||!is_string($option['id']??null)||!is_string($option['label']??null)||$option['label']==='') return null;
            // An explicit weight amount already represents half/quarter kilograms; packaged
            // or meal variants still need their actual catalog identity and option mapping.
            $portion=$this->name($option['label']); $quantity=$this->quantityMillis($item['quantity']??null);
            if (($item['quantity_mode']??null)==='weight'&&!preg_match('/(?:وجبة|وجبات|علبة|عبوة|ساندوتش|سندوتش|ميكس|\b(?:meal|pack|package|box|sandwich)\b)/iu',$product['name'])&&(($portion==='نصف'&&$quantity===500)||($portion==='ربع'&&$quantity===250))) continue;
            $labels[]=$option['label'];
        }
        $pending=($conversation['decision']??null)==='DRAFT'; $contextual=($item['conversation_reference']??null)!==null;
        if (!$contextual&&$pending) {
            $sources=WhatsAppOrderConversationEvidence::draftItemQuantitySources($conversation,$transcript);
            $index=array_search($item,$conversation['items']??[],true);
            $contextual=$sources!==null&&$index!==false&&is_array($sources[$index]??null);
        }
        if ($contextual&&$conversation===null) return null;
        $contextRows=array_column($transcript,null,'id');
        $sizeProof=static fn(array $choices,bool $preparation=true)=>$contextual
            ? WhatsAppOrderConversationEvidence::contextualOptionLabels($item,$conversation,$contextRows,$choices,$pending,$preparation)
            : WhatsAppOrderExtraction::fishSizeCatalogLabels($item,$allItems,$summaryTexts,$choices,$preparation);
        $embedded=false;
        if (WhatsAppOrderConversationEvidence::fishSizes($product['name'])) {
            $embeddedProof=$sizeProof([$product['name']],false);
            if ($embeddedProof!==[$product['name']]) return null;
            $embedded=true;
        }
        $proof=$contextual
            ? WhatsAppOrderConversationEvidence::contextualOptionLabels($item,$conversation,$contextRows,$labels,$pending,true,!$embedded)
            : WhatsAppOrderExtraction::optionClauseLabels($item,$allItems,$summaryTexts,$labels);
        if ($proof===null) return null;
        $covers=function(string $label,string $native) use($sizeProof):bool {
            $liveName=$this->name($label); $nativeName=$this->name($native);
            // A cited Arabic conjunction/preposition may remain in the literal
            // hint. The independently proved live option stays the ERP identity.
            if ($liveName===$nativeName||$nativeName==='و'.$liveName||$nativeName==='ب'.$liveName
                ||preg_match('/(?<![\p{L}\p{N}])'.preg_quote($native,'/').'(?![\p{L}\p{N}])/iu',$label)) return true;
            return $sizeProof([$label],false)===[$label]&&$sizeProof([$native],false)===[$native];
        };
        if (!$embedded) {
            $sizes=$sizeProof($labels);
            if ($sizes===null) return null;
            if ($sizes!==[]) {
                if (count($sizes)!==1) return null;
                foreach ($proof as $choice) if (!$covers($sizes[0],$choice)) return null;
                $proof=$sizes;
            }
        }
        $hint=$item['option_hint']??null;
        if ($hint!==null&&(!is_string($hint)||trim($hint)==='')) return null;
        if ($hint!==null) {
            if (count($proof)===1) { if (!$covers($proof[0],$hint)) return null; }
            elseif ($proof!==[]||!$embedded||!$covers($product['name'],$hint)) return null;
        } elseif (count($proof)>1) return null;
        if ($proof===[]) return $hint===null||$embedded?'':null;
        $matches=array_values(array_filter($options,fn($option)=>$this->name($option['label'])===$this->name($proof[0])));
        return count($matches)===1?$matches[0]['id']:null;
    }

    /** A submitted cart is one request: no partial lines or an earlier abandoned cart. */
    private function completeCart(array $transcript,array $data,array $used): bool
    {
        $latest=null;
        foreach ($transcript as $m) if ($m['speaker']==='customer'&&is_array($m['cart']??null)) $latest=$m;
        if ($latest===null) return $used===[];
        if (!in_array($latest['id'],$data['evidence']['request_ids']??[],true)) return false;
        $expected=[];
        foreach ($latest['cart']['product_items']??[] as $line) $expected[$latest['id'].':'.$line['product_retailer_id']]=true;
        ksort($expected); ksort($used);
        return $expected!==[]&&$expected===$used;
    }

    private function quantityMillis($value): ?int
    {
        if (!is_string($value)||!preg_match('/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?\z/',$value)) return null;
        [$whole,$fraction]=array_pad(explode('.',$value),2,'');
        $millis=(int)$whole*1000+(int)str_pad($fraction,3,'0');
        return $millis>0?$millis:null;
    }

    private function branchOpen(array $branch, ?string &$reason,bool $lock=false): bool
    {
        // Use an actual deployed policy only. No guessed restaurant columns or opening hours.
        if (class_exists('App\\Models\\Resturant')) {
            $query=\App\Models\Resturant::withoutGlobalScopes();
            if ($lock) $query->lockForUpdate();
            $model=$query->find((int)$branch['id']);
            if ($model && method_exists($model,'isWithinBusinessHours') && method_exists($model,'getEffectiveStatusAttribute')) {
                if ($model->isWithinBusinessHours() && $model->effective_status==='opened') return true;
                $reason='BRANCH_CLOSED'; return false;
            }
        }
        $reason='BRANCH_POLICY_UNAVAILABLE'; return false;
    }

    protected function snapshot(int $conversation, int $floor, int $ceiling,bool $lock=false): array
    {
        $thread=$this->conversation($conversation,$lock);
        $query=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)->where('id','>',$floor)->where('id','<=',$ceiling)
            ->orderByDesc('id')->limit(self::MAX_MESSAGES+1);
        if ($lock) $query->lockForUpdate(); $rows=$query->get()->all();
        $complete=count($rows)<=self::MAX_MESSAGES; $rows=array_slice($rows,0,self::MAX_MESSAGES);
        usort($rows,fn($a,$b)=>strcmp((string)$a->sent_at,(string)$b->sent_at)?:((int)$a->id<=>(int)$b->id));
        $transcript=[]; $keys=[]; $hash=[]; $chars=0; $recoveredAt=null;
        foreach ($rows as $i=>$row) {
            $cart=null; $location=null; $hydration=null;
            if ($row->source==='dashboard_api') {
                // This is an authenticated send receipt, never a fabricated native webhook.
                $receipt=app(WhatsAppAcceptedReplyEvidence::class)->unwrap($thread,$row,$lock);
                $messageId=$receipt['native_id']??null; $identity=$receipt['peer_identity']??null;
                $text=$receipt['text']??null;
                abort_unless($row->direction==='outbound' && $row->type==='text' && is_string($text)
                    && ($receipt['sent_at']??null)===$row->sent_at,500,'INVALID_INBOX_MESSAGE');
            } else {
                $dto=$this->decode($row->content); abort_unless(is_array($dto),500,'INVALID_INBOX_MESSAGE');
                $messageId=$dto['message_id']??null; $identity=$dto['peer_identity']??null;
                abort_unless(($dto['direction']??null)===$row->direction&&($dto['type']??null)===$row->type
                    &&($dto['sent_at']??null)===$row->sent_at&&is_array($dto['content']['message']??null),500,'INVALID_INBOX_MESSAGE');
                $text=is_string($dto['text']??null)?$dto['text']:null;
                if ($this->controlInstalled()) {
                    $native=(new WhatsAppInboxMessage())->projected($thread,$row);
                    $text=$native['text'];
                }
                $hydration=$this->nativeHydration($dto,$thread,$row);
                if ($hydration!==null) $recoveredAt=$recoveredAt===null?$hydration['at_utc']:max($recoveredAt,$hydration['at_utc']);
                if ($row->direction==='outbound' && $row->type==='text'
                    && $this->table('whatsapp_inbox_messages')==='whatsapp_inbox_messages' && Schema::hasTable('whatsapp_reply_projection_keys')) {
                    app(WhatsAppAcceptedReplyEvidence::class)->validateNativeReceipt($thread,$row,$lock);
                }
                $cart=$row->type==='order'&&$row->direction==='inbound' ? WhatsAppInboxProtocol::cart($dto['content']['message']['order']??null) : null;
                if ($row->type==='location' && isset($dto['content']['message']['location']['latitude'],$dto['content']['message']['location']['longitude'])) {
                    $l=$dto['content']['message']['location']; $location=['lat'=>(float)$l['latitude'],'long'=>(float)$l['longitude']];
                    if (abs($location['lat'])>90||abs($location['long'])>180) $location=null;
                }
            }
            abort_unless(is_string($messageId)&&$messageId!==''&&is_string($identity)&&$identity!==''
                &&hash_equals($row->message_key,hash('sha256','whatsapp-message-v1:'.$this->scopeKey($thread).$messageId))
                &&hash_equals($thread->peer_hash,hash_hmac('sha256','whatsapp-peer-v1:'.$this->scopeKey($thread).$identity,Crypt::getKey())),500,'INVALID_INBOX_MESSAGE');
            $speaker=$row->direction==='inbound'?'customer':($row->direction==='outbound'?'business':null);
            abort_unless($speaker!==null && is_string($row->sent_at) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',$row->sent_at),500,'INVALID_INBOX_MESSAGE');
            $chars+=$text===null?0:mb_strlen($text,'UTF-8');
            if ($row->type==='order' && $cart===null) $complete=false;
            $id='m'.($i+1); $item=['id'=>$id,'speaker'=>$speaker,'sent_at'=>$row->sent_at,'text'=>$text,'location'=>$location];
            if ($cart!==null) { $item['cart']=$cart; $chars+=mb_strlen(json_encode($cart,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'UTF-8'); }
            $transcript[]=$item;
            $keys[$id]=$row->message_key; $identity=[(int)$row->id,$row->message_key,$row->direction,$row->type,$row->sent_at,$text,$location];
            if ($cart!==null) $identity[]=$cart;
            if ($hydration!==null) $identity[]=['hydration'=>$hydration];
            if ($this->controlInstalled()&&$row->source!=='dashboard_api') $identity[]=['native_content_hash'=>hash('sha256',$row->content)];
            $hash[]=$identity;
        }
        if ($chars>self::MAX_CHARS) $complete=false;
        return ['transcript'=>$transcript,'keys'=>$keys,'hash'=>hash_hmac('sha256',PosServiceTicket::fingerprint($hash),Crypt::getKey()),'complete'=>$complete,'recovered_at'=>$recoveredAt];
    }

    /** Recovery provenance stays encrypted and is bound into the same semantic snapshot. */
    private function nativeHydration(array $dto,object $thread,object $row): ?array
    {
        if (!array_key_exists('hydration',$dto['content']??[])) return null;
        $h=$dto['content']['hydration'];
        abort_unless(is_array($h) && count($h)===8 && ($h['version']??null)==='whatsapp-native-placeholder-hydration-v1'
            && ($h['code']??null)===131060 && is_int($h['event_id']??null) && $h['event_id']>0
            && is_array($h['before']??null) && is_string($h['at_utc']??null)
            && is_string($h['before_ciphertext']??null) && strlen($h['before_ciphertext'])<=1048576
            && is_string($h['before_ciphertext_sha256']??null) && preg_match('/\A[a-f0-9]{64}\z/',$h['before_ciphertext_sha256'])
            && is_string($h['raw_payload_hash']??null) && preg_match('/\A[a-f0-9]{64}\z/',$h['raw_payload_hash']),500,'INVALID_NATIVE_HYDRATION');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$h['at_utc'],new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        abort_unless($date!==false && ($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0))
            && $date->format('Y-m-d H:i:s')===$h['at_utc'] && $h['at_utc']<=now('UTC')->addSeconds(5)->format('Y-m-d H:i:s'),500,'INVALID_NATIVE_HYDRATION');
        $before=$h['before']; $plain=$this->decode($h['before_ciphertext']);
        abort_unless(hash_equals($h['before_ciphertext_sha256'],hash('sha256',$h['before_ciphertext']))
            && is_array($plain) && PosServiceTicket::fingerprint($plain)===PosServiceTicket::fingerprint($before)
            && ($dto['direction']??null)==='inbound' && ($dto['type']??null)==='text' && is_string($dto['text']??null)
            && ($before['direction']??null)==='inbound' && ($before['type']??null)==='unsupported' && ($before['text']??null)===null
            && ($before['message_id']??null)===($dto['message_id']??null) && ($before['peer_identity']??null)===($dto['peer_identity']??null)
            && ($before['peer_phone']??null)===($dto['peer_phone']??null) && ($before['peer_user_id']??null)===($dto['peer_user_id']??null)
            && ($before['source']??null)===($dto['source']??null) && ($before['content']['message']['type']??null)==='unsupported',500,'INVALID_NATIVE_HYDRATION');
        $nativeErrors=$before['content']['message']['errors']??null;
        abort_unless(is_array($nativeErrors) && count($nativeErrors)===1
            && in_array($nativeErrors[0]['code']??null,[131060,'131060'],true),500,'INVALID_NATIVE_HYDRATION');
        $raw=DB::table($this->table('whatsapp_webhook_events'))->where('id',$h['event_id'])->first(['id','payload_hash','payload','processed_at']);
        $quarantined=DB::table($this->table('whatsapp_inbox_ingestion_failures'))->where('event_id',$h['event_id'])->exists();
        abort_unless($raw && WhatsAppNativePlaceholderHydration::prove($thread,$row,$dto,$raw,$quarantined),500,'INVALID_NATIVE_HYDRATION');
        return ['version'=>$h['version'],'code'=>$h['code'],'event_id'=>$h['event_id'],'at_utc'=>$h['at_utc'],
            'before_hash'=>PosServiceTicket::fingerprint($before),'before_ciphertext_sha256'=>$h['before_ciphertext_sha256'],
            'raw_payload_hash'=>$h['raw_payload_hash']];
    }

    private function confirmationKey(object $row): string
    {
        $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        $data=$this->decode($row->extraction); $ids=$data['evidence']['confirmation_ids']??[]; $anchor=null;
        if (isset($data['type'])&&$data['type']===WhatsAppCustomerOrderConfirmation::TYPE) {
            $proved=$this->customerReceiptEnabled((int)$row->conversation_id)&&($snapshot['complete']??false)===true
                ?WhatsAppCustomerOrderConfirmation::inspect($snapshot['transcript']):null;
            abort_unless(($proved['ok']??false)===true
                &&hash_equals(PosServiceTicket::fingerprint($proved['data']),PosServiceTicket::fingerprint($data)),422,'CUSTOMER_CONFIRMATION_REQUIRED');
            $receipt=$data['evidence_receipt'];
            $recap=$snapshot['keys'][$receipt['recap_id']]??null; $approval=$snapshot['keys'][$receipt['approval_id']]??null;
            abort_unless(is_string($recap)&&is_string($approval),422,'CUSTOMER_CONFIRMATION_REQUIRED');
            return hash('sha256','whatsapp-customer-confirmation-v1:'.$this->scopeKey($this->conversation((int)$row->conversation_id))
                .':'.$recap.':'.$approval);
        }
        foreach (WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']) as $m) if ($m['speaker']==='business' && (!$ids || in_array($m['id'],$ids,true))) $anchor=$snapshot['keys'][$m['id']];
        abort_unless(is_string($anchor),422,'BUSINESS_CONFIRMATION_REQUIRED');
        return hash('sha256','whatsapp-order-confirmation-v1:'.$this->scopeKey($this->conversation((int)$row->conversation_id)).$anchor);
    }

    private function current(object $row,bool $lock=false): void
    {
        $this->conversation((int)$row->conversation_id,$lock);
        abort_unless($this->projectAcceptedReplies((int)$row->conversation_id,$this->mode()==='auto'?$this->activationInstant((int)$row->conversation_id):null),409,'ACCEPTED_REPLIES_PENDING');
        abort_unless($this->ceiling((int)$row->conversation_id,$lock)===(int)$row->evidence_ceiling,409,'STALE_TRANSCRIPT');
        abort_unless($this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling,$lock)['hash']===$row->evidence_hash,409,'STALE_TRANSCRIPT');
    }
    private function mutable(object $row,array $v): void
    {
        abort_unless((int)$row->revision===$v['expected_revision'],409,'DRAFT_REVISION_CHANGED');
        abort_if(in_array($row->status,['DISPATCHED','NONE','CANCELLED'],true),409,'DRAFT_NOT_DISPATCHABLE');
    }
    private function actor($actor)
    {
        $fresh=$this->inboxAccess()->actor($actor);
        abort_unless(app(TakeawayAccess::class)->permissions($fresh)['can_checkout'],403,'ORDER_ACCESS_DENIED');
        return $fresh;
    }
    private function automaticActor(?int $conversation=null)
    {
        if ($this->controlInstalled()) {
            $gate=$this->controlGate($conversation);
            abort_unless(($gate['effective']??false)===true&&is_object($gate['actor']??null),403,'AI_CONTROL_BLOCKED');
            return $this->actor($gate['actor']);
        }
        $id=$this->orderConfig('whatsapp_orders.automation_actor_id');
        abort_unless((bool)$this->orderConfig('whatsapp_orders.enabled',false)&&is_numeric($id)&&(int)$id>0,403,'AUTO_NOT_CONFIGURED');
        abort_unless(is_string($this->orderConfig('whatsapp_orders.model'))&&trim($this->orderConfig('whatsapp_orders.model'))!==''&&is_string($this->orderConfig('whatsapp_orders.api_key'))&&$this->orderConfig('whatsapp_orders.api_key')!=='',403,'AUTO_NOT_CONFIGURED');
        $actor=User::withoutGlobalScopes()->find((int)$id); return $this->actor($actor);
    }
    private function branch(string $branch,$actor,bool $lock=false): array
    {
        $b=app(TakeawayAccess::class)->branch($branch,$actor,$lock); abort_unless($b['kind']==='f',403,'INVALID_BRANCH'); return $b;
    }
    private function deliveryOrigin(array $review,$actor,bool $lock=false): void
    {
        if (!isset($review['delivery_origin_branch'])) return;
        app(FasakhanstaDeliveryOrigin::class)->check($review['delivery_origin_branch'],$review['branch'],$actor,$lock);
    }
    private function storedDeliveryOrigin(object $row,array $review): array
    {
        if (array_key_exists('delivery_origin_branch',$review)) return $review;
        $sealed=$this->decode($row->sealed_payload); $stored=$sealed['review']??null;
        if (is_array($stored)&&($stored['branch']??null)===($review['branch']??null)&&isset($stored['delivery_origin_branch'])) {
            $review['delivery_origin_branch']=$stored['delivery_origin_branch'];
        }
        return $review;
    }
    private function allowed(string $branch): bool
    {
        $ids=$this->orderConfig('whatsapp_orders.allowed_branch_ids',[]);
        return is_array($ids)&&in_array(substr($branch,2),array_map('strval',$ids),true);
    }
    private function sameCustomer(object $row,array $v): bool
    {
        return BranchCustomers::phoneKey($row->phone)===BranchCustomers::phoneKey($v['customer_phone'])
            && trim($row->name)===$v['customer_name']&&trim($row->address)===$v['address'];
    }
    private function sameCustomerValues(object $row,array $v): bool
    {
        if (!$this->sameCustomer($row,$v)) return false;
        foreach (['area'=>'area','delivery_notes'=>'delivery_notes'] as $column=>$field) {
            if (trim((string)($row->$column??''))!==$v[$field]) return false;
        }
        return is_numeric($row->latitude)&&is_numeric($row->longitude)
            && round((float)$row->latitude,7)===$v['latitude']&&round((float)$row->longitude,7)===$v['longitude'];
    }
    private function name(string $value): string { return mb_strtolower(trim(preg_replace('/\s+/u',' ',$value)),'UTF-8'); }
    private function reviewHash(array $v,bool $hashes=true): string
    {
        unset($v['expected_revision']); if (!$hashes) unset($v['quote_hash'],$v['delivery_quote_hash']); return PosServiceTicket::fingerprint($v);
    }
    private function debounceSeconds(): int
    {
        $seconds=$this->orderConfig('whatsapp_cart.debounce_seconds',10);
        if (!is_int($seconds)&&!(is_string($seconds)&&preg_match('/\A[0-9]{1,3}\z/',$seconds))) $seconds=10;
        return max(0,min(120,(int)$seconds));
    }
    private function settledBefore(): string { return now('UTC')->subSeconds($this->debounceSeconds())->format('Y-m-d H:i:s'); }
    private function deferDispatch(int $conversation): void
    {
        // Only an idle scan is ours to defer. Another analysis holder keeps its complete lease.
        DB::table(static::SCANS)->where('conversation_id',$conversation)->whereNull('claim_nonce')
            ->update(['next_attempt_at'=>now('UTC')->addSeconds(60),'updated_at'=>now('UTC')]);
    }

    private function activationBoundary(string $name,?int $conversation=null): ?int
    {
        if ($this->controlInstalled()) {
            $gate=$this->controlGate($conversation);
            return ($gate['effective']??false)===true ? ($gate['floor'][$name==='activation_message_id'?'message_floor':'event_floor']??null) : null;
        }
        $value=$this->orderConfig('whatsapp_orders.'.$name);
        if (!is_int($value) && !is_string($value)) return null;
        $digits=(string)$value; $maximum=(string)PHP_INT_MAX;
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/',$digits)
            || strlen($digits)>strlen($maximum)
            || (strlen($digits)===strlen($maximum) && strcmp($digits,$maximum)>0)) return null;
        return (int)$digits;
    }
    private function automaticFloor(int $conversation,?object $scan=null,bool $lock=false): int
    {
        // Publishing a new cutoff still requires quiescing workers that loaded older configuration.
        $activation=$this->activationBoundary('activation_message_id',$conversation);
        abort_unless($activation!==null,403,'AUTO_NOT_CONFIGURED');
        if ($scan===null) {
            $query=DB::table(static::SCANS)->where('conversation_id',$conversation);
            if ($lock) $query->lockForUpdate();
            $scan=$query->first();
        }
        // Current owner activation explicitly authorizes the bounded unanswered backlog.
        // A legacy larger scan watermark is not another activation and cannot hide it.
        return max($activation,$this->controlInstalled()?0:(int)($scan->auto_floor??0),$this->dispatchedCeiling($conversation,$lock));
    }
    private function dispatchedCeiling(int $conversation,bool $lock=false): int
    {
        $query=DB::table(static::DRAFTS)->where('conversation_id',$conversation)->where('status','DISPATCHED');
        if (!$lock) return (int)$query->max('evidence_ceiling');
        $row=$query->orderByDesc('evidence_ceiling')->lockForUpdate()->first(['evidence_ceiling']);
        return (int)($row->evidence_ceiling??0);
    }
    private function scanInsert(int $conversation): void
    {
        DB::table(static::SCANS)->insertOrIgnore(['conversation_id'=>$conversation,'analyzed_ceiling'=>0,'claimed_ceiling'=>0,'attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    private function ceiling(int $conversation,bool $lock=false): int
    {
        if (!$lock) return (int)DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)->max('id');
        $row=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)->orderByDesc('id')->lockForUpdate()->first(['id']);
        return (int)($row->id??0);
    }
    private function conversation(int $id,bool $lock=false): object
    {
        $query=$this->scope(DB::table($this->table('whatsapp_inbox_conversations'))->where('id',$id));
        if ($lock) $query->lockForUpdate(); $row=$query->first();
        abort_unless($row,404,'CONVERSATION_NOT_FOUND'); return $row;
    }
    private function draft(int $id,bool $lock=false): object
    {
        $q=DB::table(static::DRAFTS)->where('id',$id); if ($lock) $q->lockForUpdate(); $row=$q->first();
        abort_unless($row,404,'DRAFT_NOT_FOUND'); $this->conversation((int)$row->conversation_id); return $row;
    }
    private function present(object $row): array
    {
        $sealed=$this->decode($row->sealed_payload); $data=$this->decode($row->extraction);
        $failure=is_array($data['analysis_failure']??null)?$this->safeAnalysisFailure($data['analysis_failure']):null;
        return ['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'revision'=>(int)$row->revision,
            'status'=>$row->status,'reason'=>$row->reason,'data'=>$failure===null?$data:null,'analysis_failure'=>$failure,'review'=>$sealed['review']??null,
            'quote'=>$sealed['quote']??null,'delivery'=>$sealed['delivery']??null,'ticket_id'=>$row->ticket_id===null?null:(int)$row->ticket_id,
            'evidence_ceiling'=>(int)$row->evidence_ceiling];
    }
    private function reviewReason(object $row,string $reason): void
    {
        abort_unless(in_array($reason,self::REASONS,true),500,'INVALID_REASON');
        DB::table(static::DRAFTS)->where('id',$row->id)->whereNotIn('status',['DISPATCHED','NONE','CANCELLED'])->update(['status'=>'REVIEW','reason'=>$reason,'updated_at'=>now('UTC')]);
    }
    private function uuid(): string
    {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40); $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
    private function pendingCapture(bool $lock=false): bool
    {
        if (!Schema::hasTable($this->table('whatsapp_webhook_events'))) return true;
        $query=DB::table($this->table('whatsapp_webhook_events').' as e')->whereNull('e.processed_at')->whereNotExists(function ($q) {
            $q->select(DB::raw('1'))->from($this->table('whatsapp_inbox_ingestion_failures').' as f')->whereColumn('f.event_id','e.id');
        });
        if ($lock) $query->lockForUpdate(); return $query->first(['e.id'])!==null;
    }
    protected function projectAcceptedReplies(?int $conversation,?string $acceptedSince): bool
    {
        // Meta channels have their own storage and accepted-send evidence contracts.
        if ($this->table('whatsapp_inbox_messages')!=='whatsapp_inbox_messages'
            || !Schema::hasTable('whatsapp_reply_requests')) return true;
        try {
            $result=app(WhatsAppAcceptedReplyEvidence::class)->projectPending(100,$conversation,$acceptedSince);
            return ($result['complete']??false)===true && ($result['invalid']??null)===0 && ($result['remaining']??null)===0;
        } catch (\Throwable $error) { return false; }
    }
    /** Store and expose only fixed categories and bounded numbers, never provider error prose. */
    private function safeAnalysisFailure($result): array
    {
        $result=is_array($result)?$result:[];
        $reason=in_array($result['reason']??null,['CONTEXT_INCOMPLETE','DISABLED','NOT_CONFIGURED','INVALID_CONFIGURATION',
            'INVALID_TRANSCRIPT','INVALID_INPUT','INPUT_TOO_LARGE','TRANSPORT_ERROR','RESPONSE_TOO_LARGE','HTTP_ERROR',
            'INVALID_RESPONSE','INCOMPLETE','REFUSED','INVALID_EXTRACTION','INVALID_EVIDENCE'],true)?$result['reason']:'UNKNOWN';
        $raw=is_array($result['diagnostic']??null)?$result['diagnostic']:[]; $diagnostic=[];
        $enums=['stage'=>['CONFIGURATION','INPUT','REQUEST','TRANSPORT','RESPONSE','EXTRACTION'],
            'transport_category'=>['NONE','TIMEOUT','DNS','CONNECT','TLS','RESPONSE_LIMIT','OTHER','UNAVAILABLE','UNKNOWN'],
            'response_status'=>['completed','incomplete','failed','cancelled','queued','in_progress','UNKNOWN'],
            'incomplete_reason'=>['max_output_tokens','content_filter','UNKNOWN'],
            'validation_category'=>WhatsAppOrderExtraction::VALIDATION_CATEGORIES,
            'validation_field'=>WhatsAppOrderExtraction::VALIDATION_FIELDS];
        foreach ($enums as $field=>$values) $diagnostic[$field]=in_array($raw[$field]??null,$values,true)?$raw[$field]:null;
        foreach (['http_status'=>599,'curl_errno'=>999,'elapsed_ms'=>3600000,'input_tokens'=>1000000000,
            'output_tokens'=>1000000000,'reasoning_tokens'=>1000000000] as $field=>$maximum) {
            $value=$raw[$field]??null; $diagnostic[$field]=is_int($value)&&$value>=0&&$value<=$maximum?$value:null;
        }
        return ['reason'=>$reason,'diagnostic'=>$diagnostic];
    }
    private function controlPresentation(?array $gate): ?array
    {
        return $gate===null?null:['effective'=>($gate['effective']??false)===true,
            'reason'=>$gate['reason']??'UNAVAILABLE','revision'=>$gate['revision']??null];
    }

    protected function controlInstalled(): bool
    {
        return Schema::hasTable('meta_ai_controls');
    }

    protected function automationChannel(?int $conversation=null): string { return 'whatsapp'; }

    protected function filterAutomaticCandidates($query)
    {
        $token=$this->controlToken();
        if ($token===null) return $query->whereRaw('0 = 1');
        return $this->filterDispatchOnlyCandidates($this->filterPrimaryInboundCandidates($query,$token),$this->automationChannel());
    }

    /** Queued ERP work can resume without reopening the automatic-analysis schedule. */
    protected function filterDispatchOnlyCandidates($query,string $channel)
    {
        try { $analysis=app(MetaAiControl::class)->analysisGate($channel); }
        catch (\Throwable $ignore) { return $query->whereRaw('0 = 1'); }
        if (($analysis['effective']??false)===true) return $query;
        return $query->whereExists(function($draft) {
            $draft->selectRaw('1')->from(static::DRAFTS.' as ai_queued')
                ->whereColumn('ai_queued.conversation_id','c.id')->whereIn('ai_queued.status',['REVIEW','READY'])
                                ->whereRaw('ai_queued.evidence_ceiling = (SELECT MAX(ai_queue_message.id) FROM '.$this->table('whatsapp_inbox_messages').' AS ai_queue_message WHERE ai_queue_message.conversation_id = c.id)');
        });
    }

    /** Cheap indexed metadata predicates run before the bounded thread limit. */
    protected function filterPrimaryInboundCandidates($query,array $token)
    {
        $messages=$this->table('whatsapp_inbox_messages');
        $replies=$this->table('whatsapp_reply_requests'); $drafts=static::DRAFTS;
        if (!Schema::hasTable($replies)) return $query->whereRaw('0 = 1');
        // Exhausted unchanged input cannot occupy a bounded worker slot indefinitely.
        // Current cached facts can still clarify or resume a queued order without AI.
        $query->where(function($budget)use($messages,$drafts,$token) {
            $budget->whereNull('s.claimed_ceiling')->orWhere('s.attempts','<',3)->orWhereNull('s.auto_floor')
                ->orWhere('s.auto_floor','!=',$token['message_floor'])
                ->orWhereExists(function($newer)use($messages) {
                    $newer->selectRaw('1')->from($messages.' as ai_budget_message')
                        ->whereColumn('ai_budget_message.conversation_id','c.id')->whereColumn('ai_budget_message.id','>','s.claimed_ceiling');
                })->orWhereExists(function($cached)use($drafts) {
                    $cached->selectRaw('1')->from($drafts.' as ai_budget_draft')
                        ->whereColumn('ai_budget_draft.conversation_id','c.id')->whereColumn('ai_budget_draft.evidence_ceiling','s.claimed_ceiling')
                        ->whereIn('ai_budget_draft.status',['REVIEW','READY','NONE']);
                });
        });
        $analysisOnly=false;
        try { $analysisOnly=app(MetaAiControl::class)->analysisOnly($token['channel']??$this->automationChannel()); }
        catch (\Throwable $ignore) {}
        return $query->whereExists(function($inbound)use($messages,$replies,$drafts,$token,$analysisOnly) {
            $inbound->selectRaw('1')->from($messages.' as ai_inbound')->whereColumn('ai_inbound.conversation_id','c.id')
                ->where('ai_inbound.direction','inbound')->where('ai_inbound.id','>',$token['message_floor'])
                ->where('ai_inbound.sent_at','>=',$token['started_at_utc'])
                ->where('ai_inbound.sent_at','>',now('UTC')->subHours(24)->format('Y-m-d H:i:s'))
                ->where('ai_inbound.sent_at','<=',now('UTC')->format('Y-m-d H:i:s'))
                ->whereNotExists(function($closed)use($drafts) {
                    $closed->selectRaw('1')->from($drafts.' as ai_closed')
                        ->whereColumn('ai_closed.conversation_id','ai_inbound.conversation_id')
                        ->whereIn('ai_closed.status',['DISPATCHED','CANCELLED'])
                        ->whereColumn('ai_closed.evidence_ceiling','>=','ai_inbound.id');
                })
                ->whereNotExists(function($later)use($messages,$analysisOnly) {
                    $later->selectRaw('1')->from($messages.' as ai_later')->whereColumn('ai_later.conversation_id','ai_inbound.conversation_id')
                        ->where(function($date) { $date->whereColumn('ai_later.sent_at','>','ai_inbound.sent_at')
                            ->orWhere(function($tie) { $tie->whereColumn('ai_later.sent_at','ai_inbound.sent_at')->whereColumn('ai_later.id','>','ai_inbound.id'); }); });
                    if ($analysisOnly) $later->where('ai_later.direction','inbound');
                })->whereNotExists(function($reply)use($replies,$analysisOnly) {
                    $reply->selectRaw('1')->from($replies.' as ai_reply')->whereColumn('ai_reply.conversation_id','ai_inbound.conversation_id')
                        ->whereIn('ai_reply.state',$analysisOnly?['CLAIMED','UNKNOWN']:['CLAIMED','UNKNOWN','ACCEPTED'])->where('ai_reply.kind','!=','reaction');
                    if (!$analysisOnly) $reply->where(function($date) { $date->whereColumn('ai_reply.completed_at','>=','ai_inbound.sent_at')
                            ->orWhereColumn('ai_reply.created_at','>=','ai_inbound.sent_at'); });
                });
        });
    }

    protected function controlGate(?int $conversation=null,?array $inbound=null): array
    {
        if (!$this->controlInstalled()) return ['effective'=>false,'reason'=>'NOT_INSTALLED'];
        try {
            return app(MetaAiControl::class)->processingGate($this->automationChannel($conversation),
                $inbound['sent_at']??null,$inbound['id']??null);
        } catch (\Throwable $ignore) { return ['effective'=>false,'reason'=>'UNAVAILABLE']; }
    }

    private function replyControlOpen(int $conversation): bool
    {
        try { return (app(MetaAiControl::class)->gate($this->automationChannel($conversation))['effective']??false)===true; }
        catch (\Throwable $ignore) { return false; }
    }

    private function analysisControlOpen(int $conversation): bool
    {
        try { return (app(MetaAiControl::class)->analysisGate($this->automationChannel($conversation))['effective']??false)===true; }
        catch (\Throwable $ignore) { return false; }
    }

    /** Automatic extraction binds its own clock to the same fresh processing authority. */
    private function requireAnalysisHours(int $conversation,?array $authority=null): array
    {
        try { $gate=app(MetaAiControl::class)->analysisGate($this->automationChannel($conversation)); }
        catch (\Throwable $ignore) { abort_unless(false,409,'AI_CONTROL_CHANGED'); }
        abort_unless(($gate['effective']??false)===true,409,
            ($gate['reason']??null)==='ANALYSIS_OUTSIDE_HOURS'?'ANALYSIS_OUTSIDE_HOURS':'AI_CONTROL_CHANGED');
        $token=$this->controlToken($conversation);
        abort_unless($token!==null&&($authority===null||$token===$authority)
            &&($gate['revision']??null)===$token['revision']&&($gate['actor_id']??null)===$token['actor_id']
            &&($gate['floor']??null)===['message_floor'=>$token['message_floor'],
                'event_floor'=>$token['event_floor'],'started_at_utc'=>$token['started_at_utc']],409,'AI_CONTROL_CHANGED');
        return $token;
    }

    /** A configured mode is never authority on its own: callers still bind processingGate. */
    protected function analysisOnly(int $conversation): bool
    {
        if (!$this->controlInstalled()) return false;
        try { return app(MetaAiControl::class)->analysisOnly($this->automationChannel($conversation))===true; }
        catch (\Throwable $ignore) { return false; }
    }

    /** This fresh business-clock guard is independent from composing a missing-data reply. */
    private function requireDispatchHours(int $conversation,?array $authority): void
    {
        try { $gate=app(MetaAiControl::class)->dispatchGate($this->automationChannel($conversation)); }
        catch (\Throwable $ignore) { abort_unless(false,409,'AI_CONTROL_CHANGED'); }
        abort_unless(($gate['effective']??false)===true,409,
            ($gate['reason']??null)==='DISPATCH_OUTSIDE_HOURS'?'DISPATCH_OUTSIDE_HOURS':'AI_CONTROL_CHANGED');
        abort_unless($authority!==null&&($gate['revision']??null)===$authority['revision']
            &&($gate['actor_id']??null)===$authority['actor_id']
            &&($gate['floor']??null)===['message_floor'=>$authority['message_floor'],
                'event_floor'=>$authority['event_floor'],'started_at_utc'=>$authority['started_at_utc']],409,'AI_CONTROL_CHANGED');
    }

    protected function controlToken(?int $conversation=null,?array $inbound=null): ?array
    {
        $gate=$this->controlGate($conversation,$inbound); $floor=$gate['floor']??null;
        if (($gate['effective']??false)!==true||!is_array($floor)||!is_int($gate['revision']??null)
            ||!is_int($gate['actor_id']??null)||$gate['actor_id']<1
            ||!is_int($floor['message_floor']??null)||!is_int($floor['event_floor']??null)
            ||!is_string($floor['started_at_utc']??null)) return null;
        return ['channel'=>$this->automationChannel($conversation),'revision'=>$gate['revision'],'actor_id'=>$gate['actor_id'],
            'message_floor'=>$floor['message_floor'],'event_floor'=>$floor['event_floor'],'started_at_utc'=>$floor['started_at_utc']];
    }

    /** The latest native inbound, including any caption, is authenticated before automatic AI. */
    protected function primaryInbound(int $conversation,array $snapshot): ?array
    {
        if (($snapshot['complete']??false)!==true) return null;
        $latest=null;
        foreach ($snapshot['transcript']??[] as $row) if (($row['speaker']??null)==='customer') $latest=$row;
        if ($latest===null||!isset($snapshot['keys'][$latest['id']])) return null;
        $native=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$conversation)
            ->where('message_key',$snapshot['keys'][$latest['id']])->where('direction','inbound')
            ->select('id','conversation_id','message_key','direction','source','type','sent_at')
            ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
        if (!$native||!is_string($native->content)) return null;
        $message=(new WhatsAppInboxMessage())->projected($this->conversation($conversation),$native);
        if ($message['direction']!=='inbound'||$message['native_message_key']!==$native->message_key
            ||$message['sent_at']!==$latest['sent_at']||$message['kind']!==$native->type
            ||($latest['text']!==null&&$latest['text']!==$message['text'])
            ||($native->type==='text'&&$message['text']!==$latest['text'])) return null;
        if (is_string($message['text'])&&WhatsAppOrderExtraction::hasProbe([
            ['id'=>'m1','speaker'=>'customer','sent_at'=>$latest['sent_at'],'text'=>$message['text'],'location'=>null]])) return null;
        return ['id'=>(int)$native->id,'message_key'=>$native->message_key,'sent_at'=>$native->sent_at,
            'label'=>$latest['id'],'text'=>$message['text'],'kind'=>$native->type];
    }

    /** Human replies take precedence even if their native echo has not arrived yet. */
    protected function primaryHumanClear(int $conversation,array $latest,array $snapshot): bool
    {
        $after=false;
        foreach ($snapshot['transcript'] as $row) {
            if ($row['id']===$latest['label']) { $after=true; continue; }
            if ($after&&$row['speaker']==='business') return false;
        }
        if (!Schema::hasTable('whatsapp_reply_requests')) return false;
        $query=DB::table('whatsapp_reply_requests')->where('conversation_id',$conversation)
            ->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID)
            ->whereIn('state',['CLAIMED','UNKNOWN','ACCEPTED'])->where(function($query)use($latest) {
                $query->where('completed_at','>=',$latest['sent_at'])->orWhere('created_at','>=',$latest['sent_at']);
            })->where('kind','!=','reaction')
            ->orderByDesc('id')->limit(61)->select('id','inbound_message_id')
            ->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit');
        if (DB::connection()->transactionLevel()>0) $query->lockForUpdate();
        $rows=$query->get();
        if (count($rows)>60) return false;
        foreach ($rows as $row) {
            if (!is_string($row->audit)) return false;
            $audit=$this->decode($row->audit); $marker=$audit['order_followup']??null;
            if (!is_array($marker)||!WhatsAppOrderFollowupService::validMarker($marker,$conversation,
                $audit['client_request_id']??'',(int)$row->inbound_message_id)) return false;
        }
        return true;
    }

    protected function primaryCancelled($text): bool
    {
        return is_string($text)&&preg_match('/(?:مش|لا|مت|ما)\s*(?:عايز|عاوز|موافق|محتاج|أريد|اريد|تأكد|تاكد|تؤكد|ترسل|تبعت|ابعت)|'
            .'(?<![\p{L}\p{N}])(?:إلغاء|الغاء|إلغي|إلغى|الغي|الغى|الغيه|إلغيه|ألغي|ألغى|لغي|لغيه|إلغ|الغ|أرفض|ارفض|بلاش|كفاية|توقف)(?![\p{L}\p{N}])|'
            .'\A\s*(?:لا|لأ|لاء|كلا|no|nope|stop)(?:\s+(?:شكرا|thanks))?[.!،,]*\s*\z|'
            .'\b(?:do\s+not|don[’\x27]t|not\s+want|cancel|please\s+stop)\b/iu',$text)===1;
    }

    /** A mode change cannot overtake an uncertain or still reserved dashboard send. */
    private function analysisRepliesSettled(int $conversation): bool
    {
        $table=$this->table('whatsapp_reply_requests');
        if (!Schema::hasTable($table)) return false;
        $query=DB::table($table)->where('conversation_id',$conversation)->whereIn('state',['CLAIMED','UNKNOWN'])
            ->where('kind','!=','reaction');
        if (DB::connection()->transactionLevel()>0) $query->lockForUpdate();
        return $query->first(['id'])===null;
    }

    /**
     * The external assistant's fixed recap is a proposal, never customer consent.
     * Its entire basket is bound to literal field/item lines. The authenticated
     * snapshot and the normal extraction, catalog, location and CAS guards remain
     * authoritative; a marker, a copied recap or an AI CONFIRMED flag is insufficient.
     */
    private function externalRecapApproved(array $data,array $snapshot): bool
    {
        if (($snapshot['complete']??false)!==true||($data['decision']??null)!=='CONFIRMED'
            ||WhatsAppOrderExtraction::hasProbe($snapshot['transcript']??[])) return false;
        $checked=WhatsAppOrderExtraction::validate($data,$snapshot['transcript']);
        if (($checked['ok']??false)!==true) return false;
        $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
        $recap=null; $position=null;
        $ack='جاري مراجعة البيانات لإرسال الطلب للفرع.';
        foreach ($episode as $i=>$row) if (($row['speaker']??null)==='business'
            &&is_string($row['text']??null)&&str_starts_with(trim($row['text']),'ملخص الطلب النهائي:')) {
            $recap=$row; $position=$i;
        }
        if ($recap===null||!in_array($recap['id'],$data['evidence']['confirmation_ids']??[],true)) return false;
        $lines=preg_split('/\R/u',trim($recap['text']));
        if (!is_array($lines)||count($lines)>120) return false;
        $lines=array_map(static fn($line)=>trim(preg_replace('/[\p{Z}\t]+/u',' ',$line)),$lines);
        if (array_shift($lines)!=='ملخص الطلب النهائي:') return false;
        $fields=[]; $itemLines=[];
        foreach ($lines as $line) {
            if ($line==='') continue;
            if (str_starts_with($line,'- ')) { $itemLines[]=$line; continue; }
            if (in_array($line,['تأكيد الطلب:','الأصناف:','للموافقة على هذه البيانات اكتب: موافق على الطلب'],true)) continue;
            if (!preg_match('/\A(الاسم|رقم الهاتف|نوع الطلب|الفرع|العنوان|المنطقة|ملاحظات|الإجمالي التقريبي):\s*(.+)\z/u',$line,$match)
                ||isset($fields[$match[1]])) return false;
            $fields[$match[1]]=$match[2];
        }
        $normalize=static fn($text)=>trim(preg_replace('/[\p{Z}\t]+/u',' ',(string)$text));
        foreach (['الاسم'=>'name','العنوان'=>'address','المنطقة'=>'area'] as $label=>$key) {
            if (!is_string($data['customer'][$key]??null)||$normalize($data['customer'][$key])===''
                ||($fields[$label]??null)!==$normalize($data['customer'][$key])) return false;
        }
        if (!is_string($data['customer']['phone']??null)||!isset($fields['رقم الهاتف'])
            ||BranchCustomers::phoneKey($fields['رقم الهاتف'])!==BranchCustomers::phoneKey($data['customer']['phone'])
            ||BranchCustomers::phoneKey($data['customer']['phone'])==='') return false;
        if (($data['fulfillment']??null)!=='DELIVERY'||($fields['نوع الطلب']??null)!=='دليفري'
            ||!is_string($data['branch_hint']??null)||$normalize($data['branch_hint'])===''
            ||($fields['الفرع']??null)!==$normalize($data['branch_hint'])) return false;
        $notes=$data['customer']['notes']??null;
        if ($notes!==null&&(!is_string($notes)||($fields['ملاحظات']??null)!==$normalize($notes))) return false;
        if ($notes===null&&isset($fields['ملاحظات'])&&!in_array($fields['ملاحظات'],['لا يوجد','بدون ملاحظات'],true)) return false;
        $digits=static fn($value)=>strtr((string)$value,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        $decimal=static function($value,bool $money=false)use($digits) {
            $value=$digits($value);
            if (!preg_match($money?'/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/':'/\A[0-9]{1,4}(?:\.[0-9]{1,3})?\z/',$value)) return null;
            $parts=explode('.',$value,2); $whole=ltrim($parts[0],'0'); $fraction=rtrim($parts[1]??'','0');
            return ($whole===''?'0':$whole).($fraction===''?'':'.'.$fraction);
        };
        $total=$data['approximate_total']??null;
        if ($total!==null) {
            if (!isset($fields['الإجمالي التقريبي'])||$decimal($total,true)===null
                ||$decimal(preg_replace('/\s*(?:جنيه|ج)\.?\z/u','',$fields['الإجمالي التقريبي']),true)!==$decimal($total,true)) return false;
        } elseif (isset($fields['الإجمالي التقريبي'])&&!in_array($fields['الإجمالي التقريبي'],['غير محدد','لا يوجد'],true)) return false;
        if (count($itemLines)!==count($data['items']??[])||$itemLines===[]) return false;
        $actual=[];
        foreach ($itemLines as $line) {
            if (!preg_match('/\A- (.+?) \| الكمية: ([^|]+?) (كيلو|قطعة)(?: \| الاختيار: (.+))?\z/u',$line,$item)
                ||($quantity=$decimal(trim($item[2])))===null) return false;
            $actual[]=[$item[1],$quantity,$item[3],$item[4]??null];
        }
        foreach ($data['items'] as $item) {
            $unit=['weight'=>'كيلو','piece'=>'قطعة'][$item['quantity_mode']??'']??null;
            $quantity=$decimal($item['quantity']??'');
            $expected=[$normalize($item['name']??''),$quantity,$unit,
                isset($item['option_hint'])&&$item['option_hint']!==null?$normalize($item['option_hint']):null];
            $index=array_search($expected,$actual,true);
            if ($unit===null||$quantity===null||$index===false) return false;
            unset($actual[$index]);
        }
        $approved=false; $acknowledged=false;
        foreach (array_slice($episode,$position+1) as $row) {
            if ($row['speaker']==='business') {
                if (!$approved||$acknowledged||!is_string($row['text'])||trim($row['text'])!==$ack) return false;
                $acknowledged=true; continue;
            }
            if (!is_string($row['text'])||!preg_match('/\A\s*(?:(?:(?:تمام|نعم)\s+)?(?:موافق|أوافق|اوافق)'
                .'(?:\s+على\s+(?:الطلب|البيانات(?:\s+والطلب)?))?|تمام|حاضر|نعم|اوكي|أوكي|ماشي|ok|okay|yes)[.!]?\s*\z/iu',$row['text'])
                ||!in_array($row['id'],$data['evidence']['customer_acceptance_ids']??[],true)) return false;
            $approved=true;
        }
        return $approved;
    }

    protected function primaryAuthority(int $conversation,array $snapshot): ?array
    {
        if (!$this->controlInstalled()) return null;
        try {
            if (WhatsAppOrderExtraction::hasProbe($snapshot['transcript']??[])) return null;
            $latest=$this->primaryInbound($conversation,$snapshot);
            if ($latest===null||$latest['sent_at']<=now('UTC')->subHours(24)->format('Y-m-d H:i:s')
                ||$latest['sent_at']>now('UTC')->format('Y-m-d H:i:s')
                ||($this->analysisOnly($conversation)?!$this->analysisRepliesSettled($conversation):!$this->primaryHumanClear($conversation,$latest,$snapshot))
                ||$this->primaryCancelled($latest['text'])||!$this->primaryCaptureClear($conversation,$latest)) return null;
            return $this->controlToken($conversation,$latest);
        } catch (\Throwable $ignore) { return null; }
    }

    /** A quarantined pre-activation cancellation cannot disappear from backlog proof. */
    private function primaryCaptureClear(int $conversation,array $latest): bool
    {
        $gate=$this->controlGate($conversation,$latest); $floor=$gate['floor']??null;
        if (($gate['effective']??false)!==true||!is_array($floor)) return false;
        $failures=$this->table('whatsapp_inbox_ingestion_failures');
        $events=$this->table('whatsapp_webhook_events');
        if (!Schema::hasTable($failures)||!Schema::hasTable($events)) return false;
        // Preserve the established forward raw-capture barrier for every turn.
        $forward=DB::table($failures)->where('event_id','>',$floor['event_floor']);
        if (DB::connection()->transactionLevel()>0) $forward->lockForUpdate();
        if ($forward->first(['event_id'])) return false;
        $original=$gate['original_activation_started_at_utc']??null;
        if (($gate['backlog_version']??null)!==1||!is_string($original)||$latest['sent_at']>$original) return true;
        if (!Schema::hasColumn($events,'received_at')) return !DB::table($failures)->exists();
        $historical=DB::table($failures.' as ai_failure')->leftJoin($events.' as ai_event','ai_event.id','=','ai_failure.event_id')
            ->where('ai_failure.event_id','<=',$floor['event_floor'])
            ->where(function($q)use($floor,$original) {
                $q->whereNull('ai_event.id')->orWhereNull('ai_event.received_at')
                    ->orWhere(function($range)use($floor,$original) {
                        $range->where('ai_event.received_at','>=',$floor['started_at_utc'])
                            ->where('ai_event.received_at','<=',$original);
                    });
            });
        if (DB::connection()->transactionLevel()>0) $historical->lockForUpdate();
        return $historical->first(['ai_failure.event_id'])===null;
    }

    public function primaryPlanCurrent(array $plan): bool
    {
        try {
            if (!$this->controlInstalled()||!isset($plan['primary_ai'])||$this->pendingCapture()
                ||!$this->replyControlOpen((int)($plan['conversation_id']??0))) return false;
            $row=$this->draft($plan['draft_id']);
            if ((int)$row->conversation_id!==$plan['conversation_id']||$row->ticket_id!==null
                ||$this->ceiling((int)$row->conversation_id)!==$plan['evidence_ceiling']) return false;
            $snapshot=$this->snapshot($plan['conversation_id'],$plan['evidence_floor'],$plan['evidence_ceiling']);
            $latest=$this->primaryInbound($plan['conversation_id'],$snapshot);
            return $snapshot['hash']===$plan['evidence_hash']&&$snapshot['transcript']===$plan['transcript']
                &&$latest!==null&&$latest['id']===$plan['latest_customer_id']
                &&$latest['message_key']===$plan['latest_customer_key']
                &&$this->primaryAuthority($plan['conversation_id'],$snapshot)===$plan['primary_ai'];
        } catch (\Throwable $ignore) { return false; }
    }

    protected function automaticProvider(?array $authority)
    {
        if ($authority===null) return $this->provider();
        // Explicit dependency bindings remain usable for isolated transports and tests.
        if (app()->bound(WhatsAppOrderAiProvider::class)) return $this->provider();
        return new WhatsAppOrderAiProvider(null,array_replace((array)$this->orderConfig('whatsapp_orders',[]),['enabled'=>true]));
    }

    private function ready(): void { abort_unless($this->available(),503,'WHATSAPP_ORDERS_UNAVAILABLE'); }
    private function mode(): string { return $this->controlInstalled()?'auto':($this->orderConfig('whatsapp_orders.mode','review')==='auto'?'auto':'review'); }
    private function encrypt(array $value): string { return Crypt::encryptString(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
    private function decode($value): ?array
    {
        if ($value===null) return null;
        $decoded=json_decode(Crypt::decryptString($value),true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($decoded),500,'INVALID_ENCRYPTED_ORDER'); return $decoded;
    }
    /** Channel hooks preserve the deployed WhatsApp scope and permit isolated Meta storage. */
    protected function table(string $table): string { return $table; }
    protected function orderConfig(string $name, $default = null) { return config($name, $default); }
    protected function inboxAccess() { return app(WhatsAppInboxAccess::class); }
    protected function provider() { return app(WhatsAppOrderAiProvider::class); }
    protected function scope($query, string $prefix = '')
    {
        return $query->where($prefix.'waba_id', WhatsAppInboxAccess::WABA_ID)
            ->where($prefix.'phone_number_id', WhatsAppInboxAccess::PHONE_ID);
    }
    protected function scopeKey(object $thread): string
    {
        return WhatsAppInboxAccess::WABA_ID.':'.WhatsAppInboxAccess::PHONE_ID.':';
    }

}
