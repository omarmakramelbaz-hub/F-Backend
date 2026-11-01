@extends('admin.index')
@section('content')
<div class="content-wrapper"><div class="content-header"><div class="container-fluid d-flex justify-content-between align-items-center"><h1>الأونرز واللوحات المستقلة</h1><a class="btn btn-primary" href="{{ route('owners.create') }}">إضافة أونر بلوحة جديدة</a></div></div>
<section class="content"><div class="container-fluid"><div class="card"><div class="card-body">
<p>كل أونر له لوحة خاصة بفروعه وحساباته وبياناته. إضافة الأونر وإدارة حسابه متاحة للأونر الرئيسي فقط.</p>
<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>الأونر</th><th>البريد الإلكتروني</th><th>الهاتف</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
@forelse($owners as $owner)
<tr><td>{{ $owner->name }}</td><td dir="ltr">{{ $owner->email }}</td><td dir="ltr">{{ $owner->mobile ? '+20 '.$owner->mobile : '—' }}</td><td>{{ ['pending'=>'جاري تطبيق التغييرات','active'=>'جاهزة','suspended'=>'موقوفة','error'=>'تعذر التجهيز','deleted'=>'محذوفة'][$owner->state] ?? $owner->state }}</td><td>
<a class="btn btn-warning btn-sm" href="{{ route('owners.edit',$owner->id) }}">تعديل</a>
@if($owner->state==='active' && $owner->desired_state==='active' && $owner->revision===$owner->applied_revision)<a class="btn btn-success btn-sm" href="{{ \App\Services\OwnerDashboards\Registry::url($owner) }}" target="_blank" rel="noopener">رابط دخول اللوحة</a>@endif
<form class="d-inline" method="post" action="{{ route('owners.state',$owner->id) }}">@csrf<input type="hidden" name="state" value="{{ $owner->desired_state==='suspended'?'active':'suspended' }}"><button class="btn btn-secondary btn-sm">{{ $owner->desired_state==='suspended'?'إعادة التفعيل':'إيقاف' }}</button></form>
<form class="d-inline" method="post" action="{{ route('owners.state',$owner->id) }}" onsubmit="return confirm('حذف حساب الأونر وإيقاف الدخول إلى لوحته؟ ستظل البيانات محفوظة للأرشيف.');">@csrf<input type="hidden" name="state" value="deleted"><button class="btn btn-danger btn-sm">حذف</button></form>
</td></tr>
@empty<tr><td colspan="5">لم تتم إضافة أونرز بلوحات مستقلة بعد.</td></tr>@endforelse
</tbody></table></div>{{ $owners->links() }}
</div></div></div></section></div>
@endsection
