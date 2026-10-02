<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchRegistry
{
    /**
     * Keep the ERP branch registry aligned with the branches that already exist
     * in the legacy Fasakhansta dashboard. This is intentionally idempotent and
     * excludes GO-scoped partner accounts.
     */
    public function syncDashboardBranches(): int
    {
        if (!Schema::hasTable('erp_branches') || !Schema::hasTable('resturants')) {
            return 0;
        }

        $ownerId = (int) config('erp.legacy_owner_id');
        if ($ownerId < 1) {
            return 0;
        }

        $query = DB::table('resturants as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->select('r.id', 'r.name');

        $hasRestaurantAddedBy = Schema::hasColumn('resturants', 'added_by');
        $hasUserAddedBy = Schema::hasColumn('users', 'added_by');
        $hasAppScope = Schema::hasColumn('users', 'app_scope');

        // Fasakhansta branch records in the existing dashboard are created by
        // the owner account directly or belong to a dashboard account created
        // by that owner. This keeps unrelated external restaurants out.
        if ($hasRestaurantAddedBy || $hasUserAddedBy) {
            $query->where(function ($scope) use ($ownerId, $hasRestaurantAddedBy, $hasUserAddedBy) {
                if ($hasRestaurantAddedBy) {
                    $scope->where('r.added_by', $ownerId);
                }
                if ($hasUserAddedBy) {
                    if ($hasRestaurantAddedBy) {
                        $scope->orWhere('u.added_by', $ownerId);
                    } else {
                        $scope->where('u.added_by', $ownerId);
                    }
                }
            });
        } else {
            // Old schemas without ownership metadata: only enroll restaurants
            // that already have normal Fasakhansta application orders.
            $query->whereExists(function ($orders) {
                $orders->select(DB::raw(1))
                    ->from('orders as o')
                    ->whereColumn('o.resturant_id', 'r.id')
                    ->where('o.type', 'current');
            });
        }

        if ($hasAppScope) {
            $query->where(function ($scope) {
                $scope->whereNull('u.app_scope')
                    ->orWhereNotIn('u.app_scope', ['go_partner', 'go_customer']);
            });
        }

        $restaurants = $query
            ->whereNotNull('r.name')
            ->where('r.name', '!=', '')
            ->orderBy('r.id')
            ->get();

        $created = 0;

        DB::transaction(function () use ($restaurants, &$created) {
            foreach ($restaurants as $restaurant) {
                $existing = DB::table('erp_branches')
                    ->where('restaurant_id', $restaurant->id)
                    ->first();

                if ($existing) {
                    DB::table('erp_branches')
                        ->where('id', $existing->id)
                        ->update([
                            'name' => $restaurant->name,
                            'active' => true,
                            'updated_at' => now(),
                        ]);
                    $branchId = (int) $existing->id;
                } else {
                    $branchId = (int) DB::table('erp_branches')->insertGetId([
                        'restaurant_id' => (int) $restaurant->id,
                        'name' => $restaurant->name,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created++;
                }

                if (Schema::hasTable('erp_warehouses')) {
                    $hasWarehouse = DB::table('erp_warehouses')
                        ->where('branch_id', $branchId)
                        ->exists();

                    if (!$hasWarehouse) {
                        DB::table('erp_warehouses')->insert([
                            'branch_id' => $branchId,
                            'name' => 'مخزن '.$restaurant->name,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        return $created;
    }
}
