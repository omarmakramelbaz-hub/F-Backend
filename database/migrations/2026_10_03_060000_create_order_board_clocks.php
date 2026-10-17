<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderBoardClocks extends Migration
{
    public function up(): void
    {
        // Deliberately no historical backfill: only acceptances observed after
        // this release start the timers; existing live orders stay untouched.
        if (Schema::hasTable('order_board_clocks')) return;
        Schema::create('order_board_clocks', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->unsignedBigInteger('order_id');
            $table->dateTime('accepted_at');
            $table->dateTime('courier_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'order_id']);
            $table->index(['closed_at', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_board_clocks');
    }
}
