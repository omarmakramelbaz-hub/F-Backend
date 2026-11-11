<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns of «مسابقة السحب» by product (App\Services\Draws\ProductDraw). Safe to run again: it only adds what is
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
        if (!Schema::hasColumn('coupon_wheels', 'product_id')) {
            Schema::table('coupon_wheels', fn (Blueprint $t) => $t->unsignedBigInteger('product_id')->nullable()->after('policy'));
            $added[] = 'coupon_wheels.product_id';
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
