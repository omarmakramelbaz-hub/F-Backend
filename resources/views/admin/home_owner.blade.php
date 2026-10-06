<div class="ho-owner" data-ho-owner>
    <p class="ho-caption ho-platform-caption">{{ __('home_overview.platform_scope') }}</p>
    <div class="ho-platform-grid" data-ho-platform></div>
    <section class="ho-panel ho-owner-middle" aria-label="{{ __('home_overview.branches_title') }}">
        <section class="ho-owner-column ho-owner-sales" aria-labelledby="ho-sales-title">
            <header><h2 id="ho-sales-title"><i class="fas fa-receipt" aria-hidden="true"></i> {{ __('home_overview.compact_sales') }}</h2><span class="ho-caption">{{ __('home_overview.currency') }} · {{ __('home_overview.period') }}</span></header>
            <div class="ho-sales-line ho-list-labels"><span>{{ __('home_overview.branch') }}</span>@foreach(['takeaway','dine','phone','app','total'] as $channel)<span>{{ __('home_overview.'.$channel) }}</span>@endforeach</div>
            <div class="ho-owner-list" data-ho-branch-sales tabindex="0" role="region" aria-label="{{ __('home_overview.compact_sales') }}"></div>
            <div class="ho-sales-line ho-list-totals" data-ho-sales-footer></div>
        </section>
        <section class="ho-owner-column ho-owner-stock" aria-labelledby="ho-stock-title">
            <header><h2 id="ho-stock-title"><i class="fas fa-boxes" aria-hidden="true"></i> {{ __('home_overview.compact_stock') }}</h2><a data-ho-stock-link href="{{ route('branch-stock.index') }}">{{ __('home_overview.view_all') }}</a></header>
            <label class="ho-stock-select"><span class="sr-only">{{ __('home_overview.ingredient') }}</span><select data-ho-stock-ingredient></select></label>
            <div class="ho-owner-list" data-ho-stock tabindex="0" role="region" aria-label="{{ __('home_overview.compact_stock') }}"></div>
            <div class="ho-list-totals ho-caption">{{ __('home_overview.current_balance') }}</div>
        </section>
        <section class="ho-owner-column ho-owner-drawers" aria-labelledby="ho-drawers-title">
            <header><h2 id="ho-drawers-title"><i class="fas fa-cash-register" aria-hidden="true"></i> {{ __('home_overview.compact_drawer') }}</h2></header>
            <div class="ho-list-labels ho-caption">{{ __('home_overview.owner_only') }} · {{ __('home_overview.currency') }}</div>
            <div class="ho-owner-list" data-ho-drawers tabindex="0" role="region" aria-label="{{ __('home_overview.compact_drawer') }}"></div>
            <div class="ho-list-totals"><span>{{ __('home_overview.total') }}</span><strong data-ho-drawer-total></strong></div>
        </section>
        <section class="ho-owner-column ho-owner-expenses" aria-labelledby="ho-expenses-title">
            <header><h2 id="ho-expenses-title"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> {{ __('home_overview.compact_expenses') }}</h2></header>
            <div class="ho-list-labels ho-caption">{{ __('home_overview.period') }} · {{ __('home_overview.currency') }}</div>
            <div class="ho-owner-list" data-ho-branch-expenses tabindex="0" role="region" aria-label="{{ __('home_overview.compact_expenses') }}"></div>
            <div class="ho-list-totals"><span>{{ __('home_overview.total') }}</span><strong data-ho-expenses-total></strong></div>
        </section>
    </section>
    <section class="ho-panel ho-owner-bottom" aria-label="{{ __('home_overview.sales_trend') }}">
        <section class="ho-owner-trend"><header><div><h2 data-ho-trend-title></h2><span class="ho-caption" data-ho-trend-caption></span></div><strong data-ho-trend-total></strong></header><div class="ho-chart" data-ho-chart></div><details class="ho-chart-data"><summary>{{ __('home_overview.show_chart_data') }}</summary><div class="ho-scroll"><table><tbody data-ho-trend-data></tbody></table></div></details></section>
        <section class="ho-owner-ranking"><header><h2><i class="fas fa-trophy" aria-hidden="true"></i> {{ __('home_overview.top_ten') }}</h2><span class="ho-caption">{{ __('home_overview.period') }}</span></header><div data-ho-ranking></div></section>
    </section>
</div>
