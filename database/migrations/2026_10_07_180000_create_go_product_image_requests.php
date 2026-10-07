<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoProductImageRequests extends Migration
{
    public function up()
    {
        Schema::create('go_product_image_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('application_id')->index();
            $table->uuid('request_key')->unique();
            $table->unsignedBigInteger('product_id')->nullable()->unique();
            $table->string('store_kind', 20);
            $table->json('product_data');
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('image_path')->default('');
            $table->json('provenance')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('go_product_image_requests');
    }
}
