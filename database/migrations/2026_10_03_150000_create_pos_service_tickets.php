<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePosServiceTickets extends Migration
{
    public function up(): void
    {
        Schema::create('pos_service_settings', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->id(); $t->string('branch',40)->unique();
            $t->unsignedInteger('service_bps')->default(0); $t->unsignedInteger('revision')->default(1); $t->timestamps();
        });
        Schema::create('pos_service_tables', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->id(); $t->string('branch',40)->index(); $t->string('name',100);
            $t->unsignedInteger('capacity'); $t->boolean('active')->default(true); $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('active_ticket_id')->nullable()->unique(); $t->timestamps();
            $t->unique(['branch','name']);
        });
        Schema::create('pos_service_tickets', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->id(); $t->string('branch',40)->index(); $t->string('channel',10);
            $t->unsignedBigInteger('actor_id'); $t->unsignedBigInteger('table_id')->nullable(); $t->text('table_snapshot')->nullable();
            $t->string('status',30); $t->string('payment_status',10)->default('unpaid'); $t->unsignedInteger('revision')->default(1);
            $t->string('waiter_name',100)->nullable(); $t->unsignedInteger('guest_count')->nullable();
            $t->string('customer_name',100)->nullable(); $t->string('customer_phone',30)->nullable(); $t->string('phone_key',30)->nullable()->index();
            $t->string('address',500)->nullable(); $t->string('area',150)->nullable(); $t->string('delivery_notes',500)->nullable();
            $t->unsignedBigInteger('delivery_cents')->default(0); $t->string('notes',500)->nullable(); $t->string('cancel_reason',500)->nullable();
            $t->mediumText('cart_snapshot'); $t->mediumText('quote_snapshot'); $t->unsignedBigInteger('paid_order_id')->nullable()->unique();
            $t->unsignedBigInteger('last_kitchen_id')->nullable(); $t->date('business_date'); $t->timestamps();
            $t->index(['branch','channel','status']); $t->index(['branch','phone_key']);
            $t->foreign('table_id')->references('id')->on('pos_service_tables')->onDelete('restrict');
            $t->foreign('paid_order_id')->references('id')->on('takeaway_orders')->onDelete('restrict');
        });
        Schema::create('pos_service_commands', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->id(); $t->string('branch',40); $t->unsignedBigInteger('actor_id');
            $t->uuid('request_key'); $t->char('request_hash',64); $t->string('kind',30);
            $t->unsignedBigInteger('ticket_id')->nullable(); $t->unsignedBigInteger('table_id')->nullable(); $t->unsignedInteger('revision')->nullable();
            $t->text('metadata')->nullable(); $t->timestamps(); $t->unique(['branch','actor_id','request_key']);
            $t->foreign('ticket_id')->references('id')->on('pos_service_tickets')->onDelete('restrict');
            $t->foreign('table_id')->references('id')->on('pos_service_tables')->onDelete('restrict');
        });
        Schema::create('pos_service_kitchen_tickets', function (Blueprint $t) {
            $t->engine='InnoDB'; $t->id(); $t->string('branch',40)->index(); $t->unsignedBigInteger('ticket_id');
            $t->unsignedInteger('revision'); $t->unsignedBigInteger('actor_id'); $t->mediumText('snapshot'); $t->timestamps();
            $t->foreign('ticket_id')->references('id')->on('pos_service_tickets')->onDelete('restrict');
            $t->unique(['ticket_id','revision']);
        });
        Schema::table('takeaway_orders', function (Blueprint $t) {
            $t->string('channel',10)->default('takeaway'); $t->unsignedBigInteger('ticket_id')->nullable()->unique();
            $t->unsignedInteger('service_bps')->default(0); $t->unsignedBigInteger('service_cents')->default(0);
            $t->unsignedBigInteger('delivery_cents')->default(0); $t->text('context_snapshot')->nullable(); $t->text('tenders_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('takeaway_orders',function(Blueprint $t){$t->dropUnique(['ticket_id']);$t->dropColumn(['channel','ticket_id','service_bps','service_cents','delivery_cents','context_snapshot','tenders_snapshot']);});
        foreach (['pos_service_kitchen_tickets','pos_service_commands','pos_service_tickets','pos_service_tables','pos_service_settings'] as $table) Schema::dropIfExists($table);
    }
}
