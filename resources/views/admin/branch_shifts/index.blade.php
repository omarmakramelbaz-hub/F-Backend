@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-shifts.css') }}?v={{ filemtime(public_path('dashboard/css/branch-shifts.css')) }}">
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
    <section class="sh-report sh-all" data-sh-all hidden>
        <header><h2>الورديات الحالية لجميع الفروع</h2><p class="sh-hint">اختر فرعًا لعدّ النقدية وإغلاق ورديته.</p></header>
        <div class="sh-table-wrap" tabindex="0" role="region" aria-label="الورديات الحالية">
            <table><thead><tr><th>الفرع</th><th>بداية الوردية</th><th>مصروفات معلقة</th><th>طلبات نقدية لم تسوّ بعد</th></tr></thead><tbody data-sh-branch-reports></tbody></table>
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
