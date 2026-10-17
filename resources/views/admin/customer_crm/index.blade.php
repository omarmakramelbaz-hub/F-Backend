@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/customer-crm.css') }}?v={{ filemtime(public_path('dashboard/css/customer-crm.css')) }}">
@endpush
@section('content')
<div class="content-wrapper"><main id="branch-operations" class="op-workspace" dir="rtl">
<header class="op-header"><div><small>إدارة الفروع</small><h1>قائمة العملاء</h1><p>دليل موحد لكل العملاء، مع نوع المشتريات وموافقات رسائل واتساب.</p></div><div class="op-header-actions"><button data-op-add class="op-primary">إضافة عميل</button>
@if($boot['can_message']??false)
<button data-op-marketing class="op-primary">متابعة العملاء ورسائل واتساب</button>
@endif
</div></header>
<div data-op-message class="op-message" role="status" hidden></div><button data-op-retry hidden>التحقق وإعادة نفس العملية</button>
<section class="op-filters"><label>الفرع<select data-op-filter="branch">@if($boot['allow_all'])<option value="all">جميع الفروع</option>@endif @foreach($boot['branches'] as $b)<option value="{{ $b['value'] }}" @if($boot['selected_branch']===$b['value']) selected @endif>{{ $b['name'] }}</option>@endforeach</select></label><label>بحث<input data-op-filter="search" type="search" placeholder="الاسم أو رقم الهاتف"></label>
<label>المصدر<select data-op-filter="source"><option value="all">كل المصادر</option><option value="takeaway">عميل تيك أواي</option><option value="dine">عميل صالة</option><option value="app">عميل تطبيق</option><option value="delivery">عميل دليفري</option></select></label>
<button data-op-refresh>تحديث</button></section>
<p class="op-help op-crm-followup">تُرسل متابعة الرأي بعد 24 ساعة من إتمام الطلب للعملاء الموافقين على المتابعة، عند تفعيل قالب واتساب المعتمد. وجود رقم العميل لا يُعد موافقة على الرسائل.</p>
<section class="op-cards" data-op-cards></section><div class="op-table-wrap" data-op-table></div><nav class="op-pager"><button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button></nav>
<dialog data-op-dialog><header><h2 data-op-title></h2><button type="button" data-op-close aria-label="إغلاق">×</button></header><div data-op-body></div></dialog>
</main></div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-operations-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/customer-crm.js') }}?v={{ filemtime(public_path('dashboard/js/customer-crm.js')) }}"></script>
@endpush
