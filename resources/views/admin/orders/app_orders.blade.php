@extends('admin.index')

@push('custom-css')
<link rel="stylesheet" href="{{ asset('erp-assets/admin-app-orders.css') }}?v=20261002-2059">
@endpush

@section('content')
@php
$kindLabels = [
    'branch' => 'فرع',
    'restaurant' => 'مطعم',
    'delivery' => 'مندوب',
    'store' => 'متجر',
    'service' => 'صنايعي',
];
$paymentStatuses = [
    'ready'=>'جاهز للدفع','pending'=>'قيد الدفع','held'=>'محجوز بالمحفظة','paid'=>'مدفوع',
    'cash_due'=>'كاش','cash_collected'=>'تم تحصيل الكاش','review'=>'مراجعة دفع',
    'refund_pending'=>'استرداد معلق','refunded'=>'تم الاسترداد','cancelled'=>'ملغي','unpaid'=>'غير مدفوع',
];
$boardColumns = [
    'new' => [
        'label'=>'الطلبات الجديدة','color'=>'purple','icon'=>'🛒',
        'count'=>($columns['new']['count'] ?? 0) + ($columns['attention']['count'] ?? 0),
        'rows'=>array_merge($columns['new']['rows'] ?? [], $columns['attention']['rows'] ?? []),
    ],
    'preparing' => [
        'label'=>'قيد التجهيز','color'=>'orange','icon'=>'♨',
        'count'=>$columns['preparing']['count'] ?? 0,'rows'=>$columns['preparing']['rows'] ?? [],
    ],
    'delivery' => [
        'label'=>'مع المندوب','color'=>'blue','icon'=>'🛵',
        'count'=>$columns['delivery']['count'] ?? 0,'rows'=>$columns['delivery']['rows'] ?? [],
    ],
    'done' => [
        'label'=>'الطلبات المنتهية','color'=>'green','icon'=>'✓',
        'count'=>$columns['done']['count'] ?? 0,'rows'=>$columns['done']['rows'] ?? [],
    ],
];
$ageLabel = function ($minutes) {
    $minutes = (int) $minutes;
    if ($minutes < 1) return 'الآن';
    if ($minutes < 60) return 'منذ '.$minutes.' دقيقة';
    if ($minutes < 1440) return 'منذ '.floor($minutes / 60).' ساعة';
    return 'منذ '.floor($minutes / 1440).' يوم';
};
@endphp

<div class="content-wrapper app-orders-dashboard" dir="rtl">
    <section class="content pt-3">
        <div class="container-fluid">
            <div class="app-orders-shell">
                <header class="app-orders-top">
                    <div class="app-orders-notify">
                        <span class="bell">◇</span>
                        <strong>{{ $stats['new'] }}</strong>
                        <small>الإشعارات</small>
                    </div>

                    <div class="app-orders-kpis">
                        <div class="kpi green"><span>✓</span><div><b>{{ $boardColumns['done']['count'] }}</b><small>تم الاستلام اليوم</small></div></div>
                        <div class="kpi blue"><span>🛵</span><div><b>{{ $boardColumns['delivery']['count'] }}</b><small>مع المندوب</small></div></div>
                        <div class="kpi orange"><span>♨</span><div><b>{{ $boardColumns['preparing']['count'] }}</b><small>قيد التجهيز</small></div></div>
                        <div class="kpi purple"><span>🛒</span><div><b>{{ $boardColumns['new']['count'] }}</b><small>الطلبات الجديدة</small></div></div>
                    </div>

                    <form class="app-orders-branch" method="get" action="{{ route('orders.applies') }}">
                        <input type="hidden" name="day" value="{{ $day }}">
                        @if(($filters['app'] ?? null))<input type="hidden" name="app" value="{{ $filters['app'] }}">@endif
                        <label>نطاق العرض</label>
                        @if($actor->allBranches())
                            <select name="branch" onchange="this.form.submit()">
                                <option value="">جميع الفروع والأنشطة</option>
                                @foreach($branches as $item)
                                    <option value="{{ $item->id }}" {{ (int)$branch === (int)$item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                @endforeach
                            </select>
                        @else
                            <div class="locked-branch">● {{ optional($branches->firstWhere('id',$branch))->name ?: 'الفرع' }}</div>
                        @endif
                    </form>
                </header>

                <details class="app-orders-filter">
                    <summary>فلترة وبحث</summary>
                    <form method="get" action="{{ route('orders.applies') }}">
                        <input type="date" name="day" value="{{ $day }}" required>
                        @if($actor->allBranches())
                            <select name="branch">
                                <option value="">كل الفروع</option>
                                @foreach($branches as $item)
                                    <option value="{{ $item->id }}" {{ (int)$branch === (int)$item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                @endforeach
                            </select>
                            <select name="app">
                                <option value="">Fasakhansta + GO</option>
                                <option value="fasakhansta" {{ ($filters['app'] ?? '') === 'fasakhansta' ? 'selected' : '' }}>Fasakhansta</option>
                                <option value="go" {{ ($filters['app'] ?? '') === 'go' ? 'selected' : '' }}>GO</option>
                            </select>
                            <select name="kind">
                                <option value="">كل الأنواع</option>
                                @foreach($kindLabels as $key=>$label)
                                    <option value="{{ $key }}" {{ ($filters['kind'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                        <select name="payment">
                            <option value="">كل طرق الدفع</option>
                            <option value="cash" {{ ($filters['payment'] ?? '') === 'cash' ? 'selected' : '' }}>كاش</option>
                            <option value="wallet" {{ ($filters['payment'] ?? '') === 'wallet' ? 'selected' : '' }}>محفظة التطبيق</option>
                            <option value="card" {{ ($filters['payment'] ?? '') === 'card' ? 'selected' : '' }}>كارت بنكي</option>
                            <option value="mobile_wallet" {{ ($filters['payment'] ?? '') === 'mobile_wallet' ? 'selected' : '' }}>محفظة إلكترونية</option>
                        </select>
                        <input type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="رقم الطلب، العميل، الهاتف، المتجر...">
                        <button type="submit" class="btn btn-primary">تطبيق</button>
                        <a class="btn btn-light" href="{{ route('orders.applies', ['day'=>$day]) }}">مسح</a>
                    </form>
                </details>

                <div class="app-orders-workspace {{ $showMenu && $menuBranch ? 'with-menu' : 'without-menu' }}">
                    @if($showMenu && $menuBranch)
                    <aside class="app-menu-panel">
                        <div class="app-menu-head">
                            <div><strong>إدارة المينيو</strong><small>{{ $menuBranch->name }}</small></div>
                            <span>⚙</span>
                        </div>
                        <div class="app-menu-counts">
                            <div class="available"><b>{{ $menuCounts['available'] }}</b><small>متوفر</small></div>
                            <div class="unavailable"><b>{{ $menuCounts['unavailable'] }}</b><small>غير متوفر</small></div>
                        </div>
                        <div class="app-menu-search"><span>⌕</span><input type="search" placeholder="ابحث عن صنف" data-admin-menu-search></div>
                        <div class="app-menu-items">
                            @forelse($menuItems as $item)
                            <div class="app-menu-item" data-admin-menu-item data-menu-name="{{ mb_strtolower($item->product_name) }}">
                                <form method="post" action="{{ route('orders.applies.menu.status', $item->id) }}">
                                    @csrf
                                    <input type="hidden" name="branch_id" value="{{ $menuBranch->id }}">
                                    <input type="hidden" name="status" value="{{ $item->status === 'show' ? 'hide' : 'show' }}">
                                    <button class="app-menu-toggle {{ $item->status === 'show' ? 'on' : 'off' }}" type="submit" title="{{ $item->status === 'show' ? 'إيقاف الصنف' : 'إتاحة الصنف' }}"><span></span></button>
                                </form>
                                <div>
                                    <strong>{{ $item->product_name }}</strong>
                                    <small>{{ number_format((float)$item->product_price, 0) }} ج · {{ $item->status === 'show' ? 'متوفر' : 'غير متوفر' }}</small>
                                </div>
                                <div class="item-icon">◉</div>
                            </div>
                            @empty
                                <div class="app-orders-empty">لا توجد أصناف مرتبطة بهذا الفرع.</div>
                            @endforelse
                        </div>
                        <a class="app-menu-manage" href="{{ url('admin/resturants/'.$menuBranch->restaurant_id) }}">إدارة الأصناف <span>▱</span></a>
                    </aside>
                    @endif

                    <section class="app-orders-board-area" data-admin-orders-live data-orders-version="{{ $version }}" data-live-new="{{ $stats['new'] }}">
                        <div class="app-orders-live"><span class="dot"></span><small>متابعة لايف</small><span data-admin-orders-last-sync>آخر تحديث الآن</span></div>

                        <div class="app-orders-board">
                            @foreach($boardColumns as $stageKey=>$column)
                            <section class="app-orders-column {{ $column['color'] }}" data-stage="{{ $stageKey }}">
                                <header>
                                    <div><span>{{ $column['icon'] }}</span><strong>{{ $column['label'] }}</strong></div>
                                    <b>{{ $column['count'] }}</b>
                                </header>

                                <div class="column-body">
                                    @forelse($column['rows'] as $order)
                                    <article class="app-order-card" data-admin-order-card data-source="{{ $order['source_app'] }}">
                                        <div class="card-top">
                                            <strong>#{{ ltrim($order['number'], '#') }}</strong>
                                            <span class="source {{ $order['source_app'] }}">{{ $order['source_app'] === 'go' ? 'GO' : 'Fasakhansta' }}</span>
                                            <small>{{ $ageLabel($order['age_minutes']) }}</small>
                                        </div>

                                        <div class="customer">
                                            <strong>{{ $order['customer_name'] ?: 'عميل' }}</strong>
                                            @if($order['customer_mobile'])<span dir="ltr">☎ {{ $order['customer_mobile'] }}</span>@endif
                                            <span>⌖ {{ $order['entity_name'] ?: ($order['branch_name'] ?: 'طلب تطبيق') }}</span>
                                            <span>▣ الدفع: {{ $order['payment_label'] }}</span>
                                        </div>

                                        @if(!empty($order['items']))
                                        <div class="items">
                                            <small>الأصناف المطلوبة</small>
                                            @foreach(array_slice($order['items'],0,4) as $item)
                                            <div><span>{{ $item['name'] }}</span><b>× {{ $item['quantity'] }}</b></div>
                                            @endforeach
                                        </div>
                                        @elseif($order['description'])
                                        <div class="items"><small>تفاصيل الطلب</small><p>{{ mb_strimwidth($order['description'],0,110,'…','UTF-8') }}</p></div>
                                        @endif

                                        @if($order['assignee_name'])
                                        <div class="assignee"><span>{{ $order['assignee_type'] === 'مندوب' ? '🛵' : '🛠' }}</span><div><small>{{ $order['assignee_type'] }}</small><strong>{{ $order['assignee_name'] }}</strong></div></div>
                                        @endif

                                        <div class="total">
                                            <span class="status-pill {{ $order['stage'] === 'attention' ? 'danger' : ($stageKey === 'done' ? 'success' : 'info') }}">{{ $order['status_label'] }}</span>
                                            @if($order['amount_cents'] !== null)<strong>{{ number_format($order['amount_cents']/100,2) }} <small>جنيه</small></strong>@endif
                                        </div>

                                        <div class="actions">
                                            @if($order['customer_mobile'])
                                                <a href="tel:{{ $order['customer_mobile'] }}">☎ اتصال</a>
                                            @endif
                                            <button type="button" data-admin-order-open>عرض التفاصيل</button>
                                        </div>

                                        <div data-admin-order-detail-source hidden>
                                            <div class="drawer-heading">
                                                <div><span class="source {{ $order['source_app'] }}">{{ $order['source_app'] === 'go' ? 'GO' : 'Fasakhansta' }}</span><span class="type">{{ $kindLabels[$order['kind']] ?? $order['kind'] }}</span></div>
                                                <h2>{{ $order['number'] }}</h2><p>{{ $order['entity_name'] }}</p>
                                            </div>
                                            <div class="drawer-grid">
                                                <div><small>الحالة</small><strong>{{ $order['status_label'] }}</strong></div>
                                                <div><small>وقت الطلب</small><strong>{{ $order['created_date'] }} · {{ $order['created_display'] }}</strong></div>
                                                <div><small>العميل</small><strong>{{ $order['customer_name'] ?: 'غير مسجل' }}</strong></div>
                                                <div><small>هاتف العميل</small><strong dir="ltr">{{ $order['customer_mobile'] ?: '—' }}</strong></div>
                                                <div><small>المسؤول الحالي</small><strong>{{ $order['assignee_name'] ?: 'لم يتم التعيين' }}</strong></div>
                                                <div><small>طريقة الدفع</small><strong>{{ $order['payment_label'] }}</strong></div>
                                                @if($order['payment_status'])<div><small>حالة الدفع</small><strong>{{ $paymentStatuses[$order['payment_status']] ?? $order['payment_status'] }}</strong></div>@endif
                                                @if($order['amount_cents'] !== null)<div><small>الإجمالي</small><strong>{{ number_format($order['amount_cents']/100,2) }} ج.م</strong></div>@endif
                                                @if($order['location'] ?? null)<div class="wide"><small>العنوان</small><strong>{{ $order['location'] }}</strong></div>@endif
                                            </div>
                                            @if($order['description'])<div class="drawer-section"><small>الوصف / الملاحظات</small><p>{{ $order['description'] }}</p></div>@endif
                                            @if(!empty($order['items']))<div class="drawer-section"><small>الأصناف</small><div class="drawer-items">@foreach($order['items'] as $item)<div><span>{{ $item['name'] }}</span><strong>× {{ $item['quantity'] }}</strong></div>@endforeach</div></div>@endif
                                        </div>
                                    </article>
                                    @empty
                                        <div class="app-orders-empty"><strong>لا توجد طلبات</strong><span>لا توجد طلبات في هذه المرحلة.</span></div>
                                    @endforelse
                                </div>
                            </section>
                            @endforeach
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>

    <div class="app-orders-drawer-backdrop" data-admin-orders-drawer hidden>
        <aside class="app-orders-drawer" role="dialog" aria-modal="true" aria-label="تفاصيل الطلب">
            <div class="drawer-top"><strong>تفاصيل الطلب</strong><button type="button" data-admin-order-close>إغلاق ×</button></div>
            <div class="drawer-content" data-admin-orders-drawer-content></div>
        </aside>
    </div>
</div>
@endsection

@push('custom-js')
<script src="{{ asset('erp-assets/admin-app-orders.js') }}?v=20261002-2059"></script>
@endpush
