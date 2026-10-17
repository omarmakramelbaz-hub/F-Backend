@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-operations.css') }}?v={{ filemtime(public_path('dashboard/css/branch-operations.css')) }}">
@endpush
@section('content')
<div class="content-wrapper"><main id="branch-operations" class="op-workspace {{ $boot['module']==='employees'?'op-employees':'' }}" dir="rtl">
<header class="op-header"><div><small>إدارة الفروع</small><h1>{{ ['employees'=>'متابعة الموظفين اليومية','customers'=>'قائمة العملاء','delivery-companies'=>'شركات الدليفري'][$boot['module']] }}</h1><p>{{ ['employees'=>'الحضور والخصومات والمكافآت والسلف لكل موظف، في اليوم المحدد.','customers'=>'بيانات العملاء المحفوظة والعملاء المسجلون وطلبات الهاتف السابقة.','delivery-companies'=>'شركات التوصيل وطلبات كل شركة في الفروع المصرح لك بها.'][$boot['module']] }}</p></div><div class="op-header-actions"><button data-op-add class="op-primary">{{ ['employees'=>'إضافة موظف','customers'=>'إضافة عميل','delivery-companies'=>'إضافة شركة'][$boot['module']] }}</button>
@if($boot['module']==='employees' && $boot['initial']['can_manage_attendance'])
<button data-op-attendance-rules class="op-primary" title="إعدادات مستقلة للصباح والمساء بكل فرع · الخصم لكل نصف ساعة مكتملة · الوقت بتوقيت مصر">مواعيد الحضور والانصراف والخصومات</button>
@endif
</div></header>
<div data-op-message class="op-message" role="status" hidden></div><button data-op-retry hidden>التحقق وإعادة نفس العملية</button>
<section class="op-filters"><label>الفرع<select data-op-filter="branch">@if($boot['allow_all'])<option value="all">جميع الفروع</option>@endif @foreach($boot['branches'] as $b)<option value="{{ $b['value'] }}" @if($boot['selected_branch']===$b['value']) selected @endif>{{ $b['name'] }}</option>@endforeach</select></label><label>بحث<input data-op-filter="search" type="search" placeholder="{{ $boot['module']==='employees'?'بحث باسم الموظف':'الاسم أو رقم الهاتف' }}"></label>
@if($boot['module']==='employees')<label>الوردية<select data-op-filter="shift"><option value="">كل الورديات</option></select></label><label>الوظيفة<select data-op-filter="job_title"><option value="">كل الوظائف</option></select></label><label title="من 6 صباحًا إلى 6 صباحًا بتوقيت مصر">يوم التشغيل<input type="date" data-op-filter="day" value="{{ $boot['today'] }}"></label>
@elseif($boot['module']==='customers')<label>المصدر<select data-op-filter="source"><option value="all">كل المصادر</option><option value="saved">دليل العملاء</option><option value="app">عملاء التطبيق</option><option value="history">طلبات الهاتف السابقة</option></select></label>@endif
<button data-op-refresh>تحديث</button></section>
@if($boot['module']==='employees')<section class="op-month-tools"><label>شهر المستحقات <input type="month" data-op-filter="month" value="{{ substr($boot['today'],0,7) }}"></label><button data-op-month class="op-month-button">تصفية حساب الشهر</button><button data-op-export>تصدير كشف الشهر Excel (CSV)</button><span>اضغط على إجمالي الخصومات أو المكافآت أو السلف لعرض التفاصيل.</span></section>@endif
<section class="op-cards" data-op-cards></section><div class="op-table-wrap" data-op-table></div><nav class="op-pager"><button data-op-prev>السابق</button><span data-op-pages></span><button data-op-next>التالي</button></nav>
<dialog data-op-dialog><header><h2 data-op-title></h2><button type="button" data-op-close aria-label="إغلاق">×</button></header><div data-op-body></div></dialog>
</main></div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-operations-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/branch-operations.js') }}?v={{ filemtime(public_path('dashboard/js/branch-operations.js')) }}"></script>
@endpush
