<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Manual native replies. Every customer-facing send has a durable uncertainty marker and UUID. */
class MetaReplyService
{
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
        $actor=app(MetaInboxAccess::class)->actor($actor);
        $wanted=['client_request_id','expected_inbound_id','text'];
        abort_unless(!array_diff(array_keys($input),$wanted)&&!array_diff($wanted,array_keys($input))
            &&$this->uuid($input['client_request_id']??null),422);
        $inbound=filter_var($input['expected_inbound_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $text=$input['text'];
        abort_unless(is_int($inbound)&&is_string($text)&&mb_check_encoding($text,'UTF-8')&&trim($text)!==''
            &&mb_strlen($text,'UTF-8')<=1000&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text),422);
        $hash=$this->requestHash((int)$actor->id,$conversation,$input['client_request_id']);
        $payloadHash=hash_hmac('sha256',json_encode([$inbound,$text],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey());
        $claim=DB::transaction(function () use ($actor,$conversation,$input,$inbound,$text,$hash,$payloadHash) {
            $actor=app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation,true);
            $old=DB::table('meta_reply_requests')->where('request_hash',$hash)->lockForUpdate()->first();
            if ($old) { abort_unless(hash_equals($old->payload_hash,$payloadHash),409); return ['result'=>$this->result($this->publicState($old->state),(int)$old->id,$old->reason,true)]; }
            $snapshot=$this->snapshot($thread,true); $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
            $reason=!$this->configured($account)?'REPLIES_UNAVAILABLE':($this->unresolved($conversation,true)?'UNKNOWN_PENDING':$snapshot['reason']);
            if ($reason===null&&$snapshot['inbound_id']!==$inbound) $reason='STALE_INBOUND';
            if ($reason!==null) return ['result'=>$this->result('FAILED',null,$reason)];
            $id=DB::table('meta_reply_requests')->insertGetId([
                'conversation_id'=>$conversation,'actor_id'=>$actor->id,'waba_id'=>$thread->waba_id,'phone_number_id'=>$thread->phone_number_id,
                'request_hash'=>$hash,'payload_hash'=>$payloadHash,'inbound_message_id'=>$inbound,'kind'=>'text','state'=>'CLAIMED',
                'audit'=>Crypt::encryptString(json_encode(['client_request_id'=>strtolower($input['client_request_id']),'text'=>$text,'recipient'=>$snapshot['recipient']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)),
                'claimed_at'=>now('UTC'),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            return ['id'=>(int)$id,'snapshot'=>$snapshot];
        },3);
        if (isset($claim['result'])) return $claim['result']; $id=$claim['id'];
        try {
            $reason=$this->preflight($actor,$conversation,$claim['snapshot']);
            if ($reason!==null) return $this->finish($id,'FAILED',$reason,['CLAIMED']);
            $changed=DB::table('meta_reply_requests')->where('id',$id)->where('state','CLAIMED')->whereNull('send_started_at')
                ->update(['state'=>'UNKNOWN','reason'=>'DELIVERY_UNKNOWN','send_started_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            if ($changed!==1) return $this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
            $reason=$this->preflight($actor,$conversation,$claim['snapshot']);
            if ($reason!==null) return $this->finish($id,'FAILED',$reason,['UNKNOWN']);
            $thread=$this->thread($conversation); $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
            $payload=['recipient'=>['id'=>$claim['snapshot']['recipient']],'message'=>['text'=>$text]];
            if ($thread->waba_id==='messenger') $payload['messaging_type']='RESPONSE';
            $response=$this->call($account,$payload); $body=json_decode($response['body'],true);
            if ($response['status']===200&&is_array($body)&&!isset($body['error'])&&is_string($body['message_id']??null)
                &&$body['message_id']!==''&&strlen($body['message_id'])<=512
                &&(!isset($body['recipient_id'])||$body['recipient_id']===$claim['snapshot']['recipient'])) {
                return $this->finish($id,'ACCEPTED',null,['UNKNOWN'],$body['message_id']);
            }
            if (in_array($response['status'],[400,401,403,404,405,409,410,413,415,422,429],true)&&is_int($body['error']['code']??null)) return $this->finish($id,'FAILED','META_REJECTED',['UNKNOWN']);
        } catch (\Throwable $error) {} // A transport timeout may follow a real send; never retry blindly.
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

    private function preflight($actor,int $conversation,array $expected): ?string
    {
        try {
            app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation);
            if (!$this->configured(MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id))) return 'REPLIES_UNAVAILABLE';
            $snapshot=$this->snapshot($thread);
            if (($snapshot['account_fingerprint']??null)!==($expected['account_fingerprint']??null)) return 'REPLIES_UNAVAILABLE';
            return $snapshot['reason']??(($snapshot['inbound_id']===$expected['inbound_id']&&$snapshot['recipient']===$expected['recipient'])?null:'STALE_INBOUND');
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
    private function finish(int $id,string $state,?string $reason,array $from,?string $mid=null): array
    {
        $values=['state'=>$state,'reason'=>$reason,'completed_at'=>now('UTC'),'updated_at'=>now('UTC')];
        if ($mid!==null) $values['response_details']=Crypt::encryptString(json_encode(['message_id'=>$mid],JSON_THROW_ON_ERROR));
        $changed=DB::table('meta_reply_requests')->where('id',$id)->whereIn('state',$from)->update($values);
        return $changed===1?$this->result($state,$id,$reason):$this->result('UNKNOWN',$id,'DELIVERY_UNKNOWN');
    }
}
