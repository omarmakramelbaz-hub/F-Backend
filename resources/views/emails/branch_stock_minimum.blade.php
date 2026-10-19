<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><title>تنبيه الحد الأدنى للبضاعة</title></head>
<body style="font-family:Tahoma,Arial,sans-serif;color:#212529;line-height:1.9">
<h2 style="color:#b42318">صنف بضاعة وصل إلى الحد الأدنى</h2>
<p>الفرع: <strong>{{ $alert['branch_name'] }}</strong></p>
<p>الصنف: <strong>{{ $alert['item_name'] }}</strong></p>
<p style="color:#b42318">الرصيد الحالي: <strong>{{ $alert['quantity'] }} {{ $alert['unit_label'] }}</strong></p>
<p>الحد الأدنى: <strong>{{ $alert['minimum_quantity'] }} {{ $alert['unit_label'] }}</strong></p>
<p>وقت التنبيه: {{ $alert['detected_at'] }} (توقيت مصر)</p>
<p><a href="{{ $alert['url'] }}">عرض بضاعة الفرع</a></p>
<p style="color:#667085;font-size:13px">يتم إرسال تنبيه جديد بعد ارتفاع الرصيد فوق الحد الأدنى ثم وصوله إليه مرة أخرى.</p>
</body>
</html>
