@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-shifts.css') }}?v={{ filemtime(public_path('dashboard/css/branch-shifts.css')) }}">
@endpush
@section('content')
<div class="content-wrapper"><main id="branch-shifts" dir="rtl">
<header class="sh-heading"><div><small>حسابات الفروع</small><h1>تقفيل الوردية</h1><p>مراجعة مبيعات الفرع والمصروفات، وتسجيل الكاش الفعلي قبل طباعة القفلة.</p></div><label>الفرع<select data-sh-branch>@foreach($boot['branches'] as $branch)<option value="{{ $branch['value'] }}" @if($boot['selected_branch']===$branch['value']) selected @endif>{{ $branch['name'] }}</option>@endforeach</select></label></header>
<p data-sh-message role="status" hidden></p><button type="button" data-sh-retry hidden>التحقق وإعادة نفس الإغلاق</button>
<section class="sh-period"><span data-sh-period></span><button type="button" data-sh-refresh>تحديث المبيعات</button></section>
<div class="sh-layout"><section class="sh-report"><h2>ملخص الوردية الحالية</h2><div class="sh-table-wrap"><table><thead><tr><th>مصدر المبيعات</th><th>عدد الفواتير</th><th>إجمالي المبيعات</th><th>خدمة التوصيل</th><th>الصافي بعد التوصيل</th></tr></thead><tbody data-sh-channels></tbody></table></div><dl data-sh-totals></dl><p data-sh-warnings></p><p class="sh-hint">تُحتسب الفواتير المحصلة وطلبات التطبيق المكتملة والمصروفات المعتمدة. المبيعات الإلكترونية والتحصيل لدى المندوب منفصلان عن نقدية الدرج.</p></section>
<section class="sh-count"><h2>عدّ النقدية</h2><form data-sh-form><label>الكاش الفعلي الموجود في الدرج <span>جنيه</span><input data-sh-counted name="counted_cash" type="number" min="0" step="0.01" max="1000000000" required inputmode="decimal" autocomplete="off" placeholder="أدخل المبلغ بعد العد"></label><p class="sh-hint">عدّ النقدية الخاصة بالفرع بعد فصل قيمة خدمة التوصيل. رصيد الدرج والنقدية المحسوبة والفرق تظهر في ورقة القفلة فقط بعد الإغلاق.</p><label>ملاحظات الوردية<textarea data-sh-notes maxlength="1000" rows="3"></textarea></label><button type="submit" class="sh-close">إغلاق الوردية وطباعة القفلة</button><p class="sh-hint">يثبت الإغلاق المبلغ الذي أدخلته. إعادة الطباعة تستخدم نفس القفلة المحفوظة. الإغلاق لا ينفذ سحبًا أو تحويلًا للنقدية.</p></form></section></div>
<section class="sh-history"><h2>القفلات السابقة</h2><div class="sh-table-wrap"><table><thead><tr><th>رقم القفلة</th><th>بداية الوردية</th><th>وقت الإغلاق</th><th>الطباعة</th></tr></thead><tbody data-sh-history></tbody></table></div></section>
</main></div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-shifts-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/branch-shifts.js') }}?v={{ filemtime(public_path('dashboard/js/branch-shifts.js')) }}"></script>
@endpush
