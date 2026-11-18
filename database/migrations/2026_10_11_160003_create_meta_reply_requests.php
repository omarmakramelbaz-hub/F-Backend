<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetaReplyRequests extends Migration
{
    public function up()
    {
        Schema::create('meta_reply_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('waba_id', 32);
            $table->string('phone_number_id', 32);
            $table->char('request_hash', 64)->unique('meta_reply_request_unique');
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('inbound_message_id');
            $table->string('kind', 16);
            $table->string('state', 16);
            $table->longText('audit'); // Encrypted UUID, text and recipient; never searchable PII.
            $table->longText('response_details')->nullable(); // Encrypted accepted Graph identifier.
            $table->char('remote_message_key', 64)->nullable();
            $table->string('reason', 40)->nullable(); // Fixed, private error codes only.
            $table->timestamp('claimed_at');
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('conversation_id', 'meta_reply_thread_fk')->references('id')
                ->on('meta_inbox_conversations')->onDelete('restrict');
            $table->index(['conversation_id', 'state'], 'meta_reply_unresolved');
            $table->index(['conversation_id', 'id'], 'meta_reply_recent');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_reply_requests');
    }
}
