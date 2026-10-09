<?php

namespace App\Console\Commands;

use App\Services\Dashboard\WhatsAppInboxConsumer;
use App\Services\Dashboard\WhatsAppOrderWorkflow;
use Illuminate\Console\Command;

class ProcessWhatsAppOrders extends Command
{
    protected $signature = 'whatsapp:process-orders {--limit=10 : Maximum conversations to inspect (1-20)}';
    protected $description = 'Extract encrypted WhatsApp order drafts; automatic dispatch requires explicit configuration.';

    public function handle(WhatsAppOrderWorkflow $workflow, WhatsAppInboxConsumer $inbox)
    {
        $limit=(string)$this->option('limit');
        if (!preg_match('/\A[0-9]{1,2}\z/',$limit)||(int)$limit<1||(int)$limit>20) { $this->line('{"error":"INVALID_LIMIT"}'); return 1; }
        try {
            if (!(bool)config('whatsapp_orders.enabled',false)) { $this->line('{"disabled":1}'); return 0; }
            // Projection is bounded and retains existing quarantine markers. No retries or billing.
            $capture=$inbox->consume(100);
            $metrics=$workflow->process((int)$limit);
            $this->line(json_encode(['capture'=>$capture,'orders'=>$metrics],JSON_THROW_ON_ERROR));
            return ($capture['errors']??0)>0||$metrics['errors']>0?1:0;
        } catch (\Throwable $error) { $this->line('{"error":"WHATSAPP_ORDER_PROCESS_FAILED"}'); return 1; }
    }
}
