<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}" data-dashboard-receipt="phone-batch">
<head>
    <meta charset="utf-8">
    <title>{{ __('phone_orders.batch_receipt') }} #{{ $batch['id'] }}</title>
    <style>
        @page{size:80mm auto;margin:3mm}
        *{box-sizing:border-box}
        body{font:12px Tahoma,Arial,sans-serif;color:#000;width:100%;max-width:72mm;margin:0 auto;padding:0 1mm;overflow-wrap:anywhere}
        header,footer{text-align:center}header img{width:28mm;height:28mm;object-fit:contain}
        h1{font-size:17px}p{margin:7px 0}
        table{width:100%;table-layout:fixed;border-collapse:collapse;margin:12px 0}
        th,td{padding:7px 1px;border-bottom:1px dashed #333;text-align:center;vertical-align:middle}
        th{font-size:11px;line-height:1.5}td{font-size:11px}th:first-child{width:19%}
        th:nth-child(2){width:28%}th:nth-child(3){width:25%}th:last-child{width:28%}
        tr{break-inside:avoid}bdi{font-variant-numeric:tabular-nums}td bdi{white-space:nowrap}
        .batch-summary{border-top:1px solid #000;padding-top:6px}
        .batch-summary div{display:flex;justify-content:space-between;gap:6px;padding:5px 0;break-inside:avoid}
        .batch-summary dt{flex:1}.batch-summary dd{margin:0;white-space:nowrap}
        .batch-delivery{font-weight:bold}.batch-grand-total{border-top:1px dashed #000;font-size:14px;font-weight:bold}
        .batch-note{font-size:11px;line-height:1.6}footer{margin-top:15px;font-size:11px}
    </style>
</head>
<body>
<header>
    <img src="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="فسخانستا">
    <h1>{{ __('phone_orders.batch_receipt') }}</h1>
    <p>{{ $batch['branch']['name'] }}</p><bdi>#{{ $batch['id'] }}</bdi>
    <p><bdi>{{ \Carbon\Carbon::parse($batch['created_at'])->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi></p>
</header>
<p>{{ __('phone_orders.courier_company') }}: {{ $batch['company']['name'] }}</p>
@if($batch['courier_name'])<p>{{ __('phone_orders.courier_name') }}: {{ $batch['courier_name'] }}</p>@endif
<p>{{ __('phone_orders.cashier') }}: {{ $batch['cashier']['name'] }}</p>
<table>
    <thead><tr>
        <th>{{ __('phone_orders.saved_number') }}</th>
        <th>{{ __('phone_batch.orders_column') }}</th>
        <th>{{ __('phone_batch.delivery_column') }}</th>
        <th>{{ __('phone_batch.total_column') }}</th>
    </tr></thead>
    <tbody>
    @foreach($batch['items'] as $line)
        <tr>
            <td><bdi>#{{ $line['ticket_id'] }}</bdi></td>
            <td><bdi>{{ $line['total_without_delivery'] ?? '—' }}</bdi></td>
            <td><bdi>{{ $line['delivery'] ?? '—' }}</bdi></td>
            <td><bdi>{{ $line['total'] }}</bdi></td>
        </tr>
    @endforeach
    </tbody>
</table>
<dl class="batch-summary">
    <div><dt>{{ __('phone_batch.orders_total') }}</dt><dd><bdi>{{ $batch['orders_total'] ?? '—' }}</bdi> {{ __('phone_orders.currency') }}</dd></div>
    <div class="batch-delivery"><dt>{{ __('phone_batch.delivery_total') }}</dt><dd><bdi>{{ $batch['delivery_total'] ?? '—' }}</bdi> {{ __('phone_orders.currency') }}</dd></div>
    <div class="batch-grand-total"><dt>{{ __('phone_batch.grand_total') }}</dt><dd><bdi>{{ $batch['total'] }}</bdi> {{ __('phone_orders.currency') }}</dd></div>
</dl>
@if(empty($batch['delivery_breakdown_complete']))<p class="batch-note">{{ __('phone_batch.breakdown_unavailable') }}</p>@endif
<p>{{ __('phone_orders.payment_method') }}: {{ __('phone_orders.'.$batch['payment_method']) }}</p>
@if($batch['payment_method']==='cash')<p>{{ __('phone_orders.cash_received') }}: <bdi>{{ $batch['cash_received'] }}</bdi></p><p>{{ __('phone_orders.change') }}: <bdi>{{ $batch['change'] }}</bdi></p>@endif
@if($batch['payment_reference'])<p>{{ __('phone_orders.payment_reference') }}: <bdi>{{ $batch['payment_reference'] }}</bdi></p>@endif
<footer>{{ __('phone_orders.batch_paid_note') }}</footer>
</body>
</html>
