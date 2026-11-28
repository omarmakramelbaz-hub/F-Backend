<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Emoji reactions on Messenger/Instagram messages, and which earlier message a dashboard reply quoted. */
class CreateMetaInboxReactions extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('meta_inbox_reactions')) Schema::create('meta_inbox_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->char('message_key', 64);
            $table->string('direction', 8);
            $table->string('emoji', 32)->nullable();
            $table->timestamp('reacted_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'message_key', 'direction'], 'meta_inbox_reaction_unique');
            $table->foreign('conversation_id', 'meta_inbox_reaction_thread_fk')->references('id')
                ->on('meta_inbox_conversations')->onDelete('cascade');
        });
        if (!Schema::hasTable('meta_inbox_reply_links')) Schema::create('meta_inbox_reply_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->char('message_key', 64);
            $table->char('quoted_key', 64);
            $table->timestamps();
            $table->unique(['conversation_id', 'message_key'], 'meta_inbox_reply_link_unique');
            $table->foreign('conversation_id', 'meta_inbox_reply_link_thread_fk')->references('id')
                ->on('meta_inbox_conversations')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_inbox_reply_links');
        Schema::dropIfExists('meta_inbox_reactions');
    }
}
