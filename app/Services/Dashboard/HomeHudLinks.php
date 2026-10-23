<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Route;

/**
 * Where each section of the GO live home screen leads. A section gets a link only when the
 * sidebar would show that page to the same account, so a click never ends on a refused page.
 */
class HomeHudLinks
{
    /** @return array<string,string> section key => page URL */
    public static function for($user): array
    {
        if (!$user) return [];
        $links = [];
        $add = function (array $keys, string $route) use (&$links) {
            if (!Route::has($route)) return;
            foreach ($keys as $key) $links[$key] = route($route);
        };
        if (app(OrderBoardService::class)->canAccess($user)) $add(['ch-app'], 'orders.applies');
        if (app(TakeawayService::class)->canAccess($user)) {
            $add(['ch-takeaway'], 'takeaway.index');
            $add(['ch-phone'], 'phone-orders.index');
            $add(['ch-dine'], 'dining.index');
            $add(['expenses', 'expenses-today'], 'branch-expenses.index');
            if (count(app(BranchStock::class)->branches($user))) $add(['stock'], 'branch-stock.index');
            if (count(app(BranchShiftClosing::class)->branches($user))) $add(['drawer'], 'branch-shifts.index');
            $add(['sales', 'active', 'branches', 'cancelled'], 'branch-orders.index');
            $add(['customers'], 'customers.index');
        }
        if (($user->account_type ?? null) === 'admin' && ((int) $user->id === 1 || $user->can('resturant-list'))) $add(['go-stores'], 'go-stores.index');
        return $links;
    }
}
