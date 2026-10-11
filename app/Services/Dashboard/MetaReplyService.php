<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Manual text replies only. Customer-facing network sends always have a durable uncertainty marker. */
class MetaReplyService
{
    private $transport;
    public function __construct(?callable $transport=null) { $this->transport=$transport; }

    public function state($actor,int $conversation,?string $uuid=null): array
    {
        $actor=app(MetaInboxAccess::class)->actor($actor);
        if ($uuid!==null) abort_unless($this->uuid($uuid),422);
        $thread=$this->thread($conversation); $snapshot=$this->snapshot($thread);
        $account=MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id);
        $reason=!$this->configured($account) ? 'REPLIES_UNAVAILABLE'
            : ($this->unresolved($conversation) ? 'UNKNOWN_PENDING' : $snapshot['reason']);
        $recent=[];
        foreach (DB::table('meta_reply_requests')->where('conversation_id',$conversation)->orderByDesc('id')->limit(20)->get() as $r) {
            try {
                $audit=json_decode(Crypt::decryptString($r->audit),true,32,JSON_THROW_ON_ERROR);
                $recent[]=['reply_id'=>(int)$r->id,'client_request_id'=>$audit['client_request_id'],
                    'state'=>$this->publicState($r->state),'kind'=>'text','text'=>$audit['text'],
                    'created_at'=>CarbonImmutable::parse($r->created_at,'UTC')->toIso8601String(),'actor_id'=>(int)$r->actor_id];
            } catch (\Throwable $e) {}
        }
        $request=$uuid===null ? null : DB::table('meta_reply_requests')->where('conversation_id',$conversation)
            ->where('request_hash',$this->requestHash((int)$actor->id,$conversation,$uuid))->first();
        return ['success'=>true,'available'=>$this->configured($account),'can_reply'=>$reason===null,
            'reason'=>$reason,'latest_inbound_id'=>$snapshot['inbound_id'],'window_expires_at'=>$snapshot['expires'],
            'voice_ready'=>false,'voice_mime_types'=>[], 'request_state'=>$request?$this->publicState($request->state):null,
            'request_reason'=>$request->reason??null,'reply_id'=>$request?(int)$request->id:null,'recent_replies'=>array_reverse($recent)];
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
        app(MetaInboxAccess::class)->actor($actor); $this->thread($conversation);
        return $this->result('FAILED',null,'VOICE_UNAVAILABLE');
    }

    private function preflight($actor,int $conversation,array $expected): ?string
    {
        try {
            app(MetaInboxAccess::class)->actor($actor); $thread=$this->thread($conversation);
            if (!$this->configured(MetaInboxAccess::account($thread->waba_id,$thread->phone_number_id))) return 'REPLIES_UNAVAILABLE';
            $snapshot=$this->snapshot($thread);
            return $snapshot['reason']??(($snapshot['inbound_id']===$expected['inbound_id']&&$snapshot['recipient']===$expected['recipient'])?null:'STALE_INBOUND');
        } catch (\Throwable $error) { return 'ACCESS_REVOKED'; }
    }

    private function snapshot(object $thread,bool $lock=false): array
    {
        $result=['inbound_id'=>null,'expires'=>null,'recipient'=>null,'reason'=>'NO_INBOUND'];
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
        $body=''; $curl=curl_init($endpoint);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'],
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
