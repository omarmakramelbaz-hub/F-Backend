<?php

namespace App\Services\Dashboard;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Resturant;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\NotifyOrderPriceTransferToWalletNotification;
use App\Notifications\NotifyUserOrderStatusUpdatedNotification;
use App\Services\GoServices\Money;
use App\Services\OrderBroadcastService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/** Shared restaurant completion with the retained settlement formulas. */
class LegacyOrderCompletion
{
    /** The caller authorizes the passed order before entering this service. */
    public function complete(Order $authorized, bool $settleGross = false): bool
    {
        return DB::transaction(function () use ($authorized, $settleGross) {
            $order = Order::withoutGlobalScopes()->whereKey($authorized->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'completed') return false;
            abort_unless($order->type === 'current' && in_array($order->status, ['pending', 'new_order', 'another_delegate', 'accepted', 'shipped'], true), 409);
            abort_unless(in_array($order->payment_type, ['cash', 'wallet', 'online', 'v_cash', 'card', 'mobile_wallet'], true), 409, 'وسيلة الدفع غير متاحة للتسوية.');
            $this->lockParties($order);
            $customer = $order->getRelation('user');
            $vendor = optional($order->getRelation('resturant'))->user;
            abort_unless($vendor && $customer, 409, 'حساب العميل أو الفرع غير موجود.');
            $refund = 0;
            if ($order->payment_type !== 'cash') {
                // Preserve the existing adjustment refund, independent of the
                // retained gross settlement that follows via notify:percentage.
                $refund = max(0, Money::minor(number_format($order->total - $order->updated_total, 2, '.', '')));
                $this->move($order, 'adjustment', (int) $vendor->id, (int) $customer->id, $refund);
            }
            $order->status = 'completed';
            $order->save(); // Keep existing coupon/competition observers.
            if ($order->payment_type === 'cash') {
                $this->settleCash($order);
            } elseif ($settleGross) {
                $this->settleGross($order);
            }
            DB::afterCommit(function () use ($order, $refund) {
                try {
                    if ($refund > 0) Notification::send($order->user, new NotifyOrderPriceTransferToWalletNotification($order, (float) Money::decimal($refund)));
                    Notification::send($order->user, new NotifyUserOrderStatusUpdatedNotification($order));
                    if ($order->resturant->user) Notification::send($order->resturant->user, new NotifyUserOrderStatusUpdatedNotification($order));
                    $email = $order->user->email;
                    if ($email) app(BestEffortOrderMail::class)->send((int) $order->id, 'emails.send_order_email',
                        ['email'=>$email, 'cart'=>$order], function ($message) use ($email) {
                            $message->to($email);
                            $message->subject('Your order has been received!');
                        });
                    OrderBroadcastService::complete($order);
                    event(new OrderStatusUpdated($order));
                } catch (\Throwable $error) {
                    \Log::warning('Restaurant order completion notice unavailable', ['order_id'=>(int) $order->id]);
                }
            });
            return true;
        }, 3);
    }

    /** Also used by retained transfer helpers: always lock order before parties. */
    public function lockParties(Order $order): void
    {
        $restaurant = Resturant::withoutGlobalScopes()->find($order->resturant_id);
        $ids = array_filter([$order->user_id, optional($restaurant)->user_id, $order->delegate_id]);
        $users = User::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($restaurant) $restaurant->setRelation('user', $users->get($restaurant->user_id));
        $order->setRelation('resturant', $restaurant);
        $order->setRelation('user', $users->get($order->user_id));
        $order->setRelation('delegate', $users->get($order->delegate_id));
        // Shared app balance must serialize commissions from separate orders.
        DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->lockForUpdate()->first();
    }

    private function settleCash(Order $order): void
    {
        if ($order->transfer_price_by !== null || $order->grand_total <= 0) return;
        $vendor = $order->resturant->user;
        $fee = Money::minor(number_format($order->app_percentage, 2, '.', ''));
        if ($order->delegate_id && $order->reason === null) {
            $delegate = $order->delegate;
            abort_unless($delegate, 409, 'حساب المندوب غير موجود.');
            $vendorPrice = Money::minor(number_format($order->vendor_percentage, 2, '.', ''));
            $this->move($order, 'vendor', (int) $delegate->id, (int) $vendor->id, $vendorPrice);
            $this->move($order, 'commission', (int) $delegate->id, null, $fee);
            $this->incrementAppBalance($fee);
            $order->transfer_price_by = 'delegate';
            $this->pauseIfInDebt((int) $delegate->id);
        } elseif (!$order->delegate_id) {
            $this->move($order, 'commission', (int) $vendor->id, null, $fee);
            $this->incrementAppBalance($fee);
            $order->transfer_price_by = 'vendor';
            $this->pauseIfInDebt((int) $vendor->id);
        }
        $order->save();
    }

    private function settleGross(Order $order): void
    {
        if ($order->transfer_price_by !== null || $order->grand_total <= 0) return;
        $vendorPrice = $order->vendor_percentage + ($order->delegate ? 0 : $order->delivery_price);
        $this->move($order, 'gross-vendor', null, (int) $order->resturant->user_id, Money::minor(number_format($vendorPrice, 2, '.', '')));
        if ($order->delegate && !$order->reason) {
            $this->move($order, 'gross-courier', null, (int) $order->delegate_id, Money::minor(number_format($order->delegate_percentage, 2, '.', '')));
        }
        $order->transfer_price_by = 'admin';
        $order->save();
    }

    private function move(Order $order, string $kind, ?int $from, ?int $to, int $amount): void
    {
        abort_if($amount < 0, 409, 'قيمة التسوية غير صالحة.');
        if ($amount === 0) return;
        $values = ['from_user'=>$from, 'to_user'=>$to, 'amount'=>Money::decimal($amount), 'status'=>'completed',
            'payment'=>'wallet', 'type'=>'transfer', 'order_id'=>$order->id];
        if (Schema::hasColumn('wallets', 'transfer_reference')) {
            $values['transfer_reference'] = 'ob:l:'.$order->id.':'.$kind;
            if (DB::table('wallets')->where('transfer_reference', $values['transfer_reference'])->exists()) return;
        }
        if ($from) abort_unless(DB::table('users')->where('id', $from)->decrement('balance', Money::decimal($amount)), 409);
        if ($to) abort_unless(DB::table('users')->where('id', $to)->increment('balance', Money::decimal($amount)), 409);
        Wallet::create($values);
    }

    public function incrementAppBalance(int $amount): void
    {
        abort_if($amount < 0, 409, 'قيمة العمولة غير صالحة.');
        if ($amount === 0) return;
        DB::transaction(function () use ($amount) {
            $row = DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->lockForUpdate()->first();
            abort_unless($row, 503, 'محفظة التطبيق غير مجهزة.');
            $current = Money::minor(json_decode($row->payload, true));
            DB::table('settings')->where('id', $row->id)->update(['payload'=>json_encode(Money::decimal($current + $amount))]);
            DB::afterCommit(function () {
                // Retained helpers resolve this scoped Settings object. Discard
                // its old app balance after our atomic update, including cache.
                app()->forgetInstance(\App\Models\GeneralSettings::class);
                try {
                    if (config('settings.cache.enabled', false)) app(\Spatie\LaravelSettings\Support\SettingsCacheFactory::class)
                        ->build(\App\Models\GeneralSettings::repository())->clear();
                } catch (\Throwable $error) {
                    \Log::warning('Order app balance settings cache unavailable', ['error_class'=>get_class($error)]);
                }
            });
        }, 3);
    }

    private function pauseIfInDebt(int $id): void
    {
        if (Schema::hasColumns('users', ['expiration_date', 'connected']) && DB::table('users')->where('id', $id)->value('balance') < 0) {
            DB::table('users')->where('id', $id)->update(['expiration_date'=>now()->addHours(2), 'connected'=>'inactive']);
        }
    }
}
