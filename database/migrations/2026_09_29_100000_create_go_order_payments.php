<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoOrderPayments extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('go_order_payments')) Schema::create('go_order_payments', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('order_id')->unique();
            $t->unsignedBigInteger('customer_id'); $t->unsignedBigInteger('partner_id')->nullable();
            $t->uuid('reference')->unique(); $t->string('method',24); $t->string('status',32);
            $t->unsignedBigInteger('amount_cents')->default(0); $t->unsignedBigInteger('integration_id')->nullable();
            $t->boolean('is_live')->default(false); $t->string('gateway_order_id',64)->nullable()->unique();
            $t->text('checkout_secret')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        if (!Schema::hasTable('go_order_payment_receipts')) Schema::create('go_order_payment_receipts', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('transaction_id',64)->unique(); $t->unsignedBigInteger('payment_id')->index();
            $t->unsignedBigInteger('amount_cents'); $t->string('status',32); $t->timestamps();
        });
    }
    public function down() { Schema::dropIfExists('go_order_payment_receipts'); Schema::dropIfExists('go_order_payments'); }
}
