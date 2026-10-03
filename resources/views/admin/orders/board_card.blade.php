@php
    $app = ($card['app'] ?? '') === 'go' ? 'go' : 'fasakhansta';
    $phone = preg_replace('/[^0-9+]/', '', (string)($card['phone'] ?? ''));
    $total = isset($card['total']) ? rtrim(rtrim(number_format((float)$card['total'], 2, '.', ','), '0'), '.') : null;
    $actions = $card['actions'] ?? [];
@endphp
<article class="ob-order" data-order-key="{{ $card['key'] }}" data-order-status="{{ $card['status'] }}" aria-label="#{{ ltrim((string)$card['number'], '#') }}">
    <div class="ob-order-top">
        <strong class="ob-order-number"><bdi>#{{ ltrim((string)$card['number'], '#') }}</bdi></strong>
        <span class="ob-elapsed">{{ $card['elapsed'] ?? '' }}</span>
    </div>
    <div class="ob-order-tags">
        <span class="ob-app-badge ob-app-{{ $app }}">{{ $card['app_label'] ?? $app }}</span>
        <span class="ob-order-status">{{ $card['status_label'] ?? '' }}</span>
    </div>
    @if(!empty($card['store']))
        <div class="ob-store"><i class="fas fa-store" aria-hidden="true"></i><span>{{ $card['store'] }}</span></div>
    @endif
    <dl class="ob-customer">
        @if(!empty($card['customer']))
            <div class="ob-customer-name"><dt><i class="far fa-user" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.customer') }}</span></dt><dd>{{ $card['customer'] }}</dd></div>
        @endif
        @if(!empty($card['phone']))
            <div><dt><i class="fas fa-phone-alt" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.phone') }}</span></dt><dd><a href="tel:{{ $phone }}"><bdi>{{ $card['phone'] }}</bdi></a></dd></div>
        @endif
        @if(!empty($card['address']))
            <div><dt><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.address') }}</span></dt><dd>{{ $card['address'] }}</dd></div>
        @endif
        @if(!empty($card['payment_label']))
            <div><dt><i class="far fa-credit-card" aria-hidden="true"></i><span class="ob-sr-only">{{ __('order_board.payment') }}</span></dt><dd>{{ __('order_board.payment') }}: {{ $card['payment_label'] }}</dd></div>
        @endif
    </dl>
    @if(!empty($card['items']))
        <div class="ob-items-heading">{{ __('order_board.items') }}</div>
        <ul class="ob-items">
            @foreach($card['items'] as $item)
                <li><span>{{ $item['name'] }}@if(!empty($item['option_label']))<small class="ob-item-option">{{ $item['option_label'] }}</small>@endif</span><bdi class="ob-quantity">× {{ $item['quantity'] }}</bdi><bdi class="ob-line-amount">@if(isset($item['line_total'])){{ rtrim(rtrim(number_format((float)$item['line_total'], 2, '.', ','), '0'), '.') }}@endif</bdi></li>
            @endforeach
        </ul>
    @endif
    @if(!empty($card['courier']['name']))
        <div class="ob-courier"><i class="fas fa-motorcycle" aria-hidden="true"></i><span>{{ __('order_board.courier_name') }}: <strong>{{ $card['courier']['name'] }}</strong>@if(!empty($card['courier']['phone']))<bdi>{{ $card['courier']['phone'] }}</bdi>@endif</span></div>
    @endif
    @if(!empty($card['notes']))
        <div class="ob-notes"><strong>{{ __('order_board.notes') }}:</strong> {{ $card['notes'] }}</div>
    @endif
    <div class="ob-total"><span>{{ __('order_board.total') }}</span>@if($total !== null)<strong><bdi>{{ $total }}</bdi> <small>{{ __('order_board.currency') }}</small></strong>@else<span class="ob-price-pending">{{ __('order_board.price_pending') }}</span>@endif</div>
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
        <p class="ob-action-note">{{ $card['action_note'] }}</p>
    @endif
</article>
