<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmCustomerDirectory extends Migration
{
    public function up()
    {
        Schema::create('crm_customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('phone', 20)->unique();
            $table->string('phone_key', 20)->index();
            $table->string('name', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('area', 150)->nullable();
            $table->string('delivery_notes', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // An order or an incoming message alone never grants messaging consent.
            $table->boolean('service_consent')->default(false);
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('service_consented_at')->nullable();
            $table->timestamp('marketing_consented_at')->nullable();
            $table->string('service_consent_evidence', 500)->nullable();
            $table->string('marketing_consent_evidence', 500)->nullable();
            $table->unsignedBigInteger('service_consented_by')->nullable();
            $table->unsignedBigInteger('marketing_consented_by')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
        Schema::create('crm_customer_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id');
            $table->string('branch', 30);
            $table->string('channel', 20);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['customer_id', 'branch', 'channel'], 'crm_customer_source_unique');
            $table->index(['branch', 'channel', 'customer_id'], 'crm_source_branch_channel');
            $table->foreign('customer_id')->references('id')->on('crm_customers')->onDelete('cascade');
        });
        Schema::create('crm_purchases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id');
            $table->string('branch', 30);
            $table->string('channel', 20);
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            $table->timestamp('paid_at');
            $table->timestamp('feedback_due_at');
            $table->string('feedback_status', 24)->default('pending');
            $table->timestamp('feedback_sent_at')->nullable();
            $table->string('message_id', 512)->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'crm_purchase_source_unique');
            $table->index(['feedback_status', 'feedback_due_at', 'id'], 'crm_feedback_due');
            $table->index(['customer_id', 'paid_at'], 'crm_customer_purchases');
            $table->foreign('customer_id')->references('id')->on('crm_customers')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_purchases');
        Schema::dropIfExists('crm_customer_sources');
        Schema::dropIfExists('crm_customers');
    }
}
