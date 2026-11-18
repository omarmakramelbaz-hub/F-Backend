<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmWhatsappStatusReceipts extends Migration
{
    public function up()
    {
        Schema::create('crm_whatsapp_status_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider_message_id', 512)->unique('crm_wa_status_message_unique');
            $table->char('recipient_hash', 64)->nullable();
            $table->string('delivery_status', 20);
            $table->timestamp('status_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_whatsapp_status_receipts');
    }
}
