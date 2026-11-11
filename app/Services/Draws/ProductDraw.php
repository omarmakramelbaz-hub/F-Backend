<?php

namespace App\Services\Draws;

use App\Models\Order;
use App\Support\RequestFacts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * «مسابقة السحب» by chosen products: during the draw's dates, the products picked on the dashboard carry a badge in the
 * customer app (in every participating branch: the customer orders from the branch nearest to them) with the prize:
 * «هذا الصنف يرشحك للدخول على سحب بقيمة 5,000 جنيه». Each piece of them in a delivered order (completed) is one entry
 * in that draw; a weighed line (half a kilo) is one.
 * The other draws keep their own rule: an order of at least the draw's amount from a participating branch (order_total).
 */
class ProductDraw
{
    public const ORDER_TOTAL = 'order_total';
    public const PRODUCT = 'product';
    public const POLICIES = [self::ORDER_TOTAL, self::PRODUCT];

    /** The columns and the products table the product draw needs (php artisan draws:install-schema adds them). */
    public static function ready(): bool
    {
        return RequestFacts::remember('draws.product.ready', fn () => Schema::hasTable('coupon_wheels') && Schema::hasTable('coupon_subscripes')
            && Schema::hasTable('coupon_wheel_products') && Schema::hasColumn('coupon_wheels', 'policy')
            && Schema::hasColumns('coupon_subscripes', ['order_id', 'entry_key']));
    }

    /** A draw's chosen products (catalog product ids). */
    public static function products(int $wheelId): array
    {
        if (!self::ready()) return [];
        return array_map('intval', DB::table('coupon_wheel_products')->where('coupon_wheel_id', $wheelId)->orderBy('id')->pluck('product_id')->all());
    }

    /** Replaces a draw's chosen products. */
    public static function choose(int $wheelId, array $productIds): void
    {
        if (!self::ready()) return;
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        DB::transaction(function () use ($wheelId, $ids) {
            DB::table('coupon_wheel_products')->where('coupon_wheel_id', $wheelId)->whereNotIn('product_id', $ids ?: [0])->delete();
            foreach ($ids as $id) DB::table('coupon_wheel_products')->insertOrIgnore(['coupon_wheel_id'=>$wheelId, 'product_id'=>$id, 'created_at'=>now(), 'updated_at'=>now()]);
        });
    }

    /** The draws running today by products, with their branches and products: [id => {wheel, restaurants[], products[]}]. */
    public static function active(): array
    {
        if (!self::ready()) return [];
        return RequestFacts::remember('draws.product.active', function () {
            $today = Carbon::now()->toDateString();
            $wheels = DB::table('coupon_wheels')->where('status', 'show')->where('policy', self::PRODUCT)
                ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->orderBy('id')->get();
            if ($wheels->isEmpty()) return [];
            $ids = $wheels->pluck('id')->all();
            $branches = DB::table('coupon_wheel_resturants')->whereIn('coupon_wheel_id', $ids)->get()->groupBy('coupon_wheel_id');
            $products = DB::table('coupon_wheel_products')->whereIn('coupon_wheel_id', $ids)->get()->groupBy('coupon_wheel_id');
            $active = [];
            foreach ($wheels as $wheel) {
                $chosen = array_map('intval', ($products[$wheel->id] ?? collect())->pluck('product_id')->all());
                if (!$chosen) continue;
                $active[(int) $wheel->id] = ['wheel'=>$wheel, 'products'=>$chosen,
                    'restaurants'=>array_map('intval', ($branches[$wheel->id] ?? collect())->pluck('resturant_id')->all())];
            }
            return $active;
        });
    }

    /** The badge on a branch's product in the app: the draw it qualifies for and its prize, or null. */
    public static function badge(?int $restaurantId, ?int $productId): ?array
    {
        if (!$restaurantId || !$productId) return null;
        foreach (self::active() as $id => $draw) {
            if (!in_array($productId, $draw['products'], true) || !in_array($restaurantId, $draw['restaurants'], true)) continue;
            $prize = $draw['wheel']->prize_amount === null ? null : (float) $draw['wheel']->prize_amount;
            return ['coupon_wheel_id'=>$id, 'name'=>(string) $draw['wheel']->name, 'prize_amount'=>$prize,
                'end_date'=>(string) $draw['wheel']->end_date,
                'label'=>self::label($prize)];
        }
        return null;
    }

    /** A delivered app order: one entry per piece of each running draw's products, once however often this is called. */
    public function enter(Order $order): int
    {
        if (!self::ready() || $order->status !== 'completed' || $order->type !== 'current' || !$order->resturant_id || !$order->user_id) return 0;
        $lines = DB::table('carts')->join('resturant_products', 'resturant_products.id', '=', 'carts.resturant_product_id')
            ->where('carts.order_id', $order->id)->get(['resturant_products.product_id', 'carts.qty'])
            ->map(fn ($line) => [(int) $line->product_id, (float) $line->qty])->all();
        return $this->grant((int) $order->resturant_id, $lines, (int) $order->user_id, 'order:'.$order->id, (int) $order->id);
    }

    /**
     * A website order the branch collected (its delivery ticket paid): the same entries, for the customer's app account
     * with the same mobile number (the winner is told in the app). Without an app account it gives none.
     */
    public function enterWebsiteTicket(int $ticketId): int
    {
        if (!self::ready() || !Schema::hasTable('web_storefront_orders') || !Schema::hasTable('pos_service_tickets')) return 0;
        $ticket = DB::table('pos_service_tickets')->where('id', $ticketId)->first();
        if (!$ticket || ($ticket->payment_status ?? '') !== 'paid' || !preg_match('/^f:([1-9][0-9]*)$/D', (string) $ticket->branch, $branch)) return 0;
        if (!DB::table('web_storefront_orders')->where('ticket_id', $ticketId)->exists()) return 0;
        $user = self::customerByPhone((string) ($ticket->customer_phone ?? ''));
        if (!$user) return 0;
        $items = (json_decode((string) $ticket->cart_snapshot, true) ?: [])['items'] ?? [];
        $ids = array_values(array_filter(array_map(fn ($item) => (int) ($item['product_id'] ?? 0), $items)));
        $catalog = $ids ? DB::table('resturant_products')->whereIn('id', $ids)->where('resturant_id', (int) $branch[1])->pluck('product_id', 'id') : collect();
        $lines = [];
        foreach ($items as $item) {
            $product = $catalog[(int) ($item['product_id'] ?? 0)] ?? null;
            if ($product) $lines[] = [(int) $product, (float) ($item['quantity'] ?? 0)];
        }
        return $this->grant((int) $branch[1], $lines, $user, 'ticket:'.$ticketId, null);
    }

    /** The app customer with this mobile number, however it was written (01..., 1..., +20..., 20...). */
    public static function customerByPhone(string $phone): ?int
    {
        $key = \App\Services\Dashboard\BranchCustomers::phoneKey($phone);
        if (!preg_match('/^01[0125][0-9]{8}$/D', $key)) return null;
        $forms = [$key, substr($key, 1), '+2'.$key, '2'.$key];
        $id = DB::table('users')->whereIn('mobile', $forms)
            ->where(fn ($q) => $q->whereNull('account_type')->orWhereIn('account_type', ['user', 'customer', '']))->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    /** One entry per piece (a weighed line is one) of each running draw's products sold by this branch, once per source. */
    private function grant(int $restaurantId, array $lines, int $userId, string $source, ?int $orderId): int
    {
        $draws = array_filter(self::active(), fn ($draw) => in_array($restaurantId, $draw['restaurants'], true));
        $created = 0;
        foreach ($draws as $id => $draw) {
            $pieces = 0;
            foreach ($lines as [$product, $qty]) {
                if ($qty <= 0 || !in_array($product, $draw['products'], true)) continue;
                $pieces += max(1, (int) floor($qty + 1e-9));
            }
            for ($n = 1; $n <= min($pieces, 500); $n++) {
                $created += DB::table('coupon_subscripes')->insertOrIgnore([
                    'user_id'=>$userId, 'user_coupon_code'=>$this->code(), 'coupon_wheel_id'=>$id, 'price'=>0, 'amount'=>1,
                    'status'=>null, 'order_id'=>$orderId, 'entry_key'=>"{$source}:draw:{$id}:{$n}",
                    'created_at'=>now(), 'updated_at'=>now(),
                ]);
            }
        }
        return $created;
    }

    /** The badge's words: «هذا الصنف يرشحك للدخول على سحب بقيمة 5,000 جنيه». */
    public static function label(?float $prize): string
    {
        return $prize ? 'هذا الصنف يرشحك للدخول على سحب بقيمة '.self::money($prize).' جنيه' : 'هذا الصنف يرشحك للدخول على السحب';
    }

    /** A customer's entries in a draw. */
    public static function entries(int $wheelId, int $userId): int
    {
        if (!self::ready()) return 0;
        return DB::table('coupon_subscripes')->where('coupon_wheel_id', $wheelId)->where('user_id', $userId)->where('amount', 1)->count();
    }

    private function code(int $length = 10): string
    {
        do {
            $code = Str::upper(Str::random($length));
        } while (DB::table('coupon_subscripes')->where('user_coupon_code', $code)->exists());
        return $code;
    }

    private static function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ','), '0'), '.');
    }
}
