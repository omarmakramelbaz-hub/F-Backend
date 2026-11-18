<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetaInboxReads extends Migration
{
    public function up()
    {
        Schema::create('meta_inbox_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('seen_message_id')->default(0);
            $table->timestamps();
            $table->primary(['actor_id', 'conversation_id'], 'meta_inbox_read_actor_thread_primary');
            $table->foreign('conversation_id', 'meta_inbox_read_thread_fk')->references('id')
                ->on('meta_inbox_conversations')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_inbox_reads');
    }
}
