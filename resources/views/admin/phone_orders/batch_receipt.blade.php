<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}" data-dashboard-receipt="phone-batch">
<head><meta charset="utf-8"><title>{{ __('phone_orders.batch_receipt') }} #{{ $batch['id'] }}</title>
<style>@page{size:80mm auto;margin:3mm}*{box-sizing:border-box}body{font:12px Tahoma,Arial;color:#000;width:72mm;margin:0 auto;overflow-wrap:anywhere}header,footer{text-align:center}header img{width:28mm;height:28mm;object-fit:contain}h1{font-size:17px}p{margin:7px 0}table{width:100%;border-collapse:collapse;margin:14px 0}th,td{padding:9px 3px;border-bottom:1px dashed #333;text-align:start}tr{break-inside:avoid}tfoot{font-size:16px;font-weight:bold}footer{margin-top:15px;font-size:11px}</style></head>
<body><header><img src="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="فسخانستا"><h1>{{ __('phone_orders.batch_receipt') }}</h1><p>{{ $batch['branch']['name'] }}</p><bdi>#{{ $batch['id'] }}</bdi><p><bdi>{{ \Carbon\Carbon::parse($batch['created_at'])->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi></p></header>
<p>{{ __('phone_orders.courier_company') }}: {{ $batch['company']['name'] }}</p>
@if($batch['courier_name'])<p>{{ __('phone_orders.courier_name') }}: {{ $batch['courier_name'] }}</p>@endif
<p>{{ __('phone_orders.cashier') }}: {{ $batch['cashier']['name'] }}</p>
<table><thead><tr><th>{{ __('phone_orders.saved_number') }}</th><th>{{ __('phone_orders.total') }}</th></tr></thead><tbody>@foreach($batch['items'] as $line)<tr><td><bdi>#{{ $line['ticket_id'] }}</bdi></td><td><bdi>{{ $line['total'] }}</bdi></td></tr>@endforeach</tbody><tfoot><tr><td>{{ __('phone_orders.total') }}</td><td><bdi>{{ $batch['total'] }}</bdi> {{ __('phone_orders.currency') }}</td></tr></tfoot></table>
<p>{{ __('phone_orders.payment_method') }}: {{ __('phone_orders.'.$batch['payment_method']) }}</p>
@if($batch['payment_method']==='cash')<p>{{ __('phone_orders.cash_received') }}: <bdi>{{ $batch['cash_received'] }}</bdi></p><p>{{ __('phone_orders.change') }}: <bdi>{{ $batch['change'] }}</bdi></p>@endif
@if($batch['payment_reference'])<p>{{ __('phone_orders.payment_reference') }}: <bdi>{{ $batch['payment_reference'] }}</bdi></p>@endif
<footer>{{ __('phone_orders.batch_paid_note') }}</footer></body></html>
