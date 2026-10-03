<?php

namespace App\Services\Dashboard;

use App\Models\Order;
use App\Models\Resturant;
use App\Models\User;
use App\Services\GoServices\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderBoardService
{
    public const GROUPS = ['new', 'preparing', 'courier', 'completed'];
    private $columns = [];
    private $related = [];
    private $storeActions;

    public function canAccess($actor): bool
    {
        if (!$actor) return false;
        if ($actor->account_type === 'admin') return (int) $actor->id === 1 || $actor->can('order-list');
        if (in_array($actor->account_type, ['vendor', 'resturant_owner'], true)) return true;
        return ($actor->app_scope ?? '') === 'go_partner' && ($actor->status ?? '') === 'accepted'
            && $this->has('go_stores', 'user_id') && DB::table('go_stores')->where('user_id', $actor->id)->exists();
    }

    public function isAdmin($actor): bool
    {
        return $actor && $actor->account_type === 'admin';
    }

    public function restaurantIds($actor): array
    {
        if (!$this->has('resturants', 'user_id')) return [];
        $query = DB::table('resturants');
        if ($this->isAdmin($actor)) return $query->pluck('id')->all();
        if ($actor->account_type === 'resturant_owner' && !empty($actor->owner_resturant_id)) {
            $query->where(function ($q) use ($actor) {
                $q->where('id', $actor->owner_resturant_id);
                if ($this->has('resturants', 'parent_id')) $q->orWhere('parent_id', $actor->owner_resturant_id);
            });
        } else {
            $query->where('user_id', $actor->id);
        }
        return $query->pluck('id')->all();
    }

    public function data(Request $request, $actor): array
    {
        abort_unless($this->canAccess($actor), 403);
        $filters = $this->filters($request);
        $groups = array_fill_keys(self::GROUPS, []);
        $counts = array_fill_keys(self::GROUPS, 0);
        $limit = max(10, min(100, (int) $request->input('limit', 50)));
        $bases = [];
        foreach (['legacy', 'store', 'service', 'partner_service'] as $source) {
            $base = $this->query($source, $actor, $filters);
            if ($base) $bases[$source] = $base;
        }
        $pages = [];
        foreach (self::GROUPS as $group) {
            $union = null;
            foreach ($bases as $source => $base) {
                $query = clone $base;
                $this->forGroup($query, $source, $group);
                $counts[$group] += (clone $query)->count();
                $query->select(['id', 'created_at'])->selectRaw('? AS source', [$source]);
                if ($union === null) $union = $query;
                else $union->unionAll($query);
            }
            $lastPage = max(1, (int) ceil($counts[$group] / $limit));
            $page = max(1, min($lastPage, (int) $request->input('page_'.$group, 1)));
            $offset = ($page - 1) * $limit;
            if ($union !== null) {
                $selected = DB::query()->fromSub($union, 'board_records')->orderByDesc('created_at')->orderByDesc('id')
                    ->orderBy('source')->offset($offset)->limit($limit)->get();
                $cardsByKey = [];
                foreach ($selected->groupBy('source') as $source => $records) {
                    $rows = (clone $bases[$source])->whereIn('id', $records->pluck('id'))->get();
                    $this->loadRelated($source, $rows);
                    foreach ($rows as $row) $cardsByKey[$source.':'.$row->id] = $this->normalize($source, $row, $actor);
                }
                foreach ($selected as $record) $groups[$group][] = $cardsByKey[$record->source.':'.$record->id];
            }
            $parameters = array_merge($request->query(), array_filter($filters));
            $pages[$group] = ['page'=>$page, 'from'=>$counts[$group] ? $offset + 1 : 0,
                'to'=>min($offset + count($groups[$group]), $counts[$group]), 'total'=>$counts[$group],
                'previous_url'=>$page > 1 ? route('orders.applies', array_merge($parameters, ['page_'.$group=>$page - 1])) : null,
                'next_url'=>$page < $lastPage ? route('orders.applies', array_merge($parameters, ['page_'.$group=>$page + 1])) : null];
        }
        return ['groups'=>$groups, 'counts'=>$counts, 'filters'=>$filters,
            'branches'=>$this->branches($actor), 'isAdmin'=>$this->isAdmin($actor),
            'limit'=>$limit, 'pages'=>$pages, 'updated_at'=>now()->toIso8601String(),
            'count'=>array_sum($counts)];
    }

    public function detail(string $source, int $id, $actor): array
    {
        abort_unless($this->canAccess($actor), 403);
        $query = $this->query($source, $actor, []);
        abort_unless($query, 404);
        $row = $query->where('id', $id)->first();
        abort_unless($row, 404);
        $this->loadRelated($source, collect([$row]));
        return $this->normalize($source, $row, $actor);
    }

    public function scopedLegacy(int $id, $actor, bool $lock = false): Order
    {
        $query = $this->query('legacy', $actor, []);
        abort_unless($query, 404);
        if ($lock) $query->lockForUpdate();
        $row = $query->where('id', $id)->first();
        abort_unless($row, 404);
        $order = (new Order())->newFromBuilder((array) $row);
        // The order has already been authorized; unrelated customer/vendor list scopes
        // must not hide the recipients needed by the established action lifecycle.
        $order->setRelation('user', User::withoutGlobalScopes()->find($row->user_id));
        $order->setRelation('resturant', Resturant::withoutGlobalScopes()->find($row->resturant_id));
        return $order;
    }

    private function filters(Request $request): array
    {
        $values = $request->validate([
            'app'=>'nullable|in:go,fasakhansta', 'branch'=>['nullable', 'regex:/^(f|gs):[0-9]+$/'],
            'search'=>'nullable|string|max:100', 'date'=>'nullable|date_format:Y-m-d',
            'q'=>'nullable|in:pending,accepted,completed,new,preparing,courier',
        ]);
        return ['app'=>$values['app'] ?? '', 'branch'=>$values['branch'] ?? '',
            'search'=>trim($values['search'] ?? $request->input('order_no', '')),
            'date'=>$values['date'] ?? '',
            'q'=>$values['q'] ?? ''];
    }

    private function query(string $source, $actor, array $filters)
    {
        $table = ['legacy'=>'orders', 'store'=>'go_store_orders', 'service'=>'go_service_jobs', 'partner_service'=>'partner_service_requests'][$source] ?? null;
        if (!$table || !$this->has($table, 'status') || !$this->has($table, 'created_at')) return null;
        if ($source !== 'legacy' && ($filters['app'] ?? '') === 'fasakhansta') return null;
        if (in_array($source, ['service', 'partner_service'], true) && !$this->isAdmin($actor)) return null;
        $query = DB::table($table);
        if ($source === 'legacy') {
            if ($this->has($table, 'type')) $query->whereIn('type', ['current', 'schedule', 'shipping']);
            if (!$this->isAdmin($actor)) {
                if (!$this->has($table, 'resturant_id')) return null;
                $query->whereIn('resturant_id', $this->restaurantIds($actor))->where('type', 'current');
            }
            if (!empty($filters['app'])) $this->forApp($query, $filters['app']);
        } elseif ($source === 'store') {
            if (!$this->has($table, 'store_id') || !$this->has($table, 'snapshot')) return null;
            if (!$this->isAdmin($actor)) $query->where('store_id', $actor->id);
        }
        if (!empty($filters['branch'])) {
            [$kind, $id] = explode(':', $filters['branch']);
            if ($source === 'legacy' && $kind === 'f') $query->where('resturant_id', (int) $id);
            elseif ($source === 'store' && $kind === 'gs') $query->where('store_id', (int) $id);
            else return null;
        }
        if (!empty($filters['date'])) $query->whereDate('created_at', $filters['date']);
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($source, $table, $search) {
                $q->where('id', (int) preg_replace('/\D/', '', $search));
                if ($source === 'legacy' && $this->has($table, 'order_no')) $q->orWhere('order_no', 'like', '%'.$search.'%');
                if ($source === 'store') $q->orWhere('snapshot', 'like', '%'.$search.'%');
                $customer = $source === 'legacy' || $source === 'partner_service' ? 'user_id' : 'customer_id';
                if ($this->has($table, $customer) && $this->has('users', 'name')) {
                    $q->orWhereIn($customer, DB::table('users')->select('id')->where(function ($users) use ($search) {
                        $users->where('name', 'like', '%'.$search.'%');
                        if ($this->has('users', 'mobile')) $users->orWhere('mobile', 'like', '%'.$search.'%');
                    }));
                }
            });
        }
        return $query;
    }

    private function forApp($query, string $app): void
    {
        // Persisted order origin is authoritative; older rows use their customer's app identity.
        $goValues = ['go', 'go_customer', 'go_partner', 'go_drive'];
        $origins = [];
        foreach (['source_app','app_scope'] as $column) if ($this->has('orders', $column)) $origins[] = "NULLIF(orders.$column, '')";
        if ($this->has('users', 'app_scope') && $this->has('orders', 'user_id')) {
            $origins[] = "(SELECT NULLIF(board_customer.app_scope, '') FROM users AS board_customer WHERE board_customer.id = orders.user_id)";
        }
        $origins[] = "'fasakhansta'";
        $expression = count($origins) === 1 ? $origins[0] : 'COALESCE('.implode(', ', $origins).')';
        $query->whereRaw($expression.($app === 'go' ? ' IN ' : ' NOT IN ').'(?, ?, ?, ?)', $goValues);
    }

    private function forGroup($query, string $source, string $group): void
    {
        $statuses = [
            'legacy'=>['new'=>['pending','another_delegate','new_order'], 'preparing'=>['accepted'], 'courier'=>['shipped'], 'completed'=>['completed','cancelled','declined']],
            'store'=>['new'=>['pending','awaiting_payment'], 'preparing'=>['preparing','ready'], 'courier'=>['out_for_delivery'], 'completed'=>['completed','cancelled','rejected']],
            'service'=>['new'=>['searching'], 'preparing'=>['booked','disputed'], 'courier'=>['in_progress','awaiting_confirmation'], 'completed'=>['completed','cancelled','expired']],
            'partner_service'=>['new'=>['pending'], 'preparing'=>['accepted'], 'courier'=>[], 'completed'=>['completed','declined','cancelled']],
        ];
        $query->where(function ($q) use ($source, $group, $statuses) {
            $q->whereIn('status', $statuses[$source][$group]);
            if ($source === 'legacy' && $this->has('orders', 'accepted_notify')) {
                if ($group === 'new') $q->where(function ($pending) { $pending->whereNull('accepted_notify')->orWhere('accepted_notify', '!=', 'yes'); });
                if ($group === 'preparing') $q->orWhere(function ($accepted) { $accepted->whereIn('status', ['pending','another_delegate','new_order'])->where('accepted_notify', 'yes'); });
            }
            if ($source === 'legacy' && $group === 'completed') $q->orWhereNull('status');
        });
    }

    private function branches($actor): array
    {
        $branches = [];
        if ($this->has('resturants', 'name')) {
            foreach (DB::table('resturants')->whereIn('id', $this->restaurantIds($actor))->orderBy('name')->get() as $r) {
                $branches[] = ['value'=>'f:'.$r->id, 'label'=>$r->name];
            }
        }
        if ($this->has('go_stores', 'user_id')) {
            $query = DB::table('go_stores');
            if (!$this->isAdmin($actor)) $query->where('user_id', $actor->id);
            foreach ($query->orderBy('name')->get() as $s) $branches[] = ['value'=>'gs:'.$s->user_id, 'label'=>'جو · '.$s->name];
        }
        return $branches;
    }

    private function loadRelated(string $source, $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $userIds = $rows->pluck($source === 'legacy' || $source === 'partner_service' ? 'user_id' : 'customer_id')
            ->merge($rows->pluck($source === 'legacy' ? 'delegate_id' : 'partner_id'))->filter()->unique()->all();
        $this->cacheRows('users', 'id', $userIds);
        if ($source !== 'legacy') return;
        $this->cacheRows('resturants', 'id', $rows->pluck('resturant_id')->filter()->all());
        $this->cacheRows('user_address', 'id', $rows->pluck('user_address_id')->filter()->all());
        if ($this->has('carts', 'order_id')) {
            $carts = DB::table('carts')->whereIn('order_id', $ids)->get();
            foreach ($ids as $id) $this->related['carts'][$id] = [];
            foreach ($carts as $cart) $this->related['carts'][$cart->order_id][] = $cart;
            $this->cacheRows('resturant_products', 'id', $carts->pluck('resturant_product_id')->filter()->all());
            $this->cacheRows('product_features', 'id', $carts->pluck('product_feature')->filter()->all());
        }
        if ($this->has('shippings', 'order_id')) {
            foreach (DB::table('shippings')->whereIn('order_id', $ids)->get() as $shipping) $this->related['shippings'][$shipping->order_id] = $shipping;
        }
    }

    private function cacheRows(string $table, string $key, array $ids): void
    {
        if (!$ids || !$this->has($table, $key)) return;
        foreach (DB::table($table)->whereIn($key, array_unique($ids))->get() as $row) $this->related[$table][$row->$key] = $row;
    }

    private function normalize(string $source, object $row, $actor): array
    {
        $customerId = $row->user_id ?? $row->customer_id ?? null;
        $customer = $this->related['users'][$customerId] ?? null;
        $app = $source === 'legacy' ? $this->origin($row, $customer) : 'go';
        $status = $row->status ?? '';
        $created = Carbon::parse($row->created_at, config('app.timezone', 'Africa/Cairo'));
        $card = ['id'=>(int) $row->id, 'source'=>$source, 'key'=>$source.':'.$row->id,
            'app'=>$app, 'app_label'=>$app === 'go' ? 'جو' : 'فسخانستا',
            'number'=>$row->order_no ?? ($source === 'store' ? 'GS-' : ($source === 'legacy' ? '#' : 'GO-')).$row->id,
            'status'=>$status, 'status_label'=>$this->statusLabel($status), 'group'=>$this->group($source, $row),
            'created_at'=>$created->toIso8601String(), 'created_label'=>$created->format('d/m/Y H:i'),
            'elapsed'=>$created->locale('ar')->diffForHumans(), 'customer'=>$customer->name ?? 'عميل',
            'phone'=>$customer->mobile ?? '', 'store'=>'', 'address'=>'', 'payment_label'=>'', 'total'=>null,
            'items'=>[], 'notes'=>$row->notes ?? $row->description ?? '', 'actions'=>[], 'action_labels'=>[],
            'accepted_notify'=>$row->accepted_notify ?? '', 'revision'=>(int) ($row->revision ?? 0),
            'action_note'=>'', 'urls'=>['details'=>route('order-board.details', [$source, $row->id]),
                'print'=>route('order-board.print', [$source, $row->id]), 'action'=>route('order-board.action', [$source, $row->id])]];
        if ($source === 'legacy') {
            $restaurant = $this->related['resturants'][$row->resturant_id ?? null] ?? null;
            $address = $this->related['user_address'][$row->user_address_id ?? null] ?? null;
            $shipping = $this->related['shippings'][$row->id] ?? null;
            $card['store'] = $restaurant->name ?? (($row->type ?? '') === 'shipping' ? 'طلب مندوب' : 'طلب التطبيق');
            $card['address'] = $this->address($address) ?: ($shipping->to_address ?? $customer->address ?? '');
            $card['phone'] = $address->mobile ?? $customer->mobile ?? '';
            $subtotal = 0;
            foreach ($this->related['carts'][$row->id] ?? [] as $line) {
                $product = $this->related['resturant_products'][$line->resturant_product_id ?? null] ?? null;
                $feature = $this->related['product_features'][$line->product_feature ?? null] ?? null;
                $lineTotal = !empty($line->updated_total) ? (float) $line->updated_total : (float) ($line->price ?? 0) * (float) ($line->qty ?? 0);
                $subtotal += $lineTotal;
                $card['items'][] = ['name'=>$product->product_name ?? $product->name_ar ?? $line->name ?? 'صنف',
                    'quantity'=>$line->qty ?? 1, 'option_label'=>$feature ? __('main.'.$feature->name) : '',
                    'line_total'=>number_format($lineTotal, 2, '.', '')];
            }
            if (($row->type ?? '') === 'shipping') {
                $amount = ($row->delegate_from_out ?? '') === 'out_resturant' ? ($row->delivery_price ?? null) : ($shipping->actual_price ?? null);
                $card['total'] = $amount !== null ? number_format((float) $amount, 2, '.', '') : null;
                $card['notes'] = $shipping->description ?? $card['notes'];
                if ($this->isAdmin($actor) && $status === 'pending' && empty($row->delegate_id)) $card['actions'] = ['reject'];
                $card['action_note'] = 'يتم الاتفاق على عرض المندوب داخل التطبيق.';
            } else {
                if (!$card['items']) $subtotal = (float) ($row->total_price ?? 0);
                $serviceRate = $this->setting('service_fees');
                // Match the current Order::grand_total convention; tax is stored in user_tax.
                $card['total'] = number_format($subtotal + (float) ($row->delivery_price ?? 0)
                    + (float) ($row->user_tax ?? 0) + round($subtotal * $serviceRate / 100, 2), 2, '.', '');
                $card['actions'] = $this->legacyActions($row, $restaurant !== null);
                $card['action_labels'] = ['prepare'=>'مندوب الفرع', 'dispatch'=>'طلب مندوب'];
            }
            $card['payment_label'] = $this->payment($row->payment_type ?? '');
            $delegate = $this->related['users'][$row->delegate_id ?? null] ?? null;
            $card['courier'] = $delegate ? ['name'=>$delegate->name ?? '', 'phone'=>$delegate->mobile ?? ''] : null;
        } elseif ($source === 'store') {
            $snapshot = json_decode($row->snapshot ?? '{}', true) ?: [];
            $card['store'] = $snapshot['store_name'] ?? 'متجر جو';
            $card['customer'] = $snapshot['customer_name'] ?? $card['customer'];
            $card['phone'] = $snapshot['customer_mobile'] ?? $card['phone'];
            $card['address'] = $this->address((object) ($snapshot['address'] ?? []));
            if (($row->fulfillment ?? '') === 'pickup') $card['address'] = 'استلام من المتجر';
            $card['items'] = array_map(function ($line) { return ['name'=>$line['name'] ?? 'صنف', 'quantity'=>$line['quantity'] ?? 1,
                'option_label'=>$line['option_label'] ?? '', 'line_total'=>$line['line_total'] ?? null]; }, $snapshot['items'] ?? []);
            $card['notes'] = $snapshot['notes'] ?? '';
            $card['total'] = isset($row->total_cents) ? Money::decimal((int) $row->total_cents) : ($snapshot['total'] ?? null);
            $card['payment_label'] = $this->payment($row->payment_method ?? '');
            if ($this->storeActions === null) $this->storeActions = app(GoStoreBoardActions::class);
            $card['actions'] = $this->storeActions->available($row);
            if ($status === 'awaiting_payment') $card['action_note'] = 'بانتظار تأكيد الدفع قبل قبول الطلب.';
            elseif (!$this->storeActions->ready()) $card['action_note'] = 'نظام طلبات المتاجر غير مجهز على السيرفر.';
        } else {
            $profession = \App\Http\Controllers\Api\V1\PartnerApplicationController::professions()[$row->profession_key ?? '']['ar'] ?? ($row->profession_key ?? 'خدمة');
            $card['store'] = 'خدمة · '.$profession;
            $card['address'] = $row->address ?? '';
            $card['phone'] = $row->phone ?? $row->customer_phone ?? $card['phone'];
            $card['items'] = [];
            if ($source === 'service') {
                $card['total'] = !empty($row->accepted_offer_id) ? Money::decimal((int) ($row->price_cents ?? 0)) : null;
                $card['payment_label'] = $this->payment($row->payment_method ?? '');
                if ($status === 'searching' && empty($row->accepted_offer_id)) $card['actions'] = ['reject'];
                $card['action_note'] = 'يختار العميل عرض الصنايعي داخل التطبيق.';
            } else {
                if ($status === 'pending') $card['actions'] = ['accept','reject'];
                elseif ($status === 'accepted') $card['actions'] = ['complete'];
            }
        }
        return $card;
    }

    public function legacyActions(object $row, bool $hasRestaurant = true): array
    {
        if (!$hasRestaurant || ($row->type ?? '') !== 'current') return [];
        $status = $row->status ?? '';
        if (in_array($status, ['pending','another_delegate','new_order'], true) && ($row->accepted_notify ?? '') !== 'yes') return ['accept','reject'];
        if (in_array($status, ['pending','another_delegate','new_order'], true) && ($row->accepted_notify ?? '') === 'yes') return ['prepare','dispatch'];
        if ($status === 'accepted') {
            if (($row->delegate_from_out ?? '') === 'in_resturant') return ['complete'];
            if (empty($row->delegate_id)) return ['prepare','dispatch'];
        }
        return $status === 'shipped' ? ['complete'] : [];
    }

    private function origin(object $row, ?object $customer): string
    {
        $origin = !empty($row->source_app) ? $row->source_app : ($row->app_scope ?? null);
        if ($origin === null || $origin === '') $origin = $customer->app_scope ?? 'fasakhansta';
        return in_array($origin, ['go','go_customer','go_partner','go_drive'], true) ? 'go' : 'fasakhansta';
    }

    private function group(string $source, object $row): string
    {
        $status = $row->status ?? '';
        if (in_array($status, ['completed','cancelled','declined','rejected','expired',''], true)) return 'completed';
        if (in_array($status, ['shipped','out_for_delivery','in_progress','awaiting_confirmation'], true)) return 'courier';
        if (in_array($status, ['accepted','preparing','ready','booked','disputed'], true)
            || ($source === 'legacy' && ($row->accepted_notify ?? '') === 'yes')) return 'preparing';
        return 'new';
    }

    private function statusLabel(string $status): string
    {
        return ['pending'=>'طلب جديد','another_delegate'=>'بانتظار مندوب','new_order'=>'طلب جديد',
            'accepted'=>'قيد التجهيز','preparing'=>'قيد التجهيز','ready'=>'جاهز للتسليم','shipped'=>'مع المندوب',
            'out_for_delivery'=>'مع المندوب','completed'=>'تم التسليم','cancelled'=>'ملغي','declined'=>'مرفوض','rejected'=>'مرفوض',
            'awaiting_payment'=>'بانتظار الدفع','searching'=>'بانتظار العروض','booked'=>'تم الاتفاق','in_progress'=>'الخدمة جارية',
            'awaiting_confirmation'=>'بانتظار تأكيد العميل','disputed'=>'تحت المراجعة','expired'=>'انتهى البحث'][$status] ?? 'منتهي';
    }

    private function payment(string $method): string
    {
        return ['cash'=>'كاش','wallet'=>'محفظة التطبيق','card'=>'بطاقة بنكية','credit_card'=>'بطاقة بنكية',
            'mobile_wallet'=>'محفظة إلكترونية','paymob'=>'دفع إلكتروني','online'=>'دفع إلكتروني'][$method] ?? ($method ?: 'غير محدد');
    }

    private function address(?object $address): string
    {
        if (!$address) return '';
        $parts = array_filter([$address->address ?? $address->street_name ?? '', $address->area_name ?? '',
            !empty($address->floor_no) ? 'الدور '.$address->floor_no : '', !empty($address->apartment_no) ? 'شقة '.$address->apartment_no : '']);
        return implode('، ', $parts);
    }

    private function setting(string $name): int
    {
        if (!array_key_exists('setting:'.$name, $this->related)) {
            $payload = $this->has('settings', 'name') ? DB::table('settings')->where('name', $name)->value('payload') : 0;
            $this->related['setting:'.$name] = (int) filter_var($payload ?? 0, FILTER_SANITIZE_NUMBER_INT);
        }
        return $this->related['setting:'.$name];
    }

    private function has(string $table, string $column): bool
    {
        if (!array_key_exists($table, $this->columns)) $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        return in_array($column, $this->columns[$table], true);
    }
}
