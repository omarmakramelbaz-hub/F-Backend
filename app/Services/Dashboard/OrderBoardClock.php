<?php

namespace App\Services\Dashboard;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use App\Services\OrderBroadcastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class OrderBoardClock
{
    /** When each failed transition may be tried again (one entry for all of them: read and written once a run). */
    private const RETRIES = 'order-board-clock-retries';
    /** The wait after the last failure doubles from a minute up to an hour. */
    private const LONGEST_WAIT_MINUTES = 60;

    public function ready(): bool
    {
        return Schema::hasTable('order_board_clocks')
            && Schema::hasColumns('order_board_clocks', ['id', 'source', 'order_id', 'accepted_at', 'courier_at', 'closed_at', 'created_at', 'updated_at']);
    }

    public function accepted(string $source, int $id): void
    {
        if (!$this->ready()) return;
        if (!in_array($source, ['legacy', 'store'], true)) throw new \InvalidArgumentException('Unsupported order clock');
        DB::table('order_board_clocks')->insertOrIgnore([
            'source'=>$source, 'order_id'=>$id, 'accepted_at'=>$this->utcNow(), 'created_at'=>$this->utcNow(), 'updated_at'=>$this->utcNow(),
        ]);
    }

    /** Called after an authorized transition, while its order row remains locked. */
    public function courier(string $source, int $id): void
    {
        if (!$this->ready()) return;
        if (!in_array($source, ['legacy', 'store'], true)) throw new \InvalidArgumentException('Unsupported order clock');
        // Preserve the first real/manual dispatch timestamp; never backfill old orders without a clock.
        DB::table('order_board_clocks')->where('source', $source)->where('order_id', $id)
            ->whereNull('closed_at')->whereNull('courier_at')->update(['courier_at'=>$this->utcNow(), 'updated_at'=>$this->utcNow()]);
    }

    /** Safe for scheduler/manual retries and concurrent runs on the same row. */
    public function run(): array
    {
        $counts = ['courier'=>0, 'completed'=>0, 'skipped'=>0, 'errors'=>0];
        if (!$this->ready()) return $counts;
        // A transition that failed waits 1, 2, 4 ... minutes (at most an hour) before it is tried again: an order that
        // cannot complete is not rebuilt every minute, and one that failed by chance is soon tried again.
        $counts['waiting'] = 0;
        $retries = $this->retries(); $now = $this->utcNow()->getTimestamp(); $due = [];
        DB::table('order_board_clocks')->whereIn('source', ['legacy', 'store'])->whereNull('closed_at')
            ->where(function ($query) { $query->where('accepted_at', '<=', $this->utcNow()->subMinutes(45))->orWhereNotNull('courier_at'); })
            ->orderBy('id')->chunkById(100, function ($clocks) use (&$counts, &$retries, &$due, $now) {
                foreach ($clocks as $clock) {
                    $id = (int) $clock->id; $due[$id] = true;
                    if (($retries[$id]['next'] ?? 0) > $now) { $counts['waiting']++; continue; }
                    try {
                        $result = $this->advance($clock);
                        $counts[$result]++;
                        unset($retries[$id]);
                    } catch (\Throwable $error) {
                        $counts['errors']++;
                        $failures = (int) ($retries[$id]['failures'] ?? 0) + 1;
                        $retries[$id] = ['failures'=>$failures, 'next'=>$now + 60 * min(self::LONGEST_WAIT_MINUTES, 2 ** min(6, $failures - 1))];
                        // A refusal's own sentence says why (written for people); other errors only their kind.
                        $reason = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? ['reason'=>$error->getMessage()] : [];
                        \Log::warning('Automatic order board transition deferred', [
                            'source'=>$clock->source, 'order_id'=>(int) $clock->order_id, 'error_class'=>get_class($error), 'failures'=>$failures,
                        ] + $reason);
                    }
                }
            });
        // Orders no longer due (closed, or changed by hand) are forgotten.
        $this->keepRetries(array_intersect_key($retries, $due));
        return $counts;
    }

    /** @return array<int, array{failures: int, next: int}> */
    private function retries(): array
    {
        try {
            $retries = Cache::get(self::RETRIES);
        } catch (\Throwable $error) {
            return [];
        }
        return is_array($retries) ? $retries : [];
    }

    private function keepRetries(array $retries): void
    {
        try {
            if ($retries === []) Cache::forget(self::RETRIES);
            else Cache::put(self::RETRIES, $retries, now()->addHours(2));
        } catch (\Throwable $error) {
            // Tried again next minute, as before.
        }
    }

    private function advance(object $candidate): string
    {
        $table = $candidate->source === 'legacy' ? 'orders' : 'go_store_orders';
        if (!Schema::hasTable($table)) return 'skipped';
        return DB::transaction(function () use ($candidate, $table) {
            // Shared ordering with acceptance/manual completion: order then clock,
            // parties and app balance. Re-read status after taking the row lock.
            $row = DB::table($table)->where('id', $candidate->order_id)->lockForUpdate()->first();
            $clock = DB::table('order_board_clocks')->where('id', $candidate->id)->lockForUpdate()->first();
            if (!$row || !$clock || $clock->closed_at) return 'skipped';
            $accepted = Carbon::parse($clock->accepted_at, 'UTC');
            if (in_array($row->status, ['completed', 'cancelled', 'declined', 'rejected', 'expired'], true)) {
                $this->clockUpdate($clock, ['closed_at'=>$this->utcNow()]);
                return 'skipped';
            }
            return $candidate->source === 'legacy'
                ? $this->legacy($row, $clock, $accepted)
                : $this->store($row, $clock, $accepted);
        }, 3);
    }

    private function legacy(object $row, object $clock, Carbon $accepted): string
    {
        if (($row->type ?? '') !== 'current' || !$row->resturant_id
            || !Schema::hasTable('carts') || !DB::table('carts')->where('order_id', $row->id)->exists()
            || !in_array($row->status, ['pending', 'new_order', 'another_delegate', 'accepted', 'shipped'], true)
            || (!in_array($row->status, ['accepted', 'shipped'], true) && ($row->accepted_notify ?? '') !== 'yes')
            || !$this->legacyPaymentReady($row)) return 'skipped';
        $order = Order::withoutGlobalScopes()->findOrFail($row->id);
        $courier = $order->status === 'shipped'
            || ($order->status === 'accepted' && $order->delegate_from_out === 'in_resturant');
        $changed = false;
        if (!$courier) {
            if ($accepted->gt($this->utcNow()->subMinutes(45))) return 'skipped';
            if ($order->delegate_id || $order->delegate_from_out === 'out_resturant') {
                // Preserve the real assignment and partner delivery model.
                $order->status = 'shipped';
            } else {
                // Same retained own-courier choice as the restaurant dashboard.
                $order->status = 'accepted';
                $order->delegate_from_out = 'in_resturant';
            }
            $order->save();
            $this->notifyLegacy($order);
            $courier = true;
            $changed = true;
        }
        $courierAt = $this->courierAt($clock);
        if ($courier && $courierAt->lte($this->utcNow()->subMinutes(90))) {
            // A disrupted assigned cash courier is an existing manual financial
            // exception, not a signal to assume a successful collection.
            if ($order->payment_type === 'cash' && $order->delegate_id && $order->reason !== null) return 'skipped';
            app(LegacyOrderCompletion::class)->complete($order, true);
            $this->clockUpdate($clock, ['closed_at'=>$this->utcNow()]);
            return 'completed';
        }
        return $changed ? 'courier' : 'skipped';
    }

    private function store(object $row, object $clock, Carbon $accepted): string
    {
        // A pickup order has no courier phase; retain its normal manual flow.
        if (($row->fulfillment ?? '') !== 'delivery') return 'skipped';
        $actions = app(GoStoreBoardActions::class);
        if (!$actions->ready() || !in_array($row->status, ['preparing', 'ready', 'out_for_delivery'], true)
            || !$actions->available($row)) return 'skipped';
        $changed = false;
        if ($row->status !== 'out_for_delivery' && $accepted->gt($this->utcNow()->subMinutes(45))) return 'skipped';
        if ($row->status === 'preparing') {
            $actions->transition((int) $row->id, (int) $row->store_id, 'ready', (int) $row->revision);
            $row = DB::table('go_store_orders')->find($row->id);
            $changed = true;
        }
        if ($row->status === 'ready') {
            $actions->transition((int) $row->id, (int) $row->store_id, 'dispatch', (int) $row->revision);
            $row = DB::table('go_store_orders')->find($row->id);
            $changed = true;
        }
        $courierAt = $this->courierAt($clock);
        if ($row->status === 'out_for_delivery' && $courierAt->lte($this->utcNow()->subMinutes(90))) {
            $actions->transition((int) $row->id, (int) $row->store_id, 'complete', (int) $row->revision);
            $this->clockUpdate($clock, ['closed_at'=>$this->utcNow()]);
            DB::afterCommit(fn () => $actions->notifyCustomer((int) $row->id));
            return 'completed';
        }
        if ($changed) DB::afterCommit(fn () => $actions->notifyCustomer((int) $row->id));
        return $changed ? 'courier' : 'skipped';
    }

    private function legacyPaymentReady(object $row): bool
    {
        $method = $row->payment_type ?? '';
        if ($method === 'cash') return true;
        if ($method === 'wallet') {
            if (!Schema::hasTable('wallets') || !Schema::hasColumns('wallets', ['order_id', 'from_user', 'to_user', 'status', 'payment', 'amount'])) return false;
            $order = Order::withoutGlobalScopes()->find($row->id);
            return $order && $order->grand_total > 0
                && DB::table('wallets')->where('order_id', $row->id)->where('from_user', $row->user_id)->whereNull('to_user')
                    ->where('status', 'completed')->where('payment', 'wallet')->where('amount', '>=', $order->grand_total)->exists();
        }
        if (!in_array($method, ['online', 'v_cash', 'card', 'mobile_wallet'], true)
            || !Schema::hasTable('payments') || !Schema::hasColumns('payments', ['order_id', 'status'])) return false;
        // payments.status is the retained Paymob callback's success boolean.
        $payments = DB::table('payments')->where('order_id', $row->id)->whereIn('status', ['1', 'true']);
        if (Schema::hasColumn('payments', 'user_id')) $payments->where('user_id', $row->user_id);
        $paid = $payments->get();
        if ($paid->isEmpty()) return false;
        if (!Schema::hasColumn('payments', 'total_price')) return true;
        $known = [];
        foreach ($paid as $payment) {
            if ($payment->total_price === null || (string) $payment->total_price === '') continue;
            try {
                $amount = \App\Services\GoServices\Money::minor($payment->total_price);
            } catch (\InvalidArgumentException $error) {
                return false;
            }
            if ($amount > 0) $known[] = $amount;
        }
        // Older checkout records used the default zero amount; keep their
        // success evidence compatible. Known checkout snapshots must cover
        // the current total after any restaurant price adjustment.
        if (!$known) return true;
        $order = Order::withoutGlobalScopes()->find($row->id);
        return $order && max($known) >= \App\Services\GoServices\Money::minor(number_format($order->grand_total, 2, '.', ''));
    }

    private function clockUpdate(object $clock, array $values): void
    {
        DB::table('order_board_clocks')->where('id', $clock->id)->update($values + ['updated_at'=>$this->utcNow()]);
    }

    private function courierAt(object $clock): Carbon
    {
        // Already-dispatched legacy clocks without a timestamp receive a fresh 90-minute window.
        $this->courier($clock->source, (int) $clock->order_id);
        $value = DB::table('order_board_clocks')->where('id', $clock->id)->value('courier_at');
        return Carbon::parse($value, 'UTC');
    }

    private function notifyLegacy(Order $order): void
    {
        DB::afterCommit(function () use ($order) {
            try {
                $customer = User::withoutGlobalScopes()->find($order->user_id);
                if ($customer) Notification::send($customer, new NotifyUserOrderStatusUpdatedNotification($order));
                OrderBroadcastService::outForDelivery($order);
                app(OrderProviderDelivery::class)->event(new OrderStatusUpdated($order), (int) $order->id);
            } catch (\Throwable $error) {
                \Log::warning('Automatic order dispatch notice unavailable', ['order_id'=>(int) $order->id]);
            }
        });
    }
    private function utcNow(): Carbon
    {
        return Carbon::now('UTC');
    }

}
