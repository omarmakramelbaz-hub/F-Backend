<?php

namespace App\Services\Dashboard;

use App\Support\MetaMessagingProtocol;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Transactional local projection only: no replies, profile lookups or order writes. */
class MetaInboxConsumer
{
    public function consume(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('LIMIT');
        $metrics = ['events_processed'=>0, 'messages_inserted'=>0, 'messages_replayed'=>0, 'quarantined_events'=>0, 'errors'=>0];
        $ids = DB::table('meta_webhook_events')->whereNull('processed_at')->whereNotExists(function ($q) {
            $q->select('event_id')->from('meta_inbox_ingestion_failures')->whereColumn('event_id', 'meta_webhook_events.id');
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $counts = DB::transaction(function () use ($id) {
                    $event = DB::table('meta_webhook_events')->where('id', $id)->lockForUpdate()->first();
                    if (!$event || $event->processed_at !== null || DB::table('meta_inbox_ingestion_failures')->where('event_id', $id)->exists()) return [];
                    try {
                        $plain = Crypt::decryptString($event->payload);
                        if (strlen($plain) > 4194304) throw new \RuntimeException('SIZE');
                        $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
                        $report = MetaMessagingProtocol::report($payload, MetaInboxAccess::accounts());
                    } catch (\Throwable $error) { throw new MetaInvalidEvent(); }
                    $counts = ['events_processed'=>1, 'messages_inserted'=>0, 'messages_replayed'=>0];
                    foreach ($report['messages'] as $message) {
                        $inserted = $this->persist($message);
                        $counts[$inserted ? 'messages_inserted' : 'messages_replayed']++;
                    }
                    DB::table('meta_webhook_events')->where('id', $id)->update(['processed_at'=>now('UTC')]);
                    return $counts;
                }, 3);
                foreach ($counts as $key=>$count) $metrics[$key] += $count;
            } catch (MetaInvalidEvent $error) {
                $metrics['quarantined_events']++;
                try {
                    DB::transaction(function () use ($id) {
                        $event=DB::table('meta_webhook_events')->where('id',$id)->lockForUpdate()->first();
                        if ($event && $event->processed_at===null) DB::table('meta_inbox_ingestion_failures')->insertOrIgnore([
                            'event_id'=>$id, 'reason'=>'INVALID_EVENT', 'attempts'=>1, 'last_attempted_at'=>now('UTC')]);
                    });
                } catch (\Throwable $e) { $metrics['errors']++; }
            } catch (\Throwable $error) { $metrics['errors']++; } // no customer data or raw exception text
        }
        return $metrics;
    }

    private function persist(array $m): bool
    {
        $scope=$m['channel'].':'.$m['account_id'].':';
        $peer=hash_hmac('sha256','whatsapp-peer-v1:'.$scope.$m['peer_identity'],Crypt::getKey());
        $key=hash('sha256','whatsapp-message-v1:'.$scope.$m['message_id']);
        $query=DB::table('meta_inbox_conversations')->where('waba_id',$m['channel'])
            ->where('phone_number_id',$m['account_id'])->where('peer_hash',$peer);
        $thread=(clone $query)->lockForUpdate()->first();
        if (!$thread) {
            // Competing consumers can claim the same peer; unique index resolves the race.
            DB::table('meta_inbox_conversations')->insertOrIgnore([
                'waba_id'=>$m['channel'],'phone_number_id'=>$m['account_id'],'peer_hash'=>$peer,
                'customer'=>$this->encrypt(['name'=>null,'phone'=>null,'user_id'=>$m['peer_user_id']]),
                'first_message_at'=>$m['sent_at'],'last_message_at'=>$m['sent_at'], 'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $thread=(clone $query)->lockForUpdate()->first();
        }
        if (!$thread) throw new \RuntimeException('PERSISTENCE');
        // Serialize replay checks after claiming the peer, including concurrent deliveries.
        $old=DB::table('meta_inbox_messages')->where('message_key',$key)->lockForUpdate()->first();
        if ($old) {
            if($old->source==='dashboard_api'){
                if((int)$old->conversation_id!==(int)$thread->id
                    ||!(new MetaReplyService())->matchesAcceptedNativeEcho($thread,$old,$m,true))throw new MetaInvalidEvent();
                // Keep explicit receipt provenance and its original completion time; the raw echo remains captured.
                return false;
            }
            $saved=json_decode(Crypt::decryptString($old->content),true,64,JSON_THROW_ON_ERROR);
            foreach (['channel','account_id','message_id','peer_identity','direction','type','text','sent_at'] as $field)
                if (($saved[$field]??null)!==$m[$field]) throw new MetaInvalidEvent();
            if ((int)$old->conversation_id!==(int)$thread->id || ($saved['content']['message']['meta']['attachments']??[])!==($m['content']['message']['meta']['attachments']??[])) throw new MetaInvalidEvent();
            return false;
        }
        DB::table('meta_inbox_messages')->insert([
            'conversation_id'=>$thread->id,'message_key'=>$key,'type'=>$m['type'],'direction'=>$m['direction'],
            'source'=>$m['source'],'content'=>$this->encrypt($m),'sent_at'=>$m['sent_at'],'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
        DB::table('meta_inbox_conversations')->where('id',$thread->id)->update([
            'first_message_at'=>min($thread->first_message_at,$m['sent_at']),
            'last_message_at'=>max($thread->last_message_at,$m['sent_at']),'updated_at'=>now('UTC')]);
        return true;
    }

    private function encrypt(array $data): string { return Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
}
