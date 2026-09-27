<?php

namespace App\Console\Commands;

use App\Services\GoStores\ApplicationUploads;
use Illuminate\Console\Command;

class PruneGoStoreSignupUploads extends Command
{
    protected $signature = 'go-stores:prune-signup-uploads';
    protected $description = 'Remove expired private GO store application uploads';

    public function handle(): int
    {
        $this->info('Removed '.app(ApplicationUploads::class)->prune().' expired upload sessions.');
        return 0;
    }
}
