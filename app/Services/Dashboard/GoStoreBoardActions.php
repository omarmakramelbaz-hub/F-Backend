<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Notifications\GoStoreOrderNotice;
use App\Services\GoServices\Money;
use App\Services\GoServices\WalletPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/** The retained GO store lifecycle, without enabling checkout or changing its schema. */
class GoStoreBoardActions
{
    private ?bool $schemaReady = null;

    public function ready(): bool
    {
        if ($this->schemaReady !== null) return $this->schemaReady;
        $required = [
            'go_store_orders' => ['id', 'customer_id', 'store_id', 'status', 'fulfillment', 'revision',
                'commission_cents', 'total_cents', 'payment_method', 'payment_status', 'reason', 'updated_at'],
            'users' => ['id', 'app_scope', 'account_type', 'status', 'balance'],
            'go_stores' => ['user_id'],
            'wallets' => ['transfer_reference', 'from_user', 'to_user', 'amount', 'status', 'payment', 'type', 'created_at', 'updated_at'],
            'settings' => ['id', 'group', 'name', 'payload'],
        ];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) return $this->schemaReady = false;
        }
        return $this->schemaReady = true;
    }

    public function available(object $order): array
    {
        if (!$this->ready()) return [];
        // These are the pairs created by checkout and verified payment settlement.
        // An inconsistent retained row must never mint a refund or debit a store.
        $method = $order->payment_method ?? '';
        $payment = $order->payment_status ?? '';
        $validPayment = ($method === 'cash' && $payment === 'cash_due')
            || ($method === 'wallet' && $payment === 'held')
            || (in_array($method, ['card', 'mobile_wallet'], true) && $payment === 'paid');
        if (!$validPayment) return [];
        switch ($order->status) {
            case 'pending': return ['accept', 'reject'];
            case 'preparing': return ['ready', 'cancel'];
            case 'ready': return ($order->fulfillment ?? '') === 'delivery' ? ['dispatch', 'cancel'] : ['complete', 'cancel'];
            case 'out_for_delivery': return ['complete'];
            default: return [];
        }
    }

    /** Caller has already checked dashboard permissions and passes the actual store owner. */
    public function transition(int $id, int $actorId, string $action, int $revision, ?string $reason = null): bool
    {
        abort_unless($this->ready(), 503, 'نظام طلبات المتاجر غير مجهز على هذا السيرفر.');
        return DB::transaction(function () use ($id, $actorId, $action, $revision, $reason): bool {
            $order = DB::table('go_store_orders')->where('id', $id)->where('store_id', $actorId)->lockForUpdate()->first();
            abort_unless($order, 404);
            $target = ['accept'=>'preparing', 'reject'=>'rejected', 'ready'=>'ready', 'dispatch'=>'out_for_delivery', 'complete'=>'completed', 'cancel'=>'cancelled'][$action] ?? null;
            abort_unless($target, 422, 'الإجراء غير صالح.');
            $users = DB::table('users')->whereIn('id', [$order->customer_id, $order->store_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $owner = $users->get($actorId);
            abort_unless($owner && ($owner->app_scope ?? '') === 'go_partner' && ($owner->status ?? '') === 'accepted'
                && $this->isStoreOwner($owner) && DB::table('go_stores')->where('user_id', $actorId)->exists(), 403);
            abort_unless($users->get($order->customer_id), 409, 'حساب العميل غير موجود.');
            // A committed retry still authenticates its owner, then performs no wallet movement.
            if ($order->status === $target) return false;
            abort_unless((int) $order->revision === $revision, 409, 'تغيرت حالة الطلب. حدّث الصفحة.');
            abort_unless(in_array($action, $this->available($order), true), 409, 'الإجراء غير متاح في حالة الطلب الحالية.');
            if ($action === 'accept') {
                WalletPolicy::requireMinimum($owner);
                $this->move($order, 'fee', (int) $order->store_id, null, (int) $order->commission_cents, true);
            }
            $payment = $order->payment_status;
            if ($action === 'cancel') {
                $this->returnCommission($order);
                if ($payment === 'held') {
                    $this->move($order, 'refund', null, (int) $order->customer_id, (int) $order->total_cents);
                    $payment = 'refunded';
                } elseif ($payment === 'paid') {
                    // Retained gateway settlement credits the store before acceptance.
                    // Require that real credit before reversing it; never debit invented gross.
                    $gross = DB::table('wallets')->where('transfer_reference', 'gs:'.$order->id.':gross')->lockForUpdate()->first();
                    abort_unless($gross && $gross->status === 'completed' && $gross->payment === 'wallet'
                        && $gross->type === 'transfer' && $gross->from_user === null
                        && (int) $gross->to_user === (int) $order->store_id
                        && $this->minor($gross->amount) === (int) $order->total_cents, 409, 'قيد الدفع الإلكتروني للمتجر يحتاج مراجعة.');
                    $this->move($order, 'reversal', (int) $order->store_id, null, (int) $order->total_cents, true);
                    $payment = 'refund_pending';
                } else {
                    $payment = 'cancelled';
                }
            }
            if ($action === 'reject') {
                if ($payment === 'held') {
                    $this->move($order, 'refund', null, (int) $order->customer_id, (int) $order->total_cents);
                    $payment = 'refunded';
                } elseif ($payment === 'paid') {
                    $this->move($order, 'reversal', (int) $order->store_id, null, (int) $order->total_cents, true);
                    $payment = 'refund_pending';
                } else {
                    $payment = 'cancelled';
                }
            }
            if ($action === 'complete' && $payment === 'held') {
                $this->move($order, 'gross', null, (int) $order->store_id, (int) $order->total_cents);
                $payment = 'paid';
            } elseif ($action === 'complete' && $payment === 'cash_due') {
                $payment = 'cash_collected';
            }
            DB::table('go_store_orders')->where('id', $id)->update([
                'status'=>$target, 'payment_status'=>$payment, 'revision'=>(int) $order->revision + 1,
                'reason'=>in_array($action, ['reject', 'cancel'], true) ? ($reason ?: ($action === 'cancel' ? 'إلغاء بطلب العميل' : null)) : ($order->reason ?? null), 'updated_at'=>now(),
            ]);
            // Query-builder transitions do not emit Eloquent observer events.
            // Keep the purchase and its feedback clock inside this sale transaction.
            app(CustomerDirectorySync::class)->captureGoOrder(DB::table('go_store_orders')->find($id), $action === 'complete');
            if ($action === 'accept' && $order->fulfillment === 'delivery') app(OrderBoardClock::class)->accepted('store', $id);
            if ($action === 'dispatch') app(OrderBoardClock::class)->courier('store', $id);
            if ($action === 'cancel' && Schema::hasTable('order_board_clocks')
                && Schema::hasColumns('order_board_clocks', ['source', 'order_id', 'closed_at'])) {
                DB::table('order_board_clocks')->where('source', 'store')->where('order_id', $id)
                    ->whereNull('closed_at')->update(['closed_at' => now('UTC')]);
            }
            return true;
        }, 3);
    }

    private function isStoreOwner(object $owner): bool
    {
        if (($owner->account_type ?? '') === 'vendor') return true;
        return ($owner->account_type ?? '') === 'delegate' && !empty($owner->pending_vendor_id)
            && Schema::hasTable('pending_vendors') && Schema::hasColumns('pending_vendors', ['id', 'profession_key'])
            && DB::table('pending_vendors')->where('id', $owner->pending_vendor_id)->where('profession_key', 'store_owner')->exists();
    }

    /** Acceptance and later item edits share this ledger; return its actual net fee once. */
    private function returnCommission(object $order): void
    {
        $reference = 'gs:'.$order->id.':fee-refund';
        abort_if(DB::table('wallets')->where('transfer_reference', $reference)->exists(), 409, 'تم رد عمولة الطلب مسبقًا.');
        abort_if(DB::table('wallets')->whereIn('transfer_reference', ['gs:'.$order->id.':refund', 'gs:'.$order->id.':reversal'])->exists(), 409, 'راجع التسوية السابقة للطلب.');
        $setting = DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->lockForUpdate()->first();
        abort_unless($setting, 503, 'محفظة التطبيق غير مجهزة.');
        $movements = DB::table('wallets')->where(function ($query) use ($order) {
            $query->where('transfer_reference', 'gs:'.$order->id.':fee')
                ->orWhere('transfer_reference', 'like', 'gs:'.$order->id.':items:%');
        })->orderBy('id')->lockForUpdate()->get();
        $charged = 0;
        foreach ($movements as $movement) {
            $debit = (int) $movement->from_user === (int) $order->store_id && $movement->to_user === null;
            $credit = $movement->from_user === null && (int) $movement->to_user === (int) $order->store_id;
            abort_unless($movement->status === 'completed' && $movement->payment === 'wallet'
                && $movement->type === 'transfer' && ($debit || $credit), 409, 'قيد عمولة الطلب يحتاج مراجعة.');
            $charged += ($debit ? 1 : -1) * $this->minor($movement->amount);
        }
        abort_unless($charged >= 0 && $charged === (int) $order->commission_cents, 409, 'راجع تسوية عمولة الطلب قبل إلغائه.');
        if ($charged === 0) return;
        $appBalance = $this->minor(json_decode($setting->payload, true), true);
        abort_unless(DB::table('users')->where('id', $order->store_id)->increment('balance', Money::decimal($charged)) === 1, 409);
        DB::table('settings')->where('id', $setting->id)->update(['payload' => json_encode(Money::decimal($appBalance - $charged))]);
        DB::table('wallets')->insert(['transfer_reference' => $reference, 'from_user' => null, 'to_user' => $order->store_id,
            'amount' => Money::decimal($charged), 'status' => 'completed', 'payment' => 'wallet', 'type' => 'transfer', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function minor($value, bool $signedBalance = false): int
    {
        try { $amount = Money::minor($value); }
        catch (\InvalidArgumentException $error) { abort(409, 'قيمة تسوية محفوظة غير صالحة.'); }
        abort_if(!$signedBalance && ($amount < 0 || $amount > 100000000), 409, 'قيمة التسوية غير صالحة.');
        return $amount;
    }

    /** Call after a committed dashboard transition; polling remains authoritative if push fails. */
    public function notifyCustomer(int $id): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->notifyCustomer($id));
            return;
        }
        try {
            $order = DB::table('go_store_orders')->find($id);
            if (!$order) return;
            $customer = User::withoutGlobalScopes()->find($order->customer_id);
            if ($customer) Notification::send($customer, new GoStoreOrderNotice($order, false));
        } catch (\Throwable $error) {
            \Log::warning('GO store order notification unavailable', ['order_id' => $id]);
        }
    }

    private function move(object $order, string $kind, ?int $from, ?int $to, int $amount, bool $debt = false): void
    {
        abort_if($amount < 0, 409, 'قيمة التسوية غير صالحة.');
        if ($amount === 0) return;
        $reference = 'gs:'.$order->id.':'.$kind;
        if (DB::table('wallets')->where('transfer_reference', $reference)->exists()) return;
        $decimal = Money::decimal($amount);
        if ($from) {
            $debit = DB::table('users')->where('id', $from);
            if (!$debt) $debit->where('balance', '>=', $decimal);
            abort_unless($debit->decrement('balance', $decimal), 409, 'رصيد المحفظة غير كافٍ.');
        }
        if ($to && !DB::table('users')->where('id', $to)->increment('balance', $decimal)) {
            throw new \RuntimeException('Wallet owner missing');
        }
        if ($kind === 'fee') {
            $setting = DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->lockForUpdate()->first();
            abort_unless($setting, 503, 'محفظة التطبيق غير مجهزة.');
            $balance = Money::minor(json_decode($setting->payload, true));
            DB::table('settings')->where('id', $setting->id)->update(['payload'=>json_encode(Money::decimal($balance + $amount))]);
        }
        // Store IDs must never be written into legacy orders.order_id.
        DB::table('wallets')->insert([
            'transfer_reference'=>$reference, 'from_user'=>$from, 'to_user'=>$to, 'amount'=>$decimal,
            'status'=>'completed', 'payment'=>'wallet', 'type'=>'transfer', 'created_at'=>now(), 'updated_at'=>now(),
        ]);
    }
}
