<?php
namespace App\Services\GoDelivery;

use App\Events\DelegateUpdated;
use App\Models\DelegateNotification;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotifyDelegatesNewOrderNotification;
use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class Dispatch
{
    private function partners()
    {
        return DB::table('users as u')->leftJoin('pending_vendors as p', 'p.id', '=', 'u.pending_vendor_id')
            ->where(function ($q) { $q->whereIn('u.app_scope', ['go_partner', 'fasakhansta'])->orWhereNull('u.app_scope'); })->where('u.account_type', 'delegate')
            ->where('u.status', 'accepted')->where('u.connected', 'active')->where('u.balance', '>=', '50.00')
            ->where(function ($q) {
                $q->whereNull('p.profession_key')->orWhere(function ($q) {
                    $q->where('p.application_kind', 'partner')->where('p.status', 'accepted')->where('p.profession_key', 'delivery_courier');
                });
            })->select('u.id')->selectRaw("COALESCE(NULLIF(p.lat, ''), u.lat) as lat, COALESCE(NULLIF(p.lng, ''), u.lng) as lng, COALESCE(NULLIF(p.work_radius_km, 0), 10) as work_radius_km");
    }

    private function covers(object $partner, $lat, $lng): bool
    {
        foreach ([$lat, $lng, $partner->lat, $partner->lng] as $value) {
            if (!is_numeric($value) || !is_finite((float) $value)) return false;
        }
        if (abs((float)$lat) > 90 || abs((float)$lng) > 180 || abs((float)$partner->lat) > 90 || abs((float)$partner->lng) > 180) return false;
        $radius = (float) $partner->work_radius_km;
        return $radius > 0 && Money::distance((float)$lat, (float)$lng, (float)$partner->lat, (float)$partner->lng) <= $radius;
    }

    public function candidates($lat, $lng)
    {
        return $this->partners()->get()->filter(fn ($p) => $this->covers($p, $lat, $lng))
            ->sortBy(fn ($p) => Money::distance((float)$lat, (float)$lng, (float)$p->lat, (float)$p->lng))->values();
    }

    public function dispatch(Order $order): int
    {
        $recipients = DB::transaction(function () use ($order) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();
            if (!$locked || $locked->type !== 'shipping' || !in_array($locked->status, ['pending', 'another_delegate'], true) || $locked->delegate_id) return [];
            if ($locked->user?->app_scope !== 'go') return [];
            $shipping = $locked->shipping;
            if (!$shipping) return [];
            $ids = $this->candidates($shipping->from_lat, $shipping->from_lng)->pluck('id')->all();
            $locked->update(['delegate_from_out' => 'out_resturant']);
            $created = [];
            foreach ($ids as $id) {
                $notice = DelegateNotification::firstOrCreate(['order_id' => $locked->id, 'delegate_id' => $id]);
                // Never erase a previous offer or a decline during a retry.
                if ($notice->wasRecentlyCreated) $created[] = (int) $id;
            }
            return $created;
        }, 3);
        // Persist every recipient before external notification delivery. A push
        // failure must not prevent the rest from seeing the order in their inbox.
        foreach ($recipients as $id) {
            try { broadcast(new DelegateUpdated($order->fresh(), 1, $id)); }
            catch (\Throwable $e) { Log::warning('GO delivery live event unavailable', ['order_id' => $order->id, 'partner_id' => $id]); }
            try { Notification::send(User::withoutGlobalScopes()->findOrFail($id), new NotifyDelegatesNewOrderNotification($order->fresh())); }
            catch (\Throwable $e) { Log::warning('GO delivery push unavailable', ['order_id' => $order->id, 'partner_id' => $id]); }
        }
        return count($recipients);
    }

    /** Recover missed delivery invitations when an eligible partner opens the inbox. */
    public function syncInbox(User $user): void
    {
        $partner = $this->partners()->where('u.id', $user->id)->first();
        if (!$partner) return;
        $orders = DB::table('orders as o')->join('users as customer', 'customer.id', '=', 'o.user_id')
            ->join('shippings as s', 's.order_id', '=', 'o.id')
            ->where('customer.app_scope', 'go')->where('o.type', 'shipping')
            ->whereNull('o.delegate_id')->whereIn('o.status', ['pending', 'another_delegate'])
            ->whereNotExists(function ($q) use ($user) {
                $q->selectRaw('1')->from('delegate_notifications as n')->whereColumn('n.order_id', 'o.id')->where('n.delegate_id', $user->id);
            })->orderByDesc('o.id')->limit(100)->get(['o.id', 's.from_lat', 's.from_lng']);
        foreach ($orders as $order) {
            if (!$this->covers($partner, $order->from_lat, $order->from_lng)) continue;
            DB::transaction(function () use ($order, $user) {
                $current = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->first();
                if (!$current || $current->delegate_id || !in_array($current->status, ['pending', 'another_delegate'], true)) return;
                $current->update(['delegate_from_out' => 'out_resturant']);
                DelegateNotification::firstOrCreate(['order_id' => $order->id, 'delegate_id' => $user->id]);
            }, 3);
        }
    }
}
