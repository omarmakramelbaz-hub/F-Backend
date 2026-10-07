<?php

namespace App\Console\Commands;

use App\Services\GoStores\AutomaticProductImages;
use App\Services\GoStores\ProductImageSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FindGoProductImages extends Command
{
    protected $signature = 'go-stores:find-product-images {--limit= : Maximum products in this run} {--check= : Search a test product without creating a store application}';
    protected $description = 'Find and attach AI-verified unbranded GO signup product images';

    public function handle(AutomaticProductImages $images): int
    {
        if ($this->option('check') !== null) {
            $name = trim((string) $this->option('check'));
            if ($name === '' || mb_strlen($name) > 180) {
                $this->error('Provide a product name of 1 to 180 characters.');
                return 1;
            }
            $search = app(ProductImageSearch::class);
            if (!$search->configured()) {
                $this->error('GO product image providers are not configured.');
                return 1;
            }
            try {
                $image = $search->find($name, 'supermarket');
            } catch (\Throwable $error) {
                // Provider exception bodies may contain secrets; never print them.
                $this->error('Live image search failed. Check provider credentials, quotas and server connectivity.');
                return 1;
            }
            if (!$image) {
                $this->error('No confident unbranded match was found.');
                return 1;
            }
            $this->info('LIVE UNBRANDED IMAGE VERIFIED');
            $this->line(Storage::disk('public')->url($image['path']));
            return 0;
        }
        $limit = max(1, min(10, (int) ($this->option('limit') ?? config('go_product_images.batch_size', 3))));
        $this->info('Processed '.$images->process($limit).' product image requests.');
        return 0;
    }
}
