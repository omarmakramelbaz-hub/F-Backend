<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoStoreOrders extends Migration
{
    public function up()
    {
        Schema::create('go_store_orders', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->bigIncrements('id');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('store_id');
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->json('snapshot');
            $t->string('fulfillment', 20);
            $t->string('status', 24);
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('delivery_cents');
            $t->unsignedBigInteger('total_cents');
            $t->unsignedInteger('commission_bps');
            $t->unsignedBigInteger('commission_cents');
            $t->string('payment_method', 24);
            $t->string('payment_status', 24);
            $t->uuid('payment_reference')->unique();
            $t->string('gateway_order_id')->nullable()->unique();
            $t->unsignedInteger('integration_id')->nullable();
            $t->boolean('is_live')->nullable();
            $t->text('checkout_secret')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'request_key']);
            $t->index(['customer_id', 'id']);
            $t->index(['store_id', 'status', 'id']);
        });
        Schema::create('go_store_payment_receipts', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->index();
            $t->string('transaction_id', 30)->unique();
            $t->unsignedBigInteger('amount_cents');
            $t->string('status', 24);
            $t->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('go_store_payment_receipts');
        Schema::dropIfExists('go_store_orders');
    }
}
