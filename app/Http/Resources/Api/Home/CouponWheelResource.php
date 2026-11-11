<?php

namespace App\Http\Resources\Api\Home;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Order;

class CouponWheelResource extends JsonResource
{
    public function toArray($request)
    {
        $latitude = $request->input('lat');
        $longitude = $request->input('lng');

        $eligibleOrdersCount = 0;
        $eligibleOrdersTotal = 0;

        if (auth('api')->check()) {
            $eligibleOrders = Order::where('user_id', auth('api')->id())
                ->where('coupon_wheel_id', $this->id)
                ->where('type', 'current')
                ->where('status', 'completed')
                ->get();

            $eligibleOrdersCount = $eligibleOrders->count();
            $eligibleOrdersTotal = round($eligibleOrders->sum(function ($order) {
                return (float) $order->updated_total;
            }), 2);
        }

        $policy = $this->policy ?? null;
        $isProduct = $policy === \App\Services\Draws\ProductDraw::PRODUCT;
        $items = $isProduct ? \App\Services\Draws\ProductDraw::items((int) $this->id) : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            // order_total: an order of at least «price» from a participating branch; product: each piece of «products».
            'policy' => $isProduct ? 'product' : 'order_total',
            // The chosen items, by name (the same item on every branch's menu).
            'products' => array_map(fn ($name) => ['id' => null, 'name' => $name], $items),
            'badge_label' => $isProduct ? \App\Services\Draws\ProductDraw::label($this->prize_amount === null ? null : (float) $this->prize_amount) : null,
            'my_entries' => $isProduct && auth('api')->check() ? \App\Services\Draws\ProductDraw::entries((int) $this->id, (int) auth('api')->id()) : null,
            // The customer's entry codes in this draw, and the winning code once drawn.
            'my_codes' => auth('api')->check() ? \App\Models\CouponSubscripe::where('coupon_wheel_id', $this->id)->where('user_id', auth('api')->id())
                ->orderBy('id')->limit(200)->pluck('user_coupon_code')->all() : [],
            'winner_code' => \App\Models\CouponSubscripe::where('coupon_wheel_id', $this->id)->where('status', 'winner')->latest('updated_at')->value('user_coupon_code'),
            'price' => $this->price,
            'prize_amount' => $this->prize_amount,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'image' => $this->getFirstMediaUrl('coupon_wheel_image', 'thumb'),
            'eligible_orders_count' => $eligibleOrdersCount,
            'eligible_orders_total' => $eligibleOrdersTotal,
            'resturants' => CouponWheelResturant::collection(
                $this->resturants()->with('resturant.resturant_areas')->get()->filter(function ($item) use ($latitude, $longitude) {
                    if (empty($latitude) || empty($longitude)) {
                        return true;
                    }

                    $resturant = $item->resturant;
                    if (!$resturant) {
                        return false;
                    }

                    foreach ($resturant->resturant_areas as $restaurantArea) {
                        if ($restaurantArea->lat && $restaurantArea->lng && $restaurantArea->expected_delivery) {
                            $distance = $this->calculateDistance(
                                $latitude,
                                $longitude,
                                $restaurantArea->lat,
                                $restaurantArea->lng
                            );

                            if ($distance <= $restaurantArea->expected_delivery) {
                                return true;
                            }
                        }
                    }

                    return false;
                })
            ),
            'created_at' => $this->created_at,
        ];
    }

    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371;

        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);

        $latDiff = $lat2 - $lat1;
        $lngDiff = $lng2 - $lng1;

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos($lat1) * cos($lat2) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
