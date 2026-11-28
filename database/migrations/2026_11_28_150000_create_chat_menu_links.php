<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A personal menu link sent in a WhatsApp/Messenger/Instagram chat; the customer picks products and the summary returns to that chat. */
class CreateChatMenuLinks extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('chat_menu_links')) Schema::create('chat_menu_links', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('channel', 10);
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->index(['channel', 'conversation_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('chat_menu_links');
    }
}
