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
        $couponWheel = $running->first(fn ($wheel) => in_array($wheel->policy ?? null, [null, \App\Services\Draws\ProductDraw::ORDER_TOTAL], true)) ?? $running->first();

        if ($couponWheel) {
            return $this->successResponse([
                'flag' => 'coupon',
                'data' => new CouponWheelResource($couponWheel),
                'draws' => CouponWheelResource::collection($running),
                'recent_winners' => $this->recentWinners(),
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
                    'recent_winners' => $this->recentWinners(),
                    'winner' => $winner->user_coupon_code,
                    'winner_data' => UserDataResource::make($winner->user),
                ], __('api.success data'));
            }
        }

        return $this->errorResponse(__('api.there is no coupon wheel'));
    }

    /** «فاتورة السحب»: the customer buys tickets of a draw by tickets from their wallet. */
    public function buyTickets(\Illuminate\Http\Request $request, int $coupon_wheel)
    {
        $result = app(\App\Services\Draws\DrawTickets::class)->buy((int) auth('api')->id(), $coupon_wheel, $request->only(['count', 'idempotency_key']));
        return $this->successResponse($result, $result['message']);
    }

    /** The draws ended in the last 30 days and their winning codes, newest first (the draw screen shows them). */
    private function recentWinners(): array
    {
        $winners = [];
        $ended = CouponWheel::where('status', 'show')->whereDate('end_date', '<', Carbon::now()->toDateString())
            ->whereDate('end_date', '>=', Carbon::now()->subDays(30)->toDateString())->orderByDesc('end_date')->limit(10)->get();
        foreach ($ended as $wheel) {
            $code = CouponSubscripe::where('coupon_wheel_id', $wheel->id)->where('status', 'winner')->latest('updated_at')->value('user_coupon_code');
            if ($code) $winners[] = ['id' => (int) $wheel->id, 'name' => $wheel->name, 'prize_amount' => $wheel->prize_amount,
                'end_date' => $wheel->end_date, 'winner_code' => $code];
        }
        return $winners;
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
