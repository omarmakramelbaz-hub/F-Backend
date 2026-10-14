<?php
namespace App\Services\Dashboard;

use App\Support\WhatsAppOrderExtraction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One bounded, receipt-backed question per genuine customer input; never an ERP command. */
class WhatsAppOrderFollowupService
{
    public const FIELDS=['NAME','PHONE','ADDRESS','ITEMS','QUANTITY','QUANTITY_MODE','FULFILLMENT','BRANCH','LOCATION','CONFIRMATION'];
    private ?WhatsAppReplyService $replies;
    private ?WhatsAppOrderAiProvider $provider;
    public function __construct(?WhatsAppReplyService $replies=null,?WhatsAppOrderAiProvider $provider=null)
    { $this->replies=$replies; $this->provider=$provider; }

    private static function fields($fields): ?array
    {
        if (!is_array($fields)||!array_is_list($fields)||!$fields||count($fields)>count(self::FIELDS)
            || count(array_unique($fields,SORT_REGULAR))!==count($fields)) return null;
        foreach ($fields as $field) if (!is_string($field)||!in_array($field,self::FIELDS,true)) return null;
        if (in_array('CONFIRMATION',$fields,true)&&count($fields)!==1) return null;
        return array_values(array_intersect(self::FIELDS,$fields));
    }

    public static function requestUuid(array $plan,string $key): string
    {
        foreach (['episode_customer_key','latest_customer_key'] as $field)
            if (!is_string($plan[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/',$plan[$field])) throw new \RuntimeException();
        if (!is_int($plan['conversation_id']??null)||$plan['conversation_id']<1||strlen($key)<16) throw new \RuntimeException();
        $scope=WhatsAppInboxAccess::WABA_ID.':'.WhatsAppInboxAccess::PHONE_ID.':';
        $hex=hash_hmac('sha256','whatsapp-order-followup-v1:'.$scope.$plan['conversation_id'].':'.$plan['latest_customer_key'],$key);
        $hex[12]='5'; $hex[16]=dechex((hexdec($hex[16])&3)|8);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    public static function validMarker($marker,int $conversation,string $uuid,int $inbound): bool
    {
        try {
            if (!is_array($marker)||count($marker)!==7||($marker['version']??null)!=='whatsapp-order-followup-v1'
                || !in_array($marker['kind']??null,['question','confirmation'],true)||self::fields($marker['fields']??null)!==($marker['fields']??null)
                || ($marker['latest_customer_id']??null)!==$inbound
                || (($marker['fields']===['CONFIRMATION'])!==($marker['kind']==='confirmation'))
                || ($marker['kind']==='question'?($marker['proposal_hash']??null)!==null
                    :(!is_string($marker['proposal_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$marker['proposal_hash'])))) return false;
            return hash_equals(self::requestUuid($marker+['conversation_id'=>$conversation],Crypt::getKey()),strtolower($uuid));
        } catch (\Throwable $ignore) { return false; }
    }

    private static function validPlan(array $plan): bool
    {
        if (count($plan)!==11) return false;
        foreach (['draft_id','conversation_id','evidence_floor','evidence_ceiling','latest_customer_id'] as $field)
            if (!is_int($plan[$field]??null)||$plan[$field]<($field==='evidence_floor'?0:1)) return false;
        foreach (['evidence_hash','latest_customer_key','episode_customer_key'] as $field)
            if (!is_string($plan[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/',$plan[$field])) return false;
        if ($plan['latest_customer_id']<=$plan['evidence_floor']||$plan['latest_customer_id']>$plan['evidence_ceiling']
            || self::fields($plan['fields']??null)===null||!is_array($plan['data']??null)||!is_array($plan['transcript']??null)
            || WhatsAppOrderExtraction::hasProbe($plan['transcript'])) return false;
        $checked=WhatsAppOrderExtraction::validate($plan['data'],$plan['transcript']);
        return ($checked['ok']??null)===true && in_array($checked['data']['decision']??null,['DRAFT','CONFIRMED'],true);
    }

    public static function question(array $fields,string $variant,array $data,array $transcript=[]): string
    {
        $fields=self::fields($fields); if ($fields===null||!in_array($variant,['SHORT','POLITE'],true)) throw new \RuntimeException();
        if ($fields===['CONFIRMATION']) {
            $customer=$data['customer']??[]; $items=$data['items']??[];
            foreach (['name','phone','address'] as $field) if (!self::recapLiteral($customer[$field]??null)) throw new \RuntimeException();
            foreach ([$customer['area']??null,$customer['notes']??null,$data['branch_hint']??null] as $value)
                if ($value!==null&&!self::recapLiteral($value)) throw new \RuntimeException();
            $fulfillment=self::fulfillment($data,$transcript);
            if (!$items||!in_array($fulfillment,['DELIVERY','PICKUP'],true)) throw new \RuntimeException();
            $lines=[];
            foreach ($items as $item) {
                if (!is_array($item)||!self::recapLiteral($item['name']??null)
                    || !is_string($item['quantity']??null)||!preg_match('/\A(?:[1-9][0-9]{0,3}|0)(?:\.[0-9]{1,3})?\z/',$item['quantity'])
                    || (float)$item['quantity']<=0||!in_array($item['quantity_mode']??null,['weight','piece'],true)
                    || (($item['option_hint']??null)!==null&&!self::recapLiteral($item['option_hint']))) throw new \RuntimeException();
                $lines[]=$item['quantity'].' '.($item['quantity_mode']==='weight'?'كيلو':'قطعة').' '.$item['name']
                    .(is_string($item['option_hint']??null)&&$item['option_hint']!==''?' '.$item['option_hint']:'');
            }
            $text="تأكيد الطلب:\n".implode('؛ ',$lines)."\nالاسم: ".$customer['name']."\nرقم التواصل: ".$customer['phone']
                ."\nالعنوان: ".$customer['address'].(is_string($customer['area']??null)&&$customer['area']!==''?"\nالمنطقة: ".$customer['area']:'')
                .(is_string($data['branch_hint']??null)&&$data['branch_hint']!==''?"\nالفرع: ".$data['branch_hint']:'')
                .(is_string($customer['notes']??null)&&$customer['notes']!==''?"\nملاحظات: ".$customer['notes']:'')
                ."\n".($fulfillment==='DELIVERY'?'طريقة الاستلام: دليفري.':'طريقة الاستلام: من الفرع.')
                ."\n".($variant==='POLITE'?'من فضلك ':'').'اكتب موافق للموافقة على البيانات والطلب المذكور.';
        } else {
            $labels=['NAME'=>'اسم حضرتك','PHONE'=>'رقم هاتف للتواصل','ADDRESS'=>'عنوان التوصيل بالتفصيل','ITEMS'=>'الأصناف المطلوبة',
                'QUANTITY'=>'كمية كل صنف','QUANTITY_MODE'=>'الكمية بالكيلو ولا بالقطعة','FULFILLMENT'=>'الطلب دليفري ولا في الصالة أو تيك أواي',
                'BRANCH'=>'الفرع أو المدينة المطلوبة','LOCATION'=>'لوكيشن عنوان التوصيل من واتساب'];
            $text=($variant==='POLITE'?'من فضلك ابعت لنا: ':'ابعت: ').implode('، ',array_map(static fn($f)=>$labels[$f],$fields)).' علشان نكمّل بيانات طلبك.';
        }
        if (!mb_check_encoding($text,'UTF-8')||mb_strlen($text,'UTF-8')>4096||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) throw new \RuntimeException();
        return $text;
    }

    private static function recapLiteral($value): bool
    { return is_string($value)&&trim($value)!==''&&mb_strlen($value,'UTF-8')<=1000&&mb_check_encoding($value,'UTF-8')&&!preg_match('/[\x00-\x1f\x7f]/',$value); }

    private static function fulfillment(array $data,array $transcript=[]): string
    {
        $value=$data['fulfillment']??'UNKNOWN';
        return $transcript?WhatsAppOrderAiProvider::followupFulfillment($data,$transcript):$value;
    }

    private static function proposalHash(array $data,array $transcript=[]): string
    {
        $normalize=static fn($value)=>$value===null?null:mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$value)),'UTF-8');
        $customer=[]; foreach (['name','phone','address','area','notes'] as $field) $customer[$field]=$normalize($data['customer'][$field]??null);
        $items=[];
        foreach ($data['items']??[] as $item) {
            $amount=$item['quantity']??null;
            if (!is_string($amount)||!preg_match('/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?\z/',$amount)) throw new \RuntimeException();
            [$whole,$fraction]=array_pad(explode('.',$amount),2,'');
            $items[]=['name'=>$normalize($item['name']??null),'millis'=>(int)$whole*1000+(int)str_pad($fraction,3,'0'),
                'mode'=>$item['quantity_mode']??null,'option'=>$normalize($item['option_hint']??null)];
        }
        return hash_hmac('sha256','whatsapp-order-followup-proposal-v1:'.json_encode([$customer,$items,
            $normalize($data['branch_hint']??null),self::fulfillment($data,$transcript)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey());
    }

    public function request($actor,array $plan,callable $refresh): array
    {
        $out=['status'=>'BLOCKED','reply_id'=>null,'field_count'=>0];
        try {
            if (!self::validPlan($plan)||$refresh()!==$plan) return $out;
            $this->replies=$this->replies??app(WhatsAppReplyService::class);
            $uuid=self::requestUuid($plan,Crypt::getKey()); $cid=$plan['conversation_id']; $inbound=$plan['latest_customer_id'];
            $prior=$this->replies->orderFollowupState($actor,$cid,$uuid,$inbound);
            if ($prior!==null) return ['status'=>'ALREADY_ASKED','reply_id'=>$prior['reply_id']??null,'field_count'=>0];
            if (!$this->handoffAllowed($plan)) return $out;
            $state=$this->replies->state($actor,$cid);
            if (($state['can_reply']??null)!==true||($state['latest_inbound_id']??null)!==$inbound) return $out;
            $fields=$this->unaskedFields($plan); if (!$fields) return $out;
            $text=self::question($fields,'SHORT',$plan['data'],$plan['transcript']);
            $marker=['version'=>'whatsapp-order-followup-v1','kind'=>$fields===['CONFIRMATION']?'confirmation':'question','fields'=>$fields,
                'latest_customer_id'=>$inbound,'latest_customer_key'=>$plan['latest_customer_key'],'episode_customer_key'=>$plan['episode_customer_key'],
                'proposal_hash'=>$fields===['CONFIRMATION']?self::proposalHash($plan['data'],$plan['transcript']):null];
            $guard=static fn():bool=>$refresh()===$plan;
            $compose=function() use($plan,$fields,$guard,$text):string {
                if (!$guard()) throw new \RuntimeException();
                $this->provider=$this->provider??app(WhatsAppOrderAiProvider::class); $choice=$this->provider->followupVariant($fields);
                if (!$guard()) throw new \RuntimeException();
                return ($choice['ok']??null)===true&&in_array($choice['variant']??null,['SHORT','POLITE'],true)
                    ?self::question($fields,$choice['variant'],$plan['data'],$plan['transcript']):$text;
            };
            $sent=$this->replies->sendOrderFollowup($actor,$cid,['client_request_id'=>$uuid,'expected_inbound_id'=>$inbound,
                'text'=>$text,'_followup'=>$marker,'_compose'=>$compose],$guard);
            return ['status'=>($sent['replayed']??false)?'ALREADY_ASKED':(($sent['state']??null)==='ACCEPTED'?'ACCEPTED':'BLOCKED'),
                'reply_id'=>$sent['reply_id']??null,'field_count'=>count($fields)];
        } catch (\Throwable $ignore) { return $out; }
    }

    public function handoffAllowed(array $plan): bool
    {
        try {
            $after=false;
            foreach (array_reverse($plan['transcript']) as $row) {
                if (($row['speaker']??null)==='customer') break;
                if (($row['speaker']??null)==='business') $after=true;
            }
            if ($after) return true;
            $anchor=DB::table('whatsapp_inbox_messages')->where('conversation_id',$plan['conversation_id'])
                ->where('message_key',$plan['episode_customer_key'])->where('direction','inbound')->first(['id','sent_at']);
            if ($anchor && (int)$anchor->id>$plan['evidence_floor']) {
                foreach ($plan['transcript'] as $row) if ($row['speaker']==='business'&&$row['sent_at']>=$anchor->sent_at
                    && is_string($row['text'])&&preg_match('/(?:ابعت|أرسل|ارسل|محتاج|مطلوب|لو سمحت|من فضلك|[?؟]).{0,80}'
                        .'(?:اسم|الاسم|رقم|هاتف|عنوان|لوكيشن|كمية|الكمية|بالكيلو|بالقطعة|بالقطعه|دليفري|الصالة|الصاله|تيك|الفرع|المدينة)/u',$row['text'])) return true;
            }
            foreach ($this->ownedMessages($plan['conversation_id'],$plan['evidence_floor'],$plan['evidence_ceiling']) as $record)
                if ($record['marker']['episode_customer_key']===$plan['episode_customer_key']) return true;
            return false;
        } catch (\Throwable $ignore) { return false; }
    }

    private function unaskedFields(array $plan): array
    {
        $fields=self::fields($plan['fields']); if ($fields===['CONFIRMATION']) return $fields;
        $texts=[];
        foreach (array_reverse($plan['transcript']) as $row) {
            if ($row['speaker']==='customer') break;
            if ($row['speaker']==='business'&&is_string($row['text'])) $texts[]=$row['text'];
        }
        $patterns=['NAME'=>'(?:اسمك|اسم حضرتك|الاسم)','PHONE'=>'(?:رقم الهاتف|رقم هاتف|رقم تواصل|رقمك|رقم الموبايل)',
            'ADDRESS'=>'(?:العنوان|عنوان التوصيل|عنوانك)','LOCATION'=>'(?:لوكيشن|اللوكيشن|موقعك|موقع التوصيل|location)',
            'ITEMS'=>'(?:الأصناف|الاصناف|المنتجات)','QUANTITY'=>'(?:الكمية|كمية|قد إيه|قد ايه)',
            'QUANTITY_MODE'=>'(?:بالكيلو|بالقطعة|بالقطعه)','FULFILLMENT'=>'(?:دليفري|الصالة|الصاله|تيك أواي|تيك اواي)',
            'BRANCH'=>'(?:الفرع|فرع|المدينة|المدينه)'];
        foreach ($texts as $text) {
            if (!preg_match('/(?:ابعت|أرسل|ارسل|محتاج|مطلوب|لو سمحت|من فضلك|[?؟])/u',$text)) continue;
            $fields=array_values(array_filter($fields,static fn($f)=>!preg_match('/'.$patterns[$f].'/iu',$text)));
        }
        return $fields;
    }

    /** Receipt proof authenticates metadata even when a native echo won the projection race. */
    private function ownedMessages(int $conversation,int $floor,int $ceiling,bool $lock=false): array
    {
        if (!Schema::hasTable(WhatsAppInboxMessage::INDEX)) return [];
        $q=DB::table('whatsapp_inbox_conversations')->where('id',$conversation)->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID);
        $thread=($lock?$q->lockForUpdate():$q)->first(); if (!$thread) throw new \RuntimeException();
        $q=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('id','>',$floor)->where('id','<=',$ceiling)
            ->where('direction','outbound')->where('type','text')->orderByDesc('id')->limit(61);
        $rows=($lock?$q->lockForUpdate():$q)->get(); if (count($rows)>60) throw new \RuntimeException();
        $records=[]; $accepted=new WhatsAppAcceptedReplyEvidence();
        foreach ($rows as $message) {
            $q=DB::table(WhatsAppInboxMessage::INDEX)->where('conversation_id',$conversation)->where('message_key',$message->message_key)
                ->where('kind','text')->where('valid',true)->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID);
            $index=($lock?$q->lockForUpdate():$q)->first(['reply_id']); if (!$index) continue;
            if ($message->source===WhatsAppAcceptedReplyEvidence::SOURCE) $accepted->unwrap($thread,$message,$lock);
            else $accepted->validateNativeReceipt($thread,$message,$lock);
            $q=DB::table('whatsapp_reply_requests')->where('id',$index->reply_id)->where('conversation_id',$conversation)->where('state','ACCEPTED')->where('kind','text')
                ->select('id','inbound_message_id')->selectRaw('CASE WHEN LENGTH(audit)<=262144 THEN audit ELSE NULL END AS audit');
            $reply=($lock?$q->lockForUpdate():$q)->first(); if (!$reply||!is_string($reply->audit)) throw new \RuntimeException();
            $plain=Crypt::decryptString($reply->audit); if (strlen($plain)>65536) throw new \RuntimeException();
            $audit=json_decode($plain,true,32,JSON_THROW_ON_ERROR); $marker=$audit['order_followup']??null;
            if ($marker===null) continue;
            if (!self::validMarker($marker,$conversation,$audit['client_request_id']??'',(int)$reply->inbound_message_id)) throw new \RuntimeException();
            $records[]=['marker'=>$marker,'message'=>$message];
        }
        return $records;
    }

    public function waitingForCustomer(int $conversation,int $analyzedCeiling,int $ceiling): bool
    {
        try {
            if ($analyzedCeiling<0||$ceiling<=$analyzedCeiling) return false;
            $rows=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('id','>',$analyzedCeiling)->where('id','<=',$ceiling)->limit(61)->get(['id']);
            if (!$rows->count()||$rows->count()>60) return false;
            $own=$this->ownedMessages($conversation,$analyzedCeiling,$ceiling); if (count($own)!==$rows->count()) return false;
            $latest=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')->orderByDesc('sent_at')->orderByDesc('id')->first(['id','message_key']);
            if (!$latest) return false;
            foreach ($own as $record) if ($record['marker']['latest_customer_id']!==(int)$latest->id||$record['marker']['latest_customer_key']!==$latest->message_key) return false;
            return true;
        } catch (\Throwable $ignore) { return false; }
    }

    public function approvalSatisfied(int $conversation,array $data,array $snapshot,bool $lock=false): bool
    {
        try {
            if (!Schema::hasTable('whatsapp_reply_requests')||!Schema::hasTable(WhatsAppInboxMessage::INDEX)) return true;
            $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']??[]);
            $keys=array_intersect_key($snapshot['keys']??[],array_flip(array_column($episode,'id')));
            if (($snapshot['complete']??null)!==true||!$keys) return false;
            $q=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->whereIn('message_key',array_values($keys));
            $ids=$q->pluck('id')->all(); if (!$ids) return false;
            $own=$this->ownedMessages($conversation,min(array_map('intval',$ids))-1,max(array_map('intval',$ids)),$lock); $recap=null;
            foreach ($own as $record) if ($record['marker']['kind']==='confirmation') { $recap=$record; break; }
            if ($recap===null) return true;
            $recapId=array_search($recap['message']->message_key,$keys,true); if ($recapId===false) return false;
            if (!in_array($recapId,$data['evidence']['confirmation_ids']??[],true)
                || !hash_equals($recap['marker']['proposal_hash'],self::proposalHash($data,$snapshot['transcript']))) return false;
            $positions=array_flip(array_column($snapshot['transcript'],'id')); $approved=false;
            foreach ($snapshot['transcript'] as $row) {
                if ($row['speaker']!=='customer'||($positions[$row['id']]??-1)<=($positions[$recapId]??PHP_INT_MAX)) continue;
                // Any intervening customer turn can change the order, even if AI omits its citation.
                if (!is_string($row['text'])||preg_match('/\A\s*(?:(?:(?:تمام|نعم)\s+)?(?:موافق|أوافق|اوافق)'
                    .'(?:\s+على\s+(?:الطلب|البيانات(?:\s+والطلب)?))?|تمام|حاضر|نعم|اوكي|أوكي|ماشي|ok|okay|yes)[.!]?\s*\z/iu',$row['text'])!==1) return false;
                if (in_array($row['id'],$data['evidence']['customer_acceptance_ids']??[],true)) $approved=true;
            }
            return $approved;
        } catch (\Throwable $ignore) { return false; }
    }
}
