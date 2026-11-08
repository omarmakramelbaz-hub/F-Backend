<?php

namespace App\Console\Commands;

use App\Support\PosOnlyProducts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only: the staff-only items (PosOnlyProducts) the customer app and the website no longer offer. */
class ListPosOnlyProducts extends Command
{
    protected $signature = 'products:pos-only';
    protected $description = 'Read-only: the items sold only by the staff (hidden from the customer app and the website).';

    public function handle(): int
    {
        $ids = PosOnlyProducts::ids();
        $this->line('GO_ERP_POS_ONLY_PRODUCTS '.count($ids));
        if (!$ids) return 0;
        $name = Schema::hasColumn('resturant_products', 'product_name') ? 'product_name' : 'name_ar';
        $branches = DB::table('resturants')->pluck('name', 'id');
        $categories = Schema::hasTable('categories') && Schema::hasColumn('categories', 'name_ar') ? DB::table('categories')->pluck('name_ar', 'id') : collect();
        foreach (DB::table('resturant_products')->whereIn('id', $ids)->orderBy('resturant_id')->orderBy('id')->get() as $row) {
            $this->line('  #'.$row->id.' '.($row->{$name} ?? '').' | price '.($row->product_price ?? '?').' | '.($row->status ?? '?')
                .' | category '.($categories[$row->category_id ?? 0] ?? '-').' | branch #'.($row->resturant_id ?? '?').' '.($branches[$row->resturant_id ?? 0] ?? ''));
        }
        return 0;
    }
}
