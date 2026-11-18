<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns and table of «مسابقة السحب» by items (App\Services\Draws\ProductDraw). Safe to run again: it only adds what is
 * missing, never changes or removes data. Existing draws keep their rule (an order of at least the draw's amount).
 */
class InstallDrawSchema extends Command
{
    protected $signature = 'draws:install-schema';
    protected $description = 'Add the draw policy columns (product draws) when missing';

    public function handle(): int
    {
        $added = [];
        if (!Schema::hasColumn('coupon_wheels', 'policy')) {
            Schema::table('coupon_wheels', fn (Blueprint $t) => $t->string('policy', 20)->nullable()->after('status'));
            $added[] = 'coupon_wheels.policy';
        }
        if (!Schema::hasTable('coupon_wheel_items')) {
            // The branch items (by name) whose pieces are entries in a draw by items: the same item is on each branch's
            // menu under its name. item_key is the name as matched (App\Services\Draws\ProductDraw::key).
            Schema::create('coupon_wheel_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('coupon_wheel_id');
                $t->string('item_key', 190);
                $t->string('item_name', 190);
                $t->timestamps();
                $t->unique(['coupon_wheel_id', 'item_key'], 'coupon_wheel_items_unique');
            });
            $added[] = 'coupon_wheel_items';
            // The draws saved with catalog products keep them, by those products' names.
            if (Schema::hasTable('coupon_wheel_products') && Schema::hasTable('products')) {
                foreach (\Illuminate\Support\Facades\DB::table('coupon_wheel_products as c')->join('products as p', 'p.id', '=', 'c.product_id')
                    ->get(['c.coupon_wheel_id', 'p.name_ar']) as $row) {
                    $name = trim((string) $row->name_ar);
                    if ($name === '') continue;
                    \Illuminate\Support\Facades\DB::table('coupon_wheel_items')->insertOrIgnore(['coupon_wheel_id'=>$row->coupon_wheel_id,
                        'item_key'=>\App\Services\Draws\ProductDraw::key($name), 'item_name'=>$name, 'created_at'=>now(), 'updated_at'=>now()]);
                }
            }
        }
        if (!Schema::hasColumn('coupon_subscripes', 'order_id')) {
            Schema::table('coupon_subscripes', fn (Blueprint $t) => $t->unsignedBigInteger('order_id')->nullable()->after('coupon_wheel_id'));
            $added[] = 'coupon_subscripes.order_id';
        }
        if (!Schema::hasColumn('coupon_subscripes', 'entry_key')) {
            Schema::table('coupon_subscripes', function (Blueprint $t) {
                $t->string('entry_key', 120)->nullable()->after('order_id');
                $t->unique('entry_key', 'coupon_subscripes_entry_key_unique');
            });
            $added[] = 'coupon_subscripes.entry_key';
        }
        $this->line(json_encode(['result'=>'DRAW_SCHEMA_READY', 'added'=>$added], JSON_UNESCAPED_UNICODE));
        return 0;
    }
}
