<?php
namespace App\Services\GoPayments;

use App\Models\Order;
use App\Services\GoServices\Money;
use App\Services\GoServices\PaymobHmac;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** GO order settlement. Lock order first, then payment and sorted wallet owners. */
class OrderPayments
{
    public static function record(int $order): ?object
    {
        return Schema::hasTable('go_order_payments') ? DB::table('go_order_payments')->where('order_id',$order)->first() : null;
    }

    public function deferShipping(Order $order): void
    {
        abort_unless(in_array($order->payment_type,['cash','wallet','v_cash','online'],true),422,'اختر طريقة دفع صحيحة.');
        $this->insert($order, 'waiting_partner');
        $order->update(['status'=>'pending']);
    }

    private function insert(Order $order, string $status): object
    {
        DB::table('go_order_payments')->insertOrIgnore(['order_id'=>$order->id,'customer_id'=>$order->user_id,
            'reference'=>(string)Str::uuid(),'method'=>$order->payment_type,'status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
        return self::record((int)$order->id);
    }

    /** Invoked inside the offer acceptance transaction, after fixing the fare. */
    public function acceptShipping(Order $order): void
    {
        $p = self::record((int)$order->id);
        if (!$p || $p->status !== 'waiting_partner') return;
        $amount = $this->amount($order);
        abort_unless($order->delegate_id,409,'اختر المندوب أولًا.');
        $status = $p->method === 'cash' ? 'cash_due' : 'ready';
        if ($p->method === 'wallet') {
            $this->lockUsers([$p->customer_id,$order->delegate_id]);
            $this->move($p,'wallet_hold',(int)$p->customer_id,null,$amount);
            $status = 'held';
        }
        $fee=DB::table('delegate_notifications')->where('order_id',$order->id)->where('delegate_id',$order->delegate_id)->value('commission_amount');
        if ($fee) $this->applicationCredit(Money::minor((string)$fee));
        DB::table('go_order_payments')->where('id',$p->id)->update(['partner_id'=>$order->delegate_id,'amount_cents'=>$amount,'status'=>$status,'updated_at'=>now()]);
    }

    private function amount(Order $order): int
    {
        $amount = Money::minor(number_format((float)$order->grand_total,2,'.',''));
        abort_unless($amount>0 && $amount<=100000000,422,'قيمة الطلب غير صالحة للدفع.');
        return $amount;
    }

    public function checkout(int $orderId, int $actor): array
    {
        $config = Gateway::settings();
        $p = DB::transaction(function () use ($orderId,$actor,$config) {
            $order = Order::withoutGlobalScopes()->where('id',$orderId)->lockForUpdate()->firstOrFail();
            $user = DB::table('users')->where('id',$actor)->first();
            abort_unless($user && $user->app_scope==='go' && (int)$order->user_id===$actor,403);
            abort_unless(in_array($order->type,['shipping','current'],true) && in_array($order->status,[null,'accepted'],true),409,'الدفع غير متاح في حالة الطلب الحالية.');
            $method = ['online'=>'card','v_cash'=>'mobile_wallet'][$order->payment_type] ?? null;
            abort_unless(in_array($method,Gateway::methods(),true),422,'طريقة الدفع غير متاحة حاليًا.');
            $p = self::record($orderId) ?? $this->insert($order,'ready');
            abort_unless($p->method===$order->payment_type && !in_array($p->status,['waiting_partner','paid','held','refund_pending','refunded','review','cancelled'],true),409,'الدفع غير مطلوب أو سبق تأكيده.');
            if ($p->status==='pending') {
                abort_if(Carbon::parse($p->expires_at)->lte(now()),409,'انتهت مهلة الدفع. ألغِ الطلب أو تواصل مع الدعم.');
                return $p;
            }
            // A timed-out create is not retried with a new intention: its external
            // outcome is unknown. This prevents two simultaneously payable links.
            abort_if($p->status==='creating',409,'جارٍ التحقق من إنشاء الدفع. أعد التحقق من حالة الطلب قبل المحاولة.');
            $partner = $order->type==='shipping' ? $order->delegate_id : $order->resturant?->user_id;
            abort_unless($partner && DB::table('users')->where('id',$partner)->exists(),409,'لم يتحدد حساب الشريك لاستلام قيمة الطلب.');
            Gateway::billing($user);
            if ($order->type==='current') {
                $settings=app(\App\Models\GeneralSettings::class);
                $vendorTax=round($order->total*$order->resturant->service_fees/100,2);
                $order->update(['vendor_tax'=>$vendorTax,'tax'=>round($vendorTax*$settings->tax/100,2)]);
            }
            DB::table('go_order_payments')->where('id',$p->id)->update(['partner_id'=>$partner,'amount_cents'=>$this->amount($order),
                'integration_id'=>(int)$config['methods'][$method],'is_live'=>(bool)$config['is_live'],'status'=>'creating','expires_at'=>now()->addMinutes(30),'updated_at'=>now()]);
            return self::record($orderId);
        },3);
        if ($p->status!=='pending') {
            $user = DB::table('users')->where('id',$actor)->first();
            try {
                $response = (new Gateway())->create(['amount'=>(int)$p->amount_cents,'currency'=>'EGP','payment_methods'=>[(int)$p->integration_id],
                    'special_reference'=>$p->reference,'expiration'=>1800,'billing_data'=>Gateway::billing($user),
                    'notification_url'=>route('go-orders.paymob-webhook'),'redirection_url'=>route('go-orders.payment-return'),
                    'metadata'=>['go_order_id'=>$orderId,'go_payment_reference'=>$p->reference]], $config);
                DB::transaction(function () use ($p,$response,$orderId) {
                    DB::table('orders')->where('id',$orderId)->lockForUpdate()->first();
                    DB::table('go_order_payments')->where('id',$p->id)->update(['gateway_order_id'=>(string)$response['intention_order_id'],
                        'checkout_secret'=>Crypt::encryptString($response['client_secret']),'status'=>self::record($orderId)->status==='creating'?'pending':'cancelled','updated_at'=>now()]);
                },3);
            } catch (\Throwable $e) {
                // Do not log gateway responses, credentials, billing data or client secrets.
                abort(502,'تعذر تأكيد تجهيز الدفع. حدّث الطلب أو تواصل مع الدعم قبل إعادة الدفع.');
            }
            $p = self::record($orderId);
            abort_unless($p->status==='pending',409,'تم إغلاق الطلب.');
        }
        return ['order_id'=>$orderId,'link'=>'https://accept.paymob.com/unifiedcheckout/?'.http_build_query(['publicKey'=>$config['public_key'],
            'clientSecret'=>Crypt::decryptString($p->checkout_secret)]),'expires_at'=>Carbon::parse($p->expires_at)->toIso8601String()];
    }

    public function callback(array $object, string $signature): void
    {
        $this->settleVerified((new Gateway())->verify($object,$signature,Gateway::settings()));
    }

    /** Only call with a verified webhook or merchant-authenticated inquiry. */
    public function settleVerified(array $o): void
    {
        $p = DB::table('go_order_payments')->where('gateway_order_id',(string)($o['order']['id']??''))->first();
        abort_unless($p,404,'Payment not found');
        abort_unless((string)($o['integration_id']??'')===(string)$p->integration_id && ($o['currency']??'')==='EGP'
            && (string)($o['amount_cents']??'')===(string)$p->amount_cents && array_key_exists('is_live',$o)
            && PaymobHmac::truth($o['is_live'])===(bool)$p->is_live,422,'Payment attributes mismatch');
        $transaction=(string)($o['id']??''); abort_unless(preg_match('/^\d{1,30}$/D',$transaction),422,'Invalid transaction');
        $reversed=PaymobHmac::truth($o['is_refunded']??false)||PaymobHmac::truth($o['is_voided']??false);
        if (!$reversed && !Gateway::successful($o)) return;
        DB::transaction(function () use ($p,$o,$transaction,$reversed) {
            $order=Order::withoutGlobalScopes()->where('id',$p->order_id)->lockForUpdate()->firstOrFail();
            $p=self::record((int)$order->id); $receipt=DB::table('go_order_payment_receipts')->where('transaction_id',$transaction)->first();
            if ($receipt && (int)$receipt->payment_id!==(int)$p->id) abort(409,'Transaction already bound');
            if ($reversed) {
                if ($receipt && $receipt->status==='credited') {
                    $this->lockUsers([$p->partner_id]);
                    $this->move($p,'reversal',(int)$p->partner_id,null,(int)$p->amount_cents,true);
                    DB::table('go_order_payment_receipts')->where('id',$receipt->id)->update(['status'=>'reversed','updated_at'=>now()]);
                    DB::table('go_order_payments')->where('id',$p->id)->update(['status'=>'review','updated_at'=>now()]);
                }
                return;
            }
            if ($receipt) return;
            $valid=$p->status==='pending' && in_array($order->status,[null,'accepted'],true)
                && ($order->type!=='shipping'||(int)$order->delegate_id===(int)$p->partner_id);
            DB::table('go_order_payment_receipts')->insert(['payment_id'=>$p->id,'transaction_id'=>$transaction,'amount_cents'=>$p->amount_cents,
                'status'=>$valid?'credited':'refund_due','created_at'=>now(),'updated_at'=>now()]);
            if (!$valid) return; // Late or second captures are auditable refund liabilities.
            $this->lockUsers([$p->partner_id]);
            $this->move($p,'gross_credit',null,(int)$p->partner_id,(int)$p->amount_cents);
            DB::table('go_order_payments')->where('id',$p->id)->update(['status'=>'paid','updated_at'=>now()]);
            if ($order->status===null) $order->update(['status'=>'pending']);
            // Maintain legacy paid-order queries without exposing their unsafe redirect path.
            if (Schema::hasTable('payments')) DB::table('payments')->updateOrInsert(['order_id'=>$order->id,'intention_order_id'=>$p->gateway_order_id],
                ['user_id'=>$p->customer_id,'total_price'=>Money::decimal((int)$p->amount_cents),'status'=>'1','transaction_id'=>$transaction,'created_at'=>now(),'updated_at'=>now()]);
        },3);
    }

    public function assertPayableWork(Order $order, ?int $partner=null): void
    {
        $p=self::record((int)$order->id); if (!$p) return;
        abort_unless($partner===null || (int)$p->partner_id===$partner,403,'الطلب يخص شريكًا آخر.');
        abort_unless(in_array($p->status,['cash_due','held','paid'],true),409,'يجب تأكيد دفع العميل قبل بدء الطلب أو إتمامه.');
    }

    public function assertCanReprice(Order $order): void
    {
        $p=self::record((int)$order->id); if (!$p) return;
        abort_unless($p->status==='waiting_partner',409,'تم الاتفاق على قيمة الطلب. لا يمكن تغيير مبلغ دفع قائم؛ تواصل مع الدعم.');
    }

    /** Returns true when this service owns settlement, including duplicate calls. */
    public function complete(int $orderId): bool
    {
        if (!self::record($orderId)) return false;
        return DB::transaction(function () use ($orderId) {
            $order=Order::withoutGlobalScopes()->where('id',$orderId)->lockForUpdate()->firstOrFail();
            $p=self::record($orderId); $this->assertPayableWork($order);
            if ($order->transfer_price_by!==null) return true;
            $this->lockUsers([$p->partner_id,$order->delegate_id]);
            if ($p->status==='held') $this->move($p,'gross_credit',null,(int)$p->partner_id,(int)$p->amount_cents);
            // Courier commission was charged when the customer accepted the fare.
            // Store gross receipts include delivery and app fees: redistribute those
            // once at completion instead of issuing the old second vendor payout.
            if ($order->type==='current' && $p->status==='paid') {
                $fee=Money::minor(number_format((float)$order->app_percentage,2,'.',''));
                if ($fee>0) $this->move($p,'store_fee',(int)$p->partner_id,null,$fee,true);
                if ($order->delegate_id && !$order->reason) {
                    $delivery=Money::minor(number_format((float)$order->delegate_percentage,2,'.',''));
                    if ($delivery>0) $this->move($p,'delivery_share',(int)$p->partner_id,(int)$order->delegate_id,$delivery,true);
                }
            }
            DB::table('go_order_payments')->where('id',$p->id)->update(['status'=>$p->status==='cash_due'?'cash_due':'paid','updated_at'=>now()]);
            $order->update(['transfer_price_by'=>'admin']);
            return true;
        },3);
    }

    public function finish(int $orderId, ?int $partner=null): void
    {
        DB::transaction(function () use ($orderId,$partner) {
            $order=Order::withoutGlobalScopes()->where('id',$orderId)->lockForUpdate()->firstOrFail();
            $this->assertPayableWork($order,$partner);
            abort_unless(in_array($order->status,['accepted','shipped','completed','in_delivery'],true),409,'لا يمكن إتمام الطلب في حالته الحالية.');
            $this->complete($orderId);
            $order->update(['status'=>'completed']);
        },3);
    }

    public function cancel(int $orderId, int $actor): bool
    {
        if (!self::record($orderId)) return false;
        return DB::transaction(function () use ($orderId,$actor) {
            $order=Order::withoutGlobalScopes()->where('id',$orderId)->lockForUpdate()->firstOrFail();
            $p=self::record($orderId); abort_unless((int)$p->customer_id===$actor,403);
            if ($order->status==='cancelled') return true;
            abort_unless(in_array($order->status,[null,'pending','accepted'],true),409,'لا يمكن الإلغاء بعد بدء تنفيذ الطلب.');
            $this->lockUsers([$p->customer_id,$p->partner_id]); $status='cancelled';
            if ($p->status==='paid') {
                $this->move($p,'reversal',(int)$p->partner_id,null,(int)$p->amount_cents,true);
                $status='refund_pending'; // External refund is never represented as already executed.
            } elseif ($p->status==='held') {
                $this->move($p,'wallet_refund',null,(int)$p->customer_id,(int)$p->amount_cents); $status='refunded';
            }
            DB::table('go_order_payments')->where('id',$p->id)->update(['status'=>$status,'updated_at'=>now()]);
            $order->update(['status'=>'cancelled']);
            if ($order->type==='shipping') DB::table('delegate_notifications')->where('order_id',$orderId)->delete();
            return true;
        },3);
    }

    private function applicationCredit(int $amount): void
    {
        $setting=DB::table('settings')->where('group','general')->where('name','app_balance')->lockForUpdate()->first();
        if (!$setting) throw new \RuntimeException('Application wallet is not configured');
        $balance=Money::minor(json_decode($setting->payload,true));
        DB::table('settings')->where('id',$setting->id)->update(['payload'=>json_encode(Money::decimal($balance+$amount))]);
    }

    private function lockUsers(array $ids): void
    {
        DB::table('users')->whereIn('id',array_filter($ids))->orderBy('id')->lockForUpdate()->get();
    }

    private function move(object $p, string $kind, ?int $from, ?int $to, int $amount, bool $debt=false): void
    {
        $reference='go:order:'.$p->order_id.':'.$kind;
        if (DB::table('wallets')->where('transfer_reference',$reference)->exists()) return;
        $decimal=Money::decimal($amount);
        if ($from) {
            $q=DB::table('users')->where('id',$from); if (!$debt) $q->where('balance','>=',$decimal);
            abort_unless($q->decrement('balance',$decimal),422,'رصيد المحفظة غير كافٍ لقيمة الطلب المتفق عليها.');
        }
        if ($to && !DB::table('users')->where('id',$to)->increment('balance',$decimal)) throw new \RuntimeException('Wallet owner missing');
        if ($kind==='store_fee') $this->applicationCredit($amount);
        DB::table('wallets')->insert(['from_user'=>$from,'to_user'=>$to,'amount'=>$decimal,'status'=>'completed','payment'=>'wallet','type'=>'transfer',
            'order_id'=>$p->order_id,'transfer_reference'=>$reference,'created_at'=>now(),'updated_at'=>now()]);
    }
}
