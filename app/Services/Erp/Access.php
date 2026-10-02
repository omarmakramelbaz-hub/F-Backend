<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Access
{
    public static function ready(): bool
    {
        $ready = config('erp.enabled')
            && Schema::hasTable('erp_branches')
            && Schema::hasTable('erp_audit')
            && Schema::hasTable('erp_ledger_state');

        if (config('erp.standalone_auth', false)) {
            $ready = $ready && Schema::hasTable('erp_users');
        }

        return $ready;
    }

    public static function actor(): ?Actor
    {
        if (!self::ready()) { return null; }

        // Isolated development/trial mode can still use the synthetic ERP staff
        // accounts, but production deliberately reuses the existing dashboard
        // session and never requires a second login.
        if (config('erp.standalone_auth', false) && Auth::guard('erp')->check()) {
            $user = Auth::guard('erp')->user()->fresh();
            if (!$user || !$user->active || !in_array($user->role, Actor::ROLES, true)) { return null; }
            if ($user->role === 'branch_manager' && !$user->branch_id) { return null; }

            return new Actor(
                'staff:'.$user->id,
                $user->name,
                $user->role,
                $user->branch_id ? (int) $user->branch_id : null,
                $user->permissions ?? []
            );
        }

        $user = Auth::guard('admin')->user();
        if (!$user) { return null; }

        if ((int) $user->id === (int) config('erp.legacy_owner_id')) {
            return new Actor('dashboard-owner:'.$user->id, $user->name, 'owner', null, Actor::CAPABILITIES);
        }

        $email = mb_strtolower(trim((string) ($user->email ?? '')));
        if ($email !== '' && in_array($email, config('erp.administrative_admin_emails', []), true)) {
            return new Actor(
                'dashboard-admin:'.$user->id,
                $user->name,
                'deputy_manager',
                null,
                Actor::defaults('deputy_manager')
            );
        }

        $branchId = self::dashboardBranchId($user);
        if ($branchId !== null) {
            return new Actor(
                'dashboard-branch:'.$user->id,
                $user->name,
                'branch_manager',
                $branchId,
                Actor::defaults('branch_manager')
            );
        }

        return null;
    }

    private static function dashboardBranchId($user): ?int
    {
        $restaurantIds = collect();

        if (Schema::hasColumn('users', 'owner_resturant_id') && !empty($user->owner_resturant_id)) {
            $restaurantIds->push((int) $user->owner_resturant_id);
        }

        if (Schema::hasTable('resturants') && Schema::hasColumn('resturants', 'user_id')) {
            $restaurantIds = $restaurantIds->merge(
                DB::table('resturants')->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)
            );
        }

        $restaurantIds = $restaurantIds->filter()->unique()->values();
        if ($restaurantIds->isEmpty()) { return null; }

        $branches = DB::table('erp_branches')
            ->where('active', true)
            ->whereIn('restaurant_id', $restaurantIds)
            ->orderBy('id')
            ->get(['id','restaurant_id']);

        if ($branches->count() === 1) {
            return (int) $branches->first()->id;
        }

        // Prefer the dashboard's explicit restaurant pointer when one account
        // happens to own more than one restaurant record.
        if (Schema::hasColumn('users', 'owner_resturant_id') && !empty($user->owner_resturant_id)) {
            $match = $branches->firstWhere('restaurant_id', (int) $user->owner_resturant_id);
            if ($match) { return (int) $match->id; }
        }

        // Ambiguous dashboard-to-branch mapping is denied instead of granting
        // broader access accidentally.
        return null;
    }
}
