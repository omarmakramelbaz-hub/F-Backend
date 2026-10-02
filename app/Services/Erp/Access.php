<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class Access
{
    public static function ready(): bool
    {
        return config('erp.enabled') && Schema::hasTable('erp_users') && Schema::hasTable('erp_audit') && Schema::hasTable('erp_ledger_state');
    }

    public static function actor(): ?Actor
    {
        if (!self::ready()) { return null; }
        // Never fall back to a legacy owner session when a staff session was revoked.
        if (Auth::guard('erp')->check()) {
            $user = Auth::guard('erp')->user()->fresh();
            if (!$user || !$user->active || !in_array($user->role, Actor::ROLES, true)) { return null; }
            if ($user->role === 'branch_manager' && !$user->branch_id) { return null; }
            return new Actor('staff:'.$user->id, $user->name, $user->role, $user->branch_id ? (int) $user->branch_id : null, $user->permissions ?? []);
        }
        $owner = Auth::guard('admin')->user();
        if ($owner && (int) $owner->id === (int) config('erp.legacy_owner_id') && in_array($owner->account_type, ['admin','super_admin'], true)) {
            return new Actor('legacy:'.$owner->id, $owner->name, 'owner', null, Actor::CAPABILITIES);
        }

        // Keep the existing legacy administrative-admin login, but scope its ERP
        // access to the application-orders workspace only. The Actor remains a
        // central role so it can select any branch enrolled in erp_branches.
        if ($owner && in_array($owner->account_type, ['admin','super_admin'], true)) {
            $email = mb_strtolower(trim((string) ($owner->email ?? '')));
            $allowed = config('erp.legacy_order_admin_emails', []);
            if ($email !== '' && in_array($email, $allowed, true)) {
                return new Actor('legacy-orders:'.$owner->id, $owner->name, 'deputy_manager', null, ['orders.view']);
            }
        }

        return null;
    }
}
