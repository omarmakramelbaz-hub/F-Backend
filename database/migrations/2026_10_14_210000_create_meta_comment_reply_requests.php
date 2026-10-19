<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetaCommentReplyRequests extends Migration
{
    public function up()
    {
        Schema::create('meta_comment_reply_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('actor_id');
            $table->char('account_key', 64);
            $table->char('target_hash', 64);
            $table->char('request_hash', 64)->unique('meta_comment_request_unique');
            $table->char('payload_hash', 64);
            // Retained only while delivery is uncertain; prevents a new UUID bypassing a timeout.
            $table->char('pending_key', 64)->nullable()->unique('meta_comment_pending_unique');
            $table->string('state', 16);
            $table->string('reason', 40)->nullable();
            $table->longText('response_details')->nullable(); // Encrypted accepted receipt only.
            $table->timestamp('send_started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'account_key', 'target_hash'], 'meta_comment_target_lookup');
        });
    }

    public function down()
    {
        Schema::dropIfExists('meta_comment_reply_requests');
    }
}
