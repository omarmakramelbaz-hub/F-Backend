<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Support\WhatsAppOrderExtraction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Suggestions are encrypted; only the existing unpaid phone-order services can dispatch. */
class WhatsAppOrderWorkflow
{
    private const DRAFTS = 'whatsapp_order_drafts';
    private const SCANS = 'whatsapp_order_scans';
    private const MAX_MESSAGES = 60;
    private const MAX_CHARS = 16000;
    private const REASONS = ['CONTEXT_INCOMPLETE', 'AI_UNAVAILABLE', 'INVALID_EXTRACTION', 'STALE_TRANSCRIPT',
        'NO_ORDER', 'CUSTOMER_CANCELLED', 'REVIEW_REQUIRED', 'PICKUP_REQUIRES_REVIEW', 'BRANCH_UNRESOLVED',
        'CATALOG_UNRESOLVED', 'LOCATION_UNCONFIRMED', 'PRICE_REQUIRES_REVIEW', 'CUSTOMER_CHANGE',
        'BRANCH_CLOSED', 'BRANCH_POLICY_UNAVAILABLE', 'AUTO_NOT_CONFIGURED', 'PROCESS_FAILED','CAPTURE_REVIEW_REQUIRED'];

    public function available(): bool
    {
        return app(WhatsAppInboxAccess::class)->available() && Schema::hasTable(self::DRAFTS) && Schema::hasTable(self::SCANS);
    }

    public function state(int $conversation, $actor): array
    {
        $this->actor($actor);
        if (!$this->available()) return ['success'=>true,'available'=>false,'enabled'=>(bool)config('whatsapp_orders.enabled',false),
            'mode'=>$this->mode(),'pending_analysis'=>false,'drafts'=>[]];
        $this->conversation($conversation);
        $scan = DB::table(self::SCANS)->where('conversation_id', $conversation)->first();
        $drafts = DB::table(self::DRAFTS)->where('conversation_id', $conversation)->orderByDesc('id')->limit(10)->get();
        return ['success'=>true, 'available'=>true, 'enabled'=>(bool)config('whatsapp_orders.enabled', false),
            'mode'=>$this->mode(), 'pending_analysis'=>$this->ceiling($conversation) > (int)($scan->analyzed_ceiling ?? 0),
            'drafts'=>$drafts->map(fn($r)=>$this->present($r))->all()];
    }

    public function analyze(int $conversation, $actor, bool $force = false): array
    {
        return $this->analyzeInternal($conversation, $this->actor($actor), $force, false);
    }

    public function quote(int $draft, array $review, $actor): array
    {
        $actor = $this->actor($actor); $this->ready(); $row = $this->draft($draft);
        $v = $this->review($review); $this->mutable($row, $v);
        $this->current($row); $this->branch($v['branch'], $actor);
        $delivery = app(PhoneDelivery::class)->quote($v, $actor)['delivery'];
        $v['delivery_quote_hash'] = $delivery['delivery_quote_hash'];
        $quote = app(PosServiceTicket::class)->quote('phone', $v, $actor);
        $v['quote_hash'] = $quote['quote_hash'];
        $sealed = ['review'=>$v, 'quote'=>$quote, 'delivery'=>$delivery];
        DB::transaction(function () use ($draft, $actor, $v, $sealed) {
            $this->actor($actor);
            $row = $this->draft($draft, true); $this->mutable($row, $v); $this->current($row);
            $key = $this->confirmationKey($row);
            // A business confirmation can have only one ERP order, across actors and branches.
            $other = DB::table(self::DRAFTS)->where('confirmation_key', $key)->where('id', '!=', $draft)->lockForUpdate()->first();
            if ($other && $other->status!=='DISPATCHED' && (int)$other->conversation_id===(int)$row->conversation_id
                && $this->ceiling((int)$other->conversation_id)!==(int)$other->evidence_ceiling) {
                DB::table(self::DRAFTS)->where('id',$other->id)->update(['confirmation_key'=>null,'sealed_payload'=>null,
                    'status'=>'REVIEW','reason'=>'STALE_TRANSCRIPT','revision'=>(int)$other->revision+1,'updated_at'=>now('UTC')]);
                $other=null;
            }
            abort_if($other !== null, 409, 'ORDER_ALREADY_REVIEWED');
            $customer=DB::table('branch_customers')->where('branch',$v['branch'])->where('phone_key',BranchCustomers::phoneKey($v['customer_phone']))->first();
            $sealed['customer_version']=$customer ? ['id'=>(int)$customer->id,'revision'=>(int)$customer->revision] : null;
            DB::table(self::DRAFTS)->where('id', $draft)->update(['confirmation_key'=>$key, 'sealed_payload'=>$this->encrypt($sealed),
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
            'dispatched'=>0, 'replayed'=>0, 'errors'=>0, 'disabled'=>0];
        if (!(bool)config('whatsapp_orders.enabled', false) || !$this->available()) { $metrics['disabled']=1; return $metrics; }
        try { $actor = $this->automaticActor(); }
        catch (\Throwable $error) { $metrics['disabled']=1; return $metrics; }
        $activation=config('whatsapp_orders.activation_message_id');
        if (!is_numeric($activation)||(string)(int)$activation!==(string)$activation||(int)$activation<0) { $metrics['disabled']=1; return $metrics; }
        $activation=(int)$activation;
        $rows = DB::table('whatsapp_inbox_conversations as c')
            ->join('whatsapp_inbox_messages as m', 'm.conversation_id', '=', 'c.id')
            ->leftJoin(self::SCANS.' as s', 's.conversation_id', '=', 'c.id')
            ->where('c.waba_id', WhatsAppInboxAccess::WABA_ID)->where('c.phone_number_id', WhatsAppInboxAccess::PHONE_ID)
            ->where(function ($q) { $q->whereNull('s.next_attempt_at')->orWhere('s.next_attempt_at', '<=', now('UTC')); })
            ->select('c.id', 's.analyzed_ceiling', 's.auto_floor', 's.claimed_ceiling', 's.attempts')->selectRaw('MAX(m.id) AS ceiling')
            ->groupBy('c.id', 's.analyzed_ceiling', 's.auto_floor', 's.claimed_ceiling', 's.attempts')
            ->havingRaw('MAX(m.id) > COALESCE(s.analyzed_ceiling, ?)',[$activation])
            ->havingRaw('(MAX(m.id) != COALESCE(s.claimed_ceiling, 0) OR COALESCE(s.attempts, 0) < 3)')
            ->orderBy('c.id')->limit($limit)->get();
        foreach ($rows as $thread) {
            $metrics['threads_seen']++;
            try {
                if ($thread->auto_floor === null) {
                    DB::transaction(function () use ($thread,$activation) {
                        $this->scanInsert((int)$thread->id);
                        $scan = DB::table(self::SCANS)->where('conversation_id', $thread->id)->lockForUpdate()->first();
                        if ($scan->auto_floor === null && !$scan->claim_nonce) DB::table(self::SCANS)->where('conversation_id', $thread->id)
                            ->update(['auto_floor'=>$activation, 'analyzed_ceiling'=>min($activation,(int)$thread->ceiling), 'updated_at'=>now('UTC')]);
                    }, 3);
                    $metrics['baselined']++;
                }
                $result = $this->analyzeInternal((int)$thread->id, $actor, false, true); $metrics['analyzed']++;
                $draft = $this->draft((int)$result['draft']['id']);
                if (in_array($draft->status,['NONE','CANCELLED'],true)) continue;
                if ($draft->reason==='AI_UNAVAILABLE') { $metrics['review_required']++; continue; }
                if ($this->mode() !== 'auto') { $metrics['review_required']++; continue; }
                $reason = null; $review = $this->automaticReview($draft, $actor, $reason);
                if ($review === null) { $this->reviewReason($draft, $reason ?? 'REVIEW_REQUIRED'); $metrics['review_required']++; continue; }
                $quoted = $this->quote((int)$draft->id, $review, $actor);
                $review = $quoted['draft']['review']; $review['expected_revision']=$quoted['draft']['revision'];
                $extraction = $this->decode($draft->extraction);
                if ($this->minor($extraction['approximate_total']??null) === null || $this->minor($extraction['approximate_total']) !== $this->minor($quoted['quote']['total']??null)) {
                    $this->reviewReason($this->draft((int)$draft->id), 'PRICE_REQUIRES_REVIEW'); $metrics['review_required']++; continue;
                }
                $sent = $this->dispatchInternal((int)$draft->id, $review, $this->automaticActor(), true);
                $metrics[$sent['replayed'] ? 'replayed' : 'dispatched']++;
            } catch (\Throwable $error) { $metrics['errors']++; }
        }
        return $metrics;
    }

    private function analyzeInternal(int $conversation, $actor, bool $force, bool $automatic): array
    {
        $this->ready(); $this->conversation($conversation);
        $claim = DB::transaction(function () use ($conversation, $force, $automatic) {
            $this->scanInsert($conversation);
            $scan = DB::table(self::SCANS)->where('conversation_id', $conversation)->lockForUpdate()->first();
            $ceiling = $this->ceiling($conversation); abort_unless($ceiling > 0, 422, 'EMPTY_CONVERSATION');
            $existing = DB::table(self::DRAFTS)->where('conversation_id', $conversation)->where('evidence_ceiling', $ceiling)->first();
            $retry=$automatic && $existing && $existing->reason==='AI_UNAVAILABLE' && (int)$scan->attempts<3;
            if ($existing && ((!$force&&!$retry) || $existing->status==='DISPATCHED')) return ['existing'=>$existing];
            abort_if($scan->claim_nonce && $scan->lease_until > now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_BUSY');
            if (!$force) abort_if($scan->next_attempt_at && $scan->next_attempt_at > now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_BACKOFF');
            $attempts = (int)$scan->claimed_ceiling === $ceiling ? (int)$scan->attempts+1 : 1;
            abort_if($attempts > 3 && !$force, 409, 'ANALYSIS_RETRY_LIMIT');
            $last = (int)DB::table(self::DRAFTS)->where('conversation_id', $conversation)->where('status', 'DISPATCHED')->max('evidence_ceiling');
            $floor = max($last, $automatic ? (int)($scan->auto_floor ?? $ceiling) : 0);
            $nonce = bin2hex(random_bytes(32));
            DB::table(self::SCANS)->where('conversation_id', $conversation)->update(['claimed_ceiling'=>$ceiling, 'claim_nonce'=>$nonce,
                'lease_until'=>now('UTC')->addSeconds(180), 'attempts'=>min(3,$attempts), 'next_attempt_at'=>null, 'updated_at'=>now('UTC')]);
            return ['nonce'=>$nonce, 'floor'=>$floor, 'ceiling'=>$ceiling, 'existing_id'=>$existing ? (int)$existing->id : null];
        }, 3);
        if (isset($claim['existing'])) return ['success'=>true, 'draft'=>$this->present($claim['existing'])];
        try {
            $snapshot = $this->snapshot($conversation, $claim['floor'], $claim['ceiling']);
            $result = $snapshot['complete'] ? app(WhatsAppOrderAiProvider::class)->extract($snapshot['transcript'])
                : ['ok'=>false, 'reason'=>'CONTEXT_INCOMPLETE', 'data'=>null];
            if (!is_array($result) || !($result['ok'] ?? false)) {
                $data = null; $reason = ($result['reason'] ?? null) === 'CONTEXT_INCOMPLETE' ? 'CONTEXT_INCOMPLETE' : 'AI_UNAVAILABLE';
                $status='REVIEW';
            } else {
                $checked = WhatsAppOrderExtraction::validate($result['data'], $snapshot['transcript']);
                $data = ($checked['ok'] ?? false) ? $checked['data'] : null;
                $reason = $data === null ? 'INVALID_EXTRACTION' : 'REVIEW_REQUIRED'; $status='REVIEW';
                if (($data['decision'] ?? '') === 'NONE') { $status='NONE'; $reason='NO_ORDER'; }
                if (($data['decision'] ?? '') === 'CANCELLED') { $status='CANCELLED'; $reason='CUSTOMER_CANCELLED'; }
            }
            $id = DB::transaction(function () use ($conversation, $actor, $claim, $snapshot, $data, $reason, $status,$automatic) {
                $this->actor($actor);
                $scan = DB::table(self::SCANS)->where('conversation_id', $conversation)->lockForUpdate()->first();
                abort_unless($scan && hash_equals((string)$scan->claim_nonce, $claim['nonce']) && $scan->lease_until >= now('UTC')->format('Y-m-d H:i:s'), 409, 'ANALYSIS_LEASE_LOST');
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
                    DB::table(self::DRAFTS)->where('id',$old->id)->update($values); $id=(int)$old->id;
                } else $id=DB::table(self::DRAFTS)->insertGetId($values);
                DB::table(self::SCANS)->where('conversation_id',$conversation)->update(['analyzed_ceiling'=>$reason==='AI_UNAVAILABLE'&&$automatic?$claim['floor']:$claim['ceiling'],
                    'claim_nonce'=>null,'lease_until'=>null,'next_attempt_at'=>$reason==='AI_UNAVAILABLE'&&$automatic?now('UTC')->addSeconds(60):null,'updated_at'=>now('UTC')]);
                return $id;
            }, 3);
            return ['success'=>true,'draft'=>$this->present($this->draft($id))];
        } catch (\Throwable $error) {
            // The lease can only be released by its holder. Exception content is never recorded.
            DB::table(self::SCANS)->where('conversation_id',$conversation)->where('claim_nonce',$claim['nonce'])
                ->update(['claim_nonce'=>null,'lease_until'=>null,'next_attempt_at'=>now('UTC')->addSeconds(60),'updated_at'=>now('UTC')]);
            throw $error;
        }
    }

    private function dispatchInternal(int $draft, array $review, $actor, bool $automatic): array
    {
        $this->ready(); $v = $this->review($review, true);
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
            $this->current($row,true); $this->branch($v['branch'],$actor,true);
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
            if ($automatic && $customer) abort_unless($this->sameCustomer($customer,$v),409,'CUSTOMER_CHANGE');
            $customerValues=['branch'=>$v['branch'],'idempotency_key'=>$row->customer_command_key,'name'=>$v['customer_name'],
                'phone'=>$v['customer_phone'],'address'=>$v['address'],'area'=>$v['area'],'delivery_notes'=>$v['delivery_notes'],
                'latitude'=>$v['latitude'],'longitude'=>$v['longitude']];
            if ($customer) $customerValues+=['customer_id'=>(int)$customer->id,'expected_revision'=>(int)$customer->revision];
            $saved=$automatic&&$customer ? ['customer'=>['id'=>(int)$customer->id]] : app(BranchCustomers::class)->save($customerValues,$actor);
            $payload=$v; unset($payload['expected_revision']);
            $payload+=['idempotency_key'=>$row->ticket_command_key,'customer_id'=>(int)$saved['customer']['id'],
                'send_to_kitchen'=>true,'discount'=>'0.00','discount_reason'=>''];
            $result=app(PosServiceTicket::class)->save('phone',$payload,$actor);
            $ticket=(int)($result['ticket']['id'] ?? $result['operation']['ticket_id'] ?? 0); abort_unless($ticket>0,500,'DISPATCH_FAILED');
            DB::table(self::DRAFTS)->where('id',$draft)->update(['status'=>'DISPATCHED','reason'=>null,'revision'=>(int)$row->revision+1,
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
            'customer_name'=>'required|string|max:100','customer_phone'=>['required','string','max:30','regex:/^[+0-9 ()-]{6,30}$/D'],
            'address'=>'required|string|max:500','area'=>'nullable|string|max:150','delivery_notes'=>'nullable|string|max:500',
            'latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','location_confirmed'=>'required|accepted',
            'items'=>'required|array|min:1|max:100','items.*.product_id'=>'required|integer|min:1',
            'items.*.quantity'=>'required|string|regex:/^[0-9]{1,4}(?:\.[0-9]{1,3})?$/D','items.*.quantity_mode'=>'required|in:piece,weight',
            'items.*.option_id'=>'nullable|string|max:80','items.*.feature_id'=>'nullable|integer|min:0',
            'items.*.product_clean'=>'nullable|in:extra_clear,extra_clean,extra_vacuim'];
        if ($hashes) $rules+=['quote_hash'=>'required|string|size:64|regex:/^[a-f0-9]+$/D','delivery_quote_hash'=>'required|string|size:64|regex:/^[a-f0-9]+$/D'];
        $v=Validator::make($values,$rules)->validate();
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
        $eventFloor=config('whatsapp_orders.activation_event_id');
        if (!is_numeric($eventFloor)||(string)(int)$eventFloor!==(string)$eventFloor||(int)$eventFloor<0) { $reason='AUTO_NOT_CONFIGURED'; return null; }
        $failures=DB::table('whatsapp_inbox_ingestion_failures')->where('event_id','>',(int)$eventFloor);
        if ($lock) $failures->lockForUpdate();
        if ($failures->first(['event_id'])) { $reason='CAPTURE_REVIEW_REQUIRED'; return null; }
        $data=$this->decode($row->extraction); $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        if (!$snapshot['complete'] || !$data || ($data['decision']??null)!=='CONFIRMED' || ($data['issues']??[])!==[]) { $reason='REVIEW_REQUIRED'; return null; }
        if (($data['fulfillment']??null)!=='DELIVERY') { $reason='PICKUP_REQUIRES_REVIEW'; return null; }
        $branches=array_values(array_filter(app(TakeawayAccess::class)->branches($actor),function ($b) use ($data) {
            return $b['kind']==='f' && $this->allowed($b['value']) && $this->name($b['name'])===$this->name((string)($data['branch_hint']??''));
        }));
        if (count($branches)!==1) { $reason='BRANCH_UNRESOLVED'; return null; } $branch=$branches[0]['value'];
        if (!$this->branchOpen($branches[0],$reason,$lock)) return null;
        $customer=$data['customer']??[];
        foreach (['name','phone','address'] as $k) if (!is_string($customer[$k]??null)||trim($customer[$k])==='') { $reason='REVIEW_REQUIRED'; return null; }
        $location=null;
        $locationTime=null; $addressTime=null; $lastLocation=null; $summaryAddress=false;
        foreach ($snapshot['transcript'] as $m) {
            if ($m['speaker']==='customer' && is_string($m['text']) && strpos($m['text'],$customer['address'])!==false) $addressTime=$m['sent_at'];
            if ($m['speaker']==='customer' && $m['location']!==null) $lastLocation=$m['id'];
            if ($m['id']===($data['evidence']['location_id']??null)&&$m['speaker']==='customer') { $location=$m['location']; $locationTime=$m['sent_at']; }
            if ($m['speaker']==='business' && in_array($m['id'],$data['evidence']['confirmation_ids']??[],true)
                && is_string($m['text']) && strpos($m['text'],$customer['address'])!==false) $summaryAddress=true;
        }
        if (!$location || !$addressTime || $locationTime<$addressTime || $lastLocation!==($data['evidence']['location_id']??null) || !$summaryAddress) {
            $reason='LOCATION_UNCONFIRMED'; return null;
        }
        $items=[]; $catalog=[];
        for ($page=1;$page<=3;$page++) {
            $list=app(TakeawayCatalog::class)->listing(['branch'=>$branch,'page'=>$page,'per_page'=>100],$actor);
            $catalog=array_merge($catalog,$list['items']);
            if ((int)($list['pagination']['last_page']??1)<=$page) break;
            if ($page===3) { $reason='CATALOG_UNRESOLVED'; return null; }
        }
        foreach ($data['items'] as $item) {
            $matches=array_values(array_filter($catalog,fn($p)=>($p['available']??false)&&$this->name($p['name'])===$this->name($item['name'])));
            if (count($matches)!==1 || !is_string($item['quantity']??null) || !in_array($item['quantity_mode'],['piece','weight'],true)
                || ($matches[0]['quantity_mode']??'select')!==$item['quantity_mode']) { $reason='CATALOG_UNRESOLVED'; return null; }
            $p=$matches[0]; $option='';
            if (($item['option_hint']??null)!==null && trim($item['option_hint'])!=='') {
                $options=array_values(array_filter($p['options']??[],fn($o)=>$this->name($o['label'])===$this->name($item['option_hint'])));
                if (count($options)!==1) { $reason='CATALOG_UNRESOLVED'; return null; } $option=$options[0]['id'];
            }
            $items[]=['product_id'=>$p['id'],'quantity'=>$item['quantity'],'quantity_mode'=>$item['quantity_mode'],'option_id'=>$option];
        }
        $v=['expected_revision'=>(int)$row->revision,'branch'=>$branch,'customer_name'=>$customer['name'],'customer_phone'=>$customer['phone'],
            'address'=>$customer['address'],'area'=>$customer['area']??'','delivery_notes'=>$customer['notes']??'',
            'latitude'=>$location['lat'],'longitude'=>$location['long'],'location_confirmed'=>true,'items'=>$items];
        $v=$this->review($v); $saved=DB::table('branch_customers')->where('branch',$branch)->where('phone_key',BranchCustomers::phoneKey($v['customer_phone']))->first();
        if ($saved&&!$this->sameCustomer($saved,$v)) { $reason='CUSTOMER_CHANGE'; return null; }
        return $v;
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
        $query=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('id','>',$floor)->where('id','<=',$ceiling)
            ->orderByDesc('id')->limit(self::MAX_MESSAGES+1);
        if ($lock) $query->lockForUpdate(); $rows=$query->get()->all();
        $complete=count($rows)<=self::MAX_MESSAGES; $rows=array_slice($rows,0,self::MAX_MESSAGES);
        usort($rows,fn($a,$b)=>strcmp((string)$a->sent_at,(string)$b->sent_at)?:((int)$a->id<=>(int)$b->id));
        $transcript=[]; $keys=[]; $hash=[]; $chars=0;
        foreach ($rows as $i=>$row) {
            $dto=$this->decode($row->content); abort_unless(is_array($dto),500,'INVALID_INBOX_MESSAGE');
            $messageId=$dto['message_id']??null; $identity=$dto['peer_identity']??null;
            abort_unless(is_string($messageId)&&$messageId!==''&&is_string($identity)&&$identity!==''
                &&($dto['direction']??null)===$row->direction&&($dto['type']??null)===$row->type&&($dto['sent_at']??null)===$row->sent_at
                &&hash_equals($row->message_key,hash('sha256','whatsapp-message-v1:'.WhatsAppInboxAccess::WABA_ID.':'.WhatsAppInboxAccess::PHONE_ID.':'.$messageId))
                &&hash_equals($thread->peer_hash,hash_hmac('sha256','whatsapp-peer-v1:'.WhatsAppInboxAccess::WABA_ID.':'.WhatsAppInboxAccess::PHONE_ID.':'.$identity,Crypt::getKey()))
                &&is_array($dto['content']['message']??null),500,'INVALID_INBOX_MESSAGE');
            $speaker=$row->direction==='inbound'?'customer':($row->direction==='outbound'?'business':null);
            abort_unless($speaker!==null && is_string($row->sent_at) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',$row->sent_at),500,'INVALID_INBOX_MESSAGE');
            $text=is_string($dto['text']??null)?$dto['text']:null; $location=null;
            if ($row->type==='location' && isset($dto['content']['message']['location']['latitude'],$dto['content']['message']['location']['longitude'])) {
                $l=$dto['content']['message']['location']; $location=['lat'=>(float)$l['latitude'],'long'=>(float)$l['longitude']];
                if (abs($location['lat'])>90||abs($location['long'])>180) $location=null;
            }
            $chars+=$text===null?0:mb_strlen($text,'UTF-8');
            $id='m'.($i+1); $transcript[]=['id'=>$id,'speaker'=>$speaker,'sent_at'=>$row->sent_at,'text'=>$text,'location'=>$location];
            $keys[$id]=$row->message_key; $hash[]=[(int)$row->id,$row->message_key,$row->direction,$row->type,$row->sent_at,$text,$location];
        }
        if ($chars>self::MAX_CHARS) $complete=false;
        return ['transcript'=>$transcript,'keys'=>$keys,'hash'=>hash_hmac('sha256',PosServiceTicket::fingerprint($hash),Crypt::getKey()),'complete'=>$complete];
    }

    private function confirmationKey(object $row): string
    {
        $snapshot=$this->snapshot((int)$row->conversation_id,(int)$row->evidence_floor,(int)$row->evidence_ceiling);
        $data=$this->decode($row->extraction); $ids=$data['evidence']['confirmation_ids']??[]; $anchor=null;
        foreach ($snapshot['transcript'] as $m) if ($m['speaker']==='business' && (!$ids || in_array($m['id'],$ids,true))) $anchor=$snapshot['keys'][$m['id']];
        abort_unless(is_string($anchor),422,'BUSINESS_CONFIRMATION_REQUIRED');
        return hash('sha256','whatsapp-order-confirmation-v1:'.WhatsAppInboxAccess::WABA_ID.':'.WhatsAppInboxAccess::PHONE_ID.':'.$anchor);
    }

    private function current(object $row,bool $lock=false): void
    {
        $this->conversation((int)$row->conversation_id,$lock);
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
        $fresh=app(WhatsAppInboxAccess::class)->actor($actor);
        abort_unless(app(TakeawayAccess::class)->permissions($fresh)['can_checkout'],403,'ORDER_ACCESS_DENIED');
        return $fresh;
    }
    private function automaticActor()
    {
        $id=config('whatsapp_orders.automation_actor_id');
        abort_unless((bool)config('whatsapp_orders.enabled',false)&&is_numeric($id)&&(int)$id>0,403,'AUTO_NOT_CONFIGURED');
        abort_unless(is_string(config('whatsapp_orders.model'))&&trim(config('whatsapp_orders.model'))!==''&&is_string(config('whatsapp_orders.api_key'))&&config('whatsapp_orders.api_key')!=='',403,'AUTO_NOT_CONFIGURED');
        $actor=User::withoutGlobalScopes()->find((int)$id); return $this->actor($actor);
    }
    private function branch(string $branch,$actor,bool $lock=false): array
    {
        $b=app(TakeawayAccess::class)->branch($branch,$actor,$lock); abort_unless($b['kind']==='f',403,'INVALID_BRANCH'); return $b;
    }
    private function allowed(string $branch): bool
    {
        $ids=config('whatsapp_orders.allowed_branch_ids',[]);
        return is_array($ids)&&in_array(substr($branch,2),array_map('strval',$ids),true);
    }
    private function sameCustomer(object $row,array $v): bool
    {
        return BranchCustomers::phoneKey($row->phone)===BranchCustomers::phoneKey($v['customer_phone'])
            && trim($row->name)===$v['customer_name']&&trim($row->address)===$v['address'];
    }
    private function name(string $value): string { return mb_strtolower(trim(preg_replace('/\s+/u',' ',$value)),'UTF-8'); }
    private function reviewHash(array $v,bool $hashes=true): string
    {
        unset($v['expected_revision']); if (!$hashes) unset($v['quote_hash'],$v['delivery_quote_hash']); return PosServiceTicket::fingerprint($v);
    }
    private function scanInsert(int $conversation): void
    {
        DB::table(self::SCANS)->insertOrIgnore(['conversation_id'=>$conversation,'analyzed_ceiling'=>0,'claimed_ceiling'=>0,'attempts'=>0,'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    private function ceiling(int $conversation,bool $lock=false): int
    {
        if (!$lock) return (int)DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->max('id');
        $row=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->orderByDesc('id')->lockForUpdate()->first(['id']);
        return (int)($row->id??0);
    }
    private function conversation(int $id,bool $lock=false): object
    {
        $query=DB::table('whatsapp_inbox_conversations')->where('id',$id)->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID);
        if ($lock) $query->lockForUpdate(); $row=$query->first();
        abort_unless($row,404,'CONVERSATION_NOT_FOUND'); return $row;
    }
    private function draft(int $id,bool $lock=false): object
    {
        $q=DB::table(self::DRAFTS)->where('id',$id); if ($lock) $q->lockForUpdate(); $row=$q->first();
        abort_unless($row,404,'DRAFT_NOT_FOUND'); $this->conversation((int)$row->conversation_id); return $row;
    }
    private function present(object $row): array
    {
        $sealed=$this->decode($row->sealed_payload);
        return ['id'=>(int)$row->id,'conversation_id'=>(int)$row->conversation_id,'revision'=>(int)$row->revision,
            'status'=>$row->status,'reason'=>$row->reason,'data'=>$this->decode($row->extraction),'review'=>$sealed['review']??null,
            'quote'=>$sealed['quote']??null,'delivery'=>$sealed['delivery']??null,'ticket_id'=>$row->ticket_id===null?null:(int)$row->ticket_id,
            'evidence_ceiling'=>(int)$row->evidence_ceiling];
    }
    private function reviewReason(object $row,string $reason): void
    {
        abort_unless(in_array($reason,self::REASONS,true),500,'INVALID_REASON');
        DB::table(self::DRAFTS)->where('id',$row->id)->whereNotIn('status',['DISPATCHED','NONE','CANCELLED'])->update(['status'=>'REVIEW','reason'=>$reason,'updated_at'=>now('UTC')]);
    }
    private function minor($value): ?int
    {
        if (!is_string($value)||!preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/',$value)) return null;
        [$whole,$fraction]=array_pad(explode('.',$value),2,''); return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }
    private function uuid(): string
    {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40); $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
    private function pendingCapture(bool $lock=false): bool
    {
        if (!Schema::hasTable('whatsapp_webhook_events')) return true;
        $query=DB::table('whatsapp_webhook_events as e')->whereNull('e.processed_at')->whereNotExists(function ($q) {
            $q->select(DB::raw('1'))->from('whatsapp_inbox_ingestion_failures as f')->whereColumn('f.event_id','e.id');
        });
        if ($lock) $query->lockForUpdate(); return $query->first(['e.id'])!==null;
    }
    private function ready(): void { abort_unless($this->available(),503,'WHATSAPP_ORDERS_UNAVAILABLE'); }
    private function mode(): string { return config('whatsapp_orders.mode','review')==='auto'?'auto':'review'; }
    private function encrypt(array $value): string { return Crypt::encryptString(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
    private function decode($value): ?array
    {
        if ($value===null) return null;
        $decoded=json_decode(Crypt::decryptString($value),true,512,JSON_THROW_ON_ERROR);
        abort_unless(is_array($decoded),500,'INVALID_ENCRYPTED_ORDER'); return $decoded;
    }
}
