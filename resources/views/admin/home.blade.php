@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/home-overview.css') }}?v={{ filemtime(public_path('dashboard/css/home-overview.css')) }}">
@endpush
@section('content')
<div class="content-wrapper ho-wrapper">
<section id="home-overview" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}" aria-labelledby="ho-title">
    <header class="ho-heading">
        <div><span class="ho-eyebrow">{{ __('home_overview.eyebrow') }}</span><h1 id="ho-title">{{ __('home_overview.title') }}</h1><p>{{ __('home_overview.subtitle') }}</p></div>
        <div class="ho-heading-tools"><span class="ho-updated"><i></i><span data-ho-updated></span></span><button type="button" class="ho-button" data-ho-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> {{ __('home_overview.refresh') }}</button></div>
    </header>
    <form class="ho-filters" data-ho-filters>
        <label class="ho-branch-label"><i class="fas fa-store" aria-hidden="true"></i><span class="sr-only">{{ __('home_overview.branch') }}</span><select name="branch" aria-label="{{ __('home_overview.branch') }}"><option value="">{{ __('home_overview.all_branches') }}</option>@foreach($boot['initial']['branches'] as $branch)<option value="{{ $branch['value'] }}">{{ $branch['name'] }}</option>@endforeach</select></label>
        <div class="ho-periods" role="group" aria-label="{{ __('home_overview.period') }}">@foreach(['today','week','month','custom'] as $period)<button type="button" data-ho-period="{{ $period }}" aria-pressed="false">{{ __('home_overview.'.$period) }}</button>@endforeach</div>
        <div class="ho-dates" data-ho-dates hidden><label>{{ __('home_overview.from') }}<input type="date" data-fp-omit name="from" required></label><label>{{ __('home_overview.to') }}<input type="date" data-fp-omit name="to" required></label><button class="ho-primary" type="submit">{{ __('home_overview.apply') }}</button></div>
        <span class="ho-range" data-ho-range></span>
    </form>
    <div class="ho-notice" role="status" data-ho-notice hidden></div>
    <p class="ho-notice" data-ho-empty hidden>{{ __('home_overview.no_branches') }}</p>
    <div data-ho-content>
        @if(isset($boot['initial']['owner_platform']))
            @include('admin.home_owner')
        @else
        <div class="ho-kpis" data-ho-kpis></div>
        <div class="ho-workspace">
            <aside class="ho-panel ho-alerts"><header><h2><i class="fas fa-bell" aria-hidden="true"></i> {{ __('home_overview.alerts') }}</h2><span class="ho-count" data-ho-alert-count></span></header><div data-ho-alerts></div><p class="ho-caption">{{ __('home_overview.now') }} · {{ __('home_overview.scope') }}</p></aside>
            <div class="ho-main">
                <div class="ho-charts">
                    <section class="ho-panel ho-trend"><header><div><h2 data-ho-trend-title></h2><span class="ho-caption" data-ho-trend-caption></span></div><strong data-ho-trend-total></strong></header><div class="ho-chart" data-ho-chart></div><details class="ho-chart-data"><summary>{{ __('home_overview.show_chart_data') }}</summary><div class="ho-scroll"><table><tbody data-ho-trend-data></tbody></table></div></details></section>
                    <section class="ho-panel ho-distribution"><header><h2>{{ __('home_overview.channels') }}</h2></header><div class="ho-donut" data-ho-donut><div><strong data-ho-completed></strong><small>{{ __('home_overview.order') }}</small></div></div><div class="ho-legend" data-ho-channels></div></section>
                    <section class="ho-panel ho-ranking"><header><h2>{{ __('home_overview.ranking') }}</h2><span class="ho-caption">{{ __('home_overview.period') }}</span></header><div data-ho-ranking></div></section>
                </div>
                <div class="ho-live" data-ho-live></div>
                <div class="ho-detail-grid">
                    <section class="ho-panel ho-stock"><header><div><h2><i class="fas fa-boxes" aria-hidden="true"></i> {{ __('home_overview.inventory') }}</h2><span class="ho-caption">{{ __('home_overview.inventory_note') }}</span></div><a data-ho-stock-link href="{{ route('branch-stock.index') }}">{{ __('home_overview.view_all') }}</a></header><div class="ho-stock-search"><input type="search" data-ho-stock-search placeholder="{{ __('home_overview.search_stock') }}" aria-label="{{ __('home_overview.search_stock') }}"><span data-ho-stock-count></span></div><div class="ho-scroll"><table><thead><tr><th>{{ __('home_overview.ingredient') }}</th><th>{{ __('home_overview.balance') }}</th><th>{{ __('home_overview.coverage') }}</th></tr></thead><tbody data-ho-stock></tbody></table></div><p class="ho-caption ho-stock-note">{{ __('home_overview.current_balance') }}</p></section>
                    <section class="ho-panel ho-branches"><header><div><h2>{{ __('home_overview.branches_title') }}</h2><span class="ho-caption">{{ __('home_overview.branch_state') }} · {{ __('home_overview.now') }}</span></div><div class="ho-branch-controls"><span data-ho-branch-count></span><button type="button" data-ho-branch-prev aria-label="{{ __('home_overview.previous_branch') }}"><i class="fas fa-chevron-right" aria-hidden="true"></i></button><button type="button" data-ho-branch-next aria-label="{{ __('home_overview.next_branch') }}"><i class="fas fa-chevron-left" aria-hidden="true"></i></button></div></header><div class="ho-branch-list" data-ho-branches></div></section>
                    <section class="ho-panel ho-recent"><header><h2>{{ __('home_overview.recent') }}</h2><a data-ho-orders-link href="{{ route('orders.applies') }}">{{ __('home_overview.view_all') }}</a></header><div class="ho-scroll" data-ho-recent></div></section>
                </div>
            </div>
        </div>
        <div class="ho-app-metrics" data-ho-app></div>
        <div class="ho-summary" data-ho-summary></div>
        @endif
        <details class="ho-method"><summary>{{ __('home_overview.method_title') }}</summary>@if(isset($boot['initial']['owner_platform']))<p>{{ __('home_overview.platform_method') }}</p>@endif<p>{{ __('home_overview.method') }}</p><p data-ho-drawer-note hidden>{{ __('home_overview.drawer_note') }}</p><p data-ho-legacy hidden>{{ __('home_overview.legacy_note') }}</p></details>
    </div>
</section>
</div>
@endsection
@push('custom-js')
<script type="application/json" id="home-overview-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/home-overview.js') }}?v={{ filemtime(public_path('dashboard/js/home-overview.js')) }}"></script>
@endpush
