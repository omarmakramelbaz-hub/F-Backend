@php
    $preview = $preview ?? false;
    $kitchenCopy = $kitchenCopy ?? false;
    $channel = $receipt['channel'] ?? 'takeaway';
    $context = $receipt['context'] ?? [];
    $unpaid = !empty($receipt['unpaid']);
    $cancelled = ($receipt['status'] ?? '') === 'cancelled';
    $showUnitPrices = $receipt['show_unit_prices'] ?? true;
    $channelTitle = $receipt['channel_label'] ?? ($channel === 'phone' ? __('receipt.phone_short') : ($channel === 'app' ? __('receipt.app') : __('pos_service.'.$channel)));
    $method = $receipt['payment_method'] ?? 'unpaid';
    $paymentLabel = $receipt['payment_label'] ?? (['cash'=>__('takeaway.cash'), 'card'=>__('takeaway.card'), 'mobile_wallet'=>__('takeaway.wallet'), 'other'=>__('takeaway.other'), 'mixed'=>__('pos_service.mixed'), 'unpaid'=>__('pos_service.unpaid')][$method] ?? $method);
    $money = static function ($value) {
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D', (string) $value, $parts)) return '—';
        return $parts[1].preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[2]).'.'.str_pad($parts[3] ?? '', 2, '0');
    };
    $compactMoney = static fn ($value) => rtrim(rtrim($money($value), '0'), '.');
    $stamp = \Carbon\Carbon::parse($receipt['created_at'])->timezone('Africa/Cairo');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" @if($preview) data-dashboard-invoice-details="1" @else data-dashboard-receipt="{{ $receipt['print_marker'] ?? 'takeaway' }}" @endif>
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $kitchenCopy ? __('pos_service.kitchen_ticket') : __('takeaway.current_invoice') }} {{ $receipt['number'] }}</title>
    @include('admin.printing.receipt_styles')
</head>
<body>
@if($preview)<h2 class="preview-title">{{ __('pos_service.details') }}</h2>@endif
<main class="receipt @if($kitchenCopy) ft-kitchen @endif" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <header class="ft-brand">
        <img class="ft-logo" src="{{ $receipt['logo_url'] ?? asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="فسخانستا" width="1254" height="1254">
        <p class="ft-branch">{{ $receipt['branch']['name'] }}</p>
        @if(!empty($receipt['branch']['address']))<p class="ft-contact-line">{{ $receipt['branch']['address'] }}</p>@endif
        @if(!empty($receipt['branch']['phone']))<p class="ft-contact-line"><bdi>{{ $receipt['branch']['phone'] }}</bdi></p>@endif
    </header>
    <div class="ft-identity">
        <div class="ft-type">@if($kitchenCopy){{ __('receipt.kitchen_short') }} · @endif{{ $channelTitle }}</div>
        <bdi class="ft-number @if(strlen((string) $receipt['number']) > 24) ft-number-long @endif">{{ $receipt['number'] }}</bdi>
    </div>
    <div class="ft-date"><bdi>{{ $stamp->format('d/m/Y H:i') }}</bdi>@if($kitchenCopy)<span>{{ __('pos_service.revision') }} <bdi>{{ $receipt['revision'] }}</bdi></span>@elseif(!empty($receipt['cashier']['name']))<span>{{ __('takeaway.cashier') }}: {{ $receipt['cashier']['name'] }}</span>@endif</div>
    @if($cancelled)<p class="ft-state">{{ __('pos_service.cancelled') }}</p>@endif
    @if($unpaid)<p class="ft-state">{{ __('pos_service.unpaid_bill') }}</p>@endif
    @if(!empty($receipt['order_state']))<p class="ft-state">{{ $receipt['order_state'] }}</p>@endif
    @if(!$kitchenCopy && !empty($receipt['payment_failed']))<p class="ft-state">{{ __('order_board.payment_failed') }}</p>@endif
    <dl class="ft-meta">@include('admin.printing.receipt_context')</dl>
    @if(!empty($receipt['items']))@include('admin.printing.receipt_items')@endif
    @if(!$kitchenCopy)
        <div class="ft-summary">
            @if(array_key_exists('summary_lines', $receipt))
                @foreach($receipt['summary_lines'] as $line)<div class="ft-sum"><span>{{ $line['label'] }}</span><span class="ft-value"><bdi>{{ $money($line['amount']) }}</bdi></span></div>@endforeach
            @else
                <div class="ft-sum"><span>{{ __('takeaway.subtotal') }}</span><span class="ft-value"><bdi>{{ $money($receipt['subtotal']) }}</bdi></span></div>
                @if((float) $receipt['discount'] > 0)<div class="ft-sum"><span>{{ __('takeaway.discount') }}</span><span class="ft-value"><bdi>{{ $money($receipt['discount']) }}</bdi></span></div>@endif
                @if((float) $receipt['tax'] != 0 || (float) ($receipt['tax_rate'] ?? 0) != 0)<div class="ft-sum"><span>{{ __('takeaway.tax') }}@if(isset($receipt['tax_rate'])) (<bdi>{{ $receipt['tax_rate'] }}%</bdi>)@endif</span><span class="ft-value"><bdi>{{ $money($receipt['tax']) }}</bdi></span></div>@endif
                @if((float) $receipt['service'] > 0)<div class="ft-sum"><span>{{ __('takeaway.service') }}@if(isset($receipt['service_rate'])) (<bdi>{{ $receipt['service_rate'] }}%</bdi>)@endif</span><span class="ft-value"><bdi>{{ $money($receipt['service']) }}</bdi></span></div>@endif
                @if((float) ($receipt['delivery'] ?? 0) > 0)<div class="ft-sum"><span>{{ __('pos_service.delivery') }}</span><span class="ft-value"><bdi>{{ $money($receipt['delivery']) }}</bdi></span></div>@endif
            @endif
            <div class="ft-sum ft-grand"><span>{{ __('receipt.total_short') }}</span><span class="ft-value">@if(isset($receipt['total']))<bdi>{{ $money($receipt['total']) }}</bdi> <small class="ft-currency">{{ __('receipt.currency_short') }}</small>@else{{ __('receipt.amount_pending') }}@endif</span></div>
            <div class="ft-pay">{{ __('takeaway.payment_method') }}: <strong>{{ $paymentLabel }}</strong></div>
            @if(!empty($receipt['payment_paid']))<p class="ft-state">{{ __('order_board.payment_paid') }}</p>@endif
            @if($method === 'mixed')
                @foreach($receipt['payment_breakdown'] ?? [] as $part)
                    <div class="ft-sum"><span>{{ ['cash'=>__('takeaway.cash'), 'card'=>__('takeaway.card'), 'mobile_wallet'=>__('takeaway.wallet')][$part['method']] ?? $part['method'] }}</span><span class="ft-value"><bdi>{{ $money($part['amount']) }}</bdi></span></div>
                    @if(!empty($part['reference']))<p class="ft-note"><strong>{{ __('takeaway.payment_reference') }}:</strong> {{ $part['reference'] }}</p>@endif
                @endforeach
            @endif
            @if(($receipt['show_cash_tender'] ?? true) && in_array($method, ['cash', 'mixed'], true))
                <div class="ft-tender"><span>{{ __('receipt.paid_short') }} <bdi>{{ $money($receipt['cash_received']) }}</bdi></span><strong>{{ __('takeaway.change') }} <bdi>{{ $money($receipt['change']) }}</bdi></strong></div>
            @endif
            @if(!empty($receipt['payment_reference']))<p class="ft-note"><strong>{{ __('takeaway.payment_reference') }}:</strong> {{ $receipt['payment_reference'] }}</p>@endif
        </div>
        @if(!empty($receipt['discount_reason']))<p class="ft-note"><strong>{{ __('takeaway.discount_reason') }}:</strong> {{ $receipt['discount_reason'] }}</p>@endif
    @endif
    @if(!empty($receipt['notes']))<p class="ft-note"><strong>{{ $kitchenCopy ? __('receipt.preparation_notes') : __('takeaway.order_notes') }}:</strong> {{ $receipt['notes'] }}</p>@endif
    <footer class="ft-footer">@if($kitchenCopy){{ __('pos_service.kitchen_copy') }}@else<strong>{{ __('takeaway.receipt_thanks') }}</strong>@if($unpaid)<p>{{ __('pos_service.unpaid') }}</p>@endif @endif</footer>
</main>
@if(!$preview && ($receipt['can_reprint'] ?? true))<div class="controls"><button type="button" onclick="window.print()">{{ __('takeaway.print_invoice') }}</button></div>@endif
@if(!$preview && !request()->boolean('dashboard_print'))<script>window.addEventListener('load',function(){window.print();});</script>@endif
</body>
</html>
