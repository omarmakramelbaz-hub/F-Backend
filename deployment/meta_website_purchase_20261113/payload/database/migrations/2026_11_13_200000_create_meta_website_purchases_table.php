<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMetaWebsitePurchasesTable extends Migration
{
    public function up(): void
    {
        Schema::create('meta_website_purchases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ticket_id')->unique();
            $table->string('event_id', 96)->unique();
            $table->string('dataset_id', 32);
            $table->unsignedBigInteger('event_time');
            $table->longText('payload'); // Encrypted with Laravel's existing application key.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->timestamps();
            $table->index(['sent_at', 'failed_at', 'next_attempt_at'], 'meta_web_purchase_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_website_purchases');
    }
}
