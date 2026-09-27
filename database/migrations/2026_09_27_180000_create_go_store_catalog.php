<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGoStoreCatalog extends Migration
{
    public function up()
    {
        Schema::create('go_stores', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('name', 150);
            $table->string('kind', 20);
            $table->string('address', 500);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('go_store_products', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('request_key');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('unit', 40);
            $table->unsignedBigInteger('price_cents');
            $table->string('image_path');
            $table->boolean('available')->default(true);
            $table->json('options');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('go_store_products');
        Schema::dropIfExists('go_stores');
    }
}
