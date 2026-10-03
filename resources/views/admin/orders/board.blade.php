@extends('admin.index')

@push('custom-css')
    <link rel="stylesheet" href="{{ asset('dashboard/css/order-board.css') }}?v={{ filemtime(public_path('dashboard/css/order-board.css')) }}">
    <link rel="stylesheet" href="{{ asset('dashboard/css/order-board-menu.css') }}?v={{ filemtime(public_path('dashboard/css/order-board-menu.css')) }}">
@endpush

@section('content')
@php
    $stages = ['new' => 'fa-shopping-cart', 'preparing' => 'fa-utensils', 'courier' => 'fa-motorcycle', 'completed' => 'fa-check-circle'];
    $filters = $board['filters'] ?? [];
    $selectedBranch = $filters['branch'] ?? '';
    if (!$selectedBranch && !($board['isAdmin'] ?? false) && count($board['branches'] ?? []) === 1) $selectedBranch = $board['branches'][0]['value'];
@endphp
<div class="content-wrapper order-board-wrapper">
    <div class="ob-workspace">
    <main id="order-board" class="ob-board" data-feed-url="{{ route('getOrders') }}" data-default-date="" aria-labelledby="order-board-title">
        <header class="ob-page-header">
            <div class="ob-heading">
                <h1 id="order-board-title">{{ __('order_board.title') }}</h1>
                <p>{{ __('order_board.subtitle') }}</p>
            </div>
            <div class="ob-header-tools"><button type="button" class="ob-menu-toggle" data-menu-toggle hidden aria-controls="branch-menu" aria-expanded="false">{{ __('order_board.branch_menu') }}</button><div class="ob-live" id="ob-live" role="status"><span class="ob-live-dot" aria-hidden="true"></span><span data-live-label>{{ __('order_board.live') }}</span></div></div>
        </header>

        <div class="ob-overview" aria-label="{{ __('order_board.title') }}">
            @foreach($stages as $stage => $icon)
                <div class="ob-metric ob-stage-{{ $stage }}">
                    <i class="fas {{ $icon }} ob-metric-icon" aria-hidden="true"></i>
                    <div><strong data-board-count="{{ $stage }}">{{ $board['counts'][$stage] ?? 0 }}</strong><span>{{ __('order_board.'.$stage) }}</span></div>
                </div>
            @endforeach
        </div>

        <form id="ob-filters" class="ob-filters" method="get" action="{{ url('admin/applies-orders') }}">
            @foreach(array_keys($stages) as $stage)
                <input type="hidden" name="page_{{ $stage }}" value="{{ $board['pages'][$stage]['page'] ?? 1 }}">
            @endforeach
            <label class="ob-field ob-search">
                <span>{{ __('main.search') }}</span>
                <span class="ob-input-wrap"><i class="fas fa-search" aria-hidden="true"></i><input name="search" type="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('order_board.search') }}" autocomplete="off"></span>
            </label>
            <label class="ob-field">
                <span>{{ __('order_board.application') }}</span>
                <select name="app">
                    <option value="">{{ __('order_board.all_applications') }}</option>
                    <option value="fasakhansta" @if(($filters['app'] ?? '') === 'fasakhansta') selected @endif>فسخانستا · Fasakhansta</option>
                    <option value="go" @if(($filters['app'] ?? '') === 'go') selected @endif>جو · Go</option>
                </select>
            </label>
            @if(!empty($board['branches']))
                <label class="ob-field ob-branch-filter">
                    <span>{{ __('order_board.branch') }}</span>
                    <select name="branch">
                        <option value="">{{ __('order_board.all_branches') }}</option>
                        @foreach($board['branches'] ?? [] as $branch)
                            <option value="{{ $branch['value'] }}" @if((string)$selectedBranch === (string)$branch['value']) selected @endif>{{ $branch['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="ob-field ob-date-filter">
                <span>{{ __('order_board.date') }}</span>
                <input type="date" name="date" value="{{ $filters['date'] ?? '' }}">
            </label>
            <div class="ob-filter-actions">
                <button class="ob-filter-submit" type="submit"><i class="fas fa-filter" aria-hidden="true"></i>{{ __('order_board.filter') }}</button>
                <a class="ob-reset" href="{{ url('admin/applies-orders') }}" data-board-reset>{{ __('order_board.reset') }}</a>
            </div>
        </form>
        @unless($board['isAdmin'] ?? false)
            <p class="ob-scope"><i class="fas fa-store" aria-hidden="true"></i>{{ __('order_board.branch_scope') }}</p>
        @endunless

        <div id="ob-message" class="ob-message" role="status" aria-live="polite" hidden><span data-message-text></span><button type="button" data-board-retry hidden>{{ __('order_board.retry') }}</button></div>
        <div id="all_orders" class="ob-columns">
            @include('admin.orders.board_columns', ['board' => $board])
        </div>
    </main>
    @include('admin.orders.board_menu')
    </div>
</div>
@endsection

@push('custom-js')
    <script type="application/json" id="ob-translations">@json(trans('order_board'))</script>
    <script src="{{ asset('dashboard/js/order-board.js') }}?v={{ filemtime(public_path('dashboard/js/order-board.js')) }}"></script>
    <script src="{{ asset('dashboard/js/order-board-menu.js') }}?v={{ filemtime(public_path('dashboard/js/order-board-menu.js')) }}"></script>
@endpush
