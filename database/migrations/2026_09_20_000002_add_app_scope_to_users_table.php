<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAppScopeToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'app_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('app_scope', 32)->default('fasakhansta')->after('account_type')->index();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropUnique('users_mobile_unique');
            } catch (\Throwable $e) {
            }
            try {
                $table->dropUnique('users_email_unique');
            } catch (\Throwable $e) {
            }
        });

        Schema::table('users', function (Blueprint $table) {
            try {
                $table->unique(['mobile', 'account_type', 'app_scope'], 'users_mobile_type_scope_unique');
            } catch (\Throwable $e) {
            }
            try {
                $table->unique(['email', 'account_type', 'app_scope'], 'users_email_type_scope_unique');
            } catch (\Throwable $e) {
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropUnique('users_mobile_type_scope_unique');
            } catch (\Throwable $e) {
            }
            try {
                $table->dropUnique('users_email_type_scope_unique');
            } catch (\Throwable $e) {
            }
            if (Schema::hasColumn('users', 'app_scope')) {
                $table->dropColumn('app_scope');
            }
        });
    }
}
