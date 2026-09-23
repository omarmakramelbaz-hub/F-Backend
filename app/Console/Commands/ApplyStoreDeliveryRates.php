<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplyStoreDeliveryRates extends Command
{
    protected $signature = 'delivery:apply-store-rates-20260923';
    protected $description = 'Apply the owner-approved September 23 store delivery rates once, with a transactional backup';

    public function handle()
    {
        $migration = '2026_09_23_200000_align_store_delivery_rates';
        if (DB::table('migrations')->where('migration', $migration)->exists()) {
            $this->info('Store delivery release already applied or rolled back; no changes.');
            return 0;
        }
        if (Schema::hasTable('store_delivery_release_20260923')
            && DB::table('store_delivery_release_20260923')->whereNotNull('rolled_back_at')->exists()) {
            $this->info('Store delivery release already applied or rolled back; no changes.');
            return 0;
        }
        // Run only this reviewed data release, never unrelated pending migrations.
        return $this->call('migrate', [
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
        ]);
    }
}
