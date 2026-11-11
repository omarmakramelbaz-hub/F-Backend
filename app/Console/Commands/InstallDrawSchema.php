<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns and table of «مسابقة السحب» by products (App\Services\Draws\ProductDraw). Safe to run again: it only adds what is
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
        if (!Schema::hasTable('coupon_wheel_products')) {
            // The products whose pieces are entries in a draw by products (several per draw).
            Schema::create('coupon_wheel_products', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('coupon_wheel_id');
                $t->unsignedBigInteger('product_id');
                $t->timestamps();
                $t->unique(['coupon_wheel_id', 'product_id'], 'coupon_wheel_products_unique');
                $t->index('product_id');
            });
            $added[] = 'coupon_wheel_products';
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
