<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWebStorefrontOrders extends Migration
{
    public function up()
    {
        if (Schema::hasTable('web_storefront_orders')) return;
        Schema::create('web_storefront_orders',function(Blueprint $table) {
            $table->id();$table->string('session_hash',64);$table->uuid('request_key');
            $table->string('payload_hash',64)->unique();$table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('ticket_id')->unique();$table->timestamp('created_at');
            $table->unique(['session_hash','request_key'],'web_storefront_request_unique');
        });
    }
    public function down() {Schema::dropIfExists('web_storefront_orders');}
}
