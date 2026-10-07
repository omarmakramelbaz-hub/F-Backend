@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-orders.css') }}?v={{ filemtime(public_path('dashboard/css/branch-orders.css')) }}">
@endpush
@section('content')
<div class="content-wrapper branch-orders-wrapper">
<main class="branch-orders" aria-labelledby="branch-orders-title">
    <header class="bo-heading"><div><h1 id="branch-orders-title">{{ __('branch_orders.title') }}</h1><p>{{ __('branch_orders.subtitle') }}</p></div><span>{{ count($branches) }} {{ __('branch_orders.branches') }}</span></header>
    <div class="bo-grid">
    @foreach($branches as $branch)
        <article class="bo-card" data-branch="{{ $branch['value'] }}">
            <header><span class="bo-icon"><i class="fas fa-store" aria-hidden="true"></i></span><div><h2>{{ $branch['name'] }}</h2><p>{{ $branch['address'] }}</p></div></header>
            <h3>{{ __('branch_orders.accounts') }}</h3>
            <ul class="bo-accounts">
            @forelse($branch['accounts'] as $account)
                <li><span>{{ $account['name'] }} <small>#<bdi>{{ $account['id'] }}</bdi></small></span><small class="{{ $account['receives_print'] ? 'bo-receiver' : '' }}">{{ __('branch_orders.'.($account['receives_print'] ? 'receiver' : 'multiple_branches')) }}</small></li>
            @empty
                <li>{{ __('branch_orders.no_account') }}</li>
            @endforelse
            </ul>
            @if(!$branch['has_receiver'])<p class="bo-note">{{ __('branch_orders.no_receiver') }}</p>@endif
            <nav class="bo-actions" aria-label="{{ $branch['name'] }}">
                <a href="{{ route('orders.applies', ['branch'=>$branch['value']]) }}"><i class="fas fa-mobile-alt" aria-hidden="true"></i>{{ __('order_board.app_orders') }}</a>
                <a href="{{ route('takeaway.index', ['branch'=>$branch['value']]) }}"><i class="fas fa-shopping-bag" aria-hidden="true"></i>{{ __('takeaway.title') }}</a>
                <a href="{{ route('dining.index', ['branch'=>$branch['value']]) }}"><i class="fas fa-chair" aria-hidden="true"></i>{{ __('dining.title') }}</a>
                <a href="{{ route('phone-orders.index', ['branch'=>$branch['value']]) }}"><i class="fas fa-phone-alt" aria-hidden="true"></i>{{ __('phone_orders.new_order') }}</a>
                <a href="{{ route('phone-orders.index', ['branch'=>$branch['value'], 'view'=>'orders']) }}"><i class="fas fa-motorcycle" aria-hidden="true"></i>{{ __('phone_orders.saved_orders') }}</a>
                <a href="{{ route('branch-expenses.index', ['branch'=>$branch['value']]) }}"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>{{ __('expenses.title') }}</a>
            </nav>
        </article>
    @endforeach
    </div>
</main>
</div>
@endsection
