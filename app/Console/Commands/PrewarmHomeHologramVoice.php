<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\Dashboard\DashboardLoginIdentity;
use App\Services\Dashboard\HomeHologramVoice;
use Illuminate\Console\Command;

class PrewarmHomeHologramVoice extends Command
{
    protected $signature = 'hologram-voice:prewarm';
    protected $description = 'Spool approved personalized greetings for dashboard accounts in this runtime only';

    public function handle(HomeHologramVoice $voice): int
    {
        $counts = ['ready' => 0, 'queued' => 0, 'unavailable' => 0];
        $accounts = [];
        // Account allowlist is deliberate: never synthesize the customer directory.
        User::withoutGlobalScopes()->whereIn('account_type', DashboardLoginIdentity::ACCOUNT_TYPES)
            ->select(['id', 'name', 'account_type'])->orderBy('id')->chunkById(100, function ($actors) use ($voice, &$counts, &$accounts) {
                foreach ($actors as $actor) {
                    $entry = $voice->prewarmDetails($actor);
                    $counts[$entry['state']]++;
                    $accounts[] = $entry;
                }
            });
        $written = $voice->writePrewarmInventory($accounts, $counts);
        $this->line(json_encode($counts + ['inventory_written' => $written]));
        return $written && $counts['unavailable'] === 0 ? 0 : 1;
    }
}
