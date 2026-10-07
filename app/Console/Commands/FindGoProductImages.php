<?php

namespace App\Console\Commands;

use App\Services\GoStores\AutomaticProductImages;
use Illuminate\Console\Command;

class FindGoProductImages extends Command
{
    protected $signature = 'go-stores:find-product-images {--limit= : Maximum products in this run}';
    protected $description = 'Find and attach AI-verified unbranded GO signup product images';

    public function handle(AutomaticProductImages $images): int
    {
        $limit = max(1, min(10, (int) ($this->option('limit') ?? config('go_product_images.batch_size', 3))));
        $this->info('Processed '.$images->process($limit).' product image requests.');
        return 0;
    }
}
