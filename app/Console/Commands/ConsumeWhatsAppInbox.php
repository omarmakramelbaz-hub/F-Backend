<?php

namespace App\Console\Commands;

use App\Services\Dashboard\WhatsAppInboxConsumer;
use Illuminate\Console\Command;
use Throwable;

class ConsumeWhatsAppInbox extends Command
{
    protected $signature = 'whatsapp:consume-inbox {--limit=100 : Maximum encrypted events to inspect (1-500)} {--retry-quarantined : Explicitly retry retained quarantined events}';
    protected $description = 'Project captured WhatsApp messages into the encrypted dashboard inbox.';

    public function handle(WhatsAppInboxConsumer $consumer)
    {
        $limit = (string) $this->option('limit');
        if (!preg_match('/\A[0-9]{1,3}\z/', $limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->line('{"error":"INVALID_LIMIT"}');
            return 1;
        }
        try {
            $metrics = $consumer->consume((int) $limit, (bool) $this->option('retry-quarantined'));
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
            return $metrics['errors'] > 0 ? 1 : ($metrics['quarantined_events'] > 0 ? 2 : 0);
        } catch (Throwable $error) {
            $this->line('{"error":"INBOX_CONSUMER_FAILED"}');
            return 1;
        }
    }
}
