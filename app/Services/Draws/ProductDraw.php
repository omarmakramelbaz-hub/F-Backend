<?php

namespace App\Services\Draws;

use App\Models\Order;
use App\Support\RequestFacts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * «مسابقة السحب» by a chosen product: during the draw's dates, a product picked on the dashboard carries a badge in the
 * customer app (in every participating branch: the customer orders it from the branch nearest to them) with the prize.
 * Each piece of it in a delivered order (completed) is one entry in that draw; a weighed line (half a kilo) is one.
 * The other draws keep their own rule: an order of at least the draw's amount from a participating branch (order_total).
 */
class ProductDraw
{
    public const ORDER_TOTAL = 'order_total';
    public const PRODUCT = 'product';
    public const POLICIES = [self::ORDER_TOTAL, self::PRODUCT];

    /** The columns the product draw needs (php artisan draws:install-schema adds them). */
    public static function ready(): bool
    {
        return RequestFacts::remember('draws.product.ready', fn () => Schema::hasTable('coupon_wheels') && Schema::hasTable('coupon_subscripes')
            && Schema::hasColumns('coupon_wheels', ['policy', 'product_id'])
            && Schema::hasColumns('coupon_subscripes', ['order_id', 'entry_key']));
    }

    /** The draws running today by a product, with their branches: [id => {wheel, restaurants[]}]. */
    public static function active(): array
    {
        if (!self::ready()) return [];
        return RequestFacts::remember('draws.product.active', function () {
            $today = Carbon::now()->toDateString();
            $wheels = DB::table('coupon_wheels')->where('status', 'show')->where('policy', self::PRODUCT)->whereNotNull('product_id')
                ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->orderBy('id')->get();
            if ($wheels->isEmpty()) return [];
            $branches = DB::table('coupon_wheel_resturants')->whereIn('coupon_wheel_id', $wheels->pluck('id')->all())->get()->groupBy('coupon_wheel_id');
            $active = [];
            foreach ($wheels as $wheel) {
                $active[(int) $wheel->id] = ['wheel'=>$wheel,
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
            if ((int) $draw['wheel']->product_id !== $productId || !in_array($restaurantId, $draw['restaurants'], true)) continue;
            $prize = $draw['wheel']->prize_amount === null ? null : (float) $draw['wheel']->prize_amount;
            return ['coupon_wheel_id'=>$id, 'name'=>(string) $draw['wheel']->name, 'prize_amount'=>$prize,
                'end_date'=>(string) $draw['wheel']->end_date,
                'label'=>self::label($prize)];
        }
        return null;
    }

    /** A delivered order: one entry per piece of each running product draw's product, once however often this is called. */
    public function enter(Order $order): int
    {
        if (!self::ready() || $order->status !== 'completed' || $order->type !== 'current' || !$order->resturant_id || !$order->user_id) return 0;
        $draws = array_filter(self::active(), fn ($draw) => in_array((int) $order->resturant_id, $draw['restaurants'], true));
        if (!$draws) return 0;
        $lines = DB::table('carts')->join('resturant_products', 'resturant_products.id', '=', 'carts.resturant_product_id')
            ->where('carts.order_id', $order->id)->whereIn('resturant_products.product_id', array_map(fn ($d) => (int) $d['wheel']->product_id, $draws))
            ->get(['resturant_products.product_id', 'carts.qty']);
        $created = 0;
        foreach ($draws as $id => $draw) {
            $pieces = 0;
            foreach ($lines as $line) {
                if ((int) $line->product_id !== (int) $draw['wheel']->product_id) continue;
                $pieces += max(1, (int) floor((float) $line->qty + 1e-9));
            }
            for ($n = 1; $n <= min($pieces, 500); $n++) {
                $created += DB::table('coupon_subscripes')->insertOrIgnore([
                    'user_id'=>$order->user_id, 'user_coupon_code'=>$this->code(), 'coupon_wheel_id'=>$id, 'price'=>0, 'amount'=>1,
                    'status'=>null, 'order_id'=>$order->id, 'entry_key'=>"order:{$order->id}:draw:{$id}:{$n}",
                    'created_at'=>now(), 'updated_at'=>now(),
                ]);
            }
        }
        return $created;
    }

    /** The badge's words: «يؤهلك لسحب على 5,000 جنيه». */
    public static function label(?float $prize): string
    {
        return $prize ? 'يؤهلك لسحب على '.self::money($prize).' جنيه' : 'يؤهلك لدخول السحب';
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
