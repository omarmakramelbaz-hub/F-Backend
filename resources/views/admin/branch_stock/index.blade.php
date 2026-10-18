@extends('admin.index')
@push('custom-css')
<link rel="stylesheet" href="{{ asset('dashboard/css/branch-stock.css') }}?v={{ filemtime(public_path('dashboard/css/branch-stock.css')) }}">
@endpush
@section('content')
<div class="content-wrapper branch-stock-wrapper"><main id="branch-stock" dir="rtl" data-inventory-tab="goods">
<header class="bs-heading"><div><h1><i class="fas fa-boxes" aria-hidden="true"></i> إضافة بضاعة</h1><p>توريد البضاعة وربط أصناف البيع بوصفات المكونات</p></div>
<nav class="bs-tabs" aria-label="المخزون والوصفات"><button type="button" data-inventory-tab="goods" aria-pressed="true">البضاعة والأرصدة</button><button type="button" data-inventory-tab="recipes" aria-pressed="false">وصفات المينيو</button></nav>
<label>الفرع<select data-stock-branch @if(count($boot['branches'])===1 && empty($boot['can_all'])) disabled @endif>@if(!empty($boot['can_all']))<option value="all" @if($boot['branch']==='all') selected @endif>جميع الفروع</option>@endif
@foreach($boot['branches'] as $b)<option value="{{ $b['value'] }}" @if($boot['branch']===$b['value']) selected @endif>{{ $b['name'] }}</option>@endforeach</select></label></header>
<p class="bs-note bs-guidance" data-stock-guidance></p>
<div data-inventory-panel="goods"><div class="bs-layout"><section class="bs-panel bs-entry"><header><h2>تسجيل بضاعة واردة</h2><span>إضافة جديدة</span></header>
<form data-stock-form><label class="bs-search-field">البحث عن صنف البضاعة<input data-stock-search type="search" maxlength="100" placeholder="اكتب اسم الصنف لتصفية القائمة"></label><label class="bs-product-field">الصنف<select name="ingredient_id" data-stock-product required><option value="">اختر صنف البضاعة</option></select></label>
<div class="bs-current"><span>الكمية الحالية في الفرع</span><strong data-stock-current>اختر الصنف</strong></div>
<div class="bs-pair"><label>الكمية المضافة<input name="quantity" type="text" inputmode="decimal" maxlength="16" required placeholder="0.000" autocomplete="off"></label><label>الوحدة الأساسية<select name="unit"><option value="kg">كيلو</option><option value="piece">قطعة</option></select></label></div>
<label class="bs-supplier-field">المورّد <small>اختياري</small><input name="supplier" maxlength="150" placeholder="اسم مورّد البضاعة"></label><label class="bs-notes-field">ملاحظات <small>اختياري</small><textarea name="notes" maxlength="500" rows="2" placeholder="رقم إذن التوريد أو ملاحظة الاستلام"></textarea></label>
<p class="bs-note bs-entry-help">الكيلو حتى 3 منازل عشرية؛ القطع أعداد صحيحة. سجّل تكلفة الشراء في صفحة المصروفات.</p>
<button class="bs-primary" type="submit" data-stock-save><i class="fas fa-plus" aria-hidden="true"></i> إضافة البضاعة لرصيد الفرع</button></form></section>
<div class="bs-overview"><div class="bs-message" data-stock-message role="status" hidden></div><button class="bs-button" type="button" data-stock-retry hidden>التحقق من آخر إضافة وإعادة المحاولة</button>
<section class="bs-panel bs-balances"><header><div><h2>أرصدة البضاعة</h2><p>تخصم المبيعات عند إنهاء الفاتورة أو طلب التطبيق</p></div><button class="bs-button" type="button" data-stock-refresh><i class="fas fa-sync-alt" aria-hidden="true"></i> تحديث</button></header><div class="bs-table-wrap" tabindex="0" aria-label="جدول أرصدة البضاعة"><table><thead><tr><th>الصنف</th><th>الوحدة</th><th>الرصيد الحالي</th><th>نوع الصنف</th></tr></thead><tbody data-stock-rows></tbody></table></div><footer class="bs-pagination"><button class="bs-button" type="button" data-stock-previous>السابق</button><span data-stock-page></span><button class="bs-button" type="button" data-stock-next>التالي</button></footer><p class="bs-note">تُخصم مكونات الوجبات والساندوتشات والسلطات حسب الوصفة المسجلة. الرصيد السالب بالأحمر.</p></section>
<details class="bs-panel bs-history"><summary><span>آخر حركات البضاعة</span><small>30 حركة</small></summary><div class="bs-table-wrap" tabindex="0" aria-label="جدول حركات البضاعة"><table><thead><tr><th>التاريخ</th><th>الصنف</th><th>الحركة</th><th>الكمية</th><th>الرصيد بعدها</th><th>المسجّل</th><th>المورّد / الملاحظات</th></tr></thead><tbody data-stock-history></tbody></table></div></details>
<details class="bs-panel bs-legacy" data-stock-legacy hidden><summary>أرصدة النظام السابق للمراجعة</summary><p class="bs-note">هذه الأرصدة محفوظة كما هي، ولم نحولها إلى مكونات بالتخمين. راجع الرصيد الفعلي للبضاعة قبل تسجيله في القائمة الجديدة.</p><div class="bs-table-wrap"><table><thead><tr><th>الصنف السابق</th><th>الرصيد</th><th>الوحدة</th></tr></thead><tbody data-stock-legacy-rows></tbody></table></div></details>
<div class="bs-message" data-stock-unmapped hidden></div>
</div></div></div>
<section data-inventory-panel="recipes" data-recipe-panel hidden>
<header class="bs-recipe-heading"><div><h2>وصفات أصناف المينيو</h2><p>حدد ما يُستهلك عند بيع وحدة واحدة أو كيلو واحد من الصنف. لكل حجم وصفة مستقلة.</p></div></header>
<div class="bs-message" data-recipe-message role="status" hidden></div><button type="button" class="bs-button" data-recipe-retry hidden>التحقق من حفظ الوصفة وإعادة المحاولة</button>
<div class="bs-recipe-layout"><section class="bs-panel"><header><h2>أصناف البيع</h2><button class="bs-button" type="button" data-recipe-refresh>تحديث</button></header><label class="bs-recipe-search">بحث في المينيو<input type="search" maxlength="100" data-recipe-search placeholder="وجبة، ساندوتش، سلطة أو صنف مباشر"></label><div class="bs-recipe-list" data-recipe-products></div><footer class="bs-pagination"><button type="button" class="bs-button" data-recipe-prev>السابق</button><span data-recipe-page></span><button type="button" class="bs-button" data-recipe-next>التالي</button></footer></section>
<section class="bs-panel"><header><div><h2 data-recipe-title>اختر صنفًا لتسجيل وصفته</h2><p data-recipe-revision></p></div></header><form data-recipe-form hidden>
<div class="bs-pair"><label>حجم الصنف<select name="feature_id" data-recipe-feature></select></label><label>المقادير لكل<select name="unit"><option value="piece">وحدة واحدة / طبق / وجبة / ساندوتش</option><option value="kg">كيلو واحد مباع</option></select></label></div>
<p class="bs-note">اختر الخامة والكمية لكل مكوّن. الصنف المباشر مثل علبة أو مشروب يحتاج ربطه بالبضاعة المقابلة أيضًا. خيارات التنظيف والتغليف تستخدم وصفة نفس الحجم.</p>
<div class="bs-component-head"><span>المكوّن</span><span>المقدار</span><span>الوحدة</span><span></span></div><div data-recipe-components></div>
<button class="bs-button" type="button" data-recipe-add><i class="fas fa-plus" aria-hidden="true"></i> إضافة مكوّن</button>
<p class="bs-note">المقادير تُسجّل بمعرفة الإدارة. يُخصم إجمالي المكونات مرة واحدة عند إنهاء البيع، وتبقى وصفة الفاتورة المحفوظة ثابتة حتى تحصيلها.</p>
<button class="bs-primary" type="submit" data-recipe-save>حفظ وصفة الصنف</button>
</form><p class="bs-note" data-recipe-empty>اختر الصنف من القائمة. لن تُفترض مقادير لأي وصفة.</p></section></div>
</section>
</main></div>
@endsection
@push('custom-js')
<script type="application/json" id="branch-stock-bootstrap">@json($boot)</script>
<script src="{{ asset('dashboard/js/branch-stock.js') }}?v={{ filemtime(public_path('dashboard/js/branch-stock.js')) }}"></script>
<script src="{{ asset('dashboard/js/branch-recipes.js') }}?v={{ filemtime(public_path('dashboard/js/branch-recipes.js')) }}"></script>
@endpush
