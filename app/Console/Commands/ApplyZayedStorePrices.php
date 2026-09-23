<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplyZayedStorePrices extends Command
{
    protected $signature = 'menu:align-stores-to-zayed-20260923';
    protected $description = 'Apply the owner-approved September 23 store menu prices matching Zayed once, with a transactional backup';

    public function handle()
    {
        $migration = '2026_09_23_210000_align_store_prices_to_zayed';
        if (DB::table('migrations')->where('migration', $migration)->exists()) {
            $this->info('Zayed store-price release already applied; no changes.');
            return 0;
        }
        if (Schema::hasTable('zayed_price_release_20260923')
            && DB::table('zayed_price_release_20260923')->whereNotNull('rolled_back_at')->exists()) {
            $this->info('Zayed store-price release was rolled back; no changes.');
            return 0;
        }
        // Run only this reviewed data release, never unrelated pending migrations.
        return $this->call('migrate', [
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
        ]);
    }
}
