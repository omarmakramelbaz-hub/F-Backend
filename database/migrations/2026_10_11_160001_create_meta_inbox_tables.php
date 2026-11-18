<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetaInboxTables extends Migration
{
    public function up()
    {
        Schema::create('meta_inbox_conversations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('waba_id', 32);
            $table->string('phone_number_id', 32);
            $table->char('peer_hash', 64);
            $table->longText('customer'); // Laravel-encrypted JSON; never a searchable phone number.
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['waba_id', 'phone_number_id', 'peer_hash'], 'meta_inbox_peer_unique');
            $table->index(['last_message_at', 'id'], 'meta_inbox_recent');
        });

        Schema::create('meta_inbox_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->char('message_key', 64);
            $table->string('type', 40);
            $table->string('direction', 16);
            $table->string('source', 32);
            $table->longText('content'); // The complete normalized DTO is encrypted.
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique('message_key', 'meta_inbox_message_unique');
            $table->foreign('conversation_id', 'meta_inbox_message_thread_fk')
                ->references('id')->on('meta_inbox_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'id'], 'meta_inbox_thread_messages');
            $table->index(['conversation_id', 'sent_at', 'id'], 'meta_inbox_thread_recent');
        });

        Schema::create('meta_inbox_ingestion_failures', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->primary();
            $table->string('reason', 32); // Fixed codes only; never exception/customer content.
            $table->unsignedInteger('attempts');
            $table->timestamp('last_attempted_at');
            $table->foreign('event_id', 'meta_inbox_failure_event_fk')
                ->references('id')->on('meta_webhook_events')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_inbox_ingestion_failures');
        Schema::dropIfExists('meta_inbox_messages');
        Schema::dropIfExists('meta_inbox_conversations');
    }
}
