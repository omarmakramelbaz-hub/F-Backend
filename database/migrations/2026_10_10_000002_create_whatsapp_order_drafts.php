<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWhatsAppOrderDrafts extends Migration
{
    public function up()
    {
        Schema::create('whatsapp_order_scans', function (Blueprint $t) {
            $t->unsignedBigInteger('conversation_id')->primary();
            $t->unsignedBigInteger('analyzed_ceiling')->default(0);
            $t->unsignedBigInteger('claimed_ceiling')->default(0);
            $t->unsignedBigInteger('auto_floor')->nullable();
            $t->char('claim_nonce', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamps();
            $t->foreign('conversation_id', 'wa_order_scan_thread_fk')->references('id')->on('whatsapp_inbox_conversations')->onDelete('restrict');
        });
        Schema::create('whatsapp_order_drafts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('evidence_floor')->default(0);
            $t->unsignedBigInteger('evidence_ceiling');
            $t->char('evidence_hash', 64);
            $t->char('confirmation_key', 64)->nullable()->unique('wa_order_confirmation_unique');
            $t->string('status', 24);
            $t->string('reason', 40)->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->longText('extraction')->nullable();
            $t->longText('sealed_payload')->nullable();
            $t->uuid('customer_command_key');
            $t->uuid('ticket_command_key');
            $t->unsignedBigInteger('ticket_id')->nullable();
            $t->unsignedBigInteger('dispatch_actor_id')->nullable();
            $t->string('assigned_branch', 32)->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamps();
            $t->foreign('conversation_id', 'wa_order_draft_thread_fk')->references('id')->on('whatsapp_inbox_conversations')->onDelete('restrict');
            $t->unique(['conversation_id', 'evidence_ceiling'], 'wa_order_draft_ceiling_unique');
            $t->index(['status', 'id'], 'wa_order_draft_pending');
        });
    }

    public function down()
    {
        Schema::dropIfExists('whatsapp_order_drafts');
        Schema::dropIfExists('whatsapp_order_scans');
    }
}
