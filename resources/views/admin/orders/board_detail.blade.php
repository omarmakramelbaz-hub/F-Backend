<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طلب {{ $card['number'] }} · {{ $card['app_label'] }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eef1f6;color:#172137;font-family:Tahoma,Arial,sans-serif;line-height:1.7}
        .receipt{max-width:850px;margin:30px auto;background:#fff;border-radius:18px;padding:30px;box-shadow:0 10px 35px #13233a12}
        .heading{display:flex;gap:20px;justify-content:space-between;align-items:center;border-bottom:1px solid #e6e9ef;padding-bottom:20px}
        .logo{width:90px;height:90px;object-fit:contain}.heading h1{font-size:24px;margin:0}.muted{color:#69768b;font-size:13px}
        .information{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:25px 0}.information div{min-width:0}.label{display:block;color:#69768b;font-size:13px}
        .ltr{direction:ltr;unicode-bidi:isolate;display:inline-block}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{padding:12px 8px;text-align:right;border-bottom:1px solid #e8eaf1}th{color:#66758b;font-size:13px}
        .total{display:flex;justify-content:space-between;align-items:center;font-size:21px;border-top:2px solid #172137;padding-top:16px;font-weight:bold}
        .notes{white-space:pre-line;overflow-wrap:anywhere;background:#faf6ed;padding:15px;border-radius:8px}.controls{display:flex;gap:10px;flex-wrap:wrap;margin-top:25px}
        .controls a,.controls button{font:inherit;border:0;border-radius:8px;padding:10px 18px;background:#172137;color:#fff;text-decoration:none;cursor:pointer}
        .controls .reject{background:#c83445}.controls .accept{background:#168750}.action-note{font-size:13px;color:#69768b;margin-top:15px}
        @media(max-width:600px){.receipt{margin:10px;border-radius:12px;padding:20px}.information{grid-template-columns:1fr}.heading h1{font-size:20px}.logo{width:70px;height:70px}}
        @media print{body{background:#fff}.receipt{box-shadow:none;border-radius:0;margin:0;max-width:100%;padding:10mm}.controls{display:none}.notes{background:#fff;border:1px solid #ddd}.logo{width:22mm;height:22mm}a{color:inherit}}
    </style>
</head>
<body>
<main class="receipt">
    <header class="heading">
        <div><h1>طلب <bdi>{{ $card['number'] }}</bdi></h1><div>{{ $card['store'] }} · {{ $card['app_label'] }}</div><div class="muted">{{ $card['created_label'] }} · {{ $card['status_label'] }}</div></div>
        <img class="logo" src="{{ asset('dashboard/branding/fasakhansta-logo.png') }}" alt="فسخانستا">
    </header>
    <section class="information">
        <div><span class="label">العميل</span>{{ $card['customer'] }}</div>
        <div><span class="label">الموبايل</span><bdi class="ltr">{{ $card['phone'] ?: 'غير متاح' }}</bdi></div>
        <div><span class="label">العنوان</span>{{ $card['address'] ?: 'غير متاح' }}</div>
        <div><span class="label">طريقة الدفع</span>{{ $card['payment_label'] ?: 'غير محدد' }}</div>
        @if(!empty($card['courier']))
            <div><span class="label">المندوب</span>{{ $card['courier']['name'] }} <bdi class="ltr">{{ $card['courier']['phone'] }}</bdi></div>
        @endif
    </section>
    @if($card['items'])
        <table><thead><tr><th>الصنف</th><th>الكمية</th><th>الإجمالي</th></tr></thead><tbody>
        @foreach($card['items'] as $item)
            <tr><td>{{ $item['name'] }}@if(!empty($item['option_label']))<div class="muted">{{ $item['option_label'] }}</div>@endif</td>
                <td><bdi>{{ $item['quantity'] }}</bdi></td><td><bdi>{{ $item['line_total'] ?? '—' }}</bdi> ج.م</td></tr>
        @endforeach
        </tbody></table>
    @endif
    @if(!empty($card['notes']))<p class="notes">{{ $card['notes'] }}</p>@endif
    <div class="total"><span>إجمالي الطلب</span><span><bdi>{{ $card['total'] ?? 'لم يتم الاتفاق' }}</bdi>@if($card['total'] !== null) ج.م@endif</span></div>
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
@if($printing)<script>window.addEventListener('load',function(){window.print();});</script>@endif
</body>
</html>
