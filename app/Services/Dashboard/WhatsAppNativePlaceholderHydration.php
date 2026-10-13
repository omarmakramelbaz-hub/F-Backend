<?php

namespace App\Services\Dashboard;

use App\Support\WhatsAppInboxProtocol;
use Illuminate\Support\Facades\Crypt;

/** Pure proofs for an authenticated native unavailable-content marker and its same-ID text. */
final class WhatsAppNativePlaceholderHydration
{
    public const VERSION = 'whatsapp-native-placeholder-hydration-v1';

    public static function transition(object $thread, object $existing, array $before, array $incoming): bool
    {
        try {
            if ($existing->source !== 'messages' || $existing->direction !== 'inbound' || $existing->type !== 'unsupported'
                || ($thread->waba_id ?? null) !== WhatsAppInboxConsumer::WABA_ID || ($thread->phone_number_id ?? null) !== WhatsAppInboxConsumer::PHONE_ID
                || (int) $existing->conversation_id !== (int) $thread->id || ($incoming['source'] ?? null) !== 'messages'
                || ($incoming['direction'] ?? null) !== 'inbound' || ($incoming['type'] ?? null) !== 'text'
                || ($before['type'] ?? null) !== 'unsupported' || ($before['source'] ?? null) !== $existing->source
                || ($before['direction'] ?? null) !== 'inbound' || ($before['sent_at'] ?? null) !== $existing->sent_at
                || !array_key_exists('text', $before) || $before['text'] !== null || isset($before['content']['hydration'])) return false;
            foreach (['message_id','peer_identity','peer_phone','peer_user_id'] as $field)
                if (!array_key_exists($field,$before) || $before[$field] !== ($incoming[$field] ?? null)) return false;
            $raw=$before['content']['message']??null; $full=$incoming['content']['message']??null;
            if (!is_array($raw)||!is_array($full)||($raw['type']??null)!=='unsupported'||($full['type']??null)!=='text'
                ||($raw['id']??null)!==$before['message_id']||($full['id']??null)!==$before['message_id']
                ||!is_string($incoming['text']??null)||trim($incoming['text'])===''||($full['text']['body']??null)!==$incoming['text']
                ||array_key_exists('errors',$full)||!is_array($raw['errors']??null)||!array_is_list($raw['errors'])||count($raw['errors'])!==1
                ||!is_array($raw['errors'][0])||($raw['errors'][0]['code']??null)!==131060) return false;
            if (array_diff(array_keys($raw),['id','from','from_user_id','to','to_user_id','timestamp','type','errors','unsupported','context','referral'])
                ||array_diff(array_keys($full),['id','from','from_user_id','to','to_user_id','timestamp','type','text','context','referral'])) return false;
            if (array_key_exists('unsupported',$raw)) {
                $body=$raw['unsupported'];
                if (!is_array($body)||array_diff(array_keys($body),['type','raw_type'])) return false;
                foreach ($body as $value) if (!in_array($value,['unknown','unsupported'],true)) return false;
            }
            foreach (['from','from_user_id','to','to_user_id','context'] as $field)
                if (self::canonical($raw[$field]??null)!==self::canonical($full[$field]??null)) return false;
            if (!self::referralTransition($raw,$full)) return false;
            $old=self::time($existing->sent_at); $new=self::time($incoming['sent_at']??null);
            if ($old===null||$new===null||$new<$old||$new->getTimestamp()-$old->getTimestamp()>3600) return false;
            $proved=(new WhatsAppInboxMessage())->projected($thread,$existing);
            return $proved['native_id']===$before['message_id']&&$proved['kind']==='unsupported'&&$proved['text']===null;
        } catch (\Throwable $error) { return false; }
    }

    /** No database or network actions: callers supply the scoped raw row and quarantine state. */
    public static function prove(object $thread, object $row, array $dto, object $rawEvent, bool $quarantined): bool
    {
        try {
            $marker=$dto['content']['hydration']??null;
            if (!is_array($marker)||array_diff(array_keys($marker),['version','code','event_id','at_utc','before','before_ciphertext','before_ciphertext_sha256','raw_payload_hash'])
                ||count($marker)!==8||$marker['version']!==self::VERSION||$marker['code']!==131060
                ||!is_int($marker['event_id'])||$marker['event_id']<1||(int)$rawEvent->id!==$marker['event_id']
                ||$quarantined||!is_string($rawEvent->processed_at??null)||self::time($rawEvent->processed_at)===null
                ||$rawEvent->processed_at!==$marker['at_utc']||self::time($marker['at_utc'])===null
                ||$marker['at_utc']>now('UTC')->addSeconds(5)->format('Y-m-d H:i:s')
                ||!is_string($marker['before_ciphertext'])||strlen($marker['before_ciphertext'])>8388608
                ||!is_string($marker['before_ciphertext_sha256'])||!preg_match('/\A[a-f0-9]{64}\z/',$marker['before_ciphertext_sha256'])
                ||!hash_equals($marker['before_ciphertext_sha256'],hash('sha256',$marker['before_ciphertext']))
                ||!is_string($marker['raw_payload_hash'])||!preg_match('/\A[a-f0-9]{64}\z/',$marker['raw_payload_hash'])
                ||!is_string($rawEvent->payload_hash??null)||!hash_equals($marker['raw_payload_hash'],$rawEvent->payload_hash)
                ||!is_string($rawEvent->payload??null)||strlen($rawEvent->payload)>8388608) return false;
            $oldPlain=Crypt::decryptString($marker['before_ciphertext']); $json=Crypt::decryptString($rawEvent->payload);
            if (strlen($oldPlain)>4194304||strlen($json)>4194304||!hash_equals($marker['raw_payload_hash'],hash('sha256',$json))) return false;
            $before=json_decode($oldPlain,true,512,JSON_THROW_ON_ERROR); $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            if (!is_array($before)||!is_array($payload)||!is_array($marker['before'])
                ||!self::boundedReferral($before['content']['message']['referral']??null)
                ||!self::boundedReferral($marker['before']['content']['message']['referral']??null)
                ||!self::boundedReferral($dto['content']['message']['referral']??null)
                ||self::canonical($before)!==self::canonical($marker['before'])) return false;
            $report=WhatsAppInboxProtocol::report($payload,WhatsAppInboxConsumer::WABA_ID,WhatsAppInboxConsumer::PHONE_ID);
            if ($report['quarantined_count']!==0||count($report['messages'])<1||count($report['messages'])>500) return false;
            $matching=[];
            foreach ($report['messages'] as $message) if (($message['message_id']??null)===($dto['message_id']??null)) $matching[]=$message;
            if (count($matching)!==1) return false;
            $incoming=$matching[0];
            $oldRow=clone $row; $oldRow->type='unsupported'; $oldRow->source=$before['source']??null;
            $oldRow->sent_at=$before['sent_at']??null; $oldRow->content=$marker['before_ciphertext'];
            if (!self::transition($thread,$oldRow,$before,$incoming)||$row->type!=='text'||$row->source!==$incoming['source']
                ||$row->direction!==$incoming['direction']||$row->sent_at!==$incoming['sent_at']) return false;
            $sources=array_values(array_unique(array_merge($before['content']['sources'],$incoming['content']['sources']))); sort($sources);
            $incoming['content']['sources']=$sources; $incoming['content']['hydration']=$marker;
            if (self::canonical($incoming)!==self::canonical($dto)) return false;
            $scope=WhatsAppInboxConsumer::WABA_ID.':'.WhatsAppInboxConsumer::PHONE_ID.':';
            return hash_equals((string)$row->message_key,hash('sha256','whatsapp-message-v1:'.$scope.$incoming['message_id']))
                &&hash_equals((string)$thread->peer_hash,hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$incoming['peer_identity'],Crypt::getKey()));
        } catch (\Throwable $error) { return false; }
    }

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as $key=>$item) $value[$key]=self::canonical($item);
        if (!array_is_list($value)) ksort($value,SORT_STRING);
        return $value;
    }

    /** An unavailable-content notification may omit the later authenticated ad metadata. */
    private static function referralTransition(array $before,array $incoming): bool
    {
        $prior=$before['referral']??null; $next=$incoming['referral']??null;
        // Bound traversal before canonical comparison, including malformed/cyclic PHP input.
        if (!self::boundedReferral($prior) || !self::boundedReferral($next)) return false;
        if (self::canonical($prior)===self::canonical($next)) return true;
        // Existing referral facts are never replaced, merged, removed or reinterpreted.
        if (array_key_exists('referral',$before) && $before['referral']!==null) return false;
        $referral=$incoming['referral']??null;
        if (!is_array($referral) || $referral===[] || array_is_list($referral) || count($referral)>16) return false;
        $enrichmentNodes=0;
        if (!self::opaqueReferralValue($referral,0,$enrichmentNodes,true)) return false;
        $reserved=['from','from_user_id','to','to_user_id','peer_identity','peer_phone','peer_user_id','waba_id','phone_number_id',
            'conversation_id','message_id','timestamp','context','errors','type','order','customer','customer_name','customer_phone',
            'name','phone','address','branch','items','quantity','price','amount','total','role','system','prompt','instruction','instructions','tool','tools'];
        foreach ($referral as $key=>$value) {
            if (!is_string($key) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/',$key) || in_array($key,$reserved,true)) return false;
        }
        if (!in_array($referral['source_type']??null,['ad','post'],true)
            || !is_string($referral['source_id']??null) || !preg_match('/\A[1-9][0-9]{0,31}\z/',$referral['source_id'])
            || !self::referralUrl($referral['source_url']??null)) return false;
        foreach (['headline','body','ctwa_clid'] as $field)
            if (array_key_exists($field,$referral) && !is_string($referral[$field])) return false;
        if (array_key_exists('media_type',$referral)
            && (!is_string($referral['media_type']) || !in_array(strtolower($referral['media_type']),['image','video'],true))) return false;
        foreach (['image_url','video_url','thumbnail_url'] as $field)
            if (array_key_exists($field,$referral) && $referral[$field]!==null && !self::referralUrl($referral[$field])) return false;
        // Values and bounded opaque JSON extensions stay exact encrypted metadata. No URL is fetched
        // and no referral prose, identifier or extension becomes customer/order evidence.
        return true;
    }

    private static function boundedReferral($value): bool
    {
        $nodes=0;
        return self::opaqueReferralValue($value,0,$nodes,false)
            && strlen(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))<=24576;
    }

    /** Bounded JSON metadata only; never flatten it into evidence, routing or authorization. */
    private static function opaqueReferralValue($value,int $depth,int &$nodes,bool $enrichment): bool
    {
        if (++$nodes>128) return false;
        if (is_array($value)) {
            // Referral root is depth zero; at most four nested container levels beneath it.
            if ($depth>4 || count($value)>32) return false;
            foreach ($value as $key=>$child) {
                // Numeric object keys become integers when JSON is decoded associatively.
                if (!is_int($key) && !is_string($key)) return false;
                $name=(string)$key;
                if (strlen($name)>128 || !mb_check_encoding($name,'UTF-8') || preg_match('/[\x00-\x1f\x7f]/',$name)) return false;
                if (!self::opaqueReferralValue($child,$depth+1,$nodes,$enrichment)) return false;
            }
            return true;
        }
        if (is_string($value)) return strlen($value)<=4096 && mb_check_encoding($value,'UTF-8')
            && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value);
        if ($value===null || is_bool($value)) return true;
        if (is_int($value)) return !$enrichment || $value>=0;
        // Preserve legacy equality for ordinary finite JSON numbers. New enrichment excludes
        // floats because the existing serialization can lose their exact type (e.g. 1.0).
        return !$enrichment && is_float($value) && is_finite($value);
    }

    private static function referralUrl($value): bool
    {
        if (!is_string($value) || $value==='' || strlen($value)>4096 || preg_match('/[\x00-\x20\x7f]/',$value)) return false;
        $url=parse_url($value);
        return is_array($url) && in_array(strtolower($url['scheme']??''),['http','https'],true)
            && is_string($url['host']??null) && $url['host']!=='' && strlen($url['host'])<=253
            && !array_key_exists('user',$url) && !array_key_exists('pass',$url)
            && filter_var($value,FILTER_VALIDATE_URL)!==false;
    }
    private static function time($value): ?\DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d H:i:s')===$value?$d:null;
    }
}
