<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateCrmWhatsappMessaging extends Migration
{
    public function up()
    {
        Schema::create('crm_whatsapp_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->boolean('feedback_enabled')->default(false);
            $table->string('feedback_template_name', 512)->default('');
            $table->string('feedback_language', 20)->default('ar');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();
        });
        DB::table('crm_whatsapp_settings')->insert(['id' => 1, 'enabled' => false, 'feedback_enabled' => false,
            'feedback_template_name' => '', 'feedback_language' => 'ar']);
        Schema::create('crm_whatsapp_campaigns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('actor_id');
            $table->char('request_hash', 64)->unique();
            $table->char('payload_hash', 64);
            $table->string('template_name', 512);
            $table->string('language', 20);
            $table->longText('parameters'); // Encrypted body parameter values.
            $table->char('template_hash', 64);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('crm_whatsapp_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('purchase_id')->nullable()->unique();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('state', 16)->default('queued');
            $table->string('provider_message_id', 512)->nullable()->index('crm_wa_provider_message');
            $table->string('delivery_status', 20)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 50)->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'customer_id'], 'crm_wa_campaign_customer');
            $table->index(['state', 'next_attempt_at', 'id'], 'crm_wa_dispatch_due');
        });
        Schema::create('crm_whatsapp_dispatch_gate', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('window_started_at')->nullable();
            $table->unsignedInteger('window_count')->default(0);
            $table->timestamp('blocked_until')->nullable();
        });
        DB::table('crm_whatsapp_dispatch_gate')->insert(['id' => 1, 'window_count' => 0]);
        Schema::create('crm_customer_feedback_replies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('delivery_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('inbox_message_id')->nullable()->index();
            $table->string('provider_message_id', 512)->unique('crm_feedback_reply_message_unique');
            $table->longText('body'); // Encrypted with Laravel Crypt, as in the existing inbox.
            $table->timestamp('replied_at');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_customer_feedback_replies');
        Schema::dropIfExists('crm_whatsapp_dispatch_gate');
        Schema::dropIfExists('crm_whatsapp_deliveries');
        Schema::dropIfExists('crm_whatsapp_campaigns');
        Schema::dropIfExists('crm_whatsapp_settings');
    }
}
