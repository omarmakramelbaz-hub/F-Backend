@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-shifts.css') }}?v={{ filemtime(public_path('dashboard/css/branch-shifts.css')) }}">
@endpush
@section('content')
<div class="content-wrapper"><main id="branch-shifts" dir="rtl">
<header class="sh-heading"><div><small>حسابات الفروع</small><h1>تقفيل الوردية</h1><p>عدّ الكاش الموجود فعليًا، ثم إغلاق الوردية وطباعة كشف الحساب.</p></div><label>الفرع<select data-sh-branch>@foreach($boot['branches'] as $branch)<option value="{{ $branch['value'] }}" @if($boot['selected_branch']===$branch['value']) selected @endif>{{ $branch['name'] }}</option>@endforeach</select></label></header>
<p data-sh-message role="status" hidden></p><button type="button" data-sh-retry hidden>التحقق وإعادة نفس الإغلاق</button>
<section class="sh-period"><span data-sh-period></span><button type="button" data-sh-refresh>تحديث الوردية</button></section>
<div class="sh-layout"><section class="sh-report sh-blind"><span class="sh-blind-icon" aria-hidden="true"><i class="fas fa-cash-register"></i></span><h2>تقفيلة بدون إظهار الأرصدة</h2><p>عدّ النقدية الفعلية وسجّلها في الخانة المقابلة.</p><ol><li>افصل قيمة خدمة التوصيل الخاصة بالمندوب.</li><li>سجّل الكاش الموجود فعليًا في الدرج.</li><li>اضغط إغلاق الوردية وطباعة القفلة.</li></ol><p class="sh-hint">إجمالي المبيعات والمصروفات والنقدية المحسوبة والعجز أو الزيادة تظهر في ورقة القفلة فقط. لن تُعرض على الشاشة بعد الإغلاق.</p><p data-sh-warnings></p></section>
<section class="sh-count"><h2>عدّ النقدية</h2><form data-sh-form><label>الكاش الفعلي الموجود في الدرج <span>جنيه</span><input data-sh-counted name="counted_cash" type="number" min="0" step="0.01" max="1000000000" required inputmode="decimal" autocomplete="off" placeholder="أدخل المبلغ بعد العد"></label><p class="sh-hint">عدّ النقدية الخاصة بالفرع بعد فصل قيمة خدمة التوصيل. رصيد الدرج والنقدية المحسوبة والفرق تظهر في ورقة القفلة فقط بعد الإغلاق.</p><label>ملاحظات الوردية<textarea data-sh-notes maxlength="1000" rows="3"></textarea></label><button type="submit" class="sh-close">إغلاق الوردية وطباعة القفلة</button><p class="sh-hint">يثبت الإغلاق المبلغ الذي أدخلته. إعادة الطباعة تستخدم نفس القفلة المحفوظة. بعد تسليم نقدية الوردية، يصفر الإغلاق رصيد الدرج المسجل وتبدأ الوردية التالية من صفر. أي عهدة جديدة تُسجل بإيداع نقدي.</p></form></section></div>
<section class="sh-history"><h2>القفلات السابقة</h2><div class="sh-table-wrap"><table><thead><tr><th>رقم القفلة</th><th>بداية الوردية</th><th>وقت الإغلاق</th><th>الطباعة</th></tr></thead><tbody data-sh-history></tbody></table></div></section>
</main></div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-shifts-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/branch-shifts.js') }}?v={{ filemtime(public_path('dashboard/js/branch-shifts.js')) }}"></script>
@endpush
