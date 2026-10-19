<?php
namespace App\Services\Dashboard;

use App\Support\WhatsAppOrderExtraction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** The shared planner uses native Meta evidence and a separate Meta receipt namespace. */
class MetaOrderFollowupService extends WhatsAppOrderFollowupService
{
    protected const MARKER_VERSION='meta-order-followup-v1';

    public function __construct(?MetaReplyService $replies=null,?WhatsAppOrderAiProvider $provider=null)
    { $this->replies=$replies; $this->provider=$provider; }

    protected function replyService() { return app(MetaReplyService::class); }

    protected function providerService(array $plan)
    {
        if (app()->bound(WhatsAppOrderAiProvider::class)) return app(WhatsAppOrderAiProvider::class);
        return new WhatsAppOrderAiProvider(null,array_replace((array)config('meta_orders',[]),['enabled'=>true]));
    }

    public static function requestUuid(array $plan,string $key): string
    {
        foreach (['episode_customer_key','latest_customer_key'] as $field)
            if (!is_string($plan[$field]??null)||!preg_match('/\A[a-f0-9]{64}\z/',$plan[$field])) throw new \RuntimeException();
        if (!is_int($plan['conversation_id']??null)||$plan['conversation_id']<1||strlen($key)<16) throw new \RuntimeException();
        $thread=(new MetaOrderNativeEvidence())->thread($plan['conversation_id']);
        $hex=hash_hmac('sha256','meta-order-followup-v1:'.$thread->waba_id.':'.$thread->phone_number_id.':'.$thread->id.':'.$plan['latest_customer_key'],$key);
        $hex[12]='5'; $hex[16]=dechex((hexdec($hex[16])&3)|8);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    protected function renderQuestion(array $plan,array $fields,string $variant,?array $choice=null): string
    {
        $text=parent::renderQuestion($plan,$fields,$variant,$choice);
        $text=str_replace(['من واتساب','في واتساب'],['داخل المحادثة','داخل المحادثة'],$text);
        if (mb_strlen($text,'UTF-8')>1000) throw new \RuntimeException('META_TEXT_LIMIT');
        return $text;
    }

    public function handoffAllowed(array $plan): bool
    {
        try { return static::validPlan($plan)&&isset($plan['primary_ai'])&&$this->primaryHandoff($plan); }
        catch (\Throwable $ignore) { return false; }
    }

    protected function primaryHandoff(array $plan): bool
    {
        return static::validPrimaryToken($plan['primary_ai']??null)
            &&in_array($plan['primary_ai']['channel'],['messenger','instagram'],true)
            &&app(MetaOrderWorkflow::class)->primaryPlanCurrent($plan);
    }

    /** The ledger and genuine echo are alternative projections of the same accepted send. */
    public function ownedMessages(int $conversation,int $floor,int $ceiling,bool $lock=false): array
    {
        $native=new MetaOrderNativeEvidence(); $thread=$native->thread($conversation,$lock);
        $query=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('id','>',$floor)->where('id','<=',$ceiling)
            ->where('direction','outbound')->where('type','text')->orderByDesc('id')->limit(61);
        $rows=($lock?$query->lockForUpdate():$query)->get(); if (count($rows)>60) throw new \RuntimeException();
        $records=[]; $replies=$this->replies??$this->replyService();
        foreach ($rows as $row) {
            $native->projected($thread,$row,$lock);
            $receipt=$replies->acceptedReceiptForNative($thread,$row,$lock);
            if ($receipt===null) continue;
            $marker=$receipt['order_followup']??null;
            if (!is_array($marker)||!static::validMarker($marker,$conversation,$receipt['client_request_id']??'',(int)($marker['latest_customer_id']??0)))
                throw new \RuntimeException('INVALID_META_OWNED_REPLY');
            $records[]=['marker'=>$marker,'message'=>$row];
        }
        return $records;
    }

    /** Every business turn after the native input must be one of our accepted replies. */
    public function humanClear(int $conversation,array $latest,array $snapshot,bool $lock=false): bool
    {
        try {
            $after=false; $business=[];
            foreach ($snapshot['transcript'] as $row) {
                if ($row['id']===$latest['label']) { $after=true; continue; }
                if ($after&&$row['speaker']==='business') $business[]=$snapshot['keys'][$row['id']];
            }
            if ($business!==[]) {
                $ids=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->whereIn('message_key',$business)->pluck('id')->all();
                if (count($ids)!==count($business)) return false;
                $own=$this->ownedMessages($conversation,min($ids)-1,max($ids),$lock);
                $owned=array_map(static fn($r)=>$r['message']->message_key,$own);
                foreach ($business as $key) if (!in_array($key,$owned,true)) return false;
            }
            return !($this->replies??$this->replyService())->hasManualReplyAfter($conversation,$latest['sent_at'],$lock);
        } catch (\Throwable $ignore) { return false; }
    }

    public function questionContinuation(int $conversation,array $snapshot,string $notBefore): ?array
    {
        try {
            $latest=(new MetaOrderNativeEvidence())->latestInbound($conversation,$snapshot);
            if ($latest===null||$latest['sent_at']<$notBefore||WhatsAppOrderExtraction::hasProbe($snapshot['transcript'])) return null;
            $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
            $positions=array_flip(array_column($episode,'id'));
            $ids=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->whereIn('message_key',array_values($snapshot['keys']))->pluck('id')->all();
            if (count($ids)!==count($snapshot['keys'])) return null;
            foreach ($this->ownedMessages($conversation,min($ids)-1,max($ids)) as $record) {
                if ($record['marker']['kind']!=='question') continue;
                $label=array_search($record['message']->message_key,$snapshot['keys'],true);
                $anchor=array_search($record['marker']['episode_customer_key'],$snapshot['keys'],true);
                if ($label===false||$anchor===false||!isset($positions[$label],$positions[$anchor],$positions[$latest['label']])
                    ||$positions[$latest['label']]<=$positions[$label]||$positions[$anchor]>=$positions[$label]) continue;
                return ['episode_customer_key'=>$record['marker']['episode_customer_key'],'fields'=>$record['marker']['fields']];
            }
        } catch (\Throwable $ignore) {}
        return null;
    }

    public function questionRecoveryIntent(int $conversation,array $snapshot,string $notBefore): ?string
    {
        // Primary control supplies authority to clarify; rejected extraction supplies no facts.
        return null;
    }

    public function waitingForCustomer(int $conversation,int $analyzedCeiling,int $ceiling): bool
    {
        try {
            if ($analyzedCeiling<0||$ceiling<=$analyzedCeiling) return false;
            $rows=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('id','>',$analyzedCeiling)->where('id','<=',$ceiling)->limit(61)->get();
            if (!$rows->count()||$rows->count()>60) return false;
            $own=$this->ownedMessages($conversation,$analyzedCeiling,$ceiling); if (count($own)!==count($rows)) return false;
            $latest=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->where('direction','inbound')->orderByDesc('sent_at')->orderByDesc('id')->first();
            if (!$latest) return false;
            foreach ($own as $record) if ($record['marker']['latest_customer_id']!==(int)$latest->id||$record['marker']['latest_customer_key']!==$latest->message_key) return false;
            return true;
        } catch (\Throwable $ignore) { return false; }
    }

    public function approvalSatisfied(int $conversation,array $data,array $snapshot,bool $lock=false): bool
    {
        try {
            $latest=(new MetaOrderNativeEvidence())->latestInbound($conversation,$snapshot);
            if ($latest===null||!$this->humanClear($conversation,$latest,$snapshot,$lock)||WhatsAppOrderExtraction::hasProbe($snapshot['transcript'])) return false;
            $episode=WhatsAppOrderConversationEvidence::episodeTranscript($snapshot['transcript']);
            $keys=array_intersect_key($snapshot['keys'],array_flip(array_column($episode,'id')));
            $ids=DB::table('meta_inbox_messages')->where('conversation_id',$conversation)->whereIn('message_key',array_values($keys))->pluck('id')->all();
            if (!$ids||count($ids)!==count($keys)) return false;
            $recap=null;
            foreach ($this->ownedMessages($conversation,min($ids)-1,max($ids),$lock) as $record)
                if ($record['marker']['kind']==='confirmation') { $recap=$record; break; }
            if ($recap===null) return false;
            $label=array_search($recap['message']->message_key,$keys,true);
            if ($label===false||!in_array($label,$data['evidence']['confirmation_ids']??[],true)
                ||!hash_equals($recap['marker']['proposal_hash'],static::proposalHash($data,$snapshot['transcript']))) return false;
            if (!$this->humanClear($conversation,['label'=>$label,'sent_at'=>$recap['message']->sent_at],$snapshot,$lock)) return false;
            $after=false; $approved=false;
            foreach ($episode as $row) {
                if ($row['id']===$label) { $after=true; continue; }
                if (!$after) continue;
                // Approval belongs to the last actual proposal, including staff edits
                // or intervening questions which the model omitted from citations.
                if ($row['speaker']==='business') return false;
                if (!static::positiveApproval($row['text'])) return false;
                if (in_array($row['id'],$data['evidence']['customer_acceptance_ids']??[],true)) $approved=true;
            }
            return $approved;
        } catch (\Throwable $ignore) { return false; }
    }
}
