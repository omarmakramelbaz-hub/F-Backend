<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAppScopeToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'app_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('app_scope', 32)
                    ->default('fasakhansta')
                    ->after('account_type')
                    ->index();
            });
        }

        // The legacy schema made mobile/email globally unique. GO customer
        // accounts must be able to use the same credentials independently.
        foreach (['users_mobile_unique', 'users_email_unique'] as $index) {
            try {
                DB::statement("ALTER TABLE users DROP INDEX {$index}");
            } catch (\Throwable $e) {
                // Already removed or installed under a different legacy name.
            }
        }

        try {
            DB::statement(
                'ALTER TABLE users ADD UNIQUE KEY users_mobile_type_scope_unique (mobile, account_type, app_scope)'
            );
        } catch (\Throwable $e) {
            // Index already exists.
        }

        try {
            DB::statement(
                'ALTER TABLE users ADD UNIQUE KEY users_email_type_scope_unique (email, account_type, app_scope)'
            );
        } catch (\Throwable $e) {
            // Index already exists.
        }
    }

    public function down()
    {
        foreach ([
            'users_mobile_type_scope_unique',
            'users_email_type_scope_unique',
        ] as $index) {
            try {
                DB::statement("ALTER TABLE users DROP INDEX {$index}");
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasColumn('users', 'app_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('app_scope');
            });
        }
    }
}
