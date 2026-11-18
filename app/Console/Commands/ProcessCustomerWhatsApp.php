<?php

namespace App\Console\Commands;

use App\Services\Dashboard\CustomerMessagingService;
use Illuminate\Console\Command;

class ProcessCustomerWhatsApp extends Command
{
    protected $signature = 'crm:whatsapp-process {--limit=25 : Maximum due recipients to inspect, 1-100}';
    protected $description = 'Dispatch consented post-purchase feedback and approved promotional WhatsApp templates.';

    public function handle(CustomerMessagingService $service)
    {
        $limit = (string) $this->option('limit');
        if (!preg_match('/\A[0-9]{1,3}\z/', $limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->line('{"error":"INVALID_LIMIT"}'); return 1;
        }
        try { $this->line(json_encode($service->process((int) $limit), JSON_THROW_ON_ERROR)); return 0; }
        catch (\Throwable $error) { $this->line('{"error":"CRM_WHATSAPP_WORKER_FAILED"}'); return 1; }
    }
}
