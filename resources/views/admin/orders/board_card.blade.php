@php
    $app = ($card['app'] ?? '') === 'go' ? 'go' : 'fasakhansta';
    $phone = preg_replace('/[^0-9+]/', '', (string)($card['phone'] ?? ''));
    $total = isset($card['total']) ? rtrim(rtrim(number_format((float)$card['total'], 2, '.', ','), '0'), '.') : null;
    $actions = $card['actions'] ?? [];
    $items = $card['items'] ?? [];
    $remainingItems = max(0, count($items) - 2);
@endphp
<article class="ob-order" data-order-key="{{ $card['key'] }}" data-order-status="{{ $card['status'] }}" aria-label="#{{ ltrim((string)$card['number'], '#') }}">
    <div class="ob-order-top">
        <div class="ob-order-identity"><strong class="ob-order-number" title="#{{ ltrim((string)$card['number'], '#') }}"><bdi>#{{ ltrim((string)$card['number'], '#') }}</bdi></strong><span class="ob-app-badge ob-app-{{ $app }}">{{ $card['app_label'] ?? $app }}</span></div>
        <span class="ob-elapsed" title="{{ $card['elapsed'] ?? '' }}">{{ $card['elapsed'] ?? '' }}</span>
    </div>
    <div class="ob-order-tags">
        @if(!empty($card['store']))
            <span class="ob-store" title="{{ $card['store'] }}"><i class="fas fa-store" aria-hidden="true"></i><span>{{ $card['store'] }}</span></span>
        @endif
        <span class="ob-order-status">{{ $card['status_label'] ?? '' }}</span>
    </div>
    <dl class="ob-customer">
        @if(!empty($card['customer']))
            <div class="ob-customer-name"><dt><i class="far fa-user" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.customer') }}</span></dt><dd title="{{ $card['customer'] }}">{{ $card['customer'] }}</dd></div>
        @endif
        @if(!empty($card['phone']))
            <div class="ob-customer-phone"><dt><i class="fas fa-phone-alt" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.phone') }}</span></dt><dd><a href="tel:{{ $phone }}" title="{{ __('order_board.phone') }}: {{ $card['phone'] }}"><bdi>{{ $card['phone'] }}</bdi></a></dd></div>
        @endif
        @if(!empty($card['address']))
            <div class="ob-customer-address"><dt><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.address') }}</span></dt><dd title="{{ $card['address'] }}">{{ $card['address'] }}</dd></div>
        @endif
    </dl>
    @if(!empty($items))
        <ul class="ob-items" aria-label="{{ __('order_board.items') }}">
            @foreach(array_slice($items, 0, 2) as $item)
                <li @unless(isset($item['line_total'])) class="ob-item-no-price" @endunless><span class="ob-item-name" title="{{ $item['name'] }}{{ !empty($item['option_label']) ? ' · '.$item['option_label'] : '' }}">{{ $item['name'] }}@if(!empty($item['option_label']))<small class="ob-item-option"> · {{ $item['option_label'] }}</small>@endif</span><bdi class="ob-quantity">× {{ $item['quantity'] }}</bdi>@if(isset($item['line_total']))<bdi class="ob-line-amount">{{ rtrim(rtrim(number_format((float)$item['line_total'], 2, '.', ','), '0'), '.') }}</bdi>@endif</li>
            @endforeach
        </ul>
        @if($remainingItems > 0)
            @if(!empty($card['urls']['details']))
                <a class="ob-items-more" href="{{ $card['urls']['details'] }}" target="_blank" rel="noopener" title="{{ __('order_board.details') }}">+ {{ $remainingItems }} {{ app()->getLocale() === 'ar' ? 'أصناف أخرى' : 'more items' }}</a>
            @else
                <span class="ob-items-more">+ {{ $remainingItems }} {{ app()->getLocale() === 'ar' ? 'أصناف أخرى' : 'more items' }}</span>
            @endif
        @endif
    @endif
    @if(!empty($card['courier']['name']))
        <div class="ob-courier" title="{{ __('order_board.courier_name') }}: {{ $card['courier']['name'] }} {{ $card['courier']['phone'] ?? '' }}"><i class="fas fa-motorcycle" aria-hidden="true"></i><span>{{ __('order_board.courier_name') }}: <strong>{{ $card['courier']['name'] }}</strong>@if(!empty($card['courier']['phone'])) <bdi>{{ $card['courier']['phone'] }}</bdi>@endif</span></div>
    @endif
    @if(!empty($card['notes']))
        <div class="ob-notes" title="{{ __('order_board.notes') }}: {{ $card['notes'] }}"><strong>{{ __('order_board.notes') }}:</strong> {{ $card['notes'] }}</div>
    @endif
    <div class="ob-total">
        @if(!empty($card['payment_label']))<span class="ob-payment" title="{{ __('order_board.payment') }}: {{ $card['payment_label'] }}"><i class="far fa-credit-card" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.payment') }}: </span>{{ $card['payment_label'] }}</span>@endif
        <span class="ob-total-amount"><span>{{ __('order_board.total') }}</span>@if($total !== null)<strong><bdi>{{ $total }}</bdi> <small>{{ __('order_board.currency') }}</small></strong>@else<span class="ob-price-pending">{{ __('order_board.price_pending') }}</span>@endif</span>
    </div>
    <div class="ob-order-tools">
        @if(!empty($card['urls']['details']))
            <a href="{{ $card['urls']['details'] }}" target="_blank" rel="noopener"><i class="far fa-file-alt" aria-hidden="true"></i>{{ __('order_board.details') }}</a>
        @endif
        @if(!empty($card['urls']['print']))
            <a href="{{ $card['urls']['print'] }}" target="_blank" rel="noopener"><i class="fas fa-print" aria-hidden="true"></i>{{ __('order_board.print') }}</a>
        @endif
    </div>
    @if(!empty($actions) && !empty($card['urls']['action']))
        <form class="ob-order-actions" action="{{ $card['urls']['action'] }}" method="post" data-order-action>
            @csrf
            <input type="hidden" name="expected_status" value="{{ $card['status'] }}">
            <input type="hidden" name="expected_accepted_notify" value="{{ $card['accepted_notify'] ?? '' }}">
            <input type="hidden" name="expected_revision" value="{{ $card['revision'] ?? '' }}">
            @foreach($actions as $action)
                @if(in_array($action, ['accept', 'reject', 'dispatch', 'complete', 'cancel', 'prepare', 'ready'], true))
                    <button type="submit" name="action" value="{{ $action }}" class="ob-action ob-action-{{ $action }}"><i class="fas {{ in_array($action, ['reject', 'cancel'], true) ? 'fa-times' : (in_array($action, ['dispatch', 'prepare'], true) ? 'fa-motorcycle' : 'fa-check') }}" aria-hidden="true"></i><span>{{ $card['action_labels'][$action] ?? __('order_board.'.$action) }}</span></button>
                @endif
            @endforeach
        </form>
    @endif
    @if(!empty($card['action_note']))
        <p class="ob-action-note" title="{{ $card['action_note'] }}">{{ $card['action_note'] }}</p>
    @endif
</article>
