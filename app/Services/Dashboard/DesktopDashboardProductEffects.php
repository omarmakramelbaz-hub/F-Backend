<?php
namespace App\Services\Dashboard;

use App\Events\ResturantProductUpdated;
use App\Models\ResturantProduct;

/** Prepared desktop writes contact the original provider only after their transaction commits. */
class DesktopDashboardProductEffects
{
    private static int $depth = 0;

    public function guard(callable $work)
    {
        self::$depth++;
        try { return $work(); }
        finally { self::$depth--; }
    }

    public function updated(ResturantProduct $product, $id): void
    {
        if (config('desktop_dashboard.local')) return;
        $event = new ResturantProductUpdated($product, $id);
        if (self::$depth > 0) {
            app(OrderProviderDelivery::class)->event($event);
            return;
        }
        broadcast($event)->toOthers();
    }
}
