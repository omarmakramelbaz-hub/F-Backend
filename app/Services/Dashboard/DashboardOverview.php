<?php

namespace App\Services\Dashboard;

use App\Services\Erp\Actor;
use App\Services\Erp\UnifiedOrders;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardOverview
{
    public function __construct(private UnifiedOrders $orders)
    {
    }

    public function build(
        Actor $actor,
        ?int $branchId,
        string $day,
        string $period = 'day',
        ?string $from = null,
        ?string $to = null
    ): array {
        [$start, $end, $period] = $this->resolveRange($day, $period, $from, $to);
        $duration = $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($duration - 1);

        $counts = $this->countsForRange($actor, $branchId, $start, $end);
        $previousCounts = $this->countsForRange($actor, $branchId, $previousStart, $previousEnd);

        $sales = $this->salesForRange($actor, $branchId, $start, $end);
        $previousSales = $this->salesForRange($actor, $branchId, $previousStart, $previousEnd);

        $periodLabel = [
            'day' => 'اليوم',
            'week' => 'الأسبوع',
            'month' => 'الشهر',
            'custom' => 'الفترة',
        ][$period] ?? 'الفترة';

        $comparisonLabel = $period === 'day' ? 'مقارنة بالأمس' : 'مقارنة بالفترة السابقة';

        $kpis = [
            ['key'=>'total','label'=>'إجمالي طلبات '.$periodLabel,'value'=>$counts['total'],'icon'=>'clipboard','tone'=>'orange','change'=>$this->change($counts['total'],$previousCounts['total'])],
            ['key'=>'new','label'=>'الطلبات الجديدة','value'=>$counts['new'],'icon'=>'cart','tone'=>'blue','change'=>$this->change($counts['new'],$previousCounts['new'])],
            ['key'=>'preparing','label'=>'قيد التجهيز','value'=>$counts['preparing'],'icon'=>'chef','tone'=>'orange','change'=>$this->change($counts['preparing'],$previousCounts['preparing'])],
            ['key'=>'delivery','label'=>'مع المندوب','value'=>$counts['delivery'],'icon'=>'truck','tone'=>'purple','change'=>$this->change($counts['delivery'],$previousCounts['delivery'])],
            ['key'=>'done','label'=>'تم التسليم','value'=>$counts['done'],'icon'=>'check','tone'=>'green','change'=>$this->change($counts['done'],$previousCounts['done'])],
            ['key'=>'sales','label'=>'مبيعات '.$periodLabel,'value'=>$sales,'money'=>true,'icon'=>'money','tone'=>'blue','change'=>$this->change($sales,$previousSales)],
        ];

        // Live operations always reflect today so the operational board remains live,
        // while reports/events follow the selected reporting period.
        $liveDay = Carbon::now(config('erp.timezone'))->startOfDay();
        $liveBoard = $this->orders->dashboard($actor, $branchId, $liveDay->format('Y-m-d'), []);
        $liveCounts = $this->stageCounts($liveBoard['columns']);
        $live = [
            'new' => collect($liveBoard['columns']['new']['rows'] ?? [])
                ->concat($liveBoard['columns']['attention']['rows'] ?? [])
                ->sortByDesc('sort_ts')->take(3)->values()->all(),
            'preparing' => array_slice($liveBoard['columns']['preparing']['rows'] ?? [], 0, 3),
            'delivery' => array_slice($liveBoard['columns']['delivery']['rows'] ?? [], 0, 3),
            'done' => array_slice($liveBoard['columns']['done']['rows'] ?? [], 0, 3),
        ];

        $recentRows = $this->recentRowsForRange($actor, $branchId, $start, $end, 12);
        $recent = $recentRows->take(5)->values()->all();
        $events = $this->events($actor, $branchId, $start, $end, $recentRows);

        $chart = $this->chartForRange($actor, $branchId, $start, $end, $period);
        $totalOrders = max(1, $counts['total']);

        $quickReports = $this->quickReports($actor, $branchId, $end);

        return [
            'kpis' => $kpis,
            'counts' => $counts,
            'live_counts' => $liveCounts,
            'live' => $live,
            'recent' => $recent,
            'events' => $events,
            'chart' => $chart,
            'sales_period' => $sales,
            'average_order' => round($sales / $totalOrders, 2),
            'total_period' => $counts['total'],
            'quick_reports' => $quickReports,
            'period' => [
                'key' => $period,
                'label' => $periodLabel,
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
                'display' => $this->rangeDisplay($start, $end),
            ],
            'comparison_label' => $comparisonLabel,
            'version' => sha1(json_encode([
                $liveBoard['version'] ?? '',
                $period,
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
                $counts,
                $sales,
            ], JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function resolveRange(string $day, string $period, ?string $from, ?string $to): array
    {
        $timezone = config('erp.timezone');
        $anchor = Carbon::createFromFormat('!Y-m-d', $day, $timezone)->startOfDay();
        $period = in_array($period, ['day','week','month','custom'], true) ? $period : 'day';

        if ($period === 'custom') {
            try {
                $start = $from ? Carbon::createFromFormat('!Y-m-d', $from, $timezone)->startOfDay() : $anchor->copy();
                $end = $to ? Carbon::createFromFormat('!Y-m-d', $to, $timezone)->startOfDay() : $anchor->copy();
            } catch (\Throwable) {
                $start = $anchor->copy();
                $end = $anchor->copy();
            }

            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }

            // One year keeps custom reports useful while protecting the dashboard
            // from accidental multi-year aggregation on every page load.
            if ($start->diffInDays($end) > 366) {
                $start = $end->copy()->subDays(366);
            }

            return [$start, $end, $period];
        }

        if ($period === 'week') {
            return [$anchor->copy()->subDays(6), $anchor->copy(), $period];
        }

        if ($period === 'month') {
            return [$anchor->copy()->startOfMonth(), $anchor->copy(), $period];
        }

        return [$anchor->copy(), $anchor->copy(), 'day'];
    }

    private function rangeDisplay(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->locale('ar')->translatedFormat('j F Y');
        }

        return $start->locale('ar')->translatedFormat('j M').' — '.$end->locale('ar')->translatedFormat('j M Y');
    }

    private function stageCounts(array $columns): array
    {
        $new = (int) ($columns['new']['count'] ?? 0) + (int) ($columns['attention']['count'] ?? 0);
        $preparing = (int) ($columns['preparing']['count'] ?? 0);
        $delivery = (int) ($columns['delivery']['count'] ?? 0);
        $done = (int) ($columns['done']['count'] ?? 0);

        return compact('new','preparing','delivery','done') + ['total'=>$new+$preparing+$delivery+$done];
    }

    private function branchRestaurantIds(Actor $actor, ?int $branchId): Collection
    {
        $query = $actor->scope(DB::table('erp_branches'), 'id')->where('active', true);

        if ($branchId !== null) {
            $actor->branch($branchId, true);
            $query->where('id', $branchId);
        }

        return $query->pluck('restaurant_id')->map(fn ($id) => (int) $id)->values();
    }

    private function utcBounds(Carbon $start, Carbon $end): array
    {
        return [
            $start->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC')),
            $end->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone', 'UTC')),
        ];
    }

    private function countsForRange(Actor $actor, ?int $branchId, Carbon $start, Carbon $end): array
    {
        [$from, $until] = $this->utcBounds($start, $end);
        $restaurantIds = $this->branchRestaurantIds($actor, $branchId);
        $counts = ['new'=>0,'preparing'=>0,'delivery'=>0,'done'=>0];

        if ($restaurantIds->isNotEmpty()) {
            $base = DB::table('orders')
                ->whereIn('resturant_id', $restaurantIds)
                ->whereNotNull('status')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $until);

            if (Schema::hasColumn('orders', 'type')) {
                $base->where('type', 'current');
            }

            $counts['new'] += (clone $base)->whereIn('status', ['pending','another_delegate'])->count();
            $counts['preparing'] += (clone $base)->whereIn('status', ['accepted','new_order'])->count();
            $counts['delivery'] += (clone $base)->where('status', 'shipped')->count();
            $counts['done'] += (clone $base)->whereIn('status', ['completed','cancelled','declined'])->count();
        }

        if ($actor->allBranches() && $branchId === null) {
            if (Schema::hasTable('go_store_orders')) {
                $base = DB::table('go_store_orders')->where('created_at', '>=', $from)
                ->where('created_at', '<', $until);
                $attention = (clone $base)->whereIn('payment_status', ['review','refund_pending'])->count();
                $counts['new'] += (clone $base)
                    ->whereIn('status', ['awaiting_payment','pending'])
                    ->whereNotIn('payment_status', ['review','refund_pending'])->count() + $attention;
                $counts['preparing'] += (clone $base)
                    ->whereIn('status', ['preparing','ready'])
                    ->whereNotIn('payment_status', ['review','refund_pending'])->count();
                $counts['delivery'] += (clone $base)
                    ->where('status', 'out_for_delivery')
                    ->whereNotIn('payment_status', ['review','refund_pending'])->count();
                $counts['done'] += (clone $base)
                    ->whereIn('status', ['completed','cancelled','rejected'])
                    ->whereNotIn('payment_status', ['review','refund_pending'])->count();
            }

            if (Schema::hasTable('go_service_jobs')) {
                $base = DB::table('go_service_jobs')->where('created_at', '>=', $from)
                ->where('created_at', '<', $until);
                $attention = (clone $base)->where(function ($q) {
                    $q->where('status', 'disputed')
                        ->orWhereIn('payment_status', ['review','refund_pending']);
                })->count();

                $safe = fn ($q) => $q->where('status', '!=', 'disputed')
                    ->where(function ($p) {
                        $p->whereNull('payment_status')
                            ->orWhereNotIn('payment_status', ['review','refund_pending']);
                    });

                $counts['new'] += $safe(clone $base)->where('status', 'searching')->count() + $attention;
                $counts['preparing'] += $safe(clone $base)->whereIn('status', ['booked','in_progress','awaiting_confirmation'])->count();
                $counts['done'] += $safe(clone $base)->whereIn('status', ['completed','cancelled','expired'])->count();
            }

            if (Schema::hasTable('partner_service_requests')) {
                $base = DB::table('partner_service_requests')->where('created_at', '>=', $from)
                ->where('created_at', '<', $until);
                $counts['new'] += (clone $base)->where('status', 'pending')->count();
                $counts['preparing'] += (clone $base)->where('status', 'accepted')->count();
                $counts['done'] += (clone $base)->whereIn('status', ['completed','declined'])->count();
            }
        }

        $counts['total'] = $counts['new'] + $counts['preparing'] + $counts['delivery'] + $counts['done'];

        return $counts;
    }

    private function salesForRange(Actor $actor, ?int $branchId, Carbon $start, Carbon $end): float
    {
        [$from, $until] = $this->utcBounds($start, $end);
        $restaurantIds = $this->branchRestaurantIds($actor, $branchId);
        $sales = 0.0;

        if ($restaurantIds->isNotEmpty() && Schema::hasColumn('orders', 'total_price')) {
            $query = DB::table('orders')
                ->whereIn('resturant_id', $restaurantIds)
                ->where('status', 'completed')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $until);

            if (Schema::hasColumn('orders', 'type')) {
                $query->where('type', 'current');
            }

            $sales += (float) $query->sum('total_price');
        }

        if ($actor->allBranches() && $branchId === null && Schema::hasTable('go_store_orders')) {
            $sales += ((float) DB::table('go_store_orders')
                ->where('status', 'completed')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $until)
                ->sum('total_cents')) / 100;
        }

        if ($actor->allBranches() && $branchId === null && Schema::hasTable('go_service_jobs')) {
            $sales += ((float) DB::table('go_service_jobs')
                ->where('status', 'completed')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $until)
                ->sum('price_cents')) / 100;
        }

        return round($sales, 2);
    }

    private function orderCountForRange(Actor $actor, ?int $branchId, Carbon $start, Carbon $end): int
    {
        return $this->countsForRange($actor, $branchId, $start, $end)['total'];
    }

    private function chartForRange(Actor $actor, ?int $branchId, Carbon $start, Carbon $end, string $period): array
    {
        if ($period === 'day') {
            $chartStart = $end->copy()->subDays(6);
            $chartEnd = $end->copy();
        } else {
            $chartStart = $start->copy();
            $chartEnd = $end->copy();
        }

        $days = $chartStart->diffInDays($chartEnd) + 1;
        $bucketSize = max(1, (int) ceil($days / 10));
        $labels = [];
        $sales = [];
        $orders = [];

        $cursor = $chartStart->copy();
        while ($cursor->lte($chartEnd)) {
            $bucketStart = $cursor->copy();
            $bucketEnd = $cursor->copy()->addDays($bucketSize - 1);
            if ($bucketEnd->gt($chartEnd)) {
                $bucketEnd = $chartEnd->copy();
            }

            $labels[] = $bucketStart->isSameDay($bucketEnd)
                ? $bucketStart->locale('ar')->translatedFormat('j M')
                : $bucketStart->locale('ar')->translatedFormat('j M').'–'.$bucketEnd->locale('ar')->translatedFormat('j M');
            $sales[] = $this->salesForRange($actor, $branchId, $bucketStart, $bucketEnd);
            $orders[] = $this->orderCountForRange($actor, $branchId, $bucketStart, $bucketEnd);

            $cursor = $bucketEnd->copy()->addDay();
        }

        return compact('labels','sales','orders');
    }

    private function recentRowsForRange(
        Actor $actor,
        ?int $branchId,
        Carbon $start,
        Carbon $end,
        int $limit
    ): Collection {
        $rows = collect();
        $cursor = $end->copy();
        $maxDays = min(45, $start->diffInDays($end) + 1);
        $visited = 0;

        while ($cursor->gte($start) && $rows->count() < $limit && $visited < $maxDays) {
            $board = $this->orders->dashboard($actor, $branchId, $cursor->format('Y-m-d'), []);
            $rows = $rows->concat(
                collect($board['columns'])->flatMap(fn ($column) => $column['rows'] ?? [])
            );
            $cursor->subDay();
            $visited++;
        }

        return $rows->sortByDesc('sort_ts')->unique('key')->take($limit)->values();
    }

    private function events(
        Actor $actor,
        ?int $branchId,
        Carbon $start,
        Carbon $end,
        Collection $recentRows
    ): array {
        $events = $recentRows->take(8)->map(function (array $row) {
            $meta = [
                'new' => ['title'=>'طلب جديد','description'=>'تم استلام طلب جديد','icon'=>'cart-shopping','tone'=>'orange'],
                'preparing' => ['title'=>'الطلب قيد التجهيز','description'=>'تم قبول الطلب وبدأ التجهيز','icon'=>'fire-burner','tone'=>'orange'],
                'delivery' => ['title'=>'انطلاق المندوب','description'=>'الطلب في الطريق إلى العميل','icon'=>'truck-fast','tone'=>'blue'],
                'done' => ['title'=>'تم التسليم','description'=>'اكتملت دورة الطلب بنجاح','icon'=>'circle-check','tone'=>'green'],
                'attention' => ['title'=>'يحتاج تدخل','description'=>'طلب يحتاج مراجعة إدارية','icon'=>'triangle-exclamation','tone'=>'red'],
            ][$row['stage'] ?? 'new'];

            return [
                'title' => $meta['title'],
                'description' => $meta['description'].' · '.($row['number'] ?? ''),
                'icon' => $meta['icon'],
                'tone' => $meta['tone'],
                'time' => $this->ageLabel((int) ($row['age_minutes'] ?? 0)),
                'sort_ts' => (int) ($row['sort_ts'] ?? 0),
            ];
        });

        if (Schema::hasTable('erp_audit')) {
            [$from, $until] = $this->utcBounds($start, $end);
            $audit = DB::table('erp_audit')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $until)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            foreach ($audit as $entry) {
                $events->push([
                    'title' => $this->auditTitle((string) $entry->action),
                    'description' => ($entry->actor_name ?: 'النظام').' · '.$entry->entity,
                    'icon' => 'clock-rotate-left',
                    'tone' => 'blue',
                    'time' => Carbon::parse($entry->created_at)->timezone(config('erp.timezone'))->locale('ar')->diffForHumans(),
                    'sort_ts' => Carbon::parse($entry->created_at)->timestamp,
                ]);
            }
        }

        return $events->sortByDesc('sort_ts')->take(6)->values()->all();
    }

    private function auditTitle(string $action): string
    {
        return [
            'menu.availability' => 'تحديث حالة صنف',
            'stock.post' => 'حركة مخزون جديدة',
            'attendance.save' => 'تحديث حضور موظف',
            'employee.save' => 'تحديث بيانات موظف',
        ][$action] ?? 'نشاط إداري جديد';
    }

    private function ageLabel(int $minutes): string
    {
        if ($minutes < 1) return 'الآن';
        if ($minutes < 60) return 'منذ '.$minutes.' دقيقة';
        if ($minutes < 1440) return 'منذ '.floor($minutes / 60).' ساعة';
        return 'منذ '.floor($minutes / 1440).' يوم';
    }

    private function quickReports(Actor $actor, ?int $branchId, Carbon $anchor): array
    {
        $dayStart = $anchor->copy();
        $weekStart = $anchor->copy()->subDays(6);
        $monthStart = $anchor->copy()->startOfMonth();

        $daySales = $this->salesForRange($actor, $branchId, $dayStart, $anchor);
        $weekSales = $this->salesForRange($actor, $branchId, $weekStart, $anchor);
        $monthSales = $this->salesForRange($actor, $branchId, $monthStart, $anchor);

        return [
            ['key'=>'day','label'=>'مبيعات اليوم','value'=>$daySales,'tone'=>'green','change'=>$this->change($daySales,$this->salesForRange($actor,$branchId,$anchor->copy()->subDay(),$anchor->copy()->subDay()))],
            ['key'=>'week','label'=>'مبيعات الأسبوع','value'=>$weekSales,'tone'=>'blue','change'=>$this->change($weekSales,$this->salesForRange($actor,$branchId,$weekStart->copy()->subDays(7),$weekStart->copy()->subDay()))],
            ['key'=>'month','label'=>'مبيعات الشهر','value'=>$monthSales,'tone'=>'purple','change'=>$this->change($monthSales,$this->salesForRange($actor,$branchId,$monthStart->copy()->subMonthNoOverflow()->startOfMonth(),$monthStart->copy()->subDay()))],
        ];
    }

    private function change(float|int $current, float|int $previous): array
    {
        if ((float) $previous === 0.0) {
            return ['value'=>$current > 0 ? 100 : 0, 'direction'=>$current > 0 ? 'up' : 'flat'];
        }

        $percent = round((($current - $previous) / abs($previous)) * 100, 1);

        return [
            'value'=>abs($percent),
            'direction'=>$percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat'),
        ];
    }
}
