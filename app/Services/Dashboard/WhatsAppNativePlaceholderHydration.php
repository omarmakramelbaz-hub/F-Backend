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
            foreach (['from','from_user_id','to','to_user_id','context','referral'] as $field)
                if (self::canonical($raw[$field]??null)!==self::canonical($full[$field]??null)) return false;
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
            if (!is_array($before)||!is_array($payload)||self::canonical($before)!==self::canonical($marker['before'])) return false;
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
    private static function time($value): ?\DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d H:i:s')===$value?$d:null;
    }
}
