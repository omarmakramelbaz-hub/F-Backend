<?php

namespace App\Services\Dashboard;

/** Shares the verified POS/customer/pricing workflow, with independent Meta activation and data. */
class MetaOrderWorkflow extends WhatsAppOrderWorkflow
{
    protected const DRAFTS = 'meta_order_drafts';
    protected const SCANS = 'meta_order_scans';
    protected function table(string $table): string { return str_replace('whatsapp_', 'meta_', $table); }
    protected function orderConfig(string $name, $default = null)
    {
        // Primary control shares the already approved business branch scope. Actor access
        // is independently checked by the normal ERP branch resolver on every use.
        if ($name==='whatsapp_orders.allowed_branch_ids'&&$this->controlInstalled())
            return config('whatsapp_orders.allowed_branch_ids',[]);
        return config(str_replace(['whatsapp_orders', 'whatsapp_cart'], ['meta_orders', 'meta_cart'], $name), $default);
    }
    protected function inboxAccess() { return app(MetaInboxAccess::class); }
    protected function provider() { return app()->bound(WhatsAppOrderAiProvider::class)?app(WhatsAppOrderAiProvider::class):new WhatsAppOrderAiProvider(null, config('meta_orders', [])); }
    protected function scope($query, string $prefix = '') { return MetaInboxAccess::scope($query, $prefix); }
    protected function scopeKey(object $thread): string { return $thread->waba_id.':'.$thread->phone_number_id.':'; }

    protected function snapshot(int $conversation,int $floor,int $ceiling,bool $lock=false): array
    { return app(MetaOrderNativeEvidence::class)->snapshot($conversation,$floor,$ceiling,$lock); }

    protected function followupService() { return app(MetaOrderFollowupService::class); }

    protected function automationChannel(?int $conversation=null): string
    {
        if ($conversation===null) return '';
        try { return (string)app(MetaOrderNativeEvidence::class)->thread($conversation)->waba_id; }
        catch (\Throwable $ignore) { return ''; }
    }

    protected function filterAutomaticCandidates($query)
    {
        if (!$this->controlInstalled()) return $query;
        $floors=[];
        foreach (['messenger','instagram'] as $channel) {
            try { $gate=app(MetaAiControl::class)->processingGate($channel); }
            catch (\Throwable $ignore) { continue; }
            if (($gate['effective']??false)===true&&is_int($gate['floor']['message_floor']??null))
                $floors[$channel]=$gate['floor'];
        }
        return $query->where(function($scope) use($floors) {
            $scope->whereRaw('0 = 1');
            foreach ($floors as $channel=>$floor) $scope->orWhere(function($candidate) use($channel,$floor) {
                $candidate->where('c.waba_id',$channel)->where('m.id','>',$floor['message_floor']);
                $this->filterDispatchOnlyCandidates($this->filterPrimaryInboundCandidates($candidate,$floor),$channel);
            });
        });
    }

    protected function primaryInbound(int $conversation,array $snapshot): ?array
    { return app(MetaOrderNativeEvidence::class)->latestInbound($conversation,$snapshot); }

    protected function primaryHumanClear(int $conversation,array $latest,array $snapshot): bool
    { return app(MetaOrderFollowupService::class)->humanClear($conversation,$latest,$snapshot); }

    protected function projectAcceptedReplies(?int $conversation,?string $acceptedSince): bool
    {
        try { return (app(MetaReplyService::class)->projectPending(100,$conversation,$acceptedSince)['complete']??false)===true; }
        catch (\Throwable $ignore) { return false; }
    }
}
