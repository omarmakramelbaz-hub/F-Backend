<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Late app orders (the customer app's orders and the GO stores' orders on «استقبال طلبات التطبيقات»), on the same rule as
 * the delivery orders (PhoneDeliveryLateness), timed from the moment the order was made as the card shows it:
 *   - not yet with the courier (new or being prepared) at 15 minutes;
 *   - still with the courier (not delivered) at 35 minutes.
 * A late order's card turns red and the branch hears the same warning every 5 minutes while it stays late.
 */
class AppOrderLateness
{
    private const WATCH_HOURS = 12;
    private const LEGACY_OPEN = ['pending', 'new_order', 'another_delegate', 'accepted', 'shipped'];
    private const STORE_OPEN = ['pending', 'preparing', 'ready', 'out_for_delivery'];

    /**
     * Whether an order in this board stage is late now. $created is when the order was made. An order still waiting for
     * its online payment, and a store pickup order ready for the customer, are not late.
     */
    public static function late(string $source, string $stage, object $row, Carbon $created, ?Carbon $now = null): bool
    {
        if (!in_array($source, ['legacy', 'store'], true)) return false;
        $now ??= Carbon::now();
        $minutes = $created->diffInSeconds($now, false) / 60;
        if ($minutes > self::WATCH_HOURS * 60) return false;
        if ($source === 'legacy' && (!in_array($row->status ?? null, self::LEGACY_OPEN, true) || ($row->type ?? 'current') !== 'current')) return false;
        if ($source === 'store' && (!in_array($row->status ?? null, self::STORE_OPEN, true)
            || (($row->fulfillment ?? '') !== 'delivery' && ($row->status ?? '') === 'ready'))) return false;
        if ($stage === 'courier') return $minutes >= PhoneDeliveryLateness::COURIER_MINUTES;
        return in_array($stage, ['new', 'preparing'], true) && $minutes >= PhoneDeliveryLateness::PREPARING_MINUTES;
    }

    /** The late app orders the account handles on the board, in the delivery warning's shape. */
    public function orders($actor): array
    {
        $board = app(OrderBoardService::class);
        try {
            if (!$actor || !$board->canAccess($actor)) return [];
        } catch (\Throwable $error) {
            return [];
        }
        $zone = config('app.timezone', 'Africa/Cairo');
        $now = Carbon::now($zone);
        $from = $now->copy()->subHours(self::WATCH_HOURS)->toDateTimeString();
        $until = $now->copy()->subMinutes(PhoneDeliveryLateness::PREPARING_MINUTES)->toDateTimeString();
        $found = [];
        if (Schema::hasTable('orders') && Schema::hasColumns('orders', ['resturant_id', 'type', 'status', 'created_at'])) {
            $restaurants = $board->restaurantIds($actor);
            if ($restaurants) {
                $rows = DB::table('orders')->whereIn('resturant_id', $restaurants)->where('type', 'current')->whereIn('status', self::LEGACY_OPEN)
                    ->whereBetween('created_at', [$from, $until])->orderBy('created_at')->limit(50)->get();
                $names = $rows->isEmpty() ? collect() : DB::table('resturants')->whereIn('id', $rows->pluck('resturant_id')->unique()->all())->pluck('name', 'id');
                foreach ($rows as $row) $found[] = $this->entry('legacy', $row, $board, $names[$row->resturant_id] ?? '', $zone, $now);
            }
        }
        $ownStores = !($actor->account_type === 'admin' && !empty($actor->owner_resturant_id));
        if ($ownStores && Schema::hasTable('go_store_orders') && Schema::hasColumns('go_store_orders', ['store_id', 'status', 'created_at'])) {
            $query = DB::table('go_store_orders')->whereIn('status', self::STORE_OPEN)->whereBetween('created_at', [$from, $until]);
            if (!$board->isAdmin($actor)) $query->where('store_id', $actor->id);
            $rows = $query->orderBy('created_at')->limit(50)->get();
            $actions = app(GoStoreBoardActions::class);
            $names = $rows->isEmpty() || !Schema::hasColumns('go_stores', ['user_id', 'name']) ? collect()
                : DB::table('go_stores')->whereIn('user_id', $rows->pluck('store_id')->unique()->all())->pluck('name', 'user_id');
            foreach ($rows as $row) {
                // An order whose payment is not settled yet is not on the branch's hands.
                if ($actions->ready() && !$actions->available($row)) continue;
                $found[] = $this->entry('store', $row, $board, isset($names[$row->store_id]) ? 'جو · '.$names[$row->store_id] : '', $zone, $now);
            }
        }
        return array_values(array_filter($found));
    }

    private function entry(string $source, object $row, OrderBoardService $board, string $branch, string $zone, Carbon $now): ?array
    {
        $created = Carbon::parse($row->created_at, $zone);
        $stage = $board->stage($source, $row);
        if (!self::late($source, $stage, $row, $created, $now)) return null;
        return ['id'=>(int) $row->id, 'key'=>$source.':'.$row->id, 'kind'=>'app',
            'number'=>ltrim((string) ($row->order_no ?? (($source === 'store' ? 'GS-' : '').$row->id)), '#'),
            'branch'=>$branch, 'stage'=>$stage === 'courier' ? 'courier' : 'preparing',
            'minutes'=>(int) floor($created->diffInSeconds($now) / 60), 'created_at'=>$created->toIso8601String()];
    }
}
