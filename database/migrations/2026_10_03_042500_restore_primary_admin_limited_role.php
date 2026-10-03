<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up()
    {
        if (
            ! Schema::hasTable('users') ||
            ! Schema::hasTable('roles') ||
            ! Schema::hasTable('permissions') ||
            ! Schema::hasTable('model_has_roles') ||
            ! Schema::hasTable('model_has_permissions')
        ) {
            return;
        }

        $user = User::withoutGlobalScopes()->find(635);

        if (! $user || $user->email !== 'omarmakramelbazz@gmail.com') {
            return;
        }

        $role = Role::find(13);
        $permission = Permission::where('name', 'home-list')
            ->where('guard_name', 'admin')
            ->first();

        if (! $role || $role->guard_name !== 'admin') {
            throw new RuntimeException('Expected role 13 for the primary administrative account was not found.');
        }

        if (! $permission) {
            throw new RuntimeException('home-list permission for admin guard was not found.');
        }

        if ($user->account_type !== 'admin') {
            $user->forceFill(['account_type' => 'admin'])->save();
        }

        // Restore the account's previous role and grant only the dashboard
        // homepage as a direct permission. Do not grant Superadmin.
        $user->syncRoles([$role]);
        $user->syncPermissions([$permission]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        // Intentional no-op: this is a corrective compatibility migration.
    }
};
