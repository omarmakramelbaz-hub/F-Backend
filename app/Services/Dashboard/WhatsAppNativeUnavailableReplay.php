<?php

namespace App\Services\Dashboard;

use App\Support\WhatsAppInboxProtocol;
use Illuminate\Support\Facades\Crypt;

/** Same unavailable notification, new transport timestamp; never new message facts. */
final class WhatsAppNativeUnavailableReplay
{
    public const VERSION = 'whatsapp-native-unavailable-replay-v1';

    /** Pure proof; callers must have verified the incoming raw-event SHA256. */
    public static function prove(object $thread, object $existing, array $before, array $incoming, ?string $rawHash): bool
    {
        try {
            if (!is_string($rawHash)||!preg_match('/\A[a-f0-9]{64}\z/D',$rawHash)
                ||$existing->source!=='messages'||$existing->direction!=='inbound'||$existing->type!=='unsupported'
                ||($before['source']??null)!=='messages'||($incoming['source']??null)!=='messages'
                ||($before['direction']??null)!=='inbound'||($incoming['direction']??null)!=='inbound'
                ||($before['type']??null)!=='unsupported'||($incoming['type']??null)!=='unsupported'
                ||!array_key_exists('text',$before)||$before['text']!==null||!array_key_exists('text',$incoming)||$incoming['text']!==null
                ||array_key_exists('hydration',$before['content']??[])||array_key_exists('hydration',$incoming['content']??[])) return false;
            $old=$before['content']['message']??null;$new=$incoming['content']['message']??null;
            foreach ([$old,$new] as $raw) {
                if (!is_array($raw)||($raw['type']??null)!=='unsupported'
                    ||array_diff(array_keys($raw),['id','from','from_user_id','to','to_user_id','timestamp','type','errors','unsupported','context','referral'])
                    ||!is_array($raw['errors']??null)||!array_is_list($raw['errors'])||count($raw['errors'])!==1
                    ||!is_array($raw['errors'][0])||($raw['errors'][0]['code']??null)!==131060) return false;
                if (array_key_exists('unsupported',$raw)) {
                    if (!is_array($raw['unsupported'])||array_diff(array_keys($raw['unsupported']),['type','raw_type'])) return false;
                    foreach ($raw['unsupported'] as $value) if (!in_array($value,['unknown','unsupported'],true)) return false;
                }
            }
            foreach (['message_id','peer_identity','peer_phone','peer_user_id','customer_name','source','direction','type','text'] as $field)
                if (!array_key_exists($field,$before)||!array_key_exists($field,$incoming)||$before[$field]!==$incoming[$field]) return false;
            foreach ([[$before,$old],[$incoming,$new]] as [$dto,$raw]) {
                $timestamp=$raw['timestamp']??null;
                if (($raw['id']??null)!==$dto['message_id']||(!is_string($timestamp)&&!is_int($timestamp))
                    ||!preg_match('/\A[0-9]{1,10}\z/D',(string)$timestamp)||(int)$timestamp<1
                    ||(int)$timestamp>min(2147483647,time()+86400)
                    ||gmdate('Y-m-d H:i:s',(int)$timestamp)!==($dto['sent_at']??null)) return false;
                $encoded=json_encode($dto,JSON_THROW_ON_ERROR);if(strlen($encoded)>4194304)return false;
            }
            // Verify the stored ciphertext and its original native timestamp. No
            // timestamp, ciphertext, source history or conversation range is rewritten.
            $native=(new WhatsAppInboxMessage())->projected($thread,$existing);
            if ($native['native_id']!==$before['message_id']||$native['kind']!=='unsupported'||$native['text']!==null
                ||($before['sent_at']??null)!==$existing->sent_at) return false;
            $oldComparable=$before;$newComparable=$incoming;
            unset($oldComparable['sent_at'],$newComparable['sent_at'],
                $oldComparable['content']['message']['timestamp'],$newComparable['content']['message']['timestamp']);
            return self::canonical($oldComparable)===self::canonical($newComparable);
        } catch (\Throwable $error) { return false; }
    }

    /** Raw-bound audit facts only. No database, network or projection actions. */
    public static function inspect(object $thread, object $existing, object $event): ?array
    {
        try {
            if (!is_int($id=filter_var($event->id??null,FILTER_VALIDATE_INT))||$id<1
                ||filter_var($existing->id??null,FILTER_VALIDATE_INT)===false||(int)$existing->id<1
                ||filter_var($thread->id??null,FILTER_VALIDATE_INT)===false||(int)$thread->id<1
                ||!is_string($event->payload??null)||strlen($event->payload)>8388608
                ||!is_string($event->payload_hash??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$event->payload_hash)
                ||!is_string($existing->content??null)||strlen($existing->content)>8388608) return null;
            $json=Crypt::decryptString($event->payload);$plain=Crypt::decryptString($existing->content);
            if (strlen($json)>4194304||strlen($plain)>4194304||!hash_equals($event->payload_hash,hash('sha256',$json)))return null;
            $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);$before=json_decode($plain,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||!is_array($before))return null;
            $report=WhatsAppInboxProtocol::report($payload,WhatsAppInboxConsumer::WABA_ID,WhatsAppInboxConsumer::PHONE_ID);
            if(($report['quarantined_count']??null)!==0||($report['ignored_count']??null)!==0||($report['status_count']??null)!==0
                ||!is_array($report['messages']??null)||count($report['messages'])!==1)return null;
            $incoming=$report['messages'][0];
            if(!self::prove($thread,$existing,$before,$incoming,$event->payload_hash))return null;
            return['proof_version'=>self::VERSION,'event_id'=>$id,'message_id'=>(int)$existing->id,
                'conversation_id'=>(int)$thread->id,'payload_hash'=>$event->payload_hash,
                'ciphertext_sha256'=>hash('sha256',$existing->content),'original_sent_at'=>$before['sent_at'],'incoming_sent_at'=>$incoming['sent_at']];
        }catch(\Throwable$error){return null;}
    }

    private static function canonical($value)
    {
        if(!is_array($value))return$value;
        foreach($value as$key=>$item)$value[$key]=self::canonical($item);
        if(!array_is_list($value))ksort($value,SORT_STRING);
        return$value;
    }
}
