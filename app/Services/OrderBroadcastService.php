<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Events\OrderUpdated;
use App\Events\UserUpdated;
use App\Services\Dashboard\OrderProviderDelivery;

class OrderBroadcastService
{
    private static function mainAdmin()
    {
        return User::withoutGlobalScopes()
            ->where('id', 635)
            ->where('email', 'omarmakramelbazz@gmail.com')
            ->where('account_type', 'admin')
            ->first();
    }

    private static function vendor(Order $order)
    {
        return User::find(optional($order->resturant)->user_id);
    }

    public static function newOrder(Order $order)
    {
        $admin = self::mainAdmin();

        if ($admin) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $admin->id, OrderAction::NEW), (int) $order->id);
        }
    }

    public static function accept(Order $order)
    {
        app(OrderProviderDelivery::class)->event(new UserUpdated($order, 1, $order->user_id), (int) $order->id);

        $vendor = self::vendor($order);
        if ($vendor) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $vendor->id, OrderAction::ACCEPTED), (int) $order->id);
        }

        $admin = self::mainAdmin();
        if ($admin) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $admin->id, OrderAction::ACCEPTED), (int) $order->id);
        }
    }

    public static function decline(Order $order)
    {
        app(OrderProviderDelivery::class)->event(new UserUpdated($order, 1, $order->user_id), (int) $order->id);

        $vendor = self::vendor($order);
        if ($vendor) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $vendor->id, OrderAction::DECLINED), (int) $order->id);
        }

        $admin = self::mainAdmin();
        if ($admin) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $admin->id, OrderAction::DECLINED), (int) $order->id);
        }
    }

    public static function outForDelivery(Order $order)
    {
        $vendor = self::vendor($order);

        if ($vendor) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $vendor->id, OrderAction::OUT_FOR_DELIVERY), (int) $order->id);
        }

        $admin = self::mainAdmin();

        if ($admin) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $admin->id, OrderAction::OUT_FOR_DELIVERY), (int) $order->id);
        }
    }

    public static function prepared(Order $order)
    {
        app(OrderProviderDelivery::class)->event(new UserUpdated($order, 1, $order->user_id), (int) $order->id);
    }

    public static function complete(Order $order)
    {
        $vendor = self::vendor($order);

        if ($vendor) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $vendor->id, "completed"), (int) $order->id);
        }

        $admin = self::mainAdmin();

        if ($admin) {
            app(OrderProviderDelivery::class)->event(new OrderUpdated($order, 1, $admin->id, "completed"), (int) $order->id);
        }
    }
}
