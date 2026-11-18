<?php

namespace App\Services\Dashboard;

use App\Events\OrderFinishedUpdated;
use App\Models\Order;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/** Close an unaccepted checkout without creating a receipt, debit or refund. */
class UnpaidOrderCancellation
{
    public function awaiting(object $order): bool
    {
        return ($order->type ?? '') === 'current' && ($order->status ?? null) === null
            && in_array($order->payment_type ?? '', ['online', 'v_cash'], true)
            && ($order->accepted_notify ?? '') !== 'yes' && empty($order->delegate_id)
            && empty($order->delegate_from_out) && empty($order->transfer_price_by);
    }

    public function available(object $order): bool
    {
        if (!$this->awaiting($order) || !Schema::hasColumns('payments', ['id','order_id','user_id','status'])) return false;
        $payments = DB::table('payments')->where('order_id', $order->id)->get(['user_id','status']);
        if (!$this->unpaidAttempts($payments, (int) $order->user_id)) return false;
        if (Schema::hasTable('wallets')) {
            if (!Schema::hasColumn('wallets', 'order_id') || DB::table('wallets')->where('order_id', $order->id)->exists()) return false;
        }
        return true;
    }

    /** Caller supplies an order already authorized by the board's persisted branch scope. */
    public function cancel(Order $authorized, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($authorized, $reason) {
            $order = Order::withoutGlobalScopes()->whereKey($authorized->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->resturant_id === (int) $authorized->resturant_id
                && (int) $order->user_id === (int) $authorized->user_id, 409, 'تغير فرع الطلب أو العميل. حدّث الصفحة.');
            if ($order->status === 'cancelled') return false;
            abort_unless($this->awaiting($order), 409, 'تغيرت حالة الطلب. حدّث الصفحة.');
            abort_unless(Schema::hasColumns('payments', ['id','order_id','user_id','status']), 503, 'سجل الدفع غير مجهز.');
            $payments = DB::table('payments')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            abort_unless($this->unpaidAttempts($payments, (int) $order->user_id), 409, 'يوجد دفع مسجل أو عملية تحتاج مراجعة؛ حدّث الصفحة.');
            if (Schema::hasTable('wallets')) {
                abort_unless(Schema::hasColumn('wallets', 'order_id'), 503, 'سجل التسوية غير مجهز.');
                abort_if(DB::table('wallets')->where('order_id', $order->id)->lockForUpdate()->first(), 409, 'يوجد سجل مالي لهذا الطلب؛ راجع التسوية أولًا.');
            }
            // Late payment callbacks independently recheck this closed state and
            // cannot reactivate the order. No payment/financial row is changed here.
            $order->status = 'cancelled';
            if (Schema::hasColumn('orders', 'reason')) $order->reason = $reason ?: 'إلغاء طلب لم يكتمل دفعه';
            $order->save();
            DB::afterCommit(function () use ($order) {
                try {
                    app(OrderProviderDelivery::class)->event(new OrderFinishedUpdated($order, 1, $order->user_id), (int) $order->id);
                    if ($order->user) Notification::send($order->user, new NotifyUserOrderStatusUpdatedNotification($order));
                } catch (\Throwable $error) {
                    \Log::warning('Unpaid order cancellation notification unavailable', ['order_id'=>(int) $order->id, 'exception'=>get_class($error)]);
                }
            });
            return true;
        }, 3);
    }

    private function unpaidAttempts($payments, int $customer): bool
    {
        foreach ($payments as $payment) {
            if ((int) $payment->user_id !== $customer || !in_array(strtolower(trim((string) ($payment->status ?? ''))),
                ['', 'pending', 'creating', '0', 'false', 'failed'], true)) return false;
        }
        return true;
    }
}
