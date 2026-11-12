<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use App\Models\CouponWheel;
use App\Models\CouponSubscripe;
use Carbon\Carbon;

class CouponWheelUpdated implements ShouldBroadcast
{
    public $CouponWheel;

    public function __construct($CouponWheel)
    {
        $this->CouponWheel = $CouponWheel;

    }

    public function broadcastOn()
    {
            return new Channel('coupon.wheel.updated');

    }

    public function broadcastAs()
    {
        return 'coupon.wheel.updated'; // Event name
    }
    
    /**
     * A small signal: the apps reload the draw from /api/coupon_wheels when they hear it (they never read the draw from
     * here), and Pusher refuses an event over 10 KB, which the whole draw (its branches, their areas) passed.
     */
    public function broadcastWith()
    {
        $wheel = CouponWheel::find($this->CouponWheel->id);
        if (!$wheel) return ['flag' => 'deleted', 'coupon_wheel_id' => (int) $this->CouponWheel->id];
        $now = Carbon::now();
        $running = $wheel->status == 'show' && $now->greaterThanOrEqualTo(Carbon::parse($wheel->start_date))
            && $now->lessThanOrEqualTo(Carbon::parse($wheel->end_date)->endOfDay());
        $winner = CouponSubscripe::where('coupon_wheel_id', $wheel->id)->where('status', 'winner')->latest('updated_at')->value('user_coupon_code');
        return ['flag' => $running ? 'coupon' : ($winner ? 'winner' : 'updated'), 'coupon_wheel_id' => (int) $wheel->id, 'winner' => $winner];
    }
}


?>