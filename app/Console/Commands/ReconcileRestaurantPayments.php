<?php

namespace App\Console\Commands;

use App\Services\Payments\RestaurantOrderPayment;
use App\Services\Payments\RestaurantPaymentEffects;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bounded recovery for restaurant intentions whose customer return was lost. */
class ReconcileRestaurantPayments extends Command
{
    protected $signature = 'order-payments:reconcile {--apply} {--limit=5} {--order-id=*}';
    protected $description = 'Verify unresolved restaurant Paymob attempts and activate only confirmed payments.';

    public function handle(RestaurantOrderPayment $payments, RestaurantPaymentEffects $effects): int
    {
        if (config('desktop_dashboard.local', false)) return $this->refuse('LOCAL_DEVICE');
        $limit = (string) $this->option('limit');
        if (!preg_match('/\A[1-9][0-9]?\z/', $limit) || (int) $limit > 20) return $this->refuse('INVALID_LIMIT');
        $ids = $this->option('order-id');
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $id)) return $this->refuse('INVALID_ORDER_ID');
        }
        if (!$this->ready()) return $this->refuse('SCHEMA_REQUIRED');
        $apply = (bool) $this->option('apply');
        if ($apply && (!is_string(config('wallet_topups.api_key')) || trim(config('wallet_topups.api_key')) === ''
            || config('wallet_topups.live') !== true)) return $this->refuse('CONFIG_REQUIRED');

        $query = DB::table('payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.type', 'current')->whereNull('o.status')->whereIn('o.payment_type', ['online', 'v_cash'])
            ->whereColumn('p.user_id', 'o.user_id')->where('p.total_price', '>', 0)->whereNotNull('p.intention_order_id');
        if (Schema::hasColumn('orders', 'accepted_notify')) $query->where(function ($q) {
            $q->whereNull('o.accepted_notify')->orWhere('o.accepted_notify', '!=', 'yes');
        });
        if (Schema::hasColumn('orders', 'transfer_price_by')) $query->whereNull('o.transfer_price_by');
        if ($ids) $query->whereIn('o.id', $ids);

        $connection = DB::connection();
        $cursorKey = 'restaurant-payments:cursor:'.hash('sha256', $connection->getName().':'.$connection->getDatabaseName());
        $cursor = $apply && !$ids ? Cache::get($cursorKey, 0) : 0;
        $cursor = is_numeric($cursor) && (int) $cursor > 0 ? (int) $cursor : 0;
        $read = function ($before) use ($query) {
            $batch = clone $query;
            if ($before > 0) $batch->where('p.id', '<', $before);
            return $batch->orderByDesc('p.id')->limit(1000)
                ->get(['p.id', 'p.order_id', 'p.intention_order_id', 'o.payment_type']);
        };
        $rows = $read($cursor);
        if ($cursor && $rows->isEmpty()) $rows = $read(0);
        $selected = []; $seen = []; $last = 0;
        foreach ($rows as $row) {
            $last = (int) $row->id;
            if (isset($seen[$row->order_id]) || !preg_match('/\A[1-9][0-9]{0,29}\z/', (string) $row->intention_order_id)) continue;
            $seen[$row->order_id] = true;
            $selected[] = $row;
            if (count($selected) >= (int) $limit) break;
        }
        $counts = ['mode' => $apply ? 'apply' : 'check', 'candidates' => count($selected), 'checked' => 0,
            'activated' => 0, 'unchanged' => 0, 'closed' => 0, 'deferred' => 0, 'effects_deferred' => 0];
        if (!$apply) {
            $this->line('RESTAURANT_PAYMENT_RECONCILIATION '.json_encode($counts));
            return 0;
        }
        foreach ($selected as $payment) {
            $integration = config('dashboard_payments.integrations.'.$payment->payment_type);
            if ((!is_string($integration) && !is_int($integration)) || !preg_match('/\A[1-9][0-9]{0,29}\z/', (string) $integration)) {
                $counts['deferred']++;
                continue;
            }
            $counts['checked']++;
            try {
                // Inquiry and effects are both outside a database transaction.
                $result = $payments->settle((int) $payment->id);
                $state = $result['state'] ?? '';
                if (!in_array($state, ['activated', 'unchanged', 'closed'], true)) throw new \RuntimeException('UNEXPECTED_RESULT');
                $counts[$state]++;
            } catch (\Throwable $error) {
                $counts['deferred']++;
                continue;
            }
            if ($state === 'activated') {
                try { $effects->afterActivation($result); }
                catch (\Throwable $error) { $counts['effects_deferred']++; }
            }
        }
        // Rotate across unresolved attempts, including older retries for an order.
        // Only an applying run advances the cursor; read-only checks never do.
        if (!$ids) Cache::put($cursorKey, $last, now()->addDay());
        $this->line('RESTAURANT_PAYMENT_RECONCILIATION '.json_encode($counts));
        return 0;
    }

    private function ready(): bool
    {
        foreach (['orders' => ['id', 'user_id', 'resturant_id', 'type', 'status', 'payment_type'],
            'payments' => ['id', 'user_id', 'order_id', 'intention_order_id', 'total_price', 'status', 'transaction_id']] as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumns($table, $columns)) return false;
        }
        return true;
    }

    private function refuse(string $reason): int
    {
        $this->error('RESTAURANT_PAYMENT_RECONCILIATION_'.$reason);
        return 1;
    }
}
