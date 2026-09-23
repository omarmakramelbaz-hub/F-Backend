<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplyApprovedMenuPrices extends Command
{
    protected $signature = 'menu:apply-approved-20260923';
    protected $description = 'Apply the owner-approved September 23 menu prices once, with a transactional backup';

    public function handle()
    {
        $migration = '2026_09_23_190000_align_approved_branch_menu_prices';
        if (DB::table('migrations')->where('migration', $migration)->exists()) {
            return 0;
        }
        if (Schema::hasTable('menu_price_release_20260923')
            && DB::table('menu_price_release_20260923')->whereNotNull('rolled_back_at')->exists()) {
            return 0;
        }
        // Run only this reviewed data release, never unrelated pending migrations.
        return $this->call('migrate', [
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
        ]);
    }
}
