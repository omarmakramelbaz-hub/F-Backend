<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCommissionAmountToDelegateNotifications extends Migration
{
    public function up()
    {
        Schema::table('delegate_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('delegate_notifications', 'commission_amount')) {
                $table->decimal('commission_amount', 10, 2)->nullable()->after('offer_price');
            }
        });
    }

    public function down()
    {
        Schema::table('delegate_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('delegate_notifications', 'commission_amount')) {
                $table->dropColumn('commission_amount');
            }
        });
    }
}
