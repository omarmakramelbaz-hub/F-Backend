<?php
namespace App\Services\Dashboard;

use App\Support\WhatsAppOrderExtraction;
use App\Support\WhatsAppInboxProtocol;
use App\Support\WhatsAppOrderDialogue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One bounded, receipt-backed question per genuine customer input; never an ERP command. */
class WhatsAppOrderFollowupService
{
    protected const MARKER_VERSION='whatsapp-order-followup-v1';
    public const FIELDS=['NAME','PHONE','ADDRESS','ITEMS','QUANTITY','QUANTITY_MODE','FULFILLMENT','BRANCH','LOCATION','CONFIRMATION'];
    protected $replies;
    protected ?WhatsAppOrderAiProvider $provider;
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
            if (!is_array($marker)||count($marker)!==7||($marker['version']??null)!==static::MARKER_VERSION
                || !in_array($marker['kind']??null,['question','confirmation'],true)||self::fields($marker['fields']??null)!==($marker['fields']??null)
                || ($marker['latest_customer_id']??null)!==$inbound
                || (($marker['fields']===['CONFIRMATION'])!==($marker['kind']==='confirmation'))
                || ($marker['kind']==='question'?($marker['proposal_hash']??null)!==null
                    :(!is_string($marker['proposal_hash']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$marker['proposal_hash'])))) return false;
            return hash_equals(static::requestUuid($marker+['conversation_id'=>$conversation],Crypt::getKey()),strtolower($uuid));
        } catch (\Throwable $ignore) { return false; }
    }

    protected static function validPlan(array $plan): bool
    {
        $primary=$plan['primary_ai']??null;
        if ($primary!==null&&!self::validPrimaryToken($primary)) return false;
        $dialogue=$plan['dialogue']??null;
        $catalogHash=$plan['catalog_fingerprint']??null;
        if (count($plan)!==($dialogue===null?11:($catalogHash===null?12:13))+($primary===null?0:1)) return false;
        if ($catalogHash!==null&&($dialogue===null||!is_string($catalogHash)||!preg_match('/\A[a-f0-9]{64}\z/',$catalogHash))) return false;
        if ($dialogue!==null&&(!is_array($dialogue)||!WhatsAppOrderDialogue::validContext($dialogue)
            ||($plan['fields']??null)!==[$dialogue['field']]||($plan['transcript']??null)!==$dialogue['transcript'])) return false;
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
                $sizes=self::itemFishSizes($item,$data,$transcript);
                $sizeText=$sizes!==[]&&WhatsAppOrderConversationEvidence::fishSizes($item['option_hint']??'')!==$sizes
                    ?' (من الكيلو '.$sizes[0].' سمكات)':'';
                $lines[]=$item['quantity'].' '.($item['quantity_mode']==='weight'?'كيلو':'قطعة').' '.$item['name']
                    .(is_string($item['option_hint']??null)&&$item['option_hint']!==''?' '.$item['option_hint']:'').$sizeText;
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

    protected static function proposalHash(array $data,array $transcript=[]): string
    {
        $normalize=static fn($value)=>$value===null?null:mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$value)),'UTF-8');
        $customer=[]; foreach (['name','phone','address','area','notes'] as $field) $customer[$field]=$normalize($data['customer'][$field]??null);
        $items=[];
        foreach ($data['items']??[] as $item) {
            $amount=$item['quantity']??null;
            if (!is_string($amount)||!preg_match('/\A(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?\z/',$amount)) throw new \RuntimeException();
            [$whole,$fraction]=array_pad(explode('.',$amount),2,'');
            $entry=['name'=>$normalize($item['name']??null),'millis'=>(int)$whole*1000+(int)str_pad($fraction,3,'0'),
                'mode'=>$item['quantity_mode']??null,'option'=>$normalize($item['option_hint']??null)];
            $sizes=self::itemFishSizes($item,$data,$transcript);
            if ($sizes!==[]) $entry['fish_per_kg']=$sizes[0];
            $items[]=$entry;
        }
        return hash_hmac('sha256','whatsapp-order-followup-proposal-v1:'.json_encode([$customer,$items,
            $normalize($data['branch_hint']??null),self::fulfillment($data,$transcript)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),Crypt::getKey());
    }

    /** Preserve independently stated fish size in the recap even with no option_hint. */
    private static function itemFishSizes(array $item,array $data,array $transcript): array
    {
        $sizes=[]; $rows=array_column($transcript,null,'id');
        $reference=$item['conversation_reference']??null;
        if ($reference===null&&($data['decision']??null)==='DRAFT'&&$transcript!==[]) {
            $sources=WhatsAppOrderConversationEvidence::draftItemQuantitySources($data,$transcript);
            $index=array_search($item,$data['items'],true);
            if ($index!==false&&is_array($sources)) $reference=$sources[$index]??null;
        }
        if (is_array($reference)) {
            $quantity=$rows[$reference['customer_quantity_id']??'']['text']??null;
            if (is_string($quantity)) $sizes=WhatsAppOrderConversationEvidence::fishSizes($quantity);
            if ($sizes===[]) {
                $offer=$rows[$reference['business_offer_id']??'']['text']??null;
                if (is_string($offer)) $sizes=WhatsAppOrderConversationEvidence::fishSizes($offer);
            }
        } else {
            $texts=[];
            foreach ($transcript as $message) if ($message['speaker']==='customer'
                &&in_array($message['id'],$data['evidence']['request_ids']??[],true)&&is_string($message['text'])) $texts[]=$message['text'];
            $sizes=WhatsAppOrderExtraction::itemFishSizes($item,$data['items'],$texts);
            if ($sizes===null) throw new \RuntimeException('AMBIGUOUS_FISH_SIZE');
        }
        $sizes=array_values(array_unique($sizes));
        if (count($sizes)>1) throw new \RuntimeException('AMBIGUOUS_FISH_SIZE');
        return $sizes;
    }

    public function request($actor,array $plan,callable $refresh): array
    {
        $out=['status'=>'BLOCKED','reply_id'=>null,'field_count'=>0];
        try {
            if (!static::validPlan($plan)||$refresh()!==$plan) return $out;
            $this->replies=$this->replies??$this->replyService();
            $uuid=static::requestUuid($plan,Crypt::getKey()); $cid=$plan['conversation_id']; $inbound=$plan['latest_customer_id'];
            $prior=$this->replies->orderFollowupState($actor,$cid,$uuid,$inbound);
            if ($prior!==null) return ['status'=>'ALREADY_ASKED','reply_id'=>$prior['reply_id']??null,'field_count'=>0];
            if (!$this->handoffAllowed($plan)) return $out;
            $state=$this->replies->state($actor,$cid);
            if (($state['can_reply']??null)!==true||($state['latest_inbound_id']??null)!==$inbound) return $out;
            $fields=$this->unaskedFields($plan); if (!$fields) return $out;
            $dialogue=$plan['dialogue']??null;
            $text=$this->renderQuestion($plan,$fields,'SHORT');
            $marker=['version'=>static::MARKER_VERSION,'kind'=>$fields===['CONFIRMATION']?'confirmation':'question','fields'=>$fields,
                'latest_customer_id'=>$inbound,'latest_customer_key'=>$plan['latest_customer_key'],'episode_customer_key'=>$plan['episode_customer_key'],
                'proposal_hash'=>$fields===['CONFIRMATION']?static::proposalHash($plan['data'],$plan['transcript']):null];
            $guard=fn():bool=>$refresh()===$plan&&$this->handoffAllowed($plan);
            $compose=function() use($plan,$fields,$guard,$text,$dialogue):string {
                if (!$guard()) throw new \RuntimeException();
                $this->provider=$this->provider??$this->providerService($plan);
                if ($dialogue!==null) {
                    $result=$this->provider->followupQuestion($dialogue);
                    if (!$guard()) throw new \RuntimeException();
                    $choice=($result['ok']??false)===true&&is_array($result['choice']??null)
                        ?WhatsAppOrderDialogue::validateChoice($dialogue,$result['choice']):null;
                    if ($choice===null) return $text;
                    $question=$this->renderQuestion($plan,$fields,$choice['variant'],$choice);
                    if ($question==='') throw new \RuntimeException('FOLLOWUP_WAIT');
                    return $question;
                }
                $choice=$this->provider->followupVariant($fields);
                if (!$guard()) throw new \RuntimeException();
                return ($choice['ok']??null)===true&&in_array($choice['variant']??null,['SHORT','POLITE'],true)
                    ?$this->renderQuestion($plan,$fields,$choice['variant']):$text;
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
            if (!static::validPlan($plan)) return false;
            if (Schema::hasTable('meta_ai_controls')&&!isset($plan['primary_ai'])) return false;
            if (isset($plan['primary_ai'])) return $this->primaryHandoff($plan);
            $tail=$this->authenticatedCustomerTail($plan['conversation_id'],$plan['data'],$plan['transcript'],
                $plan['evidence_floor'],$plan['evidence_ceiling'],['latest_id'=>$plan['latest_customer_id'],
                    'latest_key'=>$plan['latest_customer_key'],'episode_key'=>$plan['episode_customer_key']]);
            if ($tail===null) return false;
            if (($plan['dialogue']['kind']??null)==='UNREADABLE') return $this->unreadableQuestionHandoff($plan);
            // Asking for facts, including a factual recap, never grants consent to execute.
            if ($this->confirmedOrderQuestionHandoff($plan,$tail)) return true;
            $after=false;
            foreach (array_reverse($plan['transcript']) as $row) {
                if (($row['speaker']??null)==='customer') break;
                if (($row['speaker']??null)==='business') $after=true;
            }
            if ($after) return true;
            if ($this->confirmedCustomerHandoff($plan)) return true;
            if ($this->confirmedMediaQuestionHandoff($plan)) return true;
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


    protected function replyService() { return app(WhatsAppReplyService::class); }

    protected function providerService(array $plan)
    {
        if (!isset($plan['primary_ai'])||app()->bound(WhatsAppOrderAiProvider::class)) return app(WhatsAppOrderAiProvider::class);
        return new WhatsAppOrderAiProvider(null,array_replace((array)config('whatsapp_orders',[]),['enabled'=>true]));
    }

    protected function renderQuestion(array $plan,array $fields,string $variant,?array $choice=null): string
    {
        $dialogue=$plan['dialogue']??null;
        if ($dialogue===null) return static::question($fields,$variant,$plan['data'],$plan['transcript']);
        return WhatsAppOrderDialogue::render($dialogue,$choice??WhatsAppOrderDialogue::fallback($dialogue));
    }

    protected static function validPrimaryToken($token): bool
    {
        if (!is_array($token)||count($token)!==6
            ||!in_array($token['channel']??null,['whatsapp','messenger','instagram'],true)
            ||!is_int($token['revision']??null)||$token['revision']<0
            ||!is_int($token['actor_id']??null)||$token['actor_id']<1) return false;
        foreach (['message_floor','event_floor'] as $field)
            if (!is_int($token[$field]??null)||$token[$field]<0) return false;
        return is_string($token['started_at_utc']??null)
            &&preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',$token['started_at_utc'])===1;
    }

    /** Explicit primary authority permits a first question; it never supplies order facts. */
    protected function primaryHandoff(array $plan): bool
    {
        if (!self::validPrimaryToken($plan['primary_ai']??null)) return false;
        return app(WhatsAppOrderWorkflow::class)->primaryPlanCurrent($plan);
    }

    /** Only the fact-free failed-extraction plan may recover a clarification turn. */
    private function unreadableQuestionHandoff(array $plan): bool
    {
        $empty=WhatsAppOrderExtraction::none('MISSING_ITEMS'); $empty['decision']='DRAFT';
        foreach (WhatsAppOrderConversationEvidence::episodeTranscript($plan['transcript']) as $message)
            if ($message['speaker']==='customer'&&(is_string($message['text'])||($message['cart']??null)!==null))
                $empty['evidence']['request_ids'][]=$message['id'];
        if ($plan['data']!==$empty||$plan['fields']===['CONFIRMATION']) return false;
        $rows=DB::table('whatsapp_inbox_messages')->where('conversation_id',$plan['conversation_id'])
            ->where('id','>',$plan['evidence_floor'])->where('id','<=',$plan['evidence_ceiling'])
            ->orderByDesc('id')->limit(61)->get(['id','message_key','sent_at'])->all();
        if (count($rows)!==count($plan['transcript'])||count($rows)>60) return false;
        usort($rows,static fn($a,$b)=>strcmp((string)$a->sent_at,(string)$b->sent_at)?:((int)$a->id<=>(int)$b->id));
        $keys=[];
        foreach ($rows as $position=>$row) {
            if ($plan['transcript'][$position]['id']!=='m'.($position+1)) return false;
            $keys['m'.($position+1)]=$row->message_key;
        }
        $snapshot=['complete'=>true,'transcript'=>$plan['transcript'],'keys'=>$keys];
        $notBefore=config('whatsapp_cart.activated_at'); if (!is_string($notBefore)) return false;
        $continuation=$this->questionContinuation($plan['conversation_id'],$snapshot,$notBefore);
        if ($continuation!==null) return $continuation['episode_customer_key']===$plan['episode_customer_key']
            &&in_array($plan['fields'][0],$continuation['fields'],true);
        return $this->questionRecoveryIntent($plan['conversation_id'],$snapshot,$notBefore)===$plan['episode_customer_key'];
    }

    private function confirmedOrderQuestionHandoff(array $plan,?array $tail=null): bool
    {
        if (!static::validPlan($plan)||($plan['data']['decision']??null)!=='CONFIRMED') return false;
        $tail=$tail??$this->authenticatedCustomerTail($plan['conversation_id'],$plan['data'],$plan['transcript'],
            $plan['evidence_floor'],$plan['evidence_ceiling'],['latest_id'=>$plan['latest_customer_id'],
                'latest_key'=>$plan['latest_customer_key'],'episode_key'=>$plan['episode_customer_key']]);
        return $tail!==null&&$tail['final']!==null&&($tail['checked']['decision']??null)==='CONFIRMED';
    }

    /** Read bounded native metadata first, then authenticate each customer tail message separately. */
    private function authenticatedCustomerTail(int $conversation,array $data,array $transcript,int $floor,int $ceiling,
        ?array $bindings=null,?array $keys=null): ?array
    {
        if ($floor<0||$ceiling<=$floor||!WhatsAppOrderExtraction::validateTranscript($transcript)['ok']) return null;
        $thread=DB::table('whatsapp_inbox_conversations')->where('id',$conversation)
            ->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID)->first();
        if (!$thread) return null;
        $rows=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
            ->where('id','>',$floor)->where('id','<=',$ceiling)->orderByDesc('id')->limit(61)
            ->select('id','conversation_id','message_key','type','direction','source','sent_at')
            ->selectRaw('LENGTH(content) AS content_bytes')->get()->all();
        if (count($rows)!==count($transcript)||count($rows)>60) return null;
        usort($rows,static fn($a,$b)=>strcmp((string)$a->sent_at,(string)$b->sent_at)?:((int)$a->id<=>(int)$b->id));
        $positions=array_flip(array_column($transcript,'id')); $final=null; $anchor=null; $latest=null; $seen=[];
        foreach (WhatsAppOrderConversationEvidence::episodeTranscript($transcript) as $row)
            if ($row['speaker']==='business'&&in_array($row['id'],$data['evidence']['confirmation_ids']??[],true)) $final=$positions[$row['id']];
        foreach ($rows as $position=>$native) {
            $row=$transcript[$position]; $id=(int)$native->id;
            if ($row['id']!=='m'.($position+1)||$id<=$floor||$id>$ceiling||isset($seen[$id])
                ||(int)$native->conversation_id!==$conversation||!is_string($native->message_key)
                ||preg_match('/\A[a-f0-9]{64}\z/',$native->message_key)!==1
                ||$native->sent_at!==$row['sent_at']||$native->direction!==($row['speaker']==='customer'?'inbound':'outbound')
                ||!is_string($native->type)||!in_array($native->source,$native->direction==='inbound'
                    ?['messages','standby']:['smb_message_echoes','standby','dashboard_api'],true)
                ||($keys!==null&&($keys[$row['id']]??null)!==$native->message_key)) return null;
            $seen[$id]=true;
            if ($row['speaker']==='customer') $latest=$native;
            if ($bindings!==null&&$native->message_key===$bindings['episode_key']&&$row['speaker']==='customer') $anchor=$position;
        }
        if ($bindings!==null&&($anchor===null||$latest===null||(int)$latest->id!==$bindings['latest_id']
            ||$latest->message_key!==$bindings['latest_key'])) return null;
        $after=$final??$anchor;
        if ($after===null) return null;
        $tail=[]; $visible=$transcript; $resolver=new WhatsAppInboxMessage();
        foreach ($rows as $position=>$metadata) {
            $row=$transcript[$position];
            if ($position<=$after||$row['speaker']!=='customer') continue;
            if ($metadata->content_bytes===null||(int)$metadata->content_bytes>8388608) return null;
            $native=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('id',$metadata->id)
                ->where('message_key',$metadata->message_key)->select('id','conversation_id','message_key','type','direction','source','sent_at')
                ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
            if (!$native||!is_string($native->content)) return null;
            foreach (['id','conversation_id','message_key','type','direction','source','sent_at'] as $field)
                if ((string)$native->$field!==(string)$metadata->$field) return null;
            $message=$resolver->projected($thread,$native);
            if ($message['id']!==(int)$metadata->id||$message['conversation_id']!==$conversation||$message['direction']!=='inbound'
                ||$message['kind']!==$metadata->type||$message['native_message_key']!==$metadata->message_key
                ||$message['sent_at']!==$row['sent_at']||($row['text']!==null&&$message['text']!==$row['text'])
                ||($message['kind']==='text'&&$message['text']!==$row['text'])) return null;
            $location=null; $raw=$message['raw'];
            $body=$raw[$message['kind']]??null;
            if (is_array($body)&&array_key_exists('caption',$body)&&!is_string($body['caption'])) return null;
            if (in_array($message['kind'],['image','audio','document','video','sticker'],true)
                &&(!is_array($body)||!is_string($body['id']??null)||preg_match('/\A[^\x00-\x20\x7f]{1,512}\z/',$body['id'])!==1)) return null;
            if ($message['kind']==='location'&&isset($raw['location']['latitude'],$raw['location']['longitude'])) {
                $location=['lat'=>(float)$raw['location']['latitude'],'long'=>(float)$raw['location']['longitude']];
                if (abs($location['lat'])>90||abs($location['long'])>180) $location=null;
            }
            $cart=$message['kind']==='order'?WhatsAppInboxProtocol::cart($raw['order']??null):null;
            if ($location!==$row['location']||$cart!==($row['cart']??null)
                ||self::questionUnsafe($message['text'],$final!==null,$data)) return null;
            // Captions omitted from extraction stay visible to cancellation/change checks.
            $visible[$position]['text']=is_string($message['text'])&&trim($message['text'])===''?null:$message['text'];
            $tail[]=['id'=>$row['id'],'kind'=>$message['kind'],'text'=>$message['text']];
            unset($native,$message,$raw,$body);
        }
        if (WhatsAppOrderExtraction::hasProbe($visible)) return null;
        $checked=WhatsAppOrderExtraction::validateDetailed($data,$visible);
        return $checked['ok']?['final'=>$final,'rows'=>$tail,'checked'=>$checked['data']]:null;
    }

    /** A visible refusal, new order or amendment cannot borrow the completed order's question. */
    private static function questionUnsafe($text,bool $completed=false,array $data=[]): bool
    {
        if (!is_string($text)) return false;
        if (preg_match('/\A\s*(?:لا|لأ|لاء|كلا|no|nope)(?:\s*[,،]?\s+(?:شكرا|شكرًا|شكراً|thanks|thank\s+you))?[.!،,]*\s*\z|'
            .'\A\s*(?:stop(?:\s+please)?|please\s+stop)[.!،,]*\s*\z|'
            .'(?:مش|لا|مت|ما)\s*(?:عايز|عاوز|موافق|محتاج|أريد|اريد|تأكد|تاكد|تؤكد|ترسل|تبعت|ابعت|اعملي|اعمللي|هات|ضيف|زود)|'
            .'(?<![\p{L}\p{N}])غير\s*موافق(?![\p{L}\p{N}])|(?<![\p{L}\p{N}])(?:إلغاء|الغاء|إلغي|إلغى|الغي|الغى|الغيه|إلغيه|ألغي|ألغى|لغي|لغيه|إلغ|الغ|أرفض|ارفض|رفض|بلاش|كفاية|توقف)(?![\p{L}\p{N}])|'
            .'(?:بدل|بدّل|غيّر|غير\s+(?:الطلب|الأوردر|الاوردر)|خليه|خليها)|'
            .'(?:طلب|أوردر|اوردر)\s+(?:تاني|آخر|اخر|جديد)|'
            .'تجاهل\s+(?:كل|التعليمات)|تعليمات\s+(?:النظام|المطور)|'
            .'\b(?:do\s+not|don[’\x27]t|not\s+want|cancel|change|instead|another\s+order|new\s+order|'
            .'ignore\s+(?:all|previous|above)|system\s+prompt|developer\s+message)\b/iu',$text)===1) return true;
        if (!$completed||preg_match('/[?؟]|(?:اعرف|أعرف|بكام|سعر|هل|ينفع|\b(?:how|price|can|could)\b)/iu',$text)
            ||preg_match('/\A\s*(?:تمام\s+)?(?:(?:أنا|انا)\s+)?(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمل\s+لي)\s+|'
                .'\A\s*(?:I\s+want|order|send|please\s+(?:order|send))\s+/iu',$text)!==1) return false;
        if (preg_match('/(?<![\p{L}\p{N}])(?:طلب|الطلب|أوردر|اوردر|الأوردر|الاوردر|كيلو|كجم|جرام|جم|قطعة|حبة|حبه|سمكة|سمكه|علبة|وجبة|order|kg|kilo|pieces?)(?![\p{L}\p{N}])/iu',$text)) return true;
        foreach ($data['items']??[] as $item) if (is_string($item['name']??null)&&strpos($text,$item['name'])!==false) return true;
        return false;
    }

    /** A proved merchant recap followed by its actual customer approval is an established handoff. */
    private function confirmedCustomerHandoff(array $plan): bool
    {
        $data=$plan['data']??[]; $transcript=$plan['transcript']??[];
        if (!is_array($data)||!is_array($transcript)||($data['decision']??null)!=='CONFIRMED') return false;
        $checked=WhatsAppOrderExtraction::validateDetailed($data,$transcript);
        if (!$checked['ok']||($checked['data']['decision']??null)!=='CONFIRMED') return false;
        $latest=null; $final=-1;
        foreach (WhatsAppOrderConversationEvidence::episodeTranscript($transcript) as $position=>$row) {
            if ($row['speaker']==='customer') $latest=['position'=>$position,'row'=>$row];
            if ($row['speaker']==='business'&&in_array($row['id'],$data['evidence']['confirmation_ids'],true)) $final=$position;
        }
        return $latest!==null&&$final>=0&&$latest['position']>$final
            &&in_array($latest['row']['id'],$data['evidence']['customer_acceptance_ids'],true)
            &&self::positiveApproval($latest['row']['text']);
    }

    /** An authenticated trailing image can hand off a missing-data question, never approval. */
    private function confirmedMediaQuestionHandoff(array $plan): bool
    {
        if (!static::validPlan($plan)||self::fields($plan['fields'])===['CONFIRMATION']
            ||($plan['data']['decision']??null)!=='CONFIRMED') return false;
        $checked=WhatsAppOrderExtraction::validateDetailed($plan['data'],$plan['transcript']);
        if (!$checked['ok']||($checked['data']['decision']??null)!=='CONFIRMED') return false;
        $episode=WhatsAppOrderConversationEvidence::episodeTranscript($plan['transcript']);
        $final=-1; $latest=null; $image=null;
        foreach ($episode as $position=>$row) {
            if ($row['speaker']==='customer') $latest=['position'=>$position,'row'=>$row];
            if ($row['speaker']==='business'&&in_array($row['id'],$plan['data']['evidence']['confirmation_ids'],true)) $final=$position;
        }
        if ($final<0||$latest===null||$latest['position']<=$final) return false;
        foreach ($episode as $position=>$row) {
            if ($position<=$final||$row['speaker']!=='customer') continue;
            if ($row['text']!==null) {
                if (!in_array($row['id'],$plan['data']['evidence']['customer_acceptance_ids'],true)
                    ||!self::positiveApproval($row['text'])) return false;
            } else {
                if ($image!==null||($row['location']??null)!==null||($row['cart']??null)!==null) return false;
                $image=$row;
            }
        }
        if ($image===null||$image['id']!==$latest['row']['id']) return false;
        $thread=DB::table('whatsapp_inbox_conversations')->where('id',$plan['conversation_id'])
            ->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID)->first();
        $native=DB::table('whatsapp_inbox_messages')->where('conversation_id',$plan['conversation_id'])
            ->where('id',$plan['latest_customer_id'])->where('message_key',$plan['latest_customer_key'])
            ->where('id','>',$plan['evidence_floor'])->where('id','<=',$plan['evidence_ceiling'])
            ->where('direction','inbound')->where('type','image')->whereIn('source',['messages','standby'])
            ->select('id','conversation_id','message_key','type','direction','source','sent_at')
            ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
        if (!$thread||!$native||$native->sent_at!==$image['sent_at']) return false;
        $projection=(new WhatsAppInboxMessage())->projected($thread,$native);
        $body=$projection['raw']['image']??null;
        return $projection['direction']==='inbound'&&$projection['kind']==='image'
            &&$projection['native_message_key']===$plan['latest_customer_key']&&$projection['sent_at']===$image['sent_at']
            &&is_array($body)&&is_string($body['id']??null)&&preg_match('/\A[^\x00-\x20\x7f]{1,512}\z/',$body['id'])===1
            &&(!array_key_exists('caption',$body)||$body['caption']==='')
            &&($projection['text']===null||$projection['text']==='');
    }

    protected static function positiveApproval($text): bool
    {
        return is_string($text)&&preg_match('/\A\s*(?:(?:(?:تمام|نعم)\s+)?(?:موافق|أوافق|اوافق)'
            .'(?:\s+على\s+(?:الطلب|البيانات(?:\s+والطلب)?))?|تمام|حاضر|نعم|اوكي|أوكي|ماشي|ok|okay|yes)[.!]?\s*\z/iu',$text)===1;
    }

    private function unaskedFields(array $plan): array
    {
        return self::unaskedTopics($plan['fields'],$plan['transcript']);
    }

    /** Topic suppression is applied before choosing one contextual question. */
    public static function unaskedTopics(array $requested,array $transcript): array
    {
        $fields=self::fields($requested); if ($fields===null) return [];
        if ($fields===['CONFIRMATION']) return $fields;
        $texts=[];
        foreach (array_reverse($transcript) as $row) {
            if ($row['speaker']==='customer') break;
            if ($row['speaker']==='business'&&is_string($row['text'])) $texts[]=$row['text'];
        }
        $patterns=['NAME'=>'(?:اسمك|اسم حضرتك|الاسم)','PHONE'=>'(?:رقم الهاتف|رقم هاتف|رقم تواصل|رقمك|رقم الموبايل)',
            'ADDRESS'=>'(?:العنوان|عنوان التوصيل|عنوانك)','LOCATION'=>'(?:لوكيشن|اللوكيشن|موقعك|موقع التوصيل|location)',
            'ITEMS'=>'(?:الأصناف|الاصناف|المنتجات|اسم الصنف|اسم النوع|أنهي نوع|انهي نوع)','QUANTITY'=>'(?:الكمية|كمية|قد إيه|قد ايه)',
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

    /** An authenticated own question can carry intent into its actual next customer answer.
     * This authorizes clarification only; it never supplies order facts or consent. */
    public function questionContinuation(int $conversation,array $snapshot,string $notBefore): ?array
    {
        try {
            if (($snapshot['complete']??null)!==true
                ||!WhatsAppOrderExtraction::validateTranscript($snapshot['transcript']??[])['ok']
                ||WhatsAppOrderExtraction::hasProbe($snapshot['transcript'])||!is_array($snapshot['keys']??null)) return null;
            $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
            $positions=array_flip(array_column($episode,'id')); $latest=null;
            foreach ($episode as $message) if ($message['speaker']==='customer') $latest=$message;
            if ($latest===null||$latest['sent_at']<$notBefore) return null;
            $native=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
                ->whereIn('message_key',array_values($snapshot['keys']))->pluck('id')->all();
            if (count($native)!==count($snapshot['keys'])) return null;
            foreach ($this->ownedMessages($conversation,min(array_map('intval',$native))-1,max(array_map('intval',$native))) as $record) {
                if ($record['marker']['kind']!=='question') continue;
                $label=array_search($record['message']->message_key,$snapshot['keys'],true);
                $anchor=array_search($record['marker']['episode_customer_key'],$snapshot['keys'],true);
                if ($label===false||$anchor===false||!isset($positions[$label],$positions[$anchor])
                    ||$positions[$latest['id']]<=$positions[$label]||$positions[$anchor]>=$positions[$label]) continue;
                return ['episode_customer_key'=>$record['marker']['episode_customer_key'],'fields'=>$record['marker']['fields']];
            }
        } catch (\Throwable $ignore) {}
        return null;
    }

    /** Failed extraction may ask a question after a genuine, authenticated
     * customer purchase and a completed merchant turn. It cannot supply facts. */
    public function questionRecoveryIntent(int $conversation,array $snapshot,string $notBefore): ?string
    {
        try {
            if (($snapshot['complete']??null)!==true||!is_array($snapshot['keys']??null)
                ||!WhatsAppOrderExtraction::validateTranscript($snapshot['transcript']??[])['ok']) return null;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$notBefore,new \DateTimeZone('UTC'));
            $errors=\DateTimeImmutable::getLastErrors();
            if (!$date||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0))
                ||$date->format('Y-m-d H:i:s')!==$notBefore||substr($notBefore,0,4)==='0000'
                ||$notBefore>now('UTC')->addSeconds(5)->format('Y-m-d H:i:s')
                ||count($snapshot['keys'])!==count($snapshot['transcript'])
                ||count(array_unique($snapshot['keys'],SORT_REGULAR))!==count($snapshot['keys'])) return null;
            foreach ($snapshot['keys'] as $key) if (!is_string($key)||preg_match('/\A[a-f0-9]{64}\z/',$key)!==1) return null;
            $thread=DB::table('whatsapp_inbox_conversations')->where('id',$conversation)
                ->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID)->first();
            if (!$thread) return null;
            $ledger=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
                ->whereIn('message_key',array_values($snapshot['keys']))->limit(61)->get(['id','message_key','sent_at'])->all();
            if (count($ledger)!==count($snapshot['transcript'])) return null;
            usort($ledger,static fn($a,$b)=>strcmp((string)$a->sent_at,(string)$b->sent_at)?:((int)$a->id<=>(int)$b->id));
            foreach ($ledger as $position=>$native) {
                $message=$snapshot['transcript'][$position];
                if ($message['id']!=='m'.($position+1)||$native->sent_at!==$message['sent_at']
                    ||$native->message_key!==$snapshot['keys'][$message['id']]) return null;
            }
            $visible=[]; $resolver=new WhatsAppInboxMessage();
            foreach ($snapshot['transcript'] as $message) {
                $key=$snapshot['keys'][$message['id']]??null; if (!is_string($key)) return null;
                $native=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->where('message_key',$key)
                    ->select('id','conversation_id','message_key','type','direction','source','sent_at')
                    ->selectRaw('CASE WHEN LENGTH(content)<=8388608 THEN content ELSE NULL END AS content')->first();
                if (!$native) return null;
                $projection=$resolver->projected($thread,$native);
                if ($projection['sent_at']!==$message['sent_at']||$projection['native_message_key']!==$key
                    ||$projection['kind']!==$native->type
                    ||$projection['direction']!==($message['speaker']==='customer'?'inbound':'outbound')
                    ||($message['text']!==null&&$projection['text']!==$message['text'])
                    ||($projection['kind']==='text'&&$projection['text']!==$message['text'])) return null;
                $message['text']=is_string($projection['text'])&&trim($projection['text'])!==''?$projection['text']:null;
                $visible[]=$message;
            }
            if (WhatsAppOrderExtraction::hasProbe($visible)) return null;
            $anchor=null; $completed=false;
            foreach (WhatsAppOrderConversationEvidence::episodeTranscript($visible) as $message) {
                $text=$message['text'];
                if (!is_string($text)) { if ($message['speaker']==='business') $completed=false; continue; }
                if ($message['speaker']==='customer') {
                    if (self::questionUnsafe($text)) return null;
                    if ($message['sent_at']<$notBefore||preg_match('/[?؟"«»“”]|(?<![\p{L}\p{N}])'
                        .'(?:لو|مثلا|فرض|تجاهل|تعليمات|ignore|instructions)(?![\p{L}\p{N}])/iu',$text)) continue;
                    if (preg_match('/(?<![\p{L}\p{N}])(?:عايز|عاوز|أريد|اريد|اطلب|أطلب|هات|ابعت|اعملي|اعمل\s+لي|want|order|send)(?![\p{L}\p{N}])/iu',$text)
                        &&preg_match('/(?<![\p{L}\p{N}])(?:ال)?(?:طلب|أوردر|اوردر|فسيخ|رنجة|رنجه|سردين|ماكريل)(?![\p{L}\p{N}])/u',$text)) {
                        // A later new purchase cannot borrow an older completed turn.
                        $anchor=$message['id']; $completed=false;
                    }
                } elseif ($anchor!==null) $completed=!preg_match('/[?؟"«»“”]|(?:ممكن|مبدئي|تقريب|لسه|لم\s+|لن\s+|مش\s+|لا\s+)|'
                    .'(?<![\p{L}\p{N}])(?:لو|إذا|اذا|لما|عند|لحين|في\s+حال)\s+(?:تم|يتم|تأكيد|تاكيد)(?![\p{L}\p{N}])|'
                    .'(?<![\p{L}\p{N}])(?:سيتم|ستتم|هيتم|سوف|سنقوم|سنؤكد|هنأكد|هنراجع|سنراجع|will|pending)(?![\p{L}\p{N}])/iu',$text)
                    &&preg_match('/(?<![\p{L}\p{N}])(?:تم\s+(?:تأكيد|تاكيد|اعتماد)\s+(?:الطلب|طلبك|الأوردر|الاوردر)|(?:تأكيد|تاكيد)\s+(?:الطلب|طلبك|الأوردر|الاوردر)\s*[:：])/u',$text)===1;
            }
            return $anchor!==null&&$completed?($snapshot['keys'][$anchor]??null):null;
        } catch (\Throwable $ignore) { return null; }
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

    /** An employee edit or another automatic turn requires a new factual recap. */
    private function recapIsCurrentBusiness(int $conversation,array $snapshot,string $recapLabel,string $recapKey,bool $lock): bool
    {
        $after=false;
        foreach ($snapshot['transcript'] as $row) {
            if ($row['id']===$recapLabel) { $after=true; continue; }
            if ($after&&$row['speaker']==='business') return false;
        }
        $q=DB::table(WhatsAppInboxMessage::INDEX)->where('conversation_id',$conversation)->where('message_key',$recapKey)
            ->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID)
            ->where('valid',true)->where('kind','text');
        $index=($lock?$q->lockForUpdate():$q)->first(['reply_id']); if (!$index) return false;
        $q=DB::table('whatsapp_reply_requests')->where('id',$index->reply_id)->where('conversation_id',$conversation)->where('state','ACCEPTED');
        $receipt=($lock?$q->lockForUpdate():$q)->first(['id','completed_at']);
        if (!$receipt||!is_string($receipt->completed_at)) return false;
        $q=DB::table('whatsapp_reply_requests')->where('conversation_id',$conversation)
            ->where('waba_id',WhatsAppInboxAccess::WABA_ID)->where('phone_number_id',WhatsAppInboxAccess::PHONE_ID)
            ->where('id','!=',$receipt->id)->whereIn('state',['CLAIMED','UNKNOWN','ACCEPTED'])->where('kind','!=','reaction')
            ->where(function($activity)use($receipt) {
                foreach (['claimed_at','send_started_at','completed_at'] as $field) $activity->orWhere(function($time)use($field,$receipt) {
                    $time->where($field,'>',$receipt->completed_at)->orWhere(function($tie)use($field,$receipt) {
                        $tie->where($field,$receipt->completed_at)->where('id','>',$receipt->id);
                    });
                });
            });
        return ($lock?$q->lockForUpdate():$q)->first(['id'])===null;
    }

    public function approvalSatisfied(int $conversation,array $data,array $snapshot,bool $lock=false): bool
    {
        try {
            if (!Schema::hasTable('whatsapp_reply_requests')||!Schema::hasTable(WhatsAppInboxMessage::INDEX)) return false;
            $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']??[]);
            $keys=array_intersect_key($snapshot['keys']??[],array_flip(array_column($episode,'id')));
            if (($snapshot['complete']??null)!==true||!$keys) return false;
            $q=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->whereIn('message_key',array_values($keys));
            $ids=$q->pluck('id')->all(); if (!$ids) return false;
            $own=$this->ownedMessages($conversation,min(array_map('intval',$ids))-1,max(array_map('intval',$ids)),$lock); $recap=null;
            $positions=array_flip(array_column($snapshot['transcript'],'id')); $recapPosition=-1;
            foreach ($own as $record) if ($record['marker']['kind']==='confirmation') {
                $label=array_search($record['message']->message_key,$keys,true);
                if ($label!==false&&($positions[$label]??-1)>$recapPosition) {
                    $recap=$record; $recapPosition=$positions[$label];
                }
            }
            if ($recap===null) {
                $allIds=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)
                    ->whereIn('message_key',array_values($snapshot['keys']))->pluck('id')->all();
                if (!$allIds) return false;
                $tail=$this->authenticatedCustomerTail($conversation,$data,$snapshot['transcript'],
                    min(array_map('intval',$allIds))-1,max(array_map('intval',$allIds)),null,$snapshot['keys']);
                if ($tail===null||$tail['final']===null) return false;
                foreach ($tail['rows'] as $row) if ($row['kind']!=='text'||!self::positiveApproval($row['text'])
                    ||!in_array($row['id'],$data['evidence']['customer_acceptance_ids']??[],true)) return false;
                return true;
            }
            $recapId=array_search($recap['message']->message_key,$keys,true); if ($recapId===false) return false;
            if (!$this->recapIsCurrentBusiness($conversation,$snapshot,$recapId,$recap['message']->message_key,$lock)) return false;
            if (!in_array($recapId,$data['evidence']['confirmation_ids']??[],true)
                || !hash_equals($recap['marker']['proposal_hash'],static::proposalHash($data,$snapshot['transcript']))) return false;
            $positions=array_flip(array_column($snapshot['transcript'],'id')); $approved=false;
            foreach ($snapshot['transcript'] as $row) {
                if ($row['speaker']!=='customer'||($positions[$row['id']]??-1)<=($positions[$recapId]??PHP_INT_MAX)) continue;
                // Any intervening customer turn can change the order, even if AI omits its citation.
                if (!self::positiveApproval($row['text'])) return false;
                if (in_array($row['id'],$data['evidence']['customer_acceptance_ids']??[],true)) $approved=true;
            }
            return $approved;
        } catch (\Throwable $ignore) { return false; }
    }
}
