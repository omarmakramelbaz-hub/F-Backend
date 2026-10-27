<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{Crypt,DB,Validator};

/** Native-only effect lifecycle. A committed burn is never replayable as permission. */
final class DesktopDashboardPrintEffects
{
    public function __construct(private DesktopDashboardPrintLedger $ledger,private DesktopDashboardPrintLedgerSchema $schema,
        private DesktopDashboardPrintAuthority $authority) {}

    public function listing(string $device,array $values=[]): array
    {
        return $this->transaction($device,function($actor)use($device,$values){
            if(isset($values['native_session_id'])){
                Validator::make($values,['native_session_id'=>'required|uuid'])->validate();
                foreach($this->attempts($device,true)->where('a.native_session_id','!=',$values['native_session_id'])->whereIn('a.state',['claimed','burnt'])->limit(100)->get(['a.*']) as $old){
                    if(app(DesktopDashboardWorkspacePrintAuthority::class)->selected() && (int)$old->actor_id!==(int)$actor->id)continue;
                    $intent=$this->descriptor($device,$old->effect_id);if((int)$intent['actor_id']!==(int)$actor->id)continue;
                    $review=['effect_id'=>$old->effect_id,'enrollment_id'=>$intent['enrollment_id'],'origin_command_id'=>$intent['origin_command_id'],'actor_id'=>(int)$actor->id,'branch'=>$intent['branch'],
                        'revision'=>$intent['revision'],'claim_token'=>$old->claim_token,'native_session_id'=>$old->native_session_id,'invocation_id'=>$old->invocation_id!==''?$old->invocation_id:$old->claim_token,
                        'permit_hash'=>$old->permit_hash!==''?$old->permit_hash:hash('sha256','unissued:'.$old->effect_id),'state'=>'review','reason'=>'previous-native-session'];
                    DB::table($this->schema->table('print_ledger_attempts'))->where('effect_id',$old->effect_id)->update(['state'=>'review','invocation_id'=>$review['invocation_id'],'permit_hash'=>$review['permit_hash'],
                        'outcome_cipher'=>Crypt::encryptString($this->canonical($review)),'updated_at'=>now('UTC')]);
                }
            }
            $effects=[];$attention=$this->attempts($device,true)->whereIn('a.state',['claimed','burnt','review','expired'])->count();
            foreach(DB::table($this->schema->table('print_ledger_intents').' as i')->where('i.device_id',$device)->when(($values['include_manual']??false)!==true,fn($query)=>$query->whereIn('i.kind',['phone_kitchen','dining_kitchen','shift_report']))->whereNotExists(function($query){$query->selectRaw('1')->from($this->schema->table('print_ledger_attempts').' as a')->whereColumn('a.effect_id','i.effect_id');})->orderBy('i.created_at')->limit(100)->get(['i.*']) as $row){
                if(app(DesktopDashboardWorkspacePrintAuthority::class)->selected() && (int)$row->actor_id!==(int)$actor->id)continue;
                $intent=$this->descriptor($device,$row->effect_id);if(!$this->eligible($intent,$actor))continue;
                $attempt=DB::table($this->schema->table('print_ledger_attempts'))->where('effect_id',$row->effect_id)->first();
                if(!$attempt&&$intent['kind']==='sale_receipt'&&($intent['snapshot']['grant']['expires_at']??0)<=now('UTC')->getTimestamp()){
                    DB::table($this->schema->table('print_ledger_attempts'))->insert(['effect_id'=>$intent['effect_id'],'claim_token'=>'','native_session_id'=>'','actor_id'=>$actor->id,'branch'=>$intent['branch'],
                        'render_sha256'=>'','invocation_id'=>'','permit_hash'=>'','state'=>'expired','outcome_cipher'=>'','server_ack_hash'=>'','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
                    $attention++;continue;}
                if(!$attempt)$effects[]=array_intersect_key($intent,array_flip(['effect_id','kind','branch','actor_id','origin_command_id','revision']));
                elseif(in_array($attempt->state,['claimed','burnt','review'],true))$attention++;
            }
            return ['effects'=>$effects,'attention'=>$attention];
        });
    }

    public function prepare(string $device,array $values): array
    {
        $v=Validator::make($values,['effect_id'=>'required|regex:/^[a-f0-9]{64}$/D','claim_token'=>'required|uuid','native_session_id'=>'required|uuid'])->validate();
        return $this->transaction($device,function($actor)use($device,$v){
            $intent=$this->descriptor($device,$v['effect_id']);$this->authorize($intent,$actor);
            $html=$this->render($intent);$hash=hash('sha256',$html);$table=DB::table($this->schema->table('print_ledger_attempts'));
            $old=(clone $table)->where('effect_id',$v['effect_id'])->lockForUpdate()->first();
            if($old)abort_unless($old->state==='claimed'&&$old->claim_token===$v['claim_token']&&$old->native_session_id===$v['native_session_id']
                &&hash_equals($old->render_sha256,$hash),409,'الطباعة السابقة تحتاج مراجعة ولا تُعاد تلقائيًا.');
            else $table->insert(['effect_id'=>$v['effect_id'],'claim_token'=>$v['claim_token'],'native_session_id'=>$v['native_session_id'],'actor_id'=>$actor->id,
                'branch'=>$intent['branch'],'render_sha256'=>$hash,'invocation_id'=>'','permit_hash'=>'','state'=>'claimed','outcome_cipher'=>'','server_ack_hash'=>'','created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return array_intersect_key($intent,array_flip(['effect_id','device_id','enrollment_id','origin_command_id','kind','actor_id','branch','revision','snapshot_sha256']))
                +['html'=>$html,'render_sha256'=>$hash,'expires_at'=>$intent['snapshot']['grant']['expires_at']??null];
        });
    }

    public function burn(string $device,array $values): array
    {
        $v=$this->validateAttempt($values,true);
        return $this->transaction($device,function($actor)use($device,$v){
            $intent=$this->descriptor($device,$v['effect_id']);$this->authorize($intent,$actor);$this->binding($intent,$v);
            $table=DB::table($this->schema->table('print_ledger_attempts'));$row=(clone $table)->where('effect_id',$v['effect_id'])->lockForUpdate()->first();
            abort_unless($row&&$row->state==='claimed'&&$row->claim_token===$v['claim_token']&&$row->native_session_id===$v['native_session_id']
                &&hash_equals($row->render_sha256,$v['render_sha256'])&&hash_equals($v['render_sha256'],hash('sha256',$this->render($intent))),409,
                'صلاحية الطباعة استُهلكت أو تحتاج مراجعة.');
            // Consume original first-print grant in the SAME transaction as the durable burn.
            // Retained generations no longer contain the old till row; the permanent effect
            // remains the authority, and central replay suppresses its independent grant.
            if($intent['kind']==='sale_receipt'&&empty($intent['reprint'])){
                $grant=$intent['snapshot']['grant'];abort_unless($grant['expires_at']>now('UTC')->getTimestamp(),403,'انتهت صلاحية الطباعة الأولى؛ اطلب نسخة من الإدارة.');
                if($intent['origin_database']===DB::connection()->getDatabaseName()){
                    $entry=DB::table('takeaway_till_entries')->where('id',$intent['local_references']['sale_till_entry.primary'])->lockForUpdate()->first();
                    $metadata=json_decode($entry->metadata??'{}',true);$stored=$metadata['receipt_print']??[];
                    abort_unless(($stored['nonce']??null)===$grant['nonce']&&($stored['expires_at']??null)===$grant['expires_at']&&($stored['consumed_at']??null)===null,409);
                    $metadata['receipt_print']['consumed_at']=now('UTC')->toIso8601String();DB::table('takeaway_till_entries')->where('id',$entry->id)->update(['metadata'=>json_encode($metadata)]);
                }
            }
            $permit=bin2hex(random_bytes(32));$table->where('effect_id',$v['effect_id'])->update(['state'=>'burnt','invocation_id'=>$v['invocation_id'],
                'permit_hash'=>hash('sha256',$permit),'updated_at'=>now('UTC')]);
            return ['effect_id'=>$v['effect_id'],'invocation_id'=>$v['invocation_id'],'permit'=>$permit,'render_sha256'=>$v['render_sha256']];
        });
    }

    public function outcome(string $device,array $values): array
    {
        $v=$this->validateAttempt($values,false);$v+=Validator::make($values,['permit_hash'=>'required|regex:/^[a-f0-9]{64}$/D','state'=>'required|in:invoked,review','reason'=>'present|string|max:200'])->validate();
        return $this->transaction($device,function($actor)use($device,$v){
            $intent=$this->descriptor($device,$v['effect_id']);$this->binding($intent,$v);
            $row=DB::table($this->schema->table('print_ledger_attempts'))->where('effect_id',$v['effect_id'])->lockForUpdate()->first();
            abort_unless($row&&(int)$row->actor_id===(int)$actor->id&&$row->claim_token===$v['claim_token']&&$row->native_session_id===$v['native_session_id']
                &&$row->invocation_id===$v['invocation_id']&&hash_equals($row->permit_hash,$v['permit_hash']),403);
            $receipt=$v;unset($receipt['action']);
            if($row->outcome_cipher!=='')abort_unless($this->canonical(json_decode(Crypt::decryptString($row->outcome_cipher),true))===$this->canonical($receipt),409,'نتيجة الطباعة مسجلة ولا يمكن استبدالها.');
            else {abort_unless($row->state==='burnt',409);DB::table($this->schema->table('print_ledger_attempts'))->where('effect_id',$v['effect_id'])->update([
                'state'=>$v['state'],'outcome_cipher'=>Crypt::encryptString($this->canonical($receipt)),'updated_at'=>now('UTC')]);}
            return ['effect_id'=>$v['effect_id'],'invocation_id'=>$v['invocation_id'],'state'=>$v['state'],'receipt'=>$receipt,'receipt_sha256'=>hash('sha256',$this->canonical($receipt))];
        },false);
    }

    public function review(string $device,array $values): array
    {
        $v=$this->validateAttempt($values,false);$v+=Validator::make($values,['reason'=>'present|string|max:200'])->validate();
        return $this->transaction($device,function($actor)use($device,$v){
            $intent=$this->descriptor($device,$v['effect_id']);$this->binding($intent,$v);$table=DB::table($this->schema->table('print_ledger_attempts'));
            $row=(clone $table)->where('effect_id',$v['effect_id'])->lockForUpdate()->first();
            abort_unless($row&&(int)$row->actor_id===(int)$actor->id&&$row->claim_token===$v['claim_token']&&$row->native_session_id===$v['native_session_id']
                &&($row->invocation_id===''||$row->invocation_id===$v['invocation_id']),403);
            if($row->outcome_cipher!=='')$receipt=json_decode(Crypt::decryptString($row->outcome_cipher),true,512,JSON_THROW_ON_ERROR);
            else{
                abort_unless(in_array($row->state,['claimed','burnt'],true),409);$receipt=$v+['permit_hash'=>$row->permit_hash!==''?$row->permit_hash:hash('sha256','unissued:'.$row->effect_id),'state'=>'review'];
                $table->where('effect_id',$row->effect_id)->update(['state'=>'review','invocation_id'=>$v['invocation_id'],'permit_hash'=>$receipt['permit_hash'],
                    'outcome_cipher'=>Crypt::encryptString($this->canonical($receipt)),'updated_at'=>now('UTC')]);
            }
            $receipt=json_decode($this->canonical($receipt),true,512,JSON_THROW_ON_ERROR);
            return ['effect_id'=>$row->effect_id,'invocation_id'=>$receipt['invocation_id'],'state'=>$receipt['state'],'receipt'=>$receipt,'receipt_sha256'=>hash('sha256',$this->canonical($receipt))];
        },false);
    }

    public function pendingOutcomes(string $device): array
    {
        return $this->transaction($device,function()use($device){
            $outcomes=[];
            foreach($this->attempts($device)->where('a.server_ack_hash','')->where('a.outcome_cipher','!=','')->orderBy('a.created_at')->limit(100)->get(['a.*']) as $row)
                $outcomes[]=json_decode(Crypt::decryptString($row->outcome_cipher),true,512,JSON_THROW_ON_ERROR);
            return ['outcomes'=>$outcomes,'pending'=>$this->attempts($device)->where('a.server_ack_hash','')->where('a.outcome_cipher','!=','')->count()];
        },false,false);
    }

    /** Full physical-workspace counts; a bounded audit page cannot prove the absence of uncertainty. */
    public function workspaceState(string $device): array
    {
        abort_unless(app(DesktopDashboardWorkspacePrintAuthority::class)->selected(),403);
        return $this->transaction($device,fn()=>$this->workspaceStateLocked($device),false,false);
    }
    /** Called under the same fixed print scope lock as the generation fence. */
    public function workspaceStateLocked(string $device): array
    {
        abort_unless(app(DesktopDashboardWorkspacePrintAuthority::class)->selected()&&DB::transactionLevel()>0
            &&(DesktopDashboardWorkspaceExecutionContext::isRootControl($device)||PHP_SAPI==='cli'),403);
        $this->ledger->fence($device);
        $all=$this->attempts($device,true);
        return ['prepared'=>(int)(clone $all)->where('a.state','claimed')->count(),
            'uncertain'=>(int)(clone $all)->whereIn('a.state',['burnt','review'])->where('a.server_ack_hash','')->count(),
            'unacknowledged'=>(int)(clone $all)->where('a.outcome_cipher','!=','')->where('a.server_ack_hash','')->count()];
    }
    public function acknowledge(string $device,array $receipt): array
    {
        Validator::make($receipt,['effect_id'=>'required|regex:/^[a-f0-9]{64}$/D','invocation_id'=>'required|uuid','state'=>'required|in:invoked,review','receipt_sha256'=>'required|regex:/^[a-f0-9]{64}$/D'])->validate();
        return $this->transaction($device,function()use($device,$receipt){
            $this->descriptor($device,$receipt['effect_id']);$table=DB::table($this->schema->table('print_ledger_attempts'));$row=(clone $table)->where('effect_id',$receipt['effect_id'])->lockForUpdate()->first();
            abort_unless($row&&$row->outcome_cipher!==''&&$row->invocation_id===$receipt['invocation_id']&&$row->state===$receipt['state'],409);
            $outcome=json_decode(Crypt::decryptString($row->outcome_cipher),true,512,JSON_THROW_ON_ERROR);$hash=hash('sha256',$this->canonical($outcome));
            abort_unless(hash_equals($hash,$receipt['receipt_sha256'])&&($row->server_ack_hash===''||hash_equals($row->server_ack_hash,$hash)),409);
            $table->where('effect_id',$row->effect_id)->update(['server_ack_hash'=>$hash,'updated_at'=>now('UTC')]);return $receipt;
        },false,false);
    }

    public function resolve(string $device,array $values): array
    {
        $v=Validator::make($values,['path'=>'required|string|max:300','query'=>'present|array','request_id'=>'required|uuid','explicit_reprint'=>'sometimes|boolean'])->validate();
        return $this->transaction($device,function($actor)use($device,$v){
            // Exact original print routes only. No generic URL fetching or grant consumption.
            $kind=null;$id=0;
            if(preg_match('~^/admin/takeaway/receipts/([1-9][0-9]*)/print$~D',$v['path'],$m)){$kind='sale_receipt';$id=(int)$m[1];}
            elseif(preg_match('~^/admin/(phone-orders|dining)/kitchen/([1-9][0-9]*)/print$~D',$v['path'],$m)){$kind=$m[1]==='dining'?'dining_kitchen':'phone_kitchen';$id=(int)$m[2];}
            elseif(preg_match('~^/admin/(?:phone-orders|dining)/tickets/([1-9][0-9]*)/print$~D',$v['path'],$m)){$kind='bill_receipt';$id=(int)$m[1];}
            elseif(preg_match('~^/admin/phone-orders/batches/([1-9][0-9]*)/print$~D',$v['path'],$m)){$kind='delivery_batch';$id=(int)$m[1];}
            elseif(preg_match('~^/admin/branch-shifts/([1-9][0-9]*)/print$~D',$v['path'],$m)){$kind='shift_report';$id=(int)$m[1];}
            abort_unless($kind,422,'عنوان الطباعة المحلي غير معروف.');
            if($kind==='bill_receipt'){$ticket=DB::table('pos_service_tickets')->where('id',$id)->first();abort_unless($ticket,404);
                if($ticket->paid_order_id){$kind='sale_receipt';$id=(int)$ticket->paid_order_id;}}
            $key=match($kind){'sale_receipt'=>'receipt','bill_receipt'=>'ticket','shift_report'=>'shift','delivery_batch'=>'delivery_batch',default=>'kitchen.primary'};
            $origin=$this->originCommand($kind,$id);
            foreach(DB::table($this->schema->table('print_ledger_intents'))->where('device_id',$device)->where('kind',$kind)->where('command_id',$origin??'')->get() as $row){
                $intent=$this->descriptor($device,$row->effect_id);
                // A URL integer belongs only to its current generation. Old IDs cannot alias.
                if($intent['origin_database']!==DB::connection()->getDatabaseName()||($intent['local_references'][$key]??null)!==$id)continue;
                if(isset($v['query']['initial_print'])||($kind!=='sale_receipt'&&($v['explicit_reprint']??false)!==true)){
                    $this->authorize($intent,$actor);
                    if($kind==='sale_receipt'){$url=app(TakeawayService::class)->firstReceiptPrintUrl($id,$actor);parse_str(parse_url($url??'',PHP_URL_QUERY)??'',$expected);abort_unless(is_string($v['query']['initial_print']??null)&&is_string($expected['initial_print']??null)&&hash_equals($expected['initial_print'],$v['query']['initial_print']),403);}
                    return ['effect_id'=>$intent['effect_id']];
                }
                return $this->reprint($device,$v['request_id'],$intent,$actor);
            }
            abort_unless(!isset($v['query']['initial_print'])&&($kind==='sale_receipt'||($v['explicit_reprint']??false)===true)&&$this->authority->canReprint($actor),403);
            $refs=match($kind){'sale_receipt'=>['receipt'=>$id],'shift_report'=>['shift'=>$id],'bill_receipt'=>['ticket'=>$id],'delivery_batch'=>['delivery_batch'=>$id],default=>['kitchen.primary'=>$id]};
            if($kind==='sale_receipt'){$receipt=app(TakeawayService::class)->receipt($id,$actor);$snapshot=['presentation'=>$receipt];$branch=$receipt['branch']['value'];}
            elseif($kind==='shift_report'){$report=app(BranchShiftClosing::class)->receipt($id,$actor);$snapshot=$report;$branch=$report['branch']['value'];}
            elseif($kind==='delivery_batch'){$snapshot=app(PhoneDeliveryBoard::class)->receipt($id,$actor);$branch=$snapshot['branch']['value'];}
            elseif($kind==='bill_receipt'){$row=DB::table('pos_service_tickets')->where('id',$id)->first();abort_unless($row,404);$ticket=app(PosServiceTicket::class)->show($row->channel,$id,$actor)['ticket'];abort_unless($ticket['bill_locked']&&$ticket['status']!=='cancelled',409);$snapshot=['ticket'=>$ticket];$branch=$ticket['branch']['value'];}
            else{$snapshot=app(PosServiceTicket::class)->kitchen($kind==='dining_kitchen'?'dine':'phone',$id,$actor);$branch=$snapshot['ticket']['branch']['value'];}
            $this->enrolledBranch($this->device($device),$branch,$actor);
            $scope=DB::table($this->schema->table('print_ledger_scopes'))->where('device_id',$device)->first();
            $intent=['protocol'=>1,'device_id'=>$device,'enrollment_id'=>$scope->enrollment_id,'origin_command_id'=>$v['request_id'],'actor_id'=>(int)$actor->id,'branch'=>$branch,
                'kind'=>$kind,'revision'=>(int)($snapshot['revision']??$snapshot['ticket']['revision']??1),'origin_database'=>DB::connection()->getDatabaseName(),'local_references'=>$refs,
                'snapshot'=>$snapshot,'snapshot_sha256'=>hash('sha256',$this->canonical($snapshot))];
            return $this->reprint($device,$v['request_id'],$intent,$actor);
        });
    }

    private function reprint(string $device,string $request,array $intent,$actor): array
    {
        abort_unless($this->authority->canReprint($actor),403,'إعادة الطباعة متاحة للإدارة فقط.');
        $effect=hash('sha256',$this->canonical([$device,$intent['enrollment_id'],'reprint',$request]));$intent['effect_id']=$effect;$intent['actor_id']=(int)$actor->id;
        $intent['reprint']=true;$intent['reprint_request_id']=$request;$cipher=$this->canonical($intent);$table=DB::table($this->schema->table('print_ledger_reprints'));
        $old=(clone $table)->where('effect_id',$effect)->first();if($old)abort_unless(hash_equals($old->descriptor_hash,hash('sha256',$cipher)),409);
        else $table->insert(['effect_id'=>$effect,'request_id'=>$request,'device_id'=>$device,'actor_id'=>$actor->id,'branch'=>$intent['branch'],
            'descriptor_cipher'=>Crypt::encryptString($cipher),'descriptor_hash'=>hash('sha256',$cipher),'created_at'=>now('UTC')]);
        return ['effect_id'=>$effect];
    }
    public function audit(string $device): array
    {
        $rootAudit=app(DesktopDashboardWorkspacePrintAuthority::class)->selected()&&DesktopDashboardWorkspaceExecutionContext::isRootControl($device);
        return $this->transaction($device,function($actor)use($device,$rootAudit){
            $audit=[];
            foreach($this->attempts($device,true)->orderByDesc('a.updated_at')->orderBy('a.effect_id')->limit(100)->get(['a.*']) as $row){
                if(app(DesktopDashboardWorkspacePrintAuthority::class)->selected()&&!$rootAudit && (int)$row->actor_id!==(int)$actor->id)continue;
                $intent=$this->descriptor($device,$row->effect_id);if(!$rootAudit && (int)$intent['actor_id']!==(int)$actor->id)continue;
                $audit[]=['effect_id'=>$row->effect_id,'kind'=>$intent['kind'],'branch'=>$intent['branch'],'reprint'=>!empty($intent['reprint']),
                    'origin_command_id'=>$intent['origin_command_id'],'reprint_request_id'=>$intent['reprint_request_id']??null,'state'=>$row->state,'invocation_id'=>$row->invocation_id,'created_at'=>$row->created_at,
                    'outcome'=>$row->outcome_cipher!==''?json_decode(Crypt::decryptString($row->outcome_cipher),true,512,JSON_THROW_ON_ERROR):null];
            }
            return ['audit'=>$audit];
        },false,!$rootAudit);
    }
    private function originCommand(string $kind,int $id): ?string
    {
        if($kind==='sale_receipt')return DB::table('takeaway_orders')->where('id',$id)->value('request_key');
        if($kind==='shift_report')return DB::table('branch_shift_closings')->where('id',$id)->value('request_key');
        if($kind==='delivery_batch')return DB::table('phone_delivery_batches')->where('id',$id)->value('request_key');
        if($kind==='bill_receipt'){
            $ticket=DB::table('pos_service_tickets')->where('id',$id)->first();if(!$ticket||!$ticket->bill_issued_revision)return null;
            return DB::table('pos_service_commands')->where('ticket_id',$id)->where('actor_id',$ticket->bill_issued_by)->where('revision',$ticket->bill_issued_revision)->where('kind','action')->value('request_key');
        }
        $kitchen=DB::table('pos_service_kitchen_tickets')->where('id',$id)->first();if(!$kitchen)return null;
        return DB::table('pos_service_commands')->where('ticket_id',$kitchen->ticket_id)->where('actor_id',$kitchen->actor_id)->where('revision',$kitchen->revision)->value('request_key');
    }

    private function attempts(string $device,bool $reprints=false)
    {
        return DB::table($this->schema->table('print_ledger_attempts').' as a')->where(function($q)use($device,$reprints){
            $q->whereExists(function($inner)use($device){$inner->selectRaw('1')->from($this->schema->table('print_ledger_intents').' as i')->whereColumn('i.effect_id','a.effect_id')->where('i.device_id',$device);});
            if($reprints)$q->orWhereExists(function($inner)use($device){$inner->selectRaw('1')->from($this->schema->table('print_ledger_reprints').' as r')->whereColumn('r.effect_id','a.effect_id')->where('r.device_id',$device);});
        });
    }

    private function transaction(string $device,callable $work,bool $write=true,bool $actorRequired=true): array
    {
        abort_unless($this->ledger->active($device),503,'تجهيز الطباعة المحلية غير مكتمل.');$this->schema->verify();
        abort_unless(DB::transactionLevel()===0,503);
        return DB::transaction(function()use($device,$work,$write,$actorRequired){
            $workspace=app(DesktopDashboardWorkspacePrintAuthority::class);
            if($workspace->selected()&&!$actorRequired)abort_unless(DesktopDashboardWorkspaceExecutionContext::isRootControl($device)||PHP_SAPI==='cli',403);
            $this->ledger->fence($device);
            $state=$workspace->selected()&&!$actorRequired?app(DesktopDashboardWorkspaceLocalAuthority::class)->rootState($device,true)
                :app(DesktopDashboardRefresh::class)->lock($device);abort_unless($state,403);
            if($write)abort_unless($state->state==='ready',409);
            $device=$this->device($device);
            if($workspace->selected()) {
                $actor=null;
                if($actorRequired) {
                    $context=DesktopDashboardWorkspaceExecutionContext::current();abort_unless($context,403);
                    $facade=$workspace->actor($device->id,$context->actor_id,null,$write);
                    $actor=$this->authority->actor($facade->actor_id);
                }
                $facts=$workspace->facts($device);
            }else{
            $actor=$this->authority->actor((int)$device->actor_id);
            $facts=['device_id'=>$device->id,'actor_id'=>(int)$device->actor_id,'enrollment_hash'=>$device->enrollment_hash,'enrolled_at'=>(string)$device->enrolled_at,'branches'=>json_decode($device->branches,true)];sort($facts['branches'],SORT_STRING);
            }
            $scope=DB::table($this->schema->table('print_ledger_scopes'))->where('device_id',$device->id)->first();
            // Same encoding as immutable enrollment binding.
            abort_unless($scope&&hash_equals($scope->enrollment_id,hash('sha256',$this->canonical($facts))),409);
            return $work($actor);
        });
    }
    private function device(string $device): object {
        if(app(DesktopDashboardWorkspacePrintAuthority::class)->selected())return app(DesktopDashboardWorkspacePrintAuthority::class)->root($device,true);
        $row=DB::table('desktop_dashboard_devices')->where('id',$device)->lockForUpdate()->first();abort_unless($row&&$row->enabled,401);return $row;
    }
    private function descriptor(string $device,string $effect): array
    {
        $row=DB::table($this->schema->table('print_ledger_intents'))->where('device_id',$device)->where('effect_id',$effect)->first();
        if($row){
            $intents=app(DesktopDashboardWorkspacePrintAuthority::class)->selected()
                ?$this->ledger->readImmutableInTransaction($device,$row->command_id,(int)$row->actor_id,$row->branch)
                :$this->ledger->readInTransaction($device,$row->command_id,(int)$row->actor_id,$row->branch);
            foreach($intents as $intent)if($intent['effect_id']===$effect)return $intent;
        }
        $reprint=DB::table($this->schema->table('print_ledger_reprints'))->where('device_id',$device)->where('effect_id',$effect)->first();abort_unless($reprint,404);
        $value=json_decode(Crypt::decryptString($reprint->descriptor_cipher),true,512,JSON_THROW_ON_ERROR);abort_unless(hash_equals($reprint->descriptor_hash,hash('sha256',$this->canonical($value)))&&$value['effect_id']===$effect&&$value['device_id']===$device,503);return $value;
    }
    private function eligible(array $intent,$actor): bool
    {
        if((int)$intent['actor_id']!==(int)$actor->id)return false;
        if(!empty($intent['reprint']))return $this->authority->canReprint($actor);
        return in_array($intent['kind'],['sale_receipt','bill_receipt','dining_kitchen','delivery_batch'],true)?$this->authority->canCheckout($actor)
            :($intent['kind']==='shift_report'?$this->authority->canClose($actor):$this->authority->receiverBranch($actor)===$intent['branch']);
    }
    private function authorize(array $intent,$actor): void
    {
        $this->enrolledBranch($this->device($intent['device_id']),$intent['branch'],$actor);abort_unless($this->eligible($intent,$actor),403);
        if(!empty($intent['reprint']))abort_unless($this->authority->canReprint($actor),403);
        elseif($intent['kind']==='sale_receipt')abort_unless($this->authority->canCheckout($actor)&&($intent['snapshot']['grant']['expires_at']??0)>now('UTC')->getTimestamp(),403);
    }
    private function enrolledBranch(object $device,string $branch,$actor): void
    {
        if(($device->workspace_protocol??null)===2)
            app(DesktopDashboardWorkspacePrintAuthority::class)->actor($device->id,(int)$actor->id,$branch,true);
        abort_unless(in_array($branch,json_decode($device->branches,true,512,JSON_THROW_ON_ERROR),true),403);
        $this->authority->branch($branch,$actor);
    }
    private function binding(array $intent,array $value): void
    {
        foreach(['enrollment_id','origin_command_id','actor_id','branch','revision'] as $key)abort_unless(($value[$key]??null)===$intent[$key],403);
        if(isset($value['snapshot_sha256']))abort_unless(hash_equals($intent['snapshot_sha256'],$value['snapshot_sha256']),409);
    }
    private function validateAttempt(array $value,bool $burn): array
    {
        $rules=['effect_id'=>'required|regex:/^[a-f0-9]{64}$/D','enrollment_id'=>'required|regex:/^[a-f0-9]{64}$/D','origin_command_id'=>'required|uuid',
            'actor_id'=>'required|integer|min:1','branch'=>'required|string|max:40','revision'=>'required|integer|min:1','claim_token'=>'required|uuid','native_session_id'=>'required|uuid','invocation_id'=>'required|uuid'];
        if($burn)$rules+=['snapshot_sha256'=>'required|regex:/^[a-f0-9]{64}$/D','render_sha256'=>'required|regex:/^[a-f0-9]{64}$/D'];
        $v=Validator::make($value,$rules)->validate();$v['actor_id']=(int)$v['actor_id'];$v['revision']=(int)$v['revision'];return $v;
    }
    private function render(array $intent): string
    {
        $html=match($intent['kind']){
            'delivery_batch'=>view('admin.phone_orders.batch_receipt',['batch'=>$intent['snapshot']])->render(),
            'sale_receipt'=>view('admin.takeaway.receipt',['receipt'=>$intent['snapshot']['presentation']])->render(),
            'bill_receipt'=>view('admin.pos_service.ticket',['ticket'=>$intent['snapshot']['ticket']])->render(),
            'dining_kitchen','phone_kitchen'=>view('admin.pos_service.kitchen',['kitchen'=>$intent['snapshot']])->render(),
            'shift_report'=>view('admin.branch_shifts.receipt',['report'=>$intent['snapshot']+['number'=>'SHIFT-'.str_pad((string)$intent['local_references']['shift'],6,'0',STR_PAD_LEFT)]])->render(),
            default=>abort(422),};
        $html=preg_replace('~<script\b[^>]*>.*?</script>~is','',$html);
        $html=preg_replace('/\s+on[a-z]+=(?:"[^"]*"|\x27[^\x27]*\x27)/i','',$html);
        $logo=public_path('dashboard/branding/fasakhansta-logo-transparent.png');
        $html=preg_replace_callback('~(<img\b[^>]*\bsrc=)["\x27]([^"\x27]+)["\x27]~i',function($m)use($logo){abort_unless(str_ends_with($m[2],'/dashboard/branding/fasakhansta-logo-transparent.png')&&is_file($logo),503);return $m[1].'"data:image/png;base64,'.base64_encode(file_get_contents($logo)).'"';},$html);
        abort_unless(is_string($html)&&strlen($html)<3*1024*1024,413);return $html;
    }
    private function canonical($value): string
    {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);return array_map($sort,$v);};
        return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    }
}
