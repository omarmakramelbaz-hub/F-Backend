<?php

namespace App\Services\Draws;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * «فاتورة السحب»: in a draw by tickets (policy «ticket»), the customer buys tickets in the app from their wallet at the
 * price set on the dashboard; each ticket is one entry, with its own code sent to the customer. The price is taken from
 * the wallet in one step, only while the balance covers it, and goes to the app's balance. The same purchase number
 * (idempotency_key) never charges twice: it answers with the tickets already bought.
 */
class DrawTickets
{
    public const MAX_PER_PURCHASE = 50;

    public function buy(int $userId, int $wheelId, array $values): array
    {
        $v = Validator::make($values, ['count'=>'required|integer|min:1|max:'.self::MAX_PER_PURCHASE, 'idempotency_key'=>'required|uuid'])->validate();
        abort_unless(ProductDraw::ready(), 503, 'مسابقات السحب مش جاهزة حاليًا.');
        $key = strtolower($v['idempotency_key']);
        $result = DB::transaction(function () use ($userId, $wheelId, $v, $key) {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            abort_unless($user, 404);
            $done = DB::table('wallets')->where('transfer_reference', $key)->first();
            if ($done) {
                abort_unless((int) $done->from_user === $userId, 409, 'رقم العملية مستخدم.');
                return ['wallet_id'=>(int) $done->id, 'codes'=>$this->codes((int) $done->id), 'wheel'=>DB::table('coupon_wheels')->find($wheelId), 'new'=>false];
            }
            $wheel = DB::table('coupon_wheels')->where('id', $wheelId)->lockForUpdate()->first();
            $today = Carbon::now()->toDateString();
            abort_unless($wheel && $wheel->status === 'show' && ($wheel->policy ?? null) === ProductDraw::TICKET
                && $wheel->start_date <= $today && $wheel->end_date >= $today, 422, 'المسابقة دي مش متاحة للشراء دلوقتي.');
            $price = Money::minor($wheel->price);
            abort_unless($price > 0, 422, 'سعر التذكرة مش محدد.');
            $amount = Money::decimal($price * (int) $v['count']);
            $paid = DB::table('users')->where('id', $userId)->where('balance', '>=', $amount)->decrement('balance', $amount);
            abort_unless($paid, 422, 'رصيد محفظتك مش كفاية. اشحن المحفظة الأول.');
            $walletId = DB::table('wallets')->insertGetId(['from_user'=>$userId, 'to_user'=>null, 'status'=>'completed', 'payment'=>'wallet',
                'type'=>'transfer', 'amount'=>$amount, 'order_id'=>null, 'transfer_reference'=>$key, 'created_at'=>now(), 'updated_at'=>now()]);
            app(\App\Services\Dashboard\LegacyOrderCompletion::class)->incrementAppBalance($price * (int) $v['count']);
            $draws = app(ProductDraw::class);
            $codes = [];
            for ($n = 1; $n <= (int) $v['count']; $n++) {
                $code = $draws->code();
                DB::table('coupon_subscripes')->insert(['user_id'=>$userId, 'user_coupon_code'=>$code, 'coupon_wheel_id'=>$wheelId,
                    'price'=>Money::decimal($price), 'amount'=>1, 'status'=>null, 'order_id'=>null, 'entry_key'=>"purchase:{$walletId}:{$n}",
                    'created_at'=>now(), 'updated_at'=>now()]);
                $codes[] = $code;
            }
            $draws->tell($userId, $wheel, $codes);
            DB::afterCommit(function () use ($userId) {
                try {
                    $customer = \App\Models\User::withoutGlobalScopes()->find($userId);
                    if ($customer) app(\App\Services\Dashboard\OrderProviderDelivery::class)->event(new \App\Events\BalanceUpdated($customer, $customer->id));
                } catch (\Throwable $error) {
                    \Log::warning('Draw ticket balance notice unavailable', ['user_id'=>$userId, 'error_class'=>get_class($error)]);
                }
            });
            return ['wallet_id'=>$walletId, 'codes'=>$codes, 'wheel'=>$wheel, 'new'=>true];
        }, 3);
        return ['success'=>true, 'coupon_wheel_id'=>$wheelId, 'tickets'=>count($result['codes']), 'codes'=>$result['codes'],
            'balance'=>(float) DB::table('users')->where('id', $userId)->value('balance'), 'already_bought'=>!$result['new'],
            'message'=>'تم شراء '.count($result['codes']).(count($result['codes']) === 1 ? ' تذكرة' : ' تذاكر').'. أكواد الدخول وصلتك في الإشعارات.'];
    }

    private function codes(int $walletId): array
    {
        return DB::table('coupon_subscripes')->where('entry_key', 'like', "purchase:{$walletId}:%")->orderBy('id')->pluck('user_coupon_code')->all();
    }
}
