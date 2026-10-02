@extends('erp.layout')
@section('page-mode','orders-console-page')
@section('title','طلبات التطبيق')
@section('subtitle','إدارة الطلبات الواردة من Fasakhansta وGO')
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

<div class="orders-console">
    <header class="orders-console-top no-print">
        <div class="orders-notify"><span class="notify-bell">♢</span><strong>{{ $stats['new'] }}</strong><small>الإشعارات</small></div>

        <div class="orders-kpis">
            <div class="kpi green"><span>✓</span><div><b>{{ $boardColumns['done']['count'] }}</b><small>تم الاستلام اليوم</small></div></div>
            <div class="kpi blue"><span>🛵</span><div><b>{{ $boardColumns['delivery']['count'] }}</b><small>مع المندوب</small></div></div>
            <div class="kpi orange"><span>♨</span><div><b>{{ $boardColumns['preparing']['count'] }}</b><small>قيد التجهيز</small></div></div>
            <div class="kpi purple"><span>🛒</span><div><b>{{ $boardColumns['new']['count'] }}</b><small>الطلبات الجديدة</small></div></div>
        </div>

        <form class="branch-selector" method="get" action="{{ route('erp.orders') }}">
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

    <details class="orders-compact-filter no-print">
        <summary>فلترة وبحث</summary>
        <form method="get" action="{{ route('erp.orders') }}">
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
            <button type="submit">تطبيق</button>
            <a class="button secondary" href="{{ route('erp.orders', ['day'=>$day]) }}">مسح</a>
        </form>
    </details>

    <div class="orders-workspace {{ $showMenu && $menuBranch ? 'with-menu' : 'without-menu' }}">
        @if($showMenu && $menuBranch)
        <aside class="menu-control-panel no-print">
            <div class="menu-panel-head">
                <div><strong>إدارة المينيو</strong><small>{{ $menuBranch->name }}</small></div>
                <span>⚙</span>
            </div>
            <div class="menu-status-cards">
                <div class="available"><b>{{ $menuCounts['available'] }}</b><small>متوفر</small></div>
                <div class="unavailable"><b>{{ $menuCounts['unavailable'] }}</b><small>غير متوفر</small></div>
            </div>
            <div class="menu-search"><span>⌕</span><input type="search" placeholder="ابحث عن صنف" data-menu-search></div>
            <div class="menu-items" data-menu-items>
                @forelse($menuItems as $item)
                <div class="menu-item" data-menu-item data-menu-name="{{ mb_strtolower($item->product_name) }}">
                    <form method="post" action="{{ route('erp.orders.menu.status', $item->id) }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $menuBranch->id }}">
                        <input type="hidden" name="status" value="{{ $item->status === 'show' ? 'hide' : 'show' }}">
                        <button class="menu-toggle {{ $item->status === 'show' ? 'on' : 'off' }}" type="submit" title="{{ $item->status === 'show' ? 'إيقاف الصنف' : 'إتاحة الصنف' }}"><span></span></button>
                    </form>
                    <div class="menu-item-text">
                        <strong>{{ $item->product_name }}</strong>
                        <small>{{ number_format((float)$item->product_price, 0) }} ج · {{ $item->status === 'show' ? 'متوفر' : 'غير متوفر' }}</small>
                    </div>
                    <div class="menu-item-icon">◉</div>
                </div>
                @empty
                    <div class="empty">لا توجد أصناف مرتبطة بهذا الفرع.</div>
                @endforelse
            </div>
            <a class="menu-manage-button" href="{{ url('admin/resturants/'.$menuBranch->restaurant_id) }}">إدارة الأصناف <span>▱</span></a>
        </aside>
        @endif

        <section class="orders-board-area" data-orders-live data-orders-version="{{ $version }}" data-live-new="{{ $stats['new'] }}">
            <div class="live-mini"><span class="live-dot"></span><small>متابعة لايف</small><span data-orders-last-sync>آخر تحديث الآن</span></div>

            <div class="reference-board">
                @foreach($boardColumns as $stageKey=>$column)
                <section class="reference-column {{ $column['color'] }}" data-stage="{{ $stageKey }}">
                    <header>
                        <div><span class="column-icon">{{ $column['icon'] }}</span><strong>{{ $column['label'] }}</strong></div>
                        <b>{{ $column['count'] }}</b>
                    </header>

                    <div class="reference-column-body">
                        @forelse($column['rows'] as $order)
                        <article class="reference-order-card" data-order-card data-source="{{ $order['source_app'] }}">
                            <div class="ref-card-top">
                                <strong>#{{ ltrim($order['number'], '#') }}</strong>
                                <span class="source-mini {{ $order['source_app'] }}">{{ $order['source_app'] === 'go' ? 'GO' : 'Fasakhansta' }}</span>
                                <small>{{ $ageLabel($order['age_minutes']) }}</small>
                            </div>

                            <div class="ref-customer">
                                <strong>{{ $order['customer_name'] ?: 'عميل' }}</strong>
                                @if($order['customer_mobile'])<span dir="ltr">☎ {{ $order['customer_mobile'] }}</span>@endif
                                <span>⌖ {{ $order['entity_name'] ?: ($order['branch_name'] ?: 'طلب تطبيق') }}</span>
                                <span>▣ الدفع: {{ $order['payment_label'] }}</span>
                            </div>

                            @if(!empty($order['items']))
                            <div class="ref-items">
                                <small>الأصناف المطلوبة</small>
                                @foreach(array_slice($order['items'],0,4) as $item)
                                <div><span>{{ $item['name'] }}</span><b>× {{ $item['quantity'] }}</b></div>
                                @endforeach
                            </div>
                            @elseif($order['description'])
                            <div class="ref-items"><small>تفاصيل الطلب</small><p>{{ mb_strimwidth($order['description'],0,110,'…','UTF-8') }}</p></div>
                            @endif

                            @if($order['assignee_name'])
                            <div class="ref-assignee"><span>{{ $order['assignee_type'] === 'مندوب' ? '🛵' : '🛠' }}</span><div><small>{{ $order['assignee_type'] }}</small><strong>{{ $order['assignee_name'] }}</strong></div></div>
                            @endif

                            <div class="ref-total">
                                <span class="badge {{ $order['stage'] === 'attention' ? 'red' : ($stageKey === 'done' ? 'green' : 'blue') }}">{{ $order['status_label'] }}</span>
                                @if($order['amount_cents'] !== null)<strong>{{ number_format($order['amount_cents']/100,2) }} <small>جنيه</small></strong>@endif
                            </div>

                            <div class="ref-actions">
                                @if($order['customer_mobile'])
                                    <a class="ref-action" href="tel:{{ $order['customer_mobile'] }}">☎ اتصال</a>
                                @endif
                                <button class="ref-action primary" type="button" data-order-open>عرض التفاصيل</button>
                            </div>

                            <div data-order-detail-source hidden>
                                <div class="drawer-order-heading">
                                    <div><span class="source-pill {{ $order['source_app'] }}">{{ $order['source_app'] === 'go' ? 'GO' : 'Fasakhansta' }}</span><span class="kind-pill">{{ $kindLabels[$order['kind']] ?? $order['kind'] }}</span></div>
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
                                    @if($order['location'] ?? null)<div class="drawer-span"><small>العنوان</small><strong>{{ $order['location'] }}</strong></div>@endif
                                </div>
                                @if($order['description'])<div class="drawer-section"><small>الوصف / الملاحظات</small><p>{{ $order['description'] }}</p></div>@endif
                                @if(!empty($order['items']))<div class="drawer-section"><small>الأصناف</small><div class="drawer-items">@foreach($order['items'] as $item)<div><span>{{ $item['name'] }}</span><strong>× {{ $item['quantity'] }}</strong></div>@endforeach</div></div>@endif
                                <div class="drawer-contact-actions">
                                    @if($order['customer_mobile'])<a class="button secondary" href="tel:{{ $order['customer_mobile'] }}">اتصال بالعميل</a><a class="button secondary" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D+/', '', $order['customer_mobile']) }}">واتساب</a>@endif
                                </div>
                            </div>
                        </article>
                        @empty
                            <div class="empty"><strong>لا توجد طلبات</strong><span>لا توجد طلبات في هذه المرحلة.</span></div>
                        @endforelse
                    </div>
                </section>
                @endforeach
            </div>
        </section>
    </div>
</div>

<div class="orders-drawer-backdrop" data-orders-drawer hidden>
    <aside class="orders-drawer" role="dialog" aria-modal="true" aria-label="تفاصيل الطلب">
        <div class="orders-drawer-top"><strong>تفاصيل الطلب</strong><button class="secondary small" type="button" data-order-close>إغلاق ×</button></div>
        <div class="orders-drawer-content" data-orders-drawer-content></div>
    </aside>
</div>
@endsection
