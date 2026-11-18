<?php

namespace App\Console\Commands;

use App\Services\Site\WebsiteMetaPurchase;
use Illuminate\Console\Command;

class SendWebsiteMetaPurchases extends Command
{
    protected $signature = 'meta:website-purchases {--limit=25 : Maximum events to attempt} {--check : Read-only readiness and aggregate counts}';
    protected $description = 'Send new website Purchase events from the dedicated encrypted outbox';

    public function handle(WebsiteMetaPurchase $purchases): int
    {
        $result = $this->option('check') ? $purchases->readiness()
            : $purchases->drain((int) $this->option('limit'));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return isset($result['check_error']) ? 1 : 0;
    }
}
