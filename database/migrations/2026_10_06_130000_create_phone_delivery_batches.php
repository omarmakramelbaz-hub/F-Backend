<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePhoneDeliveryBatches extends Migration
{
    public function up(): void
    {
        // Courier handoff is operational metadata, separate from an issued, immutable bill.
        Schema::create('phone_delivery_dispatches', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('ticket_id')->unique(); $t->string('branch', 30);
            $t->unsignedBigInteger('company_id'); $t->text('company_snapshot');
            $t->unsignedBigInteger('actor_id'); $t->timestamps(); $t->index(['branch', 'company_id']);
        });
        Schema::create('phone_delivery_batches', function (Blueprint $t) {
            $t->id(); $t->string('branch', 30); $t->unsignedBigInteger('actor_id');
            $t->uuid('request_key'); $t->bigInteger('total_cents'); $t->longText('snapshot'); $t->timestamps();
            $t->unique(['branch', 'actor_id', 'request_key'], 'phone_batch_request_unique');
            $t->index(['branch', 'created_at']);
        });
        Schema::create('phone_delivery_batch_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('batch_id')->index(); $t->unsignedBigInteger('ticket_id')->unique();
            $t->unsignedBigInteger('order_id'); $t->bigInteger('total_cents');
        });
    }
    public function down(): void
    {
        foreach (['phone_delivery_batch_items', 'phone_delivery_batches', 'phone_delivery_dispatches'] as $table) Schema::dropIfExists($table);
    }
}
