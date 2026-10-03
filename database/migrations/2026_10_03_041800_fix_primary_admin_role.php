<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up()
    {
        if (
            ! Schema::hasTable('users') ||
            ! Schema::hasTable('roles') ||
            ! Schema::hasTable('model_has_roles')
        ) {
            return;
        }

        $user = User::withoutGlobalScopes()->find(635);

        if (! $user || $user->email !== 'omarmakramelbazz@gmail.com') {
            return;
        }

        $role = Role::find(11);

        if (! $role || $role->guard_name !== 'admin' || $role->name !== 'Superadmin') {
            throw new RuntimeException('Expected Superadmin role 11 was not found.');
        }

        if (! $role->hasPermissionTo('home-list')) {
            throw new RuntimeException('Superadmin role 11 does not have home-list permission.');
        }

        if ($user->account_type !== 'admin') {
            $user->forceFill(['account_type' => 'admin'])->save();
        }

        $user->syncRoles([$role]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        // Intentional no-op: this migration corrects the canonical
        // administrative account and should not downgrade it on rollback.
    }
};
