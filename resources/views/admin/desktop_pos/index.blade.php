@extends('admin.index')
@section('content')
<div class="content-wrapper"><section class="content-header"><h1>برنامج الكمبيوتر — فسخانستا</h1></section>
<section class="content">
<div class="card"><div class="card-body">
    <p>الطلبات والطباعة تعمل على الكمبيوتر وقت انقطاع الإنترنت. العمليات تُرفع تلقائيًا عند عودة الاتصال.</p>
    <form method="get"><label>الفرع <select name="branch" onchange="this.form.submit()">@foreach($branches as $b)<option value="{{ $b['value'] }}" @if($selected===$b['value']) selected @endif>{{ $b['name'] }}</option>@endforeach</select></label></form>
    @if(!$ready)<p>البرنامج يحتاج تفعيل تحديثه على السيرفر.</p>@endif
    @if($installer)<a class="btn btn-warning my-3" href="{{ route('desktop-pos.download') }}">تحميل برنامج ويندوز</a>@else<p class="my-3">ملف تثبيت ويندوز لم يُرفع بعد.</p>@endif
    @if($ready)
    <form method="post" action="{{ route('desktop-pos.issue') }}">@csrf<input type="hidden" name="branch" value="{{ $selected }}"><label>اسم الجهاز <input name="name" required maxlength="100" placeholder="كاشير الفرع"></label> <button class="btn btn-primary">إنشاء كود ربط الجهاز</button></form>
    @if(session('desktop_pair_code'))<div class="alert alert-info mt-3">كود الربط: <bdi style="font-size:24px">{{ session('desktop_pair_code') }}</bdi><p>اكتب الكود في البرنامج خلال ١٠ دقائق. الكود يستخدم مرة واحدة.</p></div>@endif
    @endif
</div></div>
<div class="card"><div class="card-header">الأجهزة</div><div class="card-body"><table class="table"><thead><tr><th>الجهاز</th><th>آخر اتصال</th><th>الحالة</th><th></th></tr></thead><tbody>
@foreach($devices as $d)<tr><td>{{ $d->name }}</td><td>{{ $d->last_seen_at?\Carbon\Carbon::parse($d->last_seen_at,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i'):'لم يتصل بعد' }}</td><td>{{ $d->enabled?'مفعل':'متوقف' }}</td><td>@if($d->enabled)<form method="post" action="{{ route('desktop-pos.revoke') }}">@csrf<input type="hidden" name="device_id" value="{{ $d->id }}"><button class="btn btn-outline-danger btn-sm">إيقاف الربط</button></form>@endif</td></tr>@endforeach
</tbody></table></div></div>
<div class="card"><div class="card-header">العمليات التي تمت على الكمبيوتر</div><div class="card-body"><table class="table"><thead><tr><th>وقت التنفيذ</th><th>العملية</th><th>الطلب</th><th>الفاتورة</th><th>وقت المزامنة</th></tr></thead><tbody>
@foreach($operations??[] as $op)
@php($r=json_decode($op->result,true))
<tr><td>{{ \Carbon\Carbon::parse($op->occurred_at,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</td><td>{{ ['save'=>'حفظ طلب','kitchen'=>'إرسال للمطبخ','bill'=>'طلب الحساب','sale'=>'تحصيل نقدي','cancel'=>'إلغاء'][$op->kind]??$op->kind }}</td><td><bdi>{{ substr($op->local_id,0,8) }}</bdi></td><td>@if(!empty($r['receipt']))<a href="{{ route('takeaway.details',['id'=>$r['receipt']['id']]) }}">{{ $r['receipt']['number'] }}</a>@else — @endif</td><td>{{ \Carbon\Carbon::parse($op->created_at,'UTC')->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</td></tr>
@endforeach
</tbody></table>@if($operations){{ $operations->appends(['branch'=>$selected])->links() }}@endif</div></div>
</section></div>
@endsection
