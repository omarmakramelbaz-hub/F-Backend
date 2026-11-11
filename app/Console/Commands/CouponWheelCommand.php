<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\CouponWheel;
use App\Models\User;
use App\Models\Order;
use App\Models\CouponSubscripe;
use Notification;
use App\Events\CouponWheelUpdated;
use Illuminate\Support\Str;

class CouponWheelCommand extends Command
{
    protected $signature = 'notify:coupon';
    protected $description = 'Select and notify the competition winner';

    public function handle()
    {

        $finishedCouponIds = CouponSubscripe::where('status', 'winner')
            ->whereNotNull('coupon_wheel_id')
            ->pluck('coupon_wheel_id');

        $coupons = CouponWheel::where('status', 'show')
            ->whereDate('end_date', '<', Carbon::now()->toDateString())
            ->when($finishedCouponIds->isNotEmpty(), function ($query) use ($finishedCouponIds) {
                $query->whereNotIn('id', $finishedCouponIds);
            })
            ->orderBy('end_date')
            ->get();

        // Every ended draw without a winner gets one; a draw nobody entered no longer holds back the ones after it.
        foreach ($coupons as $coupon) {
            $this->draw($coupon);
        }
        return 0;
    }

    private function draw(CouponWheel $coupon): void
    {
        if (in_array($coupon->policy ?? null, \App\Services\Draws\ProductDraw::BY_ENTRY, true)) {
            // A draw by products or by tickets: every entry (a piece bought, a ticket bought) is one chance.
            $winner = CouponSubscripe::where('coupon_wheel_id', $coupon->id)->where('amount', 1)->whereNull('status')->inRandomOrder()->first();
            if (!$winner) {
                \Log::info('No entries for coupon: '.$coupon->id);
                return;
            }
            $this->announce($coupon, $winner);
            \Log::info('winner:'.$winner->id.' coupon:'.$coupon->id.' order:'.($winner->order_id ?? ''));
            return;
        }

        // Every qualifying completed order is one independent chance in the draw.
        $winnerOrder = Order::where('coupon_wheel_id', $coupon->id)
            ->where('type', 'current')
            ->where('status', 'completed')
            ->inRandomOrder()
            ->first();

        if (!$winnerOrder) {
            \Log::info('No qualifying orders for coupon: '.$coupon->id);
            return;
        }

        $winner = CouponSubscripe::where('coupon_wheel_id', $coupon->id)
            ->where('user_id', $winnerOrder->user_id)
            ->where('amount', 1)
            ->whereNull('status')
            ->inRandomOrder()
            ->first();

        // Backward-compatible fallback for qualifying orders created before the fix.
        if (!$winner) {
            $winner = CouponSubscripe::where('coupon_wheel_id', $coupon->id)
                ->where('user_id', $winnerOrder->user_id)
                ->whereNull('status')
                ->first();
        }

        if (!$winner) {
            $winner = CouponSubscripe::create([
                'user_id' => $winnerOrder->user_id,
                'user_coupon_code' => $this->generateUniqueCode(),
                'coupon_wheel_id' => $coupon->id,
                'price' => (float) $winnerOrder->updated_total,
                'amount' => 1,
                'status' => null,
            ]);
        }

        $this->announce($coupon, $winner);

        \Log::info('winner:'.$winner->id.' coupon:'.$coupon->id.' order:'.$winnerOrder->id);
    }

    /** The winner is kept, the other entries lose, the winner is told and the home screens refresh. */
    private function announce(CouponWheel $coupon, CouponSubscripe $winner): void
    {
        $winner->update(['status' => 'winner']);

        CouponSubscripe::where('coupon_wheel_id', $coupon->id)
            ->where('id', '!=', $winner->id)
            ->whereNull('status')
            ->update(['status' => 'loser']);

        $userWinner = User::find($winner->user_id);
        if ($userWinner) {
            Notification::send(
                $userWinner,
                new \App\Notifications\NotifyUserCouponWheelWinnerNotification($winner)
            );
        }

        // Home screens refresh only after the winner is persisted.
        broadcast(new CouponWheelUpdated($coupon))->toOthers();
    }

    private function generateUniqueCode($length = 10)
    {
        do {
            $code = Str::upper(Str::random($length));
        } while (CouponSubscripe::where('user_coupon_code', $code)->exists());

        return $code;
    }
}
