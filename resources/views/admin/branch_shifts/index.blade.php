@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-shifts.css') }}?v={{ filemtime(public_path('dashboard/css/branch-shifts.css')) }}">
<style>
#branch-shifts .sh-pending{display:flex;flex-direction:column;flex:0 1 auto;max-height:29%;min-height:78px;overflow:hidden;padding:0;border-color:#f1d7b9}
#branch-shifts .sh-pending-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;flex-shrink:0;background:#fff8ee}
#branch-shifts .sh-pending-heading h2{font-size:14px;color:#8c4f16}
#branch-shifts [data-sh-pending-total]{display:inline-block;padding:1px 7px;border-radius:5px;background:#f58220;color:white;font-size:12px;margin-inline-start:5px}
#branch-shifts [data-sh-pending-counts]{color:#92602d;font-size:11px;margin-top:3px}
#branch-shifts .sh-pending .sh-hint{padding:7px 12px;font-size:10px;flex-shrink:0}
#branch-shifts [data-sh-pending-table-wrap]{flex:1;border-top:1px solid #f3e7d8}
#branch-shifts .sh-pending table{min-width:600px}
#branch-shifts .sh-pending td a{color:#b85e0d;font-weight:700;text-decoration:underline}
#branch-shifts [data-sh-pending-empty]{padding:8px 12px;font-size:11px;color:#48735b}
#branch-shifts [data-sh-pending-warning]{grid-column:1/-1;padding:7px 10px;background:#fff1ed;color:#b42318;border:1px solid #f4cfc7;border-radius:6px;font-size:12px;font-weight:700}
@media(max-height:750px){#branch-shifts .sh-pending{max-height:26%;min-height:72px}#branch-shifts .sh-pending-heading{padding:7px 10px}#branch-shifts .sh-pending .sh-hint{padding:5px 10px}}
</style>
@endpush
@section('content')
<div class="content-wrapper branch-shifts-wrapper">
<main id="branch-shifts" dir="rtl">
    <header class="sh-heading">
        <div><small>حسابات الفروع</small><h1>تقفيل الوردية</h1><p>سجّل العدّ الفعلي واحفظ كشف الوردية.</p></div>
        <label>الفرع
            <select data-sh-branch>
                @if(!empty($boot['can_all']))
                <option value="all" @if($boot['selected_branch']==='all') selected @endif>جميع الفروع</option>
                @endif
                @foreach($boot['branches'] as $branch)
                <option value="{{ $branch['value'] }}" @if($boot['selected_branch']===$branch['value']) selected @endif>{{ $branch['name'] }}</option>
                @endforeach
            </select>
        </label>
    </header>
    <p data-sh-message role="status" hidden></p>
    <button type="button" data-sh-retry hidden>التحقق وإعادة نفس الإغلاق</button>
    <section class="sh-period"><span data-sh-period></span><button type="button" data-sh-refresh>تحديث الوردية</button></section>
    <section class="sh-report sh-pending" aria-labelledby="shift-pending-title">
        <header class="sh-pending-heading"><div><h2 id="shift-pending-title">الفواتير المعلقة <span data-sh-pending-total>0</span></h2><p data-sh-pending-counts></p></div></header>
        <p class="sh-hint">تشمل الفواتير غير المنتهية من الأيام السابقة. أغلِق جميع الفواتير قبل تقفيل الوردية.</p>
        <p data-sh-pending-empty hidden>لا توجد فواتير معلقة. يمكنك متابعة إغلاق الوردية.</p>
        <div class="sh-table-wrap" data-sh-pending-table-wrap tabindex="0" role="region" aria-label="الفواتير المعلقة" hidden>
            <table><thead><tr><th>رقم الفاتورة</th><th>النوع</th><th>الفرع</th><th>الترابيزة</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody data-sh-pending-invoices></tbody></table>
        </div>
    </section>
    <section class="sh-report sh-all" data-sh-all hidden>
        <header><h2>الورديات الحالية لجميع الفروع</h2><p class="sh-hint">اختر فرعًا لعدّ النقدية وإغلاق ورديته.</p></header>
        <div class="sh-table-wrap" tabindex="0" role="region" aria-label="الورديات الحالية">
            <table><thead><tr><th>الفرع</th><th>بداية الوردية</th><th>فواتير معلقة</th><th>مصروفات معلقة</th><th>طلبات نقدية لم تسوّ بعد</th></tr></thead><tbody data-sh-branch-reports></tbody></table>
        </div>
    </section>
    <div class="sh-layout">
        <section class="sh-report sh-guidance">
            <h2><i class="fas fa-cash-register" aria-hidden="true"></i> قبل الإغلاق</h2>
            <ol><li>افصل قيمة خدمة التوصيل الخاصة بالمندوب.</li><li>عدّ الكاش الموجود فعليًا وسجّله.</li><li>احفظ القفلة؛ الكشف يُرسل لطابعة الفرع.</li></ol>
            <p class="sh-carry">رصيد الدرج مستمر للوردية التالية، وترقيم طلبات كل قناة يبدأ من ١.</p>
            <p data-sh-warnings role="status"></p>
        </section>
        <section class="sh-count">
            <h2>عدّ النقدية</h2>
            <form data-sh-form>
                <p data-sh-pending-warning role="alert" hidden>برجاء إغلاق الفواتير أولًا.</p>
                <label>الكاش الفعلي في الدرج <span>جنيه</span><input data-sh-counted name="counted_cash" type="number" min="0" step="0.01" max="1000000000" required inputmode="decimal" autocomplete="off" placeholder="المبلغ بعد العدّ"></label>
                <label>ملاحظات الوردية <span>اختياري</span><textarea data-sh-notes maxlength="1000" rows="2" placeholder="أضف ملاحظة للقفلة"></textarea></label>
                <button type="submit" class="sh-close">إغلاق الوردية وطباعة القفلة</button>
                <p class="sh-hint">رصيد الدرج مستمر والطباعة على الفرع. التفاصيل في فاتورة القفلة المحفوظة.</p>
            </form>
        </section>
    </div>
    <section class="sh-history">
        <header class="sh-history-heading">
            <div><h2>القفلات السابقة</h2><span data-sh-selection>اختر قفلة من السجل لعرض فاتورتها.</span></div>
            <button type="button" data-sh-preview disabled><i class="fas fa-file-invoice" aria-hidden="true"></i> عرض فاتورة الإغلاق</button>
        </header>
        <div class="sh-table-wrap" tabindex="0" role="region" aria-label="القفلات السابقة">
            <table><thead><tr><th class="sh-select-col"><span class="sh-sr-only">اختيار القفلة</span></th><th>الفرع</th><th>رقم القفلة</th><th>بداية الوردية</th><th>وقت الإغلاق</th><th>الطباعة</th></tr></thead><tbody data-sh-history></tbody></table>
        </div>
    </section>
</main>
</div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-shifts-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/branch-shifts.js') }}?v={{ filemtime(public_path('dashboard/js/branch-shifts.js')) }}"></script>
@endpush
