<?php

namespace App\Services\Dashboard;

use App\Events\OrderFinishedUpdated;
use App\Events\DelegateUpdated;
use App\Models\Order;
use App\Models\Resturant;
use App\Models\User;
use App\Notifications\NotifyResturantOrderCancelledNotification;
use App\Notifications\NotifyUserCancelledOrderPriceNotification;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use App\Notifications\NotifyDelegateShippingCancelledNotification;
use App\Services\GoServices\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/** Cancel an authorized accepted restaurant order before collection or handoff. */
class LegacyOrderCancellation
{
    public function available(object $order): bool { return $this->canCancel($order); }

    /** Pure state check shared by the board and the locked mutation. */
    public function canCancel(object $order): bool
    {
        $status = $order->status ?? '';
        $accepted = $status === 'accepted' || ($order->accepted_notify ?? '') === 'yes';
        return ($order->type ?? '') === 'current' && $accepted
            && in_array($status, ['pending', 'new_order', 'another_delegate', 'accepted'], true)
            && in_array($order->payment_type ?? '', ['cash', 'wallet', 'online', 'v_cash'], true)
            // An outside courier can be selected while the order is still preparing.
            // Actual handoff is shipped, or accepted with the branch's own courier.
            && ($order->delegate_from_out ?? '') !== 'in_resturant'
            && empty($order->transfer_price_by);
    }

    /** The caller must authorize this order using the board's persisted branch scope. */
    public function cancel(Order $authorized, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($authorized, $reason) {
            $order = Order::withoutGlobalScopes()->whereKey($authorized->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->resturant_id === (int) $authorized->resturant_id
                && (int) $order->user_id === (int) $authorized->user_id, 409, 'تغير فرع الطلب أو العميل. حدّث الصفحة.');
            if ($order->status === 'cancelled') return false;
            abort_unless($this->canCancel($order), 409, 'إلغاء الطلب متاح أثناء التجهيز وقبل التحصيل وتسليمه للمندوب.');

            $restaurant = Resturant::withoutGlobalScopes()->find($order->resturant_id);
            abort_unless($restaurant, 409, 'الفرع غير موجود.');
            $users = User::withoutGlobalScopes()->whereIn('id', array_filter([$order->user_id, $restaurant->user_id, $order->delegate_id]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $customer = $users->get($order->user_id); $vendor = $users->get($restaurant->user_id);
            $delegate = $users->get($order->delegate_id);
            abort_unless($customer && $vendor, 409, 'حساب العميل أو الفرع غير موجود.');
            $restaurant->setRelation('user', $vendor);
            $order->setRelation('resturant', $restaurant); $order->setRelation('user', $customer);
            $order->setRelation('delegate', $delegate);

            $refund = 0;
            if ($order->payment_type !== 'cash') {
                $refund = $this->paidAmount($order);
                $this->refund($order, $refund);
                // Also excludes this order from the retained delayed moneyback command.
                $order->transfer_price_by = 'admin';
            }
            $order->status = 'cancelled';
            if (Schema::hasColumn('orders', 'reason')) $order->reason = $reason ?: 'إلغاء بطلب العميل';
            $order->save();
            $this->clearDelegateRequests($order);
            if (Schema::hasTable('order_board_clocks') && Schema::hasColumns('order_board_clocks', ['source', 'order_id', 'closed_at'])) {
                DB::table('order_board_clocks')->where('source', 'legacy')->where('order_id', $order->id)
                    ->whereNull('closed_at')->update(['closed_at' => now('UTC')]);
            }
            DB::afterCommit(function () use ($order, $customer, $vendor, $delegate, $refund) {
                $this->notice(fn () => app(OrderProviderDelivery::class)->event(
                    new OrderFinishedUpdated($order, 1, $order->user_id), (int) $order->id), (int) $order->id);
                $this->notice(fn () => Notification::send($customer, new NotifyUserOrderStatusUpdatedNotification($order)), (int) $order->id);
                $this->notice(fn () => Notification::send($vendor, new NotifyResturantOrderCancelledNotification($order)), (int) $order->id);
                if ($delegate) {
                    $this->notice(fn () => app(OrderProviderDelivery::class)->event(
                        new DelegateUpdated($order, 1, $delegate->id), (int) $order->id), (int) $order->id);
                    $this->notice(fn () => Notification::send($delegate, new NotifyDelegateShippingCancelledNotification($order)), (int) $order->id);
                }
                if ($refund > 0) $this->notice(fn () => Notification::send($customer, new NotifyUserCancelledOrderPriceNotification($order)), (int) $order->id);
            });
            return true;
        }, 3);
    }

    private function clearDelegateRequests(Order $order): void
    {
        $delegates = $order->delegate_id ? [(int) $order->delegate_id] : [];
        if (Schema::hasTable('delegate_notifications') && Schema::hasColumns('delegate_notifications', ['order_id', 'delegate_id'])) {
            $requests = DB::table('delegate_notifications')->where('order_id', $order->id)->lockForUpdate()->get();
            foreach ($requests as $request) $delegates[] = (int) $request->delegate_id;
            DB::table('delegate_notifications')->where('order_id', $order->id)->delete();
        }
        $delegates = array_values(array_unique(array_filter($delegates)));
        if (!$delegates || !Schema::hasTable('notifications') || !Schema::hasColumns('notifications',
            ['id', 'type', 'notifiable_type', 'notifiable_id', 'data'])) return;
        $type = 'App\\Notifications\\NotifyDelegatesNewOrderNotification';
        $notices = DB::table('notifications')->where('type', $type)->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $delegates)->orderBy('id')->lockForUpdate()->get();
        $ids = [];
        foreach ($notices as $notice) {
            // Decode before selecting IDs: malformed JSON and notifications for another
            // source, order, restaurant or customer must never be deleted incidentally.
            $data = json_decode((string) $notice->data, true);
            $payload = is_array($data) ? ($data['data'] ?? null) : null;
            if (!is_array($payload)) continue;
            $id = $payload['order_id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || (string) $id !== (string) $order->id) continue;
            if (isset($payload['source']) && $payload['source'] !== 'legacy') continue;
            if (isset($payload['resturant_id']) && (!is_int($payload['resturant_id']) && !is_string($payload['resturant_id'])
                || (string) $payload['resturant_id'] !== (string) $order->resturant_id)) continue;
            if (isset($payload['user_id']) && (!is_int($payload['user_id']) && !is_string($payload['user_id'])
                || (string) $payload['user_id'] !== (string) $order->user_id)) continue;
            $ids[] = $notice->id;
        }
        if ($ids) DB::table('notifications')->where('type', $type)->whereIn('id', $ids)->delete();
    }

    private function paidAmount(Order $order): int
    {
        abort_unless(Schema::hasTable('wallets') && Schema::hasColumns('wallets',
            ['order_id', 'from_user', 'to_user', 'amount', 'status', 'payment', 'type', 'transfer_reference']), 503, 'سجل استرداد المدفوعات غير مجهز.');
        $movements = DB::table('wallets')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($movements as $movement) {
            // A prior refund or party settlement needs reconciliation, never another credit.
            abort_if($movement->to_user !== null || ($movement->from_user !== null
                && (int) $movement->from_user !== (int) $order->user_id), 409, 'راجع التسوية السابقة للطلب قبل إلغائه.');
        }
        if ($order->payment_type === 'wallet') {
            $debits = $movements->filter(fn ($row) => (int) $row->from_user === (int) $order->user_id
                && $row->to_user === null && $row->status === 'completed'
                && $row->payment === 'wallet' && $row->type === 'transfer');
            abort_unless($debits->count() === 1, 409, 'لا يوجد خصم محفظة مؤكد واحد لهذا الطلب.');
            $amount = $this->money($debits->first()->amount);
        } else {
            abort_unless(Schema::hasTable('payments') && Schema::hasColumns('payments',
                ['order_id', 'user_id', 'status', 'total_price', 'transaction_id']), 503, 'سجل الدفع الإلكتروني غير مجهز.');
            $payments = DB::table('payments')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get()
                ->filter(fn ($row) => in_array(strtolower(trim((string) $row->status)), ['1', 'true', 'paid', 'succeeded', 'success'], true));
            abort_unless($payments->count() === 1, 409, 'لا يوجد دفع إلكتروني مؤكد واحد لهذا الطلب.');
            $payment = $payments->first();
            abort_unless((int) $payment->user_id === (int) $order->user_id
                && preg_match('/^[1-9][0-9]*$/D', (string) $payment->transaction_id), 409, 'بيانات عملية الدفع تحتاج مراجعة.');
            abort_unless($movements->isEmpty(), 409, 'يوجد سجل تسوية آخر لهذا الطلب.');
            $amount = $this->money($payment->total_price);
        }
        // Later adjustments or changed service rates must not invent a refund amount.
        abort_unless($amount > 0 && $amount === $this->money($order->grand_total), 409, 'قيمة الدفع المسجلة لا تطابق الطلب. راجع التسوية أولًا.');
        return $amount;
    }

    private function refund(Order $order, int $amount): void
    {
        $reference = 'ob:l:'.$order->id.':cancel';
        abort_if(DB::table('wallets')->where('transfer_reference', $reference)->exists(), 409, 'تم استرداد قيمة الطلب مسبقًا.');
        abort_unless(DB::table('users')->where('id', $order->user_id)->increment('balance', Money::decimal($amount)) === 1, 409);
        DB::table('wallets')->insert(['transfer_reference' => $reference, 'order_id' => $order->id,
            'from_user' => null, 'to_user' => $order->user_id, 'amount' => Money::decimal($amount),
            'status' => 'completed', 'payment' => 'wallet', 'type' => 'transfer', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function money($value): int
    {
        try { $amount = Money::minor($value); }
        catch (\InvalidArgumentException $error) { abort(409, 'قيمة مالية محفوظة غير صالحة.'); }
        abort_if($amount < 0 || $amount > 100000000, 409, 'القيمة المالية خارج الحد المسموح.');
        return $amount;
    }

    private function notice(callable $callback, int $orderId): void
    {
        try { $callback(); }
        catch (\Throwable $error) { \Log::warning('Restaurant cancellation notice unavailable', ['order_id' => $orderId, 'exception' => get_class($error)]); }
    }
}
