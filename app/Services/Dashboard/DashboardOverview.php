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

    public function build(Actor $actor, ?int $branchId, string $day): array
    {
        $today = Carbon::createFromFormat('!Y-m-d', $day, config('erp.timezone'));
        $yesterday = $today->copy()->subDay();

        $todayBoard = $this->orders->dashboard($actor, $branchId, $today->format('Y-m-d'), []);
        $yesterdayBoard = $this->orders->dashboard($actor, $branchId, $yesterday->format('Y-m-d'), []);

        $counts = $this->stageCounts($todayBoard['columns']);
        $previous = $this->stageCounts($yesterdayBoard['columns']);

        $salesToday = $this->salesForDay($actor, $branchId, $today);
        $salesYesterday = $this->salesForDay($actor, $branchId, $yesterday);

        $kpis = [
            ['key'=>'total','label'=>'إجمالي الطلبات اليوم','value'=>$counts['total'],'icon'=>'clipboard','tone'=>'orange','change'=>$this->change($counts['total'],$previous['total'])],
            ['key'=>'new','label'=>'الطلبات الجديدة','value'=>$counts['new'],'icon'=>'cart','tone'=>'blue','change'=>$this->change($counts['new'],$previous['new'])],
            ['key'=>'preparing','label'=>'قيد التجهيز','value'=>$counts['preparing'],'icon'=>'chef','tone'=>'orange','change'=>$this->change($counts['preparing'],$previous['preparing'])],
            ['key'=>'delivery','label'=>'مع المندوب','value'=>$counts['delivery'],'icon'=>'truck','tone'=>'purple','change'=>$this->change($counts['delivery'],$previous['delivery'])],
            ['key'=>'done','label'=>'تم التسليم','value'=>$counts['done'],'icon'=>'check','tone'=>'green','change'=>$this->change($counts['done'],$previous['done'])],
            ['key'=>'sales','label'=>'المبيعات اليوم','value'=>$salesToday,'money'=>true,'icon'=>'money','tone'=>'blue','change'=>$this->change($salesToday,$salesYesterday)],
        ];

        $rows = $this->flatten($todayBoard['columns']);
        $recent = $rows->sortByDesc('sort_ts')->take(8)->values()->all();

        $live = [
            'new' => collect($todayBoard['columns']['new']['rows'] ?? [])
                ->concat($todayBoard['columns']['attention']['rows'] ?? [])
                ->sortByDesc('sort_ts')->take(3)->values()->all(),
            'preparing' => array_slice($todayBoard['columns']['preparing']['rows'] ?? [], 0, 3),
            'delivery' => array_slice($todayBoard['columns']['delivery']['rows'] ?? [], 0, 3),
            'done' => array_slice($todayBoard['columns']['done']['rows'] ?? [], 0, 3),
        ];

        $chart = $this->chart($actor, $branchId, $today);
        $todayOrderCount = max(1, $counts['total']);

        return [
            'kpis' => $kpis,
            'counts' => $counts,
            'live' => $live,
            'recent' => $recent,
            'chart' => $chart,
            'sales_today' => $salesToday,
            'average_order' => round($salesToday / $todayOrderCount, 2),
            'total_7d' => array_sum($chart['orders']),
            'version' => $todayBoard['version'],
        ];
    }

    private function stageCounts(array $columns): array
    {
        $new = (int) ($columns['new']['count'] ?? 0) + (int) ($columns['attention']['count'] ?? 0);
        $preparing = (int) ($columns['preparing']['count'] ?? 0);
        $delivery = (int) ($columns['delivery']['count'] ?? 0);
        $done = (int) ($columns['done']['count'] ?? 0);

        return compact('new','preparing','delivery','done') + ['total'=>$new+$preparing+$delivery+$done];
    }

    private function flatten(array $columns): Collection
    {
        return collect($columns)->flatMap(function ($column) {
            return collect($column['rows'] ?? []);
        })->values();
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

    private function branchRestaurantIds(Actor $actor, ?int $branchId): Collection
    {
        $query = $actor->scope(DB::table('erp_branches'), 'id')->where('active', true);

        if ($branchId !== null) {
            $actor->branch($branchId, true);
            $query->where('id', $branchId);
        }

        return $query->pluck('restaurant_id')->map(fn ($id) => (int) $id)->values();
    }

    private function salesForDay(Actor $actor, ?int $branchId, Carbon $day): float
    {
        $restaurantIds = $this->branchRestaurantIds($actor, $branchId);
        $start = $day->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
        $end = $day->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
        $sales = 0.0;

        if ($restaurantIds->isNotEmpty() && Schema::hasColumn('orders', 'total_price')) {
            $sales += (float) DB::table('orders')
                ->whereIn('resturant_id', $restaurantIds)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('total_price');
        }

        if ($actor->allBranches() && $branchId === null && Schema::hasTable('go_store_orders')) {
            $sales += ((float) DB::table('go_store_orders')
                ->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('total_cents')) / 100;
        }

        return round($sales, 2);
    }

    private function chart(Actor $actor, ?int $branchId, Carbon $today): array
    {
        $labels = [];
        $sales = [];
        $orders = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $today->copy()->subDays($offset);
            $labels[] = $day->locale('ar')->translatedFormat('j M');
            $sales[] = $this->salesForDay($actor, $branchId, $day);
            $orders[] = $this->orderCountForDay($actor, $branchId, $day);
        }

        return compact('labels','sales','orders');
    }

    private function orderCountForDay(Actor $actor, ?int $branchId, Carbon $day): int
    {
        $restaurantIds = $this->branchRestaurantIds($actor, $branchId);
        $start = $day->copy()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
        $end = $day->copy()->addDay()->startOfDay()->setTimezone(config('app.timezone', 'UTC'));
        $count = 0;

        if ($restaurantIds->isNotEmpty()) {
            $count += (int) DB::table('orders')
                ->whereIn('resturant_id', $restaurantIds)
                ->whereNotNull('status')
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        if ($actor->allBranches() && $branchId === null && Schema::hasTable('go_store_orders')) {
            $count += (int) DB::table('go_store_orders')
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        if ($actor->allBranches() && $branchId === null && Schema::hasTable('go_service_jobs')) {
            $count += (int) DB::table('go_service_jobs')
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return $count;
    }
}
