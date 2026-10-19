<?php

namespace App\Observers;

use App\Models\CouponSubscripe;
use App\Models\CouponWheel;
use App\Models\Order;
use Illuminate\Support\Str;

class OrderCompetitionObserver
{
    public function updated(Order $order)
    {
        if (!$order->wasChanged('status') || $order->status !== 'completed' || $order->type !== 'current') {
            return;
        }

        $couponWheel = CouponWheel::where('status', 'show')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->whereHas('resturants', function ($query) use ($order) {
                $query->where('resturant_id', $order->resturant_id);
            })
            ->orderByDesc('start_date')
            ->first();

        $orderTotal = (float) $order->updated_total;

        if (!$couponWheel || $orderTotal < (float) $couponWheel->price) {
            // Neutralize the legacy model hook if it attached a non-qualifying order.
            if ($order->coupon_wheel_id !== null) {
                $order->updateQuietly(['coupon_wheel_id' => null]);
            }
            return;
        }

        if ((int) $order->coupon_wheel_id !== (int) $couponWheel->id) {
            $order->updateQuietly(['coupon_wheel_id' => $couponWheel->id]);
        }

        // One completed qualifying order equals one real draw entry.
        $qualifiedOrdersCount = Order::where('user_id', $order->user_id)
            ->where('coupon_wheel_id', $couponWheel->id)
            ->where('type', 'current')
            ->where('status', 'completed')
            ->count();

        $realEntriesCount = CouponSubscripe::where('user_id', $order->user_id)
            ->where('coupon_wheel_id', $couponWheel->id)
            ->where('amount', 1)
            ->count();

        if ($realEntriesCount >= $qualifiedOrdersCount) {
            return;
        }

        CouponSubscripe::create([
            'user_id' => $order->user_id,
            'user_coupon_code' => $this->generateUniqueCode(),
            'coupon_wheel_id' => $couponWheel->id,
            'price' => $orderTotal,
            'amount' => 1,
            'status' => null,
        ]);
    }

    private function generateUniqueCode($length = 10)
    {
        return \App\Services\Dashboard\DesktopDashboardOrderBoard::competitionCode($length, function () use ($length) {
            do {
                $code = Str::upper(Str::random($length));
            } while (CouponSubscripe::where('user_coupon_code', $code)->exists());
            return $code;
        });
    }
}
