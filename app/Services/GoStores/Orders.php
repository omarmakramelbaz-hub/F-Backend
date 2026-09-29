<?php

namespace App\Services\GoStores;

use App\Models\User;
use App\Services\GoPayments\Gateway;
use App\Services\GoServices\Money;
use App\Services\GoServices\WalletPolicy;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** GO catalog orders have their own IDs and never enter Fasakhansta order feeds. */
class Orders
{
    public static function ready(): bool
    {
        return Schema::hasTable('go_store_orders') && Schema::hasTable('go_store_payment_receipts')
            && Schema::hasColumn('wallets', 'transfer_reference');
    }

    public function actor(int $id, string $scope): User
    {
        $u = User::withoutGlobalScopes()->findOrFail($id);
        abort_unless($u->app_scope === $scope && $u->status === 'accepted'
            && ($scope === 'go' ? $u->account_type === 'user' : Catalog::isStore($u)), 403);
        return $u;
    }

    public function quote(int $customer, array $data): array
    {
        $snapshot = $this->snapshot($customer, $data);
        return $snapshot + ['quote_token' => Crypt::encryptString(json_encode([
            'customer' => $customer, 'hash' => $this->hash($snapshot), 'expires' => now()->addMinutes(10)->timestamp,
        ])), 'payment_methods' => array_merge(['cash', 'wallet'], Gateway::methods())];
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function snapshot(int $customer, array $data, bool $lock = false): array
    {
        $user = $this->actor($customer, 'go');
        $owner = $this->actor((int)$data['store_id'], 'go_partner');
        $storeQuery = DB::table('go_stores')->where('user_id', $owner->id);
        if ($lock) $storeQuery->lockForUpdate();
        $store = $storeQuery->first();
        abort_unless($store, 404, 'المتجر غير متاح.');
        $products = DB::table('go_store_products')->where('user_id', $owner->id)
            ->whereIn('id', array_column($data['items'], 'product_id'))->orderBy('id');
        if ($lock) $products->lockForUpdate();
        $products = $products->get()->keyBy('id');
        $items = []; $seen = []; $subtotal = 0;
        foreach ($data['items'] as $line) {
            $p = $products->get($line['product_id']);
            abort_unless($p && $p->available, 409, 'أحد المنتجات لم يعد متاحًا. عدّل السلة.');
            $optionId = $line['option_id'] ?? null;
            $key = $p->id.':'.($optionId ?? 'base');
            abort_if(isset($seen[$key]), 422, 'اجمع كمية الاختيار نفسه في بند واحد.');
            $seen[$key] = true;
            $option = null;
            if ($optionId !== null) {
                foreach (json_decode($p->options, true) ?: [] as $o) if ($o['id'] === $optionId) $option = $o;
                abort_unless($option, 409, 'اختيار المنتج لم يعد متاحًا. عدّل السلة.');
            }
            $price = (int)($option['price_cents'] ?? $p->price_cents);
            $quantity = (int)$line['quantity'];
            abort_unless($quantity >= 1 && $quantity <= 99 && $price > 0, 422);
            $subtotal += $price * $quantity;
            $items[] = ['product_id' => (int)$p->id, 'option_id' => $optionId, 'quantity' => $quantity,
                'name' => $p->name, 'unit' => $p->unit, 'option_label' => $option['label'] ?? null,
                'image_url' => Storage::disk('public')->url($p->image_path), 'revision' => (int)$p->revision,
                'unit_price' => Money::decimal($price), 'line_total' => Money::decimal($price * $quantity)];
        }
        abort_unless(count($items) > 0 && count($items) <= 50 && $subtotal <= 100000000, 422, 'قيمة السلة أو عدد المنتجات غير صالح.');
        $address = null; $delivery = 0; $distance = null;
        if ($data['fulfillment'] === 'delivery') {
            $addressQuery = DB::table('user_address')->where('id', $data['address_id'] ?? 0)->where('user_id', $customer);
            if ($lock) $addressQuery->lockForUpdate();
            $a = $addressQuery->first();
            abort_unless($a, 422, 'اختر عنوان توصيل مسجلًا بحسابك.');
            $application = $owner->pending_vendor;
            foreach ([$a->lat ?? null, $application->lat ?? null] as $v) abort_unless(is_numeric($v) && abs((float)$v) <= 90, 422, 'حدد موقع العنوان والمتجر على الخريطة.');
            foreach ([$a->lng ?? null, $application->lng ?? null] as $v) abort_unless(is_numeric($v) && abs((float)$v) <= 180, 422, 'حدد موقع العنوان والمتجر على الخريطة.');
            $distance = Money::distance((float)$a->lat, (float)$a->lng, (float)$application->lat, (float)$application->lng);
            abort_if($distance > (float)($application->work_radius_km ?: 5), 422, 'العنوان خارج نطاق توصيل المتجر. اختر عنوانًا أقرب أو الاستلام من المتجر.');
            $name = $distance <= 1 ? 'default_0_1' : ($distance <= 2 ? 'default_1_2' : ($distance <= 3 ? 'default_2_3' : 'km_price'));
            $setting = DB::table('settings')->where('group', 'general')->where('name', $name)->value('payload');
            abort_unless($setting !== null, 503, 'رسوم التوصيل غير مجهزة. يمكنك اختيار الاستلام من المتجر.');
            $rate = Money::minor(json_decode($setting, true));
            abort_if($rate < 0, 503, 'إعداد رسوم التوصيل غير صالح.');
            $delivery = $distance <= 3 ? $rate : (int)round($rate * $distance);
            $address = ['id' => (int)$a->id, 'address' => $a->address ?? $a->street_name ?? '',
                'street_name' => $a->street_name ?? '', 'floor_no' => $a->floor_no ?? '',
                'apartment_no' => $a->apartment_no ?? '', 'lat' => (string)$a->lat, 'lng' => (string)$a->lng];
        }
        $total = $subtotal + $delivery;
        abort_unless($total > 0 && $total <= 100000000, 422, 'إجمالي الطلب أكبر من الحد المسموح.');
        try { $bps = Money::rate($owner->delegate_fees); }
        catch (\InvalidArgumentException $e) { abort(409, 'نسبة المتجر غير مجهزة. تواصل مع الدعم.'); }
        return ['store_id' => (int)$owner->id, 'store_name' => $store->name, 'store_address' => $store->address,
            'store_mobile' => (string)$owner->mobile, 'customer_name' => $user->name, 'customer_mobile' => (string)$user->mobile,
            'fulfillment' => $data['fulfillment'], 'address' => $address, 'notes' => trim($data['notes'] ?? ''),
            'distance_km' => $distance === null ? null : round($distance, 2), 'items' => $items,
            'subtotal' => Money::decimal($subtotal), 'delivery' => Money::decimal($delivery), 'total' => Money::decimal($total),
            'commission_rate' => Money::decimal($bps), 'commission' => Money::decimal(Money::commission($subtotal, $bps)), 'currency' => 'EGP'];
    }

    public function create(int $customer, array $data): array
    {
        $fingerprint = $data; unset($fingerprint['quote_token']);
        $hash = $this->hash($fingerprint);
        $order = DB::transaction(function () use ($customer, $data, $hash) {
            // Serialize submissions for one account, including retries after a lost response.
            $this->lockUsers([$customer, (int)$data['store_id']]);
            $this->actor($customer, 'go');
            $existing = DB::table('go_store_orders')->where('customer_id', $customer)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'هذا التأكيد يخص سلة مختلفة. راجع طلباتك.');
                return $existing;
            }
            try { $quote = json_decode(Crypt::decryptString($data['quote_token']), true); }
            catch (\Throwable $e) { abort(409, 'راجع إجمالي السلة قبل تنفيذ الطلب.'); }
            abort_unless(($quote['customer'] ?? null) === $customer && ($quote['expires'] ?? 0) > now()->timestamp, 409, 'انتهت مراجعة السعر. حدّث السلة وأكد من جديد.');
            $snapshot = $this->snapshot($customer, $data, true);
            abort_unless(hash_equals($quote['hash'] ?? '', $this->hash($snapshot)), 409, 'تغير السعر أو بيانات الطلب. حدّث السلة وراجع الإجمالي الجديد.');
            WalletPolicy::requireMinimum(DB::table('users')->find($customer));
            WalletPolicy::requireMinimum(DB::table('users')->find($data['store_id']));
            $method = $data['payment_method'];
            abort_unless(in_array($method, array_merge(['cash', 'wallet'], Gateway::methods()), true), 422, 'طريقة الدفع غير متاحة.');
            $online = in_array($method, ['card', 'mobile_wallet'], true);
            $id = DB::table('go_store_orders')->insertGetId([
                'customer_id' => $customer, 'store_id' => $data['store_id'], 'request_key' => $data['request_key'], 'request_hash' => $hash,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'fulfillment' => $data['fulfillment'],
                'status' => $online ? 'awaiting_payment' : 'pending', 'revision' => 1,
                'subtotal_cents' => Money::minor($snapshot['subtotal']), 'delivery_cents' => Money::minor($snapshot['delivery']),
                'total_cents' => Money::minor($snapshot['total']), 'commission_bps' => Money::rate($snapshot['commission_rate']),
                'commission_cents' => Money::minor($snapshot['commission']), 'payment_method' => $method,
                'payment_status' => $online ? 'ready' : ($method === 'wallet' ? 'held' : 'cash_due'),
                'payment_reference' => (string)Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $o = DB::table('go_store_orders')->find($id);
            if ($method === 'wallet') $this->move($o, 'hold', $customer, null, (int)$o->total_cents);
            return $o;
        }, 3);
        return $this->present($order);
    }

    public function visible(int $id, int $actor, bool $partner = false, bool $lock = false): object
    {
        $q = DB::table('go_store_orders')->where('id', $id)->where($partner ? 'store_id' : 'customer_id', $actor);
        if ($partner) $q->whereNotIn('status', ['awaiting_payment']);
        if ($lock) $q->lockForUpdate();
        $o = $q->first(); abort_unless($o, 404); return $o;
    }

    public function present(object $o, bool $partner = false): array
    {
        $s = json_decode($o->snapshot, true);
        $actions = [];
        if ($partner) {
            if ($o->status === 'pending' && in_array($o->payment_status, ['cash_due', 'held', 'paid'], true)) $actions = ['accept', 'reject'];
            if ($o->status === 'preparing') $actions = ['ready'];
            if ($o->status === 'ready') $actions = $o->fulfillment === 'delivery' ? ['out_for_delivery'] : ['complete'];
            if ($o->status === 'out_for_delivery') $actions = ['complete'];
        } elseif (in_array($o->status, ['awaiting_payment', 'pending'], true)) $actions = ['cancel'];
        // A reversed or disputed payment blocks fulfillment until support resolves it.
        if ($partner && !in_array($o->payment_status, ['cash_due', 'held', 'paid'], true)) $actions = [];
        return $s + ['id' => (int)$o->id, 'number' => 'GS-'.$o->id, 'status' => $o->status, 'revision' => (int)$o->revision,
            'payment_method' => $o->payment_method, 'payment_status' => $o->payment_status, 'actions' => $actions,
            'can_pay' => !$partner && $o->status === 'awaiting_payment' && in_array($o->payment_status, ['ready', 'pending'], true),
            'reason' => $o->reason, 'created_at' => $o->created_at, 'updated_at' => $o->updated_at];
    }

    public function transition(int $id, int $actor, bool $partner, string $action, int $revision, ?string $reason = null): array
    {
        return DB::transaction(function () use ($id, $actor, $partner, $action, $revision, $reason) {
            $o = $this->visible($id, $actor, $partner, true);
            $this->actor($actor, $partner ? 'go_partner' : 'go');
            $target = ['accept' => 'preparing', 'reject' => 'rejected', 'ready' => 'ready', 'out_for_delivery' => 'out_for_delivery', 'complete' => 'completed', 'cancel' => 'cancelled'][$action] ?? '';
            if ($o->status === $target) return $this->present($o, $partner);
            abort_unless((int)$o->revision === $revision, 409, 'تغيرت حالة الطلب. حدّث التفاصيل.');
            abort_unless(in_array($action, $this->present($o, $partner)['actions'], true), 409, 'هذا الإجراء غير متاح في حالة الطلب الحالية.');
            $this->lockUsers([$o->customer_id, $o->store_id]);
            if ($action === 'accept') {
                WalletPolicy::requireMinimum(DB::table('users')->find($o->store_id));
                $this->move($o, 'fee', (int)$o->store_id, null, (int)$o->commission_cents, true);
            }
            $payment = $o->payment_status;
            if (in_array($action, ['cancel', 'reject'], true)) {
                if ($payment === 'held') {
                    $this->move($o, 'refund', null, (int)$o->customer_id, (int)$o->total_cents);
                    $payment = 'refunded';
                } elseif ($payment === 'paid') {
                    $this->move($o, 'reversal', (int)$o->store_id, null, (int)$o->total_cents, true);
                    $payment = 'refund_pending';
                } else $payment = 'cancelled';
            }
            if ($action === 'complete' && $payment === 'held') {
                $this->move($o, 'gross', null, (int)$o->store_id, (int)$o->total_cents);
                $payment = 'paid';
            }
            if ($action === 'complete' && $payment === 'cash_due') $payment = 'cash_collected';
            DB::table('go_store_orders')->where('id', $id)->update(['status' => $target, 'payment_status' => $payment,
                'reason' => in_array($action, ['cancel', 'reject'], true) ? $reason : $o->reason, 'revision' => $o->revision + 1, 'updated_at' => now()]);
            return $this->present(DB::table('go_store_orders')->find($id), $partner);
        }, 3);
    }

    public function lockUsers(array $ids): void
    {
        DB::table('users')->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get();
    }

    public function move(object $o, string $kind, ?int $from, ?int $to, int $amount, bool $debt = false): void
    {
        if ($amount === 0) return;
        $reference = 'gs:'.$o->id.':'.$kind;
        if (DB::table('wallets')->where('transfer_reference', $reference)->exists()) return;
        $decimal = Money::decimal($amount);
        if ($from) {
            $q = DB::table('users')->where('id', $from);
            if (!$debt) $q->where('balance', '>=', $decimal);
            abort_unless($q->decrement('balance', $decimal), 422, 'رصيد المحفظة غير كافٍ لقيمة الطلب.');
        }
        if ($to && !DB::table('users')->where('id', $to)->increment('balance', $decimal)) throw new \RuntimeException('Wallet owner missing');
        if ($kind === 'fee') {
            $s = DB::table('settings')->where('group', 'general')->where('name', 'app_balance')->lockForUpdate()->first();
            abort_unless($s, 503, 'محفظة التطبيق غير مجهزة. تواصل مع الدعم.');
            DB::table('settings')->where('id', $s->id)->update(['payload' => json_encode(Money::decimal(Money::minor(json_decode($s->payload, true)) + $amount))]);
        }
        // order_id belongs exclusively to legacy orders; GS IDs are recorded in the unique reference.
        DB::table('wallets')->insert(['from_user' => $from, 'to_user' => $to, 'amount' => $decimal,
            'status' => 'completed', 'payment' => 'wallet', 'type' => 'transfer', 'transfer_reference' => $reference,
            'created_at' => now(), 'updated_at' => now()]);
    }
}
