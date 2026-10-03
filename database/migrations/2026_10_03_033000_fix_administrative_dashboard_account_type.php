<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the administrative dashboard account classified as an admin.
     *
     * This is intentionally guarded by both the immutable user ID and the
     * expected email address so an unrelated record is never promoted.
     */
    public function up()
    {
        if (
            ! Schema::hasTable('users') ||
            ! Schema::hasColumn('users', 'id') ||
            ! Schema::hasColumn('users', 'email') ||
            ! Schema::hasColumn('users', 'account_type')
        ) {
            return;
        }

        $user = DB::table('users')
            ->where('id', 635)
            ->where('email', 'omarmakramelbazz@gmail.com')
            ->first(['id', 'email', 'account_type']);

        if (! $user || $user->account_type === 'admin') {
            return;
        }

        if ($user->account_type !== 'resturant_owner') {
            throw new RuntimeException(
                'Administrative account 635 has an unexpected account_type; migration stopped without changing it.'
            );
        }

        $values = ['account_type' => 'admin'];

        if (Schema::hasColumn('users', 'updated_at')) {
            $values['updated_at'] = now();
        }

        DB::table('users')
            ->where('id', 635)
            ->where('email', 'omarmakramelbazz@gmail.com')
            ->where('account_type', 'resturant_owner')
            ->update($values);
    }

    public function down()
    {
        if (
            ! Schema::hasTable('users') ||
            ! Schema::hasColumn('users', 'id') ||
            ! Schema::hasColumn('users', 'email') ||
            ! Schema::hasColumn('users', 'account_type')
        ) {
            return;
        }

        $values = ['account_type' => 'resturant_owner'];

        if (Schema::hasColumn('users', 'updated_at')) {
            $values['updated_at'] = now();
        }

        DB::table('users')
            ->where('id', 635)
            ->where('email', 'omarmakramelbazz@gmail.com')
            ->where('account_type', 'admin')
            ->update($values);
    }
};
