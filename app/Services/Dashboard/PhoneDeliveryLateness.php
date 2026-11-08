<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Late delivery orders, timed as the order card's timer shows them (from the moment the order was made):
 *   - still being prepared at 15 minutes;
 *   - still with the courier (not collected) at 35 minutes.
 * A late order's card turns red and the branch hears a warning every 5 minutes while it stays late. The branches'
 * rating counts, for the delivery orders collected in a period, how many were late in each stage.
 */
class PhoneDeliveryLateness
{
    public const PREPARING_MINUTES = 15;
    public const COURIER_MINUTES = 35;
    public const REPEAT_MINUTES = 5;
    /** An order left open longer than this is a forgotten one, not one on its way: it no longer rings. */
    private const WATCH_HOURS = 12;

    public function __construct(private TakeawayAccess $access) {}

    public static function policy(): array
    {
        return ['preparing_minutes'=>self::PREPARING_MINUTES, 'courier_minutes'=>self::COURIER_MINUTES, 'repeat_minutes'=>self::REPEAT_MINUTES];
    }

    /** The late delivery orders of the account's branches right now. */
    public function late($actor): array
    {
        $branches = $this->branches($actor);
        if (!$branches || !Schema::hasTable('pos_service_tickets')) return ['success'=>true, 'orders'=>[], 'policy'=>self::policy()];
        $now = now('UTC');
        $names = array_column($branches, 'name', 'value');
        $rows = DB::table('pos_service_tickets')->whereIn('branch', array_keys($names))->where('channel', 'phone')->where('payment_status', 'unpaid')
            ->where('created_at', '>=', $now->copy()->subHours(self::WATCH_HOURS)->toDateTimeString())
            ->where(function ($late) use ($now) {
                $late->where(fn ($q) => $q->whereIn('status', ['new', 'preparing'])->where('created_at', '<=', $now->copy()->subMinutes(self::PREPARING_MINUTES)->toDateTimeString()))
                    ->orWhere(fn ($q) => $q->whereIn('status', ['out_for_delivery', 'finished'])->where('created_at', '<=', $now->copy()->subMinutes(self::COURIER_MINUTES)->toDateTimeString()));
            })->orderBy('created_at')->limit(50)->get();
        $orders = [];
        foreach ($rows as $row) {
            $created = Carbon::parse($row->created_at, 'UTC');
            $orders[] = ['id'=>(int) $row->id, 'number'=>$this->number($row), 'branch'=>$names[$row->branch] ?? $row->branch,
                'stage'=>in_array($row->status, ['new', 'preparing'], true) ? 'preparing' : 'courier',
                'minutes'=>(int) floor($created->diffInSeconds($now) / 60), 'created_at'=>$created->toIso8601String()];
        }
        return ['success'=>true, 'orders'=>$orders, 'policy'=>self::policy()];
    }

    /**
     * Each branch's delivery orders collected in the period: how many, how many were late in preparing (handed to the
     * courier, or collected without one, after 15 minutes) and late overall (collected after 35 minutes), the
     * average times, the share on time and a grade.
     */
    public function performance(array $values, $actor): array
    {
        $v = Validator::make($values, ['from'=>'nullable|date_format:Y-m-d', 'to'=>'nullable|date_format:Y-m-d|after_or_equal:from'])->validate();
        $to = $v['to'] ?? OperatingDay::date();
        $from = $v['from'] ?? $to;
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92, 422, 'اختر فترة لا تزيد عن 3 شهور.');
        $branches = $this->branches($actor);
        $result = ['success'=>true, 'from'=>$from, 'to'=>$to, 'policy'=>self::policy(), 'branches'=>[]];
        if (!$branches || !Schema::hasTable('pos_service_tickets') || !Schema::hasTable('takeaway_orders')) return $result;
        $names = array_column($branches, 'name', 'value');
        $query = DB::table('pos_service_tickets as t')->join('takeaway_orders as r', function ($join) {
            $join->on('r.id', '=', 't.paid_order_id')->on('r.ticket_id', '=', 't.id')->on('r.branch', '=', 't.branch');
        })->whereIn('t.branch', array_keys($names))->where('t.channel', 'phone')->where('t.payment_status', 'paid')->where('r.channel', 'phone');
        if (Schema::hasColumn('pos_service_tickets', 'business_date')) $query->whereBetween('t.business_date', [$from, $to]);
        else $query->where('t.created_at', '>=', OperatingDay::start($from)->setTimezone('UTC')->toDateTimeString())
            ->where('t.created_at', '<', OperatingDay::end($to)->setTimezone('UTC')->toDateTimeString());
        if (Schema::hasTable('phone_delivery_dispatches')) $query->leftJoin('phone_delivery_dispatches as d', 'd.ticket_id', '=', 't.id')->addSelect('d.created_at as handed_at');
        $rows = $query->addSelect('t.branch', 't.created_at', 'r.created_at as collected_at')->get();
        $stats = [];
        foreach ($rows as $row) {
            $created = $this->time($row->created_at); $collected = $this->time($row->collected_at);
            if (!$created || !$collected || $collected->lt($created)) continue;
            $handed = $this->time($row->handed_at ?? null);
            if (!$handed || $handed->lt($created) || $handed->gt($collected)) $handed = null;
            $prepared = ($handed ?? $collected)->diffInSeconds($created) / 60;
            $total = $collected->diffInSeconds($created) / 60;
            $s = &$stats[$row->branch];
            $s ??= ['orders'=>0, 'late_preparing'=>0, 'late_delivery'=>0, 'late'=>0, 'preparing_minutes'=>0.0, 'total_minutes'=>0.0];
            $s['orders']++;
            $latePreparing = $prepared > self::PREPARING_MINUTES; $lateDelivery = $total > self::COURIER_MINUTES;
            $s['late_preparing'] += (int) $latePreparing; $s['late_delivery'] += (int) $lateDelivery; $s['late'] += (int) ($latePreparing || $lateDelivery);
            $s['preparing_minutes'] += $prepared; $s['total_minutes'] += $total;
            unset($s);
        }
        foreach ($names as $value => $name) {
            $s = $stats[$value] ?? null;
            if (!$s) { $result['branches'][] = ['branch'=>$value, 'name'=>$name, 'orders'=>0, 'late_preparing'=>0, 'late_delivery'=>0, 'late'=>0,
                'avg_preparing_minutes'=>null, 'avg_total_minutes'=>null, 'on_time_percent'=>null, 'grade'=>null]; continue; }
            $onTime = round(100 * ($s['orders'] - $s['late']) / $s['orders'], 1);
            $result['branches'][] = ['branch'=>$value, 'name'=>$name, 'orders'=>$s['orders'], 'late_preparing'=>$s['late_preparing'],
                'late_delivery'=>$s['late_delivery'], 'late'=>$s['late'], 'avg_preparing_minutes'=>round($s['preparing_minutes'] / $s['orders'], 1),
                'avg_total_minutes'=>round($s['total_minutes'] / $s['orders'], 1), 'on_time_percent'=>$onTime, 'grade'=>self::grade($onTime)];
        }
        usort($result['branches'], fn ($a, $b) => [$b['orders'] > 0, $b['on_time_percent'] ?? -1, $b['orders']] <=> [$a['orders'] > 0, $a['on_time_percent'] ?? -1, $a['orders']]);
        return $result;
    }

    public static function grade(float $onTime): string
    {
        return $onTime >= 95 ? 'ممتاز' : ($onTime >= 85 ? 'جيد جدًا' : ($onTime >= 70 ? 'جيد' : ($onTime >= 50 ? 'ضعيف' : 'سيئ')));
    }

    private function branches($actor): array
    {
        try { return array_values(array_filter($this->access->branches($actor), fn ($b) => in_array($b['kind'] ?? '', ['f', 'gs'], true))); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { return []; }
    }

    private function number(object $row): string
    {
        return !empty($row->shift_order_number) ? (string) BranchOrderSequence::display($row, $row->branch, $row->channel, (string) $row->id) : (string) $row->id;
    }

    /** POS audit times are stored in UTC. */
    private function time($value): ?Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value)) return null;
        try { return Carbon::parse(substr($value, 0, 19), 'UTC'); } catch (\Throwable $error) { return null; }
    }
}
