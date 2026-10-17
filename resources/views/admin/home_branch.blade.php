@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/home-overview.css') }}?v={{ filemtime(public_path('dashboard/css/home-overview.css')) }}">
@endpush
@section('content')
<div class="content-wrapper ho-wrapper">
<div id="branch-home" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
    <header class="ho-heading">
        <h1>{{ __('home_overview.title') }}</h1>
        <div class="ho-heading-tools">
            @if(count($boot['initial']['branches'])>1)
            <select data-bh-branch aria-label="{{ __('home_overview.branch') }}"><option value="">{{ __('home_overview.all_branches') }}</option>@foreach($boot['initial']['branches'] as $branch)<option value="{{ $branch['value'] }}" @if($branch['value']===$boot['initial']['filters']['branch']) selected @endif>{{ $branch['name'] }}</option>@endforeach</select>
            @else<span>{{ $boot['initial']['branches'][0]['name']??'' }}</span>@endif
            <span data-bh-date>{{ $boot['initial']['today_expenses']['date'] }}</span>
            <button type="button" class="ho-button" data-bh-refresh>{{ __('home_overview.refresh') }}</button>
        </div>
    </header>
    <p class="ho-notice" data-bh-notice role="status" hidden></p>
    <div class="bh-panels">
        <section class="ho-panel" data-bh-panel="stock">
            <header><h2><i class="fas fa-boxes" aria-hidden="true"></i> {{ __('home_overview.branch_stock_title') }}</h2><a data-bh-stock-link href="{{ route('branch-stock.index') }}">{{ __('home_overview.view_all') }}</a></header>
            <div class="ho-stock-search"><input type="search" data-bh-search placeholder="{{ __('home_overview.search_stock') }}" aria-label="{{ __('home_overview.search_stock') }}"></div>
            <div class="ho-scroll"><table><thead><tr><th>{{ __('home_overview.ingredient') }}</th><th>{{ __('home_overview.balance') }}</th></tr></thead><tbody data-bh-stock></tbody></table></div>
        </section>
        <section class="ho-panel" data-bh-panel="expenses">
            <header><h2><i class="fas fa-receipt" aria-hidden="true"></i> {{ __('home_overview.today_expenses_title') }}</h2><a data-bh-expenses-link href="{{ route('branch-expenses.index') }}">{{ __('home_overview.view_all') }}</a></header>
            <div class="bh-expense-total"><strong data-bh-total></strong><small>{{ __('home_overview.today_expenses_note') }}</small></div>
            <div class="ho-scroll"><table><thead><tr><th>{{ __('home_overview.expense_category') }}</th><th>{{ __('home_overview.expense_amount') }}</th></tr></thead><tbody data-bh-expenses></tbody></table></div>
        </section>
    </div>
</div>
</div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-home-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/branch-home.js') }}?v={{ filemtime(public_path('dashboard/js/branch-home.js')) }}"></script>
@endpush
