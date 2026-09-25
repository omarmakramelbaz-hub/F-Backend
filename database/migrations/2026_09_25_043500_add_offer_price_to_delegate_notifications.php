<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOfferPriceToDelegateNotifications extends Migration
{
    public function up()
    {
        Schema::table('delegate_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('delegate_notifications', 'offer_price')) {
                $table->decimal('offer_price', 10, 2)->nullable()->after('status');
            }
        });
    }

    public function down()
    {
        Schema::table('delegate_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('delegate_notifications', 'offer_price')) {
                $table->dropColumn('offer_price');
            }
        });
    }
}
