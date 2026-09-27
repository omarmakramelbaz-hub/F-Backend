@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content p-4" dir="rtl">
    <a href="{{ route('go-stores.index') }}">العودة إلى متاجر GO</a>
    <h1 class="mt-3">إضافة متجر</h1>
    <p>أنشئ المتجر وحساب صاحبه ليتمكن من إدارة المنتجات على جو بارتنر.</p>
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>راجع البيانات التالية:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('go-stores.store') }}" id="create-go-store">
        @csrf
        <div class="card"><div class="card-body">
            <h2 class="h4 mb-4">بيانات المتجر</h2>
            <div class="row">
                <div class="col-md-6 form-group"><label for="store-name">اسم المتجر</label><input id="store-name" class="form-control" name="name" required minlength="2" maxlength="150" value="{{ old('name') }}" placeholder="اسم المتجر الذي سيظهر في التطبيق"></div>
                <div class="col-md-6 form-group"><label for="store-kind">نوع النشاط</label><select id="store-kind" class="form-control" name="kind" required>
                    <option value="">اختر النشاط</option>@foreach(\App\Services\GoStores\Catalog::KINDS as $key => $label)<option value="{{ $key }}" {{ old('kind') === $key ? 'selected' : '' }}>{{ $label }}</option>@endforeach
                </select></div>
                <div class="col-md-8 form-group"><label for="store-address">العنوان بالتفصيل</label><textarea id="store-address" class="form-control" name="address" required minlength="5" maxlength="500" rows="2" placeholder="المدينة، الشارع، رقم العقار والعلامة المميزة">{{ old('address') }}</textarea></div>
                <div class="col-md-4 form-group"><label for="store-commission">نسبة خدمة التطبيق %</label><input id="store-commission" class="form-control" name="commission_rate" type="number" min="0" max="100" step="0.01" required value="{{ old('commission_rate') }}" placeholder="مثال: 10"><small class="text-muted">النسبة المحددة لهذا المتجر من الداشبورد.</small></div>
            </div>
        </div></div>
        <div class="card"><div class="card-body">
            <h2 class="h4 mb-4">حساب صاحب المتجر</h2>
            <div class="row">
                <div class="col-md-6 form-group"><label for="owner-name">اسم صاحب المتجر</label><input id="owner-name" class="form-control" name="owner_name" required minlength="2" maxlength="130" value="{{ old('owner_name') }}" autocomplete="name"></div>
                <div class="col-md-6 form-group"><label for="owner-mobile">رقم الموبايل للدخول</label><input id="owner-mobile" class="form-control" name="mobile" type="tel" dir="ltr" required maxlength="25" value="{{ old('mobile') }}" placeholder="01012345678" autocomplete="tel"><small class="text-muted">سيستخدمه صاحب المتجر لتسجيل الدخول إلى جو بارتنر.</small></div>
                <div class="col-12 form-group"><label for="owner-email">البريد الإلكتروني للتواصل (اختياري)</label><input id="owner-email" class="form-control" name="email" type="email" dir="ltr" maxlength="254" value="{{ old('email') }}" autocomplete="email"><small class="text-muted">إضافة بريد للتواصل لا توثّقه تلقائيًا لاسترجاع كلمة المرور.</small></div>
                <div class="col-md-6 form-group"><label for="owner-password">كلمة المرور</label><input id="owner-password" class="form-control" name="password" type="password" dir="ltr" required minlength="8" maxlength="72" autocomplete="new-password"><small class="text-muted">8 أحرف على الأقل. شاركها مع صاحب المتجر بشكل خاص.</small></div>
                <div class="col-md-6 form-group"><label for="owner-password-confirm">تأكيد كلمة المرور</label><input id="owner-password-confirm" class="form-control" name="password_confirmation" type="password" dir="ltr" required minlength="8" maxlength="72" autocomplete="new-password"></div>
            </div>
            <p class="text-muted mb-0">يمكن إضافة المنتجات فور إنشاء الحساب. تبدأ المحفظة برصيد صفر، وتحتاج إلى الشحن للوصول للحد الأدنى 50 جنيه لاستقبال الطلبات عند إتاحتها.</p>
        </div></div>
        <div class="d-flex flex-wrap" style="gap:12px">
            <button class="btn btn-primary" type="submit" id="save-store">إنشاء المتجر وحساب الدخول</button>
            <a class="btn btn-outline-secondary" href="{{ route('go-stores.index') }}">رجوع</a>
        </div>
    </form>
</section></div>
<script>
document.getElementById('create-go-store').addEventListener('submit', function () {
    const button = document.getElementById('save-store');
    button.disabled = true;
    button.textContent = 'جارٍ إنشاء المتجر…';
});
window.addEventListener('pageshow', function () {
    const button = document.getElementById('save-store');
    button.disabled = false;
    button.textContent = 'إنشاء المتجر وحساب الدخول';
});
</script>
@endsection
