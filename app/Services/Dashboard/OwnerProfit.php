<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/**
 * Net profit for the primary owner, as he set it: sales (less the delivery service, which is the courier's) less the
 * branches' salaries, rent, expenses and packaging per order. Goods are bought through expenses, so they count there.
 * Taxes are not deducted: the restaurants are exempt. The figures are kept on the goods page.
 */
class OwnerProfit
{
    private const PACKAGING = 'packaging_order_cents';
    private const EXPENSE_RULES = 'expense_included';
    private TakeawayAccess $access;
    private ?bool $ready = null;

    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('branch_fixed_costs') && Schema::hasTable('owner_profit_settings');
    }

    public function canManage($actor): bool
    {
        return $actor && DashboardAccountRole::isOwner($this->access->actor($actor));
    }

    private function setting(string $name, $default)
    {
        $value = DB::table('owner_profit_settings')->where('name', $name)->value('payload');
        return $value === null ? $default : json_decode($value, true);
    }

    private function putSetting(string $name, $value, int $actor): void
    {
        DB::table('owner_profit_settings')->updateOrInsert(['name'=>$name],
            ['payload'=>json_encode($value, JSON_UNESCAPED_UNICODE), 'actor_id'=>$actor, 'updated_at'=>now('UTC'), 'created_at'=>now('UTC')]);
    }

    /** Expense items already counted another way: salaries and advances (salaries come from the employees' pay), rent
     * and packaging (fixed amounts), and money the branch only passed on (a paid delivery-app order, cash collected). */
    public static function excludedByDefault(string $key, string $name): bool
    {
        if (in_array($key, ['salaries', 'rent', 'packaging'], true)) return true;
        return (bool) preg_match('/سل[فه]|^\s*سل\s|رواتب|مرتب|ايجار|إيجار|تغليف|تحصيل|مدفوع\s*تطبيق/u', $name);
    }

    private function names(): array
    {
        $names = [];
        foreach (BranchExpenses::CATEGORIES as $key) $names[$key] = __('expenses.cat_'.$key);
        foreach (ExpenseStockPurchases::CATEGORIES as $key => $name) $names[$key] = 'مشتريات '.$name;
        if (Schema::hasTable('branch_expense_categories')) foreach (DB::table('branch_expense_categories')->get(['id', 'name']) as $row) $names['custom_'.$row->id] = $row->name;
        return $names;
    }

    /** Every expense item used in the last 90 days, with its last 30 days and whether it counts against profit. */
    private function expenseItems(array $rules): array
    {
        $names = $this->names(); $today = Carbon::parse(OperatingDay::date(), 'Africa/Cairo');
        $totals = DB::table('branch_expenses')->where('status', 'approved')->where('occurred_on', '>=', $today->copy()->subDays(89)->toDateString())
            ->select('category')->selectRaw('SUM(CASE WHEN occurred_on >= ? THEN amount_cents ELSE 0 END) AS month_cents', [$today->copy()->subDays(29)->toDateString()])
            ->groupBy('category')->get();
        $items = [];
        foreach ($totals as $row) {
            $key = (string) $row->category; $name = $names[$key] ?? $key; $default = !self::excludedByDefault($key, $name);
            $items[] = ['key'=>$key, 'name'=>$name, 'month'=>Money::decimal((int) $row->month_cents), 'month_cents'=>(int) $row->month_cents,
                'default_included'=>$default, 'included'=>array_key_exists($key, $rules) ? (bool) $rules[$key] : $default];
        }
        usort($items, fn ($a, $b) => $b['month_cents'] <=> $a['month_cents']);
        return $items;
    }

    private function branches($actor): array
    {
        return array_values(array_filter($this->access->branches($actor), fn ($b) => $b['kind'] === 'f'));
    }

    public function settings($actor): array
    {
        abort_unless($this->canManage($actor), 403);
        abort_unless($this->ready(), 503, 'حساب صافي الربح يحتاج تحديث قاعدة البيانات.');
        $rents = DB::table('branch_fixed_costs')->pluck('rent_monthly_cents', 'branch');
        $salaries = $this->monthlySalaries(array_column($this->branches($actor), 'value'));
        $branches = array_map(fn ($b) => ['value'=>$b['value'], 'name'=>$b['name'],
            'rent'=>isset($rents[$b['value']]) && (int) $rents[$b['value']] > 0 ? Money::decimal((int) $rents[$b['value']]) : '',
            'salaries'=>Money::decimal($salaries[$b['value']] ?? 0)], $this->branches($actor));
        $packaging = $this->setting(self::PACKAGING, null);
        return ['success'=>true, 'branches'=>$branches,
            'packaging'=>$packaging === null ? '' : Money::decimal((int) $packaging),
            'expenses'=>$this->expenseItems((array) $this->setting(self::EXPENSE_RULES, []))];
    }

    /** This month's pay of each branch's current employees, as the employees page sets it. */
    private function monthlySalaries(array $keys): array
    {
        if (!Schema::hasTable('branch_employees') || !Schema::hasTable('branch_employee_salaries')) return [];
        $month = substr(OperatingDay::date(), 0, 7); $out = [];
        $employees = DB::table('branch_employees')->whereIn('branch', $keys)->where('active', true)->get(['id', 'branch']);
        $pay = DB::table('branch_employee_salaries')->whereIn('employee_id', $employees->pluck('id'))->where('effective_month', '<=', $month)
            ->orderBy('effective_month')->get()->groupBy('employee_id');
        foreach ($employees as $e) { $last = ($pay[$e->id] ?? collect())->last(); $out[$e->branch] = ($out[$e->branch] ?? 0) + (int) ($last->amount_cents ?? 0); }
        return $out;
    }

    private function amount($value, string $field): ?int
    {
        $value = trim(strtr((string) $value, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.', ','=>'']));
        if ($value === '') return null;
        try { $cents = Money::minor($value); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages([$field=>'أدخل مبلغ صحيح بالجنيه.']); }
        if ($cents < 0 || $cents > 100000000) throw ValidationException::withMessages([$field=>'المبلغ من صفر لحد مليون جنيه.']);
        return $cents;
    }

    public function save(array $values, $actor): array
    {
        abort_unless($this->canManage($actor), 403);
        abort_unless($this->ready(), 503, 'حساب صافي الربح يحتاج تحديث قاعدة البيانات.');
        $actorId = (int) $this->access->actor($actor)->id;
        $branches = array_column($this->branches($actor), 'value');
        $rents = [];
        foreach ((array) ($values['rents'] ?? []) as $branch => $value) {
            abort_unless(in_array($branch, $branches, true), 422, 'فرع غير معروف.');
            $rents[$branch] = $this->amount($value, 'rents.'.$branch);
        }
        $packaging = array_key_exists('packaging', $values) ? $this->amount($values['packaging'], 'packaging') : false;
        $rules = null;
        if (isset($values['expenses']) && is_array($values['expenses'])) {
            $rules = [];
            foreach ($values['expenses'] as $key => $included) {
                abort_unless(is_string($key) && preg_match('/^[a-z0-9_]{1,40}$/D', $key), 422, 'بند مصروف غير صالح.');
                $rules[$key] = filter_var($included, FILTER_VALIDATE_BOOLEAN);
            }
        }
        DB::transaction(function () use ($rents, $packaging, $rules, $actorId) {
            $now = now('UTC');
            foreach ($rents as $branch => $cents) DB::table('branch_fixed_costs')->updateOrInsert(['branch'=>$branch], ['rent_monthly_cents'=>$cents ?? 0, 'actor_id'=>$actorId, 'updated_at'=>$now, 'created_at'=>$now]);
            if ($packaging !== false) {
                if ($packaging === null) DB::table('owner_profit_settings')->where('name', self::PACKAGING)->delete();
                else $this->putSetting(self::PACKAGING, $packaging, $actorId);
            }
            if ($rules !== null) $this->putSetting(self::EXPENSE_RULES, $rules, $actorId);
        });
        return $this->settings($actor);
    }

    /**
     * Profit for the given branches and operating days. $sales holds, per branch key, the period's gross, delivery and
     * order count exactly as the home overview counted them, so profit and the sales figure always agree.
     */
    public function compute(array $sales, string $fromDate, string $toDate): ?array
    {
        if (!$this->ready()) return null;
        $keys = array_keys($sales);
        $empty = ['gross_cents'=>0, 'delivery_cents'=>0, 'orders'=>0, 'salaries_cents'=>0, 'rent_cents'=>0,
            'packaging_cents'=>0, 'expenses_cents'=>0, 'net_cents'=>0];
        $rows = [];
        foreach ($sales as $key => $s) $rows[$key] = ['gross_cents'=>(int) $s['gross_cents'], 'delivery_cents'=>(int) $s['delivery_cents'], 'orders'=>(int) $s['count']] + $empty;

        // Salaries and rent: each month's amount spread over its days.
        $months = [];
        for ($day = Carbon::parse($fromDate, 'Africa/Cairo'); $day->toDateString() <= $toDate; $day->addDay()) $months[$day->format('Y-m')][] = $day->toDateString();
        $rents = DB::table('branch_fixed_costs')->whereIn('branch', $keys)->pluck('rent_monthly_cents', 'branch');
        $employees = Schema::hasTable('branch_employees') ? DB::table('branch_employees')->whereIn('branch', $keys)->get(['id', 'branch', 'hired_on', 'left_on', 'active']) : collect();
        $salaries = Schema::hasTable('branch_employee_salaries') && $employees->count()
            ? DB::table('branch_employee_salaries')->whereIn('employee_id', $employees->pluck('id'))->orderBy('effective_month')->get()->groupBy('employee_id') : collect();
        foreach ($months as $month => $days) {
            $length = Carbon::parse($month.'-01', 'Africa/Cairo')->daysInMonth; $count = count($days);
            foreach ($keys as $key) $rows[$key]['rent_cents'] += intdiv((int) ($rents[$key] ?? 0) * $count, $length);
            foreach ($employees as $e) {
                if (!$e->active && !$e->left_on) continue;
                $worked = count(array_filter($days, fn ($d) => $d >= $e->hired_on && (!$e->left_on || $d <= $e->left_on)));
                if (!$worked) continue;
                $salary = 0;
                foreach ($salaries[$e->id] ?? [] as $s) if ($s->effective_month <= $month) $salary = (int) $s->amount_cents;
                $rows[$e->branch]['salaries_cents'] += intdiv($salary * $worked, $length);
            }
        }

        // Packaging per order, and the expense items that count.
        $packaging = (int) $this->setting(self::PACKAGING, 0);
        $rules = (array) $this->setting(self::EXPENSE_RULES, []);
        $names = $this->names();
        $expenses = DB::table('branch_expenses')->whereIn('branch', $keys)->where('status', 'approved')
            ->whereBetween('occurred_on', [$fromDate, $toDate])->select('branch', 'category')->selectRaw('SUM(amount_cents) AS amount')->groupBy('branch', 'category')->get();
        foreach ($expenses as $x) {
            $key = (string) $x->category;
            $counts = array_key_exists($key, $rules) ? (bool) $rules[$key] : !self::excludedByDefault($key, $names[$key] ?? '');
            if ($counts) $rows[$x->branch]['expenses_cents'] += (int) $x->amount;
        }
        $total = $empty;
        foreach ($rows as &$r) {
            $r['packaging_cents'] = $r['orders'] * $packaging;
            $r['net_cents'] = $r['gross_cents'] - $r['delivery_cents'] - $r['salaries_cents'] - $r['rent_cents'] - $r['packaging_cents'] - $r['expenses_cents'];
            foreach ($empty as $field => $_) $total[$field] += $r[$field];
        }
        unset($r);
        return ['ready'=>true, 'total'=>$total, 'branches'=>$rows, 'packaging_set'=>$packaging > 0,
            'rent_set'=>$rents->filter(fn ($v) => (int) $v > 0)->count() > 0];
    }

    /**
     * Goods a branch will run out of soon: its balance against what it sold a day on average over the last 7 days.
     * @return array<int,array{branch:string,name:string,days:float,unit:string}>
     */
    public function forecasts(array $keys, int $withinDays = 3): array
    {
        if (!Schema::hasTable('branch_inventory_movements') || !Schema::hasTable('branch_inventory')) return [];
        $since = OperatingDay::start(Carbon::parse(OperatingDay::date(), 'Africa/Cairo')->subDays(6)->toDateString())->utc()->toDateTimeString();
        $rates = DB::table('branch_inventory_movements')->whereIn('branch', $keys)->whereIn('source_type', ['pos', 'app'])->where('created_at', '>=', $since)
            ->select('branch', 'ingredient_id')->selectRaw('-SUM(quantity_units) AS units')->groupBy('branch', 'ingredient_id')->get();
        $stock = DB::table('branch_inventory')->whereIn('branch', $keys)->get()->keyBy(fn ($r) => $r->branch.'|'.$r->ingredient_id);
        $items = DB::table('stock_ingredients')->get()->keyBy('id');
        $out = [];
        foreach ($rates as $r) {
            $daily = (int) $r->units / 7; $row = $stock[$r->branch.'|'.$r->ingredient_id] ?? null; $item = $items[$r->ingredient_id] ?? null;
            if ($daily <= 0 || !$row || !$item || (int) $row->quantity_units <= 0) continue;
            $days = (int) $row->quantity_units / $daily;
            if ($days <= $withinDays) $out[] = ['branch'=>$r->branch, 'name'=>$item->name, 'days'=>round($days, 1), 'unit'=>$item->unit];
        }
        usort($out, fn ($a, $b) => $a['days'] <=> $b['days']);
        return array_slice($out, 0, 6);
    }
}
