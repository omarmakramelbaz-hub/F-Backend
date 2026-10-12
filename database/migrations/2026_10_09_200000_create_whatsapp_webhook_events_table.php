<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWhatsAppWebhookEventsTable extends Migration
{
    public function up()
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('payload_hash', 64)->unique();
            $table->longText('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
}
