<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderActivitySubscriptionsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('order_activity_subscriptions')) {
            $required = ['id','user_id','order_id','activity_id','token_hash','activity_token','fcm_token','last_status','last_sent_at','expires_at','ended_at','created_at','updated_at'];
            if (array_diff($required, Schema::getColumnListing('order_activity_subscriptions'))) {
                throw new \RuntimeException('Existing order activity subscription schema is incompatible');
            }
            return;
        }
        Schema::create('order_activity_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id');
            $table->string('activity_id', 255);
            $table->char('token_hash', 64)->unique();
            $table->text('activity_token');
            $table->text('fcm_token');
            $table->string('last_status', 60)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'user_id']);
            $table->index(['ended_at', 'expires_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_activity_subscriptions');
    }
}
