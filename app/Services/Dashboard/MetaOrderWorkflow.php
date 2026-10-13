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
        return config(str_replace(['whatsapp_orders', 'whatsapp_cart'], ['meta_orders', 'meta_cart'], $name), $default);
    }
    protected function inboxAccess() { return app(MetaInboxAccess::class); }
    protected function provider() { return new WhatsAppOrderAiProvider(null, config('meta_orders', [])); }
    protected function scope($query, string $prefix = '') { return MetaInboxAccess::scope($query, $prefix); }
    protected function scopeKey(object $thread): string { return $thread->waba_id.':'.$thread->phone_number_id.':'; }
}
