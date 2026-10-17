<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTakeawayPos extends Migration
{
    public function up(): void
    {
        Schema::create('takeaway_tills', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->string('branch', 40)->unique();
            // A new POS register starts at zero; an audited cash-in records its opening cash.
            $t->bigInteger('balance_cents')->default(0);
            $t->unsignedInteger('tax_bps')->default(0);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('takeaway_orders', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('till_id')->index();
            $t->string('branch', 40)->index();
            $t->unsignedBigInteger('actor_id')->index();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->char('quote_hash', 64);
            $t->date('business_date')->index();
            $t->string('payment_method', 20);
            $t->string('payment_reference', 150)->nullable();
            $t->boolean('payment_confirmed')->default(true);
            foreach (['subtotal', 'discount', 'tax', 'total', 'cash_received', 'change'] as $name) $t->unsignedBigInteger($name.'_cents');
            $t->unsignedInteger('tax_bps');
            $t->string('discount_reason', 500)->nullable();
            $t->text('notes')->nullable();
            $t->text('branch_snapshot');
            $t->text('cashier_snapshot');
            $t->timestamps();
            $t->unique(['branch', 'actor_id', 'request_key'], 'takeaway_order_request_unique');
            $t->index(['branch', 'business_date']);
            $t->foreign('till_id')->references('id')->on('takeaway_tills')->onDelete('restrict');
        });
        Schema::create('takeaway_order_items', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('order_id')->index();
            $t->unsignedBigInteger('product_id');
            $t->string('name', 255);
            $t->string('option_id', 80)->nullable();
            $t->string('option_label', 255)->nullable();
            $t->string('unit', 40)->nullable();
            $t->string('quantity_mode', 10);
            $t->unsignedInteger('quantity_millis');
            $t->unsignedBigInteger('unit_price_cents');
            $t->unsignedBigInteger('total_cents');
            $t->foreign('order_id')->references('id')->on('takeaway_orders')->onDelete('restrict');
        });
        Schema::create('takeaway_till_entries', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->unsignedBigInteger('till_id')->index();
            $t->string('branch', 40)->index();
            $t->unsignedBigInteger('actor_id');
            $t->unsignedBigInteger('order_id')->nullable()->unique();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->string('kind', 20);
            $t->bigInteger('amount_cents');
            $t->bigInteger('balance_cents');
            $t->date('business_date');
            $t->string('note', 500);
            $t->text('metadata')->nullable();
            $t->timestamps();
            $t->unique(['till_id', 'actor_id', 'request_key'], 'takeaway_entry_request_unique');
            $t->index(['branch', 'business_date']);
            $t->foreign('till_id')->references('id')->on('takeaway_tills')->onDelete('restrict');
            $t->foreign('order_id')->references('id')->on('takeaway_orders')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('takeaway_till_entries');
        Schema::dropIfExists('takeaway_order_items');
        Schema::dropIfExists('takeaway_orders');
        Schema::dropIfExists('takeaway_tills');
    }
}
