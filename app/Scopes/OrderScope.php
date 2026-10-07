<?php

namespace App\Scopes;

use App\Models\Resturant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrderScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $admin = auth('admin')->user();

        // This is a dashboard scope. Customer, partner and delegate APIs keep
        // their own existing authentication and order filters.
        if (!$admin || ($admin->account_type === 'admin' && empty($admin->owner_resturant_id))) {
            return;
        }

        if ($admin->account_type === 'admin') {
            $restaurantIds = Resturant::withoutGlobalScopes()->whereKey($admin->owner_resturant_id)->pluck('id');
        } elseif ($admin->account_type === 'resturant_owner' && $admin->owner_resturant_id) {
            $restaurantIds = Resturant::withoutGlobalScopes()
                ->where(function (Builder $query) use ($admin) {
                    $query->where('id', $admin->owner_resturant_id)
                        ->orWhere('parent_id', $admin->owner_resturant_id);
                })
                ->pluck('id');
        } else {
            // Avoid base_resturant: the restaurant scope also includes an OR
            // for children, which can resolve a different account's branch.
            $restaurantId = Resturant::withoutGlobalScopes()
                ->where('user_id', $admin->getKey())
                ->orderBy('id')
                ->value('id');
            $restaurantIds = $restaurantId ? [$restaurantId] : [];
        }

        // Keep both conditions ANDed with requested IDs/statuses. Wallet and
        // courier orders must not bypass branch ownership.
        $builder->where($model->qualifyColumn('type'), 'current')
            ->whereIn($model->qualifyColumn('resturant_id'), $restaurantIds);
    }
}
