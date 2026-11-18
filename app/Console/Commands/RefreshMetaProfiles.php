<?php

namespace App\Console\Commands;

use App\Services\Dashboard\MetaProfileService;
use Illuminate\Console\Command;

class RefreshMetaProfiles extends Command
{
    protected $signature = 'meta:refresh-profiles {--limit=10}';
    protected $description = 'Enrich missing Meta customer display names without sending messages or processing orders.';

    public function handle(MetaProfileService $profiles)
    {
        $limit = (string) $this->option('limit');
        if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 10) { $this->line('{"error":"INVALID_LIMIT"}'); return 1; }
        try {
            $this->line(json_encode(['profiles' => $profiles->refresh((int) $limit)], JSON_THROW_ON_ERROR));
            return 0;
        } catch (\Throwable $error) { $this->line('{"error":"META_PROFILES_UNAVAILABLE"}'); return 1; }
    }
}
