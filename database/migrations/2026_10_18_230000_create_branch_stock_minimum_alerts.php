<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchStockMinimumAlerts extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_stock_minimums')) Schema::create('branch_stock_minimums', function (Blueprint $t) {
            $t->id();
            $t->string('branch', 30);
            $t->string('stock_type', 16);
            $t->unsignedBigInteger('stock_item_id');
            $t->string('unit', 10);
            $t->unsignedBigInteger('minimum_units')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('updated_by');
            $t->boolean('active_low')->default(false);
            $t->unsignedBigInteger('episode')->default(0);
            $t->timestamps();
            $t->unique(['branch', 'stock_type', 'stock_item_id'], 'stock_minimum_item_unique');
        });
        if (!Schema::hasTable('branch_stock_minimum_alerts')) Schema::create('branch_stock_minimum_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('minimum_id');
            $t->unsignedBigInteger('episode');
            $t->string('branch_name');
            $t->string('item_name');
            $t->string('unit', 10);
            $t->bigInteger('balance_units');
            $t->unsignedBigInteger('minimum_units');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('lease_token', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['minimum_id', 'episode'], 'stock_minimum_episode_unique');
            $t->index(['sent_at', 'cancelled_at', 'next_attempt_at'], 'stock_minimum_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_stock_minimum_alerts');
        Schema::dropIfExists('branch_stock_minimums');
    }
}
