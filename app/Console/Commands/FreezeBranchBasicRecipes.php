<?php

namespace App\Console\Commands;

use App\Services\Dashboard\BranchBasicRecipes;
use Illuminate\Console\Command;

class FreezeBranchBasicRecipes extends Command
{
    protected $signature = 'inventory:freeze-basic-recipes {--check : Read-only baseline preflight} {--expected-content-hash= : Reviewed source recipe digest}';
    protected $description = 'Capture the approved Mahalla menu recipes once as the immutable basic recipe.';

    public function handle(BranchBasicRecipes $recipes): int
    {
        $expected = $this->option('expected-content-hash');
        $result = $recipes->freeze((bool) $this->option('check'), $expected === null ? null : (string) $expected);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 0;
    }
}
