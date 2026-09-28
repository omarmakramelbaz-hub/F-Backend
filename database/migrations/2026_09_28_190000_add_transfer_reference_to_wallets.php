<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTransferReferenceToWallets extends Migration
{
    public function up()
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->uuid('transfer_reference')->nullable()->unique();
        });
    }

    public function down()
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['transfer_reference']);
            $table->dropColumn('transfer_reference');
        });
    }
}
