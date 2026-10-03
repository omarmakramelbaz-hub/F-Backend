<?php

namespace App\Services\Dashboard;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use App\Services\OrderBroadcastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class OrderBoardClock
{
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

    /** Safe for scheduler/manual retries and concurrent runs on the same row. */
    public function run(): array
    {
        $counts = ['courier'=>0, 'completed'=>0, 'skipped'=>0, 'errors'=>0];
        if (!$this->ready()) return $counts;
        DB::table('order_board_clocks')->whereIn('source', ['legacy', 'store'])->whereNull('closed_at')->where('accepted_at', '<=', $this->utcNow()->subMinutes(15))
            ->orderBy('id')->chunkById(100, function ($clocks) use (&$counts) {
                foreach ($clocks as $clock) {
                    try {
                        $result = $this->advance($clock);
                        $counts[$result]++;
                    } catch (\Throwable $error) {
                        $counts['errors']++;
                        \Log::warning('Automatic order board transition deferred', [
                            'source'=>$clock->source, 'order_id'=>(int) $clock->order_id, 'error_class'=>get_class($error),
                        ]);
                    }
                }
            });
        return $counts;
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
            if ($accepted->gt($this->utcNow()->subMinutes(15))) return 'skipped';
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
            if ($order->delegate_id || $order->delegate_from_out === 'out_resturant') {
                // Preserve the real assignment and partner delivery model.
                $order->status = 'shipped';
            } else {
                // Same retained own-courier choice as the restaurant dashboard.
                $order->status = 'accepted';
                $order->delegate_from_out = 'in_resturant';
            }
            $order->save();
            $this->clockUpdate($clock, ['courier_at'=>$this->utcNow()]);
            if ($accepted->gt($this->utcNow()->subMinutes(90))) $this->notifyLegacy($order);
            $courier = true;
            $changed = true;
        }
        if ($courier && $accepted->lte($this->utcNow()->subMinutes(90))) {
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
        if ($changed) $this->clockUpdate($clock, ['courier_at'=>$this->utcNow()]);
        if ($row->status === 'out_for_delivery' && $accepted->lte($this->utcNow()->subMinutes(90))) {
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

    private function notifyLegacy(Order $order): void
    {
        DB::afterCommit(function () use ($order) {
            try {
                $customer = User::withoutGlobalScopes()->find($order->user_id);
                if ($customer) Notification::send($customer, new NotifyUserOrderStatusUpdatedNotification($order));
                OrderBroadcastService::outForDelivery($order);
                event(new OrderStatusUpdated($order));
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
