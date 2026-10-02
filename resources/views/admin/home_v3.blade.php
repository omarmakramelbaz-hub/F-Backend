@extends('admin.index')

@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard-v3/dashboard-v3.css') }}?v=20261003-001">
@endpush

@section('content')
@php
$roleLabels = ['owner'=>'Owner','deputy_manager'=>'Admin','branch_manager'=>'Manager'];
$roleAr = ['owner'=>'المالك','deputy_manager'=>'أدمن إداري','branch_manager'=>'مدير الفرع'];
$activeRole = $roleLabels[$actor->role] ?? $actor->role;
$kpis = collect($dashboard['kpis']);
$stageMeta = [
    'new'=>['label'=>'جديد','tone'=>'blue','icon'=>'fa-shopping-bag'],
    'preparing'=>['label'=>'قيد التجهيز','tone'=>'orange','icon'=>'fa-fire-burner'],
    'delivery'=>['label'=>'مع المندوب','tone'=>'purple','icon'=>'fa-truck-fast'],
    'done'=>['label'=>'تم التسليم','tone'=>'green','icon'=>'fa-circle-check'],
];
$number = fn($value) => number_format((float)$value, 0);
$money = fn($value) => number_format((float)$value, 2).' ج.م';
@endphp

<div class="content-wrapper fas-home-v3" dir="rtl">
    <section class="content pt-3">
        <div class="container-fluid">
            <div class="fas-v3-page-head">
                <div>
                    <span class="eyebrow">FASAKHANSTA CONTROL CENTER</span>
                    <h1>مرحباً بك في فسخانستا</h1>
                    <p>كل ما تحتاجه لإدارة الفروع والطلبات والتشغيل في مكان واحد.</p>
                </div>
                <div class="head-date">
                    <i class="far fa-clock"></i>
                    <div>
                        <strong>{{ now(config('erp.timezone'))->locale('ar')->translatedFormat('l j F Y') }}</strong>
                        <small>{{ now(config('erp.timezone'))->format('h:i A') }} · توقيت القاهرة</small>
                    </div>
                </div>
            </div>

            <div class="fas-context-card">
                <div class="context-role">
                    <span class="context-label">نوع الحساب الحالي</span>
                    <div class="role-switcher">
                        @foreach(['Owner'=>'fa-crown','Admin'=>'fa-user-gear','Manager'=>'fa-user-tie'] as $role=>$icon)
                        <span class="role-chip {{ $activeRole === $role ? 'active' : '' }} {{ $activeRole !== $role ? 'muted' : '' }}">
                            <i class="fas {{ $icon }}"></i>{{ $role }}
                        </span>
                        @endforeach
                    </div>
                </div>

                <div class="context-divider"></div>

                <form class="branch-filter" method="get" action="{{ route('admin_dash') }}">
                    <label>اختر الفرع</label>
                    @if($actor->allBranches())
                    <select name="branch" onchange="this.form.submit()">
                        <option value="">جميع الفروع</option>
                        @foreach($branches as $item)
                        <option value="{{ $item->id }}" {{ (int)$branch === (int)$item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                        @endforeach
                    </select>
                    @else
                    <div class="branch-locked"><i class="fas fa-store"></i>{{ optional($branches->firstWhere('id',$branch))->name ?: 'الفرع' }}</div>
                    @endif
                </form>

                <div class="context-note">
                    <i class="fas fa-circle-info"></i>
                    <span>العناصر والبيانات الظاهرة تتغير تلقائياً حسب نوع الحساب والفرع المختار.</span>
                </div>
            </div>

            <div class="fas-kpi-grid">
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
                            {{ $kpi['change']['value'] }}% <em>مقارنة بالأمس</em>
                        </small>
                    </div>
                </article>
                @endforeach
            </div>

            <div class="fas-dashboard-main-grid">
                <section class="fas-panel fas-sales-panel">
                    <header class="panel-head">
                        <div>
                            <span class="panel-kicker">الأداء</span>
                            <h2>المبيعات والأداء</h2>
                        </div>
                        <span class="panel-period">آخر 7 أيام</span>
                    </header>
                    <div class="sales-metrics">
                        <div><span>مبيعات اليوم</span><strong>{{ $money($dashboard['sales_today']) }}</strong></div>
                        <div><span>متوسط قيمة الطلب</span><strong>{{ $money($dashboard['average_order']) }}</strong></div>
                        <div><span>إجمالي الطلبات 7 أيام</span><strong>{{ $number($dashboard['total_7d']) }}</strong></div>
                    </div>
                    <div class="chart-wrap"><canvas id="fasSalesChart" height="250"></canvas></div>
                </section>

                <section class="fas-panel fas-live-orders">
                    <header class="panel-head">
                        <div>
                            <span class="panel-kicker">لايف</span>
                            <h2>حالة الطلبات المباشرة اليوم</h2>
                        </div>
                        <a href="{{ url('/admin/applies-orders') }}">عرض الكل <i class="fas fa-arrow-left"></i></a>
                    </header>

                    <div class="mini-kanban">
                        @foreach(['new','preparing','delivery','done'] as $stage)
                        @php($meta=$stageMeta[$stage])
                        <div class="mini-column {{ $meta['tone'] }}">
                            <div class="mini-column-head">
                                <span><i class="fas {{ $meta['icon'] }}"></i>{{ $meta['label'] }}</span>
                                <b>{{ $number($dashboard['counts'][$stage]) }}</b>
                            </div>
                            <div class="mini-column-list">
                                @forelse($dashboard['live'][$stage] as $row)
                                <div class="mini-order">
                                    <div>
                                        <strong>{{ $row['number'] }}</strong>
                                        <small>{{ $row['customer_name'] ?: ($row['entity_name'] ?: 'عميل') }}</small>
                                    </div>
                                    <span>{{ $row['age_minutes'] < 60 ? $row['age_minutes'].' د' : floor($row['age_minutes']/60).' س' }}</span>
                                </div>
                                @empty
                                <div class="mini-empty">لا توجد طلبات</div>
                                @endforelse
                            </div>
                        </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <div class="fas-dashboard-bottom-grid">
                <section class="fas-panel quick-actions">
                    <header class="panel-head">
                        <div><span class="panel-kicker">مختصرات</span><h2>إجراءات سريعة</h2></div>
                    </header>
                    <div class="quick-grid">
                        <a class="quick-card orange" href="{{ url('/admin/orders/create') }}"><i class="fas fa-cart-plus"></i><strong>طلب جديد</strong></a>
                        @if($actor->allBranches())
                        <a class="quick-card blue" href="{{ url('/admin/resturants/create') }}"><i class="fas fa-store"></i><strong>إضافة مطعم</strong></a>
                        <a class="quick-card purple" href="{{ url('/admin/users?account_type=delegate') }}"><i class="fas fa-user-plus"></i><strong>إدارة المندوبين</strong></a>
                        @endif
                        <a class="quick-card green" href="{{ url('/admin/reports') }}"><i class="fas fa-chart-column"></i><strong>عرض التقارير</strong></a>
                    </div>
                </section>

                <section class="fas-panel latest-orders">
                    <header class="panel-head">
                        <div><span class="panel-kicker">آخر حركة</span><h2>أحدث الطلبات</h2></div>
                        <a href="{{ url('/admin/applies-orders') }}">عرض الكل <i class="fas fa-arrow-left"></i></a>
                    </header>
                    <div class="table-responsive">
                        <table class="table fas-orders-table">
                            <thead><tr>
                                <th>#</th><th>رقم الطلب</th><th>العميل</th><th>الفرع / النشاط</th><th>الحالة</th><th>الدفع</th><th>الوقت</th><th></th>
                            </tr></thead>
                            <tbody>
                            @forelse($dashboard['recent'] as $index=>$row)
                                <tr>
                                    <td>{{ $index+1 }}</td>
                                    <td><strong dir="ltr">{{ $row['number'] }}</strong></td>
                                    <td>{{ $row['customer_name'] ?: 'عميل' }}</td>
                                    <td>{{ $row['branch_name'] ?: $row['entity_name'] }}</td>
                                    <td><span class="table-status {{ $row['stage'] }}">{{ $row['status_label'] }}</span></td>
                                    <td>{{ $row['payment_label'] }}</td>
                                    <td>{{ $row['created_display'] }}</td>
                                    <td><a class="table-action" href="{{ url('/admin/applies-orders?q='.urlencode($row['number'])) }}">عرض</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted py-4">لا توجد طلبات اليوم.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </section>
</div>
@endsection

@push('custom-js')
<script>
window.FAS_DASHBOARD_V3 = @json([
    'labels'=>$dashboard['chart']['labels'],
    'sales'=>$dashboard['chart']['sales'],
    'orders'=>$dashboard['chart']['orders'],
]);
</script>
<script src="{{ asset('dashboard-v3/dashboard-v3.js') }}?v=20261003-001"></script>
@endpush
