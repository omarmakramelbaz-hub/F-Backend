<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Http\Controllers\Controller;
use App\Models\CouponWheel;
use App\Models\CouponSubscripe;
use App\Http\Traits\ApiResponses;
use App\Http\Traits\HomeTraits;
use App\Http\Resources\Api\Home\CouponWheelResource;
use App\Http\Requests\Api\Home\SubscripeCouponRequest;
use Carbon\Carbon;
use App\Http\Resources\Api\Auth\UserDataResource;

class CouponWheelController extends Controller
{
    use ApiResponses;
    use HomeTraits;

    public function coupon_wheels()
    {
        $now = Carbon::now();

        $running = CouponWheel::with('resturants')
            ->where('status', 'show')
            ->whereDate('start_date', '<=', $now)
            ->whereDate('end_date', '>=', $now)
            ->orderByDesc('start_date')
            ->get();
        // Installed apps show one draw («data»), read as an order-total draw: that one first. «draws» has them all.
        $couponWheel = $running->first(fn ($wheel) => ($wheel->policy ?? null) !== \App\Services\Draws\ProductDraw::PRODUCT) ?? $running->first();

        if ($couponWheel) {
            return $this->successResponse([
                'flag' => 'coupon',
                'data' => new CouponWheelResource($couponWheel),
                'draws' => CouponWheelResource::collection($running),
                'winner' => null,
                'winner_data' => null,
            ], __('api.success data'));
        }

        $endedCoupon = CouponWheel::where('status', 'show')
            ->whereDate('end_date', '<', $now->toDateString())
            ->orderByDesc('end_date')
            ->first();

        if ($endedCoupon) {
            $winner = CouponSubscripe::where('coupon_wheel_id', $endedCoupon->id)
                ->where('status', 'winner')
                ->latest('updated_at')
                ->first();

            if ($winner) {
                return $this->successResponse([
                    'flag' => 'winner',
                    'data' => new CouponWheelResource($endedCoupon),
                    'winner' => $winner->user_coupon_code,
                    'winner_data' => UserDataResource::make($winner->user),
                ], __('api.success data'));
            }
        }

        return $this->errorResponse(__('api.there is no coupon wheel'));
    }

    /**
     * Kept for backward compatibility with older app versions.
     * Visiting a participating restaurant must never create a draw entry.
     * A draw entry is created only by a qualifying completed order.
     */
    public function coupon_subscripes(SubscripeCouponRequest $request)
    {
        $couponWheel = CouponWheel::find($request->coupon_wheel_id);

        if (!$couponWheel) {
            return $this->errorResponse(__("api.can't subscribe"));
        }

        $now = Carbon::now();
        $startDate = Carbon::parse($couponWheel->start_date);
        $endDate = Carbon::parse($couponWheel->end_date)->endOfDay();

        if (
            $couponWheel->status == 'show' &&
            $now->greaterThanOrEqualTo($startDate) &&
            $now->lessThanOrEqualTo($endDate)
        ) {
            return $this->successResponse(
                new CouponWheelResource($couponWheel),
                __('api.success data')
            );
        }

        return $this->errorResponse(__("api.can't subscribe"));
    }
}
