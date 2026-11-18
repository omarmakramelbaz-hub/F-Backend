<?php
namespace App\Services\Dashboard;

use App\Events\ResturantUpdated;
use App\Models\Resturant;

/** The original restaurant broadcast becomes visible only after a prepared command commits. */
class DesktopDashboardRestaurantEffects
{
    private static int $depth=0;
    public function guard(callable $work) {self::$depth++;try{return $work();}finally{self::$depth--;}}
    public function updated(Resturant $restaurant,$id): void
    {
        if(config('desktop_dashboard.local'))return;$event=new ResturantUpdated($restaurant,$id);
        if(self::$depth>0){app(OrderProviderDelivery::class)->event($event);return;}
        broadcast($event)->toOthers();
    }
}
