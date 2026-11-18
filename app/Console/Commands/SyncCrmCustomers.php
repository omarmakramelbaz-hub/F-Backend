<?php

namespace App\Console\Commands;

use App\Services\Dashboard\CustomerDirectorySync;
use Illuminate\Console\Command;

class SyncCrmCustomers extends Command
{
    protected $signature = 'crm:customers-sync {--limit=500 : Maximum rows per source in each batch} {--bootstrap : Finish the initial historical customer import}';
    protected $description = 'Capture existing customers without sending historical feedback; reconcile a bounded batch of new and changed profiles.';

    public function handle(CustomerDirectorySync $sync): int
    {
        $limit = max(1, min(2000, (int)$this->option('limit')));
        $total = 0;
        do {
            $result = $sync->run($limit);
            if (!$result['ready']) { $this->error('Customer directory migrations must be applied first.'); return 1; }
            $total += $result['processed'];
        } while ($this->option('bootstrap') && !$result['bootstrapped']);
        $this->info('Customer profiles reconciled: '.$total.'. Historical feedback messages queued: 0.');
        return 0;
    }
}
