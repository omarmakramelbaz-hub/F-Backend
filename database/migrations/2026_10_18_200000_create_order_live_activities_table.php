<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderLiveActivitiesTable extends Migration
{
    public function up()
    {
        Schema::create('order_live_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');
            $table->char('activity_id_hash', 64);
            // Push tokens are encrypted with the application key; never returned to clients.
            $table->text('activity_token');
            $table->text('fcm_token');
            $table->char('token_hash', 64);
            $table->char('fcm_token_hash', 64);
            $table->char('state_hash', 64)->nullable();
            $table->unsignedBigInteger('last_timestamp')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['order_id', 'user_id', 'activity_id_hash'], 'order_live_activity_identity');
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_live_activities');
    }
}
