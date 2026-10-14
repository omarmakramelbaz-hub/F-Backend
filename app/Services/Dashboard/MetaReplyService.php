<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Manual native replies. Every customer-facing send has a durable uncertainty marker and UUID. */
class MetaReplyService
{
    public const EVIDENCE_SOURCE='dashboard_api';
    private const EVIDENCE_SCHEMA='meta-accepted-reply-evidence-v1';
    private $transport;
    private ?MetaUploadMedia $media;
    public function __construct(?callable $transport=null, ?MetaUploadMedia $media=null) { $this->transport=$transport; $this->media=$media; }

    public function state($actor,int $conversation,?string $uuid=null): array
    {
        $actor=app(MetaInboxAccess::class)->actor($actor);
        if ($uuid!==null) abort_unless($this->uuid($uuid),422);
        $thread=$this->thread($conversation); $snapshot=$this->snapshot($thread);
        $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
        $reason=!$this->configured($account) ? 'REPLIES_UNAVAILABLE'
            : ($this->unresolved($conversation) ? 'UNKNOWN_PENDING' : $snapshot['reason']);
        $recent=$this->publicReplies($actor,$thread,DB::table('meta_reply_requests')->where('conversation_id',$conversation)
            ->orderByDesc('id')->limit(20)->get());
        $request=$uuid===null ? null : DB::table('meta_reply_requests')->where('conversation_id',$conversation)
            ->where('request_hash',$this->requestHash((int)$actor->id,$conversation,$uuid))->first();
        return ['success'=>true,'available'=>$this->configured($account),'can_reply'=>$reason===null,
            'reason'=>$reason,'latest_inbound_id'=>$snapshot['inbound_id'],'window_expires_at'=>$snapshot['expires'],
            'voice_ready'=>$this->media()->audioAvailable(),'voice_mime_types'=>['audio/webm;codecs=opus','audio/webm','audio/mp4','audio/ogg;codecs=opus'],
            'attachment_ready'=>true,'attachment_mime_types'=>$thread->waba_id==='instagram'
                ?['image/jpeg','image/png','image/gif','video/mp4','audio/mpeg','audio/mp4','audio/wav']
                :['image/jpeg','image/png','image/gif','video/mp4','audio/mpeg','audio/mp4','audio/wav','application/pdf','text/plain','application/zip',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'request_state'=>$request?$this->publicState($request->state):null,
            'request_reason'=>$request->reason??null,'reply_id'=>$request?(int)$request->id:null,'recent_replies'=>array_reverse($recent)];
    }

    /** A local accepted receipt is available before the provider's webhook copy arrives. */
    public function acceptedReply($actor,int $conversation,int $reply): ?array
    {
        $actor=app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation);
        abort_unless($reply>0,404);
        $row=DB::table('meta_reply_requests')->where('conversation_id',$conversation)->where('id',$reply)
            ->where('state','ACCEPTED')->first();
        if (!$row) return null;
        $items=$this->publicReplies($actor,$thread,[$row]);
        app(MetaInboxAccess::class)->actor($actor);
        return $items[0]??null;
    }

    private function publicReplies($actor,object $thread,iterable $rows): array
    {
        $items=[]; $keys=[];
        foreach ($rows as $row) {
            try {
                if ((int)$row->conversation_id!==(int)$thread->id || $row->waba_id!==$thread->waba_id
                    || $row->phone_number_id!==$thread->phone_number_id || !in_array($row->kind,['text','audio','image','video','file'],true)) continue;
                $audit=json_decode(Crypt::decryptString($row->audit),true,32,JSON_THROW_ON_ERROR);
                if (!is_array($audit) || !$this->uuid($audit['client_request_id']??null)
                    || !hash_equals((string)$row->request_hash,$this->requestHash((int)$row->actor_id,(int)$thread->id,$audit['client_request_id']))) continue;
                $state=$this->publicState($row->state); $projection=null;
                if ($state==='ACCEPTED') {
                    $details=json_decode(Crypt::decryptString($row->response_details),true,8,JSON_THROW_ON_ERROR);
                    $native=$details['message_id']??null;
                    if (!is_string($native) || $native==='' || strlen($native)>512 || preg_match('/[\x00-\x1f\x7f]/',$native)) continue;
                    $projection=hash('sha256','whatsapp-message-v1:'.$thread->waba_id.':'.$thread->phone_number_id.':'.$native);
                    $keys[]=$projection;
                }
                $text=$audit['text']??null;
                if (!is_string($text) || !mb_check_encoding($text,'UTF-8') || mb_strlen($text,'UTF-8')>1000
                    || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) $text=null;
                $media=null; $source=$audit['media']??null;
                if (is_array($source) && in_array($source['type']??null,['audio','image','video','file'],true)) {
                    $name=$source['name']??null;
                    if (!is_string($name) || !mb_check_encoding($name,'UTF-8') || mb_strlen($name,'UTF-8')>120
                        || preg_match('/[\x00-\x1f\x7f]/',$name)) $name=null;
                    $media=['type'=>$source['type'],'name'=>$name];
                }
                $items[]=['id'=>(int)$row->id,'reply_id'=>(int)$row->id,'client_request_id'=>strtolower($audit['client_request_id']),
                    'state'=>$state,'kind'=>$row->kind,'text'=>$text,'media'=>$media,'forwarded'=>isset($audit['source_message_id']),
                    'created_at'=>CarbonImmutable::parse($row->created_at,'UTC')->toIso8601String(),
                    'accepted_at'=>$state==='ACCEPTED'&&$row->completed_at!==null?CarbonImmutable::parse($row->completed_at,'UTC')->toIso8601String():null,
                    'actor_id'=>(int)$row->actor_id,'projection_key'=>$projection,'projected_message_id'=>null,'hidden'=>false];
            } catch (\Throwable $error) {} // Corrupt private evidence is omitted, never returned.
        }
        if ($keys) {
            try {
                // One scoped batch lookup handles every receipt; provider calls never run here.
                abort_unless(Schema::hasTable(MetaMediaService::HIDES_TABLE),503);
                $projected=DB::table('meta_inbox_messages')->where('conversation_id',(int)$thread->id)->where('direction','outbound')
                    ->whereIn('message_key',array_values(array_unique($keys)))->get(['id','message_key'])->keyBy('message_key');
                $hidden=app(MetaMediaService::class)->hiddenIds($actor,$projected->pluck('id')->all());
                foreach ($items as &$item) {
                    $message=$item['projection_key']!==null?$projected->get($item['projection_key']):null;
                    if (!$message) continue;
                    $item['projected_message_id']=(int)$message->id;
                    $item['hidden']=in_array((int)$message->id,$hidden,true);
                    if ($item['hidden']) { $item['text']=null; $item['media']=null; }
                }
                unset($item);
            } catch (\Throwable $error) {
                // Failure to verify a hidden copy must not resurrect its private receipt.
                $items=array_values(array_filter($items,static fn($item)=>$item['state']!=='ACCEPTED'));
            }
        }
        return $items;
    }

    public function send($actor,int $conversation,array $input): array
    {
        return $this->sendText($actor,$conversation,$input);
    }

    /** Internal automation reserves its durable receipt before spending an AI call. */
    public function sendOrderFollowup($actor,int $conversation,array $input,callable $guard): array
    {
        $marker=$input['_followup']??null;$compose=$input['_compose']??null;
        unset($input['_followup'],$input['_compose']);
        $inbound=filter_var($input['expected_inbound_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        abort_unless(is_int($inbound)&&is_string($input['client_request_id']??null)
            &&MetaOrderFollowupService::validMarker($marker,$conversation,$input['client_request_id'],$inbound)&&is_callable($compose),422);
        return $this->sendText($actor,$conversation,$input,$marker,$compose,$guard);
    }

    /** Cross-actor reads of an owned input tombstone never invoke AI or Graph. */
    public function orderFollowupState($actor,int $conversation,string $uuid,int $inbound): ?array
    {
        app(MetaInboxAccess::class)->actor($actor);abort_unless($this->uuid($uuid)&&$inbound>0,422);
        $thread=$this->thread($conversation);
        $old=$this->orderFollowupReceipt($thread,$uuid,$inbound,false);
        return $old?$this->result($this->publicState($old->state),(int)$old->id,$old->reason,true):null;
    }

    /** Pure, conservative takeover check, including manual sends whose native echo is absent. */
    public function hasManualReplyAfter(int $conversation,string $after,bool $lock=false): bool
    {
        try{
            $this->evidenceTime($after);$thread=$this->thread($conversation,$lock);
            $q=DB::table('meta_reply_requests')->where('conversation_id',$conversation)->where('waba_id',$thread->waba_id)
                ->where('phone_number_id',$thread->phone_number_id)->whereIn('state',['CLAIMED','UNKNOWN','ACCEPTED'])
                ->where(function($q)use($after){$q->where('claimed_at','>=',$after)->orWhere('send_started_at','>=',$after)->orWhere('completed_at','>=',$after);})
                ->orderByDesc('id')->limit(21)->select('id','kind','inbound_message_id','actor_id','request_hash','payload_hash')
                ->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit');
            $rows=($lock?$q->lockForUpdate():$q)->get();if(count($rows)>20)return true;
            foreach($rows as$row){
                $audit=$this->decodeEvidence($row->audit,65536);$uuid=$audit['client_request_id']??null;
                if($row->kind!=='text'||!is_string($uuid)||!$this->uuid($uuid)
                    ||!MetaOrderFollowupService::validMarker($audit['order_followup']??null,$conversation,$uuid,(int)$row->inbound_message_id)
                    ||!hash_equals((string)$row->request_hash,$this->requestHash((int)$row->actor_id,$conversation,$uuid))
                    ||!is_string($audit['text']??null)||!hash_equals((string)$row->payload_hash,
                        $this->textPayloadHash((int)$row->inbound_message_id,$audit['text'],$audit['order_followup'])))return true;
            }
            return false;
        }catch(\Throwable$error){return true;}
    }

    private function orderFollowupReceipt(object $thread,string $uuid,int $inbound,bool $lock): ?object
    {
        $q=DB::table('meta_reply_requests')->where('conversation_id',$thread->id)->where('waba_id',$thread->waba_id)
            ->where('phone_number_id',$thread->phone_number_id)->where('inbound_message_id',$inbound)->where('kind','text')
            ->orderByDesc('id')->limit(21)->select('id','actor_id','conversation_id','state','reason','request_hash','payload_hash','remote_message_key')
            ->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit');
        $rows=($lock?$q->lockForUpdate():$q)->get();abort_unless(count($rows)<=20,409);
        foreach($rows as $row){
            $audit=$this->decodeEvidence($row->audit,65536);
            if(!array_key_exists('order_followup',$audit)){
                abort_unless($row->remote_message_key===null&&($audit['client_request_id']??null)!==strtolower($uuid),409);continue;
            }
            $savedUuid=$audit['client_request_id']??null;
            abort_unless(is_string($savedUuid)&&$this->uuid($savedUuid)
                &&hash_equals((string)$row->request_hash,$this->requestHash((int)$row->actor_id,(int)$thread->id,$savedUuid))
                &&MetaOrderFollowupService::validMarker($audit['order_followup'],(int)$thread->id,$savedUuid,$inbound)
                &&is_string($audit['text']??null)&&hash_equals((string)$row->payload_hash,$this->textPayloadHash($inbound,$audit['text'],$audit['order_followup']))
                &&in_array($row->state,['CLAIMED','UNKNOWN','ACCEPTED','FAILED'],true),409);
            // The native input, not the actor or refreshed plan, is the global send boundary.
            return $row;
        }
        return null;
    }

    private function sendText($actor,int $conversation,array $input,?array $marker=null,?callable $compose=null,?callable $guard=null): array
    {
        $actor=app(MetaInboxAccess::class)->actor($actor);
        $wanted=['client_request_id','expected_inbound_id','text'];
        abort_unless(!array_diff(array_keys($input),$wanted)&&!array_diff($wanted,array_keys($input))
            &&$this->uuid($input['client_request_id']??null),422);
        $inbound=filter_var($input['expected_inbound_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $text=$input['text'];
        abort_unless(is_int($inbound)&&is_string($text)&&mb_check_encoding($text,'UTF-8')&&trim($text)!==''
            &&mb_strlen($text,'UTF-8')<=1000&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text),422);
        $hash=$this->requestHash((int)$actor->id,$conversation,$input['client_request_id']);
        $payloadHash=$this->textPayloadHash($inbound,$text,$marker);
        $claim=DB::transaction(function () use ($actor,$conversation,$input,$inbound,$text,$hash,$payloadHash,$marker,$guard) {
            $actor=app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation,true);
            if($marker!==null){
                $old=$this->orderFollowupReceipt($thread,$input['client_request_id'],$inbound,true);
                if($old)return ['result'=>$this->result($this->publicState($old->state),(int)$old->id,$old->reason,true)];
                abort_unless($guard!==null&&$guard()===true,409);
            }
            $old=DB::table('meta_reply_requests')->where('request_hash',$hash)->lockForUpdate()->first();
            if ($old) { abort_unless(hash_equals($old->payload_hash,$payloadHash),409); return ['result'=>$this->result($this->publicState($old->state),(int)$old->id,$old->reason,true)]; }
            $snapshot=$this->snapshot($thread,true); $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
            $reason=!$this->configured($account)?'REPLIES_UNAVAILABLE':($this->unresolved($conversation,true)?'UNKNOWN_PENDING':$snapshot['reason']);
            if ($reason===null&&$snapshot['inbound_id']!==$inbound) $reason='STALE_INBOUND';
            if ($reason!==null) return ['result'=>$this->result('FAILED',null,$reason)];
            $audit=['client_request_id'=>strtolower($input['client_request_id']),'text'=>$text,'recipient'=>$snapshot['recipient']];
            if($marker!==null)$audit['order_followup']=$marker;
            $id=DB::table('meta_reply_requests')->insertGetId([
                'conversation_id'=>$conversation,'actor_id'=>$actor->id,'waba_id'=>$thread->waba_id,'phone_number_id'=>$thread->phone_number_id,
                'request_hash'=>$hash,'payload_hash'=>$payloadHash,'inbound_message_id'=>$inbound,'kind'=>'text','state'=>'CLAIMED',
                'audit'=>Crypt::encryptString(json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                'claimed_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['id'=>(int)$id,'snapshot'=>$snapshot];
        },3);
        if (isset($claim['result'])) return $claim['result']; $id=$claim['id'];
        try {
            $reason=$this->preflight($actor,$conversation,$claim['snapshot'],$guard);
            if ($reason!==null) return $this->finish($id,'FAILED',$reason,['CLAIMED']);
            if($compose!==null){
                abort_unless(DB::connection()->transactionLevel()===0,409);
                $composed=$compose();
                abort_unless(is_string($composed)&&mb_check_encoding($composed,'UTF-8')&&trim($composed)!==''
                    &&mb_strlen($composed,'UTF-8')<=1000&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$composed),422);
                $row=DB::table('meta_reply_requests')->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')->first(['audit']);
                if(!$row)return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
                $audit=$this->decodeEvidence($row->audit,65536);$audit['text']=$text=$composed;
                $changed=DB::table('meta_reply_requests')->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')->update([
                    'audit'=>Crypt::encryptString(json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                    'payload_hash'=>$this->textPayloadHash($inbound,$text,$marker),
                    'updated_at'=>now('UTC')]);
                if($changed!==1)return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
            }
            $reason=$this->preflight($actor,$conversation,$claim['snapshot'],$guard);
            if($reason!==null)return $this->finish($id,'FAILED',$reason,['CLAIMED']);
            $changed=DB::table('meta_reply_requests')->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')
                ->update(['state'=>'UNKNOWN','reason'=>'DELIVERY_UNKNOWN','send_started_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            if ($changed!==1) return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
            $reason=$this->preflight($actor,$conversation,$claim['snapshot'],$guard);
            if ($reason!==null) return $this->finish($id,'FAILED',$reason,['UNKNOWN']);
            $thread=$this->thread($conversation); $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
            $payload=['recipient'=>['id'=>$claim['snapshot']['recipient']],'message'=>['text'=>$text]];
            if ($thread->waba_id==='messenger') $payload['messaging_type']='RESPONSE';
            $response=$this->call($account,$payload); $body=json_decode($response['body'],true);
            if ($response['status']===200&&is_array($body)&&!isset($body['error'])&&is_string($body['message_id']??null)
                &&$body['message_id']!==''&&strlen($body['message_id'])<=512
                &&!preg_match('/[\x00-\x1f\x7f]/',$body['message_id'])
                &&(!isset($body['recipient_id'])||$body['recipient_id']===$claim['snapshot']['recipient'])) {
                return $this->finish($id,'ACCEPTED',null,['UNKNOWN'],$body['message_id'],$marker!==null);
            }
            if (in_array($response['status'],[400,401,403,404,405,409,410,413,415,422,429],true)&&is_int($body['error']['code']??null)
                &&!isset($body['message_id'])) return $this->finish($id,'FAILED','META_REJECTED',['UNKNOWN']);
        } catch (\Throwable $error) {
            if($marker!==null&&DB::table('meta_reply_requests')->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')->exists())
                return $this->finish($id,'FAILED','STALE_INBOUND',['CLAIMED']);
        } // A transport timeout may follow a real send; never retry blindly.
        return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
    }

    public function voice($actor,int $conversation,array $input,$file): array
    {
        return $this->attachment($actor,$conversation,$input,$file,true);
    }

    public function attachment($actor,int $conversation,array $input,$file,bool $voice=false): array
    {
        $actor=app(MetaInboxAccess::class)->actor($actor); $this->thread($conversation);
        $inbound=$this->richInput($input,['client_request_id','expected_inbound_id']);
        abort_unless($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile,422);
        $media=null;
        try {
            $media=$this->media()->prepare($file,$voice);
            $thread=$this->thread($conversation);
            if ($media['type']==='file'&&$thread->waba_id==='instagram') return $this->result('FAILED',null,'MEDIA_UNSUPPORTED');
            $audit=['media'=>['type'=>$media['type'],'name'=>$media['filename'],'mime'=>$media['mime'],
                'bytes'=>$media['bytes'],'input_sha256'=>$media['input_sha256']]];
            return $this->sendRich($actor,$conversation,$input,$inbound,$media['type'],$audit,[],function(array $account) use ($media) {
                if ($account['channel']==='instagram'&&($account['login']??'facebook')==='instagram') {
                    $url=$this->media()->expose($media);
                    return ['message'=>['attachment'=>['type'=>$media['type'],'payload'=>['url'=>$url]]],'retained'=>$media['path']];
                }
                $id=$this->upload($account,$media);
                return ['message'=>['attachment'=>['type'=>$media['type'],'payload'=>['attachment_id'=>$id]]]];
            });
        } catch (\RuntimeException $e) {
            if (in_array($e->getMessage(),['INVALID_MEDIA','VOICE_UNAVAILABLE','MEDIA_UNAVAILABLE'],true)) return $this->result('FAILED',null,$e->getMessage());
            throw $e;
        } finally { if ($media!==null) $this->media()->cleanup($media['path']); }
    }

    public function forward($actor,int $conversation,array $input): array
    {
        $actor=app(MetaInboxAccess::class)->actor($actor); $target=$this->thread($conversation);
        $wanted=['client_request_id','expected_inbound_id','source_message_id'];
        $inbound=$this->richInput($input,$wanted,['source_media_index','source_kind']);
        $choice=$input['source_kind']??null;
        abort_unless($choice===null||in_array($choice,['text','media'],true),422);
        $sourceId=filter_var($input['source_message_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $index=filter_var($input['source_media_index']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>29]]);
        abort_unless(is_int($sourceId)&&is_int($index),422);
        $sourceRow=DB::table('meta_inbox_messages')->where('id',$sourceId)->first(); abort_unless($sourceRow,404);
        $checked=app(MetaMediaService::class)->message((int)$sourceRow->conversation_id,$sourceId,$actor);
        $sourceThread=$checked['thread']; $dto=$checked['dto'];
        abort_unless(!$checked['hidden']&&$sourceThread->waba_id===$target->waba_id&&$sourceThread->phone_number_id===$target->phone_number_id,422);
        $audit=['source_conversation_id'=>(int)$sourceThread->id,'source_message_id'=>$sourceId,'source_media_index'=>$index,'source_kind'=>$choice,
            'source_message_key'=>$sourceRow->message_key,'source_evidence_hash'=>hash_hmac('sha256',json_encode($dto,JSON_THROW_ON_ERROR),Crypt::getKey())];
        $text=$dto['text']??null; $attachments=$dto['content']['message']['meta']['attachments']??[];
        if ($attachments&&$choice===null&&!array_key_exists('source_media_index',$input)&&(count($attachments)!==1||(is_string($text)&&$text!==''))) return $this->result('FAILED',null,'FORWARD_SELECTION_REQUIRED');
        if ($choice==='text'||($choice!=='media'&&is_string($text)&&$text!==''&&!$attachments)) {
            abort_unless(is_string($text)&&trim($text)!=='',422);
            abort_unless(mb_check_encoding($text,'UTF-8')&&mb_strlen($text,'UTF-8')<=1000&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text),422);
            $audit['text']=$text;
            return $this->sendRich($actor,$conversation,$input,$inbound,'text',$audit,['text'=>$text],null,
                fn()=>$this->forwardSource($actor,(int)$sourceThread->id,$sourceId,$audit['source_evidence_hash']));
        }
        $media=app(MetaMediaService::class)->source((int)$sourceThread->id,$sourceId,$index,$actor);
        if ($media['type']==='document') $media['type']='file';
        abort_unless(in_array($media['type'],['image','video','audio','file'],true)&&!($target->waba_id==='instagram'&&$media['type']==='file'),422);
        $audit['media']=['type'=>$media['type'],'name'=>$media['name']??null];
        // The URL comes only from a signed, validated stored Meta DTO; never from a request body.
        return $this->sendRich($actor,$conversation,$input,$inbound,$media['type'],$audit,[],function(array $account) use ($media) {
            return ['message'=>['attachment'=>['type'=>$media['type'],'payload'=>['url'=>$media['url']]]]];
        },fn()=>$this->forwardSource($actor,(int)$sourceThread->id,$sourceId,$audit['source_evidence_hash']));
    }

    private function richInput(array $input,array $wanted,array $optional=[]): int
    {
        abort_unless(!array_diff(array_keys($input),array_merge($wanted,$optional))&&!array_diff($wanted,array_keys($input))
            &&$this->uuid($input['client_request_id']??null),422);
        $inbound=filter_var($input['expected_inbound_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); abort_unless(is_int($inbound),422);
        return $inbound;
    }

    private function sendRich($actor,int $conversation,array $input,int $inbound,string $kind,array $audit,array $message,?callable $prepare=null,?callable $sourcePreflight=null): array
    {
        $hash=$this->requestHash((int)$actor->id,$conversation,$input['client_request_id']);
        $payloadHash=hash_hmac('sha256',json_encode([$inbound,$kind,$audit],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey());
        $claim=DB::transaction(function() use($actor,$conversation,$input,$inbound,$kind,$audit,$hash,$payloadHash) {
            $actor=app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation,true);
            $old=DB::table('meta_reply_requests')->where('request_hash',$hash)->lockForUpdate()->first();
            if ($old) { abort_unless(hash_equals($old->payload_hash,$payloadHash),409); return ['result'=>$this->result($this->publicState($old->state),(int)$old->id,$old->reason,true)]; }
            $snapshot=$this->snapshot($thread,true); $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
            $reason=!$this->configured($account)?'REPLIES_UNAVAILABLE':($this->unresolved($conversation,true)?'UNKNOWN_PENDING':$snapshot['reason']);
            if ($reason===null&&$snapshot['inbound_id']!==$inbound) $reason='STALE_INBOUND';
            if ($reason!==null) return ['result'=>$this->result('FAILED',null,$reason)];
            $audit+=['client_request_id'=>strtolower($input['client_request_id']),'recipient'=>$snapshot['recipient']];
            $id=DB::table('meta_reply_requests')->insertGetId(['conversation_id'=>$conversation,'actor_id'=>$actor->id,
                'waba_id'=>$thread->waba_id,'phone_number_id'=>$thread->phone_number_id,'request_hash'=>$hash,'payload_hash'=>$payloadHash,
                'inbound_message_id'=>$inbound,'kind'=>$kind,'state'=>'CLAIMED','audit'=>Crypt::encryptString(json_encode($audit,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                'claimed_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['id'=>(int)$id,'snapshot'=>$snapshot,'account'=>$account];
        },3);
        if (isset($claim['result'])) return $claim['result']; $id=$claim['id']; $retained=null; $sendMarked=false;
        try {
            $reason=$this->preflight($actor,$conversation,$claim['snapshot']);
            if ($reason===null&&$sourcePreflight) $reason=$sourcePreflight();
            if ($reason!==null) return $this->finish($id,'FAILED',$reason,['CLAIMED']);
            if ($prepare!==null) {
                $prepared=$prepare($claim['account']); $message=$prepared['message']; $retained=$prepared['retained']??null;
            }
            // Uploads never send a customer message. Revalidate after a potentially slow upload, before marking uncertainty.
            $reason=$this->preflight($actor,$conversation,$claim['snapshot']);
            if ($reason===null&&$sourcePreflight) $reason=$sourcePreflight();
            if ($reason!==null) return $this->finish($id,'FAILED',$reason,['CLAIMED']);
            $changed=DB::table('meta_reply_requests')->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')
                ->update(['state'=>'UNKNOWN','reason'=>'DELIVERY_UNKNOWN','send_started_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            if ($changed!==1) return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
            $sendMarked=true;
            $reason=$this->preflight($actor,$conversation,$claim['snapshot']);
            if ($reason===null&&$sourcePreflight) $reason=$sourcePreflight();
            if ($reason!==null) { $sendMarked=false; return $this->finish($id,'FAILED',$reason,['UNKNOWN']); }
            $thread=$this->thread($conversation); $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
            $payload=['recipient'=>['id'=>$claim['snapshot']['recipient']],'message'=>$message];
            if ($thread->waba_id==='messenger') $payload['messaging_type']='RESPONSE';
            // Transfer ownership BEFORE customer-facing I/O: a fatal/timeout cannot let the destructor delete Meta's URL.
            if ($retained!==null) { $this->media()->retain($retained); $retained=null; }
            $response=$this->call($account,$payload); $body=json_decode($response['body'],true);
            if ($response['status']===200&&is_array($body)&&!isset($body['error'])&&is_string($body['message_id']??null)
                &&$body['message_id']!==''&&strlen($body['message_id'])<=512&&!preg_match('/[\x00-\x1f\x7f]/',$body['message_id'])
                &&(!isset($body['recipient_id'])||$body['recipient_id']===$claim['snapshot']['recipient'])) {
                return $this->finish($id,'ACCEPTED',null,['UNKNOWN'],$body['message_id']);
            }
            if (in_array($response['status'],[400,401,403,404,405,409,410,413,415,422,429],true)&&is_int($body['error']['code']??null)) {
                $sendMarked=false; return $this->finish($id,'FAILED','META_REJECTED',['UNKNOWN']);
            }
        } catch (\Throwable $e) {
            if (!$sendMarked) return $this->finish($id,'FAILED',$e->getMessage()==='MEDIA_UNAVAILABLE'?'MEDIA_UNAVAILABLE':'MEDIA_UPLOAD_FAILED',['CLAIMED','UNKNOWN']);
        } finally {
            // Meta may still fetch an accepted/uncertain URL; keep it only until the signed capability expires.
            if ($retained!==null&&$sendMarked) $this->media()->retain($retained);
        }
        return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
    }

    private function forwardSource($actor,int $conversation,int $message,string $hash): ?string
    {
        try {
            $checked=app(MetaMediaService::class)->message($conversation,$message,$actor);
            return !$checked['hidden']&&hash_equals($hash,hash_hmac('sha256',json_encode($checked['dto'],JSON_THROW_ON_ERROR),Crypt::getKey()))?null:'SOURCE_UNAVAILABLE';
        } catch (\Throwable $e) { return 'SOURCE_UNAVAILABLE'; }
    }

    private function upload(array $account,array $media): string
    {
        $endpoint='https://graph.facebook.com/'.config('meta_messaging.api_version','v25.0').'/'.($account['channel']==='instagram'?'me':$account['account_id']).'/message_attachments';
        $payload=['message'=>['attachment'=>['type'=>$media['type'],'payload'=>['is_reusable'=>true]]],
            'filedata'=>['path'=>$media['path'],'mime'=>$media['mime'],'filename'=>$media['filename']]];
        if ($account['channel']==='instagram') $payload['platform']='instagram';
        if ($this->transport) $response=($this->transport)($endpoint,$payload,$account['access_token']);
        else {
            $form=['message'=>json_encode($payload['message'],JSON_THROW_ON_ERROR),
                'filedata'=>new \CURLFile($media['path'],$media['mime'],$media['filename'])];
            if (isset($payload['platform'])) $form['platform']=$payload['platform'];
            $response=$this->network($endpoint,$form,$account['access_token'],true);
        }
        $body=json_decode($response['body'],true);
        if ($response['status']!==200||!is_array($body)||isset($body['error'])||!\App\Support\MetaMessagingProtocol::id($body['attachment_id']??null)) throw new \RuntimeException('MEDIA_UPLOAD_FAILED');
        return $body['attachment_id'];
    }

    private function media(): MetaUploadMedia { return $this->media??($this->media=app(MetaUploadMedia::class)); }

    private function preflight($actor,int $conversation,array $expected,?callable $guard=null): ?string
    {
        try {
            app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation);
            if (!$this->configured(MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id))) return 'REPLIES_UNAVAILABLE';
            $snapshot=$this->snapshot($thread);
            if (($snapshot['account_fingerprint']??null)!==($expected['account_fingerprint']??null)) return 'REPLIES_UNAVAILABLE';
            $reason=$snapshot['reason']??(($snapshot['inbound_id']===$expected['inbound_id']&&$snapshot['recipient']===$expected['recipient'])?null:'STALE_INBOUND');
            if($reason===null&&$guard!==null&&$guard()!==true)$reason='STALE_INBOUND';
            return $reason;
        } catch (\Throwable $error) { return 'ACCESS_REVOKED'; }
    }

    private function snapshot(object $thread,bool $lock=false): array
    {
        $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
        $result=['inbound_id'=>null,'expires'=>null,'recipient'=>null,'reason'=>'NO_INBOUND',
            'account_fingerprint'=>hash_hmac('sha256',json_encode([$thread->waba_id,$thread->phone_number_id,$account['login']??'facebook',$account['access_token']??null,
                config('meta_messaging.api_version','v25.0')],JSON_THROW_ON_ERROR),Crypt::getKey())];
        $query=DB::table('meta_inbox_messages')->where('conversation_id',$thread->id)->where('direction','inbound')->orderByDesc('sent_at')->orderByDesc('id');
        $row=($lock?$query->lockForUpdate():$query)->first(); if (!$row) return $result;
        $result['inbound_id']=(int)$row->id; $result['reason']='RECIPIENT_UNAVAILABLE';
        try {
            $m=json_decode(Crypt::decryptString($row->content),true,64,JSON_THROW_ON_ERROR); $peer=$m['peer_user_id']??null;
            $scope=$thread->waba_id.':'.$thread->phone_number_id.':';
            if (!\App\Support\MetaMessagingProtocol::id($peer)||($m['direction']??null)!=='inbound'
                ||($m['channel']??null)!==$thread->waba_id||($m['account_id']??null)!==$thread->phone_number_id
                ||($m['sent_at']??null)!==$row->sent_at||($m['peer_identity']??null)!=='user:'.$peer
                ||!hash_equals($thread->peer_hash,hash_hmac('sha256','whatsapp-peer-v1:'.$scope.'user:'.$peer,Crypt::getKey()))
                ||!hash_equals($row->message_key,hash('sha256','whatsapp-message-v1:'.$scope.($m['message_id']??'')))) return $result;
            $sent=CarbonImmutable::parse($row->sent_at,'UTC'); $expires=$sent->addHours(24);
            $result['expires']=$expires->toIso8601String(); $result['recipient']=$peer;
            $result['reason']=$expires<=CarbonImmutable::now('UTC')||$sent>CarbonImmutable::now('UTC')?'WINDOW_CLOSED':null;
        } catch (\Throwable $e) {}
        return $result;
    }

    private function call(array $account,array $payload): array
    {
        $host=$account['channel']==='instagram'&&($account['login']??'facebook')==='instagram'?'graph.instagram.com':'graph.facebook.com';
        $sendId=$account['channel']==='instagram'&&($account['login']??'facebook')==='facebook'?'me':$account['account_id'];
        $endpoint='https://'.$host.'/'.config('meta_messaging.api_version','v25.0').'/'.$sendId.'/messages';
        $token=$account['access_token'];
        if ($this->transport) return ($this->transport)($endpoint,$payload,$token);
        return $this->network($endpoint,$payload,$token);
    }
    private function network(string $endpoint,array $payload,string $token,bool $multipart=false): array
    {
        $body=''; $curl=curl_init($endpoint);
        $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
        if (!$multipart) $headers[]='Content-Type: application/json';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$payload:json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function ($c,$chunk) use (&$body) {
                if (strlen($body)+strlen($chunk)>1048576) return 0; $body.=$chunk; return strlen($chunk);
            }]);
        try { $ok=curl_exec($curl); return ['status'=>$ok===false?0:(int)curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$ok===false?'':$body]; }
        finally { curl_close($curl); }
    }
    private function thread(int $id,bool $lock=false): object
    {
        abort_unless(app(MetaInboxAccess::class)->available()&&Schema::hasTable('meta_reply_requests'),503);
        $q=MetaInboxAccess::scope(DB::table('meta_inbox_conversations'))->where('id',$id);
        $thread=($lock?$q->lockForUpdate():$q)->first(); abort_unless($thread,404); return $thread;
    }
    private function unresolved(int $id,bool $lock=false): bool
    {
        $q=DB::table('meta_reply_requests')->where('conversation_id',$id)->whereIn('state',['CLAIMED','UNKNOWN']);
        return $lock?$q->lockForUpdate()->first()!==null:$q->exists();
    }
    private function configured(?array $account): bool
    {
        $token=$account['access_token']??null;
        return is_string($token)&&strlen($token)>=16&&strlen($token)<=4096&&!preg_match('/[\s\x00-\x1f\x7f]/',$token)
            &&($this->transport!==null||function_exists('curl_init'));
    }
    private function uuid($uuid): bool { return is_string($uuid)&&preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',$uuid)===1; }
    private function requestHash(int $actor,int $conversation,string $uuid): string { return hash_hmac('sha256','meta-reply-v1:'.$actor.':'.$conversation.':'.strtolower($uuid),Crypt::getKey()); }
    private function publicState(string $state): string { return in_array($state,['ACCEPTED','FAILED'],true)?$state:'UNKNOWN'; }
    private function result(string $state,?int $id,?string $reason,bool $replayed=false): array { return ['success'=>$state==='ACCEPTED','state'=>$state,'reply_id'=>$id,'reason'=>$reason,'replayed'=>$replayed]; }

    /** Commit acceptance first. This independent projection never impersonates a native webhook. */
    public function projectAcceptedReply(object $reply): ?int
    {
        if(($reply->state??null)!=='ACCEPTED'||($reply->kind??null)!=='text')return null;
        if(DB::connection()->transactionLevel()!==0){
            // A sender's refresh guard may validate prior committed evidence while
            // holding the thread claim. It must never publish missing evidence there.
            $existing=$this->existingAcceptedProjection($reply,true);
            if($existing===null)throw new \RuntimeException('ACCEPTED_EVIDENCE_PENDING');
            return $existing;
        }
        $observed=DB::table('meta_reply_requests')->where('id',$reply->id)->first();
        if(!$observed)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $audit=$this->decodeEvidence($observed->audit,65536);
        if(!array_key_exists('order_followup',$audit))return null; // Manual replies retain their native-echo path.
        return DB::transaction(function()use($observed){
            // The Consumer and sender both lock the scoped thread before its message/receipt rows.
            $thread=$this->thread((int)$observed->conversation_id,true);
            $fresh=DB::table('meta_reply_requests')->where('id',$observed->id)->lockForUpdate()->first();
            $e=$this->acceptedEvidence($thread,$fresh,true);
            $existing=DB::table('meta_inbox_messages')->where('message_key',$e['native_message_key'])->lockForUpdate()->first();
            if($existing){
                if((int)$existing->conversation_id!==(int)$thread->id)throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
                if($existing->source===self::EVIDENCE_SOURCE){
                    if($this->unwrapAcceptedEvidence($thread,$existing,true)['reply_id']!==(int)$fresh->id)
                        throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
                }else{$this->assertNativeMatches($thread,$existing,$e);}
                return (int)$existing->id;
            }
            $stamp=now('UTC')->format('Y-m-d H:i:s');
            $id=DB::table('meta_inbox_messages')->insertGetId(['conversation_id'=>(int)$thread->id,'message_key'=>$e['native_message_key'],
                'type'=>'text','direction'=>'outbound','source'=>self::EVIDENCE_SOURCE,
                'content'=>Crypt::encryptString(json_encode($this->evidenceEnvelope($fresh,$e),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                'sent_at'=>$e['sent_at'],'created_at'=>$stamp,'updated_at'=>$stamp]);
            DB::table('meta_inbox_conversations')->where('id',$thread->id)->update([
                'last_message_at'=>$thread->last_message_at===null?$e['sent_at']:max($thread->last_message_at,$e['sent_at']),'updated_at'=>$stamp]);
            return (int)$id;
        },3);
    }

    private function existingAcceptedProjection(object $observed,bool $lock): ?int
    {
        $thread=$this->thread((int)$observed->conversation_id,$lock);
        $q=DB::table('meta_reply_requests')->where('id',$observed->id);
        $fresh=($lock?$q->lockForUpdate():$q)->first();$e=$this->acceptedEvidence($thread,$fresh,$lock);
        $q=DB::table('meta_inbox_messages')->where('message_key',$e['native_message_key']);
        $row=($lock?$q->lockForUpdate():$q)->first();if(!$row)return null;
        if((int)$row->conversation_id!==(int)$thread->id)throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
        if($row->source===self::EVIDENCE_SOURCE){
            if($this->unwrapAcceptedEvidence($thread,$row,$lock)['reply_id']!==(int)$fresh->id)throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
        }else{$this->assertNativeMatches($thread,$row,$e);}
        return (int)$row->id;
    }

    /** Retry unmaterialized owned acceptances only; snapshots authenticate existing history on use. */
    public function projectPending(int $limit=100,?int $conversation=null,?string $since=null): array
    {
        if($limit<1||$limit>500||($conversation!==null&&$conversation<1))throw new \InvalidArgumentException('INVALID_EVIDENCE_LIMIT');
        if($since!==null)$this->evidenceTime($since);
        $out=['processed'=>0,'projected'=>0,'replayed'=>0,'invalid'=>0,'remaining'=>0,'complete'=>true,
            'failed_conversation_ids'=>[],'pending_conversation_ids'=>[]];
        if(!Schema::hasTable('meta_reply_requests'))return $out;
        // Only owned acceptance writes this native-key metadata. Manual receipts
        // and already materialized history must not consume the retry budget.
        // Every projected row is authenticated again when a snapshot uses it.
        $q=MetaInboxAccess::scope(DB::table('meta_reply_requests as r'),'r.')->where('r.state','ACCEPTED')->where('r.kind','text')
            ->whereNotNull('r.remote_message_key')->whereNotExists(function($q){
                $q->selectRaw('1')->from('meta_inbox_messages as m')->whereColumn('m.message_key','r.remote_message_key')
                    ->whereColumn('m.conversation_id','r.conversation_id')->where('m.direction','outbound')->where('m.type','text')
                    ->whereIn('m.source',[self::EVIDENCE_SOURCE,'message_echoes','standby']);
            });
        if($conversation!==null)$q->where('r.conversation_id',$conversation);
        if($since!==null)$q->where('r.completed_at','>=',$since);
        $rows=$q->orderByDesc('r.id')->limit($limit+1)->select('r.*')->get();
        if(count($rows)>$limit){
            $out['remaining']=1;$out['complete']=false;
            $out['pending_conversation_ids']=array_values(array_unique($rows->pluck('conversation_id')->map(fn($id)=>(int)$id)->all()));
        }
        foreach($rows->take($limit)as$row){
            try{
                $audit=$this->decodeEvidence($row->audit,65536);
                if(!array_key_exists('order_followup',$audit))throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
                $out['processed']++;
                $before=is_string($row->remote_message_key)&&DB::table('meta_inbox_messages')->where('message_key',$row->remote_message_key)->exists();
                $id=$this->projectAcceptedReply($row);
                if($id===null)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
                $out[$before?'replayed':'projected']++;
            }catch(\Throwable$error){
                $out['invalid']++;$out['complete']=false;$out['failed_conversation_ids'][]=(int)$row->conversation_id;
            }
        }
        $out['failed_conversation_ids']=array_values(array_unique($out['failed_conversation_ids']));
        return $out;
    }

    /** Pure receipt authentication used by order snapshots and follow-up ownership checks. */
    public function unwrapAcceptedEvidence(object $thread,object $row,bool $lock=false): array
    {
        if(($row->source??null)!==self::EVIDENCE_SOURCE||($row->direction??null)!=='outbound'||($row->type??null)!=='text'
            ||(int)($row->conversation_id??0)!==(int)$thread->id)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $saved=$this->decodeEvidence($row->content,65536);
        if(($saved['schema']??null)!==self::EVIDENCE_SCHEMA||!is_int($saved['reply_id']??null)||$saved['reply_id']<1)
            throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $q=DB::table('meta_reply_requests')->where('id',$saved['reply_id']);
        $reply=($lock?$q->lockForUpdate():$q)->first();$e=$this->acceptedEvidence($thread,$reply,$lock);
        if($saved!==$this->evidenceEnvelope($reply,$e)||$row->sent_at!==$e['sent_at']
            ||!hash_equals((string)$row->message_key,$e['native_message_key']))throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        return $e;
    }

    /** A genuine echo may use a different event time; immutable acceptance remains canonical. */
    public function matchesAcceptedNativeEcho(object $thread,object $row,array $native,bool $lock=false): bool
    {
        try{$e=$this->unwrapAcceptedEvidence($thread,$row,$lock);return $this->nativeMatches($thread,$native,$e);}
        catch(\Throwable$error){return false;}
    }

    public function validateNativeReceipt(object $thread,object $row,bool $lock=false): void
    {
        if(($row->source??null)===self::EVIDENCE_SOURCE||($row->direction??null)!=='outbound'||($row->type??null)!=='text'
            ||!Schema::hasTable('meta_reply_requests'))return;
        $q=DB::table('meta_reply_requests')->where('remote_message_key',$row->message_key)->where('state','ACCEPTED')->where('kind','text')->limit(2);
        $rows=($lock?$q->lockForUpdate():$q)->get();
        if(count($rows)>1)throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
        if($rows->isEmpty())return;
        $e=$this->acceptedEvidence($thread,$rows->first(),$lock);$this->assertNativeMatches($thread,$row,$e);
    }

    /** Pure ownership proof when the genuine echo won the insert race. */
    public function acceptedReceiptForNative(object $thread,object $row,bool $lock=false): ?array
    {
        if(($row->source??null)===self::EVIDENCE_SOURCE)return $this->unwrapAcceptedEvidence($thread,$row,$lock);
        if(($row->direction??null)!=='outbound'||($row->type??null)!=='text')return null;
        $q=DB::table('meta_reply_requests')->where('conversation_id',$thread->id)->where('waba_id',$thread->waba_id)
            ->where('phone_number_id',$thread->phone_number_id)->where('remote_message_key',$row->message_key)
            ->where('state','ACCEPTED')->where('kind','text')->limit(2);
        $rows=($lock?$q->lockForUpdate():$q)->get();
        if(count($rows)>1)throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
        if($rows->isEmpty())return null;
        $e=$this->acceptedEvidence($thread,$rows->first(),$lock);$this->assertNativeMatches($thread,$row,$e);
        $e['accepted_at']=$e['sent_at'];$e['sent_at']=$row->sent_at;return $e;
    }

    private function acceptedEvidence(object $thread,?object $r,bool $lock): array
    {
        $scope=$thread->waba_id.':'.$thread->phone_number_id.':';
        if(!MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id)||!$r||$r->state!=='ACCEPTED'||$r->kind!=='text'
            ||$r->waba_id!==$thread->waba_id||$r->phone_number_id!==$thread->phone_number_id
            ||(int)$r->conversation_id!==(int)$thread->id||(int)$r->actor_id<1)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $audit=$this->decodeEvidence($r->audit,65536);$response=$this->decodeEvidence($r->response_details,65536);
        $id=$response['message_id']??null;$text=$audit['text']??null;$peer=$audit['recipient']??null;$uuid=$audit['client_request_id']??null;
        if(!is_string($id)||$id===''||strlen($id)>512||preg_match('/[\x00-\x1f\x7f]/',$id)
            ||!is_string($text)||trim($text)===''||!mb_check_encoding($text,'UTF-8')||mb_strlen($text,'UTF-8')>1000
            ||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)||!\App\Support\MetaMessagingProtocol::id($peer)
            ||!is_string($uuid)||!$this->uuid($uuid)
            ||!MetaOrderFollowupService::validMarker($audit['order_followup']??null,(int)$thread->id,$uuid,(int)$r->inbound_message_id)
            ||!hash_equals((string)$r->request_hash,$this->requestHash((int)$r->actor_id,(int)$thread->id,$uuid))
            ||!hash_equals((string)$r->payload_hash,$this->textPayloadHash((int)$r->inbound_message_id,$text,$audit['order_followup']))
            ||!hash_equals((string)$r->remote_message_key,hash('sha256','whatsapp-message-v1:'.$scope.$id)))
            throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $q=DB::table('meta_inbox_messages')->where('id',$r->inbound_message_id)->where('conversation_id',$thread->id)->where('direction','inbound')
            ->select('id','message_key','direction','type','source','sent_at')->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content');
        $inbound=($lock?$q->lockForUpdate():$q)->first();
        if(!$inbound)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $native=$this->decodeEvidence($inbound->content,4194304);$identity='user:'.$peer;
        if(($native['channel']??null)!==$thread->waba_id||($native['account_id']??null)!==$thread->phone_number_id
            ||($native['peer_identity']??null)!==$identity||($native['peer_user_id']??null)!==$peer
            ||($native['direction']??null)!=='inbound'||($native['type']??null)!==$inbound->type
            ||($native['source']??null)!==$inbound->source||!in_array($inbound->source,['messages','standby'],true)
            ||($native['sent_at']??null)!==$inbound->sent_at||!is_string($native['message_id']??null)
            ||($native['content']['message']['id']??null)!==$native['message_id']
            ||($native['content']['message']['meta']['mid']??null)!==$native['message_id']
            ||!hash_equals((string)$inbound->message_key,hash('sha256','whatsapp-message-v1:'.$scope.$native['message_id']))
            ||!hash_equals((string)$thread->peer_hash,hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$identity,Crypt::getKey())))
            throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $claimed=$this->evidenceTime($r->claimed_at);$started=$this->evidenceTime($r->send_started_at);$accepted=$this->evidenceTime($r->completed_at);
        $at=$this->evidenceTime($inbound->sent_at);
        if($claimed>$started||$started>$accepted||$at>$started||$accepted>now('UTC')||$accepted->getTimestamp()>=$at->getTimestamp()+86400)
            throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        return ['reply_id'=>(int)$r->id,'native_id'=>$id,'peer_identity'=>$identity,'peer_user_id'=>$peer,'peer_phone'=>null,
            'text'=>$text,'sent_at'=>$r->completed_at,'native_message_key'=>$r->remote_message_key,
            'order_followup'=>$audit['order_followup'],'client_request_id'=>$uuid,
            'audit'=>['order_followup'=>$audit['order_followup'],'client_request_id'=>$uuid],
            'fingerprint'=>hash('sha256',$r->payload_hash.':'.$id)];
    }

    private function evidenceEnvelope(object $reply,array $e): array
    {
        return ['schema'=>self::EVIDENCE_SCHEMA,'reply_id'=>(int)$reply->id,'text'=>$e['text'],
            'receipt_hash'=>hash('sha256',json_encode([$reply->audit,$reply->response_details,$reply->request_hash,$reply->payload_hash,
                $reply->remote_message_key,$reply->claimed_at,$reply->send_started_at,$reply->completed_at],JSON_THROW_ON_ERROR))];
    }

    private function assertNativeMatches(object $thread,object $row,array $e): void
    {
        $native=$this->decodeEvidence($row->content,4194304);
        if((int)$row->conversation_id!==(int)$thread->id||$row->direction!=='outbound'||$row->type!=='text'
            ||!hash_equals((string)$row->message_key,$e['native_message_key'])
            ||($native['source']??null)!==$row->source||($native['sent_at']??null)!==$row->sent_at
            ||!$this->nativeMatches($thread,$native,$e))throw new \RuntimeException('CONFLICTING_ACCEPTED_REPLY');
        $this->evidenceTime($row->sent_at);
    }

    private function nativeMatches(object $thread,array $m,array $e): bool
    {
        return ($m['channel']??null)===$thread->waba_id&&($m['account_id']??null)===$thread->phone_number_id
            &&($m['message_id']??null)===$e['native_id']&&($m['peer_identity']??null)===$e['peer_identity']
            &&($m['peer_user_id']??null)===$e['peer_user_id']&&($m['direction']??null)==='outbound'
            &&($m['peer_phone']??null)===null&&($m['content']['message']['meta']['is_deleted']??false)!==true
            &&($m['type']??null)==='text'&&($m['text']??null)===$e['text']&&in_array($m['source']??null,['message_echoes','standby'],true)
            &&($m['content']['message']['id']??null)===$e['native_id']&&($m['content']['message']['meta']['mid']??null)===$e['native_id']
            &&($m['content']['message']['meta']['text']??null)===$e['text']&&empty($m['content']['message']['meta']['attachments']);
    }

    private function decodeEvidence($cipher,int $limit): array
    {
        if(!is_string($cipher)||strlen($cipher)>$limit*2+4096)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $plain=Crypt::decryptString($cipher);if(strlen($plain)>$limit)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $data=json_decode($plain,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('INVALID_ACCEPTED_REPLY');return $data;
    }

    private function textPayloadHash(int $inbound,string $text,?array $marker): string
    {
        $values=[$inbound,$text];if($marker!==null)$values[]=$marker;
        return hash_hmac('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey());
    }

    private function evidenceTime($value): \DateTimeImmutable
    {
        if(!is_string($value))throw new \RuntimeException('INVALID_ACCEPTED_REPLY');
        $time=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        if(!$time||$time->format('Y-m-d H:i:s')!==$value)throw new \RuntimeException('INVALID_ACCEPTED_REPLY');return $time;
    }

    private function finish(int $id,string $state,?string $reason,array $from,?string $mid=null,bool $owned=false): array
    {
        $values=['state'=>$state,'reason'=>$reason,'completed_at'=>now('UTC'),'updated_at'=>now('UTC')];
        if ($mid!==null){
            $values['response_details']=Crypt::encryptString(json_encode(['message_id'=>$mid],JSON_THROW_ON_ERROR));
            $receipt=DB::table('meta_reply_requests')->where('id',$id)->first();
            if($owned&&(!$receipt||$receipt->kind!=='text'))return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
            if($receipt&&$receipt->kind==='text'){
                try{
                    $audit=$this->decodeEvidence($receipt->audit,65536);
                    if($owned||array_key_exists('order_followup',$audit)){
                        $values['remote_message_key']=hash('sha256','whatsapp-message-v1:'.$receipt->waba_id.':'.$receipt->phone_number_id.':'.$mid);
                        $candidate=clone$receipt;foreach($values as$key=>$value)$candidate->$key=is_string($value)||$value===null?$value:$value->format('Y-m-d H:i:s');
                        $this->acceptedEvidence($this->thread((int)$receipt->conversation_id),$candidate,false);
                    }
                }catch(\Throwable$error){
                    // Provider acceptance with damaged ownership stays uncertain;
                    // it must never produce an unindexed owned ACCEPTED receipt.
                    return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
                }
            }
        }
        $changed=DB::table('meta_reply_requests')->where('id',$id)->whereIn('state',$from)->update($values);
        if($changed===1&&$state==='ACCEPTED'){
            try{$receipt=DB::table('meta_reply_requests')->where('id',$id)->first();if($receipt)$this->projectAcceptedReply($receipt);}
            catch(\Throwable$error){ /* The committed acceptance is retained; bounded workflow projection reports/retries it. */ }
        }
        return $changed===1?$this->result($state,$id,$reason):$this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
    }
}
