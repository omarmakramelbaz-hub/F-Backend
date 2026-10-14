<?php
namespace App\Services\Dashboard;

use App\Support\WhatsAppOrderExtraction;
use App\Support\WhatsAppInboxProtocol;
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
            $guard=fn():bool=>$refresh()===$plan&&$this->handoffAllowed($plan);
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
            if (!self::validPlan($plan)) return false;
            $tail=$this->authenticatedCustomerTail($plan['conversation_id'],$plan['data'],$plan['transcript'],
                $plan['evidence_floor'],$plan['evidence_ceiling'],['latest_id'=>$plan['latest_customer_id'],
                    'latest_key'=>$plan['latest_customer_key'],'episode_key'=>$plan['episode_customer_key']]);
            if ($tail===null) return false;
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

    private function confirmedOrderQuestionHandoff(array $plan,?array $tail=null): bool
    {
        if (!self::validPlan($plan)||($plan['data']['decision']??null)!=='CONFIRMED') return false;
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
        if (!self::validPlan($plan)||self::fields($plan['fields'])===['CONFIRMATION']
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

    private static function positiveApproval($text): bool
    {
        return is_string($text)&&preg_match('/\A\s*(?:(?:(?:تمام|نعم)\s+)?(?:موافق|أوافق|اوافق)'
            .'(?:\s+على\s+(?:الطلب|البيانات(?:\s+والطلب)?))?|تمام|حاضر|نعم|اوكي|أوكي|ماشي|ok|okay|yes)[.!]?\s*\z/iu',$text)===1;
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
            if (!Schema::hasTable('whatsapp_reply_requests')||!Schema::hasTable(WhatsAppInboxMessage::INDEX)) return false;
            $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']??[]);
            $keys=array_intersect_key($snapshot['keys']??[],array_flip(array_column($episode,'id')));
            if (($snapshot['complete']??null)!==true||!$keys) return false;
            $q=DB::table('whatsapp_inbox_messages')->where('conversation_id',$conversation)->whereIn('message_key',array_values($keys));
            $ids=$q->pluck('id')->all(); if (!$ids) return false;
            $own=$this->ownedMessages($conversation,min(array_map('intval',$ids))-1,max(array_map('intval',$ids)),$lock); $recap=null;
            foreach ($own as $record) if ($record['marker']['kind']==='confirmation') { $recap=$record; break; }
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
            if (!in_array($recapId,$data['evidence']['confirmation_ids']??[],true)
                || !hash_equals($recap['marker']['proposal_hash'],self::proposalHash($data,$snapshot['transcript']))) return false;
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
