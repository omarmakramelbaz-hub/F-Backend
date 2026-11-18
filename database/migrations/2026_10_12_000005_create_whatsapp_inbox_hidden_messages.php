<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWhatsAppInboxHiddenMessages extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('whatsapp_inbox_hidden_messages')) Schema::create('whatsapp_inbox_hidden_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('reply_id')->nullable();
            $table->string('item_key', 32);
            $table->char('native_message_key', 64)->nullable();
            $table->unsignedBigInteger('message_cutoff')->nullable();
            $table->unsignedBigInteger('projection_cutoff')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'conversation_id', 'item_key'], 'wa_hidden_actor_item');
            $table->index(['actor_id', 'conversation_id'], 'wa_hidden_actor_thread');
            $table->index(['actor_id', 'conversation_id', 'native_message_key'], 'wa_hidden_actor_native');
            $table->foreign('message_id', 'wa_hidden_message_fk')->references('id')->on('whatsapp_inbox_messages')->onDelete('restrict');
            $table->foreign('conversation_id', 'wa_hidden_thread_fk')->references('id')->on('whatsapp_inbox_conversations')->onDelete('restrict');
            $table->foreign('reply_id', 'wa_hidden_reply_fk')->references('id')->on('whatsapp_reply_requests')->onDelete('restrict');
        });
        if (!Schema::hasTable('whatsapp_reply_projection_keys')) Schema::create('whatsapp_reply_projection_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('reply_id')->unique();
            $table->unsignedBigInteger('conversation_id');
            $table->string('waba_id', 32); $table->string('phone_number_id', 32);
            $table->string('kind', 16);
            $table->char('message_key', 64)->nullable();
            $table->char('receipt_hash', 64);
            $table->boolean('valid');
            $table->timestamps();
            $table->unique(['waba_id', 'phone_number_id', 'message_key'], 'wa_projection_native_unique');
            $table->index(['conversation_id', 'reply_id'], 'wa_projection_thread_reply');
            $table->foreign('reply_id', 'wa_projection_reply_fk')->references('id')->on('whatsapp_reply_requests')->onDelete('restrict');
            $table->foreign('conversation_id', 'wa_projection_thread_fk')->references('id')->on('whatsapp_inbox_conversations')->onDelete('restrict');
        });
        if (!Schema::hasTable('whatsapp_reply_reaction_targets')) Schema::create('whatsapp_reply_reaction_targets', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->unsignedBigInteger('reply_id')->primary();
            $table->unsignedBigInteger('conversation_id');
            $table->string('waba_id', 32); $table->string('phone_number_id', 32);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('target_reply_id')->nullable();
            $table->char('message_key', 64)->nullable();
            $table->boolean('valid');
            $table->string('emoji', 32);
            $table->timestamp('accepted_at');
            $table->char('receipt_hash', 64);
            $table->timestamps();
            $table->index(['conversation_id', 'message_key', 'accepted_at', 'reply_id'], 'wa_reaction_target_latest');
            $table->foreign('reply_id', 'wa_reaction_request_fk')->references('id')->on('whatsapp_reply_requests')->onDelete('restrict');
            $table->foreign('conversation_id', 'wa_reaction_thread_fk')->references('id')->on('whatsapp_inbox_conversations')->onDelete('restrict');
        });
    }
    public function down() { Schema::dropIfExists('whatsapp_reply_reaction_targets'); Schema::dropIfExists('whatsapp_reply_projection_keys'); Schema::dropIfExists('whatsapp_inbox_hidden_messages'); }
}
