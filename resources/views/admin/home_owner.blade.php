@php($financial = $boot['initial']['can_view_financials'])
<div class="ho-owner ho-command-center{{ $financial ? '' : ' ho-admin-overview' }}" data-ho-owner>
    <section class="ho-cc-top" aria-label="مركز التحكم">
        <div class="ho-cc-assistant">
            <div class="ho-cc-assistant-glow" aria-hidden="true"></div>
            <canvas data-ho-owner-hologram aria-label="المساعد الهولوجرامي التفاعلي GO"></canvas>
            <span class="ho-cc-assistant-status"><i></i> {{ __('home_overview.assistant_tag') }}</span>
        </div>
        <div class="ho-cc-top-content">
            <div class="ho-cc-toolbar">
                <form class="ho-cc-ask" data-ho-command-form>
                    <i class="fas fa-wave-square" aria-hidden="true"></i>
                    <input type="text" data-ho-command-input autocomplete="off" placeholder="{{ __('home_overview.command_placeholder') }}" aria-label="اسأل GO أو نفّذ أمرًا صوتيًا">
                    <button type="button" data-ho-voice aria-label="ابدأ الإملاء الصوتي" title="تحدث إلى GO"><i class="fas fa-microphone" aria-hidden="true"></i></button>
                    <button type="submit" class="ho-cc-send" aria-label="إرسال السؤال" title="إرسال السؤال"><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
                </form>
                <div class="ho-cc-user">
                    <div><strong>{{ auth('admin')->user()->name }}</strong><small>{{ $financial ? __('home_overview.owner_role') : __('home_overview.admin_role') }}</small></div>
                    <span class="ho-cc-user-avatar"><i class="far fa-user" aria-hidden="true"></i></span>
                    <div class="ho-cc-date"><strong data-ho-clock-date>{{ now('Africa/Cairo')->translatedFormat('l d F Y') }}</strong><small data-ho-clock-time>{{ now('Africa/Cairo')->format('h:i A') }}</small></div>
                    <span class="ho-cc-live" data-ho-updated></span>
                    <button type="button" class="ho-cc-refresh" data-ho-refresh aria-label="{{ __('home_overview.refresh') }}" title="{{ __('home_overview.refresh') }}"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                </div>
            </div>
            <p class="ho-cc-command-status" data-ho-command-status aria-live="polite">{{ __('home_overview.command_status') }}</p>
            <div class="ho-cc-primary-kpis" data-ho-owner-kpis></div>
        </div>
    </section>

    <section class="ho-cc-operations" aria-labelledby="ho-operations-title">
        <header class="ho-cc-section-heading">
            <div><h2 id="ho-operations-title">{{ __('home_overview.operations_title') }}</h2><p>{{ __('home_overview.operations_description') }}</p></div>
            <span data-ho-ops-updated>{{ __('home_overview.operations_updated') }}</span>
        </header>
        <div class="ho-cc-ops-grid" data-ho-owner-metrics>
            <p class="ho-cc-loading">جار تحميل مؤشرات التشغيل…</p>
        </div>
    </section>

    <section class="ho-cc-order-flow" aria-labelledby="ho-order-flow-title">
        <header class="ho-cc-section-heading"><div><h2 id="ho-order-flow-title">حالة الطلبات المباشرة</h2><p>الطلبات الحالية والمكتملة خلال الفترة المختارة</p></div></header>
        <div class="ho-cc-order-stages" data-ho-owner-order-flow></div>
    </section>

    <section class="ho-cc-platform" aria-labelledby="ho-platform-title">
        <header class="ho-cc-section-heading">
            <div><h2 id="ho-platform-title">{{ __('home_overview.platform_title') }}</h2><p>{{ __('home_overview.platform_scope') }}</p></div>
        </header>
        <div class="ho-platform-grid" data-ho-platform></div>
    </section>

    <section class="ho-cc-workspace" aria-label="تفاصيل الإدارة والفروع">
        <section class="ho-panel ho-owner-column ho-owner-sales" aria-labelledby="ho-sales-title">
            <header><h2 id="ho-sales-title"><i class="fas fa-receipt" aria-hidden="true"></i> {{ __($financial ? 'home_overview.compact_sales' : 'home_overview.compact_orders') }}</h2><span class="ho-caption">{{ __($financial ? 'home_overview.currency' : 'home_overview.order') }} · {{ __('home_overview.period') }}</span></header>
            <div class="ho-sales-line ho-list-labels"><span>{{ __('home_overview.branch') }}</span>@foreach(['takeaway','dine','phone','app','total'] as $channel)<span>{{ __('home_overview.'.$channel) }}</span>@endforeach</div>
            <div class="ho-owner-list" data-ho-branch-sales tabindex="0" role="region" aria-label="{{ __($financial ? 'home_overview.compact_sales' : 'home_overview.compact_orders') }}"></div>
            <div class="ho-sales-line ho-list-totals" data-ho-sales-footer></div>
        </section>
        <section class="ho-panel ho-owner-column ho-owner-stock" aria-labelledby="ho-stock-title">
            <header><h2 id="ho-stock-title"><i class="fas fa-boxes" aria-hidden="true"></i> {{ __('home_overview.compact_stock') }}</h2><a data-ho-stock-link href="{{ route('branch-stock.index') }}">{{ __('home_overview.view_all') }}</a></header>
            <label class="ho-stock-select"><span class="sr-only">{{ __('home_overview.branch') }}</span><select data-ho-stock-branch></select></label>
            <div class="ho-owner-list" data-ho-stock tabindex="0" role="region" aria-label="{{ __('home_overview.compact_stock') }}"></div>
            <div class="ho-list-totals ho-caption">{{ __('home_overview.current_balance') }}</div>
        </section>
        @if(isset($boot['initial']['owner_drawer']))
        <section class="ho-panel ho-owner-column ho-owner-drawers" aria-labelledby="ho-drawers-title" id="branch-drawers">
            <header><h2 id="ho-drawers-title"><i class="fas fa-cash-register" aria-hidden="true"></i> {{ __('home_overview.compact_drawer') }}</h2></header>
            <div class="ho-list-labels ho-caption">{{ __('home_overview.owner_only') }} · {{ __('home_overview.currency') }}</div>
            <div class="ho-drawer-actions"><span role="status" aria-live="polite" data-ho-drawer-status>{{ __('home_overview.drawers_loading') }}</span></div>
            <div class="ho-owner-list" data-ho-drawers tabindex="0" role="region" aria-label="{{ __('home_overview.compact_drawer') }}"></div>
            <div class="ho-list-totals"><span>{{ __('home_overview.total') }}</span><strong data-ho-drawer-total></strong></div>
        </section>
        @endif
        <section class="ho-panel ho-owner-column ho-owner-expenses" aria-labelledby="ho-expenses-title">
            <header><h2 id="ho-expenses-title"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> {{ __('home_overview.compact_expenses') }}</h2></header>
            <div class="ho-list-labels ho-caption">{{ __('home_overview.period') }} · {{ __('home_overview.currency') }}</div>
            <div class="ho-owner-list" data-ho-branch-expenses tabindex="0" role="region" aria-label="{{ __('home_overview.compact_expenses') }}"></div>
            <div class="ho-list-totals"><span>{{ __('home_overview.total') }}</span><strong data-ho-expenses-total></strong></div>
        </section>
        <section class="ho-panel ho-owner-column ho-owner-alerts" aria-labelledby="ho-alerts-title">
            <header><h2 id="ho-alerts-title"><i class="fas fa-bell" aria-hidden="true"></i> {{ __('home_overview.alerts') }}</h2><span class="ho-cc-alert-count" data-ho-owner-alert-count></span></header>
            <div class="ho-cc-alert-list" data-ho-owner-alerts></div>
        </section>
    </section>

    <section class="ho-cc-insights" aria-label="ملخص الأصناف والمخزون والحضور">
        <section class="ho-panel ho-cc-insight-card">
            <header><div><h2>أكثر الأصناف تكرارًا في فواتير البيع</h2><p>حسب سجلات المبيعات المرتبطة بالمخزون</p></div><i class="fas fa-star" aria-hidden="true"></i></header>
            <div class="ho-cc-top-products" data-ho-owner-top-products></div>
        </section>
        <section class="ho-panel ho-cc-insight-card">
            <header><div><h2>حالة المخزون</h2><p>الأصناف المسجلة وحدود إعادة الطلب</p></div><i class="fas fa-cube" aria-hidden="true"></i></header>
            <div class="ho-cc-stock-health" data-ho-owner-stock-health></div>
        </section>
        <section class="ho-panel ho-cc-insight-card">
            <header><div><h2>الموظفون والحضور</h2><p>ملخص يوم التشغيل الحالي</p></div><i class="fas fa-users" aria-hidden="true"></i></header>
            <div class="ho-cc-attendance" data-ho-owner-attendance></div>
        </section>
    </section>

    <section class="ho-cc-analytics">
        <section class="ho-panel ho-owner-trend">
            <header><div><h2 data-ho-trend-title></h2><span class="ho-caption" data-ho-trend-caption></span></div><strong data-ho-trend-total></strong></header>
            <div class="ho-chart" data-ho-chart></div>
            <details class="ho-chart-data"><summary>{{ __('home_overview.show_chart_data') }}</summary><div class="ho-scroll"><table><tbody data-ho-trend-data></tbody></table></div></details>
        </section>
        <section class="ho-panel ho-owner-ranking">
            <header><h2><i class="fas fa-trophy" aria-hidden="true"></i> {{ __($financial ? 'home_overview.top_ten' : 'home_overview.top_ten_orders') }}</h2><span class="ho-caption">{{ __('home_overview.period') }}</span></header>
            <div data-ho-ranking></div>
        </section>
    </section>

    <nav class="ho-cc-bottom-nav" aria-label="التنقل الرئيسي">
        <a href="{{ url('/admin/dashboard') }}" class="ho-cc-bottom-home" aria-current="page"><i class="fas fa-home" aria-hidden="true"></i><span>الرئيسية</span></a>
        <a href="{{ url('/admin/adminLogout') }}" class="ho-cc-bottom-logout"><i class="fas fa-power-off" aria-hidden="true"></i><span>خروج</span></a>
    </nav>
</div>
