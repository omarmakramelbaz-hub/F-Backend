@php
    $preview = $preview ?? false;
    $rtl = app()->getLocale() === 'ar';
    $channel = $receipt['channel'] ?? 'takeaway';
    $context = $receipt['context'] ?? [];
    $unpaid = !empty($receipt['unpaid']);
    $cancelled = ($receipt['status'] ?? '') === 'cancelled';
    $channelTitle = $channel === 'dine' ? __('dining.title') : ($channel === 'phone' ? __('phone_orders.title') : __('takeaway.title'));
    $money = static function ($value) {
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D', (string)$value, $parts)) return '—';
        return $parts[1].preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[2]).'.'.str_pad($parts[3] ?? '', 2, '0');
    };
    $paymentLabel = ['cash'=>__('takeaway.cash'), 'card'=>__('takeaway.card'), 'mobile_wallet'=>__('takeaway.wallet'), 'other'=>__('takeaway.other'), 'mixed'=>__('pos_service.mixed'), 'unpaid'=>__('pos_service.unpaid')][$receipt['payment_method']] ?? $receipt['payment_method'];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" @if($preview) data-dashboard-invoice-details="1" @else data-dashboard-receipt="{{ $receipt['print_marker'] ?? 'takeaway' }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('takeaway.current_invoice') }} {{ $receipt['number'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf1f6; color: #172137; font: 13px/1.55 Tahoma, Arial, sans-serif; }
        .receipt { width: calc(100% - 28px); max-width: 660px; margin: 28px auto; padding: 28px; background: #fff; border: 1px solid #dfe5ec; border-radius: 12px; box-shadow: 0 10px 35px #13233a12; }
        .preview-title { margin: 0 0 22px; color: #647186; font-size: 13px; font-weight: normal; }
        .heading { text-align: center; border-bottom: 1px dashed #aeb9c8; padding-bottom: 18px; }
        .logo { display: block; width: 112px; height: 112px; object-fit: contain; margin: 0 auto 8px; }
        .heading h2 { margin: 4px 0; font-size: 20px; line-height: 1.4; }
        .branch-contact { max-width: 440px; margin: 4px auto 0; font-size: 11px; color: #667387; overflow-wrap: anywhere; }
        .receipt-channel { display: inline-block; margin-top: 12px; padding: 3px 15px; border: 1px solid #c9d2de; border-radius: 4px; color: #36475c; font-size: 11px; font-weight: bold; }
        .receipt-identity { margin-top: 12px; }
        .receipt-identity h1 { margin: 0 0 3px; color: #647186; font-size: 11px; font-weight: normal; }
        .receipt-number { font-size: 19px; font-weight: bold; letter-spacing: .25px; overflow-wrap: anywhere; }
        .receipt-state { margin: 10px 0 0; padding: 6px; border: 1px solid #172137; font-size: 12px; font-weight: bold; }
        .information { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 18px; margin: 18px 0; }
        .information div { display: flex; gap: 8px; align-items: baseline; min-width: 0; overflow-wrap: anywhere; }
        .information dt { flex: 0 0 auto; color: #6b778a; font-size: 11px; }
        .information dd { margin: 0; color: #25364b; font-size: 12px; font-weight: bold; }
        .full-width { grid-column: 1 / -1; }
        bdi { unicode-bidi: isolate; direction: ltr; font-variant-numeric: tabular-nums; }
        .number { display: inline-block; white-space: nowrap; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; margin: 18px 0 0; }
        .item-col { width: 46%; }.quantity-col { width: 15%; }.price-col { width: 18%; }.amount-col { width: 21%; }
        th, td { padding: 11px 5px; border-bottom: 1px solid #e2e7ee; vertical-align: top; text-align: start; font-size: 12px; }
        th { border-top: 1px solid #b8c4d3; border-bottom-color: #b8c4d3; background: #f5f7fa; color: #4c5c71; font-size: 11px; white-space: nowrap; }
        .item-name { overflow-wrap: anywhere; font-weight: bold; }
        .item-option { display: block; margin-top: 3px; color: #68758a; font-size: 10px; font-weight: normal; }
        .item-quantity { text-align: center; }
        .item-unit { display: block; margin-top: 2px; color: #68758a; font-size: 10px; }
        .item-price, .item-total { text-align: end; white-space: nowrap; }
        td.item-total { font-weight: bold; }
        .summary { margin-top: 14px; padding-top: 6px; }
        .summary-row { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; padding: 4px 0; font-size: 12px; }
        .summary-value { display: inline-flex; flex: 0 0 auto; gap: 4px; align-items: baseline; white-space: nowrap; font-weight: bold; }
        .currency { color: #68758a; font-size: 10px; font-weight: normal; }
        .grand-total { margin: 8px 0 4px; padding: 10px 0 7px; border-top: 2px solid #172137; border-bottom: 1px solid #c9d2df; font-size: 19px; font-weight: bold; }
        .grand-total .currency { font-size: 11px; }
        .payment-total, .change-total { font-size: 13px; }
        .change-total { font-weight: bold; }
        .notes { margin-top: 12px; padding: 10px 12px; border: 1px dashed #b8c4d3; border-radius: 4px; white-space: pre-line; overflow-wrap: anywhere; font-size: 11px; }
        .notes strong { display: block; margin-bottom: 3px; }
        .footer { margin: 22px 0 0; padding-top: 15px; border-top: 1px dashed #aeb9c8; text-align: center; color: #68758a; font-size: 10px; }
        .footer strong { display: block; margin-bottom: 3px; color: #27384c; font-size: 14px; }
        .footer p { margin: 3px 0; }
        .controls { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .controls button { border: 0; border-radius: 6px; padding: 10px 20px; background: #172137; color: #fff; font: inherit; cursor: pointer; }
        @media (max-width: 480px) {
            .receipt { width: calc(100% - 16px); margin: 10px auto; padding: 16px 12px; }
            .information { grid-template-columns: 1fr; gap: 6px; }
            th, td { padding-inline: 3px; font-size: 10.5px; }
            th { font-size: 10px; }.item-option, .item-unit { font-size: 9px; }
            .grand-total { font-size: 17px; }
        }
        @page { size: auto; margin: 0; }
        @media print {
            html, body { width: 100%; margin: 0; padding: 0; background: #fff; color: #000; font-size: 11px; line-height: 1.4; }
            .receipt { width: 100%; max-width: 80mm; margin: 0 auto; padding: 3mm 2mm; border: 0; border-radius: 0; box-shadow: none; }
            .preview-title { display: none; }
            .heading { padding-bottom: 9px; border-color: #000; break-inside: avoid; page-break-inside: avoid; }
            .logo { width: 28mm; height: 28mm; margin: 0 auto 5px; filter: grayscale(1); }
            .heading h2 { margin: 2px 0; font-size: 14px; line-height: 1.35; overflow-wrap: anywhere; }
            .branch-contact { margin: 3px 0 0; color: #000; font-size: 10px; }
            .receipt-channel { margin-top: 7px; padding: 2px 10px; color: #000; border-color: #000; font-size: 10px; }
            .receipt-identity { margin-top: 7px; }
            .receipt-identity h1 { margin: 0 0 2px; color: #000; font-size: 9px; }
            .receipt-number { font-size: 13px; line-height: 1.4; letter-spacing: .15px; }
            .receipt-state { margin-top: 6px; padding: 4px; border: 1px solid #000; font-size: 11px; }
            .information { display: block; margin: 9px 0; }
            .information div { gap: 6px; margin: 4px 0; break-inside: avoid; page-break-inside: avoid; }
            .information dt { color: #000; font-size: 10px; }
            .information dd { color: #000; font-size: 10.5px; }
            table { margin: 10px 0 0; }
            th, td { padding: 6px 2px; border-color: #888; font-size: 10.5px; line-height: 1.4; }
            th { padding-block: 5px; color: #000; background: #fff; border-color: #000; font-size: 10px; }
            .item-option, .item-unit { color: #000; font-size: 9px; line-height: 1.4; }
            .item-price, .item-total { font-size: 10px; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; page-break-inside: avoid; }
            .summary { margin-top: 8px; padding-top: 0; break-inside: avoid; page-break-inside: avoid; }
            .summary-row { gap: 8px; padding: 3px 0; font-size: 11px; }
            .summary-value { gap: 3px; }
            .currency { color: #000; font-size: 9px; }
            .grand-total { margin: 6px 0 3px; padding: 7px 0 5px; border-color: #000; font-size: 16px; }
            .grand-total .currency { font-size: 9px; }
            .payment-total, .change-total { font-size: 12px; }
            .notes { margin-top: 8px; padding: 6px; border-color: #555; border-radius: 0; font-size: 10px; break-inside: avoid; page-break-inside: avoid; }
            .notes strong { margin-bottom: 2px; }
            .footer { margin-top: 13px; padding-top: 9px; color: #000; border-color: #000; font-size: 9px; break-inside: avoid; page-break-inside: avoid; }
            .footer strong { color: #000; font-size: 12px; }
            .footer p { margin: 2px 0; }
            .controls { display: none !important; }
        }
    </style>
</head>
<body>
<main class="receipt">
    @if($preview)<h2 class="preview-title">{{ __('pos_service.details') }}</h2>@endif
    <header class="heading">
        <img class="logo" src="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="فسخانستا" width="1254" height="1254">
        <h2>{{ $receipt['branch']['name'] }}</h2>
        @if(!empty($receipt['branch']['address']))<p class="branch-contact">{{ $receipt['branch']['address'] }}</p>@endif
        @if(!empty($receipt['branch']['phone']))<p class="branch-contact"><bdi class="number">{{ $receipt['branch']['phone'] }}</bdi></p>@endif
        <div class="receipt-channel">{{ __('pos_service.'.$channel) }}</div>
        <div class="receipt-identity">
            <h1>{{ $unpaid ? __('pos_service.unpaid_bill') : __('takeaway.invoice_number') }}</h1>
            <bdi class="receipt-number">{{ $receipt['number'] }}</bdi>
        </div>
        @if($cancelled)<p class="receipt-state">{{ __('pos_service.cancelled') }}</p>@endif
    </header>
    <dl class="information">
        <div><dt>{{ __('takeaway.date') }}</dt><dd><bdi>{{ \Carbon\Carbon::parse($receipt['created_at'])->timezone('Africa/Cairo')->format('d/m/Y H:i') }}</bdi></dd></div>
        <div><dt>{{ __('takeaway.cashier') }}</dt><dd>{{ $receipt['cashier']['name'] }}</dd></div>
        @if($channel !== 'takeaway')
            @include('admin.pos_service.context')
        @else
            @if(!empty($context['customer_name']))<div><dt>{{ __('pos_service.customer') }}</dt><dd>{{ $context['customer_name'] }}</dd></div>@endif
            @if(!empty($context['customer_phone']))<div><dt>{{ __('pos_service.phone_number') }}</dt><dd><bdi>{{ $context['customer_phone'] }}</bdi></dd></div>@endif
        @endif
        <div><dt>{{ __('takeaway.payment_method') }}</dt><dd>{{ $paymentLabel }}</dd></div>
        @if(!empty($receipt['payment_reference']))<div class="full-width"><dt>{{ __('takeaway.payment_reference') }}</dt><dd>{{ $receipt['payment_reference'] }}</dd></div>@endif
    </dl>
    <table>
        <colgroup><col class="item-col"><col class="quantity-col"><col class="price-col"><col class="amount-col"></colgroup>
        <thead><tr><th scope="col">{{ __('takeaway.item') }}</th><th scope="col" class="item-quantity">{{ __('takeaway.quantity_short') }}</th><th scope="col" class="item-price">{{ __('takeaway.price') }}</th><th scope="col" class="item-total">{{ __('takeaway.line_total') }}</th></tr></thead>
        <tbody>
        @foreach($receipt['items'] as $item)
            <tr>
                <td class="item-name">{{ $item['name'] }}@if(!empty($item['option_label']))<span class="item-option">{{ $item['option_label'] }}</span>@endif</td>
                <td class="item-quantity"><bdi class="number">{{ $item['quantity_mode'] === 'piece' ? explode('.', (string)$item['quantity'])[0] : $item['quantity'] }}</bdi><span class="item-unit">{{ __('takeaway.'.($item['quantity_mode'] === 'weight' ? 'kg' : 'piece')) }}</span></td>
                <td class="item-price"><bdi class="number">{{ $money($item['unit_price']) }}</bdi></td>
                <td class="item-total"><bdi class="number">{{ $money($item['total']) }}</bdi></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="summary">
        <div class="summary-row"><span>{{ __('takeaway.subtotal') }}</span><span class="summary-value"><bdi>{{ $money($receipt['subtotal']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>
        @if((float)$receipt['discount'] > 0)<div class="summary-row"><span>{{ __('takeaway.discount') }}</span><span class="summary-value"><bdi>{{ $money($receipt['discount']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>@endif
        <div class="summary-row"><span>{{ __('takeaway.tax') }} (<bdi>{{ $receipt['tax_rate'] }}%</bdi>)</span><span class="summary-value"><bdi>{{ $money($receipt['tax']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>
        @if((float)$receipt['service'] > 0)<div class="summary-row"><span>{{ __('takeaway.service') }}@if(isset($receipt['service_rate'])) (<bdi>{{ $receipt['service_rate'] }}%</bdi>)@endif</span><span class="summary-value"><bdi>{{ $money($receipt['service']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>@endif
        @if((float)($receipt['delivery'] ?? '0') > 0)<div class="summary-row"><span>{{ __('pos_service.delivery') }}</span><span class="summary-value"><bdi>{{ $money($receipt['delivery']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>@endif
        <div class="summary-row grand-total"><span>{{ __('takeaway.total') }}</span><span class="summary-value"><bdi>{{ $money($receipt['total']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>
        @if($receipt['payment_method'] === 'mixed')
            @foreach($receipt['payment_breakdown'] ?? [] as $part)
                <div class="summary-row"><span>{{ ['cash'=>__('takeaway.cash'), 'card'=>__('takeaway.card'), 'mobile_wallet'=>__('takeaway.wallet')][$part['method']] ?? $part['method'] }}</span><span class="summary-value"><bdi>{{ $money($part['amount']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>
                @if(!empty($part['reference']))<div class="notes">{{ __('takeaway.payment_reference') }}: {{ $part['reference'] }}</div>@endif
            @endforeach
        @endif
        @if(in_array($receipt['payment_method'], ['cash', 'mixed'], true))
            <div class="summary-row payment-total"><span>{{ __('takeaway.cash_paid') }}</span><span class="summary-value"><bdi>{{ $money($receipt['cash_received']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>
            <div class="summary-row change-total"><span>{{ __('takeaway.change') }}</span><span class="summary-value"><bdi>{{ $money($receipt['change']) }}</bdi><small class="currency">{{ __('takeaway.currency') }}</small></span></div>
        @endif
    </div>
    @if(!empty($receipt['discount_reason']))<div class="notes"><strong>{{ __('takeaway.discount_reason') }}</strong>{{ $receipt['discount_reason'] }}</div>@endif
    @if(!empty($receipt['notes']))<div class="notes"><strong>{{ __('takeaway.order_notes') }}</strong>{{ $receipt['notes'] }}</div>@endif
    <footer class="footer">
        <strong>{{ __('takeaway.receipt_thanks') }}</strong>
        <p>{{ __('takeaway.receipt_welcome') }}</p>
        <p>{{ $channelTitle }}@if($unpaid) · {{ __('pos_service.unpaid') }}@endif</p>
    </footer>
    @if(!$preview)<div class="controls"><button type="button" onclick="window.print()">{{ __('takeaway.print_invoice') }}</button></div>@endif
</main>
@if(!$preview && !request()->boolean('dashboard_print'))<script>window.addEventListener('load',function(){window.print();});</script>@endif
</body>
</html>
