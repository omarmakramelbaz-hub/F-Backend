<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\WhatsAppOrderExtraction;
use App\Support\WhatsAppInboxProtocol;
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
        'RECOVERED_HISTORY_REVIEW'];

    public function available(): bool
    {
        return $this->inboxAccess()->available() && Schema::hasTable(static::DRAFTS) && Schema::hasTable(static::SCANS);
    }

    public function state(int $conversation, $actor): array
    {
        $this->actor($actor);
        if (!$this->available()) return ['success'=>true,'available'=>false,'enabled'=>(bool)$this->orderConfig('whatsapp_orders.enabled',false),
            'mode'=>$this->mode(),'pending_analysis'=>false,'drafts'=>[]];
        $this->conversation($conversation);
        $scan = DB::table(static::SCANS)->where('conversation_id', $conversation)->first();
        $drafts = DB::table(static::DRAFTS)->where('conversation_id', $conversation)->orderByDesc('id')->limit(10)->get();
        return ['success'=>true, 'available'=>true, 'enabled'=>(bool)$this->orderConfig('whatsapp_orders.enabled', false),
            'mode'=>$this->mode(), 'pending_analysis'=>$this->ceiling($conversation) > (int)($scan->analyzed_ceiling ?? 0),
            'automatic'=>['configured'=>(bool)$this->orderConfig('whatsapp_orders.enabled',false)&&$this->mode()==='auto'
                &&is_numeric($this->orderConfig('whatsapp_orders.automation_actor_id'))&&(int)$this->orderConfig('whatsapp_orders.automation_actor_id')>0
                &&is_array($this->orderConfig('whatsapp_orders.allowed_branch_ids'))&&$this->orderConfig('whatsapp_orders.allowed_branch_ids')!==[],
                'debounce_seconds'=>$this->debounceSeconds(),'last_analysis_at'=>$scan->updated_at??null,'next_attempt_at'=>$scan->next_attempt_at??null],
            'drafts'=>$drafts->map(fn($r)=>$this->present($r))->all()];
    }

    public function analyze(int $conversation, $actor, bool $force = false): array
    {
        return $this->analyzeInternal($conversation, $this->actor($actor), $force, false);
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
                $activation=$this->activationBoundary('activation_message_id');
                abort_unless($activation!==null,403,'AUTO_NOT_CONFIGURED');
                $floor=max($floor,$activation,(int)($scan->auto_floor??0));
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

    /** CLI only: newly observed threads are baselined rather than replaying historical orders. */
    public function process(int $limit = 10): array
    {
        abort_unless($limit >= 1 && $limit <= 20, 422, 'INVALID_LIMIT');
        $metrics = ['threads_seen'=>0, 'baselined'=>0, 'analyzed'=>0, 'review_required'=>0,
            'dispatched'=>0, 'replayed'=>0, 'errors'=>0, 'disabled'=>0, 'capture_pending'=>0, 'deferred'=>0];
        if (!(bool)$this->orderConfig('whatsapp_orders.enabled', false) || !$this->available()) { $metrics['disabled']=1; return $metrics; }
        try { $actor = $this->automaticActor(); }
        catch (\Throwable $error) { $metrics['disabled']=1; return $metrics; }
        $activation=$this->activationBoundary('activation_message_id');
        if ($activation===null || $this->activationBoundary('activation_event_id')===null || $this->activationInstant()===null) { $metrics['disabled']=1; return $metrics; }
        // API replies already accepted by Meta are durable business evidence even when no
        // business-app echo arrives. Project their receipts before selecting a message ceiling.
        // A receipt has an explicit conversation: invalid evidence blocks that conversation
        // below, while this bounded global pass also wakes fresh API-only confirmations.
        $this->projectAcceptedReplies(null,$this->activationInstant());
        // Incomplete projection must never classify a partial transcript or spend another API call.
        if ($this->pendingCapture()) { $metrics['capture_pending']=1; return $metrics; }
        $rows = $this->scope(DB::table($this->table('whatsapp_inbox_conversations').' as c'), 'c.')
            ->join($this->table('whatsapp_inbox_messages').' as m', 'm.conversation_id', '=', 'c.id')
            ->leftJoin(static::SCANS.' as s', 's.conversation_id', '=', 'c.id')
            ->where(function ($q) use ($activation) {
                // A newer cutoff starts a new bounded attempt sequence, under the scan lock below.
                $q->whereNull('s.auto_floor')->orWhere('s.auto_floor','<',$activation)
                    ->orWhereNull('s.next_attempt_at')->orWhere('s.next_attempt_at', '<=', now('UTC'));
            })
            ->select('c.id', 's.analyzed_ceiling', 's.auto_floor', 's.claimed_ceiling', 's.attempts')->selectRaw('MAX(m.id) AS ceiling, MAX(m.created_at) AS observed_at')
            ->groupBy('c.id', 's.analyzed_ceiling', 's.auto_floor', 's.claimed_ceiling', 's.attempts')
            ->havingRaw('MAX(m.created_at) <= ?', [$this->settledBefore()])
            ->havingRaw('MAX(m.id) > ?',[$activation])
            ->havingRaw('MAX(m.id) > COALESCE(s.auto_floor, 0)')
            ->havingRaw('(MAX(m.id) > COALESCE(s.analyzed_ceiling, 0) OR (MAX(m.id) = s.analyzed_ceiling AND EXISTS (SELECT 1 FROM '.static::DRAFTS.' AS d WHERE d.conversation_id = c.id AND d.evidence_ceiling = s.analyzed_ceiling AND d.status IN (?, ?) AND COALESCE(d.reason, ?) NOT IN (?, ?))))', ['REVIEW','READY','','INVALID_EXTRACTION','CONTEXT_INCOMPLETE'])
            ->havingRaw('(MAX(m.id) != COALESCE(s.claimed_ceiling, 0) OR COALESCE(s.attempts, 0) < 3 OR s.auto_floor IS NULL OR s.auto_floor < ?)',[$activation])
            ->orderBy('c.id')->limit($limit)->get();
        foreach ($rows as $thread) {
            $metrics['threads_seen']++;
            try {
                if ($this->waitingForCustomer((int)$thread->id,(int)($thread->analyzed_ceiling??0),(int)$thread->ceiling)) {
                    $this->deferDispatch((int)$thread->id);
                    $metrics['deferred']++; continue;
                }
                $result = $this->analyzeInternal((int)$thread->id, $actor, false, true); $metrics['analyzed']++;
                if ($result['baselined'] ?? false) $metrics['baselined']++;
                $draft = $this->draft((int)$result['draft']['id']);
                if ($draft->status==='DISPATCHED') { $metrics['replayed']++; continue; }
                if (in_array($draft->status,['NONE','CANCELLED'],true)) continue;
                if (in_array($draft->reason,['AI_UNAVAILABLE','INVALID_EXTRACTION','CONTEXT_INCOMPLETE'],true)) { $metrics['review_required']++; continue; }
                if ($this->mode() !== 'auto') { $metrics['review_required']++; continue; }
                $reason = null; $review = $this->automaticReview($draft, $actor, $reason);
                if ($review === null) {
                    $this->reviewReason($draft, $reason ?? 'REVIEW_REQUIRED');
                    $this->requestMissingInformation($draft,$actor);
                    $this->deferDispatch((int)$draft->conversation_id); $metrics['review_required']++; continue;
                }
                $quoted = $this->quote((int)$draft->id, $review, $actor);
                $review = $quoted['draft']['review']; $review['expected_revision']=$quoted['draft']['revision'];
                // A business estimate is advisory. The unpaid ticket uses the current ERP catalog
                // and delivery quote, and dispatch rechecks both fingerprints before any write.
                $sent = $this->dispatchInternal((int)$draft->id, $review, $this->automaticActor(), true);
                $metrics[$sent['replayed'] ? 'replayed' : 'dispatched']++;
            } catch (\Throwable $error) {
                if ($error->getMessage()==='TRANSCRIPT_SETTLING') $metrics['deferred']++;
                elseif (in_array($error->getMessage(),['CAPTURE_PENDING','ACCEPTED_REPLIES_PENDING'],true)) $metrics['capture_pending']=1;
                else { $metrics['errors']++; $this->deferDispatch((int)$thread->id); }
            }
        }
        return $metrics;
    }

    private function analyzeInternal(int $conversation, $actor, bool $force, bool $automatic): array
    {
        $this->ready(); $this->conversation($conversation);
        $bounded=$automatic || ((bool)$this->orderConfig('whatsapp_orders.enabled',false) && $this->mode()==='auto'
            && $this->activationBoundary('activation_message_id')!==null
            && $this->activationBoundary('activation_event_id')!==null && $this->activationInstant()!==null);
        abort_unless($this->projectAcceptedReplies($conversation,$bounded?$this->activationInstant():null),409,'ACCEPTED_REPLIES_PENDING');
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
            $activation=$bounded ? $this->activationBoundary('activation_message_id') : null;
            $eventActivation=$bounded ? $this->activationBoundary('activation_event_id') : null;
            $activationTime=$bounded ? $this->activationInstant() : null;
            if ($bounded) abort_unless($activation!==null && $eventActivation!==null && $activationTime!==null,403,'AUTO_NOT_CONFIGURED');
            $floor=$bounded ? $this->automaticFloor($conversation,$scan,true) : $this->dispatchedCeiling($conversation);
            if ($bounded) abort_unless($ceiling>$floor,409,'STALE_TRANSCRIPT');
            $baselined=$bounded && ($scan->auto_floor===null || (int)$scan->auto_floor<$activation);
            $staleBounded=$bounded && $existing && (int)$existing->evidence_floor<$floor;
            $staleEvidence=$existing && !$staleBounded
                && !hash_equals((string)$existing->evidence_hash,$this->snapshot($conversation,$floor,$ceiling)['hash']);
            if ($baselined || $staleBounded || $staleEvidence) {
                // Never reset a live lease. Keep historical drafts and completed command receipts.
                $updates=['claimed_ceiling'=>0,'attempts'=>0,'next_attempt_at'=>null,'updated_at'=>now('UTC')];
                if ($bounded) $updates['auto_floor']=max($activation,(int)($scan->auto_floor??0));
                DB::table(static::SCANS)->where('conversation_id',$conversation)->update($updates);
                if ($bounded) $scan->auto_floor=$updates['auto_floor'];
                $scan->claimed_ceiling=0; $scan->attempts=0; $scan->next_attempt_at=null;
            }
            $retry=$automatic && $existing && $existing->reason==='AI_UNAVAILABLE' && (int)$scan->attempts<3;
            if ($existing && !$staleBounded && !$staleEvidence && !$force && !$retry) return ['existing'=>$existing,'baselined'=>$baselined];
            if (!$force) abort_if($scan->next_attempt_at && $scan->next_attempt_at > now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_BACKOFF');
            $attempts = (int)$scan->claimed_ceiling === $ceiling ? (int)$scan->attempts+1 : 1;
            abort_if($attempts > 3 && !$force, 409, 'ANALYSIS_RETRY_LIMIT');
            $nonce = bin2hex(random_bytes(32));
            DB::table(static::SCANS)->where('conversation_id', $conversation)->update(['claimed_ceiling'=>$ceiling, 'claim_nonce'=>$nonce,
                'lease_until'=>now('UTC')->addSeconds(180), 'attempts'=>min(3,$attempts), 'next_attempt_at'=>null, 'updated_at'=>now('UTC')]);
            return ['nonce'=>$nonce, 'floor'=>$floor, 'ceiling'=>$ceiling, 'existing_id'=>$existing ? (int)$existing->id : null,
                'activation'=>$activation,'event_activation'=>$eventActivation,'activation_time'=>$activationTime,'baselined'=>$baselined];
        }, 3);
        if (isset($claim['existing'])) return ['success'=>true, 'draft'=>$this->present($claim['existing']),'baselined'=>$claim['baselined']];
        try {
            $snapshot = $this->snapshot($conversation, $claim['floor'], $claim['ceiling']);
            $result = $snapshot['complete'] ? $this->provider()->extract($snapshot['transcript'])
                : ['ok'=>false, 'reason'=>'CONTEXT_INCOMPLETE', 'data'=>null];
            if (!is_array($result) || !($result['ok'] ?? false)) {
                $data = ['analysis_failure'=>$this->safeAnalysisFailure($result)];
                $reason = in_array($result['reason']??null,['INVALID_INPUT','INVALID_EVIDENCE','INVALID_EXTRACTION'],true)
                    ? 'INVALID_EXTRACTION' : (($result['reason'] ?? null) === 'CONTEXT_INCOMPLETE' ? 'CONTEXT_INCOMPLETE' : 'AI_UNAVAILABLE');
                $status='REVIEW';
            } else {
                $checked = WhatsAppOrderExtraction::validate($result['data'], $snapshot['transcript']);
                $data = ($checked['ok'] ?? false) ? $checked['data'] : null;
                $reason = $data === null ? 'INVALID_EXTRACTION' : 'REVIEW_REQUIRED'; $status='REVIEW';
                if (($data['decision'] ?? '') === 'NONE') { $status='NONE'; $reason='NO_ORDER'; }
                if (($data['decision'] ?? '') === 'CANCELLED') { $status='CANCELLED'; $reason='CUSTOMER_CANCELLED'; }
            }
            // A reply may be accepted while extraction is in flight. Project it before CAS
            // so neither a partial NONE result nor an earlier confirmation can be persisted.
            abort_unless($this->projectAcceptedReplies($conversation,$bounded?$claim['activation_time']:null),409,'ACCEPTED_REPLIES_PENDING');
            $id = DB::transaction(function () use ($conversation, $actor, $claim, $snapshot, $data, $reason, $status,$automatic,$bounded) {
                $this->actor($actor);
                $scan = DB::table(static::SCANS)->where('conversation_id', $conversation)->lockForUpdate()->first();
                abort_unless($scan && hash_equals((string)$scan->claim_nonce, $claim['nonce']) && $scan->lease_until >= now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_LEASE_LOST');
                if ($bounded) {
                    abort_unless($this->activationBoundary('activation_message_id')===$claim['activation']
                        && $this->activationBoundary('activation_event_id')===$claim['event_activation']
                        && $this->activationInstant()===$claim['activation_time']
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

    private function dispatchInternal(int $draft, array $review, $actor, bool $automatic): array
    {
        $this->ready(); $v = $this->review($this->storedDeliveryOrigin($this->draft($draft),$review), true);
        return DB::transaction(function () use ($draft, $v, $actor, $automatic) {
            if ($automatic) abort_unless($this->mode()==='auto',403,'AUTO_NOT_CONFIGURED');
            $actor = $automatic ? $this->automaticActor() : $this->actor($actor);
            $row = $this->draft($draft, true);
            if ($row->status === 'DISPATCHED') {
                $sealed = $this->decode($row->sealed_payload);
                abort_unless($sealed && $this->reviewHash($v) === $this->reviewHash($sealed['review']),409,'DISPATCH_REPLAY_CHANGED');
                $this->branch((string)$row->assigned_branch,$actor);
                return ['success'=>true,'replayed'=>true,'draft'=>$this->present($row),'ticket_id'=>(int)$row->ticket_id,'print_queued'=>true];
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
            $saved=$automatic&&$customer&&$this->sameCustomerValues($customer,$v)
                ? ['customer'=>['id'=>(int)$customer->id]] : app(BranchCustomers::class)->save($customerValues,$actor);
            $payload=$v; unset($payload['expected_revision']);
            $payload+=['idempotency_key'=>$row->ticket_command_key,'customer_id'=>(int)$saved['customer']['id'],
                'send_to_kitchen'=>true,'discount'=>'0.00','discount_reason'=>''];
            $result=app(PosServiceTicket::class)->save('phone',$payload,$actor);
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
        $eventFloor=$this->activationBoundary('activation_event_id');
        if ($eventFloor===null || $this->activationBoundary('activation_message_id')===null || $this->activationInstant()===null) { $reason='AUTO_NOT_CONFIGURED'; return null; }
        $floor=$this->automaticFloor((int)$row->conversation_id,null,$lock);
        if ((int)$row->evidence_floor<$floor || (int)$row->evidence_ceiling<=$floor) { $reason='STALE_TRANSCRIPT'; return null; }
        $failures=DB::table($this->table('whatsapp_inbox_ingestion_failures'))->where('event_id','>',$eventFloor);
        if ($lock) $failures->lockForUpdate();
        if ($failures->first(['event_id'])) { $reason='CAPTURE_REVIEW_REQUIRED'; return null; }
        $data=$this->decode($row->extraction); $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        if (!$snapshot['complete'] || !$data || ($data['decision']??null)!=='CONFIRMED'
            || array_diff($data['issues']??[],['PRICE_ESTIMATE_ONLY','LOCATION_REQUIRED','MISSING_BRANCH','MISSING_AREA'])!==[]) { $reason='REVIEW_REQUIRED'; return null; }
        if ($snapshot['recovered_at']!==null && !$this->freshAutomaticEvidence($data,$snapshot['transcript'],$snapshot['recovered_at'])) {
            $reason='RECOVERED_HISTORY_REVIEW'; return null;
        }
        if (!$this->freshAutomaticEvidence($data,$snapshot['transcript'])) { $reason='STALE_TRANSCRIPT'; return null; }
        $followup=$this->followupService();
        if ($followup!==null&&!$followup->approvalSatisfied((int)$row->conversation_id,$data,$snapshot,$lock)) {
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
        $customer=$data['customer']??[];
        foreach (['name','phone','address'] as $k) if (!is_string($customer[$k]??null)||trim($customer[$k])==='') { $reason='REVIEW_REQUIRED'; return null; }
        $savedQuery=DB::table('branch_customers')->where('branch',$branch)->where('phone_key',BranchCustomers::phoneKey($customer['phone']));
        if ($lock) $savedQuery->lockForUpdate(); $saved=$savedQuery->first();
        $location=null;
        $locationTime=null; $addressTime=null; $lastLocation=null; $summaryAddress=false;
        foreach ($snapshot['transcript'] as $m) {
            if ($m['speaker']==='customer' && ((is_string($m['text']) && strpos($m['text'],$customer['address'])!==false)
                || (is_string($m['cart']['text']??null)&&strpos($m['cart']['text'],$customer['address'])!==false))) $addressTime=$m['sent_at'];
            if ($m['speaker']==='customer' && $m['location']!==null) $lastLocation=$m['id'];
            if ($m['id']===($data['evidence']['location_id']??null)&&$m['speaker']==='customer') { $location=$m['location']; $locationTime=$m['sent_at']; }
            if ($m['speaker']==='business' && in_array($m['id'],$data['evidence']['confirmation_ids']??[],true)
                && is_string($m['text']) && strpos($m['text'],$customer['address'])!==false) $summaryAddress=true;
        }
        if (!$summaryAddress && WhatsAppOrderConversationEvidence::addressAcknowledged($data,
                array_column($snapshot['transcript'],null,'id'))) $summaryAddress=true;
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
        foreach ($snapshot['transcript'] as $message) if ($message['speaker']==='business'&&in_array($message['id'],$data['evidence']['confirmation_ids']??[],true)&&is_string($message['text'])) $summaryTexts[]=$message['text'];
        $usedCartLines=[];
        foreach ($data['items'] as $item) {
            if (($item['cart_reference']??null)!==null) {
                $resolved=$this->cartItem($item,$data['items'],$summaryTexts,$snapshot['transcript'],$catalog,$branch,$usedCartLines);
                if ($resolved===null) { $reason='CATALOG_UNRESOLVED'; return null; }
                $items[]=$resolved; continue;
            }
            $matches=array_values(array_filter($catalog,fn($p)=>($p['available']??false)&&$this->name($p['name'])===$this->name($item['name'])));
            if (count($matches)!==1 || !is_string($item['quantity']??null) || !in_array($item['quantity_mode'],['piece','weight'],true)
                || !in_array($matches[0]['quantity_mode']??'select',[$item['quantity_mode'],'select'],true)) { $reason='CATALOG_UNRESOLVED'; return null; }
            $p=$matches[0]; $option=$this->catalogOption($item,$data['items'],$summaryTexts,$p,$data,$snapshot['transcript']);
            if ($option===null) { $reason='CATALOG_UNRESOLVED'; return null; }
            $items[]=['product_id'=>$p['id'],'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],'option_id'=>$option];
        }
        if (!$this->completeCart($snapshot['transcript'],$data,$usedCartLines)) { $reason='CATALOG_UNRESOLVED'; return null; }
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

    private function followupService()
    {
        if ($this->table('whatsapp_inbox_messages')!=='whatsapp_inbox_messages'
            ||!class_exists(WhatsAppOrderFollowupService::class)) return null;
        return app(WhatsAppOrderFollowupService::class);
    }

    private function waitingForCustomer(int $conversation,int $analyzed,int $ceiling): bool
    {
        try { $service=$this->followupService(); return $service!==null&&$service->waitingForCustomer($conversation,$analyzed,$ceiling); }
        catch (\Throwable $ignore) { return false; }
    }

    /** Asking for facts is separate from confirming, quoting or dispatching an order. */
    private function requestMissingInformation(object $row,$actor): void
    {
        try {
            $service=$this->followupService(); if ($service===null) return;
            $plan=$this->missingInformationPlan((int)$row->id,$actor);
            if ($plan!==null) $service->request($actor,$plan,fn()=>$this->missingInformationPlan((int)$row->id,$actor));
        } catch (\Throwable $ignore) {
            // A question failure leaves the ordinary review/backoff and all ERP gates intact.
        }
    }

    private function missingInformationPlan(int $id,$actor): ?array
    {
        if (!(bool)$this->orderConfig('whatsapp_orders.enabled',false)||$this->mode()!=='auto'
            ||$this->pendingCapture()) return null;
        $fresh=$this->automaticActor(); if ((int)$fresh->id!==(int)$actor->id) return null;
        $row=$this->draft($id);
        if ($row->status!=='REVIEW'||$row->ticket_id!==null
            ||!in_array($row->reason,['REVIEW_REQUIRED','LOCATION_UNCONFIRMED','BRANCH_UNRESOLVED','PICKUP_REQUIRES_REVIEW'],true)) return null;
        $this->current($row);
        $floor=$this->automaticFloor((int)$row->conversation_id);
        $eventFloor=$this->activationBoundary('activation_event_id'); $activation=$this->activationInstant();
        if ($eventFloor===null||$activation===null||(int)$row->evidence_floor<$floor
            ||(int)$row->evidence_ceiling<=$floor
            ||DB::table($this->table('whatsapp_inbox_ingestion_failures'))->where('event_id','>',$eventFloor)->exists()) return null;
        $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        $data=$this->decode($row->extraction);
        if (!$snapshot['complete']||!is_array($data)) return null;
        $checked=WhatsAppOrderExtraction::validateDetailed($data,$snapshot['transcript']);
        if (!$checked['ok']||!in_array($data['decision']??null,['DRAFT','CONFIRMED'],true)
            ||array_diff($data['issues']??[],['MISSING_CUSTOMER_NAME','MISSING_PHONE','MISSING_ADDRESS','MISSING_AREA',
                'MISSING_BRANCH','MISSING_ITEMS','MISSING_QUANTITY','LOCATION_REQUIRED','UNCONFIRMED','PRICE_ESTIMATE_ONLY'])) return null;
        $transcript=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
        $notBefore=max($activation,$snapshot['recovered_at']??$activation);
        $episode=$this->freshFollowupIntent($data,$transcript,$notBefore);
        if ($episode===null) return null;
        if ($data['decision']==='CONFIRMED'&&!$this->freshAutomaticEvidence($data,$snapshot['transcript'],$notBefore)) return null;
        $latest=null;
        foreach ($transcript as $message) if ($message['speaker']==='customer') $latest=$message;
        if ($latest===null||$latest['sent_at']<$notBefore) return null;
        $customerRow=DB::table($this->table('whatsapp_inbox_messages'))->where('conversation_id',$row->conversation_id)
            ->where('message_key',$snapshot['keys'][$latest['id']])->where('direction','inbound')->first(['id','message_key']);
        if (!$customerRow) return null;
        $fields=[]; $customer=$data['customer'];
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
        $routing=null; $resolved=$this->automaticBranch($data,$fresh,$routing);
        if ($resolved===null&&$fields===[]&&in_array($routing,['MISSING','UNKNOWN_HINT'],true)) $fields[]='BRANCH';
        if ($resolved!==null) {
            $reason=null; if (!$this->branchOpen($this->branch($resolved['fulfillment_branch']??$resolved['value'],$fresh),$reason)) return null;
        }
        if ($data['decision']==='CONFIRMED'&&$row->reason==='LOCATION_UNCONFIRMED'&&!$pickup&&$resolved!==null
            &&!$this->followupHasPoint($data,$transcript,$resolved['fulfillment_branch']??$resolved['value'],$fresh)) $fields[]='LOCATION';
        $needsApproval=$data['decision']==='CONFIRMED'&&$this->followupService()!==null
            &&!$this->followupService()->approvalSatisfied((int)$row->conversation_id,$data,$snapshot);
        if ($fields===[]&&($data['decision']==='DRAFT'||$needsApproval)&&!$pickup&&$resolved!==null) {
            if (!$this->followupItemQuantities($data,$transcript,$resolved['fulfillment_branch']??$resolved['value'])) {
                foreach ($data['items'] as $item) if (($item['cart_reference']??null)!==null) return null;
                $fields[]='QUANTITY';
            }
            else {
                $branch=$resolved['fulfillment_branch']??$resolved['value'];
                if (!$this->followupHasPoint($data,$transcript,$branch,$fresh)) $fields[]='LOCATION';
                elseif ($this->followupCatalogReady($data,$transcript,$branch,$fresh)) $fields[]='CONFIRMATION';
            }
        }
        if ($fields===[]) return null;
        $fields=array_values(array_unique($fields)); sort($fields);
        return ['draft_id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,
            'evidence_floor'=>(int)$row->evidence_floor,'evidence_ceiling'=>(int)$row->evidence_ceiling,
            'evidence_hash'=>$row->evidence_hash,'latest_customer_id'=>(int)$customerRow->id,
            'latest_customer_key'=>$customerRow->message_key,'episode_customer_key'=>$snapshot['keys'][$episode],
            'fields'=>$fields,'data'=>$data,'transcript'=>$snapshot['transcript']];
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
            if (!is_string($text)||preg_match('/[?؟"«»“”]|(?:مش\s+(?:عايز|عاوز)|لا\s+(?:اريد|أريد)|إلغ|الغ|لغي|'
                . 'اعرف|أعرف|بكام|سعر|هل|ينفع|تجاهل|تعليمات|ignore|instructions|cancel|not\s+want)/iu',$text)
                ||!preg_match('/(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمل\s+لي|\b(?:want|order|send)\b)/iu',$text)) continue;
            $named=preg_match('/(?<![\p{L}\p{N}])(?:طلب|الطلب|أوردر|اوردر|الأوردر|الاوردر|order)(?![\p{L}\p{N}])/iu',$text)===1;
            foreach ($data['items'] as $item) if (strpos($text,$item['name'])!==false) $named=true;
            if ($named) { $intent=$message['id']; $position=$index; break; }
        }
        if ($intent===null) return null;
        foreach ($transcript as $index=>$message) if ($index>$position&&$message['speaker']==='customer'
            &&is_string($message['text'])&&preg_match('/(?:مش\s+(?:عايز|عاوز|موافق)|لا\s+(?:أريد|اريد|ترسل)|إلغ|الغ|لغي|'
                . '(?:غير|غيّر|بدل|بدّل)\s+(?:الطلب|الأوردر|الاوردر)|تجاهل|تعليمات|cancel|not\s+want|ignore|instructions|change\s+the\s+order)/iu',$message['text'])) return null;
        return $intent;
    }

    private function followupHasPoint(array $data,array $transcript,string $branch,$actor): bool
    {
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
        if ($data['decision']==='CONFIRMED') return WhatsAppOrderExtraction::validateDetailed($data,$transcript)['ok'];
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
    private function freshAutomaticEvidence(array $data,array $transcript,?string $notBefore=null): bool
    {
        $activation=$this->activationInstant();
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

    private function activationInstant(): ?string
    {
        $value=$this->orderConfig('whatsapp_cart.activated_at');
        if (!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/',$value)) return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        if ($date===false||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0))||$date->format('Y-m-d H:i:s')!==$value||substr($value,0,4)==='0000'||$value>now('UTC')->addSeconds(5)->format('Y-m-d H:i:s')) return null;
        return $value;
    }

    /** Recognized place labels in supplied customer text can select only one authorized branch. */
    private function automaticBranch(array $data,$actor,?string &$routing=null): ?array
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
        $byPlace=$byArea+$byAddress;
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
        $options=$product['options']??[]; if (!is_array($options)) return null;
        $labels=[];
        foreach ($options as $option) {
            if (!is_array($option)||!is_string($option['id']??null)||!is_string($option['label']??null)||$option['label']==='') return null;
            // An explicit weight amount already represents half/quarter kilograms; packaged
            // or meal variants still need their actual catalog identity and option mapping.
            $portion=$this->name($option['label']); $quantity=$this->quantityMillis($item['quantity']??null);
            if (($item['quantity_mode']??null)==='weight'&&!preg_match('/(?:وجبة|وجبات|علبة|عبوة|ساندوتش|سندوتش|ميكس|\b(?:meal|pack|package|box|sandwich)\b)/iu',$product['name'])&&(($portion==='نصف'&&$quantity===500)||($portion==='ربع'&&$quantity===250))) continue;
            $labels[]=$option['label'];
        }
        $proof=($item['conversation_reference']??null)!==null
            ? ($conversation===null?null:WhatsAppOrderConversationEvidence::catalogOptionLabels(
                $item,$conversation,array_column($transcript,null,'id'),$labels))
            : WhatsAppOrderExtraction::optionClauseLabels($item,$allItems,$summaryTexts,$labels);
        if ($proof===null) return null;
        $hint=$item['option_hint']??null;
        if ($hint!==null&&(!is_string($hint)||trim($hint)==='')) return null;
        if ($hint!==null) {
            if (count($proof)!==1||$this->name($proof[0])!==$this->name($hint)) return null;
        } elseif (count($proof)>1) return null;
        if ($proof===[]) return $hint===null?'':null;
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

    private function snapshot(int $conversation, int $floor, int $ceiling,bool $lock=false): array
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
        foreach ($snapshot['transcript'] as $m) if ($m['speaker']==='business' && (!$ids || in_array($m['id'],$ids,true))) $anchor=$snapshot['keys'][$m['id']];
        abort_unless(is_string($anchor),422,'BUSINESS_CONFIRMATION_REQUIRED');
        return hash('sha256','whatsapp-order-confirmation-v1:'.$this->scopeKey($this->conversation((int)$row->conversation_id)).$anchor);
    }

    private function current(object $row,bool $lock=false): void
    {
        $this->conversation((int)$row->conversation_id,$lock);
        abort_unless($this->projectAcceptedReplies((int)$row->conversation_id,$this->mode()==='auto'?$this->activationInstant():null),409,'ACCEPTED_REPLIES_PENDING');
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
    private function automaticActor()
    {
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

    private function activationBoundary(string $name): ?int
    {
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
        $activation=$this->activationBoundary('activation_message_id');
        abort_unless($activation!==null,403,'AUTO_NOT_CONFIGURED');
        if ($scan===null) {
            $query=DB::table(static::SCANS)->where('conversation_id',$conversation);
            if ($lock) $query->lockForUpdate();
            $scan=$query->first();
        }
        return max($activation,(int)($scan->auto_floor??0),$this->dispatchedCeiling($conversation,$lock));
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
    private function projectAcceptedReplies(?int $conversation,?string $acceptedSince): bool
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
    private function ready(): void { abort_unless($this->available(),503,'WHATSAPP_ORDERS_UNAVAILABLE'); }
    private function mode(): string { return $this->orderConfig('whatsapp_orders.mode','review')==='auto'?'auto':'review'; }
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
