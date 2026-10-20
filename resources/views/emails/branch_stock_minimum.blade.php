<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>تقرير رصيد البضاعة — فسخانستا</title>
    <style>
        .stock-table td{border-bottom:1px solid #e7edf3;vertical-align:top;text-align:center}
        .stock-table td.stock-name{text-align:right;word-wrap:break-word;overflow-wrap:anywhere}
        .stock-table small{font-size:11px;color:#7a8898}
        .stock-table strong{font-size:14px}
        .stock-table .stock-status{display:inline-block;padding:3px 5px;border-radius:5px;font-size:11px;line-height:1.7;font-weight:bold}
        .stock-table .shortage{font-size:11px;color:#b42318}
    </style>
</head>
<body dir="rtl" style="margin:0;padding:0;background-color:#f2f5f8;color:#20334a;font-family:Tahoma,Arial,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f2f5f8;font-size:1px;line-height:1px;">{{ $alert['low_count'] }} من الأصناف وصلت إلى الحد الأدنى. تقرير واحد يشمل رصيد البضاعة بالكامل.</div>
<table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background-color:#f2f5f8;">
    <tr><td align="center" style="padding:20px 8px;">
        <!--[if mso]><table role="presentation" width="740" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
        <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:740px;border-collapse:separate;border-spacing:0;background-color:#ffffff;border:1px solid #dce4ed;border-radius:14px;overflow:hidden;">
            <tr><td style="height:5px;background-color:#f58220;font-size:1px;line-height:5px;">&nbsp;</td></tr>
            <tr><td style="padding:25px 20px 24px;background-color:#17304a;color:#ffffff;text-align:right;">
                <p style="margin:0 0 12px;color:#ffb36d;font-size:13px;font-weight:bold;letter-spacing:1px;"><span dir="ltr">FASAKHANSTA</span> &nbsp; | &nbsp; فسخانستا</p>
                <h1 style="margin:0 0 10px;color:#ffffff;font-size:25px;line-height:1.5;font-weight:bold;">تقرير رصيد البضاعة</h1>
                <p style="margin:0;color:#d8e3ee;font-size:13px;line-height:1.8;">جميع الفروع &nbsp;•&nbsp; <span dir="ltr" style="white-space:nowrap;">{{ $alert['generated_at'] }}</span> &nbsp;بتوقيت مصر</p>
            </td></tr>
            <tr><td style="padding:20px 16px 16px;">
                <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed;">
                    <tr>
                        <td width="33%" align="center" valign="top" style="padding:12px 4px;background-color:#fff1ee;border:1px solid #f5d4ce;">
                            <p style="margin:0;color:#b42318;font-size:27px;line-height:1.3;font-weight:bold;">{{ $alert['low_count'] }}</p>
                            <p style="margin:5px 0 0;color:#9b3026;font-size:12px;line-height:1.6;">عند الحد الأدنى أو أقل</p>
                        </td>
                        <td width="34%" align="center" valign="top" style="padding:12px 4px;background-color:#f6f8fb;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;">
                            <p style="margin:0;color:#17304a;font-size:27px;line-height:1.3;font-weight:bold;">{{ $alert['total_items'] }}</p>
                            <p style="margin:5px 0 0;color:#66778b;font-size:12px;line-height:1.6;">إجمالي أصناف الفروع</p>
                        </td>
                        <td width="33%" align="center" valign="top" style="padding:12px 4px;background-color:#f6f8fb;border:1px solid #e2e8f0;">
                            <p style="margin:0;color:#17304a;font-size:27px;line-height:1.3;font-weight:bold;">{{ $alert['branch_count'] }}</p>
                            <p style="margin:5px 0 0;color:#66778b;font-size:12px;line-height:1.6;">الفروع في التقرير</p>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;padding:12px 13px;background-color:#fff8f0;border-right:3px solid #f58220;color:#744719;font-size:13px;line-height:1.9;">وصل صنف واحد أو أكثر إلى الحد الأدنى. يشمل هذا التقرير <strong>كل أرصدة البضاعة</strong>، مع تمييز الأصناف التي تحتاج إلى متابعة باللون الأحمر.</p>
            </td></tr>
            @foreach($alert['branches'] as $branch)
            <tr><td style="padding:6px 16px 20px;">
                <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
                    <tr><td style="padding:12px;background-color:#edf2f7;border-top:2px solid #d8e2ed;">
                        <h2 style="margin:0;color:#17304a;font-size:17px;line-height:1.6;">{{ $branch['name'] }}</h2>
                        <p style="margin:3px 0 0;color:#66778b;font-size:12px;line-height:1.8;">{{ $branch['total_items'] }} صنف &nbsp;•&nbsp; <span style="color:{{ $branch['low_count'] > 0 ? '#b42318' : '#66778b' }};">{{ $branch['low_count'] }} عند الحد الأدنى أو أقل</span></p>
                    </td></tr>
                </table>
                <table class="stock-table" dir="rtl" width="100%" cellpadding="7" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed;text-align:right;font-size:13px;line-height:1.8;">
                    <thead><tr style="background-color:#f7f9fc;">
                        <th scope="col" width="37%" style="padding:10px 8px;border-bottom:1px solid #dce4ed;color:#5c6e82;font-size:12px;font-weight:bold;text-align:right;">الصنف</th>
                        <th scope="col" width="21%" style="padding:10px 4px;border-bottom:1px solid #dce4ed;color:#5c6e82;font-size:12px;font-weight:bold;text-align:center;">الرصيد</th>
                        <th scope="col" width="21%" style="padding:10px 4px;border-bottom:1px solid #dce4ed;color:#5c6e82;font-size:12px;font-weight:bold;text-align:center;">الحد الأدنى</th>
                        <th scope="col" width="21%" style="padding:10px 4px;border-bottom:1px solid #dce4ed;color:#5c6e82;font-size:12px;font-weight:bold;text-align:center;">الحالة</th>
                    </tr></thead>
                    <tbody>
@foreach($branch['items'] as $item)
<tr bgcolor="{{ $item['low_stock'] || (float)$item['quantity'] <= 0 ? '#fff6f4' : '#ffffff' }}">
<td class="stock-name" align="right" valign="top"><b>{{ $item['name'] }}</b><br><small>{{ $item['stock_type'] === 'ingredient' ? 'مخزون أساسي' : 'صنف مباشر' }}</small>@if($item['shortage_quantity'] !== null && $item['shortage_quantity'] !== '0')<div class="shortage">للوصول للحد الأدنى: <span dir="ltr">{{ $item['shortage_quantity'] }}</span> {{ $item['unit_label'] }}</div>@endif</td>
<td align="center" valign="top"><strong dir="ltr" style="color:{{ $item['low_stock'] || (float)$item['quantity'] <= 0 ? '#b42318' : '#20334a' }}">{{ $item['quantity'] }}</strong><br><small>{{ $item['unit_label'] }}</small></td>
<td align="center" valign="top">@if($item['minimum_quantity'] !== null)<span dir="ltr">{{ $item['minimum_quantity'] }}</span><br><small>{{ $item['unit_label'] }}</small>@else<small>غير محدد</small>@endif</td>
<td align="center" valign="top"><span class="stock-status" style="background:{{ $item['low_stock'] || (float)$item['quantity'] <= 0 ? '#fde7e3' : ($item['minimum_quantity'] !== null ? '#eaf5f1' : '#eef1f5') }};color:{{ $item['low_stock'] || (float)$item['quantity'] <= 0 ? '#b42318' : ($item['minimum_quantity'] !== null ? '#26735a' : '#66778b') }}">{{ $item['status_label'] }}</span></td>
</tr>
@endforeach
                    </tbody>
                </table>
                <p style="margin:10px 0 0;text-align:left;"><a href="{{ $branch['url'] }}" style="color:#ad530f;font-size:12px;font-weight:bold;text-decoration:underline;">عرض بضاعة الفرع</a></p>
            </td></tr>
            @endforeach
            <tr><td align="center" style="padding:4px 16px 22px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;"><tr><td align="center" bgcolor="#f58220" style="border-radius:7px;background-color:#f58220;"><a href="{{ $alert['url'] }}" style="display:inline-block;padding:12px 24px;color:#ffffff;font-size:14px;line-height:1.6;font-weight:bold;text-decoration:none;">فتح رصيد البضاعة بالكامل</a></td></tr></table>
            </td></tr>
            <tr><td style="padding:16px;background-color:#f7f9fc;border-top:1px solid #e2e8f0;color:#718095;font-size:11px;line-height:1.9;text-align:right;">
                <p style="margin:0 0 5px;">الأرصدة معروضة وقت إعداد التقرير. تُعرض كل كمية بوحدتها، ويُحسب الصنف في كل فرع على حدة.</p>
                <p style="margin:0;">غير محدد: لم يُضبط حد أدنى للصنف. يُرسل التقرير عند تسجيل انخفاض جديد، ولا يتكرر لمجرد بقاء الصنف منخفضًا.</p>
            </td></tr>
        </table>
        <!--[if mso]></td></tr></table><![endif]-->
        <p style="margin:13px 0 0;color:#8490a1;font-family:Tahoma,Arial,sans-serif;font-size:11px;line-height:1.6;">فسخانستا &nbsp;•&nbsp; متابعة مخزون الفروع</p>
    </td></tr>
</table>
</body>
</html>
