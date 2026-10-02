<?php

namespace App\Services\Erp;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnifiedOrders
{
    public const STAGES = ['new', 'preparing', 'delivery', 'attention', 'done'];
    public const KINDS = ['branch', 'restaurant', 'delivery', 'store', 'service'];
    public const APPS = ['fasakhansta', 'go'];

    public function dashboard(Actor $actor, ?int $branchId, string $day, array $filters = []): array
    {
        $localDay = Carbon::createFromFormat('!Y-m-d', $day, config('erp.timezone'));
        $start = $localDay->copy()->setTimezone(config('app.timezone', 'UTC'));
        $end = $localDay->copy()->addDay()->setTimezone(config('app.timezone', 'UTC'));

        $branches = DB::table('erp_branches')
            ->select('id', 'restaurant_id', 'name')
            ->get()
            ->keyBy('restaurant_id');

        $rows = collect($this->legacyOrders($actor, $branches, $start, $end));

        // GO is a company-wide marketplace. Branch managers never receive GO rows,
        // even if a client submits crafted filter/query parameters.
        if ($actor->allBranches()) {
            $rows = $rows
                ->concat($this->goStoreOrders($start, $end))
                ->concat($this->goServiceJobs($start, $end))
                ->concat($this->legacyServiceRequests($start, $end));
        }

        $rows = $rows->map(fn (array $row) => $this->normalize($row));

        if ($branchId !== null) {
            $rows = $rows->filter(fn (array $row) => (int) ($row['branch_id'] ?? 0) === $branchId);
        }

        $app = $filters['app'] ?? null;
        if ($app) {
            $rows = $rows->where('source_app', $app);
        }

        $kind = $filters['kind'] ?? null;
        if ($kind) {
            $rows = $rows->where('kind', $kind);
        }

        $stage = $filters['stage'] ?? null;
        if ($stage) {
            $rows = $rows->where('stage', $stage);
        }

        $payment = $filters['payment'] ?? null;
        if ($payment) {
            $rows = $rows->where('payment_method', $payment);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search) {
                $haystack = implode(' ', array_filter([
                    $row['number'] ?? null,
                    $row['entity_name'] ?? null,
                    $row['branch_name'] ?? null,
                    $row['customer_name'] ?? null,
                    $row['customer_mobile'] ?? null,
                    $row['assignee_name'] ?? null,
                    $row['status_label'] ?? null,
                    $row['description'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''));

                return mb_stripos($haystack, $search) !== false;
            });
        }

        $rows = $rows->sortByDesc('sort_ts')->values();

        $columns = [];
        foreach (self::STAGES as $stageKey) {
            $stageRows = $rows->where('stage', $stageKey)->values();
            $columns[$stageKey] = [
                'count' => $stageRows->count(),
                'rows' => $stageRows->take(60)->all(),
            ];
        }

        $stats = [
            'total' => $rows->count(),
            'active' => $rows->whereNotIn('stage', ['done'])->count(),
            'new' => $rows->where('stage', 'new')->count(),
            'attention' => $rows->where('stage', 'attention')->count(),
            'fasakhansta' => $rows->where('source_app', 'fasakhansta')->count(),
            'go' => $rows->where('source_app', 'go')->count(),
        ];

        $version = sha1(json_encode($rows->map(fn (array $row) => [
            $row['key'], $row['status'], $row['payment_status'], $row['updated_at'],
        ])->all(), JSON_UNESCAPED_UNICODE));

        return compact('columns', 'stats', 'version');
    }

    private function legacyOrders(Actor $actor, Collection $branches, Carbon $start, Carbon $end): array
    {
        $hasUserId = Schema::hasColumn('orders', 'user_id');
        $hasDelegateId = Schema::hasColumn('orders', 'delegate_id');
        $hasOrderUpdatedAt = Schema::hasColumn('orders', 'updated_at');
        $hasOrderTotal = Schema::hasColumn('orders', 'total_price');
        $hasRestaurantOwner = Schema::hasColumn('resturants', 'user_id');
        $hasMobile = Schema::hasColumn('users', 'mobile');
        $hasAppScope = Schema::hasColumn('users', 'app_scope');

        $query = DB::table('orders as o')
            ->leftJoin('resturants as r', 'r.id', '=', 'o.resturant_id');

        $hasCartTotals = Schema::hasTable('carts')
            && Schema::hasColumn('carts', 'price')
            && Schema::hasColumn('carts', 'qty');

        if ($hasCartTotals) {
            $cartLineTotal = Schema::hasColumn('carts', 'updated_total')
                ? 'COALESCE(updated_total, price * qty, 0)'
                : 'COALESCE(price * qty, 0)';

            $cartTotals = DB::table('carts')
                ->select('order_id', DB::raw('SUM('.$cartLineTotal.') AS cart_total'))
                ->groupBy('order_id');

            $query->leftJoinSub($cartTotals, 'cart_totals', function ($join) {
                $join->on('cart_totals.order_id', '=', 'o.id');
            });
        }

        if ($hasUserId) {
            $query->leftJoin('users as customer', 'customer.id', '=', 'o.user_id');
        }
        if ($hasDelegateId) {
            $query->leftJoin('users as delegate', 'delegate.id', '=', 'o.delegate_id');
        }
        if ($hasRestaurantOwner && $hasAppScope) {
            $query->leftJoin('users as owner', 'owner.id', '=', 'r.user_id');
        }

        if (!$actor->allBranches()) {
            $restaurantIds = $actor->scope(DB::table('erp_branches'), 'id')->pluck('restaurant_id');
            $query->where('o.type', 'current')->whereIn('o.resturant_id', $restaurantIds);
        } else {
            $query->whereIn('o.type', ['current', 'shipping']);
        }

        $select = [
            'o.id', 'o.order_no', 'o.resturant_id', 'o.type', 'o.status',
            'o.payment_type', 'o.created_at', 'r.name as restaurant_name',
            $hasOrderTotal ? 'o.total_price' : DB::raw('NULL as total_price'),
            $hasCartTotals ? 'cart_totals.cart_total' : DB::raw('NULL as cart_total'),
            $hasUserId ? 'o.user_id' : DB::raw('NULL as user_id'),
            $hasDelegateId ? 'o.delegate_id' : DB::raw('NULL as delegate_id'),
            $hasOrderUpdatedAt ? 'o.updated_at' : DB::raw('NULL as updated_at'),
            $hasUserId ? 'customer.name as customer_name' : DB::raw('NULL as customer_name'),
            ($hasUserId && $hasMobile) ? 'customer.mobile as customer_mobile' : DB::raw('NULL as customer_mobile'),
            $hasDelegateId ? 'delegate.name as delegate_name' : DB::raw('NULL as delegate_name'),
            ($hasDelegateId && $hasMobile) ? 'delegate.mobile as delegate_mobile' : DB::raw('NULL as delegate_mobile'),
            ($hasRestaurantOwner && $hasAppScope) ? 'owner.app_scope as owner_scope' : DB::raw('NULL as owner_scope'),
        ];

        return $query
            ->whereNotNull('o.status')
            ->whereBetween('o.created_at', [$start, $end])
            ->orderByDesc('o.id')
            ->limit(1200)
            ->get($select)
            ->map(function ($order) use ($branches) {
                $branch = $order->resturant_id ? $branches->get($order->resturant_id) : null;
                $shipping = $order->type === 'shipping';
                $source = $shipping ? 'go' : ($branch ? 'fasakhansta' : ($order->owner_scope === 'go_partner' ? 'go' : 'fasakhansta'));
                $kind = $shipping ? 'delivery' : ($branch ? 'branch' : 'restaurant');

                return [
                    'key' => 'legacy:'.$order->id,
                    'number' => (string) ($order->order_no ?: '#'.$order->id),
                    'source_app' => $source,
                    'kind' => $kind,
                    'branch_id' => $branch ? (int) $branch->id : null,
                    'branch_name' => $branch?->name,
                    'entity_id' => $order->resturant_id ? (int) $order->resturant_id : null,
                    'entity_name' => $shipping ? 'طلب مندوب' : ($branch?->name ?: ($order->restaurant_name ?: 'مطعم')),
                    'customer_name' => $order->customer_name,
                    'customer_mobile' => $order->customer_mobile,
                    'assignee_name' => $order->delegate_name,
                    'assignee_mobile' => $order->delegate_mobile,
                    'assignee_type' => $shipping || $order->delegate_name ? 'مندوب' : null,
                    'status' => (string) $order->status,
                    'stage' => $this->legacyStage((string) $order->status),
                    'status_label' => $this->legacyStatus((string) $order->status),
                    'payment_method' => $this->paymentKey($order->payment_type),
                    'payment_status' => null,
                    'amount_cents' => $order->total_price !== null
                        ? (int) round(((float) $order->total_price) * 100)
                        : ($order->cart_total !== null ? (int) round(((float) $order->cart_total) * 100) : null),
                    'description' => $shipping ? 'طلب توصيل / مندوب عبر GO' : null,
                    'items' => [],
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ];
            })->all();
    }

    private function goStoreOrders(Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('go_store_orders')) {
            return [];
        }

        return DB::table('go_store_orders')
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('id')
            ->limit(1200)
            ->get()
            ->map(function ($order) {
                $snapshot = json_decode((string) $order->snapshot, true) ?: [];
                $items = array_slice(array_map(function ($item) {
                    return [
                        'name' => (string) ($item['name'] ?? 'صنف'),
                        'quantity' => (int) ($item['quantity'] ?? 1),
                        'line_total' => $item['line_total'] ?? null,
                        'option_label' => $item['option_label'] ?? null,
                    ];
                }, $snapshot['items'] ?? []), 0, 12);

                $stage = $this->storeStage((string) $order->status);
                if (in_array((string) $order->payment_status, ['review', 'refund_pending'], true)) {
                    $stage = 'attention';
                }

                return [
                    'key' => 'go-store:'.$order->id,
                    'number' => 'GS-'.$order->id,
                    'source_app' => 'go',
                    'kind' => 'store',
                    'branch_id' => null,
                    'branch_name' => null,
                    'entity_id' => (int) $order->store_id,
                    'entity_name' => (string) ($snapshot['store_name'] ?? 'متجر GO'),
                    'customer_name' => $snapshot['customer_name'] ?? null,
                    'customer_mobile' => $snapshot['customer_mobile'] ?? null,
                    'assignee_name' => $snapshot['store_name'] ?? null,
                    'assignee_mobile' => $snapshot['store_mobile'] ?? null,
                    'assignee_type' => 'متجر',
                    'status' => (string) $order->status,
                    'stage' => $stage,
                    'status_label' => $this->storeStatus((string) $order->status),
                    'payment_method' => $this->paymentKey($order->payment_method),
                    'payment_status' => (string) $order->payment_status,
                    'amount_cents' => (int) $order->total_cents,
                    'description' => trim((string) ($snapshot['notes'] ?? '')),
                    'items' => $items,
                    'location' => $snapshot['address']['address'] ?? ($snapshot['store_address'] ?? null),
                    'fulfillment' => $snapshot['fulfillment'] ?? $order->fulfillment,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ];
            })->all();
    }

    private function goServiceJobs(Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('go_service_jobs')) {
            return [];
        }

        return DB::table('go_service_jobs as j')
            ->leftJoin('users as customer', 'customer.id', '=', 'j.customer_id')
            ->leftJoin('users as partner', 'partner.id', '=', 'j.partner_id')
            ->whereBetween('j.created_at', [$start, $end])
            ->orderByDesc('j.id')
            ->limit(1200)
            ->get([
                'j.id', 'j.profession_key', 'j.description', 'j.address', 'j.status',
                'j.partner_id', 'j.price_cents', 'j.payment_method', 'j.payment_status',
                'j.created_at', 'j.updated_at',
                'customer.name as customer_name', 'customer.mobile as customer_mobile',
                'partner.name as partner_name', 'partner.mobile as partner_mobile',
            ])
            ->map(function ($job) {
                $stage = $this->serviceStage((string) $job->status);
                if ($job->status === 'disputed' || in_array((string) $job->payment_status, ['review', 'refund_pending'], true)) {
                    $stage = 'attention';
                }

                return [
                    'key' => 'go-service:'.$job->id,
                    'number' => 'GJ-'.$job->id,
                    'source_app' => 'go',
                    'kind' => 'service',
                    'branch_id' => null,
                    'branch_name' => null,
                    'entity_id' => null,
                    'entity_name' => 'خدمة '.str_replace('_', ' ', (string) $job->profession_key),
                    'customer_name' => $job->customer_name,
                    'customer_mobile' => $job->customer_mobile,
                    'assignee_name' => $job->partner_name,
                    'assignee_mobile' => $job->partner_mobile,
                    'assignee_type' => 'صنايعي',
                    'status' => (string) $job->status,
                    'stage' => $stage,
                    'status_label' => $this->serviceStatus((string) $job->status),
                    'payment_method' => $this->paymentKey($job->payment_method),
                    'payment_status' => $job->payment_status ? (string) $job->payment_status : null,
                    'amount_cents' => (int) $job->price_cents > 0 ? (int) $job->price_cents : null,
                    'description' => trim((string) $job->description),
                    'items' => [],
                    'location' => $job->address,
                    'created_at' => $job->created_at,
                    'updated_at' => $job->updated_at,
                ];
            })->all();
    }

    private function legacyServiceRequests(Carbon $start, Carbon $end): array
    {
        // Preserve visibility for service requests created before the GO offer
        // marketplace was enabled. They are distinct records, not aliases.
        if (!Schema::hasTable('partner_service_requests')) {
            return [];
        }

        return DB::table('partner_service_requests as s')
            ->leftJoin('users as customer', 'customer.id', '=', 's.user_id')
            ->leftJoin('users as partner', 'partner.id', '=', 's.partner_id')
            ->whereBetween('s.created_at', [$start, $end])
            ->orderByDesc('s.id')
            ->limit(500)
            ->get([
                's.id', 's.profession_key', 's.description', 's.address', 's.customer_phone',
                's.status', 's.created_at', 's.updated_at',
                'customer.name as customer_name', 'customer.mobile as customer_mobile',
                'partner.name as partner_name', 'partner.mobile as partner_mobile',
            ])
            ->map(function ($request) {
                return [
                    'key' => 'legacy-service:'.$request->id,
                    'number' => 'SR-'.$request->id,
                    'source_app' => 'go',
                    'kind' => 'service',
                    'branch_id' => null,
                    'branch_name' => null,
                    'entity_id' => null,
                    'entity_name' => 'خدمة '.str_replace('_', ' ', (string) $request->profession_key),
                    'customer_name' => $request->customer_name,
                    'customer_mobile' => $request->customer_phone ?: $request->customer_mobile,
                    'assignee_name' => $request->partner_name,
                    'assignee_mobile' => $request->partner_mobile,
                    'assignee_type' => 'صنايعي',
                    'status' => (string) $request->status,
                    'stage' => in_array($request->status, ['completed', 'declined'], true) ? 'done' : ($request->status === 'pending' ? 'new' : 'preparing'),
                    'status_label' => $this->legacyServiceStatus((string) $request->status),
                    'payment_method' => null,
                    'payment_status' => null,
                    'amount_cents' => null,
                    'description' => trim((string) $request->description),
                    'items' => [],
                    'location' => $request->address,
                    'created_at' => $request->created_at,
                    'updated_at' => $request->updated_at,
                ];
            })->all();
    }

    private function normalize(array $row): array
    {
        $created = Carbon::parse($row['created_at'])->timezone(config('erp.timezone'));
        $now = Carbon::now(config('erp.timezone'));
        $row['created_display'] = $created->format('H:i');
        $row['created_date'] = $created->format('Y-m-d');
        $row['age_minutes'] = max(0, $created->diffInMinutes($now));
        $row['sort_ts'] = $created->timestamp;
        $row['updated_at'] = $row['updated_at'] ? (string) $row['updated_at'] : (string) $row['created_at'];
        $row['payment_label'] = $this->paymentLabel($row['payment_method'] ?? null);
        return $row;
    }

    private function paymentKey($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = mb_strtolower(trim((string) $value));
        $aliases = [
            'online' => 'card', 'visa' => 'card', 'bank_card' => 'card',
            'mobilewallet' => 'mobile_wallet', 'mobile-wallet' => 'mobile_wallet',
            'electronic_wallet' => 'mobile_wallet',
        ];

        return $aliases[$value] ?? $value;
    }

    private function paymentLabel(?string $value): string
    {
        return [
            'cash' => 'كاش',
            'wallet' => 'محفظة التطبيق',
            'card' => 'كارت بنكي',
            'mobile_wallet' => 'محفظة إلكترونية',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
        ][$value] ?? ($value ?: 'غير محدد');
    }

    private function legacyStage(string $status): string
    {
        if (in_array($status, ['pending', 'another_delegate'], true)) return 'new';
        if (in_array($status, ['accepted', 'new_order'], true)) return 'preparing';
        if ($status === 'shipped') return 'delivery';
        return 'done';
    }

    private function storeStage(string $status): string
    {
        if (in_array($status, ['awaiting_payment', 'pending'], true)) return 'new';
        if (in_array($status, ['preparing', 'ready'], true)) return 'preparing';
        if ($status === 'out_for_delivery') return 'delivery';
        return 'done';
    }

    private function serviceStage(string $status): string
    {
        if ($status === 'searching') return 'new';
        if (in_array($status, ['booked', 'in_progress', 'awaiting_confirmation'], true)) return 'preparing';
        if ($status === 'disputed') return 'attention';
        return 'done';
    }

    private function legacyStatus(string $status): string
    {
        return [
            'pending' => 'طلب جديد',
            'another_delegate' => 'بانتظار مندوب',
            'accepted' => 'مقبول',
            'new_order' => 'قيد التجهيز',
            'shipped' => 'مع المندوب',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            'declined' => 'مرفوض',
        ][$status] ?? $status;
    }

    private function storeStatus(string $status): string
    {
        return [
            'awaiting_payment' => 'بانتظار الدفع',
            'pending' => 'طلب متجر جديد',
            'preparing' => 'قيد تجهيز المتجر',
            'ready' => 'جاهز',
            'out_for_delivery' => 'خرج للتوصيل',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            'rejected' => 'مرفوض من المتجر',
        ][$status] ?? $status;
    }

    private function serviceStatus(string $status): string
    {
        return [
            'searching' => 'بانتظار عروض الصنايعية',
            'booked' => 'تم الاتفاق',
            'in_progress' => 'جاري التنفيذ',
            'awaiting_confirmation' => 'بانتظار تأكيد العميل',
            'disputed' => 'نزاع يحتاج تدخل',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            'expired' => 'انتهت مهلة البحث',
        ][$status] ?? $status;
    }

    private function legacyServiceStatus(string $status): string
    {
        return [
            'pending' => 'طلب خدمة جديد',
            'accepted' => 'مقبول',
            'declined' => 'مرفوض',
            'completed' => 'مكتمل',
        ][$status] ?? $status;
    }
}
