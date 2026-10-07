<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAudienceToDashboardPushCampaigns extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('dashboard_push_campaigns', 'audience')) {
            Schema::table('dashboard_push_campaigns', function (Blueprint $t) { $t->text('audience')->nullable(); });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('dashboard_push_campaigns', 'audience')) {
            Schema::table('dashboard_push_campaigns', function (Blueprint $t) { $t->dropColumn('audience'); });
        }
    }
}
