<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Events\OrderUpdated;
use App\Events\UserUpdated;

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
            broadcast(new OrderUpdated($order, 1, $admin->id, OrderAction::NEW));
        }
    }

    public static function accept(Order $order)
    {
        broadcast(new UserUpdated($order, 1, $order->user_id));

        $vendor = self::vendor($order);
        if ($vendor) {
            broadcast(new OrderUpdated($order, 1, $vendor->id, OrderAction::ACCEPTED));
        }

        $admin = self::mainAdmin();
        if ($admin) {
            broadcast(new OrderUpdated($order, 1, $admin->id, OrderAction::ACCEPTED));
        }
    }

    public static function decline(Order $order)
    {
        broadcast(new UserUpdated($order, 1, $order->user_id));

        $vendor = self::vendor($order);
        if ($vendor) {
            broadcast(new OrderUpdated($order, 1, $vendor->id, OrderAction::DECLINED));
        }

        $admin = self::mainAdmin();
        if ($admin) {
            broadcast(new OrderUpdated($order, 1, $admin->id, OrderAction::DECLINED));
        }
    }

    public static function outForDelivery(Order $order)
    {
        $vendor = self::vendor($order);

        if ($vendor) {
            broadcast(new OrderUpdated($order, 1, $vendor->id, OrderAction::OUT_FOR_DELIVERY));
        }

        $admin = self::mainAdmin();

        if ($admin) {
            broadcast(new OrderUpdated($order, 1, $admin->id, OrderAction::OUT_FOR_DELIVERY));
        }
    }

    public static function prepared(Order $order)
    {
        broadcast(new UserUpdated($order, 1, $order->user_id));
    }

    public static function complete(Order $order)
    {
        $vendor = self::vendor($order);

        if ($vendor) {
            broadcast(new OrderUpdated($order, 1, $vendor->id, "completed"));
        }

        $admin = self::mainAdmin();

        if ($admin) {
            broadcast(new OrderUpdated($order, 1, $admin->id, "completed"));
        }
    }
}
