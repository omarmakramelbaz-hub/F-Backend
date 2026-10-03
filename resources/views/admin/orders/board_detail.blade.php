@php
    $money = static function ($value) {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ',') : $value;
    };
    $number = ltrim((string) $card['number'], '#');
@endphp
<!doctype html>
<html lang="ar" dir="rtl" data-dashboard-receipt="order-board">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طلب {{ $number }} · {{ $card['app_label'] }}</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#eef1f6;color:#172137;font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.6}
        .receipt{max-width:850px;margin:30px auto;background:#fff;border-radius:16px;padding:28px;box-shadow:0 10px 35px #13233a12}
        .heading{display:flex;gap:20px;justify-content:space-between;align-items:center;border-bottom:1px solid #e6e9ef;padding-bottom:16px}
        .heading-copy{min-width:0}.heading h1{font-size:23px;line-height:1.45;margin:0 0 3px}.order-number{white-space:nowrap;unicode-bidi:isolate}
        .logo{width:112px;height:112px;object-fit:contain;flex:0 0 112px}.store-name{font-weight:bold}.app-name,.muted{color:#69768b;font-size:12px}.created-at{display:inline-block;direction:ltr;unicode-bidi:isolate}
        .information{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 22px;margin:18px 0}
        .info-row{display:grid;grid-template-columns:72px minmax(0,1fr);gap:8px;min-width:0}.info-row dt{margin:0;color:#69768b;font-size:12px}.info-row dd{margin:0;min-width:0;overflow-wrap:anywhere}.info-address{grid-column:1/-1}
        .number{direction:ltr;unicode-bidi:isolate;display:inline-block;white-space:nowrap;font-variant-numeric:tabular-nums}
        .payment-failed{display:inline-block;color:#b51f34;background:#fff0f2;border:1px solid #f2c8d0;border-radius:4px;padding:1px 5px;margin-inline-start:5px;font-size:12px;font-weight:bold;white-space:nowrap}
        .courier-phone{display:block}.items{width:100%;border-collapse:collapse;table-layout:fixed;margin:16px 0}.item-name-col{width:62%}.quantity-col{width:12%}.amount-col{width:26%}
        .items th,.items td{padding:9px 7px;text-align:right;border-bottom:1px solid #e8eaf1;vertical-align:top}.items th{color:#66758b;font-size:12px}.items .item-quantity{text-align:center}.items .item-amount{text-align:left;white-space:nowrap;font-variant-numeric:tabular-nums}
        .item-name{overflow-wrap:anywhere}.item-option{display:block;color:#69768b;font-size:12px;margin-top:2px}
        .summary{border-top:1px solid #d4dae4;padding-top:10px;margin-top:12px}.summary-row{display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:3px 0}.summary-row>span:last-child{white-space:nowrap}
        .total{font-size:20px;border-top:2px solid #172137;margin-top:7px;padding-top:9px;font-weight:bold}.price-pending{font-size:13px;font-weight:normal;color:#69768b}
        .notes{white-space:pre-line;overflow-wrap:anywhere;background:#faf6ed;padding:10px;border-radius:6px;margin:12px 0;font-size:13px}.notes strong{display:block}
        .controls{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.controls form{margin:0}.controls a,.controls button{font:inherit;border:0;border-radius:7px;padding:9px 16px;background:#172137;color:#fff;text-decoration:none;cursor:pointer}.controls .reject{background:#c83445}.controls .accept{background:#168750}.action-note{font-size:12px;color:#69768b;margin:12px 0 0}
        @media(max-width:600px){.receipt{margin:10px;border-radius:10px;padding:18px}.information{grid-template-columns:1fr}.info-address{grid-column:auto}.heading h1{font-size:18px}.logo{width:96px;height:96px;flex-basis:96px}.heading{gap:10px}.items th,.items td{padding:7px 3px}.total{font-size:17px}}
        @page{size:auto;margin:0}
        @media print{
            html,body{margin:0;padding:0;background:#fff;color:#000;width:100%;font-size:10px;line-height:1.4}
            .receipt{width:100%;max-width:80mm;margin:0 auto;padding:3mm 2mm;box-shadow:none;border-radius:0}
            .heading{display:flex;flex-direction:column;gap:3px;text-align:center;padding:0 0 7px;border-color:#000;break-inside:avoid;page-break-inside:avoid}
            .logo{order:-1;width:40mm;max-width:80%;height:auto;aspect-ratio:1;flex:none}.heading-copy{width:100%}.heading h1{font-size:13px;line-height:1.4;margin:0 0 3px}.order-number{white-space:nowrap;display:inline-block}.store-name{font-size:11px}.app-name,.muted{color:#000;font-size:9px}.created-at{white-space:nowrap}
            .information{display:block;margin:8px 0}.info-row{grid-template-columns:43px minmax(0,1fr);gap:5px;margin:3px 0;break-inside:avoid;page-break-inside:avoid}.info-row dt{font-size:9px;color:#000}.info-row dd{font-size:10px;line-height:1.45}.courier-phone{display:inline-block;margin-inline-start:4px}
            .payment-failed{font-size:10px;color:#b51f34;background:#fff;border-color:#b51f34;print-color-adjust:exact;-webkit-print-color-adjust:exact}
            .items{margin:8px 0;width:100%;font-size:10px}.items th,.items td{padding:5px 2px;border-color:#aaa}.items th{font-size:9px;color:#000;font-weight:bold}.items .item-amount{font-size:9.5px}.item-option{color:#000;font-size:9px;margin-top:1px}.items thead{display:table-header-group}.items tr{break-inside:avoid;page-break-inside:avoid}
            .notes{background:#fff;border:1px dashed #888;border-radius:0;padding:5px;margin:8px 0;font-size:9px;line-height:1.45}
            .summary{padding-top:5px;margin-top:7px;border-color:#000;break-inside:avoid;page-break-inside:avoid}.summary-row{font-size:10px;gap:5px;padding:2px 0}.total{font-size:13px;border-color:#000;margin-top:5px;padding-top:6px}.price-pending{font-size:9px;color:#000}
            .controls,.action-note{display:none!important}a{color:inherit;text-decoration:none}
        }
    </style>
</head>
<body>
<main class="receipt">
    <header class="heading">
        <div class="heading-copy">
            <h1>طلب <bdi class="order-number">#{{ $number }}</bdi></h1>
            <div class="store-name">{{ $card['store'] }}</div>
            <div class="app-name">{{ $card['app_label'] }}</div>
            <div class="muted"><bdi class="created-at">{{ $card['created_label'] }}</bdi> · {{ $card['status_label'] }}</div>
        </div>
        <img class="logo" src="{{ asset('dashboard/branding/fasakhansta-logo-transparent.png') }}" alt="فسخانستا" width="1254" height="1254">
    </header>
    <dl class="information">
        <div class="info-row"><dt>العميل</dt><dd>{{ $card['customer'] }}</dd></div>
        <div class="info-row"><dt>الموبايل</dt><dd><bdi class="number">{{ $card['phone'] ?: 'غير متاح' }}</bdi></dd></div>
        <div class="info-row info-address"><dt>العنوان</dt><dd>{{ $card['address'] ?: 'غير متاح' }}</dd></div>
        <div class="info-row"><dt>طريقة الدفع</dt><dd>{{ $card['payment_label'] ?: 'غير محدد' }}@if(!empty($card['payment_failed']))<strong class="payment-failed">{{ __('order_board.payment_failed') }}</strong>@endif</dd></div>
        @if(!empty($card['courier']))
            <div class="info-row"><dt>المندوب</dt><dd>{{ $card['courier']['name'] }} <bdi class="number courier-phone">{{ $card['courier']['phone'] }}</bdi></dd></div>
        @endif
    </dl>
    @if($card['items'])
        <table class="items">
            <colgroup><col class="item-name-col"><col class="quantity-col"><col class="amount-col"></colgroup>
            <thead><tr><th>الصنف</th><th class="item-quantity">الكمية</th><th class="item-amount">الإجمالي</th></tr></thead>
            <tbody>
            @foreach($card['items'] as $item)
                <tr><td class="item-name">{{ $item['name'] }}@if(!empty($item['option_label']))<span class="item-option">{{ $item['option_label'] }}</span>@endif</td>
                    <td class="item-quantity"><bdi class="number">{{ $item['quantity'] }}</bdi></td>
                    <td class="item-amount"><bdi class="number">{{ isset($item['line_total']) ? $money($item['line_total']) : '—' }}</bdi></td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @if(!empty($card['notes']))<div class="notes"><strong>ملاحظات الطلب</strong>{{ $card['notes'] }}</div>@endif
    <div class="summary">
        @foreach($card['totals'] ?? [] as $amount)
            <div class="summary-row"><span>{{ $amount['label'] }}</span><span><bdi class="number">{{ $money($amount['amount']) }}</bdi> ج.م</span></div>
        @endforeach
        <div class="summary-row total"><span>إجمالي الطلب</span><span>@if(isset($card['total']))<bdi class="number">{{ $money($card['total']) }}</bdi> ج.م@else<span class="price-pending">لم يتم الاتفاق</span>@endif</span></div>
    </div>
    @if(!empty($card['action_note']))<p class="action-note">{{ $card['action_note'] }}</p>@endif
    <div class="controls">
        <button type="button" onclick="window.print()">طباعة الطلب</button>
        @if(!$printing)<a href="{{ route('orders.applies') }}">متابعة الطلبات</a>@endif
        @if(!$printing)
            @foreach($card['actions'] as $action)
                <form method="post" action="{{ $card['urls']['action'] }}" @if($action === 'reject') onsubmit="return confirm('تأكيد رفض الطلب؟')" @endif>
                    @csrf
                    <input type="hidden" name="action" value="{{ $action }}">
                    <input type="hidden" name="expected_status" value="{{ $card['status'] }}">
                    <input type="hidden" name="expected_accepted_notify" value="{{ $card['accepted_notify'] }}">
                    <input type="hidden" name="expected_revision" value="{{ $card['revision'] }}">
                    <button type="submit" class="{{ $action }}">{{ $card['action_labels'][$action] ?? ['accept'=>'قبول الطلب','reject'=>'رفض الطلب','prepare'=>'مندوب الفرع','ready'=>'تم التجهيز','dispatch'=>'تسليم للمندوب','complete'=>'تم التسليم'][$action] }}</button>
                </form>
            @endforeach
        @endif
    </div>
</main>
@if($printing && !request()->boolean('dashboard_print'))<script>window.addEventListener('load',function(){window.print();});</script>@endif
</body>
</html>
