<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePartnerServiceRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('partner_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('partner_id')->constrained('users')->onDelete('cascade');
            $table->string('profession_key', 80)->index();
            $table->text('description');
            $table->string('customer_phone', 30)->nullable();
            $table->decimal('customer_lat', 10, 7);
            $table->decimal('customer_lng', 10, 7);
            $table->string('address')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('partner_service_requests');
    }
}
