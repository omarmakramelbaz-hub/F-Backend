@extends('erp.layout')
@section('title','مركز الطلبات')
@section('subtitle', $actor->allBranches() ? 'استقبال ومتابعة طلبات Fasakhansta وGO من شاشة تشغيل موحدة' : 'استقبال ومتابعة طلبات فرعك فقط')
@section('content')
@php
$stageLabels = [
    'new' => 'الطلبات الجديدة',
    'preparing' => 'قيد التنفيذ / التجهيز',
    'delivery' => 'في الطريق',
    'attention' => 'يحتاج تدخل',
    'done' => 'منتهي',
];
$kindLabels = [
    'branch' => 'فرع فسخانستا',
    'restaurant' => 'مطعم',
    'delivery' => 'مندوب',
    'store' => 'متجر',
    'service' => 'صنايعي / خدمة',
];
$paymentStatuses = [
    'ready' => 'جاهز للدفع',
    'pending' => 'الدفع قيد التنفيذ',
    'held' => 'محجوز بالمحفظة',
    'paid' => 'مدفوع',
    'cash_due' => 'كاش عند التنفيذ',
    'cash_collected' => 'تم تحصيل الكاش',
    'review' => 'مراجعة دفع',
    'refund_pending' => 'استرداد معلق',
    'refunded' => 'تم الاسترداد',
    'cancelled' => 'الدفع ملغي',
    'unpaid' => 'غير مدفوع',
];
$ageLabel = function ($minutes) {
    $minutes = (int) $minutes;
    if ($minutes < 1) return 'الآن';
    if ($minutes < 60) return 'منذ '.$minutes.' دقيقة';
    if ($minutes < 1440) return 'منذ '.floor($minutes / 60).' ساعة';
    return 'منذ '.floor($minutes / 1440).' يوم';
};
@endphp

<form class="card orders-filter no-print" method="get" action="{{ route('erp.orders') }}">
    <div class="orders-filter-grid">
        <div class="field">
            <label for="orders-day">اليوم</label>
            <input id="orders-day" type="date" name="day" value="{{ $day }}" required>
        </div>

        @if($actor->allBranches())
        <div class="field">
            <label for="orders-branch">الفرع</label>
            <select id="orders-branch" name="branch">
                <option value="">كل الفروع والأنشطة</option>
                @foreach($branches as $item)
                    <option value="{{ $item->id }}" {{ (int)$branch === (int)$item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="orders-app">التطبيق</label>
            <select id="orders-app" name="app">
                <option value="">Fasakhansta + GO</option>
                <option value="fasakhansta" {{ ($filters['app'] ?? '') === 'fasakhansta' ? 'selected' : '' }}>Fasakhansta</option>
                <option value="go" {{ ($filters['app'] ?? '') === 'go' ? 'selected' : '' }}>GO</option>
            </select>
        </div>
        <div class="field">
            <label for="orders-kind">نوع الطلب</label>
            <select id="orders-kind" name="kind">
                <option value="">كل الأنواع</option>
                @foreach($kindLabels as $key=>$label)
                    <option value="{{ $key }}" {{ ($filters['kind'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @else
        <div class="field">
            <label>نطاق الحساب</label>
            <div class="scope-lock">🔒 {{ optional($branches->firstWhere('id',$branch))->name ?: 'الفرع المحدد' }} فقط</div>
        </div>
        @endif

        <div class="field">
            <label for="orders-stage">الحالة التشغيلية</label>
            <select id="orders-stage" name="stage">
                <option value="">كل الحالات</option>
                @foreach($stageLabels as $key=>$label)
                    <option value="{{ $key }}" {{ ($filters['stage'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="orders-payment">طريقة الدفع</label>
            <select id="orders-payment" name="payment">
                <option value="">كل طرق الدفع</option>
                @foreach(['cash'=>'كاش','wallet'=>'محفظة التطبيق','card'=>'كارت بنكي','mobile_wallet'=>'محفظة إلكترونية','apple_pay'=>'Apple Pay','google_pay'=>'Google Pay'] as $key=>$label)
                    <option value="{{ $key }}" {{ ($filters['payment'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field orders-search-field">
            <label for="orders-q">بحث</label>
            <input id="orders-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="رقم الطلب، العميل، الهاتف، المتجر...">
        </div>
        <div class="orders-filter-actions">
            <button type="submit">تطبيق الفلاتر</button>
            <a class="button secondary" href="{{ route('erp.orders', ['day'=>$day]) }}">مسح الفلاتر</a>
            <button class="secondary" type="button" data-orders-refresh>تحديث الآن</button>
        </div>
    </div>
</form>

<section data-orders-live data-orders-version="{{ $version }}" data-live-new="{{ $stats['new'] }}">
    <div class="orders-summary">
        <article class="card stat">
            <span class="stat-icon">▤</span>
            <div class="stat-label">إجمالي الطلبات</div>
            <div class="stat-value">{{ $stats['total'] }}</div>
            <small>حسب الفلاتر الحالية</small>
        </article>
        <article class="card stat">
            <span class="stat-icon">●</span>
            <div class="stat-label">طلبات نشطة</div>
            <div class="stat-value">{{ $stats['active'] }}</div>
            <small>لم تنتهِ بعد</small>
        </article>
        <article class="card stat">
            <span class="stat-icon">＋</span>
            <div class="stat-label">طلبات جديدة</div>
            <div class="stat-value">{{ $stats['new'] }}</div>
            <small>تحتاج متابعة أولية</small>
        </article>
        @if($actor->allBranches())
        <article class="card stat">
            <span class="stat-icon">F</span>
            <div class="stat-label">Fasakhansta</div>
            <div class="stat-value">{{ $stats['fasakhansta'] }}</div>
            <small>الفروع والمطاعم</small>
        </article>
        <article class="card stat">
            <span class="stat-icon">GO</span>
            <div class="stat-label">GO</div>
            <div class="stat-value">{{ $stats['go'] }}</div>
            <small>متاجر، مندوبون وخدمات</small>
        </article>
        @else
        <article class="card stat">
            <span class="stat-icon">🔒</span>
            <div class="stat-label">نطاق الفرع</div>
            <div class="stat-value">{{ $stats['fasakhansta'] }}</div>
            <small>لا تظهر أي طلبات خارج الفرع</small>
        </article>
        @endif
        @if($stats['attention'] > 0)
        <article class="card stat attention-stat">
            <span class="stat-icon">!</span>
            <div class="stat-label">يحتاج تدخل</div>
            <div class="stat-value">{{ $stats['attention'] }}</div>
            <small>نزاع أو مراجعة دفع</small>
        </article>
        @endif
    </div>

    <div class="orders-live-strip">
        <span class="live-dot" aria-hidden="true"></span>
        <strong>متابعة لايف</strong>
        <span>يتم جلب أي تغيير تلقائيًا بدون Refresh كامل للصفحة.</span>
        <small data-orders-last-sync>آخر مزامنة: الآن</small>
    </div>

    <div class="board orders-board">
        @foreach($columns as $stageKey=>$column)
        <section class="board-column orders-column" data-stage="{{ $stageKey }}">
            <div class="section-header">
                <h2>{{ $stageLabels[$stageKey] }}</h2>
                <span class="badge">{{ $column['count'] }}</span>
            </div>

            @forelse($column['rows'] as $order)
            <article class="order-card order-card-clickable" data-order-card data-source="{{ $order['source_app'] }}">
                <div class="order-card-head">
                    <div>
                        <strong class="order-number">{{ $order['number'] }}</strong>
                        <div class="order-pills">
                            <span class="source-pill {{ $order['source_app'] }}">{{ $order['source_app'] === 'go' ? 'GO' : 'Fasakhansta' }}</span>
                            <span class="kind-pill">{{ $kindLabels[$order['kind']] ?? $order['kind'] }}</span>
                        </div>
                    </div>
                    <div class="order-age">
                        <strong>{{ $order['created_display'] }}</strong>
                        <small>{{ $ageLabel($order['age_minutes']) }}</small>
                    </div>
                </div>

                <div class="order-entity">
                    <strong>{{ $order['entity_name'] ?: 'طلب' }}</strong>
                    @if($order['branch_name'] && $order['branch_name'] !== $order['entity_name'])
                        <small>{{ $order['branch_name'] }}</small>
                    @endif
                </div>

                <div class="order-person">
                    <span>👤</span>
                    <div><strong>{{ $order['customer_name'] ?: 'عميل' }}</strong>@if($order['customer_mobile'])<small>{{ $order['customer_mobile'] }}</small>@endif</div>
                </div>

                @if($order['assignee_name'])
                <div class="order-person">
                    <span>{{ $order['assignee_type'] === 'مندوب' ? '🛵' : ($order['assignee_type'] === 'صنايعي' ? '🛠' : '🏪') }}</span>
                    <div><strong>{{ $order['assignee_name'] }}</strong><small>{{ $order['assignee_type'] }}</small></div>
                </div>
                @elseif(in_array($order['kind'], ['delivery','service'], true) && $order['stage'] !== 'done')
                <div class="order-person waiting">
                    <span>⌛</span><div><strong>لم يتم التعيين بعد</strong><small>{{ $order['kind'] === 'delivery' ? 'بانتظار مندوب' : 'بانتظار صنايعي / عرض' }}</small></div>
                </div>
                @endif

                @if($order['description'])
                    <p class="order-description">{{ mb_strimwidth($order['description'], 0, 95, '…', 'UTF-8') }}</p>
                @endif

                <div class="order-status-row">
                    <span class="badge {{ $order['stage'] === 'done' ? 'green' : ($order['stage'] === 'attention' ? 'red' : ($order['stage'] === 'new' ? 'orange' : 'blue')) }}">{{ $order['status_label'] }}</span>
                    @if($order['amount_cents'] !== null)
                        <strong class="order-amount">{{ number_format($order['amount_cents']/100, 2) }} ج.م</strong>
                    @endif
                </div>

                <div class="order-card-foot">
                    <span>{{ $order['payment_label'] }}</span>
                    @if($order['payment_status'])
                        <small>{{ $paymentStatuses[$order['payment_status']] ?? $order['payment_status'] }}</small>
                    @endif
                    <button type="button" class="secondary small" data-order-open>عرض التفاصيل</button>
                </div>

                <div data-order-detail-source hidden>
                    <div class="drawer-order-heading">
                        <div>
                            <span class="source-pill {{ $order['source_app'] }}">{{ $order['source_app'] === 'go' ? 'GO' : 'Fasakhansta' }}</span>
                            <span class="kind-pill">{{ $kindLabels[$order['kind']] ?? $order['kind'] }}</span>
                        </div>
                        <h2>{{ $order['number'] }}</h2>
                        <p>{{ $order['entity_name'] }}</p>
                    </div>

                    <div class="drawer-grid">
                        <div><small>الحالة</small><strong>{{ $order['status_label'] }}</strong></div>
                        <div><small>وقت الطلب</small><strong>{{ $order['created_date'] }} · {{ $order['created_display'] }}</strong></div>
                        <div><small>العميل</small><strong>{{ $order['customer_name'] ?: 'غير مسجل' }}</strong></div>
                        <div><small>هاتف العميل</small><strong dir="ltr">{{ $order['customer_mobile'] ?: '—' }}</strong></div>
                        <div><small>المسؤول الحالي</small><strong>{{ $order['assignee_name'] ?: 'لم يتم التعيين' }}</strong></div>
                        <div><small>النوع</small><strong>{{ $order['assignee_type'] ?: ($kindLabels[$order['kind']] ?? '—') }}</strong></div>
                        <div><small>طريقة الدفع</small><strong>{{ $order['payment_label'] }}</strong></div>
                        <div><small>حالة الدفع</small><strong>{{ $order['payment_status'] ? ($paymentStatuses[$order['payment_status']] ?? $order['payment_status']) : '—' }}</strong></div>
                        @if($order['amount_cents'] !== null)
                        <div><small>إجمالي الطلب</small><strong>{{ number_format($order['amount_cents']/100, 2) }} ج.م</strong></div>
                        @endif
                        @if($order['location'] ?? null)
                        <div class="drawer-span"><small>العنوان</small><strong>{{ $order['location'] }}</strong></div>
                        @endif
                    </div>

                    @if($order['description'])
                    <div class="drawer-section">
                        <small>الوصف / الملاحظات</small>
                        <p>{{ $order['description'] }}</p>
                    </div>
                    @endif

                    @if(!empty($order['items']))
                    <div class="drawer-section">
                        <small>الأصناف</small>
                        <div class="drawer-items">
                            @foreach($order['items'] as $item)
                            <div>
                                <span>{{ $item['name'] }} @if($item['option_label'])<small>({{ $item['option_label'] }})</small>@endif</span>
                                <strong>× {{ $item['quantity'] }}</strong>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="drawer-contact-actions">
                        @if($order['customer_mobile'])
                            <a class="button secondary" href="tel:{{ $order['customer_mobile'] }}">اتصال بالعميل</a>
                            <a class="button secondary" target="_blank" rel="noopener" href="https://wa.me/{{ preg_replace('/\D+/', '', $order['customer_mobile']) }}">واتساب</a>
                        @endif
                        @if($order['assignee_mobile'])
                            <a class="button secondary" href="tel:{{ $order['assignee_mobile'] }}">اتصال بـ {{ $order['assignee_type'] ?: 'المسؤول' }}</a>
                        @endif
                    </div>
                </div>
            </article>
            @empty
                <div class="empty"><strong>لا توجد طلبات</strong><span>لا توجد طلبات في هذه المرحلة حسب الفلاتر الحالية.</span></div>
            @endforelse

            @if($column['count'] > 60)
                <p class="note">يظهر أحدث 60 طلبًا من {{ $column['count'] }} في هذه المرحلة.</p>
            @endif
        </section>
        @endforeach
    </div>
</section>

<div class="orders-drawer-backdrop" data-orders-drawer hidden>
    <aside class="orders-drawer" role="dialog" aria-modal="true" aria-label="تفاصيل الطلب">
        <div class="orders-drawer-top">
            <strong>تفاصيل الطلب</strong>
            <button class="secondary small" type="button" data-order-close>إغلاق ×</button>
        </div>
        <div class="orders-drawer-content" data-orders-drawer-content></div>
    </aside>
</div>
@endsection
