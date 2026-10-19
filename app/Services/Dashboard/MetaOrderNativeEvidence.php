<?php

namespace App\Services\Dashboard;

use App\Support\MetaMessagingProtocol;
use App\Support\WhatsAppOrderExtraction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Native Messenger/Instagram evidence. No WhatsApp envelope or synthetic customer message is created. */
class MetaOrderNativeEvidence
{
    public function thread(int $conversation,bool $lock=false): object
    {
        $query=MetaInboxAccess::scope(DB::table('meta_inbox_conversations'))->where('id',$conversation);
        $thread=($lock?$query->lockForUpdate():$query)->first();
        if (!$thread) throw new \RuntimeException('META_SCOPE_UNAVAILABLE');
        return $thread;
    }

    public function projected(object $thread,object $row,bool $lock=false): array
    {
        if ((int)$row->conversation_id!==(int)$thread->id
            ||MetaInboxAccess::account((string)$thread->waba_id,(string)$thread->phone_number_id)===null)
            throw new \RuntimeException('META_SCOPE_UNAVAILABLE');
        if ($row->source==='dashboard_api') {
            $receipt=app(MetaReplyService::class)->unwrapAcceptedEvidence($thread,$row,$lock);
            if (!is_array($receipt)||!is_string($receipt['text']??null)) throw new \RuntimeException('INVALID_META_RECEIPT');
            return ['id'=>(int)$row->id,'conversation_id'=>(int)$thread->id,'direction'=>'outbound','kind'=>'text',
                'text'=>$receipt['text'],'sent_at'=>$receipt['sent_at'],'native_message_key'=>$receipt['native_message_key'],
                'location'=>null,'cart'=>null,'peer_user_id'=>$receipt['peer_user_id']??null,'raw'=>null,
                'fingerprint'=>$receipt['fingerprint'],'reply_id'=>$receipt['reply_id']??null];
        }
        if (!is_string($row->content)||strlen($row->content)>8388608) throw new \RuntimeException('INVALID_META_NATIVE');
        $plain=Crypt::decryptString($row->content);
        if (strlen($plain)>4194304) throw new \RuntimeException('INVALID_META_NATIVE');
        $dto=json_decode($plain,true,64,JSON_THROW_ON_ERROR); $raw=$dto['content']['message']['meta']??null;
        $peer=$dto['peer_user_id']??null; $mid=$dto['message_id']??null;
        $scope=$thread->waba_id.':'.$thread->phone_number_id.':';
        if (!is_array($dto)||!is_array($raw)||!MetaMessagingProtocol::id($peer)
            ||!is_string($mid)||$mid===''||strlen($mid)>512||preg_match('/[\x00-\x1f\x7f]/',$mid)
            ||($raw['mid']??null)!==$mid||($dto['content']['message']['id']??null)!==$mid
            ||($dto['channel']??null)!==$thread->waba_id||($dto['account_id']??null)!==$thread->phone_number_id
            ||($dto['peer_identity']??null)!=='user:'.$peer||($dto['peer_phone']??null)!==null
            ||($dto['direction']??null)!==$row->direction||($dto['type']??null)!==$row->type
            ||($dto['source']??null)!==$row->source||($dto['sent_at']??null)!==$row->sent_at
            ||!in_array($row->direction,['inbound','outbound'],true)
            ||!in_array($row->source,$row->direction==='inbound'?['messages','standby']:['message_echoes','standby'],true)
            ||(($raw['is_echo']??false)===true&&$row->direction!=='outbound')
            ||!hash_equals((string)$thread->peer_hash,hash_hmac('sha256','whatsapp-peer-v1:'.$scope.'user:'.$peer,Crypt::getKey()))
            ||!hash_equals((string)$row->message_key,hash('sha256','whatsapp-message-v1:'.$scope.$mid)))
            throw new \RuntimeException('INVALID_META_NATIVE');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$row->sent_at,new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        if (!$date||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0))
            ||$date->format('Y-m-d H:i:s')!==$row->sent_at||substr($row->sent_at,0,4)==='0000')
            throw new \RuntimeException('INVALID_META_NATIVE');
        $text=$raw['text']??null;
        if ($text!==null&&(!is_string($text)||!mb_check_encoding($text,'UTF-8')||strlen($text)>100000)
            ||$text!==($dto['text']??null)||($raw['is_deleted']??false)===true)
            throw new \RuntimeException('INVALID_META_NATIVE');
        $attachments=$raw['attachments']??[];
        if (!is_array($attachments)||!array_is_list($attachments)||count($attachments)>30)
            throw new \RuntimeException('INVALID_META_NATIVE');
        $kind=$text===null?'other':'text'; $location=null;
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) throw new \RuntimeException('INVALID_META_NATIVE');
            $type=$attachment['type']??'other';
            if ($text===null&&$kind==='other') $kind=in_array($type,['image','video','audio','location'],true)?$type:'document';
            if ($type==='location') {
                $point=$attachment['payload']['coordinates']??null;
                if (!is_array($point)||!is_numeric($point['lat']??null)||!is_numeric($point['long']??null)
                    ||!is_finite((float)$point['lat'])||!is_finite((float)$point['long'])
                    ||abs((float)$point['lat'])>90||abs((float)$point['long'])>180) throw new \RuntimeException('INVALID_META_NATIVE');
                $location=['lat'=>(float)$point['lat'],'long'=>(float)$point['long']];
            }
        }
        $storedPoint=$dto['content']['message']['location']??null;
        if (is_array($storedPoint)&&count($storedPoint)===2&&is_numeric($storedPoint['latitude']??null)&&is_numeric($storedPoint['longitude']??null))
            $storedPoint=['latitude'=>(float)$storedPoint['latitude'],'longitude'=>(float)$storedPoint['longitude']];
        if ($kind!==$row->type||($location===null?$storedPoint!==null:
            $storedPoint!==['latitude'=>$location['lat'],'longitude'=>$location['long']])) throw new \RuntimeException('INVALID_META_NATIVE');
        return ['id'=>(int)$row->id,'conversation_id'=>(int)$thread->id,'direction'=>$row->direction,'kind'=>$kind,
            'text'=>$text,'sent_at'=>$row->sent_at,'native_message_key'=>$row->message_key,'location'=>$location,'cart'=>null,
            'peer_user_id'=>$peer,'raw'=>$raw,'fingerprint'=>hash_hmac('sha256',$plain,Crypt::getKey()),'reply_id'=>null];
    }

    public function snapshot(int $conversation,int $floor,int $ceiling,bool $lock=false): array
    {
        if ($floor<0||$ceiling<$floor) throw new \RuntimeException('INVALID_META_BOUNDARY');
        $thread=$this->thread($conversation,$lock);
        $query=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('id','>',$floor)->where('id','<=',$ceiling)
            ->orderByDesc('id')->limit(61);
        $rows=($lock?$query->lockForUpdate():$query)->get()->all(); $complete=count($rows)<=60; $rows=array_slice($rows,0,60);
        usort($rows,static fn($a,$b)=>strcmp((string)$a->sent_at,(string)$b->sent_at)?:((int)$a->id<=>(int)$b->id));
        $transcript=[]; $keys=[]; $identities=[]; $characters=0;
        foreach ($rows as $position=>$row) {
            $native=$this->projected($thread,$row,$lock); $label='m'.($position+1);
            $transcript[]=['id'=>$label,'speaker'=>$native['direction']==='inbound'?'customer':'business',
                'sent_at'=>$native['sent_at'],'text'=>$native['text'],'location'=>$native['location']];
            $keys[$label]=$native['native_message_key']; $characters+=is_string($native['text'])?mb_strlen($native['text'],'UTF-8'):0;
            $identities[]=[(int)$row->id,$native['native_message_key'],$native['direction'],$native['kind'],$native['sent_at'],
                $native['text'],$native['location'],$native['fingerprint']];
        }
        if ($characters>16000) $complete=false;
        return ['transcript'=>$transcript,'keys'=>$keys,'hash'=>hash_hmac('sha256',PosServiceTicket::fingerprint($identities),Crypt::getKey()),
            'complete'=>$complete,'recovered_at'=>null];
    }

    public function latestInbound(int $conversation,array $snapshot): ?array
    {
        if (($snapshot['complete']??null)!==true||!WhatsAppOrderExtraction::validateTranscript($snapshot['transcript']??[])['ok']) return null;
        $thread=$this->thread($conversation);
        $row=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')
            ->orderByDesc('sent_at')->orderByDesc('id')->first();
        if (!$row) return null;
        $native=$this->projected($thread,$row); $label=array_search($row->message_key,$snapshot['keys']??[],true);
        if ($label===false) return null;
        foreach ($snapshot['transcript'] as $message) if ($message['id']===$label) {
            if ($message['speaker']!=='customer'||$message['sent_at']!==$native['sent_at']
                ||$message['text']!==$native['text']||$message['location']!==$native['location']) return null;
            return ['id'=>(int)$row->id,'message_key'=>$row->message_key,'sent_at'=>$row->sent_at,
                'label'=>$label,'text'=>$native['text'],'kind'=>$native['kind']];
        }
        return null;
    }
}
