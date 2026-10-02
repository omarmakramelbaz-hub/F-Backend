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
        return null;
    }
}
