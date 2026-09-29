<?php
namespace App\Services\GoStores;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class Notices
{
    public static function send(int $id, bool $toStore): void
    {
        try {
            $order = DB::table('go_store_orders')->find($id);
            $user = User::withoutGlobalScopes()->find($toStore ? $order->store_id : $order->customer_id);
            if ($user) Notification::send($user, new \App\Notifications\GoStoreOrderNotice($order, $toStore));
        } catch (\Throwable $error) {
            // Order storage and inbox polling remain authoritative if push is unavailable.
            \Log::warning('GO store order notification unavailable', ['order_id' => $id]);
        }
    }
}
