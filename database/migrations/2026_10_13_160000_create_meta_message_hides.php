<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetaMessageHides extends Migration
{
    public function up()
    {
        Schema::create('meta_message_hides', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->char('message_key', 64); // Immutable source identifier, no customer content.
            $table->timestamp('hidden_at');
            $table->primary(['actor_id', 'message_id'], 'meta_message_hide_actor_message_primary');
            $table->foreign('message_id', 'meta_message_hide_message_fk')->references('id')
                ->on('meta_inbox_messages')->onDelete('restrict');
            $table->foreign('conversation_id', 'meta_message_hide_thread_fk')->references('id')
                ->on('meta_inbox_conversations')->onDelete('restrict');
        });
    }

    public function down() { Schema::dropIfExists('meta_message_hides'); }
}
