<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{Cache, DB, Schema};

/**
 * Actor-scoped, lifetime read counters for the live opening. The only amount is the primary owner's: the branches'
 * drawer balance on the shift-closing card, exactly as the home page's own drawers show it to that account.
 */
class HomeHologramMetrics
{
    private TakeawayAccess $access;
    private array $columns = [];

    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    private function has(string $table, array $columns): bool
    {
        if (!array_key_exists($table, $this->columns)) {
            $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        }
        return !array_diff($columns, $this->columns[$table]);
    }

    public function data($actor, ?array $links = null): array
    {
        $actor = $this->access->actor($actor);
        $branches = array_values(array_filter($this->access->branches($actor), fn ($branch) => $branch['kind'] === 'f'));
        $keys = array_column($branches, 'value');
        $ids = array_column($branches, 'id');
        $central = $actor->account_type === 'admin' && empty($actor->owner_resturant_id);
        $aggregate = $central || $actor->account_type === 'resturant_owner';
        $links = $links ?? HomeHudLinks::for($actor);
        $specs = [
            'employees'=>['الموظفين والرواتب', 'موظف'],
            'customers'=>['العملاء', 'عميل'],
            'expenses'=>['المصروفات', 'مصروف معتمد'],
            'drawer'=>['تقفيل الوردية', 'فروع للتقفيل'],
            'stock'=>['مخزون البضاعة', 'صنف'],
            'ch-app'=>['طلبات التطبيق', 'في التجهيز اليوم'],
            'ch-takeaway'=>['تيك أواي', 'إجمالي الطلبات'],
            'ch-phone'=>['دليفري', 'في التجهيز اليوم'],
            'ch-dine'=>['صالة', 'في التجهيز اليوم'],
            'pending-expenses'=>['مصروفات منتظرة', 'بانتظار الاعتماد'],
            'drawers'=>['رصيد أدراج الفروع', 'جنيه'],
        ];
        if (isset($links['expenses'])) $links['pending-expenses'] = $links['expenses'];
        // The primary owner: the branches' drawer balance in place of the pending expenses; it opens the balance of
        // each branch's drawer, on the home page itself.
        $balance = $this->ownerDrawerBalance($actor);
        if ($balance !== null && isset($links['pending-expenses'])) {
            unset($links['pending-expenses']);
            $links['drawers'] = (\Illuminate\Support\Facades\Route::has('admin_dash') ? route('admin_dash') : url('admin/dashboard')).'#branch-drawers';
        }
        $cards = [];
        foreach ($specs as $key => [$label, $countLabel]) {
            if (!isset($links[$key])) continue;
            $cards[$key] = ['key'=>$key, 'label'=>$label, 'url'=>$links[$key],
                'count'=>null, 'count_label'=>$countLabel, 'secondary_count'=>null,
                'secondary_label'=>null, 'warning'=>false, 'available'=>false];
        }
        $set = function (string $key, ?int $count, ?int $secondary = null, ?string $secondaryLabel = null) use (&$cards) {
            if (!isset($cards[$key])) return;
            $cards[$key]['count'] = $count;
            $cards[$key]['available'] = $count !== null;
            $cards[$key]['secondary_count'] = $secondary;
            $cards[$key]['secondary_label'] = $secondaryLabel;
        };
        // Delivery, dine-in and app: today's orders (the operating day) being prepared, and those finished today; every
        // branch for the owner and the admins, the branch's own for a branch account.
        $today = OperatingDay::date();
        $finished = 'منتهية اليوم'.($aggregate ? ' · جميع الفروع' : '');
        if (isset($cards['ch-app'])) {
            $app = app(OrderBoardService::class)->hologramCounts($actor, $ids, $today);
            $set('ch-app', $app['open'], $app['completed'], $finished);
        }
        if (isset($cards['ch-takeaway']) && $this->has('takeaway_orders', ['id', 'branch', 'channel', 'payment_confirmed'])) {
            $receipts = DB::table('takeaway_orders')->whereIn('branch', $keys)->where('channel', 'takeaway')->where('payment_confirmed', true);
            // These rows are completed POS receipts, not an open service-ticket draft.
            foreach (['status', 'payment_status', 'refund_status'] as $column) {
                if ($this->has('takeaway_orders', [$column])) $receipts->where(function ($query) use ($column) {
                    $query->whereNull($column)->orWhereNotIn($column, ['cancelled', 'canceled', 'failed', 'refunded', 'refund_pending', 'reversed']);
                });
            }
            $set('ch-takeaway', $receipts->count());
        }
        if ($this->has('pos_service_tickets', ['id', 'branch', 'channel', 'payment_status', 'status'])) {
            foreach (['ch-phone'=>'phone', 'ch-dine'=>'dine'] as $key => $channel) {
                if (!isset($cards[$key])) continue;
                $tickets = DB::table('pos_service_tickets')->whereIn('branch', $keys)->where('channel', $channel);
                if ($this->has('pos_service_tickets', ['business_date'])) $tickets->where('business_date', $today);
                else $tickets->where('created_at', '>=', OperatingDay::start($today)->setTimezone('UTC')->toDateTimeString())
                    ->where('created_at', '<', OperatingDay::end($today)->setTimezone('UTC')->toDateTimeString());
                // Website checkouts already appear under application orders.
                if ($channel === 'phone') $this->excludeWebsiteTickets($tickets);
                $open = (clone $tickets)->where('payment_status', 'unpaid')->whereNotIn('status', ['cancelled', 'paid'])->count();
                $set($key, $open, (clone $tickets)->where('payment_status', 'paid')->where('status', '!=', 'cancelled')->count(), $finished);
            }
        }
        $expenseFrom = [];
        if ($this->has('branch_expenses', ['id', 'branch', 'status'])) {
            $expenses = DB::table('branch_expenses')->whereIn('branch', $keys);
            $pending = (clone $expenses)->where('status', 'pending')->count();
            $set('expenses', (clone $expenses)->where('status', 'approved')->count(), $pending, 'بانتظار الاعتماد');
            $set('pending-expenses', $pending);
            if (isset($cards['pending-expenses'])) $cards['pending-expenses']['warning'] = $pending > 0;
            if ($this->has('branch_expenses', ['occurred_on'])) {
                $expenseFrom['expenses'] = (clone $expenses)->min('occurred_on');
                $expenseFrom['pending-expenses'] = (clone $expenses)->where('status', 'pending')->min('occurred_on');
            }
        }
        if ($this->has('branch_employees', ['id', 'branch'])) {
            $set('employees', DB::table('branch_employees')->whereIn('branch', $keys)->count());
        }
        // Mirror the existing shared customer directory. A branch may read all
        // directory identities, while edits and source badges remain scoped.
        if ($keys && $this->has('crm_customers', ['id']) && $this->has('crm_customer_sources', ['customer_id', 'branch'])) {
            $set('customers', DB::table('crm_customers')->count());
        }
        if (isset($cards['stock'])) {
            [$stock, $low, $occurrences] = $this->stock($keys, $ids);
            $set('stock', $stock, $low, 'وصلت للحد الأدنى');
            $cards['stock']['warning'] = $low !== null && $low > 0;
            $cards['stock']['low_stock_branches'] = $occurrences;
        }
        if (isset($cards['drawer']) && $this->has('branch_shift_closings', ['branch'])) $set('drawer', count($keys));
        if (isset($cards['drawers'])) $set('drawers', $balance, count($keys), 'فرع');
        foreach ($cards as &$card) {
            if ($card['key'] === 'drawers') continue; // the home page itself
            // Opening links use the account's branch scope, never a stale home
            // filter belonging to another account in the same browser session.
            $query = [];
            if (count($keys) === 1) $query['branch'] = $keys[0];
            elseif ($central && in_array($card['key'], ['expenses', 'pending-expenses', 'stock', 'drawer', 'employees', 'customers'], true)) $query['branch'] = 'all';
            if ($card['key'] === 'pending-expenses') $query['status'] = 'pending';
            if (!empty($expenseFrom[$card['key']])) {
                $query['from'] = $expenseFrom[$card['key']];
                $query['to'] = OperatingDay::date();
            }
            if ($query) $card['url'] .= (strpos($card['url'], '?') === false ? '?' : '&').http_build_query($query);
        }
        unset($card);
        return ['cards'=>array_values($cards), 'scope'=>$aggregate ? 'all' : 'branch',
            'scope_label'=>$aggregate ? 'جميع الفروع المصرّح بها' : implode('، ', array_column($branches, 'name')),
            'branch_count'=>count($keys), 'updated_at'=>now('Africa/Cairo')->toIso8601String()];
    }

    /**
     * The branches' drawer balance in whole pounds for the primary owner (HomeOverview::drawers, the home page's own
     * drawers), or null for any other account or when it cannot be read. Reconstructing every shift is costly, so the
     * opening (asked every 15 seconds) reuses one reading for a minute.
     */
    private function ownerDrawerBalance($actor): ?int
    {
        if ((int) $actor->id !== 1 || $actor->account_type !== 'admin' || !empty($actor->owner_resturant_id)) return null;
        try {
            $cents = Cache::remember('home-hologram-owner-drawers:'.(int) $actor->id, 60, function () use ($actor) {
                $drawer = app(HomeOverview::class)->drawers([], $actor)['owner_drawer'] ?? null;
                return is_array($drawer) && !empty($drawer['ready']) && is_numeric($drawer['total_cents'] ?? null) ? (int) $drawer['total_cents'] : 'none';
            });
        } catch (\Throwable $error) {
            return null;
        }
        return is_int($cents) ? (int) round($cents / 100) : null;
    }

    private function excludeWebsiteTickets($query): void
    {
        if (!$this->has('web_storefront_orders', ['ticket_id', 'branch_id', 'created_at'])) return;
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "('f:' || hologram_web.branch_id)" : "CONCAT('f:', hologram_web.branch_id)";
        $query->whereNotExists(function ($receipt) use ($expression) {
            $receipt->selectRaw('1')->from('web_storefront_orders as hologram_web')
                ->whereColumn('hologram_web.ticket_id', 'pos_service_tickets.id')
                ->whereColumn('hologram_web.created_at', '>=', 'pos_service_tickets.created_at')
                ->whereRaw('pos_service_tickets.branch = '.$expression);
        });
    }

    /** Count goods once across branches; a healthy branch never cancels another's alert. */
    private function stock(array $keys, array $ids): array
    {
        $raw = $this->has('stock_ingredients', ['id', 'unit']) && $this->has('branch_inventory', ['branch', 'ingredient_id', 'quantity_units']);
        $direct = $this->has('branch_stock', ['branch', 'product_id', 'unit', 'quantity_units'])
            && $this->has('resturant_products', ['id', 'resturant_id']);
        if (!$raw && !$direct) return [null, null, null];
        $count = $raw && $keys ? DB::table('stock_ingredients')->count() : 0;
        $directQuery = $direct ? DB::table('branch_stock as stock')->join('resturant_products as product', function ($join) {
            $join->on('product.id', '=', 'stock.product_id');
        })->whereIn('stock.branch', $keys)->whereIn('product.resturant_id', $ids) : null;
        if ($directQuery) $directQuery->whereRaw('stock.branch = '.(DB::connection()->getDriverName() === 'sqlite'
            ? "('f:' || product.resturant_id)" : "CONCAT('f:', product.resturant_id)"));
        if ($directQuery) $count += (clone $directQuery)->distinct()->count('stock.product_id');
        if (!$this->has('branch_stock_minimums', ['branch', 'stock_type', 'stock_item_id', 'unit', 'minimum_units'])) return [$count, null, null];
        $lowItems = []; $occurrences = 0;
        if ($raw) {
            $minimums = DB::table('branch_stock_minimums as minimum')->join('stock_ingredients as item', function ($join) {
                $join->on('item.id', '=', 'minimum.stock_item_id')->on('item.unit', '=', 'minimum.unit');
            })->leftJoin('branch_inventory as stock', function ($join) {
                $join->on('stock.branch', '=', 'minimum.branch')->on('stock.ingredient_id', '=', 'minimum.stock_item_id');
            })->whereIn('minimum.branch', $keys)->where('minimum.stock_type', 'ingredient')->whereNotNull('minimum.minimum_units')
                ->whereRaw('COALESCE(stock.quantity_units, 0) <= minimum.minimum_units')->get(['minimum.stock_item_id']);
            foreach ($minimums as $row) { $lowItems['ingredient:'.$row->stock_item_id] = true; $occurrences++; }
        }
        if ($directQuery) {
            $low = (clone $directQuery)->join('branch_stock_minimums as minimum', function ($join) {
                $join->on('minimum.branch', '=', 'stock.branch')->on('minimum.stock_item_id', '=', 'stock.product_id')
                    ->on('minimum.unit', '=', 'stock.unit')->where('minimum.stock_type', 'product');
            })->whereNotNull('minimum.minimum_units')->whereColumn('stock.quantity_units', '<=', 'minimum.minimum_units')->get(['stock.product_id']);
            foreach ($low as $row) { $lowItems['product:'.$row->product_id] = true; $occurrences++; }
        }
        return [$count, count($lowItems), $occurrences];
    }
}
