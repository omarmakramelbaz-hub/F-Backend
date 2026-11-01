@extends('admin.index')
@section('content')
<div class="content-wrapper"><div class="content-header"><div class="container-fluid"><h1>{{ $owner?'تعديل حساب الأونر':'إضافة أونر بلوحة مستقلة' }}</h1></div></div>
<section class="content"><div class="container-fluid"><div class="card"><div class="card-body">
<p>اللوحة الجديدة تبدأ بدون فروع أو حسابات أو عمليات سابقة. الأونر يدير كل أعماله من رابط دخوله الخاص.</p>
@if($owner)
<div class="alert alert-info" data-owner-status data-status-url="{{ route('owners.status',$owner->id) }}">حالة اللوحة: <strong data-owner-state>{{ ['pending'=>'جاري تطبيق التغييرات','active'=>'جاهزة','suspended'=>'موقوفة','error'=>'تعذر التجهيز'][$owner->state] ?? $owner->state }}</strong>
<a data-owner-login href="{{ \App\Services\OwnerDashboards\Registry::url($owner) }}" target="_blank" rel="noopener" @if($owner->state!=='active'||$owner->desired_state!=='active'||$owner->revision!==$owner->applied_revision) hidden @endif>فتح رابط الدخول</a>
<p data-owner-host-status @if($owner->error_code!=='GO_OWNER_DNS_OR_SSL_PENDING') hidden @endif>بانتظار جاهزية نطاق اللوحة وشهادة الاتصال الآمن: <span dir="ltr">{{ parse_url(\App\Services\OwnerDashboards\Registry::url($owner), PHP_URL_HOST) }}</span></p>
@if($owner->state==='error')<p>تعذر تجهيز اللوحة. احفظ البيانات مرة أخرى لإعادة المحاولة. كود المتابعة: {{ $owner->error_code }}</p>@endif</div>
@endif
<form method="post" action="{{ $owner?route('owners.update',$owner->id):route('owners.store') }}" autocomplete="off">
@csrf @if($owner)@method('PUT')@endif
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<p class="mb-1">{{ $error }}</p>@endforeach</div>@endif
<div class="row"><div class="col-md-6 form-group"><label for="owner-name">اسم الأونر / النشاط</label><input id="owner-name" class="form-control" name="name" value="{{ old('name',$owner->name??'') }}" maxlength="130" required></div>
<div class="col-md-6 form-group"><label for="owner-email">البريد الإلكتروني للدخول</label><input id="owner-email" class="form-control" name="email" type="email" dir="ltr" value="{{ old('email',$owner->email??'') }}" maxlength="191" required></div>
<div class="col-md-6 form-group"><label for="owner-mobile">رقم الموبايل للدخول (اختياري)</label><div class="input-group" dir="ltr"><div class="input-group-prepend"><span class="input-group-text">+20</span></div><input id="owner-mobile" class="form-control" name="mobile" type="tel" dir="ltr" value="{{ old('mobile',$owner->mobile??'') }}" placeholder="1012345678"></div></div>
<div class="col-md-6 form-group"><label for="owner-password">{{ $owner?'كلمة مرور جديدة (اتركها فارغة للإبقاء على الحالية)':'كلمة المرور' }}</label><input id="owner-password" class="form-control" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" @if(!$owner) required @endif></div>
<div class="col-md-6 form-group"><label for="owner-confirm">تأكيد كلمة المرور</label><input id="owner-confirm" class="form-control" name="password_confirmation" type="password" autocomplete="new-password" @if(!$owner) required @endif></div></div>
<button type="submit" class="btn btn-success">{{ $owner?'حفظ التغييرات':'إنشاء حساب الأونر ولوحته' }}</button><a class="btn btn-outline-secondary mx-2" href="{{ route('owners.index') }}">عرض الأونرز</a>
</form></div></div></div></section></div>
@endsection
@push('custom-js')
<script>
(function(){var box=document.querySelector('[data-owner-status]');if(!box)return;var labels={pending:'جاري تطبيق التغييرات',active:'جاهزة',suspended:'موقوفة',error:'تعذر التجهيز'};var timer=setInterval(function(){if(!document.body.contains(box)){clearInterval(timer);return;}fetch(box.dataset.statusUrl,{headers:{Accept:'application/json'},cache:'no-store',credentials:'same-origin'}).then(function(r){if(!r.ok)throw Error();return r.json();}).then(function(data){box.querySelector('[data-owner-state]').textContent=labels[data.state]||data.state;box.querySelector('[data-owner-host-status]').hidden=data.error_code!=='GO_OWNER_DNS_OR_SSL_PENDING';box.querySelector('[data-owner-login]').hidden=!(data.state==='active'&&data.desired_state==='active'&&Number(data.revision)===Number(data.applied_revision));}).catch(function(){clearInterval(timer);});},5000);})();
</script>
@endpush
