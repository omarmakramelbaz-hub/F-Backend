<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddGoPartnerFieldsToPendingVendorsTable extends Migration
{
    public function up()
    {
        Schema::table('pending_vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('pending_vendors', 'application_kind')) {
                $table->string('application_kind', 30)->nullable()->index();
            }
            if (!Schema::hasColumn('pending_vendors', 'age')) {
                $table->unsignedTinyInteger('age')->nullable();
            }
            if (!Schema::hasColumn('pending_vendors', 'profession_key')) {
                $table->string('profession_key', 80)->nullable()->index();
            }
            if (!Schema::hasColumn('pending_vendors', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('pending_vendors', 'lng')) {
                $table->decimal('lng', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('pending_vendors', 'work_radius_km')) {
                $table->unsignedTinyInteger('work_radius_km')->nullable();
            }
            if (!Schema::hasColumn('pending_vendors', 'payment_method')) {
                $table->string('payment_method', 30)->nullable();
            }
            if (!Schema::hasColumn('pending_vendors', 'payment_identifier')) {
                $table->string('payment_identifier', 120)->nullable();
            }
            if (!Schema::hasColumn('pending_vendors', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable();
            }
        });

        try {
            DB::statement('ALTER TABLE pending_vendors MODIFY national_id VARCHAR(255) NULL');
        } catch (\Throwable $e) {
        }
    }

    public function down()
    {
        Schema::table('pending_vendors', function (Blueprint $table) {
            foreach ([
                'application_kind',
                'age',
                'profession_key',
                'lat',
                'lng',
                'work_radius_km',
                'payment_method',
                'payment_identifier',
                'terms_accepted_at',
            ] as $column) {
                if (Schema::hasColumn('pending_vendors', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
