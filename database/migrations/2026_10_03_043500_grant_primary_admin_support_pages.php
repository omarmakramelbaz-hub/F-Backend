<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up()
    {
        if (
            ! Schema::hasTable('users') ||
            ! Schema::hasTable('permissions') ||
            ! Schema::hasTable('model_has_permissions')
        ) {
            return;
        }

        $user = User::withoutGlobalScopes()->find(635);

        if (! $user || $user->email !== 'omarmakramelbazz@gmail.com') {
            return;
        }

        $permissions = Permission::where('guard_name', 'admin')
            ->whereIn('name', [
                'question_answer-list',
                'contact-list',
            ])
            ->get();

        if ($permissions->count() !== 2) {
            throw new RuntimeException(
                'Expected question_answer-list and contact-list admin permissions were not both found.'
            );
        }

        $user->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        // Intentional no-op: these are explicit access grants for the
        // primary administrative account and should not be silently revoked.
    }
};
