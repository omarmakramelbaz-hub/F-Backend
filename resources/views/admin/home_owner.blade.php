<div class="ho-owner" data-ho-owner>
    <p class="ho-caption ho-platform-caption">{{ __('home_overview.platform_scope') }}</p>
    <div class="ho-platform-grid" data-ho-platform></div>

    <section class="ho-panel ho-owner-drawers" aria-labelledby="ho-drawers-title">
        <header><div><h2 id="ho-drawers-title"><i class="fas fa-cash-register" aria-hidden="true"></i> {{ __('home_overview.branch_drawers') }}</h2><span class="ho-caption">{{ __('home_overview.owner_only') }}</span></div><div class="ho-panel-total"><small>{{ __('home_overview.total') }}</small><strong data-ho-drawer-total></strong></div></header>
        <div class="ho-small-branches" data-ho-drawers></div>
    </section>

    <section class="ho-panel ho-owner-sales" aria-labelledby="ho-sales-title">
        <header><div><h2 id="ho-sales-title"><i class="fas fa-receipt" aria-hidden="true"></i> {{ __('home_overview.branch_sales') }}</h2><span class="ho-caption">{{ __('home_overview.completed_note') }} · {{ __('home_overview.period') }}</span></div><div class="ho-panel-total"><small>{{ __('home_overview.gross') }}</small><strong data-ho-sales-total></strong></div></header>
        <div class="ho-scroll ho-owner-table-scroll" tabindex="0" role="region" aria-label="{{ __('home_overview.branch_sales') }}"><table class="ho-sales-table"><thead><tr><th scope="col">{{ __('home_overview.branch') }}</th>@foreach(['takeaway','dine','phone','app','total'] as $channel)<th scope="col">{{ __('home_overview.'.$channel) }}</th>@endforeach</tr></thead><tbody data-ho-branch-sales></tbody><tfoot data-ho-sales-footer></tfoot></table></div>
    </section>

    <section class="ho-panel ho-owner-stock" aria-labelledby="ho-stock-title">
        <header><div><h2 id="ho-stock-title"><i class="fas fa-boxes" aria-hidden="true"></i> {{ __('home_overview.branch_inventory') }}</h2><span class="ho-caption">{{ __('home_overview.current_balance') }}</span></div><a data-ho-stock-link href="{{ route('branch-stock.index') }}">{{ __('home_overview.stock_link') }}</a></header>
        <div class="ho-stock-search"><input type="search" data-ho-stock-search placeholder="{{ __('home_overview.search_stock') }}" aria-label="{{ __('home_overview.search_stock') }}"><span data-ho-stock-count></span></div>
        <div class="ho-scroll ho-stock-matrix-scroll" tabindex="0" role="region" aria-label="{{ __('home_overview.branch_inventory') }}"><table class="ho-stock-matrix"><thead data-ho-stock-head></thead><tbody data-ho-stock></tbody></table></div>
        <p class="ho-caption ho-stock-note">{{ __('home_overview.stock_matrix_note') }}</p>
    </section>

    <section class="ho-panel ho-owner-expenses" aria-labelledby="ho-expenses-title">
        <header><div><h2 id="ho-expenses-title"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i> {{ __('home_overview.branch_expenses') }}</h2><span class="ho-caption">{{ __('home_overview.approved_expenses') }} · {{ __('home_overview.period') }}</span></div><div class="ho-panel-total"><small>{{ __('home_overview.total') }}</small><strong data-ho-expenses-total></strong></div></header>
        <div class="ho-small-branches" data-ho-branch-expenses></div>
    </section>

    <section class="ho-panel ho-trend ho-owner-trend" aria-labelledby="ho-trend-title"><header><div><h2 id="ho-trend-title" data-ho-trend-title></h2><span class="ho-caption" data-ho-trend-caption></span></div><strong data-ho-trend-total></strong></header><div class="ho-chart" data-ho-chart></div><details class="ho-chart-data"><summary>{{ __('home_overview.show_chart_data') }}</summary><div class="ho-scroll"><table><tbody data-ho-trend-data></tbody></table></div></details></section>

    <section class="ho-panel ho-ranking ho-owner-ranking" aria-labelledby="ho-ranking-title"><header><h2 id="ho-ranking-title"><i class="fas fa-trophy" aria-hidden="true"></i> {{ __('home_overview.top_ten') }}</h2><span class="ho-caption">{{ __('home_overview.period') }}</span></header><div data-ho-ranking></div></section>
</div>
