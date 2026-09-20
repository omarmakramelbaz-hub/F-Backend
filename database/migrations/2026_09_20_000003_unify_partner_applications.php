<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UnifyPartnerApplications extends Migration
{
    public function up()
    {
        Schema::table('pending_vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('pending_vendors', 'source_app')) {
                $table->string('source_app', 32)->default('fasakhansta')->index();
            }
            if (!Schema::hasColumn('pending_vendors', 'partner_type')) {
                $table->string('partner_type', 50)->nullable()->index();
            }
            if (!Schema::hasColumn('pending_vendors', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('pending_vendors', function (Blueprint $table) {
            foreach (['source_app','partner_type','reviewed_at'] as $column) {
                if (Schema::hasColumn('pending_vendors', $column)) $table->dropColumn($column);
            }
        });
    }
}
