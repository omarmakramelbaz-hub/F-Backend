<?php

namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * A customer's orders by his phone number, in the branches the account works with: the paid ones of every kind (the
 * customer directory's purchases: takeaway, dine-in, delivery and app orders) and the delivery and dine-in orders not
 * collected yet, newest first, with how many and how much.
 */
class CustomerOrderHistory
{
    private const CHANNELS = ['takeaway'=>'تيك أواي', 'dine'=>'صالة', 'phone'=>'دليفري', 'delivery'=>'دليفري', 'app'=>'تطبيق'];

    public function __construct(private TakeawayAccess $access) {}

    public function history(array $values, $actor): array
    {
        $v = Validator::make($values, ['phone'=>'required|string|max:30'])->validate();
        $key = BranchCustomers::phoneKey($v['phone']);
        abort_unless(preg_match('/^0?1[0125][0-9]{8}$|^\+?[0-9]{6,20}$/D', $key), 422, 'اكتب رقم موبايل صحيح.');
        $names = array_column($this->access->branches($actor), 'name', 'value');
        // Shubra prepares its stores' app orders: they are its customers' orders too.
        if (isset($names['f:'.FasakhanstaStoreFulfillment::RESTAURANT_ID])) foreach (FasakhanstaStoreFulfillment::sourceRestaurantIdsForBranch(FasakhanstaStoreFulfillment::RESTAURANT_ID) as $id) $names['f:'.$id] ??= $names['f:'.FasakhanstaStoreFulfillment::RESTAURANT_ID];
        $orders = []; $customer = null;
        if (Schema::hasTable('crm_customers') && Schema::hasTable('crm_purchases')) {
            $customer = DB::table('crm_customers')->where('phone_key', CustomerDirectory::phoneKey($v['phone']) ?: $key)->first();
            if ($customer) {
                $purchases = DB::table('crm_purchases')->where('customer_id', $customer->id)->whereIn('branch', array_keys($names))
                    ->where('feedback_status', '!=', 'cancelled')->orderByDesc('paid_at')->limit(60)->get();
                $receipts = DB::table('takeaway_orders')->whereIn('id', $purchases->where('source_type', 'takeaway_orders')->pluck('source_id')->map(fn ($id) => (int) $id))->get()->keyBy('id');
                $apps = Schema::hasTable('orders') ? DB::table('orders')->whereIn('id', $purchases->where('source_type', 'app_order')->pluck('source_id')->map(fn ($id) => (int) $id))->get()->keyBy('id') : collect();
                foreach ($purchases as $p) {
                    $receipt = $p->source_type === 'takeaway_orders' ? ($receipts[(int) $p->source_id] ?? null) : null;
                    $app = $p->source_type === 'app_order' ? ($apps[(int) $p->source_id] ?? null) : null;
                    $channel = $receipt->channel ?? $p->channel;
                    $orders[] = ['at'=>$this->time($p->paid_at), 'branch'=>$names[$p->branch] ?? $p->branch, 'channel'=>self::CHANNELS[$channel] ?? $channel,
                        'number'=>$app ? ($app->order_no ?? '#'.$app->id) : ($receipt ? 'R-'.$receipt->id : $p->source_id),
                        'total'=>$receipt ? Money::decimal((int) $receipt->total_cents) : ($app && isset($app->total_price) ? number_format((float) $app->total_price, 2, '.', '') : null),
                        'status'=>'مدفوع',
                        'details_url'=>$receipt ? $this->link('takeaway.details', ['id'=>$receipt->id]) : ($app ? $this->link('order-board.details', ['source'=>'legacy', 'id'=>$app->id]) : null)];
                }
            }
        }
        // Not collected yet: delivery and dine-in orders taking his number.
        if (Schema::hasTable('pos_service_tickets')) {
            foreach (DB::table('pos_service_tickets')->where('phone_key', $key)->whereIn('branch', array_keys($names))->where('payment_status', 'unpaid')
                ->where('status', '!=', 'cancelled')->orderByDesc('id')->limit(20)->get() as $t) {
                $quote = json_decode((string) ($t->quote_snapshot ?? ''), true) ?: [];
                $orders[] = ['at'=>$this->time($t->created_at), 'branch'=>$names[$t->branch] ?? $t->branch, 'channel'=>self::CHANNELS[$t->channel] ?? $t->channel,
                    'number'=>!empty($t->shift_order_number) ? BranchOrderSequence::display($t, $t->branch, $t->channel, (string) $t->id) : '#'.$t->id,
                    'total'=>$quote['total'] ?? null, 'status'=>'جاري',
                    'details_url'=>$this->link($t->channel === 'dine' ? 'dining.details' : 'phone-orders.details', ['id'=>$t->id])];
            }
        }
        usort($orders, fn ($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));
        $paid = array_filter($orders, fn ($o) => $o['status'] === 'مدفوع' && $o['total'] !== null);
        return ['success'=>true, 'phone'=>$key, 'name'=>$customer->name ?? null, 'orders'=>$orders,
            'summary'=>['count'=>count($orders), 'paid_total'=>number_format(array_sum(array_map(fn ($o) => (float) $o['total'], $paid)), 2, '.', ''),
                'last'=>$orders[0]['at'] ?? null]];
    }

    /** The order's invoice as the invoice review opens it (a preview: viewing never counts as printing). */
    private function link(string $route, array $parameters): ?string
    {
        return \Illuminate\Support\Facades\Route::has($route) ? route($route, $parameters, false) : null;
    }

    private function time($value): ?string
    {
        if (!$value) return null;
        try { return Carbon::parse($value, 'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i'); } catch (\Throwable $error) { return null; }
    }
}
