@extends('admin.index')

@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard-v3/dashboard-v3.css') }}?v=20261003-003">
@endpush

@section('content')
@php
$roleLabels = ['owner'=>'Owner','deputy_manager'=>'Admin','branch_manager'=>'Manager'];
$roleAr = ['owner'=>'المالك','deputy_manager'=>'أدمن إداري','branch_manager'=>'مدير الفرع'];
$activeRole = $roleLabels[$actor->role] ?? $actor->role;
$stageMeta = [
    'new'=>['label'=>'جديد','tone'=>'blue','icon'=>'fa-bag-shopping'],
    'preparing'=>['label'=>'قيد التجهيز','tone'=>'orange','icon'=>'fa-fire-burner'],
    'delivery'=>['label'=>'مع المندوب','tone'=>'purple','icon'=>'fa-truck-fast'],
    'done'=>['label'=>'تم التسليم','tone'=>'green','icon'=>'fa-circle-check'],
];
$number = fn($value) => number_format((float)$value, 0);
$money = fn($value) => number_format((float)$value, 2).' ج.م';
$periodKey = $dashboard['period']['key'] ?? 'day';
$periodUrl = function ($key) use ($branch, $dashboard) {
    return route('admin_dash', array_filter([
        'branch' => $branch,
        'period' => $key,
        'to' => $dashboard['period']['to'] ?? null,
    ], fn($value) => $value !== null && $value !== ''));
};
$ageLabel = function ($minutes) {
    $minutes = (int) $minutes;
    if ($minutes < 1) return 'الآن';
    if ($minutes < 60) return 'منذ '.$minutes.' دقيقة';
    if ($minutes < 1440) return 'منذ '.floor($minutes / 60).' ساعة';
    return 'منذ '.floor($minutes / 1440).' يوم';
};
@endphp

<div class="content-wrapper fas-home-v3" dir="rtl">
    <section class="content">
        <div class="container-fluid fas-dashboard-canvas">
            <header class="fas-welcome-row">
                <div class="fas-welcome-copy">
                    <span class="fas-eyebrow">FASAKHANSTA CONTROL CENTER</span>
                    <h1>مرحبًا بك في <strong>فسخانستا</strong></h1>
                    <p>كل ما تحتاجه لإدارة مطاعمك وفروعك وطلباتك في مكان واحد.</p>
                </div>
                <div class="fas-welcome-date">
                    <i class="far fa-clock"></i>
                    <div>
                        <strong>{{ now(config('erp.timezone'))->locale('ar')->translatedFormat('l j F Y') }}</strong>
                        <small>الساعة {{ now(config('erp.timezone'))->format('h:i A') }} · توقيت القاهرة</small>
                    </div>
                </div>
            </header>

            <section class="fas-control-strip">
                <div class="fas-role-control">
                    <span class="control-caption">نوع الحساب الحالي:</span>
                    <div class="role-switcher">
                        @foreach(['Owner'=>'fa-crown','Admin'=>'fa-user-gear','Manager'=>'fa-user'] as $role=>$icon)
                        <span class="role-chip {{ $activeRole === $role ? 'active' : '' }}">
                            <i class="fas {{ $icon }}"></i>{{ $role }}
                        </span>
                        @endforeach
                    </div>
                </div>

                <div class="control-separator"></div>

                <form class="fas-branch-control" method="get" action="{{ route('admin_dash') }}">
                    <input type="hidden" name="period" value="{{ $periodKey }}">
                    <input type="hidden" name="from" value="{{ $dashboard['period']['from'] ?? '' }}">
                    <input type="hidden" name="to" value="{{ $dashboard['period']['to'] ?? '' }}">
                    <label><i class="fas fa-store"></i> اختر الفرع</label>
                    @if($actor->allBranches())
                    <select name="branch" onchange="this.form.submit()">
                        <option value="">جميع الفروع</option>
                        @foreach($branches as $item)
                        <option value="{{ $item->id }}" {{ (int)$branch === (int)$item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                        @endforeach
                    </select>
                    @else
                    <div class="branch-locked"><i class="fas fa-lock"></i>{{ optional($branches->firstWhere('id',$branch))->name ?: 'الفرع' }}</div>
                    @endif
                </form>

                <div class="fas-period-control">
                    <span class="control-caption">فترة التقرير</span>
                    <div class="period-tabs">
                        <a href="{{ $periodUrl('day') }}" class="{{ $periodKey === 'day' ? 'active' : '' }}"><i class="far fa-calendar"></i> اليوم</a>
                        <a href="{{ $periodUrl('week') }}" class="{{ $periodKey === 'week' ? 'active' : '' }}"><i class="far fa-calendar"></i> الأسبوع</a>
                        <a href="{{ $periodUrl('month') }}" class="{{ $periodKey === 'month' ? 'active' : '' }}"><i class="far fa-calendar"></i> الشهر</a>
                        <button type="button" class="{{ $periodKey === 'custom' ? 'active' : '' }}" data-custom-period-toggle><i class="far fa-calendar-days"></i> تحديد تواريخ</button>
                    </div>

                    <form class="custom-period-form {{ $periodKey === 'custom' ? 'open' : '' }}" method="get" action="{{ route('admin_dash') }}" data-custom-period-form>
                        <input type="hidden" name="period" value="custom">
                        @if($branch)<input type="hidden" name="branch" value="{{ $branch }}">@endif
                        <label>من تاريخ
                            <input type="date" name="from" value="{{ $dashboard['period']['from'] ?? now(config('erp.timezone'))->format('Y-m-d') }}" required>
                        </label>
                        <label>إلى تاريخ
                            <input type="date" name="to" value="{{ $dashboard['period']['to'] ?? now(config('erp.timezone'))->format('Y-m-d') }}" required>
                        </label>
                        <button type="submit"><i class="fas fa-chart-column"></i> عرض البيانات</button>
                    </form>
                </div>
            </section>

            <section class="fas-kpi-grid">
                @foreach($dashboard['kpis'] as $kpi)
                <article class="fas-kpi-card {{ $kpi['tone'] }}">
                    <div class="kpi-icon">
                        @if($kpi['icon']==='clipboard')<i class="fas fa-clipboard-list"></i>
                        @elseif($kpi['icon']==='cart')<i class="fas fa-cart-shopping"></i>
                        @elseif($kpi['icon']==='chef')<i class="fas fa-fire-burner"></i>
                        @elseif($kpi['icon']==='truck')<i class="fas fa-truck-fast"></i>
                        @elseif($kpi['icon']==='check')<i class="fas fa-circle-check"></i>
                        @else<i class="fas fa-sack-dollar"></i>@endif
                    </div>
                    <div class="kpi-body">
                        <span>{{ $kpi['label'] }}</span>
                        <strong>{{ !empty($kpi['money']) ? $money($kpi['value']) : $number($kpi['value']) }}</strong>
                        <small class="change {{ $kpi['change']['direction'] }}">
                            @if($kpi['change']['direction']==='up')<i class="fas fa-arrow-up"></i>
                            @elseif($kpi['change']['direction']==='down')<i class="fas fa-arrow-down"></i>
                            @else<i class="fas fa-minus"></i>@endif
                            {{ $kpi['change']['value'] }}%
                            <em>{{ $dashboard['comparison_label'] }}</em>
                        </small>
                    </div>
                </article>
                @endforeach
            </section>

            <section class="fas-primary-grid">
                <article class="fas-panel fas-sales-panel">
                    <header class="panel-head">
                        <div>
                            <span class="panel-kicker">الأداء</span>
                            <h2><i class="fas fa-chart-column"></i> المبيعات والأداء</h2>
                        </div>
                        <span class="panel-period">{{ $dashboard['period']['display'] }}</span>
                    </header>
                    <div class="sales-metrics">
                        <div>
                            <span>مبيعات {{ $dashboard['period']['label'] }}</span>
                            <strong>{{ $money($dashboard['sales_period']) }}</strong>
                            <i class="fas fa-arrow-trend-up"></i>
                        </div>
                        <div>
                            <span>متوسط قيمة الطلب</span>
                            <strong>{{ $money($dashboard['average_order']) }}</strong>
                            <i class="fas fa-chart-simple"></i>
                        </div>
                        <div>
                            <span>إجمالي الطلبات</span>
                            <strong>{{ $number($dashboard['total_period']) }}</strong>
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                    <div class="chart-wrap"><canvas id="fasSalesChart"></canvas></div>
                </article>

                <article class="fas-panel fas-live-orders">
                    <header class="panel-head">
                        <div>
                            <span class="panel-kicker">لايف</span>
                            <h2><i class="fas fa-box-open"></i> حالة الطلبات المباشرة اليوم</h2>
                        </div>
                        <a href="{{ url('/admin/applies-orders') }}">عرض الكل <i class="fas fa-chevron-left"></i></a>
                    </header>
                    <div class="mini-kanban">
                        @foreach(['new','preparing','delivery','done'] as $stage)
                        @php($meta=$stageMeta[$stage])
                        <section class="mini-column {{ $meta['tone'] }}">
                            <div class="mini-column-head">
                                <span><i class="fas {{ $meta['icon'] }}"></i>{{ $meta['label'] }}</span>
                                <b>{{ $number($dashboard['live_counts'][$stage] ?? 0) }}</b>
                            </div>
                            <div class="mini-column-list">
                                @forelse($dashboard['live'][$stage] as $row)
                                <div class="mini-order">
                                    <div class="mini-order-main">
                                        <strong>{{ $row['number'] }}</strong>
                                        <small>{{ $ageLabel($row['age_minutes'] ?? 0) }}</small>
                                        <span>{{ $row['customer_name'] ?: ($row['entity_name'] ?: 'عميل') }}</span>
                                    </div>
                                    <i class="fas {{ $meta['icon'] }}"></i>
                                </div>
                                @empty
                                <div class="mini-empty">لا توجد طلبات</div>
                                @endforelse
                            </div>
                            <a class="mini-column-link" href="{{ url('/admin/applies-orders?stage='.$stage) }}">عرض جميع الطلبات</a>
                        </section>
                        @endforeach
                    </div>
                </article>

                <article class="fas-panel fas-events-panel">
                    <header class="panel-head">
                        <div>
                            <span class="panel-kicker">النشاط</span>
                            <h2><i class="fas fa-bell"></i> الأحداث والنشاطات</h2>
                        </div>
                        <span class="panel-period">{{ $dashboard['period']['label'] }}</span>
                    </header>
                    <div class="activity-timeline">
                        @forelse($dashboard['events'] as $event)
                        <div class="activity-item {{ $event['tone'] }}">
                            <div class="activity-icon"><i class="fas fa-{{ $event['icon'] }}"></i></div>
                            <div class="activity-copy">
                                <strong>{{ $event['title'] }}</strong>
                                <span>{{ $event['description'] }}</span>
                                <small>{{ $event['time'] }}</small>
                            </div>
                        </div>
                        @empty
                        <div class="activity-empty">
                            <i class="far fa-bell-slash"></i>
                            <span>لا توجد أحداث في الفترة المحددة</span>
                        </div>
                        @endforelse
                    </div>
                </article>
            </section>

            <section class="fas-bottom-grid">
                <div class="fas-bottom-left">
                    <article class="fas-panel fas-quick-reports">
                        <header class="panel-head">
                            <div>
                                <span class="panel-kicker">مختصر</span>
                                <h2><i class="fas fa-chart-column"></i> التقارير السريعة</h2>
                            </div>
                            <a href="{{ url('/admin/reports') }}">عرض الكل <i class="fas fa-chevron-left"></i></a>
                        </header>
                        <div class="quick-report-grid">
                            @foreach($dashboard['quick_reports'] as $report)
                            <a href="{{ $periodUrl($report['key']) }}" class="quick-report-card {{ $report['tone'] }}">
                                <i class="fas {{ $report['key']==='day' ? 'fa-sack-dollar' : 'fa-chart-simple' }}"></i>
                                <span>{{ $report['label'] }}</span>
                                <strong>{{ $money($report['value']) }}</strong>
                                <small class="{{ $report['change']['direction'] }}">
                                    @if($report['change']['direction']==='up')↑@elseif($report['change']['direction']==='down')↓@else—@endif
                                    {{ $report['change']['value'] }}%
                                </small>
                            </a>
                            @endforeach
                        </div>
                    </article>

                    <article class="fas-panel fas-quick-actions">
                        <header class="panel-head">
                            <div>
                                <span class="panel-kicker">اختصارات</span>
                                <h2><i class="fas fa-bolt"></i> إجراءات سريعة</h2>
                            </div>
                        </header>
                        <div class="quick-action-grid">
                            <a class="quick-action orange" href="{{ url('/admin/orders/create') }}"><i class="fas fa-cart-plus"></i><span>طلب جديد</span></a>
                            @if($actor->allBranches())
                            <a class="quick-action blue" href="{{ url('/admin/resturants/create') }}"><i class="fas fa-store"></i><span>إضافة مطعم</span></a>
                            <a class="quick-action purple" href="{{ url('/admin/users?account_type=delegate') }}"><i class="fas fa-user-plus"></i><span>إدارة المندوبين</span></a>
                            @endif
                            <a class="quick-action green" href="{{ url('/admin/reports') }}"><i class="fas fa-chart-column"></i><span>عرض التقارير</span></a>
                        </div>
                    </article>
                </div>

                <article class="fas-panel fas-latest-orders">
                    <header class="panel-head">
                        <div>
                            <span class="panel-kicker">آخر حركة</span>
                            <h2><i class="fas fa-file-invoice"></i> أحدث الطلبات</h2>
                        </div>
                        <a href="{{ url('/admin/applies-orders') }}">عرض الكل <i class="fas fa-chevron-left"></i></a>
                    </header>
                    <div class="table-responsive">
                        <table class="table fas-orders-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>رقم الطلب</th>
                                <th>العميل</th>
                                <th>الفرع / النشاط</th>
                                <th>الحالة</th>
                                <th>القيمة</th>
                                <th>الدفع</th>
                                <th>الوقت</th>
                                <th>الإجراءات</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($dashboard['recent'] as $index=>$row)
                            <tr>
                                <td>{{ $index+1 }}</td>
                                <td><strong dir="ltr">{{ $row['number'] }}</strong></td>
                                <td>{{ $row['customer_name'] ?: 'عميل' }}</td>
                                <td>{{ $row['branch_name'] ?: $row['entity_name'] }}</td>
                                <td><span class="table-status {{ $row['stage'] }}">{{ $row['status_label'] }}</span></td>
                                <td>{{ $row['amount_cents'] !== null ? $money($row['amount_cents']/100) : '—' }}</td>
                                <td>{{ $row['payment_label'] }}</td>
                                <td>{{ $ageLabel($row['age_minutes'] ?? 0) }}</td>
                                <td><a class="table-action" href="{{ url('/admin/applies-orders?q='.urlencode($row['number'])) }}">عرض</a></td>
                            </tr>
                            @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">لا توجد طلبات في الفترة المحددة.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        </div>
    </section>
</div>
@endsection

@push('custom-js')
<script>
window.FAS_DASHBOARD_V3 = {!! json_encode([
    'labels' => $dashboard['chart']['labels'],
    'sales' => $dashboard['chart']['sales'],
    'orders' => $dashboard['chart']['orders'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
</script>
<script src="{{ asset('dashboard-v3/dashboard-v3.js') }}?v=20261003-003"></script>
@endpush
