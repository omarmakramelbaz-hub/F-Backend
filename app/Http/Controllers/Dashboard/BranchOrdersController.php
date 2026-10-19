<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\TakeawayAccess;
use Illuminate\Support\Facades\DB;

class BranchOrdersController extends Controller
{
    public function index(TakeawayAccess $access)
    {
        $actor = auth('admin')->user();
        abort_unless($access->canAccess($actor), 403);
        $branches = $access->branches($actor);
        $restaurantIds = array_column(array_filter($branches, fn ($branch) => $branch['kind'] === 'f'), 'id');
        $owners = $restaurantIds ? DB::table('resturants')->whereIn('id', $restaurantIds)->pluck('user_id', 'id')->all() : [];
        $accountIds = array_merge(array_values($owners), array_column(array_filter($branches, fn ($branch) => $branch['kind'] === 'gs'), 'id'));
        $accounts = User::withoutGlobalScopes()->where(function ($query) use ($accountIds, $restaurantIds, $access) {
            $query->whereIn('id', $accountIds);
            if ($access->has('users', 'owner_resturant_id')) {
                $query->orWhere(function ($linked) use ($restaurantIds) {
                    $linked->where('account_type', 'admin')->whereIn('owner_resturant_id', $restaurantIds);
                });
            }
        })->get();
        $links = [];
        foreach ($accounts as $account) {
            if (!$access->canAccess($account)) continue;
            $receiver = $access->receiverBranch($account);
            foreach ($access->branches($account) as $allowed) {
                $explicitlyLinked = $allowed['kind'] === 'gs'
                    ? $allowed['id'] === (int) $account->id
                    : (int) ($owners[$allowed['id']] ?? 0) === (int) $account->id
                        || ($account->account_type === 'admin' && (int) $account->owner_resturant_id === $allowed['id']);
                if (!$explicitlyLinked) continue;
                $links[$allowed['value']][] = ['id'=>(int) $account->id, 'name'=>$account->name,
                    'receives_print'=>$receiver === $allowed['value']];
            }
        }
        foreach ($branches as &$branch) {
            $branch['accounts'] = $links[$branch['value']] ?? [];
            $branch['has_receiver'] = count(array_filter($branch['accounts'], fn ($account) => $account['receives_print'])) > 0;
        }
        unset($branch);
        return view('admin.branch_orders.index', compact('branches'));
    }
}
